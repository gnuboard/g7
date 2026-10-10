<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Traits\RendersLayoutSyncResult;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Search\SearchIndexMaintenanceManager;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
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
    use RendersLayoutSyncResult;

    public const RESULT_PREFIX = '[BUNDLED-RESULT] ';

    protected $signature = 'core:execute-bundled-updates
        {--manifest= : 업데이트 매니페스트 JSON 파일 경로 (필수)}
        {--force : 강제 업데이트 플래그 (확장 매니저 update 호출에 전달)}
        {--rebuild-search-index : 일괄 업데이트 후 검색 인덱스 재생성 (매니페스트의 선택보다 우선)}';

    protected $description = '번들 확장 일괄 업데이트를 실행합니다 (CoreUpdateCommand 내부용 — fresh PHP 프로세스에서 호출)';

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
        }

        $manifest = json_decode(File::get($manifestPath), true);
        if (! is_array($manifest)) {
            $this->error(__('extensions.commands.bundled_update.manifest_parse_failed', ['path' => $manifestPath]));

            return self::FAILURE;
        }

        // 부모(core:update)가 운영자 선택을 매니페스트에 실어 보낸다 — 자식이 임의로 정하지 않는다
        $rebuildSearchIndex = (bool) $this->option('rebuild-search-index') || (bool) ($manifest['rebuild_search_index'] ?? false);

        // 종류 순서(모듈 → 플러그인 → 템플릿 → 언어팩)는 아래 반복문 순서가 지킨다. 같은 종류 안에서도 의존하는 쪽이
        // 뒤에 오도록 번들 manifest 의 같은 종류 의존 선언으로 정렬한다 — 부모(이전 버전 core:update)가 만든 목록의 순서를
        // 믿지 않는다.
        $modules = self::orderByDependencies(
            $manifest['modules'] ?? [],
            fn (string $id) => self::bundledSameTypeDependencies('modules', $id, 'module.json')
        );
        $plugins = self::orderByDependencies(
            $manifest['plugins'] ?? [],
            fn (string $id) => self::bundledSameTypeDependencies('plugins', $id, 'plugin.json')
        );
        $templates = $manifest['templates'] ?? [];
        $langPacks = $manifest['lang_packs'] ?? [];

        $moduleManager = app(ModuleManager::class);
        $pluginManager = app(PluginManager::class);
        $templateManager = app(TemplateManager::class);
        $langPackService = app(LanguagePackService::class);

        $success = 0;
        $failed = 0;

        $this->info(__('extensions.commands.bundled_update.child_running'));

        foreach ($modules as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            $strategy = (string) ($entry['strategy'] ?? 'overwrite');
            if ($id === '') {
                continue;
            }
            $this->line(__('extensions.commands.bundled_update.running_items.module', ['identifier' => $id, 'strategy' => $strategy]));
            try {
                $result = $moduleManager->updateModule($id, true, null, VendorMode::Auto, $strategy, null, 'bundled');
                $this->lineLayoutSyncSummary($result);
                $this->renderDependencyWarnings($result, '  ');
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn(__('extensions.commands.bundled_update.item_failed', ['message' => $e->getMessage()]));
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'module', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($plugins as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            $strategy = (string) ($entry['strategy'] ?? 'overwrite');
            if ($id === '') {
                continue;
            }
            $this->line(__('extensions.commands.bundled_update.running_items.plugin', ['identifier' => $id, 'strategy' => $strategy]));
            try {
                $result = $pluginManager->updatePlugin($id, true, null, VendorMode::Auto, $strategy, null, 'bundled');
                $this->lineLayoutSyncSummary($result);
                $this->renderDependencyWarnings($result, '  ');
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn(__('extensions.commands.bundled_update.item_failed', ['message' => $e->getMessage()]));
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'plugin', 'id' => $id, 'error' => $e->getMessage()]);
            }
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
                $failed++;
                $this->warn(__('extensions.commands.bundled_update.item_failed', ['message' => $e->getMessage()]));
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'template', 'id' => $id, 'error' => $e->getMessage()]);
            }
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
                $failed++;
                $this->warn(__('extensions.commands.bundled_update.item_failed', ['message' => $e->getMessage()]));
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'lang_pack', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $this->newLine();
        $this->info(__('extensions.commands.bundled_update.completed', ['success' => $success, 'failed' => $failed]));

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

        // 부모 프로세스가 결과를 복원할 수 있도록 표식 라인으로 페이로드 출력
        $this->line(self::RESULT_PREFIX.json_encode([
            'success' => $success,
            'failed' => $failed,
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
     * @return array{success: int, failed: int}|null 실행했으면 집계, 프로세스를 띄울 수 없으면 null
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
            $process = proc_open($commandLine, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), $_ENV));
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

                return ['success' => 0, 'failed' => count($langPacks)];
            }

            return ['success' => (int) ($payload['success'] ?? 0), 'failed' => (int) ($payload['failed'] ?? 0)];
        } finally {
            if (File::exists($manifestPath)) {
                File::delete($manifestPath);
            }
        }
    }

    /**
     * 같은 종류 항목을 의존 대상이 먼저 오도록 정렬합니다(안정 정렬 — 의존이 없으면 원래 순서).
     *
     * 목록에 없는 의존은 무시하고, 순환 의존은 원래 순서로 둔다. 어떤 경우에도 항목을 잃거나 내용을 바꾸지 않는다.
     *
     * @param  array<int, array<string, mixed>>  $entries  매니페스트 항목 (`identifier`·`strategy`)
     * @param  callable(string): array<int, string>  $dependenciesOf  식별자 → 같은 종류 의존 식별자 목록
     * @return array<int, array<string, mixed>> 정렬된 항목
     */
    public static function orderByDependencies(array $entries, callable $dependenciesOf): array
    {
        $byId = [];
        foreach ($entries as $index => $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            $byId[$id !== '' ? $id : "#{$index}"] = $entry;
        }

        $sorted = [];
        $state = []; // 1 = 방문 중, 2 = 완료

        $visit = function (string $id) use (&$visit, &$sorted, &$state, $byId, $dependenciesOf): void {
            if (($state[$id] ?? 0) !== 0) {
                return; // 완료됐거나 순환 — 순환은 끊고 원래 순서에 맡긴다
            }
            $state[$id] = 1;
            foreach ($dependenciesOf($id) as $dependency) {
                if (isset($byId[$dependency])) {
                    $visit($dependency);
                }
            }
            $state[$id] = 2;
            $sorted[] = $byId[$id];
        };

        foreach (array_keys($byId) as $id) {
            $visit((string) $id);
        }

        return $sorted;
    }

    /**
     * 번들 manifest 가 선언한 같은 종류 의존 식별자를 읽습니다(모듈 → 모듈, 플러그인 → 플러그인).
     *
     * @param  string  $type  `modules`·`plugins`
     * @param  string  $identifier  확장 식별자
     * @param  string  $manifestFile  manifest 파일 이름
     * @return array<int, string> 의존 식별자 목록 (읽지 못하면 빈 배열)
     */
    private static function bundledSameTypeDependencies(string $type, string $identifier, string $manifestFile): array
    {
        $path = base_path("{$type}/_bundled/{$identifier}/{$manifestFile}");

        if (! File::isFile($path)) {
            return [];
        }

        $manifest = json_decode((string) File::get($path), true);
        $dependencies = is_array($manifest) ? ($manifest['dependencies'][$type] ?? []) : [];

        return is_array($dependencies) ? array_map('strval', array_keys($dependencies)) : [];
    }
}
