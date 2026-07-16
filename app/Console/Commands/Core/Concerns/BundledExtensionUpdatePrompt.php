<?php

namespace App\Console\Commands\Core\Concerns;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 코어 업데이트 완료 후 _bundled 번들에 신버전이 포함된
 * 모듈/플러그인/템플릿을 일괄 업데이트하는 대화형 프롬프트.
 *
 * CoreUpdateCommand 에서 사용되며, 다음 단계로 동작한다.
 *  1) 각 확장 매니저의 checkAllXxxForUpdates() 로 번들 업데이트 감지
 *  2) 감지 목록 표시 + 일괄 업데이트 진행 여부 확인
 *  3) 동의 시 전역 레이아웃 전략(overwrite|keep) 1회 질의
 *  4) 확장별 예외 변경 질의 (choice 로 다중 선택)
 *  5) 각 확장 매니저의 updateXxx() 호출
 *  6) 결과 요약 출력
 *
 * --force 옵션이 지정된 경우: 프롬프트 스킵 + 전역 전략 'overwrite' 로 즉시 실행.
 *
 * 본 트레이트를 사용하는 Command 는 HasUnifiedConfirm 트레이트도 함께 사용해야 한다
 * (yes/no 입력 정규화 및 재질문 루프 제공).
 */
trait BundledExtensionUpdatePrompt
{
    /**
     * 번들 업데이트 목록 수집.
     *
     * @return array{modules: array, plugins: array, templates: array, lang_packs: array}
     */
    protected function collectBundledUpdates(
        ModuleManager $moduleManager,
        PluginManager $pluginManager,
        TemplateManager $templateManager,
    ): array {
        // CoreUpdateService::collectBundledExtensionUpdates() 를 통해 _bundled manifest 버전을
        // DB 현재 버전과 직접 비교한다. Manager::checkXxxUpdate() 의 "GitHub 엄격 우선" 정책을
        // 우회하여 GitHub 미릴리스 상태에서도 _bundled 신버전을 정확히 감지.
        $extUpdates = app(CoreUpdateService::class)->collectBundledExtensionUpdates();

        // 언어팩도 동일 패턴으로 _bundled vs DB 직접 비교 (LanguagePackService::collectBundledLangPackUpdates).
        $extUpdates['lang_packs'] = app(LanguagePackService::class)->collectBundledLangPackUpdates();

        return $extUpdates;
    }

    /**
     * 일괄 업데이트 UX 를 실행하고 결과를 반환한다.
     *
     * @return array{success: int, failed: int, skipped: int, has_updates: bool}
     */
    protected function runBundledExtensionUpdatePrompt(
        ModuleManager $moduleManager,
        PluginManager $pluginManager,
        TemplateManager $templateManager,
        bool $force,
    ): array {
        $updates = $this->collectBundledUpdates($moduleManager, $pluginManager, $templateManager);
        $updates['lang_packs'] = $updates['lang_packs'] ?? [];
        $total = count($updates['modules']) + count($updates['plugins']) + count($updates['templates']) + count($updates['lang_packs']);

        if ($total === 0) {
            $this->info('활성 확장이 최신 번들과 일치합니다.');

            return ['success' => 0, 'failed' => 0, 'skipped' => 0, 'has_updates' => false];
        }

        $this->newLine();
        $this->info('번들에 새 버전이 포함된 확장이 감지되었습니다:');
        foreach ($updates['modules'] as $m) {
            $this->line("  [모듈]    {$m['identifier']}   {$m['current_version']} → {$m['latest_version']}");
        }
        foreach ($updates['plugins'] as $p) {
            $this->line("  [플러그인] {$p['identifier']}   {$p['current_version']} → {$p['latest_version']}");
        }
        foreach ($updates['templates'] as $t) {
            $this->line("  [템플릿]  {$t['identifier']}   {$t['current_version']} → {$t['latest_version']}");
        }
        foreach ($updates['lang_packs'] as $lp) {
            $this->line("  [언어팩]  {$lp['identifier']}   {$lp['current_version']} → {$lp['latest_version']}");
        }
        $this->newLine();

        if (! $force && ! $this->unifiedConfirm('일괄 업데이트를 진행하시겠습니까?', true)) {
            $this->info('일괄 업데이트를 건너뜁니다.');

            return ['success' => 0, 'failed' => 0, 'skipped' => $total, 'has_updates' => true];
        }

        // 전역 레이아웃 전략
        $globalStrategy = 'overwrite';
        if (! $force) {
            $answer = $this->choice(
                '전역 레이아웃 전략을 선택하세요',
                ['overwrite' => '모든 레이아웃을 번들로 덮어쓰기', 'keep' => '사용자가 수정한 레이아웃은 보존'],
                'overwrite',
            );
            $globalStrategy = $answer;
        }

        // 확장별 전략 오버라이드
        $strategies = $this->collectPerExtensionStrategies($updates, $globalStrategy, $force);

        // 일괄 실행
        return $this->executeBulkUpdate(
            $moduleManager,
            $pluginManager,
            $templateManager,
            $updates,
            $strategies,
        );
    }

