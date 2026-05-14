<?php

namespace App\Upgrades\Data\V7_0_0_beta_6\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * beta.5 사용자의 사후 manifest 작성 — 자동 롤백 신규 파일 prune 의 한계 보완.
 *
 * 배경: beta.5 → beta.6 업그레이드 시 부모 프로세스(beta.5 CoreUpdateCommand) 는 본
 * 브랜치의 Step 6.5 / restoreFromBackup 확장 로직을 모른다. 따라서 부모 catch 가
 * 트리거되어도 manifest 가 없어 자동 prune 이 동작하지 않는다.
 *
 * 다만 Step 10 의 spawn 자식은 beta.6 메모리로 실행되므로, 그 시점에 사후 manifest 를
 * 백업 디렉토리에 작성할 수 있다. 부모 catch 의 즉시 자동 prune 효과는 없지만:
 *
 *  - 백업 디렉토리가 보존된 채로 fatal 이 발생한 경우 (Step 10 이후 ~ Step 11 cleanup
 *    직전 구간), 운영자 수동 복구 / `hotfix:rollback-stale-files` 가 manifest 를 활용
 *    가능
 *  - beta.6 이후의 업그레이드 사이클부터는 Step 6.5 가 정상 동작하므로 이중 보장
 *
 * 격리 원칙 (docs/extension/upgrade-step-guide.md §4, §13):
 *  - `CoreBackupHelper::writeNewFilesManifest()` 등 신 코드 직접 호출 금지
 *  - 모든 비교/직렬화 로직을 본 클래스의 private 메서드로 중복 구현
 *  - manifest JSON 스키마는 `CoreBackupHelper::MANIFEST_SCHEMA_VERSION = 1` 과 바이트
 *    단위 호환 (회귀 테스트 Beta6BackfillManifestTest 시나리오 4 가 invariant 강제)
 *
 * 멱등성: manifest 가 이미 존재하면 noop (created_at 변동 없음). 백업 디렉토리 부재
 * (예: --no-backup 모드로 부모가 진행) 시 정상 종료 + log 기록.
 */
final class BackfillNewFilesManifest implements DataMigration
{
    private const MANIFEST_FILENAME = '_new_files_manifest.json';

    private const MANIFEST_SCHEMA_VERSION = 1;

    public function name(): string
    {
        return 'BackfillNewFilesManifest';
    }

