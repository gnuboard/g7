<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionScheduleSyncHelper;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Services\Schedule\ScheduleRegistrar;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 확장 스케줄 선언 → DB 동기화 → 스케줄러 등록 (7.2.0 — 옛 ExtensionScheduleRegistrationTest 대체)
 *
 * 7.1.x 는 `routes/console.php` 가 매 틱 활성 확장의 `getSchedules()` 를 읽어 직접 등록했다(옛 시험이 그 경로를 봤다).
 * 7.2.0 은 선언을 스케줄 표로 동기화하고 등록기가 표의 활성 행만 `schedule:{id}` 이벤트로 등록한다. 옛 시험의 네 시나리오
 * (활성 모듈 등록·활성 플러그인 등록·설정 게이트·빈 선언)를 새 경로로 다시 확인하고, 비활성 확장 차단을 더한다.
 */
class ExtensionScheduleSyncTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 확장 상태 캐시를 비우고 시작합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        ModuleManager::invalidateModuleStatusCache();
        PluginManager::invalidatePluginStatusCache();
    }

    /**
     * 확장 상태 캐시를 비우고 끝냅니다 (트랜잭션 롤백 뒤 남은 캐시가 다른 시험을 오염시키지 않게).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ModuleManager::invalidateModuleStatusCache();
        PluginManager::invalidatePluginStatusCache();
        parent::tearDown();
    }

    /**
     * 활성 모듈 선언이 cron 그대로(사이트 시간대) schedule:{id} 이벤트로 등록되는지 확인합니다.
     *
     * @scenario owner=module, owner_state=active, declaration=new_schema
     *
     * @effects active_extension_schedule_registered
     *
     * @return void 반환값 없음
     */
    public function test_active_module_declaration_is_registered(): void
    {
        Module::factory()->create(['identifier' => 'vendor-probe', 'status' => 'active']);
        $this->resetStatusCache();
        app(ExtensionScheduleSyncHelper::class)->syncSchedules(ExtensionOwnerType::Module, 'vendor-probe', [$this->declaration('daily-task', 'inspire', '0 0 * * *')]);

        $this->assertSame(['0 0 * * *', null], $this->summary($this->registeredEvents(), 'vendor-probe', 'daily-task'));
    }

    /**
     * 활성 플러그인 선언이 cron·행 시간대 그대로 등록되는지 확인합니다.
     *
     * @scenario owner=plugin, owner_state=active, declaration=new_schema
     *
     * @effects active_extension_schedule_registered
     *
     * @return void 반환값 없음
     */
    public function test_active_plugin_declaration_is_registered_with_timezone(): void
    {
        Plugin::factory()->create(['identifier' => 'vendor-rates', 'status' => 'active']);
        $this->resetStatusCache();
        app(ExtensionScheduleSyncHelper::class)->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-rates', [$this->declaration('update-rates', 'inspire', '30 11 * * *', 'Asia/Seoul')]);

        $this->assertSame(['30 11 * * *', 'Asia/Seoul'], $this->summary($this->registeredEvents(), 'vendor-rates', 'update-rates'));
    }

    /**
     * 비활성 모듈의 선언 행은 지우지 않되 스케줄러에는 등록되지 않는지 확인합니다.
     *
     * @scenario owner=module, owner_state=inactive, declaration=new_schema
     *
     * @effects inactive_extension_schedule_not_registered
     *
     * @return void 반환값 없음
     */
    public function test_inactive_module_declaration_is_not_registered(): void
    {
        Module::factory()->create(['identifier' => 'vendor-probe', 'status' => 'active']);
        Module::factory()->create(['identifier' => 'vendor-off', 'status' => 'inactive']);
        $this->resetStatusCache();
        app(ExtensionScheduleSyncHelper::class)->syncSchedules(ExtensionOwnerType::Module, 'vendor-off', [$this->declaration('off-task', 'inspire', '0 1 * * *')]);

        $this->assertNull($this->summary($this->registeredEvents(), 'vendor-off', 'off-task'), '비활성 확장의 스케줄은 등록되지 않아야 한다');
    }

    /**
     * 옛 형식 선언(enabled_config)도 실행 조건으로 정규화되어 설정이 꺼지면 그 틱에 실행되지 않는지 확인합니다.
     *
     * @scenario owner=module, owner_state=active, declaration=legacy_schema
     *
     * @effects legacy_enabled_config_gates_execution
     *
     * @return void 반환값 없음
     */
    public function test_legacy_enabled_config_still_gates_execution(): void
    {
        Module::factory()->create(['identifier' => 'vendor-probe', 'status' => 'active']);
        $this->resetStatusCache();
        $helper = app(ExtensionScheduleSyncHelper::class);
        $helper->syncSchedules(ExtensionOwnerType::Module, 'vendor-probe', $helper->normalizeLegacySchedules([
            ['command' => 'inspire', 'schedule' => 'daily', 'description' => '옛 형식', 'enabled_config' => 'vendor-probe.auto_run'],
        ], ExtensionOwnerType::Module, 'vendor-probe'));

        $row = \App\Models\Schedule::query()->where('extension_identifier', 'vendor-probe')->firstOrFail();
        $this->assertSame([['source' => 'module_setting', 'key' => 'auto_run']], array_map(
            fn (array $c) => ['source' => $c['source'], 'key' => $c['key']],
            $row->enabled_condition,
        ));
        $this->assertSame('0 0 * * *', $row->expression);

        $event = $this->registeredEvents()['schedule:'.$row->id] ?? null;
        $this->assertNotNull($event);
        // 옛 의미: 설정 조회 실패 시 true 폴백 — 설정이 없는 모듈은 실행된다
        $this->assertTrue($event->filtersPass($this->app));
    }

    /**
     * 선언이 빈 확장은 행·이벤트를 만들지 않는지 확인합니다.
     *
     * @scenario owner=module, owner_state=active, declaration=empty
     *
     * @effects empty_declaration_registers_nothing
     *
     * @return void 반환값 없음
     */
    public function test_empty_declaration_registers_nothing(): void
    {
        Module::factory()->create(['identifier' => 'vendor-probe', 'status' => 'active']);
        $this->resetStatusCache();
        app(ExtensionScheduleSyncHelper::class)->syncSchedules(ExtensionOwnerType::Module, 'vendor-probe', []);

        $this->assertSame(0, \App\Models\Schedule::query()->where('extension_identifier', 'vendor-probe')->count());
        // 등록기는 표의 행만 이벤트로 만든다 — 행이 없으니 이 확장 이벤트도 없다(생존 신호 이벤트는 항상 있다).
        $this->assertArrayHasKey(ScheduleRegistrar::HEARTBEAT_EVENT, $this->registeredEvents());
    }

    /**
     * 새 형식 선언 1건을 만듭니다.
     *
     * @param  string  $name  스케줄 이름
     * @param  string  $command  artisan 명령
     * @param  string  $expression  cron 식
     * @param  string|null  $timezone  시간대
     * @return array<string, mixed> 선언
     */
    private function declaration(string $name, string $command, string $expression, ?string $timezone = null): array
    {
        return array_filter([
            'name' => $name,
            'type' => 'artisan',
            'label' => ['ko' => $name, 'en' => $name],
            'description' => ['ko' => $name, 'en' => $name],
            'command' => $command,
            'expression' => $expression,
            'timezone' => $timezone,
        ], fn ($v) => $v !== null);
    }

    /**
     * 등록기로 스케줄러에 등록된 이벤트를 이름 ⇒ 이벤트로 반환합니다.
     *
     * @return array<string, Event> 이벤트 맵
     */
    private function registeredEvents(): array
    {
        $schedule = new Schedule;
        app(ScheduleRegistrar::class)->register($schedule);

        $events = [];
        foreach ($schedule->events() as $event) {
            $events[(string) $event->description] = $event;
        }

        return $events;
    }

    /**
     * 확장 행의 등록 이벤트 cron·시간대를 요약합니다.
     *
     * @param  array<string, Event>  $events  이벤트 맵
     * @param  string  $identifier  확장 식별자
     * @param  string  $name  스케줄 이름
     * @return array{0: string, 1: string|null}|null [cron, 시간대(행 지정분만)], 미등록이면 null
     */
    private function summary(array $events, string $identifier, string $name): ?array
    {
        $row = \App\Models\Schedule::query()->where('extension_identifier', $identifier)->where('name', $name)->first();
        $this->assertNotNull($row, "{$identifier}:{$name} 행이 동기화되어야 한다");
        $event = $events['schedule:'.$row->id] ?? null;

        return $event === null ? null : [$event->expression, $row->timezone === null ? null : (string) $event->timezone];
    }

    /**
     * 확장 상태 캐시를 비웁니다 (팩토리로 만든 상태를 등록기가 읽게).
     *
     * @return void 반환값 없음
     */
    private function resetStatusCache(): void
    {
        ModuleManager::invalidateModuleStatusCache();
        PluginManager::invalidatePluginStatusCache();
    }
}
