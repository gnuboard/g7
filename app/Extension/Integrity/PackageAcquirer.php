<?php

namespace App\Extension\Integrity;

use App\Exceptions\PackageIntegrityException;
use App\Extension\Helpers\GithubHelper;
use App\Support\OutboundUrlValidator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * 설치·업데이트 원천(GitHub 릴리스·로컬 ZIP·원격 서명 파일)에서 패키지를 얻고 무결성 정책을 적용합니다.
 *
 * 모든 경로가 같은 순서를 지킨다 — 받기 → 해제 전 바이트 검증 + 정책 → (호출부가 해제) → `confirmManifest()` 로
 * manifest 대조 + 정책 → 복사. 미서명이라 계속할 수 없으면 GitHub 에서 ZIP 을 받기 **전에** 409 로 멈춘다.
 *
 * @since 7.2.0
 */
class PackageAcquirer
{
    /**
     * 받는 쪽을 만듭니다.
     *
     * @param  PackageSignatureVerifier  $verifier  서명 검증기
     * @param  UnsignedPackagePolicy  $policy  미서명 정책
     */
    public function __construct(
        private readonly PackageSignatureVerifier $verifier,
        private readonly UnsignedPackagePolicy $policy,
    ) {}

    /**
     * 로컬 ZIP(업로드·`--zip`·운영자가 둔 코어 ZIP)을 검증합니다.
     *
     * 서명 파일은 `$options->signaturePath`, 없으면(그리고 `$autoSidecar`) ZIP 옆 `{zip}.sig.json` 을 쓴다.
     *
     * @param  string  $zipPath  ZIP 경로
     * @param  PackageExpectation  $expectation  기대 패키지
     * @param  PackageIntegrityOptions  $options  운영자 선택
     * @param  bool  $autoSidecar  ZIP 옆 서명 파일을 자동으로 찾을지 (업로드 임시 파일이면 false)
     * @return PackageVerification 정책을 통과한 검증 결과
     *
     * @throws PackageIntegrityException 차단·확인 필요 시
     * @throws \RuntimeException 지정한 서명 파일이 없을 때
     */
    public function verifyLocalZip(string $zipPath, PackageExpectation $expectation, PackageIntegrityOptions $options, bool $autoSidecar = true): PackageVerification
    {
        $signaturePath = $options->signaturePath;
        if (($signaturePath === null || $signaturePath === '') && $autoSidecar) {
            foreach (PackageSignatureFormat::sidecarCandidates($zipPath) as $candidate) {
                if (is_file($candidate)) {
                    $signaturePath = $candidate;
                    break;
                }
            }
        }

        $verification = $this->verifier->verify($zipPath, $this->readSignatureFile($signaturePath), $expectation);

        return $this->policy->decide($verification, $options);
    }

    /**
     * 해제한 manifest 를 서명된 패키지와 대조하고 정책을 다시 적용합니다 (복사 전).
     *
     * @param  PackageVerification  $verification  해제 전 검증 결과
     * @param  array<string, mixed>  $manifest  해제한 manifest
     * @param  PackageIntegrityOptions  $options  운영자 선택
     * @return PackageVerification 대조 결과
     *
     * @throws PackageIntegrityException 식별자·버전 불일치·vendor 범위 밖일 때
     */
    public function confirmManifest(PackageVerification $verification, array $manifest, PackageIntegrityOptions $options): PackageVerification
    {
        return $this->policy->decide($this->verifier->verifyManifest($verification, $manifest), $options);
    }

