<?php

namespace App\Console\Commands\Core;

use App\Extension\CoreVersionChecker;
use App\Extension\Helpers\FilePermissionHelper;
use App\Services\Core\BundledInventory;
use App\Services\Core\BundledSourcePlanner;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 공식 번들 소스(`{modules,plugins,templates,lang-packs}/_bundled/*`)를 사후에 보고·배치·정리합니다 (공개 #146).
 *
 * - list: 배포본 번들 × 디스크 × 설치 상태 표. 배포본을 읽었으면 미배치 기록 파일을 갱신한다
 * - place <id…>: 배포본에서 그 번들 + 의존 번들 + 대상 언어팩을 `_bundled` 로 복사한다(설치는 하지 않음)
 * - prune [id…]: 설치되지 않았고 아무도 의존하지 않는 번들 소스를 지운다. 기본은 미리보기, `--force` 로 삭제.
 *   배포본과 다른 파일이 있는 폴더는 `--include-modified` 없이는 지우지 않는다. 배포본 목록에 없는
 *   사용자 자체 `_bundled/{id}` 는 어떤 경우에도 지우지 않는다.
 *
 * 배포본은 `--source` > `--zip` > GitHub 의 현재 코어 버전 태그 순으로 고르고, 코어 버전이 다르면 거부한다.
 */
class BundledSourcesCommand extends Command
{
    protected $signature = 'core:bundled-sources
        {action : list|place|prune}
        {identifiers?* : 대상 번들 식별자 (type:id 허용)}
        {--source= : 배포본 디렉토리 경로 (현재 코어와 같은 버전)}
        {--zip= : 배포본 ZIP 파일 경로 (현재 코어와 같은 버전)}
        {--force : prune 미리보기 대신 실제로 삭제}
        {--include-modified : prune 이 배포본과 다른 파일이 있는 폴더도 삭제}';

    protected $description = '설치하지 않은 공식 번들 소스를 보고·배치·정리합니다';

    /**
     * 이번 실행이 만든 격리 디렉토리(zip 추출·GitHub 다운로드) — 종료 시 정리.
     */
    private ?string $stagingPath = null;

    /**
     * 커맨드를 실행합니다.
     *
     * @param  BundledSourcePlanner  $planner  판정 서비스
     * @param  CoreUpdateService  $service  코어 업데이트 서비스 (zip 추출·GitHub 다운로드 재사용)
     * @return int 종료 코드
     */
    public function handle(BundledSourcePlanner $planner, CoreUpdateService $service): int
    {
        $action = strtolower((string) $this->argument('action'));
        if (! in_array($action, ['list', 'place', 'prune'], true)) {
            $this->error(__('settings.bundled_sources.unknown_action', ['action' => $action]));

            return self::FAILURE;
        }

        try {
            [$inventory, $error] = $this->resolveInventory($planner, $service, needFiles: $action !== 'list');

            if ($error !== null && ($this->option('source') || $this->option('zip'))) {
                // 운영자가 지정한 배포본이 잘못되었으면 폴백하지 않고 거부한다
                $this->error($error);

                return self::FAILURE;
            }

            return match ($action) {
                'list' => $this->runList($planner, $inventory, $error),
                'place' => $this->runPlace($planner, $inventory, $error),
                'prune' => $this->runPrune($planner, $inventory, $error),
            };
        } finally {
            if ($this->stagingPath !== null) {
                $service->cleanupPending($this->stagingPath);
            }
        }
    }

    /**
     * 배포본을 골라 릴리스 인벤토리를 읽습니다(--source > --zip > GitHub).
     *
     * @param  BundledSourcePlanner  $planner  판정 서비스
     * @param  CoreUpdateService  $service  코어 업데이트 서비스
     * @param  bool  $needFiles  파일 비교·복사가 필요한지 (zip 을 풀어야 하는지)
     * @return array{0: BundledInventory|null, 1: string|null} [인벤토리, 오류 메시지]
     */
    private function resolveInventory(BundledSourcePlanner $planner, CoreUpdateService $service, bool $needFiles): array
    {
        $current = CoreVersionChecker::getCoreVersion();

        try {
            if ($source = $this->option('source')) {
                $dir = realpath((string) $source);
                if ($dir === false || ! is_dir($dir)) {
                    return [null, __('settings.bundled_sources.source_failed', ['error' => (string) $source])];
                }
                $inventory = $planner->readInventoryFromDirectory($dir);
                $label = $dir;
            } elseif ($zip = $this->option('zip')) {
                $zipPath = realpath((string) $zip);
                if ($zipPath === false || ! is_file($zipPath)) {
                    return [null, __('settings.bundled_sources.source_failed', ['error' => (string) $zip])];
                }
                $inventory = $needFiles ? null : $planner->readInventoryFromZip($zipPath);
                if ($inventory === null) {
                    $root = $service->extractZipToPending($zipPath);
                    $this->stagingPath = $root;
                    $inventory = $planner->readInventoryFromDirectory($root);
                }
                $label = $zipPath;
            } else {
                $this->line(__('settings.bundled_sources.source_resolving_github', ['version' => $current]));
                $root = $service->downloadUpdate($current);
                $this->stagingPath = $root;
                $inventory = $planner->readInventoryFromDirectory($root);
                $label = 'GitHub '.$current;
            }
        } catch (\Throwable $e) {
            return [null, __('settings.bundled_sources.source_failed', ['error' => $e->getMessage()])];
        }

        if ($inventory->coreVersion !== $current) {
            return [null, __('settings.bundled_sources.errors.version_mismatch', [
                'source' => $inventory->coreVersion ?? '?',
                'current' => $current,
            ])];
        }

        $this->line(__('settings.bundled_sources.source_used', ['source' => $label, 'version' => $inventory->coreVersion]));

        return [$inventory, null];
    }

    /**
     * list — 배포본(또는 기록, 디스크·DB) 기준 번들 표를 출력합니다.
     *
     * @param  BundledSourcePlanner  $planner  판정 서비스
     * @param  BundledInventory|null  $inventory  릴리스 인벤토리 (못 읽었으면 null)
     * @param  string|null  $error  배포본을 못 읽은 사유
     * @return int 종료 코드
     */
    private function runList(BundledSourcePlanner $planner, ?BundledInventory $inventory, ?string $error): int
    {
        $installed = $planner->installedKeys();
        $headers = array_values((array) __('settings.bundled_sources.list_headers'));

        if ($inventory !== null) {
            $required = $planner->installedDependencyKeys($inventory);
            $rows = [];
            foreach ($inventory->all() as $key => $entry) {
                $rows[] = [
                    __('settings.bundled_sources.types.'.$entry['type']),
                    $entry['identifier'].(! empty($entry['hidden']) ? __('settings.bundled_sources.sample_suffix') : ''),
                    $entry['version'],
                    $this->yesNo($planner->isOnDisk($entry['type'], $entry['identifier'])),
                    $this->yesNo(isset($installed[$key])),
                    $this->yesNo(isset($required[$key])),
                ];
            }
            $this->table($headers, $rows);

            $record = $planner->writeRecord($planner->plan($inventory), (string) $inventory->coreVersion, 'core:bundled-sources');
            $this->info(__('settings.bundled_sources.list_record_updated', ['count' => count($record['unplaced'])]));

            return self::SUCCESS;
        }

        $this->warn((string) $error);

        $record = $planner->readRecord();
        if ($record !== null && ($record['core_version'] ?? null) === CoreVersionChecker::getCoreVersion()) {
            $this->line(__('settings.bundled_sources.record_fallback', ['recorded_at' => (string) ($record['recorded_at'] ?? '')]));
            $rows = [];
            foreach ($record['unplaced'] as $item) {
                $rows[] = [
                    __('settings.bundled_sources.types.'.$item['type']),
                    $item['identifier'].(! empty($item['hidden']) ? __('settings.bundled_sources.sample_suffix') : ''),
                    (string) ($item['version'] ?? ''),
                    $this->yesNo($planner->isOnDisk($item['type'], $item['identifier'])),
                    $this->yesNo(isset($installed[BundledInventory::key($item['type'], $item['identifier'])])),
                    '-',
                ];
            }
            $this->table($headers, $rows);

            return self::SUCCESS;
        }

        $this->line(__('settings.bundled_sources.disk_db_only'));
        $rows = [];
        $domains = BundledSourcePlanner::EXTENSION_DOMAINS + ['language_pack' => BundledSourcePlanner::LANGUAGE_PACK_DOMAIN];
        foreach ($domains as $type => [$domain]) {
            $dir = base_path($domain.DIRECTORY_SEPARATOR.'_bundled');
            if (! is_dir($dir)) {
                continue;
            }
            foreach (File::directories($dir) as $path) {
                $id = basename($path);
                $rows[] = [
                    __('settings.bundled_sources.types.'.$type), $id, '-', $this->yesNo(true),
                    $this->yesNo(isset($installed[BundledInventory::key($type, $id)])), '-',
                ];
            }
        }
        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * place — 고른 번들과 그 의존 번들·대상 언어팩을 배포본에서 `_bundled` 로 복사합니다.
     *
     * @param  BundledSourcePlanner  $planner  판정 서비스
     * @param  BundledInventory|null  $inventory  릴리스 인벤토리
     * @param  string|null  $error  배포본을 못 읽은 사유
     * @return int 종료 코드
     */
    private function runPlace(BundledSourcePlanner $planner, ?BundledInventory $inventory, ?string $error): int
    {
        $identifiers = (array) $this->argument('identifiers');
        if ($identifiers === []) {
            $this->error(__('settings.bundled_sources.errors.identifiers_required'));

            return self::FAILURE;
        }
        if ($inventory === null || $inventory->root === null) {
            if ($error !== null) {
                $this->warn($error);
            }
            $this->error(__('settings.bundled_sources.errors.release_required'));

            return self::FAILURE;
        }

        try {
            $selection = $planner->resolveSelection($inventory, implode(',', $identifiers));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $placedKeys = [];
        foreach ($planner->placementClosure($inventory, (array) ($selection['keys'] ?? [])) as $key => $row) {
            $entry = $row['entry'];
            $label = $entry['type'].':'.$entry['identifier'];
            if ($planner->isOnDisk($entry['type'], $entry['identifier'])) {
                $this->line(__('settings.bundled_sources.place_already', ['label' => $label]));

                continue;
            }

            $from = $inventory->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $entry['path']);
            FilePermissionHelper::copyDirectory($from, $planner->bundledPath($entry['type'], $entry['identifier']));
            $placedKeys[] = $key;
            $suffix = $row['required_by'] !== [] ? ' ← '.implode(', ', $row['required_by']) : '';
            $this->info(__('settings.bundled_sources.place_done', ['label' => $label.$suffix]));
        }

        if ($placedKeys !== []) {
            $planner->removeFromRecord($placedKeys, 'core:bundled-sources');
        }
        $this->line(__('settings.bundled_sources.place_summary', ['count' => count($placedKeys)]));

        return self::SUCCESS;
    }

    /**
     * prune — 설치되지 않았고 아무도 의존하지 않는 번들 소스를 미리보기하거나(기본) 지웁니다(--force).
     *
     * @param  BundledSourcePlanner  $planner  판정 서비스
     * @param  BundledInventory|null  $inventory  릴리스 인벤토리
     * @param  string|null  $error  배포본을 못 읽은 사유
     * @return int 종료 코드
     */
    private function runPrune(BundledSourcePlanner $planner, ?BundledInventory $inventory, ?string $error): int
    {
        if ($inventory === null) {
            if ($error !== null) {
                $this->warn($error);
            }
            $this->error(__('settings.bundled_sources.errors.release_required'));

            return self::FAILURE;
        }

        $keys = null;
        $identifiers = (array) $this->argument('identifiers');
        if ($identifiers !== []) {
            try {
                $keys = (array) ($planner->resolveSelection($inventory, implode(',', $identifiers))['keys'] ?? []);
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $includeModified = (bool) $this->option('include-modified');
        $result = $planner->planPrune($inventory, $keys, $includeModified);
        if ($result['unverifiable']) {
            $this->error(__('settings.bundled_sources.prune_no_release_for_modified'));

            return self::FAILURE;
        }
        foreach ($result['not_target'] as $key) {
            $this->warn(__('settings.bundled_sources.errors.not_unplaced_target', ['identifier' => $key]));
        }
        foreach ($result['protected'] as $key => $requiredBy) {
            $this->line(__('settings.bundled_sources.prune_protected_dependency', ['label' => $key, 'required_by' => implode(', ', $requiredBy)]));
        }
        foreach ($result['modified'] as $key => $files) {
            $this->warn(__('settings.bundled_sources.prune_modified_kept', ['label' => $key, 'count' => count($files)]));
            foreach ($files as $file) {
                $this->line(__('settings.bundled_sources.prune_modified_file', ['file' => $file]));
            }
        }
        if ($result['modified'] !== []) {
            $this->line(__('settings.bundled_sources.prune_modified_hint'));
        }

        if ($result['candidates'] === []) {
            $this->info(__('settings.bundled_sources.prune_none'));

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        if (! $force) {
            $this->info(__('settings.bundled_sources.prune_preview_title'));
        }

        $removed = [];
        foreach ($result['candidates'] as $key => $entry) {
            if (! $force) {
                $this->line('  - '.$key);

                continue;
            }

            File::deleteDirectory($planner->bundledPath($entry['type'], $entry['identifier']));
            $removed[] = $key;
            $this->info(__('settings.bundled_sources.prune_removed', ['label' => $key]));
        }

        if ($force) {
            if ($removed !== []) {
                $planner->removeFromRecord($removed, 'core:bundled-sources');
            }
            $this->info(__('settings.bundled_sources.prune_summary', ['count' => count($removed)]));
        }

        return self::SUCCESS;
    }

    /**
     * 예/아니오 표기를 돌려줍니다.
     *
     * @param  bool  $value  값
     * @return string 표기
     */
    private function yesNo(bool $value): string
    {
        return $value ? __('settings.bundled_sources.yes') : __('settings.bundled_sources.no');
    }
}
