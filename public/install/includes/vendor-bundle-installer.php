<?php

use App\Support\ComposerInstallInfo;
use App\Support\ExecutionEnvironment;

require_once __DIR__.'/safe-archive-extractor.php';

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
 * @param  callable|null  $onProgress  묶음 하나를 풀 때마다 호출 (fn (int $done, int $total): void).
 *                                     호출자가 이 구간에도 「아직 살아 있다」를 알리기 위한 자리다.
 * @return array{success: bool, error?: string, package_count?: int}
 */
function extractVendorBundle(string $sourceDir, string $targetDir, ?callable $onProgress = null): array
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

    // 지난 실행이 남긴 백업을 먼저 치운다. 복원은 **같은 프로세스**에서 추출이 false 를
    // 돌려준 경우에만 도는데, 프로세스가 그냥 죽으면(타임아웃·OOM·워커 탈취) 백업이 그대로
    // 남는다. 그 상태에서 재시도하면 그 백업까지 통째로 새 백업 안으로 옮겨져 중첩되고,
    // vendor 한 벌 크기의 사본이 실행할 때마다 하나씩 쌓인다.
    cleanupStaleVendorBundleBackups($vendorDir, $targetDir);

    $backupDirName = '.bundle_backup_'.date('Ymd_His');
    // 백업은 vendor/ 밖(storage/installer)에 둔다 — vendor/ 안에 두면 이미 깊은 패키지
    // 경로에 한 단계가 더 붙어 Windows 의 260자 경로 한계를 그대로 넘긴다.
    // storage/installer 를 쓸 수 없는 환경에서는 종전 위치로 떨어진다(기능 축소 없음).
    $backupDir = resolveVendorBundleBackupDir($targetDir, $vendorDir, $backupDirName);

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
    $backupState = 'not_needed';
    if (is_dir($vendorDir)) {
        $hasBackup = moveVendorContentsToBackup($vendorDir, $backupDir, $backupDirName);
        $backupState = $hasBackup ? 'created' : 'failed';

        if (! $hasBackup) {
            // 여기서부터는 **되돌릴 수 없는** 덮어쓰기다. 종전에는 이 사실이 어디에도
            // 남지 않아, 추출이 중간에 깨진 뒤 운영자는 왜 vendor/ 가 반쪽이 됐는지 알
            // 방법이 없었다. 태스크를 실패로 바꾸지는 않는다 — 백업 불가는 공유 호스팅에서
            // 정상적으로 발생하고, 그 환경의 설치를 새로 막으면 기능 축소다.
            recordVendorBundleWarning(
                'vendor backup failed — proceeding with irreversible in-place overwrite: '.$vendorDir
            );
        }
    } else {
        // vendor/ 없음 — 신규 생성 (targetDir 쓰기 권한 필요, 위에서 이미 검증)
        if (! @mkdir($vendorDir, 0755, true) && ! is_dir($vendorDir)) {
            return ['success' => false, 'error' => 'target_not_writable: '.$vendorDir];
        }
    }

    // 추출 방식은 둘이다. 어느 쪽이든 위 사전 검증(checkVendorBundleZipSafety — `..` 조각·절대경로·
    // 드라이브·UNC·널 문자·심볼릭 링크 거부)을 이미 통과했다.
    //
    //  - 안전 추출기(g7InstallSafeExtractZip) — 역슬래시 경로를 정규화하고, 대상 안에 미리 있던 링크를
    //    따라 밖으로 나가는 쓰기도 막는다. 항목을 하나씩 스트림으로 풀어 진행 알림이 없다.
    //    Windows 에서 만든 묶음(역슬래시 항목)이거나, 백업 실패로 기존 vendor/ 항목이 그대로 남아 있을 때 쓴다.
    //  - 묶음 단위 추출(extractVendorBundleInChunks) — 그 밖의 경우. 한 번의 extractTo 로 전부 푸는 대신
    //    묶음으로 나눠 그 사이에 호출자가 「아직 살아 있다」를 알릴 수 있게 한다. 번들 추출은 설치에서
    //    가장 오래 걸리는 태스크(P0 실측 ≈10~15s)인데 그 구간에 emit 이 하나도 없으면 워커 heartbeat 가
    //    멈춰, 새로고침한 요청이 아직 살아 있는 이 요청을 stale 로 보고 탈취해 **두 요청이 같은 vendor/ 를
    //    동시에 옮기고 푼다** (10차 #M2). vendor/ 는 백업으로 비워졌거나 새로 만들어졌으므로 미리 있던
    //    링크가 없다.
    $zip = new ZipArchive;
    $openResult = $zip->open($zipPath);
    if ($openResult !== true) {
        if ($hasBackup && is_dir($backupDir)) {
            restoreVendorFromBackup($vendorDir, $backupDir, $backupDirName);
        }

        return ['success' => false, 'error' => 'extraction_failed: ZipArchive::open failed (code '.$openResult.')'];
    }

    if ($backupState === 'failed' || vendorBundleHasBackslashEntries($zip)) {
        $zip->close();

        $extracted = g7InstallSafeExtractZip($zipPath, $targetDir);

        if ($extracted['success'] && $onProgress !== null) {
            $count = count($extracted['files'] ?? []);
            $onProgress($count, $count);
        }
    } else {
        $extracted = extractVendorBundleInChunks($zip, $targetDir, $onProgress)
            ? ['success' => true]
            : ['success' => false, 'error' => 'ZipArchive::extractTo returned false'];
        $zip->close();
    }

    if (! $extracted['success']) {
        if ($hasBackup && is_dir($backupDir)) {
            restoreVendorFromBackup($vendorDir, $backupDir, $backupDirName);
        }

        if (($extracted['error'] ?? '') === 'unsafe_entry') {
            return ['success' => false, 'error' => 'bundle_contains_unsafe_path: '.$extracted['unsafe_entry']];
        }

        return ['success' => false, 'error' => 'extraction_failed: '.($extracted['error'] ?? 'unknown')];
    }

    // 성공: 백업 디렉토리 삭제 (vendor/.bundle_backup_{ts}/)
    if ($hasBackup && is_dir($backupDir)) {
        deleteVendorBundleDirectory($backupDir);
    }

    return [
        'success' => true,
        'package_count' => (int) ($integrity['meta']['package_count'] ?? 0),
        'backup' => $backupState,
    ];
}

