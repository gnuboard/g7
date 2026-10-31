<?php

namespace Tests\Unit\Console\Commands;

use App\Events\GenericBroadcastEvent;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * BroadcastDashboardResources 커맨드 테스트
 */
class BroadcastDashboardResourcesTest extends TestCase
{
    /**
     * Mockery 기대값을 닫고 부모 정리를 실행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 커맨드가 관리자 대시보드 채널로 resources 갱신 이벤트를 방송하고 완료 문구를 출력하는지 검증한다.
     *
     * @scenario aspect=channel_and_event
     *
     * @effects dashboard_resources_broadcast_on_admin_dashboard_channel
     *
     * @return void 반환값 없음
     */
    public function test_command_broadcasts_resources_update(): void
    {
        // HookManager::broadcast 는 null/log 드라이버 또는 host 미설정 시 dispatch 스킵
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);

        Event::fake([GenericBroadcastEvent::class]);

        $this->artisan('dashboard:broadcast-resources')
            ->expectsOutput(__('dashboard.console.broadcast_resources.done'))
            ->assertExitCode(0);

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) {
            return $event->channel === 'core.admin.dashboard'
                && $event->eventName === 'dashboard.resources.updated'
                && $event->payload['type'] === 'resources';
        });
    }

    /**
     * 방송 페이로드에 CPU·메모리·디스크 자원 데이터가 담기는지 검증한다.
     *
     * @scenario aspect=resource_payload
     *
     * @effects dashboard_resources_payload_includes_cpu_memory_disk
     *
     * @return void 반환값 없음
     */
    public function test_command_sends_correct_resource_data(): void
    {
        // HookManager::broadcast 는 null/log 드라이버 또는 host 미설정 시 dispatch 스킵
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);

        Event::fake([GenericBroadcastEvent::class]);

        $this->artisan('dashboard:broadcast-resources')
            ->assertExitCode(0);

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) {
            if ($event->payload['type'] !== 'resources') {
                return false;
            }

            $data = $event->payload['data'] ?? null;
            if (! is_array($data)) {
                return false;
            }

            return isset($data['cpu'])
                && isset($data['memory'])
                && isset($data['disk']);
        });
    }
}
