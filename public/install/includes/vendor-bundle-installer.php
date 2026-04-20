<?php

/**
 * Vendor 번들 설치 헬퍼 (웹 인스톨러 전용 shim).
 *
 * Laravel 부트 전 단계에서 실행되므로 App\Extension\Vendor\VendorBundleInstaller
 * 클래스를 사용할 수 없다. 동일한 검증/추출 로직을 순수 PHP로 구현한다.
 *
 * Laravel 측 클래스와의 동등성은 VendorBundleInstallerShimTest 가 보장한다.
 */

const VENDOR_BUNDLE_SCHEMA_VERSION = '1.0';
const VENDOR_BUNDLE_ZIP_FILENAME = 'vendor-bundle.zip';
const VENDOR_BUNDLE_MANIFEST_FILENAME = 'vendor-bundle.json';

/**
 * vendor-bundle.zip 의 무결성을 검증합니다.
 *
 * @param  string  $sourceDir  vendor-bundle.zip 이 위치한 디렉토리
 * @return array{valid: bool, errors: array<string>, meta: array<string, mixed>}
 */
function verifyVendorBundle(string $sourceDir): array
{
    $zipPath = $sourceDir.DIRECTORY_SEPARATOR.VENDOR_BUNDLE_ZIP_FILENAME;
    $manifestPath = $sourceDir.DIRECTORY_SEPARATOR.VENDOR_BUNDLE_MANIFEST_FILENAME;

    if (! file_exists($zipPath)) {
        return ['valid' => false, 'errors' => ['bundle_zip_missing'], 'meta' => []];
    }

    if (! file_exists($manifestPath)) {
        return ['valid' => false, 'errors' => ['bundle_manifest_missing'], 'meta' => []];
    }

    $manifestJson = @file_get_contents($manifestPath);
    if ($manifestJson === false) {
        return ['valid' => false, 'errors' => ['bundle_manifest_invalid'], 'meta' => []];
    }

    $manifest = json_decode($manifestJson, true);
    if (! is_array($manifest)) {
        return ['valid' => false, 'errors' => ['bundle_manifest_invalid'], 'meta' => []];
    }

    $schemaVersion = $manifest['schema_version'] ?? null;
    if ($schemaVersion !== VENDOR_BUNDLE_SCHEMA_VERSION) {
        return ['valid' => false, 'errors' => ['bundle_schema_unsupported'], 'meta' => $manifest];
    }

    $errors = [];

    $expectedZipHash = $manifest['zip_sha256'] ?? null;
    if ($expectedZipHash === null) {
        $errors[] = 'bundle_manifest_invalid';
    } else {
        $actualZipHash = hash_file('sha256', $zipPath);
        if (! hash_equals((string) $expectedZipHash, (string) $actualZipHash)) {
            $errors[] = 'zip_hash_mismatch';
        }
    }

    $composerJsonPath = $sourceDir.DIRECTORY_SEPARATOR.'composer.json';
    if (file_exists($composerJsonPath)) {
        $expected = $manifest['composer_json_sha256'] ?? null;
        if ($expected !== null) {
            $actual = hash_file('sha256', $composerJsonPath);
            if (! hash_equals((string) $expected, (string) $actual)) {
                $errors[] = 'composer_json_sha_mismatch';
            }
        }
    }

    if (! empty($errors)) {
        return ['valid' => false, 'errors' => $errors, 'meta' => $manifest];
    }

    return ['valid' => true, 'errors' => [], 'meta' => $manifest];
}

/**
 * vendor-bundle.zip 을 대상 디렉토리에 추출합니다.
 *
 * 공유 호스팅 친화적 설계:
 * - targetDir 자체(프로젝트 루트)의 쓰기 권한은 요구하지 않음
 * - vendor/ 디렉토리 자체는 유지하고 내부 항목만 조작
 * - 기존 내용은 vendor/.bundle_backup_{ts}/ 로 이동 (vendor/ 쓰기 권한만으로 가능)
 * - 추출 실패 시 백업에서 복원, 성공 시 백업 삭제
 *
 * @param  string  $sourceDir  vendor-bundle.zip 위치
 * @param  string  $targetDir  vendor/ 가 배치될 위치
 * @return array{success: bool, error?: string, package_count?: int}
 */
