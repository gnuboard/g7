<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdateStep;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `core:update` 는 번들 확장 일괄 업데이트가 끝난 뒤에 점검 모드를 푼다 — 실패·예외에서도 반드시 푼다.
 *
 * 종전에는 코어 마무리 직후 점검을 풀어, 새 코어와 옛 확장이 섞인 사이트가 일괄 업데이트 동안 열려 있었다. 실제 코어 업데이트는
 * 파일·DB·캐시를 바꾸므로 시험에서 돌릴 수 없어 순서를 소스 계약으로 잠근다. 웹 경로는 단계 순서(번들 → 마무리)로 같다.
 *
 * @scenario-manifest tests/scenarios/maintenance-bypass.yaml
 */
class CoreUpdateMaintenanceReleaseOrderTest extends TestCase
{
    /**
     * CLI 성공 경로 — 점검 해제는 일괄 업데이트 호출을 감싼 finally 안에 있고, 그보다 앞의 정상 경로 해제는 없다.
     *
     * @scenario case=release_order
     *
     * @effects maintenance_released_after_bundled_updates
     *
     * @return void 반환값 없음
     */
    public function test_cli_releases_maintenance_after_bundled_updates_in_finally(): void
    {
        $method = new ReflectionMethod(CoreUpdateCommand::class, 'runUpdate');
        $source = implode('', array_slice(file((string) $method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $prompt = strpos($source, '$promptResult = $this->runBundledExtensionUpdatePrompt(');
        $this->assertNotFalse($prompt);

        $before = substr($source, 0, $prompt);
        $tail = substr($before, (int) strrpos($before, 'CoreBackupHelper::deleteBackup($backupPath);'));
        $this->assertStringNotContainsString('disableMaintenanceMode()', $tail, '코어 마무리 직후(일괄 업데이트 전)에 점검을 풀면 안 된다');

        $after = substr($source, $prompt, 900);
        $this->assertMatchesRegularExpression('/\}\s*finally\s*\{[^}]*disableMaintenanceMode\(\)/s', $after, '일괄 업데이트를 감싼 finally 에서 푼다');
    }

    /**
     * 웹 경로 — 번들 단계가 마무리(점검 해제) 단계보다 앞이다.
     *
     * @scenario case=release_order
     *
     * @effects maintenance_released_after_bundled_updates
     *
     * @return void 반환값 없음
     */
    public function test_web_steps_run_bundled_before_finalize(): void
    {
        $steps = array_map(fn (CoreUpdateStep $s) => $s->value, CoreUpdateStep::cases());

        $this->assertLessThan(array_search(CoreUpdateStep::Finalize->value, $steps, true), array_search(CoreUpdateStep::Bundled->value, $steps, true));
        $this->assertTrue(method_exists(CoreUpdateOrchestrator::class, 'run') || class_exists(CoreUpdateOrchestrator::class));
    }
}
