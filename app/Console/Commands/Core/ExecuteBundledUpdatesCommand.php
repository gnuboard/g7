<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\RendersLayoutSyncResult;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Exceptions\ExtensionOperationLockUnavailableException;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Search\SearchIndexMaintenanceManager;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use App\Support\ExtensionDependencyOrder;
use App\Support\UpdateChildEnv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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
 *   - 종료 직전 `[BUNDLED-RESULT] {json}` 표식으로 최종 카운트 페이로드를 1회 출력 →
 *     부모가 stdout 파싱해 결과 복원.
 */
class ExecuteBundledUpdatesCommand extends Command
{
    use LocalizesDescription;
    use RendersLayoutSyncResult;
    use ReportsLifecycleWarnings;

    public const RESULT_PREFIX = '[BUNDLED-RESULT] ';

    protected $signature = 'core:execute-bundled-updates
        {--manifest= : 업데이트 매니페스트 JSON 파일 경로 (필수)}
        {--force : 강제 업데이트 플래그 (확장 매니저 update 호출에 전달)}
        {--rebuild-search-index : 일괄 업데이트 후 검색 인덱스 재생성 (매니페스트의 선택보다 우선)}';

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
        $extensionOrder = ExtensionDependencyOrder::orderModulesAndPlugins($modules, $plugins, [self::class, 'bundledDependencyKeys']);
        foreach ($extensionOrder['cycles'] as $cycle) {
            $this->warn(__('extensions.commands.bundled_update.dependency_cycle', ['cycle' => implode(' → ', $cycle)]));
        }
        $templates = $manifest['templates'] ?? [];
        $langPacks = $manifest['lang_packs'] ?? [];

        $moduleManager = app(ModuleManager::class);
        $pluginManager = app(PluginManager::class);
        $templateManager = app(TemplateManager::class);
        $langPackService = app(LanguagePackService::class);

        $success = 0;
        $failed = 0;
        // 잠금 저장소 장애로 일괄 업데이트를 멈췄는지 — 처리한 항목 수로 남은(건너뛴) 항목 수를 센다
        $aborted = false;
        $attempted = 0;
        $totalItems = count($extensionOrder['ordered'])
            + count(array_filter($templates, fn ($entry) => (string) ($entry['identifier'] ?? '') !== ''))
            + count(array_filter($langPacks, fn ($entry) => (string) ($entry['identifier'] ?? '') !== ''));

        $this->info(__('extensions.commands.bundled_update.child_running'));

