<?php

namespace Tests\Feature\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Mockery;
use Tests\TestCase;

/**
 * 확장 스케줄 등록 테스트
 *
 * routes/console.php에서 활성 모듈/플러그인의 getSchedules()를
 * 스케줄러에 등록하는 기능을 검증합니다.
 */
class ExtensionScheduleRegistrationTest extends TestCase
{
    /**
     * 활성 모듈의 스케줄이 등록되는지 확인합니다.
     *
     * @scenario case=ext_schedule_registration
     *
     * @effects extension_schedules_registered_from_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_active_module_schedules_are_registered(): void
    {
        $mockModule = Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $mockModule->shouldReceive('getSchedules')->andReturn([
            [
                'command' => 'test-module:daily-task',
                'schedule' => 'daily',
                'description' => '테스트 일일 작업',
            ],
        ]);

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn([$mockModule]);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);

        $schedule = $this->registerExtensionSchedules();
        $events = $schedule->events();

        $found = collect($events)->contains(function ($event) {
            return str_contains($event->command ?? '', 'test-module:daily-task');
        });

        $this->assertTrue($found, 'test-module:daily-task 스케줄이 등록되어야 합니다.');
    }

    /**
     * 활성 플러그인의 스케줄이 등록되는지 확인합니다.
     *
     * @scenario case=ext_schedule_registration
     *
     * @effects extension_schedules_registered_from_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_active_plugin_schedules_are_registered(): void
    {
        $mockPlugin = Mockery::mock(\App\Contracts\Extension\PluginInterface::class);
        $mockPlugin->shouldReceive('getSchedules')->andReturn([
            [
                'command' => 'test-plugin:hourly-task',
                'schedule' => 'hourly',
                'description' => '테스트 시간별 작업',
            ],
        ]);

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn([]);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([$mockPlugin]);

        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);

        $schedule = $this->registerExtensionSchedules();
        $events = $schedule->events();

        $found = collect($events)->contains(function ($event) {
            return str_contains($event->command ?? '', 'test-plugin:hourly-task');
        });

        $this->assertTrue($found, 'test-plugin:hourly-task 스케줄이 등록되어야 합니다.');
    }

    /**
     * cron expression이 올바르게 처리되는지 확인합니다.
     *
     * @scenario case=ext_schedule_registration
     *
     * @effects extension_schedules_registered_from_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_cron_expression_schedule_is_registered(): void
    {
        $mockModule = Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $mockModule->shouldReceive('getSchedules')->andReturn([
            [
                'command' => 'test-module:cron-task',
                'schedule' => '*/5 * * * *',
                'description' => '5분마다 실행',
            ],
        ]);

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn([$mockModule]);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);

        $schedule = $this->registerExtensionSchedules();
        $events = $schedule->events();

        $event = collect($events)->first(function ($event) {
            return str_contains($event->command ?? '', 'test-module:cron-task');
        });

        $this->assertNotNull($event, 'cron 스케줄이 등록되어야 합니다.');
        $this->assertEquals('*/5 * * * *', $event->expression);
    }

    /**
     * 빈 스케줄을 반환하는 모듈은 문제 없이 처리됩니다.
     *
     * @scenario case=ext_schedule_registration
     *
     * @effects extension_schedules_registered_from_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_module_with_empty_schedules(): void
    {
        $mockModule = Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $mockModule->shouldReceive('getSchedules')->andReturn([]);

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn([$mockModule]);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);

        // 예외 없이 실행되어야 함
        $schedule = $this->registerExtensionSchedules();
        $this->assertIsArray($schedule->events());
    }

    /**
     * DB 미준비 시 예외가 전파되지 않습니다.
     *
     * @scenario case=ext_schedule_registration
     *
     * @effects extension_schedules_registered_from_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_database_not_ready_does_not_throw(): void
    {
        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')
            ->andThrow(new \Exception('Database connection refused'));

        $this->app->instance(ModuleManager::class, $moduleManager);

        Log::shouldReceive('debug')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '확장 스케줄 등록 스킵');
            });

        // 예외가 전파되지 않아야 함
        $schedule = $this->registerExtensionSchedules();
        $this->assertNotNull($schedule);
    }

    /**
     * command나 schedule이 비어있으면 스킵됩니다.
     *
     * @scenario case=ext_schedule_registration
     *
     * @effects extension_schedules_registered_from_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_invalid_schedule_config_is_skipped(): void
    {
        $mockModule = Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $mockModule->shouldReceive('getSchedules')->andReturn([
            ['command' => '', 'schedule' => 'daily'],       // command 비어있음
            ['command' => 'valid:task', 'schedule' => ''],  // schedule 비어있음
            ['command' => 'valid:task', 'schedule' => 'daily', 'description' => '유효한 작업'],
        ]);

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn([$mockModule]);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);

        $schedule = $this->registerExtensionSchedules();
        $events = $schedule->events();

        // valid:task만 등록되어야 함
        $validEvents = collect($events)->filter(function ($event) {
            return str_contains($event->command ?? '', 'valid:task');
        });

        $this->assertCount(1, $validEvents);
    }

    /**
     * enabled_config 조건부 활성화가 동작합니다.
     *
     * @scenario case=ext_schedule_registration
     *
     * @effects extension_schedules_registered_from_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_enabled_config_conditional_schedule(): void
    {
        $mockModule = Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $mockModule->shouldReceive('getSchedules')->andReturn([
            [
                'command' => 'test-module:conditional-task',
                'schedule' => 'daily',
                'description' => '조건부 작업',
                'enabled_config' => 'test-module.feature_enabled',
            ],
        ]);

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn([$mockModule]);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);

        $schedule = $this->registerExtensionSchedules();
        $events = $schedule->events();

        $event = collect($events)->first(function ($event) {
            return str_contains($event->command ?? '', 'test-module:conditional-task');
        });

        $this->assertNotNull($event, '조건부 스케줄이 등록되어야 합니다.');
    }

    /**
     * 실제 `routes/console.php` 를 새 스케줄러에 다시 읽혀 확장 스케줄을 등록하고, 지정 커맨드의 이벤트를 돌려줍니다.
     *
     * 등록 블록을 복제하지 않고 원본 파일을 그대로 실행한다 — 기본값(중복 실행 방지·한 서버) 배선이 원본에만 있으므로
     * 복제본으로는 회귀를 잡을 수 없다. 원본은 `.env` 가 있을 때만 확장 스케줄을 등록하므로 없으면 건너뛴다.
     *
     * @param  array<int, array<string, mixed>>  $moduleSchedules  시험 모듈의 getSchedules() 반환값
     * @param  array<int, array<string, mixed>>  $pluginSchedules  시험 플러그인의 getSchedules() 반환값
     * @return Schedule 원본 등록 블록이 채운 스케줄러
     */
    private function registerFromConsoleRoutes(array $moduleSchedules, array $pluginSchedules = []): Schedule
    {
        if (! file_exists(base_path('.env'))) {
            $this->markTestSkipped('routes/console.php 는 .env 가 있을 때만 확장 스케줄을 등록한다');
        }

        $mockModule = Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $mockModule->shouldReceive('getSchedules')->andReturn($moduleSchedules);
        $mockModule->shouldReceive('getIdentifier')->andReturn('i161-module');

        $mockPlugin = Mockery::mock(\App\Contracts\Extension\PluginInterface::class);
        $mockPlugin->shouldReceive('getSchedules')->andReturn($pluginSchedules);
        $mockPlugin->shouldReceive('getIdentifier')->andReturn('i161-plugin');

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn([$mockModule]);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([$mockPlugin]);

        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);

        $schedule = new Schedule($this->app);
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return $schedule;
    }

    /**
     * 스케줄러에서 커맨드 이름으로 이벤트를 찾습니다.
     *
     * @param  Schedule  $schedule  스케줄러
     * @param  string  $command  artisan 커맨드 이름
     * @return Event 등록된 이벤트
     */
    private function eventFor(Schedule $schedule, string $command): Event
    {
        $event = collect($schedule->events())->first(fn (Event $event) => str_contains((string) $event->command, $command));
        $this->assertNotNull($event, "{$command} 스케줄이 등록되어야 합니다.");

        return $event;
    }

    /**
     * 확장 예약 작업은 지정하지 않아도 중복 실행 방지(1440분 뒤 만료)·한 서버에서만 실행이 걸린다(#161 F3) — 길어진 매시
     * 작업이 다음 회차와 겹치거나, 다중 서버에서 같은 작업이 서버 수만큼 돌았다. 모듈·플러그인 모두 같다.
     *
     * @scenario case=ext_schedule_default
     *
     * @effects extension_schedule_defaults_to_overlap_guards
     *
     * @return void 반환값 없음
     */
    public function test_extension_schedule_defaults_to_without_overlapping_and_one_server(): void
    {
        $schedule = $this->registerFromConsoleRoutes(
            [['command' => 'i161-module:hourly', 'schedule' => 'hourly']],
            [['command' => 'i161-plugin:hourly', 'schedule' => 'hourly']],
        );

        foreach (['i161-module:hourly', 'i161-plugin:hourly'] as $command) {
            $event = $this->eventFor($schedule, $command);
            $this->assertTrue($event->withoutOverlapping, "{$command}: 기본으로 중복 실행 방지");
            $this->assertSame(1440, $event->expiresAt, "{$command}: 잠금은 1440분 뒤 만료");
            $this->assertTrue($event->onOneServer, "{$command}: 기본으로 한 서버에서만 실행");
        }
    }

    /**
     * `without_overlapping => false` 면 중복 실행 방지를 걸지 않는다 — 겹쳐 돌아도 되는 짧은 작업의 선택지다. 한 서버 실행은
     * 그대로 남는다.
     *
     * @scenario case=ext_schedule_overlap_opt_out
     *
     * @effects extension_schedule_overlap_guard_opt_out
     *
     * @return void 반환값 없음
     */
    public function test_without_overlapping_false_opts_out(): void
    {
        $schedule = $this->registerFromConsoleRoutes(
            [['command' => 'i161-module:free', 'schedule' => 'everyMinute', 'without_overlapping' => false]],
            [['command' => 'i161-plugin:free', 'schedule' => 'everyMinute', 'without_overlapping' => false]],
        );

        foreach (['i161-module:free', 'i161-plugin:free'] as $command) {
            $event = $this->eventFor($schedule, $command);
            $this->assertFalse($event->withoutOverlapping, "{$command}: 중복 실행 방지를 끈다");
            $this->assertTrue($event->onOneServer, "{$command}: 한 서버 실행은 그대로");
        }
    }

    /**
     * `without_overlapping` 에 분 단위 정수를 주면 그 시간 뒤 잠금이 만료된다 — 비정상 종료가 하루 동안 다음 실행을 막지 않게
     * 작업 길이에 맞춘다.
     *
     * @scenario case=ext_schedule_overlap_minutes
     *
     * @effects extension_schedule_overlap_expiry_minutes
     *
     * @return void 반환값 없음
     */
    public function test_without_overlapping_minutes_sets_expiry(): void
    {
        $schedule = $this->registerFromConsoleRoutes(
            [['command' => 'i161-module:short', 'schedule' => '*/5 * * * *', 'without_overlapping' => 10]],
            [['command' => 'i161-plugin:short', 'schedule' => 'hourly', 'without_overlapping' => 55]],
        );

        $module = $this->eventFor($schedule, 'i161-module:short');
        $this->assertTrue($module->withoutOverlapping);
        $this->assertSame(10, $module->expiresAt);
        $this->assertSame('*/5 * * * *', $module->expression);

        $plugin = $this->eventFor($schedule, 'i161-plugin:short');
        $this->assertTrue($plugin->withoutOverlapping);
        $this->assertSame(55, $plugin->expiresAt);
    }

    /**
     * `on_one_server => false` 면 서버마다 실행한다 — 서버별 로컬 정리 작업의 선택지다. 중복 실행 방지는 그대로 남는다.
     *
     * @scenario case=ext_schedule_one_server_opt_out
     *
     * @effects extension_schedule_one_server_opt_out
     *
     * @return void 반환값 없음
     */
    public function test_on_one_server_false_opts_out(): void
    {
        $schedule = $this->registerFromConsoleRoutes(
            [['command' => 'i161-module:local', 'schedule' => 'daily', 'on_one_server' => false]],
            [['command' => 'i161-plugin:local', 'schedule' => 'daily', 'on_one_server' => false]],
        );

        foreach (['i161-module:local', 'i161-plugin:local'] as $command) {
            $event = $this->eventFor($schedule, $command);
            $this->assertFalse($event->onOneServer, "{$command}: 한 서버 실행을 끈다");
            $this->assertTrue($event->withoutOverlapping, "{$command}: 중복 실행 방지는 그대로");
            $this->assertSame(1440, $event->expiresAt);
        }
    }

    /**
     * 확장 스케줄 등록 로직만 직접 실행합니다.
     *
     * routes/console.php의 확장 스케줄 등록 블록을 격리하여 실행하고,
     * 등록된 스케줄을 포함하는 Schedule 인스턴스를 반환합니다.
     *
     * @return Schedule 스케줄 인스턴스
     */
    private function registerExtensionSchedules(): Schedule
    {
        $schedule = new Schedule($this->app);
        $this->app->instance(Schedule::class, $schedule);

        // routes/console.php의 확장 스케줄 등록 블록과 동일한 로직 실행.
        //
        // 본 테스트는 등록 로직 자체를 검증하므로 production 의 `.env` 부트스트랩 가드는
        // 그대로 옮기지 않는다 (.env 부재 시 등록을 스킵하는 가드는 통합 부팅 안전망일 뿐
        // 단위 검증 대상이 아님).
        try {
            $moduleManager = app(ModuleManager::class);
            foreach ($moduleManager->getActiveModules() as $module) {
                foreach ($module->getSchedules() as $config) {
                    if (empty($config['command']) || empty($config['schedule'])) {
                        continue;
                    }

                    $cmd = $schedule->command($config['command']);

                    str_contains($config['schedule'], ' ')
                        ? $cmd->cron($config['schedule'])
                        : $cmd->{$config['schedule']}();

                    if (isset($config['description'])) {
                        $cmd->description($config['description']);
                    }

                    if (isset($config['enabled_config'])) {
                        $cmd->when(fn () => config($config['enabled_config'], true));
                    }
                }
            }

            $pluginManager = app(PluginManager::class);
            foreach ($pluginManager->getActivePlugins() as $plugin) {
                foreach ($plugin->getSchedules() as $config) {
                    if (empty($config['command']) || empty($config['schedule'])) {
                        continue;
                    }

                    $cmd = $schedule->command($config['command']);

                    str_contains($config['schedule'], ' ')
                        ? $cmd->cron($config['schedule'])
                        : $cmd->{$config['schedule']}();

                    if (isset($config['description'])) {
                        $cmd->description($config['description']);
                    }

                    if (isset($config['enabled_config'])) {
                        $cmd->when(fn () => config($config['enabled_config'], true));
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('확장 스케줄 등록 스킵', ['error' => $e->getMessage()]);
        }

        return $schedule;
    }
}
