<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\RendersLayoutSyncResult;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Search\SearchIndexMaintenanceManager;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use App\Support\ConfigCacheHelper;
use App\Support\ExecutionEnvironment;
use App\Support\ExtensionDependencyOrder;
use App\Support\RouteCacheHelper;
use App\Support\UpdateChildEnv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 번들 확장(모듈/플러그인/템플릿/언어팩) 일괄 업데이트를 별도 PHP 프로세스에서 실행합니다.
 *
 * `core:update` 의 `BundledExtensionUpdatePrompt::executeBulkUpdate` 가 사용자 선택을
 * 매니페스트로 직렬화한 후 본 커맨드를 `proc_open` 으로 spawn 한다. 자식은 fresh PHP
 * 프로세스이므로 디스크에 막 적용된 신버전 코어 코드(특히 `ModuleManager` /
 * `PluginManager` / `TemplateManager` / `LanguagePackService`) 를 메모리에 로드한 상태
 * 에서 update 메서드를 호출한다.
 *
 * 부모 프로세스의 stale memory 가 신규 sync 메서드(`syncDeclarativeArtifacts` 등) 를
 * 호출하지 못하던 결함의 영구 차단 (beta.4 의 `Upgrade_7_0_0_beta_4` 사후 보정과는 별개의
 * 구조적 fix — 향후 모든 코어 업그레이드에서 동일 패턴 회귀를 차단).
 *
 * 항목이 둘 이상이면 이 프로세스는 순서·집계만 맡고 항목마다 새 PHP 프로세스(`--item-process`)를 띄운다. 확장 하나를
 * 갱신할 때마다 config·라우트 캐시 재굽기가 일회용 앱을 부팅하는데, 그 앱은 같은 프로세스에서 회수되지 않는다. 한
 * 프로세스에서 확장 19개를 차례로 갱신하면 그 몫이 쌓여 512 MB 에서 마지막 템플릿 도중 죽었다(7.1.3 → 7.2.0 리허설).
 * 항목 프로세스가 죽어도 그 항목만 실패로 세고 이름을 남긴 뒤 다음 항목으로 간다. 새 프로세스를 띄울 수 없으면 이
 * 프로세스에서 차례로 돌되 config·라우트 캐시 재굽기를 끝에 한 번으로 모은다.
 *
 * 매니페스트 형식:
 * ```json
 * {
 *   "modules":    [{"identifier": "...", "strategy": "overwrite|keep"}, ...],
 *   "plugins":    [{"identifier": "...", "strategy": "overwrite|keep"}, ...],
 *   "templates":  [{"identifier": "...", "strategy": "overwrite|keep"}, ...],
 *   "lang_packs": [{"identifier": "..."}, ...]
 * }
 * ```
 *
 * 출력:
 *   - stdout 에 진행 라인을 그대로 출력 (부모 콘솔이 forwarding)
 *   - 실패한 항목이 있으면 끝에 그 목록과 다시 실행할 명령을 출력
 *   - 종료 직전 `[BUNDLED-RESULT] {json}` 표식으로 최종 카운트 페이로드를 1회 출력 →
 *     부모가 stdout 파싱해 결과 복원. `success`·`failed` 는 항목 단위 수이고(이전 판 부모가 읽는 키),
 *     `failed_items` 는 실패 항목 목록이다(이전 판 부모는 읽지 않는다).
 */
class ExecuteBundledUpdatesCommand extends Command
{
    use LocalizesDescription;
    use RendersLayoutSyncResult;
    use ReportsLifecycleWarnings;

    public const RESULT_PREFIX = '[BUNDLED-RESULT] ';

    /** 매니페스트 키 → 항목 종류 */
    private const MANIFEST_TYPES = [
        'modules' => 'module',
        'plugins' => 'plugin',
        'templates' => 'template',
        'lang_packs' => 'lang_pack',
    ];

    protected $signature = 'core:execute-bundled-updates
        {--manifest= : 업데이트 매니페스트 JSON 파일 경로 (필수)}
        {--force : 강제 업데이트 플래그 (확장 매니저 update 호출에 전달)}
        {--rebuild-search-index : 일괄 업데이트 후 검색 인덱스 재생성 (매니페스트의 선택보다 우선)}
        {--item-process : 내부용 — 항목 하나만 처리하는 새 프로세스로 실행 (머리줄·합계·검색 인덱스·격리 디렉토리 청소 생략)}';

