<?php

namespace App\Extension\Vendor;

/**
 * vendor-bundle.json 기반 번들 파일의 무결성을 검증합니다.
 *
 * 검증 항목:
 * - vendor-bundle.zip 존재 + SHA256 일치
 * - vendor-bundle.json 존재 + 파싱 가능 + 스키마 버전 지원
 * - composer.json/composer.lock SHA256 일치 (소스 디렉토리에 존재 시)
 */
class VendorIntegrityChecker
{
    /**
     * 지원하는 manifest 스키마 버전 목록.
     */
    public const SUPPORTED_SCHEMA_VERSIONS = ['1.0'];

    public const MANIFEST_FILENAME = 'vendor-bundle.json';

    public const ZIP_FILENAME = 'vendor-bundle.zip';

    /**
     * 주어진 소스 디렉토리의 번들 무결성을 검증합니다.
     *
     * @param  string  $sourceDir  vendor-bundle.zip 이 위치한 디렉토리
     */
    public function verify(string $sourceDir): IntegrityResult
    {
        $zipPath = $sourceDir.DIRECTORY_SEPARATOR.self::ZIP_FILENAME;
        $manifestPath = $sourceDir.DIRECTORY_SEPARATOR.self::MANIFEST_FILENAME;

        // 1. 파일 존재 확인
        if (! file_exists($zipPath)) {
            return IntegrityResult::invalid(['bundle_zip_missing']);
        }
        if (! file_exists($manifestPath)) {
            return IntegrityResult::invalid(['bundle_manifest_missing']);
        }

        // 2. manifest 파싱
        $manifestJson = @file_get_contents($manifestPath);
        if ($manifestJson === false) {
            return IntegrityResult::invalid(['bundle_manifest_invalid']);
        }

        try {
            $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return IntegrityResult::invalid(['bundle_manifest_invalid']);
        }

        if (! is_array($manifest)) {
            return IntegrityResult::invalid(['bundle_manifest_invalid']);
        }

        // 3. 스키마 버전 확인
        $schemaVersion = $manifest['schema_version'] ?? null;
        if (! in_array($schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            return IntegrityResult::invalid(['bundle_schema_unsupported'], $manifest);
        }

        $errors = [];
        $warnings = [];

        // 4. zip SHA256 검증
        $expectedZipHash = $manifest['zip_sha256'] ?? null;
        if ($expectedZipHash === null) {
            $errors[] = 'bundle_manifest_invalid';
        } else {
            $actualZipHash = $this->computeFileHash($zipPath);
            if (! hash_equals((string) $expectedZipHash, $actualZipHash)) {
                $errors[] = 'zip_hash_mismatch';
            }
        }

        // 5. composer.json/lock 해시 비교 (소스에 존재할 때만)
        $composerJsonPath = $sourceDir.DIRECTORY_SEPARATOR.'composer.json';
        if (file_exists($composerJsonPath)) {
            $expected = $manifest['composer_json_sha256'] ?? null;
            if ($expected !== null) {
                $actual = $this->computeFileHash($composerJsonPath);
                if (! hash_equals((string) $expected, $actual)) {
                    $errors[] = 'composer_json_sha_mismatch';
                }
            }
        }

        $composerLockPath = $sourceDir.DIRECTORY_SEPARATOR.'composer.lock';
        if (file_exists($composerLockPath)) {
            $expected = $manifest['composer_lock_sha256'] ?? null;
            if ($expected !== null) {
                $actual = $this->computeFileHash($composerLockPath);
                if (! hash_equals((string) $expected, $actual)) {
                    $errors[] = 'composer_lock_sha_mismatch';
                }
            }
        }

        if (! empty($errors)) {
            return IntegrityResult::invalid($errors, $manifest);
        }

        return IntegrityResult::valid($manifest, $warnings);
    }

    /**
     * 파일의 SHA256 해시를 계산합니다.
     */
    public function computeFileHash(string $path): string
    {
        return hash_file('sha256', $path) ?: '';
    }

    /**
     * manifest만 파싱하여 반환 (검증 없이 메타 조회용).
     *
     * @return array<string, mixed>|null
     */
    public function readManifest(string $sourceDir): ?array
    {
        $path = $sourceDir.DIRECTORY_SEPARATOR.self::MANIFEST_FILENAME;
        if (! file_exists($path)) {
            return null;
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
