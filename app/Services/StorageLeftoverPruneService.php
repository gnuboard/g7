<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * 스토리지 잔존물 정리 서비스 (`storage:prune-leftovers`)
 *
 * 확장/코어 업데이트·설치가 중단되며 남긴 임시 산출물과 오래된 백업본을 회수합니다.
 * 각 산출물의 생성 지점은 best-effort 정리(finally)만 가지고 있어, 강제 종료·파일
 * 잠금 환경에서 남은 잔존물은 어떤 경로도 회수하지 않았습니다 (공개이슈 #120 동형 —
 * 산출물 누적 + 회수 경로 부재). `CleanupExtensionBundlesCommand` →
 * `ExtensionBundleService` 위임 패턴을 미러합니다.
 *
 * 파괴 방지 가드:
 *  - `_pending` 직하위는 타임스탬프/교체 마커 패턴 매칭 디렉토리만 대상 —
 *    다운로드 대기 정상 확장 디렉토리(접미사 없음)는 불가침.
 *  - 백업은 identifier 별 최신 1개를 나이와 무관하게 상시 보존 (롤백 능력 유지).
 *  - 판정은 전부 mtime 기준 — 진행 중 작업의 산출물은 신선해서 걸리지 않는다.
 */
class StorageLeftoverPruneService
{
    /**
     * 업데이트 스테이징 타임스탬프 접미사 (`ExtensionPendingHelper::createUpdateStagingPath`
     * 의 `{identifier}_{Ymd_His}` 형식).
     */
    private const STAGING_TIMESTAMP_PATTERN = '/_\d{8}_\d{6}$/';

    /**
     * 디렉토리 교체 임시 마커 (`{identifier}_updating_{uniqid}` / `{identifier}_old_{uniqid}`).
     * `cleanupSwapLeftovers()` 는 다음 업데이트 시점에만 재시도하므로, 업데이트가 다시
     * 실행되지 않는 확장의 잔존물은 여기서만 회수된다.
     */
    private const SWAP_LEFTOVER_PATTERN = '/_(?:updating|old)_[0-9a-f]{13}$/';

    /**
     * 백업 디렉토리 이름 (`{identifier}_{Ymd_His}`) — ExtensionBackupHelper::createBackup.
     */
    private const BACKUP_NAME_PATTERN = '/^(.+)_(\d{8}_\d{6})$/';

    /**
     * 코어 백업 디렉토리 이름 (`core_{Ymd_His}`) — CoreBackupHelper::createBackup.
     */
    private const CORE_BACKUP_NAME_PATTERN = '/^core_(\d{8}_\d{6})$/';

    /**
     * @param  string|null  $baseRoot  프로젝트 루트 재정의 (테스트 격리용, 기본 base_path())
     * @param  string|null  $storageRoot  스토리지 루트 재정의 (테스트 격리용, 기본 storage_path())
     */
    public function __construct(
        private readonly ?string $baseRoot = null,
        private readonly ?string $storageRoot = null,
    ) {}

    /**
     * 잔존물을 대상군별로 정리하고 삭제(예정) 경로 목록을 돌려줍니다.
     *
     * @param  int  $days  임시 산출물 보존일 (mtime 기준, 경과분 삭제)
     * @param  int  $backupDays  백업 보존일 (최신 1개는 나이와 무관하게 보존)
     * @param  bool  $dryRun  true 면 삭제 없이 대상 목록만 수집
     * @return array<string, list<string>> 대상군별 삭제(예정) 경로 목록
     */
    public function prune(int $days = 3, int $backupDays = 30, bool $dryRun = false): array
    {
        $now = time();
        $cutoff = $now - $days * 86400;
        $backupCutoff = $now - $backupDays * 86400;

        return [
            'staging' => $this->pruneUpdateStaging($cutoff, $dryRun),
            'temp' => $this->pruneCoreTemp($cutoff, $dryRun),
            'vendor_bundle_staging' => $this->pruneVendorBundleStaging($cutoff, $dryRun),
            'extension_backups' => $this->pruneExtensionBackups($backupCutoff, $dryRun),
            'core_backups' => $this->pruneCoreBackups($backupCutoff, $dryRun),
            'legacy_browser_log' => $this->pruneLegacyBrowserLog($dryRun),
        ];
    }

