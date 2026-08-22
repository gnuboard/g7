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

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = new ExtensionUpdateQueueListener;
    }

    /**
     * 훅 구독이 올바르게 설정되어 있는지 확인합니다.
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
     * 성공 업데이트 시 queue:restart가 호출됩니다.
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
     */
    public function test_queue_restart_not_called_on_failed_update(): void
    {
        Artisan::shouldReceive('call')
            ->never();

        $this->listener->handleAfterUpdate('test-module', ['success' => false]);
    }

    /**
     * result에 success 키가 없으면 queue:restart가 호출되지 않습니다.
     */
    public function test_queue_restart_not_called_when_success_key_missing(): void
    {
        Artisan::shouldReceive('call')
            ->never();

        $this->listener->handleAfterUpdate('test-module', []);
    }

    /**
     * queue:restart 실패 시 예외가 전파되지 않습니다.
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