    /**
     * 확장별 전략 오버라이드 수집.
     *
     * @param  array  $updates  collectBundledUpdates() 반환값
     * @return array<string, string> key: "{type}:{identifier}", value: strategy
     */
    private function collectPerExtensionStrategies(array $updates, string $globalStrategy, bool $force): array
    {
        // lang_packs 는 layout 이 없어 overwrite/keep strategy 가 의미 없으므로 의도적으로 제외.
        // 매니페스트에는 strategy 없이 그대로 전달되어 ExecuteBundledUpdatesCommand 가 일괄 처리.
        $strategies = [];
        foreach (['modules', 'plugins', 'templates'] as $type) {
            foreach ($updates[$type] as $ext) {
                $strategies["{$type}:{$ext['identifier']}"] = $globalStrategy;
            }
        }

        if ($force) {
            return $strategies;
        }

        if (! $this->unifiedConfirm('전역 전략과 다르게 적용할 확장이 있습니까?', false)) {
            return $strategies;
        }

        $choices = [];
        foreach ($updates['modules'] as $m) {
            $choices[] = "[모듈] {$m['identifier']}";
        }
        foreach ($updates['plugins'] as $p) {
            $choices[] = "[플러그인] {$p['identifier']}";
        }
        foreach ($updates['templates'] as $t) {
            $choices[] = "[템플릿] {$t['identifier']}";
        }

        $overrideStrategy = $globalStrategy === 'overwrite' ? 'keep' : 'overwrite';

        $this->info("예외 확장은 '{$overrideStrategy}' 전략이 적용됩니다.");
        $selected = $this->ask('예외 확장 선택 (쉼표로 구분, 빈 값이면 건너뛰기)', '');

        if (trim((string) $selected) === '') {
            return $strategies;
        }

        $lookup = [];
        $idx = 0;
        foreach (['modules', 'plugins', 'templates'] as $type) {
            foreach ($updates[$type] as $ext) {
                $lookup[$choices[$idx]] = "{$type}:{$ext['identifier']}";
                $idx++;
            }
        }

        foreach (array_map('trim', explode(',', (string) $selected)) as $choice) {
            if (isset($lookup[$choice])) {
                $strategies[$lookup[$choice]] = $overrideStrategy;
            }
        }

        return $strategies;
    }