    /**
     * `{modules,plugins,templates}/_pending` 의 스테이징/교체 잔존물을 정리합니다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneUpdateStaging(int $cutoff, bool $dryRun): array
    {
        $pruned = [];

        foreach (['modules', 'plugins', 'templates'] as $type) {
            $pendingRoot = $this->basePath($type.DIRECTORY_SEPARATOR.'_pending');

            if (! File::isDirectory($pendingRoot)) {
                continue;
            }

            foreach (File::directories($pendingRoot) as $dir) {
                $name = basename($dir);

                // 타임스탬프/교체 마커가 없는 디렉토리는 다운로드 대기 정상 확장 — 불가침.
                if (! preg_match(self::STAGING_TIMESTAMP_PATTERN, $name)
                    && ! preg_match(self::SWAP_LEFTOVER_PATTERN, $name)) {
                    continue;
                }

                if ($this->isFresh($dir, $cutoff)) {
                    continue;
                }

                $pruned[] = $dir;

                if (! $dryRun) {
                    File::deleteDirectory($dir);
                }
            }
        }

        return $pruned;
    }

    /**
     * `storage/app/temp` 직하위 전부(디렉토리·파일)를 보존일 기준으로 정리합니다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneCoreTemp(int $cutoff, bool $dryRun): array
    {
        return $this->pruneEntriesByAge(
            $this->storagePath('app'.DIRECTORY_SEPARATOR.'temp'.DIRECTORY_SEPARATOR.'*'),
            $cutoff,
            $dryRun
        );
    }

    /**
     * vendor 번들 스테이징(`build-*`)을 보존일 기준으로 정리합니다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneVendorBundleStaging(int $cutoff, bool $dryRun): array
    {
        return $this->pruneEntriesByAge(
            $this->storagePath('app'.DIRECTORY_SEPARATOR.'vendor-bundle-staging'.DIRECTORY_SEPARATOR.'build-*'),
            $cutoff,
            $dryRun
        );
    }

    /**
     * 확장 백업(`extension_backups/{type}/{identifier}_{Ymd_His}`)을 정리합니다.
     *
     * identifier 별 최신 1개는 나이와 무관하게 보존하고, 나머지 중 보존일 경과분만
     * 삭제합니다. type 디렉토리는 열거로 얻으므로 새 백업 유형(lang-packs 등)도
     * 하드코딩 없이 같은 규칙을 받습니다.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneExtensionBackups(int $backupCutoff, bool $dryRun): array
    {
        $pruned = [];
        $backupRoot = $this->storagePath('app'.DIRECTORY_SEPARATOR.'extension_backups');

        if (! File::isDirectory($backupRoot)) {
            return $pruned;
        }

        foreach (File::directories($backupRoot) as $typeDir) {
            /** @var array<string, list<array{path: string, stamp: string}>> $byIdentifier */
            $byIdentifier = [];

            foreach (File::directories($typeDir) as $dir) {
                if (! preg_match(self::BACKUP_NAME_PATTERN, basename($dir), $matches)) {
                    continue;
                }

                $byIdentifier[$matches[1]][] = ['path' => $dir, 'stamp' => $matches[2]];
            }