/**
 * 백업 디렉토리 경로를 정합니다.
 *
 * 기본은 `storage/installer/{name}` — vendor/ 안에 두면 이미 긴 패키지 경로
 * (`vendor/vendor명/패키지명/src/...`)에 한 단계가 더 붙어 Windows 의 260자 한계를 넘긴다.
 * 넘어가면 `rename()` 이 조용히 실패하고 백업이 "부분 이동" 으로 끝난다.
 *
 * storage/installer 를 만들 수 없으면 종전 위치(vendor/ 내부)로 떨어진다 — 그 환경은
 * targetDir 쓰기 권한이 없는 공유 호스팅이고, 새로 막으면 기능 축소다.
 *
 * @param  string  $targetDir  vendor/ 가 배치될 위치 (프로젝트 루트)
 * @param  string  $vendorDir  vendor/ 절대 경로
 * @param  string  $backupDirName  백업 디렉토리 이름
 * @return string 백업 디렉토리 절대 경로
 */
function resolveVendorBundleBackupDir(string $targetDir, string $vendorDir, string $backupDirName): string
{
    $root = $targetDir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'installer';

    if (! is_dir($root)) {
        @mkdir($root, 0755, true);
    }

    if (is_dir($root) && is_writable($root)) {
        return $root.DIRECTORY_SEPARATOR.'vendor-backup-'.ltrim($backupDirName, '.');
    }

    return $vendorDir.DIRECTORY_SEPARATOR.$backupDirName;
}

