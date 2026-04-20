<?php

namespace App\Extension\Vendor;

use App\Extension\Vendor\Exceptions\VendorInstallException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * vendor-bundle.zip 을 대상 디렉토리로 추출하여 vendor/를 구성합니다.
 *
 * 공유 호스팅 친화적 설계:
 * - targetDir 자체(프로젝트 루트)의 쓰기 권한은 요구하지 않음
 * - vendor/ 디렉토리 자체는 유지하고 **내부 항목만 조작**
 * - 기존 내용은 vendor/.bundle_backup_{ts}/ 로 이동 (vendor/ 쓰기 권한만으로 가능)
 * - 추출 실패 시 백업에서 복원, 성공 시 백업 삭제
 *
 * 이 접근은 웹 서버 사용자가 프로젝트 루트에는 쓰기 권한이 없지만
 * vendor/ 디렉토리에만 권한이 부여된 전형적 공유 호스팅 환경을 지원합니다.
 */
class VendorBundleInstaller
{
    public function __construct(
        private readonly VendorIntegrityChecker $integrityChecker,
    ) {}

    /**
     * 번들 zip을 대상 디렉토리에 추출합니다.
     *
     * @param  string  $sourceDir  vendor-bundle.zip 이 위치한 디렉토리
     * @param  string  $targetDir  vendor/가 배치될 디렉토리 (일반적으로 sourceDir과 동일)
     *
     * @throws VendorInstallException
     */
    public function install(string $sourceDir, string $targetDir): VendorInstallResult
    {
        $startTime = microtime(true);

        // 1. ZipArchive 확장 확인
        if (! class_exists(\ZipArchive::class)) {
            throw new VendorInstallException('zip_archive_not_available');
        }

        // 2. 무결성 검증
        $integrity = $this->integrityChecker->verify($sourceDir);
        if (! $integrity->valid) {
            throw new VendorInstallException(
                errorKey: 'bundle_integrity_failed',
                context: ['details' => implode(', ', $integrity->errorMessages())],
            );
        }

        $zipPath = $sourceDir.DIRECTORY_SEPARATOR.VendorIntegrityChecker::ZIP_FILENAME;
        $vendorDir = $targetDir.DIRECTORY_SEPARATOR.'vendor';
        $backupDirName = '.bundle_backup_'.date('Ymd_His');
        $backupDir = $vendorDir.DIRECTORY_SEPARATOR.$backupDirName;

        // 3. 쓰기 권한 유연 검사 — vendor/ 가 있으면 vendor/ 권한만, 없으면 targetDir 권한 필요
        $this->validateTargetWritable($targetDir, $vendorDir);

        // 4. zip slip 방지 사전 검증
        $this->validateZipContents($zipPath);

        // 5. 기존 vendor/ 내부 항목을 vendor/.bundle_backup_{ts}/ 로 이동
        //    (vendor/ 디렉토리 자체는 유지 — targetDir 쓰기 권한 불필요)
        $hasBackup = false;
        if (is_dir($vendorDir)) {
            $hasBackup = $this->moveVendorContentsToBackup($vendorDir, $backupDir, $backupDirName);
        } else {
            // vendor/ 없음 — 신규 생성 (targetDir 쓰기 권한 필요, 위에서 이미 검증)
            if (! @mkdir($vendorDir, 0755, true) && ! is_dir($vendorDir)) {
                throw new VendorInstallException(
                    errorKey: 'target_not_writable',
                    context: ['path' => $vendorDir],
                );
            }
        }

        // 6. 추출
        try {
            $zip = new \ZipArchive;
            $openResult = $zip->open($zipPath);
            if ($openResult !== true) {
                throw new VendorInstallException(
                    errorKey: 'extraction_failed',
                    context: ['message' => 'ZipArchive::open failed (code: '.$openResult.')'],
                );
            }

            $extracted = $zip->extractTo($targetDir);
            $zip->close();

            if (! $extracted) {
                throw new VendorInstallException(
                    errorKey: 'extraction_failed',
                    context: ['message' => 'ZipArchive::extractTo returned false'],
                );
            }

            // 7. 성공: 백업 디렉토리 삭제 (vendor/.bundle_backup_{ts}/)
            if ($hasBackup && is_dir($backupDir)) {
                File::deleteDirectory($backupDir);
            }

            $duration = microtime(true) - $startTime;
            $packageCount = (int) ($integrity->meta['package_count'] ?? 0);
            $zipSize = @filesize($zipPath) ?: 0;

            Log::info('Vendor 번들 추출 성공', [
                'source_dir' => $sourceDir,
                'target_dir' => $targetDir,
                'package_count' => $packageCount,
                'zip_size' => $zipSize,
                'duration_seconds' => round($duration, 2),
            ]);

            return new VendorInstallResult(
                mode: VendorMode::Bundled,
                strategy: 'bundled',
                packageCount: $packageCount,
                durationSeconds: $duration,
                details: [
                    'zip_size' => $zipSize,
                    'source_dir' => $sourceDir,
                ],
            );
        } catch (\Throwable $e) {
            // 실패: 백업에서 복원
            if ($hasBackup && is_dir($backupDir)) {
                $this->restoreVendorFromBackup($vendorDir, $backupDir, $backupDirName);
            }

            if ($e instanceof VendorInstallException) {
                throw $e;
            }

            throw new VendorInstallException(
                errorKey: 'extraction_failed',
                context: ['message' => $e->getMessage()],
                previous: $e,
            );
        }
    }

