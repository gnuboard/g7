<?php

namespace Tests\Unit\Listeners;

use App\Listeners\ExtensionUpdateQueueListener;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * ExtensionUpdateQueueListener 테스트
 *
 * 확장 업데이트 후 큐 워커 재시작 리스너의 기능을 검증합니다.
 */
class ExtensionUpdateQueueListenerTest extends TestCase
{
    private ExtensionUpdateQueueListener $listener;

    /**
     * 리스너 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = new ExtensionUpdateQueueListener;
    }

    /**
     * 훅 구독이 올바르게 설정되어 있는지 확인합니다.
     *
     * @effects queue_restart_listener_subscribes_code_intake_events_sync
     *
     * @return void 반환값 없음
     */
    public function test_subscribed_hooks_are_configured(): void
    {
        $hooks = ExtensionUpdateQueueListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.modules.after_update', $hooks);
        $this->assertArrayHasKey('core.plugins.after_update', $hooks);

        $this->assertEquals('handleAfterUpdate', $hooks['core.modules.after_update']['method']);
        $this->assertEquals('handleAfterUpdate', $hooks['core.plugins.after_update']['method']);

        $this->assertEquals(100, $hooks['core.modules.after_update']['priority']);
        $this->assertEquals(100, $hooks['core.plugins.after_update']['priority']);
    }

    /**
     * 세 확장 종류(모듈·플러그인·템플릿)의 설치·활성화·업데이트·제거 12건을 구독하고,
     * 전부 동기 실행이다 — 큐로 가면 CLI 에서는 처리 주체가 없고, 워커가 자기 재시작을 실행하게 된다(#138).
     * 제거는 설계 확정(2026-11-08)으로 들어왔다 — 지운 확장의 클래스·리스너를 상주 워커가 재시작 전까지 계속 들고 있다.
     *
     * @effects queue_restart_listener_subscribes_code_intake_events_sync
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_only_code_intake_events_and_all_run_sync(): void
    {
        $hooks = ExtensionUpdateQueueListener::getSubscribedHooks();

        $this->assertSame([
            'core.modules.after_install',
            'core.modules.after_activate',
            'core.modules.after_update',
            'core.plugins.after_install',
            'core.plugins.after_activate',
            'core.plugins.after_update',
            'core.templates.after_install',
            'core.templates.after_activate',
            'core.templates.after_version_update',
            'core.modules.after_uninstall',
            'core.plugins.after_uninstall',
            'core.templates.after_uninstall',
        ], array_keys($hooks));

        foreach ($hooks as $hook => $config) {
            $this->assertTrue($config['sync'] ?? false, "{$hook} 는 sync 여야 한다");
            $this->assertSame(100, $config['priority'], "{$hook} 우선순위");
        }

        $this->assertSame('handleAfterSetup', $hooks['core.modules.after_install']['method']);
        $this->assertSame('handleAfterSetup', $hooks['core.plugins.after_activate']['method']);
        $this->assertSame('handleAfterUninstall', $hooks['core.modules.after_uninstall']['method']);
        $this->assertSame('handleAfterUninstall', $hooks['core.templates.after_uninstall']['method']);
        $this->assertSame('handleAfterPluginUninstall', $hooks['core.plugins.after_uninstall']['method']);
        $this->assertSame('handleAfterSetup', $hooks['core.templates.after_install']['method']);
        $this->assertSame('handleAfterTemplateActivate', $hooks['core.templates.after_activate']['method']);
        $this->assertSame('handleAfterUpdate', $hooks['core.templates.after_version_update']['method']);
    }

    /**
     * 템플릿 설치·활성화·버전 업데이트도 재시작 신호를 보낸다(설계 확정 2026-11-08 「템플릿도 같게」). 템플릿 활성화 완료 훅은
     * 식별자 없이 정보 배열 하나만 넘기고, 버전 업데이트는 결과의 success 가 true 일 때만 보낸다.
     *
     * @effects queue_restart_runs_on_template_install_activate_update
     *
     * @return void 반환값 없음
     */
    public function test_queue_restart_called_on_template_install_activate_and_successful_update(): void
    {
        Artisan::shouldReceive('call')
            ->times(3)
            ->with('queue:restart');

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->listener->handleAfterSetup('test-template', ['identifier' => 'test-template']);
        $this->listener->handleAfterTemplateActivate(['identifier' => 'test-template']);
        $this->listener->handleAfterUpdate('test-template', ['success' => false], []);
        $this->listener->handleAfterUpdate('test-template', ['success' => true], []);
    }