function extractVendorBundle(string $sourceDir, string $targetDir): array
{
    if (! class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'zip_archive_not_available'];
    }

    $integrity = verifyVendorBundle($sourceDir);
    if (! $integrity['valid']) {
        return [
            'success' => false,
            'error' => 'bundle_integrity_failed: '.implode(', ', $integrity['errors']),
        ];
    }

    $zipPath = $sourceDir.DIRECTORY_SEPARATOR.VENDOR_BUNDLE_ZIP_FILENAME;
    $vendorDir = $targetDir.DIRECTORY_SEPARATOR.'vendor';
    $backupDirName = '.bundle_backup_'.date('Ymd_His');
    $backupDir = $vendorDir.DIRECTORY_SEPARATOR.$backupDirName;

    // 쓰기 권한 유연 검사 — vendor/ 가 있으면 vendor/ 권한만, 없으면 targetDir 권한 필요
    if (is_dir($vendorDir)) {
        if (! is_writable($vendorDir)) {
            return ['success' => false, 'error' => 'target_not_writable: '.$vendorDir.buildVendorBundlePermissionHint($vendorDir)];
        }
    } else {
        if (! is_dir($targetDir) || ! is_writable($targetDir)) {
            return ['success' => false, 'error' => 'target_not_writable: '.$targetDir.buildVendorBundlePermissionHint($targetDir)];
        }
    }

    // zip slip 사전 검증
    $unsafePathError = checkVendorBundleZipSafety($zipPath);
    if ($unsafePathError !== null) {
        return ['success' => false, 'error' => 'bundle_contains_unsafe_path: '.$unsafePathError];
    }

    // 기존 vendor/ 내부 항목을 vendor/.bundle_backup_{ts}/ 로 이동
    // (vendor/ 디렉토리 자체는 유지 — targetDir 쓰기 권한 불필요)
    $hasBackup = false;
    if (is_dir($vendorDir)) {
        $hasBackup = moveVendorContentsToBackup($vendorDir, $backupDir, $backupDirName);
    } else {
        // vendor/ 없음 — 신규 생성 (targetDir 쓰기 권한 필요, 위에서 이미 검증)
        if (! @mkdir($vendorDir, 0755, true) && ! is_dir($vendorDir)) {
            return ['success' => false, 'error' => 'target_not_writable: '.$vendorDir];
        }
    }

    $zip = new ZipArchive;
    $openResult = $zip->open($zipPath);
    if ($openResult !== true) {
        if ($hasBackup && is_dir($backupDir)) {
            restoreVendorFromBackup($vendorDir, $backupDir, $backupDirName);
        }

        return ['success' => false, 'error' => 'extraction_failed: ZipArchive::open failed (code '.$openResult.')'];
    }

    $extracted = $zip->extractTo($targetDir);
    $zip->close();

    if (! $extracted) {
        if ($hasBackup && is_dir($backupDir)) {
            restoreVendorFromBackup($vendorDir, $backupDir, $backupDirName);
        }

        return ['success' => false, 'error' => 'extraction_failed: ZipArchive::extractTo returned false'];
    }

    // 성공: 백업 디렉토리 삭제 (vendor/.bundle_backup_{ts}/)
    if ($hasBackup && is_dir($backupDir)) {
        deleteVendorBundleDirectory($backupDir);
    }

    return [
        'success' => true,
        'package_count' => (int) ($integrity['meta']['package_count'] ?? 0),
    ];
}

/**
 * 기존 vendor/ 내부 항목을 vendor/.bundle_backup_{ts}/ 로 이동합니다.
 *
 * vendor/ 디렉토리 자체는 유지하므로 targetDir 쓰기 권한이 필요하지 않습니다.
 *
 * @return bool 백업 성공 여부 (false 시 in-place overwrite 로 추출 진행)
 */
