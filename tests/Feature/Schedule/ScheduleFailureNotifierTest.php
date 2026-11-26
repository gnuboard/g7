<?php

namespace Tests\Feature\Schedule;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Extension\HookManager;
use App\Listeners\ScheduleNotificationDataListener;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Services\Schedule\ScheduleFailureNotifier;
use App\Support\ScreenUrl;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 예약 작업 실패 알림 — 보낼지 판정(알림 꺼짐·수동 실행·하루 1회·캐시 장애)과 본문 치환 값
 */
class ScheduleFailureNotifierTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 테스트 정리
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 같은 스케줄의 예약 실행 실패는 사이트 시간대 기준 하루 1회만 알린다. 다음 날은 다시 알린다.
     *
     * @scenario trigger=scheduled, outcome=failure
     *
     * @effects failure_notification_throttled_once_per_day
     *
     * @return void 반환값 없음
     */
    public function test_scheduled_failure_is_notified_once_per_site_day(): void
    {
        config(['app.schedule_timezone' => 'Asia/Seoul']);
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'UTC')); // 19:00 KST
        $schedule = Schedule::factory()->create(['notify_on_failure' => true]);
        $history = $this->failedHistory($schedule, ScheduleTriggerType::Scheduled);
        $notifier = app(ScheduleFailureNotifier::class);

        $this->assertTrue($notifier->shouldNotify($schedule, $history));
        $this->assertFalse($notifier->shouldNotify($schedule, $history), '같은 날 두 번째 실패가 또 알려졌다');

        Carbon::setTestNow(Carbon::parse('2026-10-16 15:30:00', 'UTC')); // 다음 날 00:30 KST
        $this->assertTrue($notifier->shouldNotify($schedule, $history), '사이트 기준 다음 날인데 알리지 않았다');
    }

    /**
     * 「실패 시 알림」이 꺼진 스케줄과 수동 실행 실패는 알리지 않는다.
     *
     * @scenario trigger=manual, outcome=failure
     *
     * @effects failure_notification_respects_flag_and_skips_manual_runs
     *
     * @return void 반환값 없음
     */
    public function test_disabled_flag_and_manual_run_are_not_notified(): void
    {
        $off = Schedule::factory()->create(['notify_on_failure' => false]);
        $on = Schedule::factory()->create(['notify_on_failure' => true]);
        $notifier = app(ScheduleFailureNotifier::class);

        $this->assertFalse($notifier->shouldNotify($off, $this->failedHistory($off, ScheduleTriggerType::Scheduled)));
        $this->assertFalse($notifier->shouldNotify($on, $this->failedHistory($on, ScheduleTriggerType::Manual)));
        $this->assertTrue($notifier->shouldNotify($on, $this->failedHistory($on, ScheduleTriggerType::Scheduled)));
    }

    /**
     * 캐시 장애면 스로틀을 확인할 수 없어도 알린다(놓치는 쪽이 더 해롭다).
     *
     * @scenario outcome=failure
     *
     * @effects failure_notification_sent_when_cache_down
     *
     * @return void 반환값 없음
     */
    public function test_cache_failure_still_notifies(): void
    {
        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('has')->andThrow(new RuntimeException('cache down'));
        $schedule = Schedule::factory()->create(['notify_on_failure' => true]);

        $this->assertTrue((new ScheduleFailureNotifier($cache))->claim($schedule));
    }

    /**
     * 알림 데이터 리스너는 정의 변수를 채우고, 보내지 않을 실패는 context.skip 으로 멈춘다.
     *
     * @scenario trigger=scheduled, trigger=manual, outcome=failure
     *
     * @effects failure_notification_variables_filled, failure_notification_respects_flag_and_skips_manual_runs
     *
     * @return void 반환값 없음
     */
    public function test_data_listener_fills_variables_or_skips(): void
    {
        $schedule = Schedule::factory()->create([
            'name' => 'nightly-sync-'.uniqid(),
            'label' => ['ko' => '야간 동기화', 'en' => 'Nightly sync'],
            'notify_on_failure' => true,
        ]);
        $listener = app(ScheduleNotificationDataListener::class);
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];

        $this->assertSame($default, $listener->extractData($default, 'analytics_archive_failed', []));

        $manual = $listener->extractData($default, 'schedule_failed', [$schedule, $this->failedHistory($schedule, ScheduleTriggerType::Manual), 'boom']);
        $this->assertTrue($manual['context']['skip'] ?? false);

        app()->setLocale('ko');
        $scheduled = $listener->extractData($default, 'schedule_failed', [$schedule, $this->failedHistory($schedule, ScheduleTriggerType::Scheduled), 'boom']);
        $data = $scheduled['data'];

        $this->assertEmpty($scheduled['context']['skip'] ?? null);
        $this->assertSame('야간 동기화', $data['schedule_label']);
        $this->assertSame($schedule->name, $data['schedule_name']);
        $this->assertSame('boom', $data['error']);
        $this->assertNotSame('', $data['owner']);
        $this->assertNotSame('', $data['failed_at']);
        $this->assertStringContainsString('/admin/schedules', $data['schedule_url']);
        foreach (['app_name', 'site_url', 'name'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }

    /**
     * 실패 알림의 스케줄 관리 링크는 화면 키 `admin.schedules` 로 주소 필터를 거치고, 베이스는 사이트 주소다
     * (화면 주소 계약 — 리베이스 접합부).
     *
     * @scenario trigger=scheduled, outcome=failure
     *
     * @effects failure_notification_schedule_url_uses_screen_url
     *
     * @return void 반환값 없음
     */
    public function test_schedule_url_goes_through_screen_url_filter(): void
    {
        config([
            'app.url' => 'https://site.example.test',
            'g7_settings.core.general.user_screen_url' => 'https://front.example.test',
        ]);
        $listener = app(ScheduleNotificationDataListener::class);
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
        // 같은 작업은 하루 한 번만 알리므로 호출마다 새 스케줄을 쓴다.
        $urlOf = function (Schedule $schedule) use ($listener, $default): string {
            $result = $listener->extractData($default, 'schedule_failed', [$schedule, $this->failedHistory($schedule, ScheduleTriggerType::Scheduled), 'boom']);

            return $result['data']['schedule_url'];
        };
        $newSchedule = fn () => Schedule::factory()->create(['name' => 'nightly sync/'.uniqid(), 'notify_on_failure' => true]);

        try {
            $plain = $newSchedule();
            $this->assertSame('https://site.example.test/admin/schedules?search_field=identifier&search_keyword='.rawurlencode($plain->name), $urlOf($plain));

            $seen = [];
            HookManager::addFilter(ScreenUrl::FILTER, function (string $path, string $key, array $params, string $scope) use (&$seen) {
                $seen = [$key, $params['name'] ?? null, $scope];

                return $key === 'admin.schedules' ? '/ops/jobs' : $path;
            });
            $filtered = $newSchedule();
            $this->assertSame('https://site.example.test/ops/jobs', $urlOf($filtered));
            $this->assertSame(['admin.schedules', $filtered->name, ScreenUrl::SCOPE_ADMIN], $seen);
        } finally {
            HookManager::clearFilter(ScreenUrl::FILTER);
        }
    }

    /**
     * 실패 이력을 만듭니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @param  ScheduleTriggerType  $trigger  실행 계기
     * @return ScheduleHistory 저장된 실패 이력
     */
    private function failedHistory(Schedule $schedule, ScheduleTriggerType $trigger): ScheduleHistory
    {
        return ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now(),
            'ended_at' => now(),
            'status' => ScheduleResultStatus::Failed,
            'trigger_type' => $trigger,
            'error_output' => 'boom',
        ]);
    }
}
