<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CoreBackupHelper
{
    /**
     * 코어 파일을 선택적으로 백업합니다.
     *
     * @param  array  $targets  백업 대상 경로 목록
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  array  $excludes  제외할 디렉토리/파일 이름 목록 (예: ['node_modules', '.git'])
     * @return string 백업 디렉토리 경로
     */
    public static function createBackup(array $targets, ?\Closure $onProgress = null, array $excludes = []): string
    {
        $timestamp = date('Ymd_His');
        $backupPath = storage_path("app/core_backups/core_{$timestamp}");

        File::ensureDirectoryExists($backupPath, 0770, true);

        foreach ($targets as $target) {
            $sourcePath = base_path($target);

            if (! File::exists($sourcePath) && ! File::isDirectory($sourcePath)) {
                continue;
            }

            $onProgress?->__invoke('backup', $target);
            $destPath = $backupPath.DIRECTORY_SEPARATOR.$target;

            if (File::isDirectory($sourcePath)) {
                FilePermissionHelper::copyDirectory($sourcePath, $destPath, $onProgress, $excludes);
            } else {
                FilePermissionHelper::copyFile($sourcePath, $destPath);
            }
        }

        Log::info('코어 백업 생성 완료', ['path' => $backupPath]);

        return $backupPath;
    }

    /**
     * 백업에서 코어 파일을 선택적으로 복원합니다.
     *
     * 복원 시에는 FilePermissionHelper를 사용하여 퍼미션을 보존합니다.
     * 백업 파일을 복원하되 현재 파일의 퍼미션은 유지합니다.
     * 개별 target 복원 실패 시에도 나머지 target 복원을 계속 진행합니다.
     *
     * @param  string  $backupPath  백업 디렉토리 경로
     * @param  array  $targets  복원 대상 경로 목록
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  array  $excludes  제외할 디렉토리/파일 이름 목록 (예: ['node_modules', '.git'])
     * @return array 복원 실패한 target 목록 (빈 배열이면 전체 성공)
     */
    public static function restoreFromBackup(string $backupPath, array $targets, ?\Closure $onProgress = null, array $excludes = []): array
    {
        $failedTargets = [];

        foreach ($targets as $target) {
            $src = $backupPath.DIRECTORY_SEPARATOR.$target;
            $dest = base_path($target);

            if (! File::exists($src) && ! File::isDirectory($src)) {
                continue;
            }

            try {
                $onProgress?->__invoke('restore', $target);

                if (File::isDirectory($src)) {
                    FilePermissionHelper::copyDirectory($src, $dest, $onProgress, $excludes);
                } else {
                    FilePermissionHelper::copyFile($src, $dest);
                }
            } catch (\Throwable $e) {
                Log::error("코어 백업 복원 실패 (계속 진행): {$target}", [
                    'error' => $e->getMessage(),
                    'backup_path' => $backupPath,
                ]);
                $failedTargets[] = $target;
            }
        }

        if (empty($failedTargets)) {
            Log::info('코어 백업 복원 완료', ['backup_path' => $backupPath]);
        } else {
            Log::warning('코어 백업 부분 복원 완료', [
                'backup_path' => $backupPath,
                'failed_targets' => $failedTargets,
            ]);
        }

        return $failedTargets;
    }

    /**
     * 백업을 삭제합니다.
     *
     * @param  string  $backupPath  백업 디렉토리 경로
     */
    public static function deleteBackup(string $backupPath): void
    {
        if (! File::isDirectory($backupPath)) {
            return;
        }

        File::deleteDirectory($backupPath);

        // Windows: 파일 핸들 잠금으로 빈 디렉토리가 남을 수 있음 — 재시도
        if (File::isDirectory($backupPath)) {
            usleep(500_000); // 0.5초 대기 후 재시도
            File::deleteDirectory($backupPath);
        }

        if (File::isDirectory($backupPath)) {
            Log::warning('코어 백업 삭제 불완전 (잔여 디렉토리 존재)', ['path' => $backupPath]);
        } else {
            Log::info('코어 백업 삭제 완료', ['path' => $backupPath]);
        }
    }

    /**
     * 모든 코어 백업 목록을 반환합니다.
     *
     * @return array 백업 목록 [{path, created_at, size}]
     */
    public static function listBackups(): array
    {
        $backupsDir = storage_path('app/core_backups');

        if (! File::isDirectory($backupsDir)) {
            return [];
        }

        $backups = [];
        foreach (File::directories($backupsDir) as $dir) {
            $backups[] = [
                'path' => $dir,
                'name' => basename($dir),
                'created_at' => date('Y-m-d H:i:s', filectime($dir)),
            ];
        }

        return $backups;
    }
}
