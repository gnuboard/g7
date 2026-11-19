<?php

namespace App\Extension\Integrity;

use App\Support\CoreVersionDiskReader;
use Illuminate\Support\Facades\File;

/**
 * 제3자 제작자용 서명 도구 — 키쌍 만들기, 패키지 ZIP 서명, 디렉터리에서 결정적 ZIP 만들기.
 *
 * `extension:keygen`·`extension:sign` 명령이 쓴다. 키 파일은 JSON 으로 시드(32바이트)와 공개키를 담는다 — 같은 파일을
 * Node `crypto` 로도 읽을 수 있다(JWK `d` = 시드, `x` = 공개키). 비밀키 파일은 저장소 밖에 두고 권한 0600 으로 쓴다.
 *
 * @since 7.2.0
 */
class PackageSigner
{
    /** Ed25519 시드 길이 (sodium 상수에 기대지 않는다 — sodium 이 없는 서버에서도 이 클래스를 읽을 수 있게) */
    public const SEED_BYTES = 32;

    /** 키 파일 format 칸 값 */
    public const KEY_FILE_FORMAT = 'g7-signing-key';

    /** 결정적 ZIP 의 항목 시각 (1980-01-01 00:00:00 UTC — ZIP 이 표현할 수 있는 가장 이른 시각) */
    public const ZIP_FIXED_MTIME = 315532800;

    /** 디렉터리를 ZIP 으로 만들 때 넣지 않는 이름 */
    public const ZIP_EXCLUDED_NAMES = ['.git', '.github', '.DS_Store', 'node_modules', 'Thumbs.db'];

    /** 패키지 종류별 manifest 파일 이름 */
    public const MANIFEST_FILES = [
        PackageExpectation::TYPE_MODULE => 'module.json',
        PackageExpectation::TYPE_PLUGIN => 'plugin.json',
        PackageExpectation::TYPE_TEMPLATE => 'template.json',
        PackageExpectation::TYPE_LANGUAGE_PACK => 'language-pack.json',
    ];

    /**
     * 새 키쌍을 만듭니다.
     *
     * @param  string  $label  서명자 이름 (영문·숫자·`_`·`-`, 32자 이내)
     * @return array{format: string, version: int, algorithm: string, label: string, key_id: string, public_key: string, secret_seed: string} 키 파일 내용
     *
     * @throws \InvalidArgumentException 이름 규칙 위반 시
     */
    public function generateKey(string $label): array
    {
        $this->assertLabel($label);

        $seed = random_bytes(self::SEED_BYTES);

        return $this->keyFromSeed($label, $seed);
    }

