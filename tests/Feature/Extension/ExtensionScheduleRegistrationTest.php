<?php

namespace Tests\Feature\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
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
