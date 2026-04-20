<?php

namespace App\Console\Commands\Core\Concerns;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
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
 */
trait BundledExtensionUpdatePrompt
{
    /**
     * 번들 업데이트 목록 수집.
     *
     * @return array{modules: array, plugins: array, templates: array}
     */
    protected function collectBundledUpdates(
        ModuleManager $moduleManager,
        PluginManager $pluginManager,
        TemplateManager $templateManager,
    ): array {
        // CoreUpdateService::collectBundledExtensionUpdates() 를 통해 _bundled manifest 버전을
        // DB 현재 버전과 직접 비교한다. Manager::checkXxxUpdate() 의 "GitHub 엄격 우선" 정책을
        // 우회하여 GitHub 미릴리스 상태에서도 _bundled 신버전을 정확히 감지.
        return app(\App\Services\CoreUpdateService::class)->collectBundledExtensionUpdates();
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
        $total = count($updates['modules']) + count($updates['plugins']) + count($updates['templates']);

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
        $this->newLine();

        if (! $force && ! $this->confirm('일괄 업데이트를 진행하시겠습니까?', true)) {
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
     * @return array<string, string>  key: "{type}:{identifier}", value: strategy
     */
    private function collectPerExtensionStrategies(array $updates, string $globalStrategy, bool $force): array
    {
        $strategies = [];
        foreach (['modules', 'plugins', 'templates'] as $type) {
            foreach ($updates[$type] as $ext) {
                $strategies["{$type}:{$ext['identifier']}"] = $globalStrategy;
            }
        }

        if ($force) {
            return $strategies;
        }

        if (! $this->confirm('전역 전략과 다르게 적용할 확장이 있습니까?', false)) {
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
     * @return array{success: int, failed: int, skipped: int, has_updates: bool}
     */
    private function executeBulkUpdate(
        ModuleManager $moduleManager,
        PluginManager $pluginManager,
        TemplateManager $templateManager,
        array $updates,
        array $strategies,
    ): array {
        $success = 0;
        $failed = 0;

        $this->newLine();
        $this->info('── 일괄 업데이트 실행 ──');

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