    /**
     * 시드로 키 파일 내용을 만듭니다 (같은 시드는 늘 같은 키).
     *
     * @param  string  $label  서명자 이름
     * @param  string  $seed  32바이트 시드
     * @return array{format: string, version: int, algorithm: string, label: string, key_id: string, public_key: string, secret_seed: string} 키 파일 내용
     *
     * @throws \InvalidArgumentException 이름 규칙 위반 시
     * @throws \RuntimeException sodium 서명 함수가 없을 때
     */
    public function keyFromSeed(string $label, string $seed): array
    {
        $this->assertLabel($label);
        $this->assertSodium();

        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));

        return [
            'format' => self::KEY_FILE_FORMAT,
            'version' => 1,
            'algorithm' => PackageSignatureFormat::ALGORITHM,
            'label' => $label,
            'key_id' => PackageSignatureFormat::keyId($label, $publicKey),
            'public_key' => base64_encode($publicKey),
            'secret_seed' => base64_encode($seed),
        ];
    }

    /**
     * 키 파일을 씁니다 (권한 0600, 이미 있으면 덮지 않음).
     *
     * @param  array<string, mixed>  $key  키 파일 내용
     * @param  string  $path  쓸 경로
     * @return void 반환값 없음
     *
     * @throws \RuntimeException 파일이 이미 있거나 쓰지 못했을 때
     */
    public function writeKeyFile(array $key, string $path): void
    {
        if (file_exists($path)) {
            throw new \RuntimeException(__('extensions.commands.keygen.file_exists', ['path' => $path]));
        }

        File::ensureDirectoryExists(dirname($path));

        $json = json_encode($key, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $umask = umask(0077);
        try {
            $written = file_put_contents($path, $json, LOCK_EX);
        } finally {
            umask($umask);
        }

        if ($written !== strlen($json)) {
            throw new \RuntimeException(__('extensions.commands.keygen.write_failed', ['path' => $path]));
        }

        @chmod($path, 0600);
    }

    /**
     * 키 파일을 읽고 검사합니다.
     *
     * @param  string  $path  키 파일 경로
     * @return array{label: string, key_id: string, public_key: string, seed: string} 키 (공개키·시드는 raw 바이트)
     *
     * @throws \RuntimeException 파일이 없거나 형식이 틀렸을 때
     */
    public function readKeyFile(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException(__('extensions.commands.sign.key_not_found', ['path' => $path]));
        }

        $data = json_decode((string) file_get_contents($path), true);
        $seed = is_array($data) ? base64_decode((string) ($data['secret_seed'] ?? ''), true) : false;
        $label = is_array($data) ? (string) ($data['label'] ?? '') : '';

        if (! is_array($data) || ($data['format'] ?? null) !== self::KEY_FILE_FORMAT
            || ! is_string($seed) || strlen($seed) !== self::SEED_BYTES
            || ! preg_match(PackageSignatureFormat::LABEL_PATTERN, $label)) {
            throw new \RuntimeException(__('extensions.commands.sign.key_invalid', ['path' => $path]));
        }

        $key = $this->keyFromSeed($label, $seed);

        // 파일의 공개키·key_id 가 시드와 맞지 않으면 손상·편집된 파일이다
        if (($data['public_key'] ?? null) !== $key['public_key'] || ($data['key_id'] ?? null) !== $key['key_id']) {
            throw new \RuntimeException(__('extensions.commands.sign.key_invalid', ['path' => $path]));
        }

        return [
            'label' => $label,
            'key_id' => $key['key_id'],
            'public_key' => (string) base64_decode($key['public_key'], true),
            'seed' => $seed,
        ];
    }

    /**
     * ZIP 을 서명해 서명 파일 내용을 돌려줍니다.
     *
     * @param  string  $zipPath  서명할 ZIP
     * @param  array{label: string, key_id: string, public_key: string, seed: string}  $key  `readKeyFile()` 결과
     * @param  string  $type  패키지 종류
     * @param  string  $identifier  패키지 식별자
     * @param  string  $version  패키지 버전
     * @param  string|null  $signedAt  서명 시각 (null 이면 지금, UTC `Y-m-d\TH:i:s\Z`)
     * @return array<string, mixed> 서명 파일 내용
     *
     * @throws \RuntimeException sodium 서명 함수가 없을 때
     */
    public function signZip(string $zipPath, array $key, string $type, string $identifier, string $version, ?string $signedAt = null): array
    {
        $this->assertSodium();

        $document = PackageSignatureFormat::buildDocument(
            $type,
            $identifier,
            $version,
            basename($zipPath),
            (int) filesize($zipPath),
            (string) hash_file('sha256', $zipPath),
            $key['key_id'],
            $key['label'],
            $signedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
        );

        // php-ext-guard: 이 메서드 첫 줄의 assertSodium() 이 sodium 서명 함수가 없으면 안내 예외로 멈춘다
        $secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($key['seed']));
        $document['signature'] = base64_encode(sodium_crypto_sign_detached(PackageSignatureFormat::signingString($document), $secretKey));
        sodium_memzero($secretKey);

        return $document;
    }

    /**
     * ZIP 안의 manifest 에서 종류·식별자·버전을 읽습니다.
     *
     * 루트 또는 한 단계 아래 디렉터리의 manifest 를 찾는다. 코어 패키지는 `config/app.php` 의 버전 기본값을 읽는다.
     *
     * @param  string  $zipPath  ZIP 경로
     * @param  string|null  $type  종류 (null 이면 manifest 이름으로 판별)
     * @return array{type: string, identifier: string, version: string}|null 패키지 정체 (찾지 못하면 null)
     */
    public function readPackageIdentity(string $zipPath, ?string $type = null): ?array
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        try {
            $candidates = $type !== null && isset(self::MANIFEST_FILES[$type])
                ? [$type => self::MANIFEST_FILES[$type]]
                : self::MANIFEST_FILES;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                $depth = substr_count(trim($name, '/'), '/');

                foreach ($candidates as $candidateType => $manifestFile) {
                    if ($depth <= 1 && basename($name) === $manifestFile) {
                        $manifest = json_decode((string) $zip->getFromIndex($i), true);
                        if (is_array($manifest) && is_string($manifest['identifier'] ?? null) && is_string($manifest['version'] ?? null)) {
                            return ['type' => $candidateType, 'identifier' => $manifest['identifier'], 'version' => $manifest['version']];
                        }
                    }
                }

                if (($type === null || $type === PackageExpectation::TYPE_CORE) && $depth <= 2 && str_ends_with($name, 'config/app.php')) {
                    // 버전 줄 해석은 CoreVersionDiskReader 한 곳이 소유한다 — 검증하는 쪽(코어 업데이트)도 같은 규칙으로 읽는다
                    $version = CoreVersionDiskReader::fromContent((string) $zip->getFromIndex($i));
                    if ($version !== null) {
                        return ['type' => PackageExpectation::TYPE_CORE, 'identifier' => PackageExpectation::CORE_IDENTIFIER, 'version' => $version];
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /**
     * 디렉터리를 결정적 ZIP(`{identifier}-{version}.zip`, 루트 `{identifier}-{version}/`)으로 만듭니다.
     *
     * 항목은 경로 순, 시각은 고정값이라 같은 내용이면 같은 바이트가 나온다(재현 가능한 서명).
     *
     * @param  string  $directory  패키지 디렉터리
     * @param  string  $identifier  패키지 식별자
     * @param  string  $version  패키지 버전
     * @param  string  $outDir  ZIP 을 쓸 디렉터리
     * @return string 만든 ZIP 경로
     *
     * @throws \RuntimeException ZIP 을 만들지 못했을 때
     */
    public function buildDeterministicZip(string $directory, string $identifier, string $version, string $outDir): string
    {
        $base = $identifier.'-'.$version;
        File::ensureDirectoryExists($outDir);
        $zipPath = rtrim($outDir, '/\\').DIRECTORY_SEPARATOR.$base.'.zip';

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $files = [];
        $root = rtrim(str_replace('\\', '/', (string) realpath($directory)), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $file) => ! in_array($file->getFilename(), self::ZIP_EXCLUDED_NAMES, true) && ! $file->isLink()
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            }
        }
        sort($files, SORT_STRING);

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(__('extensions.commands.sign.zip_failed', ['path' => $zipPath]));
        }

        foreach ($files as $relative) {
            $entry = $base.'/'.$relative;
            $zip->addFromString($entry, (string) file_get_contents($root.'/'.$relative));
            $zip->setMtimeName($entry, self::ZIP_FIXED_MTIME);
            $zip->setCompressionName($entry, \ZipArchive::CM_DEFLATE);
        }

        if (! $zip->close()) {
            throw new \RuntimeException(__('extensions.commands.sign.zip_failed', ['path' => $zipPath]));
        }

        return $zipPath;
    }

    /**
     * 이 PHP 에 sodium 서명 함수가 있는지 확인합니다 (선택 확장 — 없으면 키를 만들거나 서명할 수 없다).
     *
     * @return void 반환값 없음
     *
     * @throws \RuntimeException sodium 이 없을 때
     */
    private function assertSodium(): void
    {
        if (! function_exists('sodium_crypto_sign_detached') || ! function_exists('sodium_crypto_sign_seed_keypair')) {
            throw new \RuntimeException(__('extensions.commands.keygen.sodium_missing'));
        }
    }

    /**
     * 서명자 이름 규칙을 확인합니다.
     *
     * @param  string  $label  서명자 이름
     * @return void 반환값 없음
     *
     * @throws \InvalidArgumentException 규칙 위반 시
     */
    private function assertLabel(string $label): void
    {
        if (! preg_match(PackageSignatureFormat::LABEL_PATTERN, $label)) {
            throw new \InvalidArgumentException(__('extension_integrity.errors.label_invalid'));
        }
    }
}
