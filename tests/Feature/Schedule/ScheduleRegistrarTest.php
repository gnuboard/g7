<?php

namespace Tests\Feature\Schedule;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleTriggerType;
use App\Models\Schedule as ScheduleModel;
use App\Services\Schedule\ScheduleDispatcher;
use App\Services\Schedule\ScheduleRegistrar;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * DB 스케줄 등록기 — 행마다 `schedule:{id}` 이벤트, 주기·시간대·다중 서버·점검 모드·실행 조건·등록 순서
 *
 * 테스트 DB 의 다른 활성 행과 섞이지 않도록 이 테스트가 만든 행의 이벤트만 골라 단언한다.
 */
class ScheduleRegistrarTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 행마다 고유한 `schedule:{id}` 이름으로 등록된다 — 같은 이벤트명이 잠금을 공유하던 결함(a) 회귀.
     *
     * @scenario frequency=cron
     *
     * @effects event_name_is_unique_per_row
     *
     * @return void 반환값 없음
     */
    public function test_each_row_gets_unique_event_name(): void
    {
        $a = $this->row(['expression' => '0 4 * * *', 'on_one_server' => true]);
        $b = $this->row(['expression' => '0 4 * * *', 'on_one_server' => true]);

        $events = $this->register();

        $this->assertArrayHasKey("schedule:{$a->id}", $events);
        $this->assertArrayHasKey("schedule:{$b->id}", $events);
        $this->assertNotSame(
            $events["schedule:{$a->id}"]->mutexName(),
            $events["schedule:{$b->id}"]->mutexName(),
            '같은 분에 도는 두 행이 같은 잠금을 공유한다'
        );
    }

    /**
     * 30초 주기 행은 everyThirtySeconds, 그 외는 행 expression 과 시간대로 등록된다.
     *
     * @scenario frequency=every_thirty_seconds, frequency=cron
     *
     * @effects thirty_second_rows_repeat_every_thirty_seconds, cron_rows_use_expression_and_timezone
     *
     * @return void 반환값 없음
     */
    public function test_frequency_expression_and_timezone(): void
    {
        $thirty = $this->row(['frequency' => 'everyThirtySeconds', 'expression' => '* * * * *']);
        $cron = $this->row(['expression' => '30 11 * * *', 'timezone' => 'Asia/Seoul']);
        $plain = $this->row(['expression' => '15 0 * * *', 'timezone' => null]);

        $events = $this->register(new Schedule('UTC'));

        $this->assertTrue($events["schedule:{$thirty->id}"]->isRepeatable());
        $this->assertSame(30, $events["schedule:{$thirty->id}"]->repeatSeconds);
        $this->assertFalse($events["schedule:{$cron->id}"]->isRepeatable());
        $this->assertSame('30 11 * * *', $events["schedule:{$cron->id}"]->expression);
        $this->assertSame('Asia/Seoul', (string) $events["schedule:{$cron->id}"]->timezone);
        $this->assertSame('UTC', (string) $events["schedule:{$plain->id}"]->timezone, '행 시간대가 없으면 스케줄러 기준을 따라야 한다');
    }

    /**
     * 다중 서버 1대·점검 모드 실행 옵션이 행 값대로 걸린다.
     *
     * @scenario frequency=cron
     *
     * @effects one_server_and_maintenance_follow_row
     *
     * @return void 반환값 없음
     */
    public function test_one_server_and_maintenance_follow_row(): void
    {
        $on = $this->row(['on_one_server' => true, 'run_in_maintenance' => true]);
        $off = $this->row(['on_one_server' => false, 'run_in_maintenance' => false]);

        $events = $this->register();

        $this->assertTrue($events["schedule:{$on->id}"]->onOneServer);
        $this->assertTrue($events["schedule:{$on->id}"]->runsInMaintenanceMode());
        $this->assertFalse($events["schedule:{$off->id}"]->onOneServer);
        $this->assertFalse($events["schedule:{$off->id}"]->runsInMaintenanceMode());
    }

    /**
     * 실행 조건은 등록 시점이 아니라 실행 시점에 평가된다 — 사이트맵·GeoIP 설정 on/off 가 실행 여부를 바꾼다(결함 d 회귀).
     *
     * @scenario condition=met, condition=unmet
     *
     * @effects conditions_are_evaluated_at_run_time
     *
     * @return void 반환값 없음
     */
    public function test_conditions_toggle_with_settings(): void
    {
        $sitemap = $this->row(['enabled_condition' => [
            ['source' => 'core_setting', 'key' => 'seo.sitemap_enabled', 'operator' => 'truthy', 'default' => true],
        ]]);
        $geoip = $this->row(['enabled_condition' => [
            ['source' => 'core_setting', 'key' => 'geoip.feature_enabled', 'operator' => 'truthy', 'default' => false],
            ['source' => 'core_setting', 'key' => 'geoip.auto_update_enabled', 'operator' => 'truthy', 'default' => true],
            ['source' => 'core_setting', 'key' => 'geoip.license_key', 'operator' => 'not_empty', 'default' => ''],
        ]]);

        $events = $this->register();

        config(['g7_settings.core.seo.sitemap_enabled' => true]);
        config(['g7_settings.core.geoip' => ['feature_enabled' => true, 'auto_update_enabled' => true, 'license_key' => 'k']]);
        $this->assertTrue($events["schedule:{$sitemap->id}"]->filtersPass($this->app));
        $this->assertTrue($events["schedule:{$geoip->id}"]->filtersPass($this->app));

        config(['g7_settings.core.seo.sitemap_enabled' => false]);
        config(['g7_settings.core.geoip' => ['feature_enabled' => true, 'auto_update_enabled' => true, 'license_key' => '']]);
        $this->assertFalse($events["schedule:{$sitemap->id}"]->filtersPass($this->app));
        $this->assertFalse($events["schedule:{$geoip->id}"]->filtersPass($this->app));
    }

    /**
     * 비활성 행과 소유 확장이 비활성인 행은 등록하지 않는다. 코어·직접 등록 행은 등록한다.
     *
     * @scenario owner=core, owner=custom, owner=module_inactive
     *
     * @effects inactive_owner_is_blocked, inactive_rows_are_not_registered
     *
     * @return void 반환값 없음
     */
    public function test_inactive_rows_and_inactive_owners_are_skipped(): void
    {
        $core = $this->row([], ExtensionOwnerType::Core, 'core');
        $custom = $this->row();
        $inactiveRow = $this->row(['is_active' => false]);
        $inactiveOwner = $this->row([], ExtensionOwnerType::Module, 'vendor-not-installed-'.uniqid());

        $events = $this->register();

        $this->assertArrayHasKey("schedule:{$core->id}", $events);
        $this->assertArrayHasKey("schedule:{$custom->id}", $events);
        $this->assertArrayNotHasKey("schedule:{$inactiveRow->id}", $events);
        $this->assertArrayNotHasKey("schedule:{$inactiveOwner->id}", $events);
    }

    /**
     * 등록 순서는 30초 주기 → 바로 실행 → 큐로 보내기.
     *
     * @scenario frequency=every_thirty_seconds, dispatch_mode=sync, dispatch_mode=queue
     *
     * @effects registration_order_light_first
     *
     * @return void 반환값 없음
     */
    public function test_registration_order_is_thirty_seconds_then_sync_then_queue(): void
    {
        $queue = $this->row(['dispatch_mode' => 'queue']);
        $sync = $this->row(['dispatch_mode' => 'sync']);
        $thirty = $this->row(['frequency' => 'everyThirtySeconds', 'expression' => '* * * * *']);

        $order = array_keys($this->register());
        $mine = array_values(array_intersect($order, ["schedule:{$queue->id}", "schedule:{$sync->id}", "schedule:{$thirty->id}"]));

        $this->assertSame(["schedule:{$thirty->id}", "schedule:{$sync->id}", "schedule:{$queue->id}"], $mine);
    }

    /**
     * 생존 신호 이벤트가 매분 등록되고, 등록기는 `schedule:` 이벤트만 관리 대상으로 본다(#609 계약 ⓐⓑ).
     *
     * @scenario trigger=scheduled
     *
     * @effects heartbeat_event_registered_every_minute, managed_events_are_identifiable
     *
     * @return void 반환값 없음
     */
    public function test_heartbeat_and_is_managed(): void
    {
        $schedule = new Schedule;
        app(ScheduleRegistrar::class)->register($schedule);
        $schedule->command('inspire')->daily();

        $heartbeat = collect($schedule->events())->first(fn (Event $e) => $e->description === ScheduleRegistrar::HEARTBEAT_EVENT);
        $other = collect($schedule->events())->first(fn (Event $e) => ! $e instanceof CallbackEvent);

        $this->assertNotNull($heartbeat);
        $this->assertSame('* * * * *', $heartbeat->expression);
        $this->assertTrue(app(ScheduleRegistrar::class)->isManaged($heartbeat));
        $this->assertFalse(app(ScheduleRegistrar::class)->isManaged($other));
    }

    /**
     * 이벤트를 실행하면 디스패처를 예약 계기로 부른다.
     *
     * @scenario trigger=scheduled
     *
     * @effects tick_dispatches_with_scheduled_trigger
     *
     * @return void 반환값 없음
     */
    public function test_event_calls_dispatcher_with_scheduled_trigger(): void
    {
        $row = $this->row();
        $dispatcher = Mockery::mock(ScheduleDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->with($row->id, ScheduleTriggerType::Scheduled)->andReturnNull();
        $this->app->instance(ScheduleDispatcher::class, $dispatcher);

        $events = $this->register();
        $events["schedule:{$row->id}"]->run($this->app);

        $this->addToAssertionCount(1);
    }

    /**
     * 활성 스케줄 행을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @param  ExtensionOwnerType|null  $ownerType  소유 타입
     * @param  string|null  $ownerIdentifier  소유 식별자
     * @return ScheduleModel 저장된 행
     */
    private function row(array $attributes = [], ?ExtensionOwnerType $ownerType = null, ?string $ownerIdentifier = null): ScheduleModel
    {
        $factory = ScheduleModel::factory();

        if ($ownerType !== null) {
            $factory = $factory->ownedBy($ownerType, (string) $ownerIdentifier);
        }

        return $factory->create($attributes);
    }

    /**
     * 새 스케줄러에 등록하고 이벤트를 이름으로 모읍니다.
     *
     * @param  Schedule|null  $schedule  스케줄러 (null 이면 새로 만든다)
     * @return array<string, Event> 이름 → 이벤트 (등록 순서 유지)
     */
    private function register(?Schedule $schedule = null): array
    {
        $schedule ??= new Schedule;
        app(ScheduleRegistrar::class)->register($schedule);

        $events = [];
        foreach ($schedule->events() as $event) {
            $events[(string) $event->description] = $event;
        }

        return $events;
    }
}