    /**
     * 부분 실패 경고는 표로만 보이고 종료코드는 바꾸지 않습니다 — 부모는 종료코드와 결과 페이로드로 성공·실패를 판정한다.
     *
     * @return bool 늘 false
     */
    protected function lifecycleWarningsChangeExitCode(): bool
    {
        return false;
    }

    /**
     * 매니페스트를 읽어 번들 확장·언어팩을 일괄 업데이트하고 결과 페이로드를 출력한다.
     *
     * @return int 매니페스트 누락 시 INVALID, 판독 실패 또는 1건 이상 실패 시 FAILURE, 전부 성공 시 SUCCESS
     */
    public function handle(): int
    {
        $manifestPath = (string) $this->option('manifest');
        if ($manifestPath === '' || ! File::isFile($manifestPath)) {
            $this->error(__('extensions.commands.bundled_update.manifest_required'));

            return self::INVALID;
        }

        $isItemProcess = (bool) $this->option('item-process');

        // spawn 자식 진입 시 활성 모듈/플러그인의 PSR-4 매핑을 fresh 등록 — 자세한 배경은
        // ExecuteUpgradeStepsCommand::handle 의 동일 호출 주석 참조.
        // (Artisan::call 대신 직접 메서드 호출 — nested Artisan::call 이 outer 명령의
        // output buffer 를 덮어쓰는 Laravel 동작 회피)
        try {
            app(ExtensionManager::class)->updateComposerAutoload();
        } catch (\Throwable $e) {
            Log::warning('bundled update spawn 자식: updateComposerAutoload 호출 실패', [
                'error' => $e->getMessage(),
            ]);
            LifecycleWarnings::current()->add('autoload', 'autoload-extensions.php', $e->getMessage());
        }

        $manifest = json_decode(File::get($manifestPath), true);
        if (! is_array($manifest)) {
            $this->error(__('extensions.commands.bundled_update.manifest_parse_failed', ['path' => $manifestPath]));

            return self::FAILURE;
        }

        // 부모(core:update)가 운영자 선택을 매니페스트에 실어 보낸다 — 자식이 임의로 정하지 않는다
        $rebuildSearchIndex = (bool) $this->option('rebuild-search-index') || (bool) ($manifest['rebuild_search_index'] ?? false);

        // 모듈·플러그인은 한 목록으로 서로의 의존 순서를 따른다(기본 순서 모듈 → 플러그인, 의존만 앞당김 — 모듈이 플러그인에
        // 의존하면 플러그인이 먼저). 의존은 새 배포본(_bundled) manifest 의 선언을 읽는다 — 부모(이전 버전 core:update)가
        // 만든 목록의 순서를 믿지 않는다. 템플릿 → 언어팩 단계는 그 뒤에 그대로 돈다.
        $modules = $manifest['modules'] ?? [];
        $plugins = $manifest['plugins'] ?? [];
        $extensionOrder = self::orderedModulesAndPlugins($modules, $plugins);
        foreach ($extensionOrder['cycles'] as $cycle) {
            $this->warn(__('extensions.commands.bundled_update.dependency_cycle', ['cycle' => implode(' → ', $cycle)]));
        }
        $templates = $manifest['templates'] ?? [];
        $langPacks = $manifest['lang_packs'] ?? [];

        if (! $isItemProcess) {
            $this->info(__('extensions.commands.bundled_update.child_running'));
        }

        $items = self::flattenItems($modules, $plugins, $templates, $langPacks);
        $outcome = null;

        if (self::shouldRunEachItemInFreshProcess($items, $isItemProcess, $this->canSpawnProcess())) {
            $outcome = $this->runEachItemInFreshProcess($items);
        }

        if ($outcome === null) {
            // 새 프로세스를 띄울 수 없는 서버: 같은 프로세스에서 차례로 돈다. 확장마다 config·라우트 캐시를 다시 굽는
            // 일회용 앱 부팅이 쌓이지 않도록 재굽기를 끝에 한 번으로 모은다. proc_open 이 없으니 그 사이 캐시를 읽는
            // 새 프로세스(composer·훅 캐시·언어팩 단계)도 뜨지 않는다.
            $outcome = self::shouldDeferCacheRebuilds($items, $this->canSpawnProcess())
                ? ConfigCacheHelper::deferRebuilds(fn () => RouteCacheHelper::deferRebuilds(
                    fn () => $this->runItemsInProcess($modules, $plugins, $templates, $langPacks)
                ))
                : $this->runItemsInProcess($modules, $plugins, $templates, $langPacks);
        }

        $success = $outcome['success'];
        $failed = $outcome['failed'];
        $failedItems = $outcome['failed_items'];

        if (! $isItemProcess) {
            $this->newLine();
            $this->info(__('extensions.commands.bundled_update.completed', ['success' => $success, 'failed' => $failed]));
            self::renderFailedItems($this, $failedItems);

            // 검색 인덱스 재생성 — 운영자가 부모 단계에서 선택했을 때만 수행 (인덱스 잠금·재색인 비용)
            if ($rebuildSearchIndex) {
                $report = app(SearchIndexMaintenanceManager::class)->repairStale();
                $this->newLine();
                $this->info(__('extensions.commands.bundled_update.search_index_result', ['summary' => $report->summary()]));

                foreach ($report->failed as $identifier => $message) {
                    $this->warn('  '.__('search.index.rebuild_failed_item', ['index' => $identifier, 'error' => $message]));
                }
            }

            // 이전 버전 부모가 남긴 빈 격리 디렉토리(core_{ts}/extracted 껍데기) 청소 —
            // 부모의 정리 단계는 이 자식보다 먼저 끝나므로, 구버전 부모에서 올라오는
            // 업데이트도 이 자리에서 껍데기 없이 마무리된다. 실패해도 업데이트 결과와 무관.
            try {
                app(CoreUpdateService::class)->sweepEmptyStagingDirectories();
            } catch (\Throwable $e) {
                Log::channel('upgrade')->warning('[spawn] 빈 격리 디렉토리 청소 실패', ['error' => $e->getMessage()]);
            }
        }

        // 부모 프로세스가 결과를 복원할 수 있도록 표식 라인으로 페이로드 출력 — 실패가 없으면 이전 판과 같은 모양
        $payload = ['success' => $success, 'failed' => $failed];
        if ($failedItems !== []) {
            $payload['failed_items'] = $failedItems;
        }
        $this->line(self::RESULT_PREFIX.json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 이 서버에서 새 PHP 프로세스를 띄울 수 있는지 답합니다.
     *
     * @return bool proc_open 을 쓸 수 있으면 true
     */
    protected function canSpawnProcess(): bool
    {
        return ExecutionEnvironment::hasFunction('proc_open');
    }

    /**
     * 항목마다 새 프로세스로 처리해야 하는지 판정합니다 — 항목이 둘 이상이고, 항목 프로세스 자신이 아니며, 프로세스를 띄울 수 있을 때.
     *
     * @param  array<int, array<string, mixed>>  $items  {@see flattenItems()} 결과
     * @param  bool  $isItemProcess  항목 프로세스(`--item-process`)로 실행 중인지
     * @param  bool  $canSpawn  새 프로세스를 띄울 수 있는지
     * @return bool 항목마다 새 프로세스로 처리하면 true
     */
    public static function shouldRunEachItemInFreshProcess(array $items, bool $isItemProcess, bool $canSpawn): bool
    {
        return ! $isItemProcess && $canSpawn && count($items) > 1;
    }

    /**
     * 같은 프로세스에서 여러 항목을 돌 때 config·라우트 캐시 재굽기를 끝으로 미뤄야 하는지 판정합니다.
     *
     * 새 프로세스를 띄울 수 없을 때만 미룬다 — 띄울 수 있는 서버에서 미루면 그 사이 뜨는 새 프로세스가 옛 캐시로 부팅한다.
     *
     * @param  array<int, array<string, mixed>>  $items  {@see flattenItems()} 결과
     * @param  bool  $canSpawn  새 프로세스를 띄울 수 있는지
     * @return bool 미루면 true
     */
    public static function shouldDeferCacheRebuilds(array $items, bool $canSpawn): bool
    {
        return ! $canSpawn && count($items) > 1;
    }

    /**
     * 매니페스트의 모듈·플러그인 항목을 한 목록으로 의존 순서에 맞춰 정렬합니다(기본 순서 모듈 → 플러그인, 의존만 앞당김).
     *
     * 의존은 새 배포본(_bundled) manifest 선언({@see bundledDependencyKeys()})을 읽는다. 식별자가 빈 항목은 뺀다.
     *
     * @param  array<int, mixed>  $modules  모듈 항목
     * @param  array<int, mixed>  $plugins  플러그인 항목
     * @return array{ordered: array<int, array{key: string, type: string, entry: array<string, mixed>}>, cycles: array<int, array<int, string>>} 정렬 결과
     */
    private static function orderedModulesAndPlugins(array $modules, array $plugins): array
    {
        $keep = fn (array $entries): array => array_values(array_filter($entries, 'is_array'));

        return ExtensionDependencyOrder::orderModulesAndPlugins($keep($modules), $keep($plugins), [self::class, 'bundledDependencyKeys']);
    }

    /**
     * 매니페스트 목록을 처리 순서(모듈·플러그인 의존 순서 → 템플릿 → 언어팩)의 항목 목록으로 폅니다. 식별자가 빈 항목은 뺀다.
     *
     * 모듈·플러그인은 {@see orderedModulesAndPlugins()} 의 순서를 따른다 — 항목 프로세스도 이 순서로 띄운다.
     *
     * @param  array<int, mixed>  $modules  모듈 항목
     * @param  array<int, mixed>  $plugins  플러그인 항목
     * @param  array<int, mixed>  $templates  템플릿 항목
     * @param  array<int, mixed>  $langPacks  언어팩 항목
     * @return array<int, array{type: string, identifier: string, strategy: string|null}> 항목 목록
     */
    public static function flattenItems(array $modules, array $plugins, array $templates, array $langPacks): array
    {
        $items = [];
        foreach (self::orderedModulesAndPlugins($modules, $plugins)['ordered'] as $node) {
            $items[] = [
                'type' => $node['type'],
                'identifier' => (string) $node['entry']['identifier'],
                'strategy' => (string) ($node['entry']['strategy'] ?? 'overwrite'),
            ];
        }
        foreach (['templates' => $templates, 'lang_packs' => $langPacks] as $key => $entries) {
            foreach ($entries as $entry) {
                $id = is_array($entry) ? (string) ($entry['identifier'] ?? '') : '';
                if ($id === '') {
                    continue;
                }
                $items[] = [
                    'type' => self::MANIFEST_TYPES[$key],
                    'identifier' => $id,
                    'strategy' => $key === 'lang_packs' ? null : (string) ($entry['strategy'] ?? 'overwrite'),
                ];
            }
        }

        return $items;
    }

    /**
     * 항목 하나를 담은 매니페스트를 만듭니다.
     *
     * @param  array{type: string, identifier: string, strategy: string|null}  $item  항목
     * @return array<string, mixed> 매니페스트
     */
    public static function manifestForItem(array $item): array
    {
        $manifest = ['modules' => [], 'plugins' => [], 'templates' => [], 'lang_packs' => [], 'rebuild_search_index' => false];
        $key = array_search($item['type'], self::MANIFEST_TYPES, true);
        $manifest[$key][] = $item['type'] === 'lang_pack'
            ? ['identifier' => $item['identifier']]
            : ['identifier' => $item['identifier'], 'strategy' => $item['strategy'] ?? 'overwrite'];

        return $manifest;
    }

    /**
     * 항목마다 새 PHP 프로세스(`core:execute-bundled-updates --item-process`)를 띄워 차례로 처리하고 결과를 모읍니다.
     *
     * 항목 프로세스의 출력은 그대로 이어 보이고 결과 표식 줄만 받아 집계한다. 결과 줄 없이 끝난 항목 프로세스(메모리
     * 고갈 등)는 그 항목만 실패로 세고 다음 항목으로 간다. 어떤 항목의 프로세스를 띄우지 못하면 그 항목만 이
     * 프로세스에서 처리한다.
     *
     * @param  array<int, array{type: string, identifier: string, strategy: string|null}>  $items  항목 목록
     * @return array{success: int, failed: int, failed_items: array<int, array<string, mixed>>}|null 집계, 프로세스를 쓸 수 없으면 null
     */
    private function runEachItemInFreshProcess(array $items): ?array
    {
        if (! $this->canSpawnProcess()) {
            return null;
        }

        $total = ['success' => 0, 'failed' => 0, 'failed_items' => []];

        foreach ($items as $item) {
            $run = $this->runManifestInFreshProcess(self::manifestForItem($item), ['--item-process']);

            if ($run === null) {
                $single = self::manifestForItem($item);
                $outcome = $this->runItemsInProcess($single['modules'], $single['plugins'], $single['templates'], $single['lang_packs']);
            } else {
                $outcome = self::itemOutcome($item, $run['payload'], $run['exit_code']);
                if ($run['payload'] === null) {
                    $this->warn(__('extensions.commands.bundled_update.item_failed', ['message' => $outcome['failed_items'][0]['reason']]));
                    Log::error('번들 일괄 업데이트 항목 프로세스가 결과 없이 끝남', ['type' => $item['type'], 'id' => $item['identifier'], 'exit_code' => $run['exit_code']]);
                }
            }

            $total['success'] += $outcome['success'];
            $total['failed'] += $outcome['failed'];
            array_push($total['failed_items'], ...$outcome['failed_items']);
        }

        return $total;
    }

    /**
     * 항목 프로세스 하나의 결과를 항목 단위 집계로 바꿉니다.
     *
     * 결과 줄이 있으면 그 수를 쓴다. 실패인데 실패 항목 이름이 없으면 이 항목으로 채운다. 결과 줄 없이 끝났으면 이
     * 항목 1건을 실패로 세고 종료 코드를 사유에 남긴다.
     *
     * @param  array{type: string, identifier: string, strategy: string|null}  $item  항목
     * @param  array<string, mixed>|null  $payload  항목 프로세스의 결과 페이로드 (없으면 null)
     * @param  int  $exitCode  항목 프로세스 종료 코드
     * @return array{success: int, failed: int, failed_items: array<int, array<string, mixed>>} 집계
     */
    public static function itemOutcome(array $item, ?array $payload, int $exitCode): array
    {
        if ($payload === null) {
            return [
                'success' => 0,
                'failed' => 1,
                'failed_items' => [$item + ['reason' => __('extensions.commands.bundled_update.item_process_aborted', ['code' => $exitCode]), 'exit_code' => $exitCode]],
            ];
        }

        $failed = (int) ($payload['failed'] ?? 0);
        $failedItems = is_array($payload['failed_items'] ?? null) ? array_values(array_filter($payload['failed_items'], 'is_array')) : [];
        if ($failed > 0 && $failedItems === []) {
            $failedItems = [$item + ['reason' => '']];
        }

        return [
            'success' => (int) ($payload['success'] ?? 0),
            'failed' => $failed,
            'failed_items' => $failedItems,
        ];
    }

    /**
     * 실패한 항목과 다시 실행할 명령을 출력합니다. 실패가 없으면 아무것도 출력하지 않는다.
     *
     * 일괄 업데이트 자식과 부모의 같은 프로세스 처리(proc_open 없는 서버)가 같은 모양으로 쓴다.
     *
     * @param  Command  $command  출력할 명령
     * @param  array<int, array<string, mixed>>  $failedItems  실패 항목 (`type`·`identifier`·`strategy`·`reason`)
     * @return void 반환값 없음
     */
    public static function renderFailedItems(Command $command, array $failedItems): void
    {
        if ($failedItems === []) {
            return;
        }

        $command->newLine();
        $command->warn(__('extensions.commands.bundled_update.failed_items_header', ['count' => count($failedItems)]));

        foreach ($failedItems as $item) {
            $type = (string) ($item['type'] ?? '');
            $command->line('  '.__('extensions.commands.bundled_update.failed_item_labels.'.$type, ['identifier' => (string) ($item['identifier'] ?? '')]));
            $command->line('    '.self::retryCommand($item));
        }
    }

    /**
     * 실패한 항목을 하나만 다시 갱신하는 명령을 만듭니다 — 일괄 업데이트와 같이 번들 소스·강제·같은 레이아웃 전략.
     *
     * `--force` 는 도중에 끊겨 「업데이트 중」으로 남은 상태도 정리하고 다시 적용한다.
     *
     * @param  array<string, mixed>  $item  항목 (`type`·`identifier`·`strategy`)
     * @return string 실행할 명령
     */
    public static function retryCommand(array $item): string
    {
        $id = (string) ($item['identifier'] ?? '');
        $strategy = (string) ($item['strategy'] ?? '') ?: 'overwrite';

        return match ((string) ($item['type'] ?? '')) {
            'module' => "php artisan module:update {$id} --source=bundled --force --layout-strategy={$strategy}",
            'plugin' => "php artisan plugin:update {$id} --source=bundled --force --layout-strategy={$strategy}",
            'template' => "php artisan template:update {$id} --source=bundled --force --layout-strategy={$strategy}",
            default => "php artisan language-pack:update {$id} --source=bundled",
        };
    }

    /**
     * 이 프로세스에서 항목을 차례로 갱신합니다 (항목 프로세스 자신, 항목 하나짜리 매니페스트, 새 프로세스를 띄울 수 없는 서버).
     *
     * @param  array<int, mixed>  $modules  모듈 항목
     * @param  array<int, mixed>  $plugins  플러그인 항목
     * @param  array<int, mixed>  $templates  템플릿 항목
     * @param  array<int, mixed>  $langPacks  언어팩 항목
     * @return array{success: int, failed: int, failed_items: array<int, array<string, mixed>>} 집계
     */
    private function runItemsInProcess(array $modules, array $plugins, array $templates, array $langPacks): array
    {
        $moduleManager = app(ModuleManager::class);
        $pluginManager = app(PluginManager::class);
        $templateManager = app(TemplateManager::class);
        $langPackService = app(LanguagePackService::class);

        $success = 0;
        $failed = 0;
        $failedItems = [];
        $fail = function (string $type, string $id, ?string $strategy, \Throwable $e) use (&$failed, &$failedItems): void {
            $failed++;
            $failedItems[] = ['type' => $type, 'identifier' => $id, 'strategy' => $strategy, 'reason' => $e->getMessage()];
            $this->warn(__('extensions.commands.bundled_update.item_failed', ['message' => $e->getMessage()]));
            Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => $type, 'id' => $id, 'error' => $e->getMessage()]);
        };

        // 모듈·플러그인은 한 목록으로 서로의 의존 순서를 따른다(순환 경고는 handle() 이 한 번 낸다)
        foreach (self::orderedModulesAndPlugins($modules, $plugins)['ordered'] as $node) {
            $entry = $node['entry'];
            $id = (string) ($entry['identifier'] ?? '');
            $strategy = (string) ($entry['strategy'] ?? 'overwrite');
            $type = $node['type'];
            $this->line($type === 'module'
                ? __('extensions.commands.bundled_update.running_items.module', ['identifier' => $id, 'strategy' => $strategy])
                : __('extensions.commands.bundled_update.running_items.plugin', ['identifier' => $id, 'strategy' => $strategy]));
            try {
                $result = $type === 'module'
                    ? $moduleManager->updateModule($id, true, null, VendorMode::Auto, $strategy, null, 'bundled')
                    : $pluginManager->updatePlugin($id, true, null, VendorMode::Auto, $strategy, null, 'bundled');
                $this->lineLayoutSyncSummary($result);
                $this->renderDependencyWarnings($result, '  ');
                $success++;
            } catch (\Throwable $e) {
                $fail($type, $id, $strategy, $e);
            }
            gc_collect_cycles();
        }

        foreach ($templates as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            $strategy = (string) ($entry['strategy'] ?? 'overwrite');
            if ($id === '') {
                continue;
            }
            $this->line(__('extensions.commands.bundled_update.running_items.template', ['identifier' => $id, 'strategy' => $strategy]));
            try {
                $result = $templateManager->updateTemplate($id, true, null, $strategy, 'bundled');
                $this->lineLayoutSyncSummary($result);
                $this->renderDependencyWarnings($result, '  ');
                $success++;
            } catch (\Throwable $e) {
                $fail('template', $id, $strategy, $e);
            }
            gc_collect_cycles();
        }

        // 언어팩 단계 — 이 프로세스에서 확장을 갱신했으면 새 PHP 프로세스로 돌린다. 이 프로세스는 확장 갱신 전에 부팅해
        // 모듈 서비스 프로바이더(바인딩)가 옛 판으로 등록돼 있어, 팩 활성화가 부르는 모듈 시더 재실행이 새 판 클래스의 새
        // 바인딩을 못 찾아 실패한다(새 클레임 사유의 일본어 이름이 빈 채 남은 W3 실측). 새 프로세스를 띄울 수 없으면 종전처럼
        // 여기서 돈다.
        if (self::langPackPhaseNeedsFreshProcess($modules, $plugins, $templates, $langPacks)) {
            $isolated = $this->runLangPackPhaseInFreshProcess($langPacks);
            if ($isolated !== null) {
                $success += $isolated['success'];
                $failed += $isolated['failed'];
                array_push($failedItems, ...$isolated['failed_items']);
                $langPacks = [];
            } else {
                $this->warn(__('extensions.commands.bundled_update.lang_pack_phase_in_process'));
            }
        }

        foreach ($langPacks as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            if ($id === '') {
                continue;
            }
            $this->line(__('extensions.commands.bundled_update.running_items.lang_pack', ['identifier' => $id]));
            try {
                $pack = $langPackService->findByIdentifier($id);
                if (! $pack) {
                    throw new \RuntimeException(__('language_packs.errors.identifier_not_found', ['identifier' => $id]));
                }
                $langPackService->performUpdate($pack, true);
                $success++;
            } catch (\Throwable $e) {
                $fail('lang_pack', $id, null, $e);
            }
        }

        return ['success' => $success, 'failed' => $failed, 'failed_items' => $failedItems];
    }

