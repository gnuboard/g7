<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\File;

/**
 * 확장 백업/복원 헬퍼
 *
 * 업데이트 시 롤백을 위한 백업 생성/복원/삭제 기능을 제공합니다.
 * 백업 위치: storage/app/extension_backups/{type}/{identifier}_{timestamp}/
 */
class ExtensionBackupHelper
{
    /**
     * 확장 디렉토리를 백업합니다.
     *
     * @param  string  $type  확장 타입 ('modules', 'plugins', 'templates')
     * @param  string  $identifier  확장 식별자
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return string 백업 경로
     *
     * @throws \RuntimeException 소스 디렉토리가 없거나 복사 실패 시
     */
    public static function createBackup(string $type, string $identifier, ?\Closure $onProgress = null): string
    {
        $sourcePath = base_path("{$type}/{$identifier}");

        if (! File::isDirectory($sourcePath)) {
            throw new \RuntimeException(
                "Backup source directory does not exist: {$sourcePath}"
            );
        }

        $timestamp = date('Ymd_His');
        $backupRoot = storage_path('app/extension_backups');
        $backupTypeDir = storage_path("app/extension_backups/{$type}");
        $backupPath = storage_path("app/extension_backups/{$type}/{$identifier}_{$timestamp}");

        // 백업 부모 체인(extension_backups → {type})을 php-fpm 그룹이 쓸 수 있도록 보장한다.
        //
        // sudo 업데이트가 이 디렉토리들을 root/운영자 소유 + g-w(0755) 로 만들어 두면, 이후
        // www-data(php-fpm) 가 그 안에 백업 디렉토리를 mkdir 하지 못해 "mkdir(): Permission
        // denied" 로 실패한다(백업 생성 단계 중단). 디렉토리를 g+w(0775) 로 생성하고, 이미
        // 존재하면 부모 소유권 상속 + g+w 승격으로 정상화한다. 소유자가 아니라 chmod 가
        // 불가한 환경에서는 silent no-op(멱등) — 운영자가 디렉토리 권한을 직접 부여한다.
        foreach ([$backupRoot, $backupTypeDir] as $dir) {
            File::ensureDirectoryExists($dir, 0775);
            FilePermissionHelper::inheritOwnershipFromParent($dir);
            FilePermissionHelper::syncGroupWritability($dir);
        }

        // 파일별 복사로 진행 상세 보고
        self::copyDirectoryWithProgress($sourcePath, $backupPath, $sourcePath, $onProgress);

        return $backupPath;
    }

    /**
     * 디렉토리를 파일별로 복사하며 진행 콜백에 개별 파일을 보고합니다.
     *
     * @param  string  $source  소스 디렉토리
     * @param  string  $dest  대상 디렉토리
     * @param  string  $basePath  상대 경로 계산 기준
     * @param  \Closure|null  $onProgress  진행 콜백
     */
    private static function copyDirectoryWithProgress(
        string $source,
        string $dest,
        string $basePath,
        ?\Closure $onProgress = null
    ): void {
        File::ensureDirectoryExists($dest, 0775);
        // 백업 디렉토리 트리도 부모 소유권을 상속해 sudo update 후 root 소유 잔존을 차단한다.
        FilePermissionHelper::inheritOwnershipFromParent($dest);
        $items = new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            // 제외 대상 디렉토리는 건너뛰기 (PendingHelper와 동일 목록)
            if ($item->isDir() && in_array($item->getBasename(), ExtensionPendingHelper::EXCLUDED_DIRECTORIES, true)) {
                continue;
            }

            $target = $dest.DIRECTORY_SEPARATOR.$item->getBasename();
            $relativePath = ltrim(str_replace($basePath, '', $item->getPathname()), '/\\');

            if ($item->isDir()) {
                self::copyDirectoryWithProgress($item->getPathname(), $target, $basePath, $onProgress);
            } else {
                $onProgress?->__invoke(null, $relativePath);
                FilePermissionHelper::copyFile($item->getPathname(), $target);
            }
        }
    }

    /**
     * 백업으로부터 확장 디렉토리를 복원합니다.
     *
     * ExtensionPendingHelper::copyToActive()의 원자적 교체 패턴을 사용합니다.
     *
     * @param  string  $type  확장 타입 ('modules', 'plugins', 'templates')
     * @param  string  $identifier  확장 식별자
     * @param  string  $backupPath  백업 경로
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     *
     * @throws \RuntimeException 백업 디렉토리가 없을 때
     *
     * @see ExtensionPendingHelper::copyToActive() 원자적 디렉토리 교체 로직
     */
    public static function restoreFromBackup(string $type, string $identifier, string $backupPath, ?\Closure $onProgress = null): void
    {
        if (! File::isDirectory($backupPath)) {
            throw new \RuntimeException(
                "Backup directory does not exist: {$backupPath}"
            );
        }

        $targetPath = base_path("{$type}/{$identifier}");

        // 원자적 복원: ExtensionPendingHelper의 안전한 교체 로직 사용
        ExtensionPendingHelper::copyToActive($backupPath, $targetPath, $onProgress);
    }

    /**
     * 백업 디렉토리를 삭제합니다.
     *
     * @param  string  $backupPath  백업 경로
     */
    public static function deleteBackup(string $backupPath): void
    {
        if (File::isDirectory($backupPath)) {
            File::deleteDirectory($backupPath);
        }
    }
}