    /**
     * 실제 일괄 업데이트 실행.
     *
     * 구조 fix (beta.4 도입): 부모(`core:update`) 프로세스의 stale memory 가 신버전 sync
     * 메서드를 호출하지 못하던 결함의 영구 차단. 사용자 선택을 매니페스트로 직렬화한 후
     * `core:execute-bundled-updates` 를 별도 PHP 프로세스에서 spawn 하여 실행한다 (자식은
     * 디스크의 fresh 코어 코드 로드).
     *
     * proc_open 미지원 / 실패 환경에서는 in-process fallback 으로 안전하게 전환 (기존 흐름).
     *
     * @return array{success: int, failed: int, skipped: int, has_updates: bool}
     */
    private function executeBulkUpdate(
        ModuleManager $moduleManager,
        PluginManager $pluginManager,
        TemplateManager $templateManager,
        array $updates,
        array $strategies,
    ): array {
        $manifest = $this->buildBundledUpdateManifest($updates, $strategies);
        if ($this->bundledManifestIsEmpty($manifest)) {
            return ['success' => 0, 'failed' => 0, 'skipped' => 0, 'has_updates' => false];
        }

        $this->newLine();
        $this->info('── 일괄 업데이트 실행 ──');

        $spawnResult = $this->spawnBundledUpdates($manifest);
        if ($spawnResult !== null) {
            return [
                'success' => $spawnResult['success'],
                'failed' => $spawnResult['failed'],
                'skipped' => 0,
                'has_updates' => true,
            ];
        }

        // proc_open 미지원 / spawn 실패 — in-process fallback
        $this->warn('별도 프로세스 spawn 실패 — in-process fallback 으로 전환합니다.');

        return $this->executeBulkUpdateInProcess(
            $moduleManager,
            $pluginManager,
            $templateManager,
            $updates,
            $strategies,
        );
    }

    /**
     * 사용자 선택을 spawn 자식에 전달할 매니페스트 형식으로 직렬화.
     *
     * @return array{modules: array, plugins: array, templates: array, lang_packs: array}
     */
    private function buildBundledUpdateManifest(array $updates, array $strategies): array
    {
        $manifest = [
            'modules' => [],
            'plugins' => [],
            'templates' => [],
            'lang_packs' => [],
        ];

        foreach ($updates['modules'] ?? [] as $m) {
            $id = $m['identifier'];
            $manifest['modules'][] = [
                'identifier' => $id,
                'strategy' => $strategies["modules:{$id}"] ?? 'overwrite',
            ];
        }
        foreach ($updates['plugins'] ?? [] as $p) {
            $id = $p['identifier'];
            $manifest['plugins'][] = [
                'identifier' => $id,
                'strategy' => $strategies["plugins:{$id}"] ?? 'overwrite',
            ];
        }
        foreach ($updates['templates'] ?? [] as $t) {
            $id = $t['identifier'];
            $manifest['templates'][] = [
                'identifier' => $id,
                'strategy' => $strategies["templates:{$id}"] ?? 'overwrite',
            ];
        }
        foreach ($updates['lang_packs'] ?? [] as $lp) {
            $manifest['lang_packs'][] = ['identifier' => $lp['identifier']];
        }

        return $manifest;
    }

    private function bundledManifestIsEmpty(array $manifest): bool
    {
        return empty($manifest['modules'])
            && empty($manifest['plugins'])
            && empty($manifest['templates'])
            && empty($manifest['lang_packs']);
    }

    /**
     * `core:execute-bundled-updates` 를 별도 PHP 프로세스에서 실행.
     *
     * 자식 stdout 을 부모 콘솔로 실시간 전달 (단 `[BUNDLED-RESULT]` prefix 라인은
     * 결과 페이로드로 보관 후 부모 콘솔로 노출하지 않음). 종료 후 페이로드 파싱.
     *
     * @return array{success: int, failed: int}|null spawn 성공 시 결과, 실패/미지원 시 null
     */
    private function spawnBundledUpdates(array $manifest): ?array
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $manifestPath = storage_path('app/core_pending'.DIRECTORY_SEPARATOR.'bundled-updates-manifest_'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        try {
            $phpBinary = config('process.php_binary', PHP_BINARY);
            $artisan = base_path('artisan');

            $command = [
                $phpBinary,
                $artisan,
                'core:execute-bundled-updates',
                '--manifest='.$manifestPath,
            ];
            $commandLine = implode(' ', array_map('escapeshellarg', $command)).' 2>&1';

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            // ENV 합집합 (G7_UPDATE_IN_PROGRESS 등 핵심 플래그 자식에 전달)
            $env = array_merge(getenv(), $_ENV);

            $process = proc_open($commandLine, $descriptors, $pipes, base_path(), $env);
            if (! is_resource($process)) {
                return null;
            }

            fclose($pipes[0]);

            $resultPayload = null;
            $resultPrefix = ExecuteBundledUpdatesCommand::RESULT_PREFIX;

            while (! feof($pipes[1])) {
                $line = fgets($pipes[1]);
                if ($line === false) {
                    continue;
                }
                $trimmed = rtrim($line);

                if (str_starts_with($trimmed, $resultPrefix)) {
                    $json = substr($trimmed, strlen($resultPrefix));
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        $resultPayload = $decoded;
                    }

                    continue; // 부모 콘솔에 노출 안 함
                }

                $this->line($trimmed);
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            if ($resultPayload === null) {
                Log::warning('번들 spawn 자식이 결과 페이로드를 출력하지 않음 — 카운트 0 으로 처리');

                return ['success' => 0, 'failed' => 0];
            }

            return [
                'success' => (int) ($resultPayload['success'] ?? 0),
                'failed' => (int) ($resultPayload['failed'] ?? 0),
            ];
        } finally {
            if (File::exists($manifestPath)) {
                File::delete($manifestPath);
            }
        }
    }