            foreach ($byIdentifier as $entries) {
                array_push($pruned, ...$this->pruneBackupSet($entries, $backupCutoff, $dryRun));
            }
        }

        return $pruned;
    }

    /**
     * 코어 백업(`core_backups/core_{Ymd_His}`)을 정리합니다 — 최신 1개 상시 보존.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneCoreBackups(int $backupCutoff, bool $dryRun): array
    {
        $backupRoot = $this->storagePath('app'.DIRECTORY_SEPARATOR.'core_backups');

        if (! File::isDirectory($backupRoot)) {
            return [];
        }

        $entries = [];

        foreach (File::directories($backupRoot) as $dir) {
            if (! preg_match(self::CORE_BACKUP_NAME_PATTERN, basename($dir), $matches)) {
                continue;
            }

            $entries[] = ['path' => $dir, 'stamp' => $matches[1]];
        }

        return $this->pruneBackupSet($entries, $backupCutoff, $dryRun);
    }

    /**
     * 레거시 단일 `storage/logs/browser.log` 를 정리합니다.
     *
     * 브라우저 로그가 daily 드라이버로 전환되어 이 파일에 쓰는 코드는 더 이상 없다 —
     * 존재 자체가 잔존물이므로 나이와 무관하게 삭제합니다. 날짜별 `browser-*.log` 는
     * daily 드라이버가 보존일로 자체 정리하므로 대상이 아닙니다.
     *
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneLegacyBrowserLog(bool $dryRun): array
    {
        $path = $this->storagePath('logs'.DIRECTORY_SEPARATOR.'browser.log');

        if (! File::exists($path)) {
            return [];
        }

        if (! $dryRun) {
            File::delete($path);
        }

        return [$path];
    }

    /**
     * 같은 백업 집합에서 최신 1개를 제외하고 보존일 경과분을 삭제합니다.
     *
     * 최신 판정은 디렉토리명 타임스탬프(`Ymd_His` — 사전순 = 시간순) 기준입니다.
     *
     * @param  list<array{path: string, stamp: string}>  $entries  같은 identifier 의 백업 목록
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneBackupSet(array $entries, int $backupCutoff, bool $dryRun): array
    {
        if ($entries === []) {
            return [];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));

        // 최신 1개는 롤백 자산 — 나이와 무관하게 보존.
        array_shift($entries);

        $pruned = [];

        foreach ($entries as $entry) {
            if ($this->isFresh($entry['path'], $backupCutoff)) {
                continue;
            }

            $pruned[] = $entry['path'];

            if (! $dryRun) {
                File::deleteDirectory($entry['path']);
            }
        }

        return $pruned;
    }

    /**
     * glob 패턴에 걸리는 항목(디렉토리·파일)을 보존일 기준으로 정리합니다.
     *
     * @param  string  $pattern  glob 패턴
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneEntriesByAge(string $pattern, int $cutoff, bool $dryRun): array
    {
        $pruned = [];

        foreach (File::glob($pattern) ?: [] as $entry) {
            if ($this->isFresh($entry, $cutoff)) {
                continue;
            }

            $pruned[] = $entry;

            if (! $dryRun) {
                is_dir($entry) ? File::deleteDirectory($entry) : File::delete($entry);
            }
        }

        return $pruned;
    }

    /**
     * 항목의 mtime 이 보존일 이내인지 판정합니다 (mtime 조회 실패 시 보존 — fail-safe).
     *
     * @param  string  $path  대상 경로
     * @param  int  $cutoff  기준 시각 (Unix time)
     * @return bool 보존일 이내(삭제 금지)면 true
     */
    private function isFresh(string $path, int $cutoff): bool
    {
        $mtime = @filemtime($path);

        return $mtime === false || $mtime > $cutoff;
    }

    /**
     * 프로젝트 루트 기준 경로를 만듭니다.
     *
     * @param  string  $path  루트 기준 상대 경로
     * @return string 절대 경로
     */
    private function basePath(string $path): string
    {
        return ($this->baseRoot ?? base_path()).DIRECTORY_SEPARATOR.$path;
    }

    /**
     * 스토리지 루트 기준 경로를 만듭니다.
     *
     * @param  string  $path  스토리지 루트 기준 상대 경로
     * @return string 절대 경로
     */
    private function storagePath(string $path): string
    {
        return ($this->storageRoot ?? storage_path()).DIRECTORY_SEPARATOR.$path;
    }
}