/**
 * 지난 실행이 남긴 백업 디렉토리를 제거합니다.
 *
 * 두 위치를 모두 본다 — 현재 위치(`storage/installer/vendor-backup-*`)와 종전 위치
 * (`vendor/.bundle_backup_*`). 종전 위치의 잔재는 이미 설치된 사이트에 남아 있을 수 있고,
 * 그것을 그대로 두면 다음 백업이 그것까지 옮겨 담아 중첩된다.
 *
 * @param  string  $vendorDir  vendor/ 절대 경로
 * @param  string  $targetDir  프로젝트 루트
 * @return int 제거한 디렉토리 수
 */
function cleanupStaleVendorBundleBackups(string $vendorDir, string $targetDir): int
{
    $removed = 0;

    // `glob` 이 막힌 호스팅에서는 정리를 건너뛴다 — 무가드 호출은 PHP 8 에서 Error(치명)라
    // 그 자체로 vendor 설치를 세운다. 잔여 백업은 남아도 설치를 막지 않는다.
    $candidates = installerHasFunction('glob') ? array_merge(
        glob($vendorDir.DIRECTORY_SEPARATOR.'.bundle_backup_*', GLOB_ONLYDIR) ?: [],
        glob($targetDir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'installer'
            .DIRECTORY_SEPARATOR.'vendor-backup-*', GLOB_ONLYDIR) ?: [],
    ) : [];

    foreach ($candidates as $stale) {
        if (is_link($stale)) {
            continue;
        }

        if (deleteVendorBundleDirectory($stale)) {
            $removed++;
        }
    }

    return $removed;
}

/**
 * 되돌릴 수 없는 상태로 진행한다는 사실을 로그에 남깁니다.
 *
 * 이 파일은 Laravel 부트 전에 단독 로드될 수 있으므로 로거 존재를 가정하지 않는다.
 *
 * @param  string  $message  기록할 메시지
 */
function recordVendorBundleWarning(string $message): void
{
    if (function_exists('addLog')) {
        addLog('[vendor-bundle] '.$message);

        return;
    }

    if (function_exists('logInstallationError')) {
        logInstallationError('[vendor-bundle] '.$message);

        return;
    }

    // 이 파일은 자기 의존을 require 하지 않는다 (호출부가 필요한 것을 미리 로드한다) —
    // 그래서 래퍼가 없을 수 있고, `error_log` 자체도 차단 대상이다. 위 두 분기와 같은 형태로
    // 존재를 확인하고 부른다. 셋 다 없으면 조용히 버린다: 이 함수는 진단 채널일 뿐이다.
    if (function_exists('installerErrorLog')) {
        installerErrorLog('[vendor-bundle] '.$message);

        return;
    }

    // audit:allow execution-function-availability-via-ssot 자기 의존을 require 하지 않는 파일의 최후 폴백
    if (function_exists('error_log')) {
        error_log('[vendor-bundle] '.$message);
    }
}

/**
 * 묶음에 역슬래시 경로 항목이 있는지 — 있으면 안전 추출기가 경로를 정규화하며 풀어야 한다.
 *
 * Windows 에서 만든 zip 은 경로 구분자를 역슬래시로 담을 수 있다. 그대로 extractTo 하면 리눅스에서는
 * `vendor\pkg\file.php` 라는 이름의 파일 하나가 생겨 vendor/ 가 깨진다 (코어 7.1.3 압축 해제 검사).
 *
 * @param  ZipArchive  $zip  열린 묶음
 * @return bool 역슬래시 항목이 하나라도 있으면 true
 */
