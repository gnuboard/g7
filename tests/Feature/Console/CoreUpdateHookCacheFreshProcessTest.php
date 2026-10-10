<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Support\HookCacheFreshProcess;
use ReflectionClass;
use Tests\TestCase;

/**
 * 코어 업데이트 끝에 훅 캐시를 새 프로세스에서 다시 굽는다 (#138 후속 — 설계 결정)
 *
 * 코어 업데이트는 이전 코드로 부팅한 프로세스에서 끝까지 돈다. 시작 때 훅 캐시가 없던 사이트는 흐름 중간의
 * 재생성이 이전 리스너 구독으로 구워질 수 있어, 새 코드로 부팅하는 새 프로세스에서 한 번 더 굽는다.
 *
 * PHPUnit 실행 중에는 실제 훅 캐시를 다시 굽지 않는다(다른 테스트가 쓰는 캐시를 덮는다) — 새 프로세스 실행기는
 * 해가 없는 명령으로 경로를 재고, 코어 업데이트가 그 실행기를 흐름 끝에서 부르는지는 소스로 본다.
 */
class CoreUpdateHookCacheFreshProcessTest extends TestCase
{
    /**
     * 새 프로세스 실행기는 artisan 을 별도 프로세스로 돌려 종료 코드와 출력을 돌려준다.
     *
     * @scenario case=fresh_process_runner
     *
     * @effects artisan_runs_in_fresh_process_with_exit_code
     *
     * @return void 반환값 없음
     */
    public function test_runner_executes_artisan_in_a_fresh_process(): void
    {
        $ok = HookCacheFreshProcess::run(['--version']);

        $this->assertTrue($ok['ok'], $ok['output']);
        $this->assertSame(0, $ok['exit_code']);
        $this->assertStringContainsString('Laravel', $ok['output']);

        $failed = HookCacheFreshProcess::run(['no-such:command-xyz']);

        $this->assertFalse($failed['ok']);
        $this->assertNotSame(0, $failed['exit_code']);
    }

    /**
     * 코어 업데이트는 config·라우트 캐시를 되살린 뒤 새 프로세스로 hooks:cache 를 실행하고, 실패하면 수동 명령을 안내한다.
     *
     * @scenario case=core_update_flow
     *
     * @effects core_update_rebuilds_hook_cache_in_fresh_process_after_route_cache
     *
     * @return void 반환값 없음
     */
    public function test_core_update_rebuilds_hook_cache_in_fresh_process_at_the_end(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(CoreUpdateCommand::class))->getFileName());
        $code = preg_replace('#//[^\n]*#', '', $source);

        $route = strpos($code, 'RouteCacheHelper::rebuild()');
        $this->assertNotFalse($route);

        $hook = strpos($code, '$this->rebuildHookCacheInFreshProcess(', $route);
        $prompt = strpos($code, '$promptResult = $this->runBundledExtensionUpdatePrompt(');
        $this->assertNotFalse($hook, '라우트 캐시를 되살린 뒤 새 프로세스로 훅 캐시를 굽지 않는다');
        $this->assertLessThan($prompt, $hook, '훅 캐시는 모든 파일이 안착하고 라우트 캐시까지 되살린 뒤, 일괄 업데이트 질문 전에 굽는다');
        $this->assertStringContainsString("HookCacheFreshProcess::run(['hooks:cache'])", $code, '재굽기 헬퍼가 새 프로세스 실행기를 쓰지 않는다');
        $this->assertStringContainsString('php artisan hooks:cache', $source, '실패 시 수동 명령 안내가 없다');
    }

    /**
     * 번들 확장 일괄 업데이트와 핸드오프 종료도 새 프로세스로 훅 캐시를 다시 굽는다.
     *
     * 일괄 업데이트는 이전 코드로 부팅한 이 프로세스에서 확장마다 훅 캐시를 재생성하므로, 앞서 새 프로세스로
     * 구운 캐시를 이전 리스너 구독으로 덮어쓴다. 핸드오프로 멈춘 업데이트는 정상 종료 경로를 지나지 않는다.
     *
     * @scenario case=core_update_late_paths
     *
     * @effects hook_cache_rebuilt_after_bundled_updates_and_on_handoff
     *
     * @return void 반환값 없음
     */
    public function test_bundled_update_prompt_and_handoff_also_rebuild_hook_cache_in_fresh_process(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(CoreUpdateCommand::class))->getFileName());
        $code = preg_replace('#//[^\n]*#', '', $source);

        $call = '$this->rebuildHookCacheInFreshProcess(';
        $prompt = strpos($code, '$promptResult = $this->runBundledExtensionUpdatePrompt(');
        $handoff = strpos($code, 'catch (UpgradeHandoffException $e)');
        $this->assertNotFalse($prompt);
        $this->assertNotFalse($handoff);

        $afterPrompt = strpos($code, $call, $prompt);
        $this->assertNotFalse($afterPrompt, '번들 일괄 업데이트 뒤 훅 캐시를 새 프로세스로 다시 굽지 않는다');
        $this->assertLessThan($handoff, $afterPrompt, '일괄 업데이트 뒤 재굽기는 정상 종료 경로 안에 있어야 한다');

        $handoffClear = strpos($code, '$service->clearAllCaches();', $handoff);
        $inHandoff = strpos($code, $call, $handoff);
        $this->assertNotFalse($inHandoff, '핸드오프 종료 경로가 훅 캐시를 새 프로세스로 굽지 않는다');
        $this->assertGreaterThan($handoffClear, $inHandoff, '핸드오프 재굽기는 캐시 비우기 뒤에 온다');
    }
}