    /**
     * GitHub 태그 릴리스에서 패키지를 받습니다.
     *
     * 릴리스에 `{base}.zip` + `{base}.zip.sig.json` 짝이 있으면 그 자산을 받아 검증한다. 짝이 없으면 미서명 — 정책이
     * 허락할 때만 태그 zipball 을 받는다. 릴리스·태그가 없으면 브랜치로 물러서지 않고 실패한다.
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string|null  $version  받을 버전 (null 이면 최신 릴리스)
     * @param  string  $token  GitHub 토큰
     * @param  PackageExpectation  $expectation  기대 패키지 (식별자·버전을 모르면 null 칸)
     * @param  PackageIntegrityOptions  $options  운영자 선택
     * @param  string  $destDir  내려받을 디렉터리
     * @return AcquiredPackage 받은 패키지
     *
     * @throws PackageIntegrityException 차단·확인 필요 시
     * @throws PackageReleaseNotFoundException 릴리스·태그가 없을 때
     * @throws \RuntimeException 다운로드 실패 시
     */
    public function fromGithubRelease(
        string $owner,
        string $repo,
        ?string $version,
        string $token,
        PackageExpectation $expectation,
        PackageIntegrityOptions $options,
        string $destDir,
    ): AcquiredPackage {
        $release = $version !== null
            ? GithubHelper::fetchReleaseByTag($owner, $repo, $version, $token)
            : GithubHelper::fetchLatestRelease($owner, $repo, $token);

        if ($release['error'] !== null) {
            throw new \RuntimeException($release['error']);
        }

        $releaseVersion = $release['version'] ?? ($version !== null ? ltrim($version, 'vV') : null);
        $expectedVersion = $expectation->version ?? $releaseVersion;
        $expectation = new PackageExpectation($expectation->type, $expectation->identifier, $expectedVersion);

        $preferredBase = $expectation->identifier !== null && $expectedVersion !== null
            ? $expectation->identifier.'-'.PackageExpectation::normalizeVersion($expectedVersion)
            : null;
        $pair = GithubHelper::findSignedAssetPair($release['assets'] ?? [], $preferredBase);

        File::ensureDirectoryExists($destDir);

        if ($pair !== null) {
            if ((int) ($pair['signature']['size'] ?? 0) > PackageSignatureFormat::MAX_FILE_BYTES) {
                return $this->rejectOversizedSignature($expectation, $options);
            }

            $signaturePath = $destDir.DIRECTORY_SEPARATOR.basename((string) $pair['signature']['name']);
            $zipPath = $destDir.DIRECTORY_SEPARATOR.basename((string) $pair['zip']['name']);

            GithubHelper::downloadAsset($pair['signature'], $signaturePath, $token);
            GithubHelper::downloadAsset($pair['zip'], $zipPath, $token);

            $verification = $this->verifier->verify($zipPath, $this->readSignatureFile($signaturePath), $expectation);
            $this->policy->decide($verification, $options);

            return new AcquiredPackage($zipPath, $verification, $releaseVersion, true);
        }

        // 서명 자산이 없다 — 받을 태그가 있는지 먼저 확인하고(없으면 「릴리스 없음」), 내려받기 전에 정책을 묻는다
        // (계속할 수 없으면 받지 않는다)
        $archiveUrl = $version !== null
            ? GithubHelper::resolveArchiveUrl($owner, $repo, $version, 'zipball', $token)
            : ($release['zipball_url'] ?? null);

        if (! is_string($archiveUrl) || $archiveUrl === '') {
            throw new PackageReleaseNotFoundException($owner.'/'.$repo, $version);
        }

        $verification = $this->policy->decide(PackageVerification::unsigned(), $options);

        $zipPath = $destDir.DIRECTORY_SEPARATOR.uniqid('github_').'.zip';
        GithubHelper::downloadArchive($archiveUrl, $zipPath, $token);

        return new AcquiredPackage($zipPath, $verification, $releaseVersion, false);
    }

    /**
     * 원격 서명 파일(언어팩 `signature_url`)을 내려받아 경로를 돌려줍니다.
     *
     * 서버가 대신 내려받는 주소이므로 공개 http(s) 주소만 받는다. 크기는 64KB 까지.
     *
     * @param  string  $url  서명 파일 주소
     * @param  string  $destDir  저장할 디렉터리
     * @return string 내려받은 서명 파일 경로
     *
     * @throws \RuntimeException 주소가 공개 주소가 아니거나 내려받기 실패 시
     */
    public function downloadSignature(string $url, string $destDir): string
    {
        if (! OutboundUrlValidator::isPublicHttpUrl($url)) {
            throw new \RuntimeException(__('extension_integrity.errors.signature_url_not_public'));
        }

        $response = Http::timeout(30)->connectTimeout(5)->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException(__('extension_integrity.errors.signature_download_failed', ['status' => (string) $response->status()]));
        }

        $body = $response->body();
        if (strlen($body) > PackageSignatureFormat::MAX_FILE_BYTES) {
            throw new \RuntimeException(__('extension_integrity.errors.signature_too_large'));
        }

        File::ensureDirectoryExists($destDir);
        $path = $destDir.DIRECTORY_SEPARATOR.'package'.PackageSignatureFormat::FILE_SUFFIX;
        File::put($path, $body);

        return $path;
    }

    /**
     * 서명 파일을 읽습니다 (경로가 없으면 null).
     *
     * @param  string|null  $path  서명 파일 경로
     * @return string|null 내용 (경로가 비었으면 null, 64KB 초과면 형식 오류가 되도록 그대로)
     *
     * @throws \RuntimeException 경로를 줬는데 파일이 없을 때
     */
    private function readSignatureFile(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (! is_file($path)) {
            throw new \RuntimeException(__('extension_integrity.errors.signature_file_not_found', ['path' => basename($path)]));
        }

        $content = file_get_contents($path, false, null, 0, PackageSignatureFormat::MAX_FILE_BYTES + 1);

        return $content === false ? null : $content;
    }

    /**
     * 너무 큰 서명 자산을 형식 오류로 거부합니다.
     *
     * @param  PackageExpectation  $expectation  기대 패키지
     * @param  PackageIntegrityOptions  $options  운영자 선택
     * @return never 늘 예외
     *
     * @throws PackageIntegrityException 늘
     */
    private function rejectOversizedSignature(PackageExpectation $expectation, PackageIntegrityOptions $options): never
    {
        $this->policy->decide(new PackageVerification(PackageVerificationStatus::Invalid, 'format_unknown', [
            'type' => $expectation->type,
            'identifier' => (string) $expectation->identifier,
            'version' => (string) $expectation->version,
        ]), $options);

        throw new \LogicException('unreachable');
    }
}