function vendorBundleHasBackslashEntries(ZipArchive $zip): bool
{
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name !== false && strpos($name, '\\') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * 열린 아카이브를 묶음 단위로 추출하고 묶음마다 진행 콜백을 부릅니다.
 *
 * `extractTo($dir)` 한 번은 수천 개 파일을 푸는 동안 호출자에게 아무 제어도 돌려주지 않는다.
 * 그 사이 워커 heartbeat 가 멈추면 살아 있는 요청이 stale 로 판정되므로(10차 #M2), 항목 이름을
 * 묶어 여러 번 나눠 부른다 — `extractTo($dir, $names)` 는 디렉토리 구조를 그대로 유지하므로
 * 결과물은 한 번에 푼 것과 같다. zip slip 검증은 호출부가 이미 마쳤다.
 *
 * @param  ZipArchive  $zip  열린 아카이브
 * @param  string  $targetDir  추출 대상 디렉토리
 * @param  callable|null  $onProgress  fn (int $done, int $total): void
 * @return bool 전 묶음 추출 성공 여부
 */
function extractVendorBundleInChunks(ZipArchive $zip, string $targetDir, ?callable $onProgress = null): bool
{
    $total = $zip->numFiles;

    if ($total <= 0) {
        return $zip->extractTo($targetDir);
    }

    // 묶음 크기는 「알림 간격」과 「호출 오버헤드」의 절충이다. 500 이면 P0 실측(≈14,900 항목)
    // 기준 30회 알림 — 15초짜리 추출에서 0.5초 간격이라 어떤 stale 창보다도 촘촘하다.
    $chunkSize = 500;
    $names = [];
    $done = 0;

    for ($i = 0; $i < $total; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name !== false) {
            $names[] = $name;
        }

        if (count($names) < $chunkSize && $i < $total - 1) {
            continue;
        }

        if ($names !== [] && ! $zip->extractTo($targetDir, $names)) {
            return false;
        }

        $done += count($names);
        $names = [];

        if ($onProgress !== null) {
            $onProgress($done, $total);
        }
    }

    return true;
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
    $moved = [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === $backupDirName) {
            continue;
        }

        $src = $vendorDir.DIRECTORY_SEPARATOR.$item;
        $dst = $backupDir.DIRECTORY_SEPARATOR.$item;

        if (@rename($src, $dst)) {
            $moved[] = $item;

            continue;
        }

        // 한 항목이라도 옮기지 못하면 "백업했다" 고 말할 수 없다. 그대로 true 를 돌려주면
        // 추출 실패 시 복원 경로가 **백업되지 않은 원본까지** 지운다(백업 폴더를 뺀 vendor/
        // 전체를 삭제하기 때문). 이미 옮긴 것을 되돌려 상태를 그대로 두고, 문서화된 폴백
        // (백업 없이 in-place 덮어쓰기)으로 넘긴다.
        $stranded = [];
        foreach ($moved as $revert) {
            if (! @rename($backupDir.DIRECTORY_SEPARATOR.$revert, $vendorDir.DIRECTORY_SEPARATOR.$revert)) {
                $stranded[] = $revert;
            }
        }
        @rmdir($backupDir);

        // 되돌리기 자체가 실패하면 원본 일부가 백업 디렉토리에 남는다 (19차 F6).
        // 호출부의 경고는 「백업 실패」만 말하므로, **원본이 흩어졌다**는 사실은 여기서만
        // 남길 수 있다. 이 사실 없이 in-place 덮어쓰기로 넘어가면 그 조각들은 영영 미아가 된다.
        if ($stranded !== []) {
            recordVendorBundleWarning(lang('vendor_bundle_backup_revert_failed', [
                'items' => implode(', ', $stranded),
                'path' => $backupDir,
            ]));
        }

        return false;
    }

    return true;
}

/**
 * 백업 디렉토리에서 vendor/ 로 내용을 복원합니다.
 */