    public function run(UpgradeContext $context): void
    {
        try {
            $this->runInternal($context);
        } catch (\Throwable $e) {
            // 본 보완 로직은 실패해도 업그레이드 본체를 중단하지 않는다.
            $context->logger->warning(sprintf(
                '[7.0.0-beta.6] BackfillNewFilesManifest 실패 (계속 진행): %s',
                $e->getMessage(),
            ));
            Log::warning('beta.6 BackfillNewFilesManifest 실패', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function runInternal(UpgradeContext $context): void
    {
        $backupsDir = storage_path('app'.DIRECTORY_SEPARATOR.'core_backups');

        if (! is_dir($backupsDir)) {
            $context->logger->info('[7.0.0-beta.6] core_backups 디렉토리 부재 — manifest 사후 작성 skip');

            return;
        }

        $latestBackup = $this->findLatestBackupDir($backupsDir);
        if ($latestBackup === null) {
            $context->logger->info('[7.0.0-beta.6] core_backups 비어 있음 — manifest 사후 작성 skip');

            return;
        }

        $manifestPath = $latestBackup.DIRECTORY_SEPARATOR.self::MANIFEST_FILENAME;
        if (file_exists($manifestPath)) {
            $context->logger->info(sprintf(
                '[7.0.0-beta.6] manifest 이미 존재 — noop (path=%s)',
                $manifestPath,
            ));

            return;
        }

        // 신 버전 디스크의 config 를 require — 부모 메모리의 stale config 와 무관한 SSoT
        $config = $this->loadCoreUpdateConfigFromDisk();
        $targets = $config['targets'];
        $protectedPaths = $config['protected_paths'];
        $excludes = $config['excludes'];

        $activeRoot = base_path();
        $newFiles = [];
        $newDirs = [];

        $protectedSet = $this->normalizeProtectedSet($protectedPaths);
        $excludeSet = array_values(array_filter(array_map('trim', $excludes)));

        foreach ($targets as $target) {
            $target = trim((string) $target);
            if ($target === '') {
                continue;
            }
            if ($this->isWithinProtectedPath($target, $protectedSet)) {
                continue;
            }

            $activeTargetPath = $activeRoot.DIRECTORY_SEPARATOR.$target;
            if (! file_exists($activeTargetPath)) {
                continue;
            }

            if (is_file($activeTargetPath) && ! is_link($activeTargetPath)) {
                $backupItem = $latestBackup.DIRECTORY_SEPARATOR.$target;
                if (! file_exists($backupItem)) {
                    $newFiles[] = $this->normalizeRelative($target);
                }

                continue;
            }

            if (! is_dir($activeTargetPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($activeTargetPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                /** @var SplFileInfo $item */
                $absolute = $item->getPathname();
                $relative = $this->normalizeRelative(
                    $target.'/'.ltrim(substr($absolute, strlen($activeTargetPath)), DIRECTORY_SEPARATOR.'/'),
                );

                if ($this->matchesExcludes($relative, $excludeSet)) {
                    continue;
                }
                if ($this->isWithinProtectedPath($relative, $protectedSet)) {
                    continue;
                }

                $backupItemPath = $latestBackup.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

                if ($item->isDir() && ! $item->isLink()) {
                    if (! is_dir($backupItemPath)) {
                        $newDirs[] = $relative;
                    }
                } elseif ($item->isFile()) {
                    if (! file_exists($backupItemPath)) {
                        $newFiles[] = $relative;
                    }
                }
            }
        }

        sort($newFiles, SORT_STRING);
        sort($newDirs, SORT_STRING);

        $manifest = [
            'version' => self::MANIFEST_SCHEMA_VERSION,
            'created_at' => date('c'),
            'from_version' => $context->fromVersion,
            'to_version' => $context->toVersion,
            'new_files' => $newFiles,
            'new_dirs' => $newDirs,
        ];

        File::put(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $context->logger->info(sprintf(
            '[7.0.0-beta.6] manifest 사후 작성 완료 — backup=%s, new_files=%d, new_dirs=%d',
            $latestBackup,
            count($newFiles),
            count($newDirs),
        ));
    }

    /**
     * `storage/app/core_backups/` 의 가장 최근 백업 디렉토리를 식별.
     *
     * CoreBackupHelper::listBackups() 직접 호출 금지 — 로컬 scandir 로 동일 결과 산출.
     */
    private function findLatestBackupDir(string $backupsDir): ?string
    {
        $entries = @scandir($backupsDir);
        if ($entries === false) {
            return null;
        }

        $candidates = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $backupsDir.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($full)) {
                continue;
            }
            $candidates[$full] = (int) @filemtime($full);
        }

        if ($candidates === []) {
            return null;
        }

        arsort($candidates, SORT_NUMERIC);

        return (string) array_key_first($candidates);
    }

    /**
     * 신 버전 디스크의 `config/app.php` 에서 update 관련 설정 추출.
     *
     * 부모 메모리의 stale `config()` 캐시를 우회하기 위해 디스크의 config 를 직접 require.
     * env() 호출이 안전한 default 값을 반환하므로 신 버전 적용 후의 fresh 값을 얻을 수 있다.
     *
     * @return array{targets:array<int,string>, protected_paths:array<int,string>, excludes:array<int,string>}
     */
    private function loadCoreUpdateConfigFromDisk(): array
    {
        // 부모 메모리의 config 사용을 우회 — 디스크 SSoT 의 신 버전 config 로드
        $appConfig = require base_path('config'.DIRECTORY_SEPARATOR.'app.php');

        $update = $appConfig['update'] ?? [];

        return [
            'targets' => (array) ($update['targets'] ?? []),
            'protected_paths' => (array) ($update['protected_paths'] ?? []),
            'excludes' => (array) ($update['excludes'] ?? []),
        ];
    }

    private function normalizeProtectedSet(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $out[] = $this->normalizeRelative($p);
        }

        return $out;
    }

    private function isWithinProtectedPath(string $relative, array $protectedSet): bool
    {
        $relative = $this->normalizeRelative($relative);
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

    private function matchesExcludes(string $relative, array $excludes): bool
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

    private function normalizeRelative(string $path): string
    {
        $p = str_replace('\\', '/', $path);
        $p = ltrim($p, '/');
        while (str_contains($p, '//')) {
            $p = str_replace('//', '/', $p);
        }

        return $p;
    }
}
