<?php

namespace App\Console\Commands\Extension;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Extension\Integrity\PackageExpectation;
use App\Extension\Integrity\PackageSignatureFormat;
use App\Extension\Integrity\PackageSigner;
use App\Support\CoreVersionDiskReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 확장·언어팩·코어 패키지 ZIP 을 서명합니다 (7.2.0).
 *
 * `{path}` 가 ZIP 이면 그 파일을 그대로 서명하고, 디렉터리면 `{identifier}-{version}.zip`(루트 `{identifier}-{version}/`,
 * 경로 순·고정 시각 — 같은 내용이면 같은 바이트)을 만든 뒤 서명한다. 서명 파일은 `{zip}.sig.json` 이다. 식별자·버전은
 * 패키지 manifest(코어는 `config/app.php`)에서 읽는다. 릴리스에 ZIP 과 서명 파일을 함께 올린다.
 */
class ExtensionSignCommand extends Command
{
    use LocalizesDescription;

    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'extension:sign
        {path : 서명할 패키지 ZIP 또는 패키지 디렉터리}
        {--key= : extension:keygen 으로 만든 비밀키 파일 경로}
        {--type= : 패키지 종류 (module|plugin|template|language_pack|core) — 생략하면 manifest 로 판별}
        {--out= : 결과를 쓸 디렉터리 (기본: ZIP 이면 ZIP 옆, 디렉터리면 그 상위)}
        {--signed-at= : 서명 시각 (UTC, 예 2026-11-20T00:00:00Z — 재현 가능한 서명용, 생략하면 지금)}';

    /**
     * 커맨드 실행
     *
     * @param  PackageSigner  $signer  서명 도구
     * @return int 실행 결과 코드
     */
    public function handle(PackageSigner $signer): int
    {
        $path = (string) $this->argument('path');
        $realPath = realpath($path);
        if ($realPath === false) {
            return $this->failWith(__('extensions.commands.sign.path_not_found', ['path' => $path]));
        }

        $type = $this->option('type');
        $type = is_string($type) && $type !== '' ? $type : null;
        if ($type !== null && ! in_array($type, PackageExpectation::TYPES, true)) {
            return $this->failWith(__('extensions.commands.sign.invalid_type'));
        }

        $keyPath = (string) ($this->option('key') ?? '');
        if ($keyPath === '') {
            return $this->failWith(__('extensions.commands.sign.key_missing'));
        }

        try {
            $key = $signer->readKeyFile($keyPath);
        } catch (\RuntimeException $e) {
            return $this->failWith($e->getMessage());
        }

        $out = $this->option('out');
        $out = is_string($out) && $out !== '' ? $out : null;

        try {
            if (is_dir($realPath)) {
                $identity = $this->identityOfDirectory($realPath, $type);
                if ($identity === null) {
                    return $this->failWith(__('extensions.commands.sign.identity_not_found'));
                }
                $zipPath = $signer->buildDeterministicZip($realPath, $identity['identifier'], $identity['version'], $out ?? dirname($realPath));
                $this->info('📦 '.__('extensions.commands.sign.zip_built', ['path' => $zipPath]));
            } else {
                $identity = $signer->readPackageIdentity($realPath, $type);
                if ($identity === null) {
                    return $this->failWith(__('extensions.commands.sign.identity_not_found'));
                }
                $zipPath = $realPath;
                if ($out !== null) {
                    File::ensureDirectoryExists($out);
                    $zipPath = rtrim($out, '/\\').DIRECTORY_SEPARATOR.basename($realPath);
                    if ($zipPath !== $realPath) {
                        File::copy($realPath, $zipPath);
                    }
                }
            }

            $signedAt = $this->option('signed-at');
            $document = $signer->signZip(
                $zipPath,
                $key,
                $identity['type'],
                $identity['identifier'],
                $identity['version'],
                is_string($signedAt) && $signedAt !== '' ? $signedAt : null,
            );
        } catch (\RuntimeException $e) {
            return $this->failWith($e->getMessage());
        }

        $signaturePath = $zipPath.PackageSignatureFormat::FILE_SUFFIX;
        File::put($signaturePath, PackageSignatureFormat::encode($document));

        $this->info('✅ '.__('extensions.commands.sign.signed', ['path' => $signaturePath]));
        $this->line(__('extensions.commands.sign.upload_hint'));

        return Command::SUCCESS;
    }

    /**
     * 패키지 디렉터리의 종류·식별자·버전을 manifest 에서 읽습니다.
     *
     * @param  string  $directory  패키지 디렉터리
     * @param  string|null  $type  종류 (null 이면 manifest 이름으로 판별)
     * @return array{type: string, identifier: string, version: string}|null 패키지 정체
     */
    private function identityOfDirectory(string $directory, ?string $type): ?array
    {
        foreach (PackageSigner::MANIFEST_FILES as $candidateType => $manifestFile) {
            if ($type !== null && $type !== $candidateType) {
                continue;
            }
            $manifestPath = $directory.DIRECTORY_SEPARATOR.$manifestFile;
            if (is_file($manifestPath)) {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                if (is_array($manifest) && is_string($manifest['identifier'] ?? null) && is_string($manifest['version'] ?? null)) {
                    return ['type' => $candidateType, 'identifier' => $manifest['identifier'], 'version' => $manifest['version']];
                }
            }
        }

        if ($type === null || $type === PackageExpectation::TYPE_CORE) {
            // 버전 줄 해석은 CoreVersionDiskReader 한 곳이 소유한다 — 검증하는 쪽(코어 업데이트)도 같은 규칙으로 읽는다
            $version = CoreVersionDiskReader::shippedVersion($directory.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php');
            if ($version !== null) {
                return ['type' => PackageExpectation::TYPE_CORE, 'identifier' => PackageExpectation::CORE_IDENTIFIER, 'version' => $version];
            }
        }

        return null;
    }

    /**
     * 오류를 출력하고 실패 코드를 돌려줍니다.
     *
     * @param  string  $message  오류 문장
     * @return int Command::FAILURE
     */
    private function failWith(string $message): int
    {
        $this->error('❌ '.$message);

        return Command::FAILURE;
    }
}