function restoreVendorFromBackup(string $vendorDir, string $backupDir, string $backupDirName): bool
{
    // 복원 실패는 조용히 지나가지 않는다 (19차 F7). 종전에는 반환값이 없어 호출부가 확인할
    // 방법 자체가 없었고, 복원에 실패하면 운영자는 **원래 실패 사유만** 보는데 vendor/ 는
    // 백업 디렉토리에 원본이 남은 채 비어 있었다.
    $restored = true;

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
        if (! @rename($src, $dst)) {
            $restored = false;
        }
    }

    @rmdir($backupDir);

    if (! $restored) {
        recordVendorBundleWarning(lang('vendor_bundle_restore_failed', ['path' => $backupDir]));
    }

    return $restored;
}

/**
 * zip 내부 파일 경로의 안전성을 검증합니다 (zip slip 방지).
 */
function checkVendorBundleZipSafety(string $zipPath): ?string
{
    // `..` 조각·절대경로·드라이브·UNC·널 문자·심볼릭 링크 거부 (safe-archive-extractor.php)
    return g7InstallFindUnsafeZipEntry($zipPath);
}

/**
 * 디렉토리 재귀 삭제 (Laravel File 파사드 의존 제거).
 */
function deleteVendorBundleDirectory(string $dir): bool
{
    if (! is_dir($dir)) {
        return false;
    }

    // 열거 실패는 삭제 실패다 (19차 F2 형제) — `false` 를 순회하면 PHP 8 에서 TypeError 다.
    $items = @scandir($dir);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir.DIRECTORY_SEPARATOR.$item;

        // 재귀 안에서도 심볼릭 링크를 따라가지 않는다 (19차 F4).
        //
        // 최상위 후보에는 이미 `is_link` 가드가 있는데(그 함수의 호출부) 재귀 내부에는 없었다.
        // 백업으로 옮겨진 링크가 있으면 그 **대상**의 내용이 지워진다 — 삭제 경계가
        // vendor/ 밖으로 나간다.
        if (is_link($path)) {
            if (! @unlink($path)) {
                @rmdir($path);
            }

            continue;
        }

        if (is_dir($path)) {
            deleteVendorBundleDirectory($path);
        } else {
            @unlink($path);
        }
    }

    return @rmdir($dir);
}

/**
 * Laravel 부트 시 재생성되는 컴파일 캐시(packages.php / services.php / config.php)를 제거합니다.
 *
 * 인스톨러는 vendor 를 교체하기 때문에 이전 환경(특히 dev) 에서 생성된 캐시가 남아있으면
 * 제거된 패키지의 ServiceProvider 를 참조하다가 "Class ... not found" 오류가 발생한다.
 * 본 함수는 Laravel\Foundation\ComposerScripts::clearCompiled() 와 동일한 3개 파일을 정리한다.
 *
 * @param  string  $basePath  프로젝트 루트 (bootstrap/cache 의 상위 디렉토리)
 * @return array<string> 실제로 삭제된 파일명 목록 (basename)
 */
function clearLaravelCompiledCache(string $basePath): array
{
    $cacheDir = $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';

    if (! is_dir($cacheDir)) {
        return [];
    }

    $cleared = [];
    foreach (['packages.php', 'services.php', 'config.php'] as $filename) {
        $path = $cacheDir.DIRECTORY_SEPARATOR.$filename;
        if (is_file($path) && @unlink($path)) {
            $cleared[] = $filename;
        }
    }

    return $cleared;
}

/**
 * 이미 준비된 vendor 가 개발용(require-dev 포함) 설치인지 조사합니다.
 *
 * 판정은 코어와 공유하는 `App\Support\ComposerInstallInfo` 가 단독으로 소유한다 — 인스톨러와
 * 코어 업데이트가 같은 vendor 를 두고 서로 다른 답을 내놓지 않도록.
 *
 * 클래스 파일 경로는 인자 `$basePath` 가 아니라 **인스톨러 자신의 트리** 기준으로 읽는다.
 * 단위 테스트는 임시 디렉토리를 `$basePath` 로 넘기는데 그 경로에는 `app/Support` 가 없다.
 *
 * @param  string  $basePath  프로젝트 루트 (vendor 의 상위 디렉토리)
 * @return array{dev: bool|null, packages: array<int, string>} dev 가 null 이면 판정 불가
 */