function moveVendorContentsToBackup(string $vendorDir, string $backupDir, string $backupDirName): bool
{
    if (! @mkdir($backupDir) && ! is_dir($backupDir)) {
        return false;
    }

    $items = @scandir($vendorDir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === $backupDirName) {
            continue;
        }

        $src = $vendorDir.DIRECTORY_SEPARATOR.$item;
        $dst = $backupDir.DIRECTORY_SEPARATOR.$item;
        @rename($src, $dst);
    }

    return true;
}

/**
 * 백업 디렉토리에서 vendor/ 로 내용을 복원합니다.
 */
function restoreVendorFromBackup(string $vendorDir, string $backupDir, string $backupDirName): void
{
    // 추출로 이미 생성된 파일/디렉토리 정리 (백업 폴더 제외)
    $items = @scandir($vendorDir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === $backupDirName) {
            continue;
        }

        $path = $vendorDir.DIRECTORY_SEPARATOR.$item;
        if (is_dir($path) && ! is_link($path)) {
            deleteVendorBundleDirectory($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }

    // 백업 항목을 원위치로 이동
    $backupItems = @scandir($backupDir) ?: [];
    foreach ($backupItems as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $src = $backupDir.DIRECTORY_SEPARATOR.$item;
        $dst = $vendorDir.DIRECTORY_SEPARATOR.$item;
        @rename($src, $dst);
    }

    @rmdir($backupDir);
}

/**
 * zip 내부 파일 경로의 안전성을 검증합니다 (zip slip 방지).
 */
function checkVendorBundleZipSafety(string $zipPath): ?string
{
    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        return 'cannot open zip';
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        $normalized = str_replace('\\', '/', $name);
        if (
            str_contains($normalized, '../')
            || str_starts_with($normalized, '/')
            || preg_match('#^[A-Za-z]:/#', $normalized)
        ) {
            $zip->close();

            return $name;
        }
    }

    $zip->close();

    return null;
}

/**
 * 디렉토리 재귀 삭제 (Laravel File 파사드 의존 제거).
 */
function deleteVendorBundleDirectory(string $dir): bool
{
    if (! is_dir($dir)) {
        return false;
    }

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir.DIRECTORY_SEPARATOR.$item;
        if (is_dir($path)) {
            deleteVendorBundleDirectory($path);
        } else {
            @unlink($path);
        }
    }

    return @rmdir($dir);
}

/**
 * Composer 실행이 현재 환경에서 가능한지 검사합니다.
 *
 * proc_open() 사용 가능 여부 + composer 바이너리 발견 여부를 종합 판단.
 */
function canExecuteComposerForInstall(?string $composerBinary, ?string $phpBinary): bool
{
    // proc_open 사용 가능 여부
    if (! function_exists('proc_open')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('proc_open', $disabled, true)) {
        return false;
    }

    // composer 바이너리 결정
    $binary = $composerBinary;
    if (! $binary) {
        // PATH 검색 단계는 인스톨러 환경에서 비용이 크므로 단순화
        $binary = 'composer';
    }

    return ! empty($binary);
}

/**
 * 권한 거부 시 진단 힌트를 생성합니다.
 *
 * 인스톨러는 Laravel __() 사용 불가하므로 순수 PHP로 메시지를 구성합니다.
 * (한국어 고정 — 인스톨러는 다국어 지원 범위가 제한적)
 */
function buildVendorBundlePermissionHint(string $path): string
{
    if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
        return '';
    }

    $currentUid = posix_geteuid();
    $currentUser = posix_getpwuid($currentUid)['name'] ?? (string) $currentUid;

    if (! file_exists($path)) {
        return " (실행 사용자: {$currentUser})";
    }

    $ownerUid = fileowner($path);
    if ($currentUid === $ownerUid) {
        return " (실행 사용자: {$currentUser}, 소유자 동일)";
    }

    $ownerUser = posix_getpwuid($ownerUid)['name'] ?? (string) $ownerUid;

    return " — 실행 사용자({$currentUser})와 디렉토리 소유자({$ownerUser})가 다릅니다. ".
        "FTP/SSH로 'chown -R {$currentUser} {$path}' 실행 후 재시도하세요.";
}
