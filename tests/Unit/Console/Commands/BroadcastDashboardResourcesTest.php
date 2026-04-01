<?php

namespace Tests\Unit\Console\Commands;

use App\Events\Dashboard\DashboardUpdated;
use App\Services\DashboardService;
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
        Event::fake([DashboardUpdated::class]);

        // 커맨드 실행
        $this->artisan('dashboard:broadcast-resources')
            ->expectsOutput('시스템 리소스 정보가 브로드캐스트되었습니다.')
            ->assertExitCode(0);

        // 이벤트 디스패치 확인
        Event::assertDispatched(DashboardUpdated::class, function ($event) {
            return $event->type === 'resources';
        });
    }

    public function test_command_sends_correct_resource_data(): void
    {
        Event::fake([DashboardUpdated::class]);

        // 커맨드 실행
        $this->artisan('dashboard:broadcast-resources')
            ->assertExitCode(0);

        // 이벤트에 올바른 데이터가 포함되어 있는지 확인
        Event::assertDispatched(DashboardUpdated::class, function ($event) {
            // resources 타입인지 확인
            if ($event->type !== 'resources') {
                return false;
            }

            // data가 배열인지 확인
            if (! is_array($event->data)) {
                return false;
            }

            // 필수 필드가 있는지 확인
            return isset($event->data['cpu']) &&
                   isset($event->data['memory']) &&
                   isset($event->data['disk']);
        });
    }
}
