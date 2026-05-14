<?php

namespace App\Extension\Helpers;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class CoreBackupHelper
{
    /**
     * 백업 디렉토리에 기록되는 manifest 파일명.
     */
    public const NEW_FILES_MANIFEST = '_new_files_manifest.json';

    /**
     * Manifest 스키마 버전. DataMigration (Upgrade_7_0_0_beta_6) 의 사후 작성본과
     * 바이트 단위 호환 invariant — 스키마 변경 시 양쪽 동시 갱신 필수.
     */
    public const MANIFEST_SCHEMA_VERSION = 1;

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
     * 백업 디렉토리에 `_new_files_manifest.json` 이 있으면 복사 직후 신 버전 신규 파일을
     * 정리하는 prune 단계를 추가 실행한다. manifest 부재 시 기존 overlay 복사만 수행
     * (기존 동작 유지).
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

        // Manifest 가 있으면 신 버전 신규 파일 prune. 부재 시 silent skip (기존 동작).
        $manifestPath = $backupPath.DIRECTORY_SEPARATOR.self::NEW_FILES_MANIFEST;
        if (File::exists($manifestPath)) {
            try {
                $protected = (array) config('app.update.protected_paths', []);
                $pruneResult = self::pruneNewFiles($backupPath, base_path(), $protected, $onProgress);
                Log::info('코어 자동 롤백 prune 완료', [
                    'backup_path' => $backupPath,
                    'removed_files' => $pruneResult['removed_files'],
                    'removed_dirs' => $pruneResult['removed_dirs'],
                    'protected_count' => $pruneResult['protected_count'],
                    'symlink_skipped' => $pruneResult['symlink_skipped'],
                    'failed_count' => $pruneResult['failed_count'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('코어 자동 롤백 prune 실패 (overlay 복사는 완료)', [
                    'backup_path' => $backupPath,
                    'error' => $e->getMessage(),
                ]);
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

    /**
     * 신 버전이 추가하는 파일/디렉토리 목록을 백업 디렉토리에 manifest 로 기록합니다.
     *
     * 비교 기준:
     *  - 백업 디렉토리 (`$backupPath/$target`) = 활성 디렉토리의 사전 스냅샷 (= 현재 디스크의 구버전)
     *  - 소스 디렉토리 (`$sourcePath/$target`) = 신 버전 _pending 소스
     *  - 신규 항목 정의 = `_pending` 에는 존재 + 백업에는 없는 파일/디렉토리
     *
     * 자동 롤백 시 `restoreFromBackup()` 이 본 manifest 를 참조하여 활성 디렉토리에서
     * 정확히 그 항목만 prune. 사용자가 활성 디렉토리에 직접 추가한 파일은 백업에 포함되어
     * 있으므로 manifest 에서 제외되어 보존된다.
     *
     * `protectedPaths` 와 `excludes` 하위는 manifest 에서 사전 제외 (방어 깊이). `pruneNewFiles`
     * 도 동일 가드를 재실행하므로 이중 방어.
     *
     * @param  string  $backupPath  백업 디렉토리 경로 (= 활성 사전 스냅샷)
     * @param  string  $sourcePath  소스 디렉토리 경로 (= _pending 신 버전)
     * @param  array  $targets  처리할 target 경로 목록 (`app.update.targets`)
     * @param  array  $protectedPaths  보호 경로 목록 (manifest 에서 제외)
     * @param  array  $excludes  제외 패턴 목록 (예: ['node_modules', '.git'])
     * @param  string  $fromVersion  시작 버전 (manifest 기록용)
     * @param  string  $toVersion  대상 버전 (manifest 기록용)
     * @return array{new_files_count:int, new_dirs_count:int}
     */
    public static function writeNewFilesManifest(
        string $backupPath,
        string $sourcePath,
        array $targets,
        array $protectedPaths,
        array $excludes,
        string $fromVersion,
        string $toVersion,
    ): array {
        $newFiles = [];
        $newDirs = [];

        $protectedSet = self::normalizeProtectedSet($protectedPaths);
        $excludeSet = array_values(array_filter(array_map('trim', $excludes)));

        foreach ($targets as $target) {
            $target = trim($target);
            if ($target === '') {
                continue;
            }

            // target 자체가 보호 경로면 스킵
            if (self::isWithinProtectedPath($target, $protectedSet)) {
                continue;
            }

            $sourceTargetPath = $sourcePath.DIRECTORY_SEPARATOR.$target;
            if (! file_exists($sourceTargetPath)) {
                continue;
            }

            // 단일 파일 target
            if (is_file($sourceTargetPath) && ! is_link($sourceTargetPath)) {
                $backupFilePath = $backupPath.DIRECTORY_SEPARATOR.$target;
                if (! file_exists($backupFilePath)) {
                    $newFiles[] = self::normalizeRelative($target);
                }

                continue;
            }

            if (! is_dir($sourceTargetPath)) {
                continue;
            }

            // 디렉토리 target — 재귀 비교
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceTargetPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                /** @var SplFileInfo $item */
                $absolute = $item->getPathname();
                $relative = self::normalizeRelative($target.'/'.ltrim(substr($absolute, strlen($sourceTargetPath)), DIRECTORY_SEPARATOR.'/'));

                if (self::matchesExcludes($relative, $excludeSet)) {
                    continue;
                }
                if (self::isWithinProtectedPath($relative, $protectedSet)) {
                    continue;
                }

                $backupItemPath = $backupPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

                if ($item->isDir() && ! $item->isLink()) {
                    if (! is_dir($backupItemPath)) {
                        $newDirs[] = $relative;
                    }
                } elseif ($item->isFile()) {
                    if (! file_exists($backupItemPath)) {
                        $newFiles[] = $relative;
                    }
                }
                // symlink 는 manifest 에 등재하지 않음 — prune 도 어차피 skip
            }
        }

        sort($newFiles, SORT_STRING);
        sort($newDirs, SORT_STRING);

        $manifest = [
            'version' => self::MANIFEST_SCHEMA_VERSION,
            'created_at' => date('c'),
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'new_files' => $newFiles,
            'new_dirs' => $newDirs,
        ];

        File::ensureDirectoryExists($backupPath);
        $manifestPath = $backupPath.DIRECTORY_SEPARATOR.self::NEW_FILES_MANIFEST;
        File::put($manifestPath, json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        Log::info('코어 신규 파일 manifest 작성 완료', [
            'manifest_path' => $manifestPath,
            'new_files_count' => count($newFiles),
            'new_dirs_count' => count($newDirs),
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
        ]);

        return [
            'new_files_count' => count($newFiles),
            'new_dirs_count' => count($newDirs),
        ];
    }

    /**
     * 백업 디렉토리의 manifest 를 로드해 활성 디렉토리에서 신 버전 신규 파일을 정리합니다.
     *
     * 안전 가드:
     *  - symlink 는 manifest 에 있더라도 무조건 skip (`public/storage` 등 운영 데이터 보호)
     *  - protected_paths 하위 항목은 prune 시점에 재검증되어 skip (이중 방어)
     *  - manifest 부재/JSON 파싱 실패 시 noop + manifest_loaded:false 반환
     *  - 디렉토리는 깊이 역순으로 rmdir 시도. 빈 디렉토리만 제거 (사용자 파일이 있으면 유지)
     *  - 개별 항목 삭제 실패는 fatal 이 아닌 warning + failed_count 누적
     *
     * @param  string  $backupPath  백업 디렉토리 경로 (manifest 위치)
     * @param  string  $activePath  활성(= 정리 대상) 디렉토리 경로
     * @param  array  $protectedPaths  보호 경로 목록 (이중 가드)
     * @param  \Closure|null  $onProgress  진행 콜백 (현재 미사용 — 호출 시그니처 호환용)
     * @return array{removed_files:int, removed_dirs:int, protected_count:int, symlink_skipped:int, failed_count:int, manifest_loaded:bool}
     */
    public static function pruneNewFiles(
        string $backupPath,
        string $activePath,
        array $protectedPaths,
        ?\Closure $onProgress = null,
    ): array {
        $result = [
            'removed_files' => 0,
            'removed_dirs' => 0,
            'protected_count' => 0,
            'symlink_skipped' => 0,
            'failed_count' => 0,
            'manifest_loaded' => false,
        ];

        $manifestPath = $backupPath.DIRECTORY_SEPARATOR.self::NEW_FILES_MANIFEST;
        if (! File::exists($manifestPath)) {
            return $result;
        }

        $raw = @file_get_contents($manifestPath);
        if ($raw === false) {
            Log::warning('코어 prune: manifest 읽기 실패 — skip', ['path' => $manifestPath]);

            return $result;
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest) || ! isset($manifest['new_files']) || ! isset($manifest['new_dirs'])) {
            Log::warning('코어 prune: manifest JSON 파싱 실패 — skip', ['path' => $manifestPath]);

            return $result;
        }

        $result['manifest_loaded'] = true;

        $protectedSet = self::normalizeProtectedSet($protectedPaths);

        // 신규 파일 정리
        foreach ((array) $manifest['new_files'] as $rel) {
            if (! is_string($rel) || $rel === '') {
                continue;
            }
            $rel = self::normalizeRelative($rel);

            if (self::isWithinProtectedPath($rel, $protectedSet)) {
                $result['protected_count']++;

                continue;
            }

            $absolute = $activePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);

            if (is_link($absolute)) {
                $result['symlink_skipped']++;

                continue;
            }

            if (! file_exists($absolute)) {
                continue; // 이미 부재 — skip
            }

            if (is_dir($absolute) && ! is_link($absolute)) {
                // 파일로 등재되었으나 실제는 디렉토리 — 안전 우선, skip
                continue;
            }

            if (@unlink($absolute)) {
                $result['removed_files']++;
            } else {
                $result['failed_count']++;
                Log::warning('코어 prune: 파일 삭제 실패', ['path' => $absolute]);
            }
        }

        // 신규 디렉토리 정리 — 깊이 역순으로 빈 디렉토리만 rmdir
        $dirs = array_values(array_filter((array) $manifest['new_dirs'], 'is_string'));
        usort($dirs, fn ($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));

        foreach ($dirs as $rel) {
            $rel = self::normalizeRelative($rel);

            if (self::isWithinProtectedPath($rel, $protectedSet)) {
                $result['protected_count']++;

                continue;
            }

            $absolute = $activePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);

            if (is_link($absolute)) {
                $result['symlink_skipped']++;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            // 빈 디렉토리만 rmdir — 사용자 파일이 남아있으면 유지
            if (self::isEmptyDirectory($absolute)) {
                if (@rmdir($absolute)) {
                    $result['removed_dirs']++;
                } else {
                    $result['failed_count']++;
                    Log::warning('코어 prune: 디렉토리 삭제 실패', ['path' => $absolute]);
                }
            }
        }

        return $result;
    }

    /**
     * protected_paths 배열을 정규화된 비교 집합으로 변환합니다.
     *
     * 슬래시 정규화 + 좌우 공백 제거 + 빈 항목 제거 + 최상위/하위 prefix 매칭에 사용.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private static function normalizeProtectedSet(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $out[] = self::normalizeRelative($p);
        }

        return $out;
    }

    /**
     * 상대 경로가 protected_paths 목록의 어떤 항목 하위에 위치하는지 검사합니다.
     */
    private static function isWithinProtectedPath(string $relative, array $protectedSet): bool
    {
        $relative = self::normalizeRelative($relative);
        foreach ($protectedSet as $p) {
            if ($p === '') {
                continue;
            }
            if ($relative === $p) {
                return true;
            }
            if (str_starts_with($relative, $p.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 상대 경로가 excludes 패턴(이름 또는 슬래시 포함 경로) 에 매칭되는지 검사합니다.
     *
     * 단순 이름(슬래시 미포함) 은 경로의 어떤 세그먼트와도 매칭 — `node_modules` 처럼
     * 깊이 무관 제외 패턴 의도와 일치.
     */
    private static function matchesExcludes(string $relative, array $excludes): bool
    {
        $segments = explode('/', $relative);
        foreach ($excludes as $exclude) {
            if ($exclude === '') {
                continue;
            }
            if (str_contains($exclude, '/')) {
                if ($relative === $exclude || str_starts_with($relative, $exclude.'/')) {
                    return true;
                }
            } else {
                if (in_array($exclude, $segments, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 경로 문자열을 슬래시 정규화 + 좌측 슬래시 제거.
     */
    private static function normalizeRelative(string $path): string
    {
        $p = str_replace('\\', '/', $path);
        $p = ltrim($p, '/');
        // 연속 슬래시 제거
        while (str_contains($p, '//')) {
            $p = str_replace('//', '/', $p);
        }

        return $p;
    }

    /**
     * 디렉토리가 비어있는지 검사 (`.` `..` 제외).
     */
    private static function isEmptyDirectory(string $path): bool
    {
        if (! is_dir($path)) {
            return true;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            return false;
        }

        return count(array_diff($entries, ['.', '..'])) === 0;
    }
}
