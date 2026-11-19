<?php

namespace App\Extension\Update;

use App\Exceptions\PackageIntegrityException;
use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\PackageVerification;
use App\Extension\Integrity\UnsignedPackagePolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * 업데이트 미리보기가 GitHub 에서 내려받은 새 판(`_pending` 스테이징)을 이어지는 업데이트가 다시 쓰게 하는 표.
 *
 * 미리보기는 새 판을 내려받아 화면별 판정을 계산하고 `pending_token` 을 돌려준다. 업데이트 요청이 그 토큰을 넘기면
 * ({@see useToken()}) 다운로드 단계가 같은 확장·같은 판의 준비본을 먼저 찾아({@see claimAcquired()}) 다시 내려받지 않는다.
 * 미리보기로 본 파일과 실제로 적용하는 파일이 같아진다.
 *
 * 준비본에는 미리보기가 받은 패키지의 서명 검증 결과가 함께 적힌다. 업데이트는 {@see claimAcquired()} 로 꺼내며, 그 요청의
 * 무결성 선택(미서명 계속 여부)으로 정책을 다시 판정한다 — 미리보기를 거쳐도 미서명 확인을 건너뛰지 않는다(7.2.0).
 *
 * 표는 `storage/app/extension_update_previews/{토큰}.json` 이다. 1시간이 지난 항목은 새 항목을 넣을 때 지운다.
 */
final class ExtensionUpdatePreviewStore
{
    /** 준비본을 쓸 수 있는 시간(초) */
    public const TTL_SECONDS = 3600;

    /** 이번 요청의 토큰 */
    private static ?string $activeToken = null;

    /**
     * 내려받은 준비본을 등록하고 토큰을 돌려줍니다.
     *
     * @param  string  $type  확장 종류 (`module`·`plugin`·`template`)
     * @param  string  $identifier  확장 식별자
     * @param  string  $version  새 판 번호
     * @param  string  $path  준비본 디렉토리 (풀어 둔 새 판)
     * @param  array<string, mixed>|null  $verification  받은 패키지의 서명 검증 결과 (`PackageVerification::toArray()`, 없으면 미서명으로 다룬다)
     * @param  string|null  $tempDir  준비본을 감싼 임시 디렉토리 (정리 단위, 없으면 `$path`)
     * @return string 토큰
     */
    public function put(string $type, string $identifier, string $version, string $path, ?array $verification = null, ?string $tempDir = null): string
    {
        $this->pruneExpired();

        $token = Str::random(40);
        File::ensureDirectoryExists($this->directory());
        File::put($this->file($token), (string) json_encode([
            'type' => $type,
            'identifier' => $identifier,
            'version' => $version,
            'path' => $path,
            'temp_dir' => $tempDir ?? $path,
            'verification' => $verification,
            'created_at' => time(),
        ], JSON_UNESCAPED_SLASHES));

        return $token;
    }

    /**
     * 이번 요청에서 쓸 토큰을 정합니다 (업데이트 컨트롤러·명령이 업데이트 직전에 부른다).
     *
     * @param  string|null  $token  토큰 (null 이면 해제)
     * @return void 반환값 없음
     */
    public static function useToken(?string $token): void
    {
        self::$activeToken = ($token === null || $token === '') ? null : $token;
    }

    /**
     * 업데이트의 다운로드 단계가 부릅니다 — 이번 요청의 토큰이 같은 확장·같은 판의 준비본을 가리키면 그 받은 소스를 넘기고 표에서 지웁니다.
     *
     * 준비본에 적힌 서명 검증 결과를 이 요청의 무결성 선택으로 다시 판정한다. 미서명 확인이 필요하거나 차단이면 예외를 던지되
     * 준비본과 토큰은 남겨 둔다 — 운영자가 「미서명으로 계속」을 고른 재요청이 같은 준비본을 쓴다.
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  확장 식별자
     * @param  string  $version  내려받을 판 번호
     * @param  PackageIntegrityOptions  $options  이 요청의 무결성 선택
     * @return array{temp_dir: string, extracted_dir: string, verification: PackageVerification}|null 받은 소스 (없거나 맞지 않으면 null — 평소대로 내려받는다)
     *
     * @throws PackageIntegrityException 미서명 확인 필요·차단 시 (준비본 유지)
     */
    public function claimAcquired(string $type, string $identifier, string $version, PackageIntegrityOptions $options): ?array
    {
        $token = self::$activeToken;

        if ($token === null || ! preg_match('/^[A-Za-z0-9]{40}$/', $token) || ! File::exists($this->file($token))) {
            return null;
        }

        $entry = json_decode((string) File::get($this->file($token)), true);

        if (! is_array($entry)
            || ($entry['type'] ?? null) !== $type
            || ($entry['identifier'] ?? null) !== $identifier
            || ($entry['version'] ?? null) !== $version
            || time() - (int) ($entry['created_at'] ?? 0) > self::TTL_SECONDS
            || ! is_string($entry['path'] ?? null)
            || ! File::isDirectory($entry['path'])) {
            File::delete($this->file($token));
            self::$activeToken = null;

            return null;
        }

        // 던지면 준비본·토큰을 그대로 둔다
        $verification = app(UnsignedPackagePolicy::class)->decide(
            PackageVerification::fromArray(is_array($entry['verification'] ?? null) ? $entry['verification'] : []),
            $options
        );

        File::delete($this->file($token));
        self::$activeToken = null;

        return [
            'temp_dir' => is_string($entry['temp_dir'] ?? null) ? $entry['temp_dir'] : $entry['path'],
            'extracted_dir' => $entry['path'],
            'verification' => $verification,
        ];
    }

    /**
     * 기한이 지난 항목과 그 준비본을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function pruneExpired(): void
    {
        if (! File::isDirectory($this->directory())) {
            return;
        }

        foreach (File::files($this->directory()) as $file) {
            $entry = json_decode((string) File::get($file->getPathname()), true);

            if (! is_array($entry) || time() - (int) ($entry['created_at'] ?? 0) > self::TTL_SECONDS) {
                $dir = is_array($entry) ? ($entry['temp_dir'] ?? $entry['path'] ?? null) : null;
                if (is_string($dir) && File::isDirectory($dir)) {
                    File::deleteDirectory($dir);
                }
                File::delete($file->getPathname());
            }
        }
    }

    /**
     * 표 디렉토리.
     *
     * @return string 경로
     */
    private function directory(): string
    {
        return storage_path('app/extension_update_previews');
    }

    /**
     * 토큰의 표 파일.
     *
     * @param  string  $token  토큰
     * @return string 경로
     */
    private function file(string $token): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$token.'.json';
    }
}