    /**
     * 모듈·템플릿 제거 완료 훅은 성공 분기에서만 발화하므로 결과 검사 없이 재시작 신호를 보낸다
     * (인자: 식별자, 제거 전 정보, 데이터 삭제 여부).
     *
     * @effects queue_restart_runs_on_uninstall
     *
     * @return void 반환값 없음
     */
    public function test_queue_restart_called_on_module_or_template_uninstall(): void
    {
        Artisan::shouldReceive('call')
            ->twice()
            ->with('queue:restart');

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->listener->handleAfterUninstall('test-module', ['identifier' => 'test-module'], true);
        $this->listener->handleAfterUninstall('test-template', [], false);
    }

    /**
     * 플러그인 제거 완료 훅은 실패에도 발화한다(인자: 식별자, 데이터 삭제 여부, 결과) — 성공일 때만 재시작 신호를 보낸다.
     *
     * @effects queue_restart_runs_on_uninstall, queue_restart_skipped_when_uninstall_failed
     *
     * @return void 반환값 없음
     */
    public function test_queue_restart_called_only_on_successful_plugin_uninstall(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:restart');

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->listener->handleAfterPluginUninstall('test-plugin', false, false);
        $this->listener->handleAfterPluginUninstall('test-plugin', true, true);
    }

    /**
     * 설치·활성화 핸들러는 결과 검사 없이 queue:restart 를 호출합니다 (성공 분기에서만 발화).
     *
     * @effects queue_restart_runs_on_install_or_activate
     *
     * @return void 반환값 없음
     */
    public function test_queue_restart_called_on_install_or_activate(): void
    {
        Artisan::shouldReceive('call')
            ->twice()
            ->with('queue:restart');

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->listener->handleAfterSetup('test-module', ['version' => '1.0.0']);
        $this->listener->handleAfterSetup('test-plugin');
    }

    /**
     * 성공 업데이트 시 queue:restart가 호출됩니다.
     *
     * @effects queue_restart_runs_on_successful_update
     *
     * @return void 반환값 없음
     */
    public function test_queue_restart_called_on_successful_update(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:restart');

        $this->listener->handleAfterUpdate('test-module', ['success' => true]);
    }

    /**
     * 실패 업데이트 시 queue:restart가 호출되지 않습니다.
     *
     * @effects queue_restart_skipped_when_update_not_successful
     *
     * @return void 반환값 없음
     */
    public function test_queue_restart_not_called_on_failed_update(): void
    {
        Artisan::shouldReceive('call')
            ->never();

        $this->listener->handleAfterUpdate('test-module', ['success' => false]);
    }

    /**
     * result에 success 키가 없으면 queue:restart가 호출되지 않습니다.
     *
     * @effects queue_restart_skipped_when_update_not_successful
     *
     * @return void 반환값 없음
     */
    public function test_queue_restart_not_called_when_success_key_missing(): void
    {
        Artisan::shouldReceive('call')
            ->never();

        $this->listener->handleAfterUpdate('test-module', []);
    }

    /**
     * queue:restart 실패 시 예외가 전파되지 않습니다.
     *
     * @effects queue_restart_failure_does_not_break_extension_operation
     *
     * @return void 반환값 없음
     */
    public function test_queue_restart_failure_does_not_propagate(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:restart')
            ->andThrow(new \RuntimeException('Cache driver not available'));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '큐 워커 재시작 실패');
            });

        Log::shouldReceive('info')->zeroOrMoreTimes();

        // 예외가 전파되지 않아야 함
        $this->listener->handleAfterUpdate('test-module', ['success' => true]);
    }

    /**
     * 성공 업데이트 시 로그가 기록됩니다.
     *
     * @effects queue_restart_runs_on_successful_update
     *
     * @return void 반환값 없음
     */
    public function test_successful_restart_logs_info(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:restart');

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '큐 워커 재시작 완료')
                    && $context['identifier'] === 'test-module';
            });

        $this->listener->handleAfterUpdate('test-module', ['success' => true]);
    }

    /**
     * 세 번째 인수(info)가 전달되어도 정상 동작합니다.
     *
     * @effects queue_restart_runs_on_successful_update
     *
     * @return void 반환값 없음
     */
    public function test_handles_optional_info_parameter(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:restart');

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->listener->handleAfterUpdate('test-module', ['success' => true], ['version' => '1.2.0']);
    }
}