    /**
     * 언어팩 단계를 새 프로세스로 돌려야 하는지 판정합니다 — 이 프로세스에서 확장을 갱신했고 언어팩이 있을 때.
     *
     * 언어팩만 담은 매니페스트(새 프로세스의 언어팩 단계 자신)는 false 라 다시 분리하지 않는다.
     *
     * @param  array<int, mixed>  $modules  모듈 항목
     * @param  array<int, mixed>  $plugins  플러그인 항목
     * @param  array<int, mixed>  $templates  템플릿 항목
     * @param  array<int, mixed>  $langPacks  언어팩 항목
     * @return bool 새 프로세스로 돌려야 하면 true
     */
    public static function langPackPhaseNeedsFreshProcess(array $modules, array $plugins, array $templates, array $langPacks): bool
    {
        return $langPacks !== [] && ($modules !== [] || $plugins !== [] || $templates !== []);
    }

    /**
     * 언어팩 단계를 언어팩만 담은 매니페스트로 새 PHP 프로세스(`core:execute-bundled-updates`)에서 실행합니다.
     *
     * 자식 출력은 그대로 이어 보이고 결과 표식 줄만 받아 집계한다. 환경 변수(`G7_UPDATE_IN_PROGRESS` 등)는 그대로 넘긴다.
     *
     * @param  array<int, mixed>  $langPacks  언어팩 항목
     * @return array{success: int, failed: int, failed_items: array<int, array<string, mixed>>}|null 실행했으면 집계, 프로세스를 띄울 수 없으면 null
     */
    private function runLangPackPhaseInFreshProcess(array $langPacks): ?array
    {
        $run = $this->runManifestInFreshProcess(['modules' => [], 'plugins' => [], 'templates' => [], 'lang_packs' => array_values($langPacks)], []);
        if ($run === null) {
            return null;
        }

        if ($run['payload'] === null) {
            Log::warning('번들 일괄 업데이트: 언어팩 단계 새 프로세스가 결과를 내지 않음 — 실패로 집계');
            $items = self::flattenItems([], [], [], $langPacks);
            $reason = __('extensions.commands.bundled_update.item_process_aborted', ['code' => $run['exit_code']]);

            return ['success' => 0, 'failed' => count($items), 'failed_items' => array_map(fn (array $item) => $item + ['reason' => $reason, 'exit_code' => $run['exit_code']], $items)];
        }

        return [
            'success' => (int) ($run['payload']['success'] ?? 0),
            'failed' => (int) ($run['payload']['failed'] ?? 0),
            'failed_items' => is_array($run['payload']['failed_items'] ?? null) ? array_values($run['payload']['failed_items']) : [],
        ];
    }

