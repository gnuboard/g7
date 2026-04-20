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
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_broadcasts_resources_update(): void
    {
        Event::fake([GenericBroadcastEvent::class]);

        $this->artisan('dashboard:broadcast-resources')
            ->expectsOutput('시스템 리소스 정보가 브로드캐스트되었습니다.')
            ->assertExitCode(0);

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) {
            return $event->channel === 'core.admin.dashboard'
                && $event->eventName === 'dashboard.resources.updated'
                && $event->payload['type'] === 'resources';
        });
    }

    public function test_command_sends_correct_resource_data(): void
    {
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