function detectDevVendorInstall(string $basePath): array
{
    if (! class_exists('App\\Support\\ComposerInstallInfo')) {
        $classFile = dirname(__DIR__, 3).'/app/Support/ComposerInstallInfo.php';
        if (! is_file($classFile)) {
            return ['dev' => null, 'packages' => []];
        }
        require_once $classFile;
    }

    return ComposerInstallInfo::inspect(rtrim($basePath, '/\\').'/vendor');
}

/**
 * Composer 실행이 현재 환경에서 가능한지 검사합니다.
 *
 * proc_open() 사용 가능 여부 + composer 바이너리 발견 여부를 종합 판단.
 */
function canExecuteComposerForInstall(?string $composerBinary, ?string $phpBinary): bool
{
    // proc_open 사용 가능 여부 — 판정은 코어와 공유하는 SSoT (function_exists + disable_functions)
    if (! ExecutionEnvironment::hasFunction('proc_open')) {
        return false;
    }

    // composer 바이너리가 **실제로 실행되는지**까지 본다. 경로 문자열의 비어 있지 않음만 보면
    // 이 함수는 언제나 true 라 `auto` 모드의 번들 폴백이 발동하지 않는다 — exec 는 열려 있고
    // composer 만 없는 공유 호스팅(= auto 의 대표 환경)에서 설치가 종료코드 127 로 막히고,
    // 그 태스크는 best_effort 도 skippable 도 아니라 재시도해도 같은 자리에서 멈춘다.
    //
    // 판정은 composer_check 태스크가 이미 내렸다 — 그 결과를 상태에서 읽어 두 태스크가 같은
    // 답을 쓰게 한다. 기록이 없을 때(bundled 로 건너뛴 설치 등)만 여기서 직접 확인한다.
    $recorded = function_exists('getInstallationState')
        ? (getInstallationState()['composer_available'] ?? null)
        : null;

    if ($recorded !== null) {
        return (bool) $recorded;
    }

    // 직접 확인도 composer_check 와 **같은 함수**(probeComposerForInstall, task-runner.php)로 한다. 그 함수가
    // COMPOSER_HOME·HOME 을 storage/composer 로 주입하고 exec 차단을 미설치와 같은 분기로 보낸다 — 여기서
    // 따로 exec 하면 웹 서버 계정의 HOME 이 없거나 쓸 수 없는 호스팅에서 같은 composer 가 한쪽에서만 실패해
    // 두 판정이 갈린다. 판정 함수가 실리지 않은 진입에서는 실행 가능을 증명할 수 없으므로 미설치로 본다
    // (auto 는 번들 폴백, composer 명시는 오류 — probeComposerForInstall 의 exec 차단 분기와 같은 결론).
    if (! function_exists('probeComposerForInstall')) {
        return false;
    }

    return probeComposerForInstall()['ok'];
}

/**
 * 권한 거부 시 진단 힌트를 생성합니다.
 *
 * 문구는 인스톨러 다국어(lang()) 로 낸다 — 종전에는 한국어로 고정돼 있어 en 로케일의
 * 실패 화면에 한국어가 그대로 나갔다. 그리고 `chown -R` 를 직접 처방하지 않는다
 * (§7 R39): 공유 호스팅에서는 그 명령을 쓸 수 없어 반복 실패를 부르고, 웹서버 사용자를
 * 소유자로 만드는 것은 코드·시크릿 변조 표면을 넓힌다. 진단만 내고 조치는 요구사항
 * 화면의 권한 안내(3가지 선택지)가 담당한다.
 *
 * @param  string  $path  검사 대상 디렉토리
 * @return string 진단 문구 (판정 불가 시 빈 문자열)
 */