    /**
     * 매니페스트 하나를 새 PHP 프로세스(`core:execute-bundled-updates`)에서 실행합니다.
     *
     * 자식 출력은 그대로 이어 보이고 결과 표식 줄만 받는다. 환경 변수(`G7_UPDATE_IN_PROGRESS` 등)는 그대로 넘긴다.
     *
     * @param  array<string, mixed>  $manifest  매니페스트
     * @param  array<int, string>  $extraArgs  덧붙일 인자
     * @return array{payload: array<string, mixed>|null, exit_code: int}|null 실행했으면 결과, 프로세스를 띄울 수 없으면 null
     */
    private function runManifestInFreshProcess(array $manifest, array $extraArgs): ?array
    {
        if (! $this->canSpawnProcess()) {
            return null;
        }

        // 고유 이름은 차단 가능한 `uniqid()` 가 아니라 `Str::random()` 으로 만든다 (형제
        // `BundledExtensionUpdatePrompt` 와 같은 처분 — 이 커맨드도 무CLI 환경에서 웹 요청 안으로 도달한다).
        $manifestPath = storage_path('app/core_pending'.DIRECTORY_SEPARATOR.'bundled-item-manifest_'.Str::random(16).'.json');
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));

        try {
            $command = [config('process.php_binary', PHP_BINARY), base_path('artisan'), 'core:execute-bundled-updates', '--manifest='.$manifestPath, ...$extraArgs];
            if ($this->option('force')) {
                $command[] = '--force';
            }
            $commandLine = implode(' ', array_map('escapeshellarg', $command)).' 2>&1';

            // audit:allow spawn-after-vendor-swap-clears-package-manifest 이 spawn 은 vendor 를 바꾸지 않고, 부모 core:update 가
            // 이미 패키지 매니페스트를 다시 만든 뒤 띄운 일괄 업데이트 자식 안에서 항목(또는 언어팩 단계)만 떼어 낸다.
            // env 는 APP_VERSION·업데이트 플래그를 고정해 넘긴다 (#145 F10).
            $process = proc_open($commandLine, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), UpdateChildEnv::build());
            if (! is_resource($process)) {
                return null;
            }

            fclose($pipes[0]);
            $payload = null;

            while (! feof($pipes[1])) {
                $line = fgets($pipes[1]);
                if ($line === false) {
                    continue;
                }
                $trimmed = rtrim($line);

                if (str_starts_with($trimmed, self::RESULT_PREFIX)) {
                    $decoded = json_decode(substr($trimmed, strlen(self::RESULT_PREFIX)), true);
                    $payload = is_array($decoded) ? $decoded : $payload;

                    continue;
                }

                $this->line($trimmed);
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return ['payload' => $payload, 'exit_code' => $exitCode];
        } finally {
            if (File::exists($manifestPath)) {
                File::delete($manifestPath);
            }
        }
    }

    /**
     * 항목을 의존 대상이 먼저 오도록 정렬합니다(안정 정렬 — 의존이 없으면 원래 순서). {@see ExtensionDependencyOrder} 에 위임.
     *
     * 목록에 없는 의존은 무시하고, 순환 구간은 원래 순서로 둔다. 어떤 경우에도 항목을 잃거나 내용을 바꾸지 않는다.
     *
     * @param  array<int, array<string, mixed>>  $entries  매니페스트 항목 (`identifier`·`strategy`)
     * @param  callable(string): array<int, string>  $dependenciesOf  식별자 → 의존 식별자 목록
     * @return array<int, array<string, mixed>> 정렬된 항목
     */
    public static function orderByDependencies(array $entries, callable $dependenciesOf): array
    {
        $nodes = [];
        foreach (array_values($entries) as $index => $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            $nodes[] = ['key' => $id !== '' ? $id : "#{$index}", 'entry' => $entry];
        }

        return array_column(ExtensionDependencyOrder::order($nodes, $dependenciesOf)['ordered'], 'entry');
    }

    /**
     * 번들(`{type}/_bundled/{id}`) manifest 가 선언한 모듈·플러그인 의존을 노드 키(`module:…`·`plugin:…`)로 읽습니다.
     *
     * 일괄 업데이트는 새 배포본의 선언을 따라야 한다(활성본은 아직 옛 판). 같은 프로세스 경로(BundledExtensionUpdatePrompt)도 쓴다.
     *
     * @param  string  $key  노드 키 (`module:…`·`plugin:…`)
     * @return array<int, string> 의존 키 목록 (읽지 못하면 빈 배열)
     */
    public static function bundledDependencyKeys(string $key): array
    {
        [$kind, $identifier] = array_pad(explode(':', $key, 2), 2, '');
        $path = match ($kind) {
            'module' => base_path("modules/_bundled/{$identifier}/module.json"),
            'plugin' => base_path("plugins/_bundled/{$identifier}/plugin.json"),
            default => null,
        };

        if ($path === null || $identifier === '' || ! File::isFile($path)) {
            return [];
        }

        $manifest = json_decode((string) File::get($path), true);

        // 목록형(`["sirsoft-board"]`)도 받는다 — 키로 읽으면 식별자 자리에 0·1 이 들어간다(#822 D6)
        return is_array($manifest) ? ExtensionDependencyOrder::dependencyKeys($manifest['dependencies'] ?? []) : [];
    }
}