    /**
     * 쓰기 권한 검사 — vendor/ 가 존재하면 vendor/ 권한만, 없으면 targetDir 권한 필요.
     *
     * 공유 호스팅 환경에서 targetDir (프로젝트 루트) 에는 쓰기 권한이 없고
     * vendor/ 에만 권한이 부여된 케이스를 지원한다.
     *
     * @throws VendorInstallException
     */
    private function validateTargetWritable(string $targetDir, string $vendorDir): void
    {
        if (is_dir($vendorDir)) {
            if (! is_writable($vendorDir)) {
                throw new VendorInstallException(
                    errorKey: 'target_not_writable',
                    context: $this->buildPermissionContext($vendorDir),
                );
            }

            return;
        }

        // vendor/ 없음 — 생성을 위해 targetDir 에 쓰기 권한 필요
        if (! is_dir($targetDir) || ! is_writable($targetDir)) {
            throw new VendorInstallException(
                errorKey: 'target_not_writable',
                context: $this->buildPermissionContext($targetDir),
            );
        }
    }

    /**
     * 권한 거부 컨텍스트에 소유권 정보를 포함합니다.
     *
     * 공유 호스팅에서 FTP 사용자와 PHP 실행 사용자가 다른 경우 진단을 돕기 위해
     * 현재 실행 사용자, 디렉토리 소유자, 권장 조치를 함께 반환합니다.
     */
    private function buildPermissionContext(string $path): array
    {
        $context = ['path' => $path];

        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            return $context;
        }

        $currentUid = posix_geteuid();
        $currentUser = posix_getpwuid($currentUid)['name'] ?? (string) $currentUid;
        $context['current_user'] = "{$currentUser}({$currentUid})";

        if (file_exists($path)) {
            $ownerUid = fileowner($path);
            $ownerUser = posix_getpwuid($ownerUid)['name'] ?? (string) $ownerUid;
            $context['owner'] = "{$ownerUser}({$ownerUid})";

            if ($currentUid !== $ownerUid) {
                $context['hint'] = __('exceptions.vendor.target_not_writable_owner_hint', [
                    'path' => $path,
                    'current_user' => $context['current_user'],
                    'current_user_name' => $currentUser,
                    'owner' => $context['owner'],
                    'owner_name' => $ownerUser,
                ]);
            }
        }

        return $context;
    }

    /**
     * 기존 vendor/ 내부 항목을 vendor/.bundle_backup_{ts}/ 로 이동합니다.
     *
     * vendor/ 디렉토리 자체는 유지하므로 targetDir 쓰기 권한이 필요하지 않습니다.
     *
     * @return bool 백업 성공 여부 (false 시 in-place overwrite 로 추출 진행)
     */
    private function moveVendorContentsToBackup(string $vendorDir, string $backupDir, string $backupDirName): bool
    {
        if (! @mkdir($backupDir) && ! is_dir($backupDir)) {
            Log::warning('Vendor 번들 백업 디렉토리 생성 실패 — in-place overwrite 로 진행', [
                'vendor_dir' => $vendorDir,
                'backup_dir' => $backupDir,
            ]);

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
     *
     * 추출 중 실패한 경우 기존 상태로 되돌리기 위해 호출됩니다.
     */
    private function restoreVendorFromBackup(string $vendorDir, string $backupDir, string $backupDirName): void
    {
        // 1. 추출로 이미 생성된 파일/디렉토리 정리 (백업 폴더 제외)
        $items = @scandir($vendorDir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === $backupDirName) {
                continue;
            }

            $path = $vendorDir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path) && ! is_link($path)) {
                File::deleteDirectory($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }

        // 2. 백업 항목을 원위치로 이동
        $backupItems = @scandir($backupDir) ?: [];
        foreach ($backupItems as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $src = $backupDir.DIRECTORY_SEPARATOR.$item;
            $dst = $vendorDir.DIRECTORY_SEPARATOR.$item;
            @rename($src, $dst);
        }

        // 3. 빈 백업 디렉토리 제거
        @rmdir($backupDir);
    }

    /**
     * zip 내부 파일 경로의 안전성을 검증합니다 (zip slip 방지).
     *
     * @throws VendorInstallException
     */
    private function validateZipContents(string $zipPath): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new VendorInstallException(
                errorKey: 'extraction_failed',
                context: ['message' => 'cannot open zip for validation'],
            );
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            // 경로 탈출 방지
            $normalized = str_replace('\\', '/', $name);
            if (
                str_contains($normalized, '../')
                || str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:/#', $normalized)
            ) {
                $zip->close();
                throw new VendorInstallException(
                    errorKey: 'bundle_contains_unsafe_path',
                    context: ['path' => $name],
                );
            }
        }

        $zip->close();
    }
}