function buildVendorBundlePermissionHint(string $path): string
{
    if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
        return '';
    }

    $currentUid = posix_geteuid();
    $currentUser = posix_getpwuid($currentUid)['name'] ?? (string) $currentUid;

    if (! file_exists($path)) {
        return ' '.lang('vendor_bundle_hint_run_user', ['user' => $currentUser]);
    }

    $ownerUid = fileowner($path);
    if ($currentUid === $ownerUid) {
        return ' '.lang('vendor_bundle_hint_owner_same', ['user' => $currentUser]);
    }

    $ownerUser = posix_getpwuid($ownerUid)['name'] ?? (string) $ownerUid;

    return ' '.lang('vendor_bundle_hint_owner_differs', [
        'user' => $currentUser,
        'owner' => $ownerUser,
    ]);
}

/**
 * 개발자 전용 composer(`custom/`, 7.2.0 #792)의 vendor 를 맞춥니다.
 *
 * custom/composer.json 이 있을 때만 동작한다. 설치본이 custom/composer.lock 과 맞으면(설치 표식
 * `vendor/.g7-custom-lock.sha256`) 건너뛴다. vendor 묶음 모드이거나 auto 인데 composer 를 쓸 수 없고
 * custom/vendor-bundle.zip 이 있으면 묶음을 무결성 검사 후 풀고, 아니면 composer install --no-dev 를
 * custom 에서 실행한다. 표식 형식은 코어 App\Extension\Custom\CustomVendorService 와 같다.
 *
 * @param  string  $basePath  설치 루트
 * @param  bool  $useBundle  묶음으로 풀지 여부 (호출자가 vendor 모드로 판정)
 * @param  callable|null  $composerRunner  composer 실행 콜백 (작업 디렉터리를 받아 성공 여부 반환)
 * @return array{success: bool, skipped?: bool, mode?: string, error?: string}
 */
function installCustomVendorIfNeeded(string $basePath, bool $useBundle, ?callable $composerRunner = null): array
{
    $customDir = rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.'custom';
    if (! file_exists($customDir.DIRECTORY_SEPARATOR.'composer.json')) {
        return ['success' => true, 'skipped' => true];
    }

    $lockFile = file_exists($customDir.DIRECTORY_SEPARATOR.'composer.lock')
        ? $customDir.DIRECTORY_SEPARATOR.'composer.lock'
        : $customDir.DIRECTORY_SEPARATOR.'composer.json';
    $lockHash = (string) hash_file('sha256', $lockFile);
    $vendorDir = $customDir.DIRECTORY_SEPARATOR.'vendor';
    $marker = $vendorDir.DIRECTORY_SEPARATOR.'.g7-custom-lock.sha256';

    if (file_exists($vendorDir.DIRECTORY_SEPARATOR.'autoload.php') && file_exists($marker)
        && trim((string) file_get_contents($marker)) === $lockHash) {
        return ['success' => true, 'skipped' => true, 'mode' => 'in-sync'];
    }

    if ($useBundle) {
        $integrity = verifyVendorBundle($customDir);
        if (! $integrity['valid']) {
            return ['success' => false, 'error' => 'custom vendor-bundle.zip: '.implode(', ', $integrity['errors'])];
        }
        $result = extractVendorBundle($customDir, $customDir);
        if (! $result['success']) {
            return ['success' => false, 'error' => 'custom vendor-bundle.zip: '.($result['error'] ?? 'unknown')];
        }
        $mode = 'bundled';
    } else {
        if ($composerRunner === null || ! $composerRunner($customDir)) {
            return ['success' => false, 'error' => 'composer install (custom) failed'];
        }
        $mode = 'composer';
    }

    if (! is_dir($vendorDir)) {
        @mkdir($vendorDir, 0755, true);
    }
    $written = @file_put_contents($marker, $lockHash);
    if ($written !== strlen($lockHash)) {
        // 잘린 표식은 다음 설치에서 해시 불일치로 읽혀 다시 설치될 뿐이지만, 잘린 파일은 남기지 않는다.
        @unlink($marker);
    }

    return ['success' => true, 'mode' => $mode];
}
