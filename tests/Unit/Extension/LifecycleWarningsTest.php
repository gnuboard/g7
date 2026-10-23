<?php

namespace Tests\Unit\Extension;

use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\TemplateManager;
use App\Http\Controllers\Api\Base\BaseApiController;
use App\Services\TemplateService;
use App\Support\ExtensionStoragePath;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 생명주기 부분 실패 경고 목록 (7.1.3).
 *
 * 여러 파일·항목을 처리하는 작업이 항목별 실패를 로그만 남기고 성공(✅)을 내던 것을, 같은 요청·명령 안에서
 * `LifecycleWarnings` 에 모아 ① 매니저 결과·API 응답 `data.warnings` ② artisan 경고 표 + 종료코드 1
 * ③ 관리자 결과 창으로 돌려준다. 작업 자체의 성공 여부는 바꾸지 않는다.
 *
 * @scenario-manifest tests/scenarios/lifecycle-warnings.yaml
 */
class LifecycleWarningsTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $templateDir = null;

    /**
     * 경고 수집기를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        LifecycleWarnings::current()->clear();
    }

    /**
     * 시험이 만든 템플릿 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->templateDir !== null && File::isDirectory($this->templateDir)) {
            File::deleteDirectory($this->templateDir);
        }
        parent::tearDown();
    }

    /**
     * 수집기는 추가 순서대로 모으고, mark/since 로 작업 하나가 더한 것만 잘라 낸다.
     *
     * @scenario surface=collector
     *
     * @effects warnings_collected_in_order
     *
     * @return void 반환값 없음
     */
    public function test_collector_keeps_order_and_slices_since_mark(): void
    {
        $warnings = LifecycleWarnings::current();
        $warnings->add('layout_register', 'a.json', 'boom');
        $mark = $warnings->mark();
        $warnings->add('seeder', 'X', 'fail');

        $this->assertSame(2, $warnings->count());
        $this->assertSame([['step' => 'seeder', 'target' => 'X', 'message' => 'fail', 'level' => 'warning']], $warnings->since($mark));
        $this->assertSame(LifecycleWarnings::current(), $warnings, '요청 안에서는 같은 인스턴스(scoped)');
    }

    /**
     * 깨진 오버라이드 레이아웃 하나는 나머지 등록을 막지 않고 경고 1건으로 남는다.
     *
     * @scenario surface=manager
     *
     * @effects broken_layout_file_becomes_warning_not_silent
     *
     * @return void 반환값 없음
     */
    public function test_broken_override_layout_becomes_warning(): void
    {
        $name = 'zztest-warn-tpl-'.substr(md5(uniqid()), 0, 6);
        $this->templateDir = base_path('templates/'.$name);
        File::ensureDirectoryExists($this->templateDir.'/layouts/overrides/zz-module/admin');
        File::put($this->templateDir.'/layouts/overrides/zz-module/admin/broken.json', '{ "layout_name": ');

        $manager = app(TemplateManager::class);
        (fn () => $this->registerLayoutOverrides($name, 0))->call($manager);

        $all = LifecycleWarnings::current()->all();
        $this->assertCount(1, $all);
        $this->assertSame('layout_override_register', $all[0]['step']);
        $this->assertSame('zz-module', $all[0]['target']);
    }

    /**
     * 템플릿 활성화 중 모듈 하나의 레이아웃 등록이 실패해도 나머지 모듈은 등록되고, 실패는 경고 1건으로 남는다.
     *
     * @scenario surface=manager
     *
     * @effects broken_layout_file_becomes_warning_not_silent
     *
     * @return void 반환값 없음
     */
    public function test_module_manager_layout_register_failure_becomes_warning(): void
    {
        $this->assertExtensionLayoutFailureBecomesWarning(
            ModuleManager::class, 'getActiveModules', 'registerModuleLayouts', ModuleInterface::class,
            fn (ModuleManager $m) => $m->registerLayoutsForAllActiveModules('admin', 'zz-tpl'),
        );
    }

    /**
     * 템플릿 활성화 중 플러그인 하나의 레이아웃 등록이 실패해도 나머지 플러그인은 등록되고, 실패는 경고 1건으로 남는다.
     *
     * @scenario surface=manager
     *
     * @effects broken_layout_file_becomes_warning_not_silent
     *
     * @return void 반환값 없음
     */
    public function test_plugin_manager_layout_register_failure_becomes_warning(): void
    {
        $this->assertExtensionLayoutFailureBecomesWarning(
            PluginManager::class, 'getActivePlugins', 'registerPluginLayouts', PluginInterface::class,
            fn (PluginManager $m) => $m->registerLayoutsForAllActivePlugins('admin', 'zz-tpl'),
        );
    }

    /**
     * 모듈 제거(데이터 삭제)에서 저장 폴더를 다 지우지 못하면 경고 1건이 남는다.
     *
     * `File::deleteDirectory` 는 파일 권한 등으로 지우지 못해도 예외 없이 true 를 돌려주므로,
     * 예외만 받는 catch 로는 이 실패가 경고가 되지 않는다 — 삭제 뒤 폴더가 남았는지로 판정한다.
     *
     * @scenario surface=manager
     *
     * @effects storage_cleanup_left_behind_becomes_warning
     *
     * @return void 반환값 없음
     */
    public function test_module_uninstall_storage_left_behind_becomes_warning(): void
    {
        $this->assertStorageLeftBehindBecomesWarning(
            ModuleManager::class, ModuleInterface::class, 'deleteModuleStorage', ExtensionStoragePath::module('zz-cleanup-probe'),
        );
    }

    /**
     * 플러그인 제거(데이터 삭제)에서 저장 폴더를 다 지우지 못하면 경고 1건이 남는다.
     *
     * @scenario surface=manager
     *
     * @effects storage_cleanup_left_behind_becomes_warning
     *
     * @return void 반환값 없음
     */
    public function test_plugin_uninstall_storage_left_behind_becomes_warning(): void
    {
        $this->assertStorageLeftBehindBecomesWarning(
            PluginManager::class, PluginInterface::class, 'deletePluginSettingsDirectory', ExtensionStoragePath::plugin('zz-cleanup-probe'),
        );
    }

    /**
     * 저장 폴더 삭제가 「성공(true)을 돌려주고 폴더는 남는」 상황을 만들어 정리 함수를 부르고, 경고 1건을 확인합니다.
     * 정상 삭제(폴더가 사라짐)에서는 경고가 없어야 합니다.
     *
     * @param  class-string  $managerClass  모듈·플러그인 매니저 클래스
     * @param  class-string  $extensionInterface  확장 인터페이스
     * @param  string  $cleanupMethod  저장 폴더 정리 메서드명
     * @param  string  $storageDir  그 확장의 저장 폴더 절대 경로
     * @return void 반환값 없음
     */
    private function assertStorageLeftBehindBecomesWarning(
        string $managerClass,
        string $extensionInterface,
        string $cleanupMethod,
        string $storageDir,
    ): void {
        $extension = Mockery::mock($extensionInterface);
        $extension->shouldReceive('getIdentifier')->andReturn('zz-cleanup-probe');
        $manager = Mockery::mock($managerClass)->makePartial()->shouldAllowMockingProtectedMethods();
        $cleanup = new \ReflectionMethod($managerClass, $cleanupMethod);

        File::ensureDirectoryExists($storageDir.'/settings');
        File::put($storageDir.'/settings/setting.json', '{}');

        try {
            // 정상 삭제 — 폴더가 사라지면 경고 없음
            $cleanup->invoke($manager, $extension);
            $this->assertDirectoryDoesNotExist($storageDir);
            $this->assertSame([], LifecycleWarnings::current()->all(), '정상 삭제는 경고를 남기지 않아야 합니다.');

            // 권한 실패 모사 — deleteDirectory 가 true 를 돌려주지만 폴더는 남는다(프레임워크 실제 동작)
            File::ensureDirectoryExists($storageDir.'/settings');
            File::put($storageDir.'/settings/setting.json', '{}');
            File::partialMock()->shouldReceive('deleteDirectory')->with($storageDir)->andReturn(true);

            $cleanup->invoke($manager, $extension);

            $all = LifecycleWarnings::current()->all();
            $this->assertCount(1, $all, '남은 저장 폴더는 경고 1건이어야 합니다.');
            $this->assertSame('cleanup', $all[0]['step']);
            $this->assertSame('zz-cleanup-probe', $all[0]['target']);
            $this->assertSame(__('extensions.warnings.storage_cleanup_incomplete'), $all[0]['message']);
            $this->assertTrue(LifecycleWarnings::hasFailures($all), '정리 실패는 안내가 아니라 실패 항목이어야 합니다.');
        } finally {
            File::clearResolvedInstance('files');
            File::swap(new Filesystem);
            if (is_dir($storageDir)) {
                (new Filesystem)->deleteDirectory($storageDir);
            }
        }
    }

    /**
     * 활성 확장 둘 중 첫째의 레이아웃 등록을 실패시켜, 둘째는 등록되고 경고는 첫째 1건인지 확인합니다.
     *
     * @param  class-string  $managerClass  모듈·플러그인 매니저 클래스
     * @param  string  $activeMethod  활성 확장 목록 메서드명
     * @param  string  $registerMethod  확장 레이아웃 등록 메서드명
     * @param  class-string  $extensionInterface  확장 인터페이스
     * @param  \Closure  $run  매니저를 받아 일괄 등록을 실행하고 등록 수를 돌려주는 함수
     * @return void 반환값 없음
     */
    private function assertExtensionLayoutFailureBecomesWarning(
        string $managerClass,
        string $activeMethod,
        string $registerMethod,
        string $extensionInterface,
        \Closure $run,
    ): void {
        $broken = Mockery::mock($extensionInterface);
        $broken->shouldReceive('getIdentifier')->andReturn('zz-broken');
        $healthy = Mockery::mock($extensionInterface);
        $healthy->shouldReceive('getIdentifier')->andReturn('zz-healthy');

        $manager = Mockery::mock($managerClass)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive($activeMethod)->andReturn([$broken, $healthy]);
        $manager->shouldReceive($registerMethod)->with('zz-broken', null, 'admin')->andThrow(new \RuntimeException('broken layout'));
        $manager->shouldReceive($registerMethod)->with('zz-healthy', null, 'admin')->andReturn(3);

        $this->assertSame(3, $run($manager), '실패한 확장 뒤의 확장도 등록돼야 합니다.');

        $all = LifecycleWarnings::current()->all();
        $this->assertCount(1, $all);
        $this->assertSame('layout_register', $all[0]['step']);
        $this->assertSame('zz-broken', $all[0]['target']);
        $this->assertSame('broken layout', $all[0]['message']);
    }

    /**
     * 경고가 있으면 API 성공 응답 data 에 warnings 를 싣고, 없으면 응답을 바꾸지 않는다.
     *
     * @scenario surface=api_response
     *
     * @effects api_success_response_carries_warnings
     *
     * @return void 반환값 없음
     */
    public function test_api_success_response_carries_warnings(): void
    {
        $controller = new class extends BaseApiController
        {
            /**
             * 성공 응답을 그대로 노출합니다.
             *
             * @param  mixed  $data  응답 데이터
             * @return JsonResponse 성공 응답
             */
            public function respond(mixed $data): JsonResponse
            {
                return $this->success('common.success', $data);
            }
        };

        $clean = $controller->respond(['identifier' => 'x'])->getData(true);
        $this->assertArrayNotHasKey('warnings', $clean['data']);

        LifecycleWarnings::current()->add('layout_refresh', 'b.json', 'bad');
        $withWarnings = $controller->respond(['identifier' => 'x'])->getData(true);

        $this->assertSame('x', $withWarnings['data']['identifier']);
        $this->assertSame([['step' => 'layout_refresh', 'target' => 'b.json', 'message' => 'bad', 'level' => 'warning']], $withWarnings['data']['warnings']);
    }

    /**
     * 경고와 함께 끝난 명령은 경고 표·표지를 출력하고 종료코드 1 을 낸다. 경고가 없으면 종전 종료코드 그대로다.
     *
     * @scenario surface=cli
     *
     * @effects cli_exit_code_one_with_marker, cli_marker_distinguishes_from_failure
     *
     * @return void 반환값 없음
     */
    public function test_command_with_warnings_exits_one_with_marker(): void
    {
        $command = new class extends Command
        {
            use ReportsLifecycleWarnings;

            protected $signature = 'zztest:lifecycle-warn {--with-warning}';

            /**
             * 옵션이 있으면 경고 하나를 남기고 성공으로 끝납니다.
             *
             * @return int 종료코드
             */
            public function handle(): int
            {
                if ($this->option('with-warning')) {
                    LifecycleWarnings::current()->add('layout_refresh', 'c.json', 'broken');
                }

                return self::SUCCESS;
            }
        };
        $this->app->make(Kernel::class)->registerCommand($command);

        $this->assertSame(0, Artisan::call('zztest:lifecycle-warn'));

        $exit = Artisan::call('zztest:lifecycle-warn', ['--with-warning' => true]);
        $output = Artisan::output();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('c.json', $output);
        $this->assertStringContainsString(LifecycleWarnings::CLI_MARKER, $output);

        $this->assertTrue(LifecycleWarnings::cliCompletedWithWarnings($exit, $output), '표지 있는 1 은 완료(경고 포함)');
        $this->assertFalse(LifecycleWarnings::cliCompletedWithWarnings(1, 'Error: boom'), '표지 없는 1 은 실패');
    }

    /**
     * 본 명령이 실패로 끝나면 실패 경고가 쌓여 있어도 표지를 찍지 않는다 — 설치 마법사가 실패를 「완료(경고 포함)」로 보지 않게.
     *
     * @scenario surface=cli
     *
     * @effects failed_command_with_warnings_has_no_completion_marker
     *
     * @return void 반환값 없음
     */
    public function test_failed_command_with_warnings_has_no_marker(): void
    {
        $command = new class extends Command
        {
            use ReportsLifecycleWarnings;

            protected $signature = 'zztest:lifecycle-warn-fail';

            /**
             * 경고 하나를 남기고 실패로 끝납니다(경고가 쌓인 뒤 설치가 실패해 되돌린 경우).
             *
             * @return int 종료코드
             */
            public function handle(): int
            {
                LifecycleWarnings::current()->add('composer_install', 'sirsoft-x', 'broken');

                return self::FAILURE;
            }
        };
        $this->app->make(Kernel::class)->registerCommand($command);

        $exit = Artisan::call('zztest:lifecycle-warn-fail');
        $output = Artisan::output();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('sirsoft-x', $output, '실패해도 쌓인 경고 표는 보인다');
        $this->assertStringNotContainsString(LifecycleWarnings::CLI_MARKER, $output);
        $this->assertFalse(LifecycleWarnings::cliCompletedWithWarnings($exit, $output), '실패한 명령은 완료(경고 포함)가 아니다');
    }

    /**
     * 확장 상태를 바꾸는 생명주기 명령은 모두 부분 실패 경고를 출력한다(계획 D9) — 설치·활성화만이 아니라 업데이트·제거·비활성화까지.
     *
     * 모집단은 모듈·플러그인·템플릿·언어팩 명령 중 시그니처가 install·activate·deactivate·uninstall·update·refresh-layout·provision
     * 인 것이다(저장소에서 파생 — 명령이 늘면 함께 잡힌다).
     *
     * @scenario surface=cli
     *
     * @effects every_lifecycle_command_reports_warnings
     *
     * @return void 반환값 없음
     */
    public function test_every_extension_lifecycle_command_reports_warnings(): void
    {
        $population = [];
        foreach (['Module', 'Plugin', 'Template', 'LanguagePack'] as $dir) {
            foreach (glob(app_path("Console/Commands/{$dir}/*Command.php")) as $file) {
                $source = (string) file_get_contents($file);
                if (preg_match('/\$signature\s*=\s*[\'"][\w-]+:(install|activate|deactivate|uninstall|update|refresh-layout|provision)\b/', $source)) {
                    $population[] = "App\\Console\\Commands\\{$dir}\\".basename($file, '.php');
                }
            }
        }
        $this->assertGreaterThanOrEqual(24, count($population), '모집단이 비거나 줄면 안 된다 — 4종 × 설치·활성화·비활성화·제거·업데이트·레이아웃 갱신(언어팩은 provision)');
        $this->assertContains('App\\Console\\Commands\\Module\\UpdateModuleCommand', $population);

        $missing = array_values(array_filter(
            $population,
            fn (string $class) => ! in_array(ReportsLifecycleWarnings::class, class_uses_recursive($class), true),
        ));
        $this->assertSame([], $missing, '경고를 출력하지 않는 생명주기 명령');
    }

    /**
     * DB 예외 원문의 접속 정보·SQL 꼬리를 떼고, 안내(notice)는 실패로 세지 않는다.
     *
     * @scenario surface=collector
     *
     * @effects db_connection_details_scrubbed_from_reason, notice_level_not_counted_as_failure
     *
     * @return void 반환값 없음
     */
    public function test_reason_is_scrubbed_and_notice_is_not_a_failure(): void
    {
        $warnings = LifecycleWarnings::current();
        $warnings->clear();

        $warnings->add('layout_refresh', 'a.g7.html', "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1 (Connection: mysql, Host: db.internal, Port: 3306, Database: shop, SQL: insert into `t` values (1))");
        $warnings->notice('extract', 'my.zip', '정규화 8개');

        $items = $warnings->all();
        $this->assertSame("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1", $items[0]['message']);
        $this->assertStringNotContainsString('db.internal', $items[0]['message']);
        $this->assertSame(LifecycleWarnings::LEVEL_WARNING, $items[0]['level']);
        $this->assertSame(LifecycleWarnings::LEVEL_NOTICE, $items[1]['level']);

        $this->assertTrue(LifecycleWarnings::hasFailures($items));
        $this->assertFalse(LifecycleWarnings::hasFailures([$items[1]]));
    }

    /**
     * 안내만 남긴 명령은 표를 보이되 종료코드·표지를 바꾸지 않는다(실패가 아니다).
     *
     * @scenario surface=cli
     *
     * @effects notice_only_command_keeps_exit_code
     *
     * @return void 반환값 없음
     */
    public function test_command_with_only_notices_keeps_exit_code(): void
    {
        $command = new class extends Command
        {
            use ReportsLifecycleWarnings;

            protected $signature = 'zztest:lifecycle-notice';

            /**
             * 안내 하나를 남기고 성공으로 끝납니다.
             *
             * @return int 종료코드
             */
            public function handle(): int
            {
                LifecycleWarnings::current()->notice('extract', 'my.zip', 'normalized');

                return self::SUCCESS;
            }
        };
        $this->app->make(Kernel::class)->registerCommand($command);

        $exit = Artisan::call('zztest:lifecycle-notice');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('my.zip', $output);
        $this->assertStringNotContainsString(LifecycleWarnings::CLI_MARKER, $output);
    }

    /**
     * 코어 업데이트 명령은 실패 경고를 표로 보이되 종료코드는 성공(0)을 유지한다.
     *
     * 모집단: app/Console/Commands/Core 의 명령 중 경고를 쌓거나(LifecycleWarnings) 코어 재동기화를 부르는 것 — 그 전부가
     * 경고 출력 trait 를 쓰고 종료코드를 바꾸지 않아야 한다. 경고가 모이기만 하고 어디에도 출력되지 않던 결함의 잠금.
     *
     * @scenario surface=cli
     *
     * @effects core_update_commands_show_warnings_keep_exit_code
     *
     * @return void 반환값 없음
     */
    public function test_core_update_commands_show_warnings_but_keep_exit_code(): void
    {
        $population = [];
        foreach (glob(app_path('Console/Commands/Core/*Command.php')) as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, 'LifecycleWarnings::current()') || str_contains($source, 'reloadCoreConfigAndResync(')) {
                $population[] = 'App\\Console\\Commands\\Core\\'.basename($file, '.php');
            }
        }
        $this->assertContains('App\\Console\\Commands\\Core\\CoreUpdateCommand', $population, '모집단이 비거나 core:update 를 놓치면 안 된다');

        foreach ($population as $class) {
            $this->assertContains(ReportsLifecycleWarnings::class, class_uses_recursive($class), "{$class} 가 경고를 출력해야 한다");
            $method = new \ReflectionMethod($class, 'lifecycleWarningsChangeExitCode');
            $this->assertFalse($method->invoke((new \ReflectionClass($class))->newInstanceWithoutConstructor()), "{$class} 는 종료코드를 유지해야 한다");
        }

        $command = new class extends Command
        {
            use ReportsLifecycleWarnings;

            protected $signature = 'zztest:lifecycle-keep-exit';

            /**
             * 종료코드를 바꾸지 않습니다.
             *
             * @return bool 늘 false
             */
            protected function lifecycleWarningsChangeExitCode(): bool
            {
                return false;
            }

            /**
             * 실패 경고 하나를 남기고 성공으로 끝납니다.
             *
             * @return int 종료코드
             */
            public function handle(): int
            {
                LifecycleWarnings::current()->add('core_resync', 'permissions', 'boom');

                return self::SUCCESS;
            }
        };
        $this->app->make(Kernel::class)->registerCommand($command);

        $exit = Artisan::call('zztest:lifecycle-keep-exit');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('permissions', $output);
        $this->assertStringContainsString('boom', $output);
        $this->assertStringNotContainsString(LifecycleWarnings::CLI_MARKER, $output);
    }

    /**
     * `template:refresh-layout` 은 갱신 중 실패 경고가 쌓이면 완료 줄을 ✅ 대신 ⚠️ 로 찍고 종료코드 1 을 낸다.
     * 안내만 쌓이면 ✅ 와 종료코드 0 을 유지한다.
     *
     * @scenario surface=cli
     *
     * @effects cli_exit_code_one_with_marker, cli_done_line_marks_warning_instead_of_success
     *
     * @return void 반환값 없음
     */
    public function test_template_refresh_layout_command_marks_warning_and_exits_one(): void
    {
        $level = 'warning';
        $service = Mockery::mock(TemplateService::class);
        $service->shouldReceive('refreshTemplateLayouts')->with('zz-tpl')->andReturnUsing(function () use (&$level) {
            $level === 'warning'
                ? LifecycleWarnings::current()->add('layout_refresh', 'broken.json', 'bad')
                : LifecycleWarnings::current()->notice('extract', 'my.zip', 'normalized');

            return ['layout_warnings' => []];
        });
        $this->app->instance(TemplateService::class, $service);

        $exit = Artisan::call('template:refresh-layout', ['identifier' => 'zz-tpl']);
        $output = Artisan::output();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('broken.json', $output);
        $this->assertStringContainsString(LifecycleWarnings::CLI_MARKER, $output);
        $this->assertStringContainsString('⚠️ '.__('templates.commands.refresh_layout.success', ['template' => 'zz-tpl']), $output);
        $this->assertStringNotContainsString('✅', $output, '실패 경고가 있는데 성공 표시가 찍혔습니다.');

        $level = 'notice';
        LifecycleWarnings::current()->clear();
        $exit = Artisan::call('template:refresh-layout', ['identifier' => 'zz-tpl']);
        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('✅ '.__('templates.commands.refresh_layout.success', ['template' => 'zz-tpl']), $output);
    }
}