        foreach ($extensionOrder['ordered'] as $node) {
            $entry = $node['entry'];
            $id = (string) ($entry['identifier'] ?? '');
            $strategy = (string) ($entry['strategy'] ?? 'overwrite');
            $type = $node['type'];
            $attempted++;
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
            } catch (ExtensionOperationLockUnavailableException $e) {
                // 잠금 저장소 장애 — 남은 항목도 같은 오류로 하나씩 실패하므로 여기서 멈추고 끝에서 한 번만 알린다(#161 D8)
                $failed++;
                $aborted = true;
                Log::error('번들 일괄 업데이트 중단 — 작업 잠금 저장소 오류', ['type' => $type, 'id' => $id]);
                break;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn(__('extensions.commands.bundled_update.item_failed', ['message' => $e->getMessage()]));
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => $type, 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($aborted ? [] : $templates as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            $strategy = (string) ($entry['strategy'] ?? 'overwrite');
            if ($id === '') {
                continue;
            }
            $attempted++;
            $this->line(__('extensions.commands.bundled_update.running_items.template', ['identifier' => $id, 'strategy' => $strategy]));
            try {
                $result = $templateManager->updateTemplate($id, true, null, $strategy, 'bundled');
                $this->lineLayoutSyncSummary($result);
                $this->renderDependencyWarnings($result, '  ');
                $success++;
            } catch (ExtensionOperationLockUnavailableException $e) {
                // 잠금 저장소 장애 — 남은 항목도 같은 오류로 하나씩 실패하므로 여기서 멈추고 끝에서 한 번만 알린다(#161 D8)
                $failed++;
                $aborted = true;
                Log::error('번들 일괄 업데이트 중단 — 작업 잠금 저장소 오류', ['type' => 'template', 'id' => $id]);
                break;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn(__('extensions.commands.bundled_update.item_failed', ['message' => $e->getMessage()]));
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'template', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        // 언어팩 단계 — 이 프로세스에서 확장을 갱신했으면 새 PHP 프로세스로 돌린다. 이 프로세스는 확장 갱신 전에 부팅해
        // 모듈 서비스 프로바이더(바인딩)가 옛 판으로 등록돼 있어, 팩 활성화가 부르는 모듈 시더 재실행이 새 판 클래스의 새
        // 바인딩을 못 찾아 실패한다(새 클레임 사유의 일본어 이름이 빈 채 남은 W3 실측). 새 프로세스를 띄울 수 없으면 종전처럼
        // 여기서 돈다.
        // 언어팩 단계 자식이 중단을 알렸으면 그 자식이 이미 안내했다 — 여기서 다시 알리지 않는다
        $abortReportedByChild = false;
        if (! $aborted && self::langPackPhaseNeedsFreshProcess($modules, $plugins, $templates, $langPacks)) {
            $isolated = $this->runLangPackPhaseInFreshProcess($langPacks);
            if ($isolated !== null) {
                $success += $isolated['success'];
                $failed += $isolated['failed'];
                $attempted += count($langPacks) - $isolated['skipped'];
                if ($isolated['aborted']) {
                    $aborted = true;
                    $abortReportedByChild = true;
                }
                $langPacks = [];
            } else {
                $this->warn(__('extensions.commands.bundled_update.lang_pack_phase_in_process'));
            }
        }

        foreach ($aborted ? [] : $langPacks as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            if ($id === '') {
                continue;
            }
            $attempted++;
            $this->line(__('extensions.commands.bundled_update.running_items.lang_pack', ['identifier' => $id]));
            try {
                $pack = $langPackService->findByIdentifier($id);
                if (! $pack) {
                    throw new \RuntimeException(__('language_packs.errors.identifier_not_found', ['identifier' => $id]));
                }
                $langPackService->performUpdate($pack, true);
                $success++;
            } catch (ExtensionOperationLockUnavailableException $e) {
                // 잠금 저장소 장애 — 남은 항목도 같은 오류로 하나씩 실패하므로 여기서 멈추고 끝에서 한 번만 알린다(#161 D8)
                $failed++;
                $aborted = true;
                Log::error('번들 일괄 업데이트 중단 — 작업 잠금 저장소 오류', ['type' => 'lang_pack', 'id' => $id]);
                break;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn(__('extensions.commands.bundled_update.item_failed', ['message' => $e->getMessage()]));
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'lang_pack', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $skipped = $aborted ? max(0, $totalItems - $attempted) : 0;

        $this->newLine();
        $this->info(__('extensions.commands.bundled_update.completed', ['success' => $success, 'failed' => $failed]));
        if ($aborted && ! $abortReportedByChild) {
            $this->error(__('extensions.commands.bundled_update.lock_unavailable_aborted', ['count' => $skipped]));
        }

        // 검색 인덱스 재생성 — 운영자가 부모 단계에서 선택했을 때만 수행 (인덱스 잠금·재색인 비용)
        // 잠금 저장소 장애로 멈췄으면 재생성하지 않는다 — 확장 갱신이 끝나지 않았다
        if ($rebuildSearchIndex && ! $aborted) {
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

        // 부모 프로세스가 결과를 복원할 수 있도록 표식 라인으로 페이로드 출력
        // skipped·aborted 는 7.1.5 부터 — 옛 부모는 모르는 키를 무시한다
        $this->line(self::RESULT_PREFIX.json_encode([
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'aborted' => $aborted,
        ], JSON_UNESCAPED_UNICODE));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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
     * @return array{success: int, failed: int, skipped: int, aborted: bool}|null 실행했으면 집계, 프로세스를 띄울 수 없으면 null
     */
    private function runLangPackPhaseInFreshProcess(array $langPacks): ?array
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $manifestPath = storage_path('app/core_pending'.DIRECTORY_SEPARATOR.'bundled-lang-packs-manifest_'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode(['modules' => [], 'plugins' => [], 'templates' => [], 'lang_packs' => array_values($langPacks)], JSON_UNESCAPED_UNICODE));

        try {
            $command = [config('process.php_binary', PHP_BINARY), base_path('artisan'), 'core:execute-bundled-updates', '--manifest='.$manifestPath];
            if ($this->option('force')) {
                $command[] = '--force';
            }
            $commandLine = implode(' ', array_map('escapeshellarg', $command)).' 2>&1';

            // audit:allow spawn-after-vendor-swap-clears-package-manifest 이 spawn 은 vendor 를 바꾸지 않고, 부모 core:update 가
            // 이미 패키지 매니페스트를 다시 만든 뒤 띄운 일괄 업데이트 자식 안에서 언어팩 단계만 떼어 낸다.
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
            proc_close($process);

            if ($payload === null) {
                Log::warning('번들 일괄 업데이트: 언어팩 단계 새 프로세스가 결과를 내지 않음 — 실패로 집계');

                return ['success' => 0, 'failed' => count($langPacks), 'skipped' => 0, 'aborted' => false];
            }

            return [
                'success' => (int) ($payload['success'] ?? 0),
                'failed' => (int) ($payload['failed'] ?? 0),
                'skipped' => (int) ($payload['skipped'] ?? 0),
                'aborted' => (bool) ($payload['aborted'] ?? false),
            ];
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