    /**
     * in-process fallback — proc_open 미지원 환경 전용.
     *
     * 기존 (beta.3) 흐름의 직접 호출 패턴 보존. 단 부모 메모리의 stale 코드로 인해
     * sync 메서드 누락 결함이 재현될 수 있으므로 spawn 가능 환경에서는 사용되지 않는다.
     *
     * @return array{success: int, failed: int, skipped: int, has_updates: bool}
     */
    private function executeBulkUpdateInProcess(
        ModuleManager $moduleManager,
        PluginManager $pluginManager,
        TemplateManager $templateManager,
        array $updates,
        array $strategies,
    ): array {
        $success = 0;
        $failed = 0;

        foreach ($updates['modules'] as $m) {
            $id = $m['identifier'];
            $strategy = $strategies["modules:{$id}"] ?? 'overwrite';
            $this->line("→ [모듈] {$id} ({$strategy})");
            try {
                $moduleManager->updateModule($id, true, null, VendorMode::Auto, $strategy, null, 'bundled');
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  실패: {$e->getMessage()}");
                Log::error('번들 일괄 업데이트 실패', ['type' => 'module', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($updates['plugins'] as $p) {
            $id = $p['identifier'];
            $strategy = $strategies["plugins:{$id}"] ?? 'overwrite';
            $this->line("→ [플러그인] {$id} ({$strategy})");
            try {
                $pluginManager->updatePlugin($id, true, null, VendorMode::Auto, $strategy, null, 'bundled');
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  실패: {$e->getMessage()}");
                Log::error('번들 일괄 업데이트 실패', ['type' => 'plugin', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($updates['templates'] as $t) {
            $id = $t['identifier'];
            $strategy = $strategies["templates:{$id}"] ?? 'overwrite';
            $this->line("→ [템플릿] {$id} ({$strategy})");
            try {
                $templateManager->updateTemplate($id, true, null, $strategy, 'bundled');
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  실패: {$e->getMessage()}");
                Log::error('번들 일괄 업데이트 실패', ['type' => 'template', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $langPackService = app(LanguagePackService::class);
        foreach ($updates['lang_packs'] ?? [] as $lp) {
            $id = $lp['identifier'];
            $this->line("→ [언어팩] {$id}");
            try {
                $pack = $langPackService->findByIdentifier($id);
                if (! $pack) {
                    throw new \RuntimeException(__('language_packs.errors.identifier_not_found', ['identifier' => $id]));
                }
                $langPackService->performUpdate($pack, true);
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  실패: {$e->getMessage()}");
                Log::error('번들 일괄 업데이트 실패', ['type' => 'lang_pack', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $this->newLine();
        $this->info("업데이트 완료: 성공 {$success}, 실패 {$failed}");

        return [
            'success' => $success,
            'failed' => $failed,
            'skipped' => 0,
            'has_updates' => true,
        ];
    }

}
