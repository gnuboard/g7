<?php

namespace Tests\Feature\RuntimeRole;

use App\Console\RuntimeRoleCommandGuard;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Jobs\GenerateSitemapJob;
use App\Models\Module;
use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Scheduling\WebCronPolicy;
use App\Seo\BotDetector;
use App\Seo\SitemapManager;
use App\Services\CoreUpdateService;
use App\Services\FrontendConfigService;
use App\Services\Schedule\ScheduleRegistrar;
use App\Support\RuntimeRole;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Concerns\GrantsExtensionAdminToken;
use Tests\TestCase;

/**
 * 실행 역할 `G7_RUNTIME_ROLE=stage` 와 서버별 스케줄 스위치 `G7_SCHEDULER` (#888).
 *
 * 같은 DB·캐시를 쓰는 검수 서버가 스케줄·큐 워커·사이트맵·SEO 캐시·방문 분석을 돌리고 관리자 화면에서 확장·코어 수명주기를 바꿀 수
 * 있었다. 판정은 `RuntimeRole` 하나(스케줄은 `schedulerEnabled()`)이고, 각 진입점은 live 에서 종전과 같다(회귀 축).
 *
 * @scenario-manifest tests/scenarios/runtime-role-stage.yaml
 */
class RuntimeRoleStageTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsExtensionAdminToken;

    /**
     * 시험용 종료 가로채기를 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        RuntimeRoleCommandGuard::$exitUsing = null;

        parent::tearDown();
    }

    /**
     * 역할을 정합니다.
     *
     * @param  string  $role  실행 역할 (live|stage)
     * @param  bool  $scheduler  서버별 스케줄 스위치 값 (G7_SCHEDULER)
     * @return void 반환값 없음
     */
    private function role(string $role, bool $scheduler = true): void
    {
        config(['core.runtime_role' => $role, 'core.scheduler.enabled' => $scheduler]);
    }

    /**
     * 판정 — 알 수 없는 역할은 예외, 스케줄은 stage 거나 스위치가 꺼지면 꺼진다.
     *
     * @scenario case=role_resolution
     *
     * @effects runtime_role_single_judgment
     *
     * @return void 반환값 없음
     */
    public function test_role_resolution_and_scheduler_switch(): void
    {
        $this->role('live');
        $this->assertTrue(RuntimeRole::schedulerEnabled());
        $this->role('live', false);
        $this->assertFalse(RuntimeRole::schedulerEnabled());
        $this->role('stage', true);
        $this->assertFalse(RuntimeRole::schedulerEnabled(), 'stage 는 스위치와 무관하게 끈다');

        config(['core.runtime_role' => 'preview']);
        $this->expectException(\InvalidArgumentException::class);
        RuntimeRole::current();
    }

    /**
     * ① 스케줄 등록 — stage 면 0건, live 는 종전대로(하트비트 포함).
     *
     * @scenario case=schedule_registration
     *
     * @effects stage_registers_no_schedules
     *
     * @return void 반환값 없음
     */
    public function test_schedule_registrar_registers_nothing_on_stage(): void
    {
        $this->role('stage');
        $stage = new Schedule;
        app(ScheduleRegistrar::class)->register($stage);
        $this->assertCount(0, $stage->events());

        $this->role('live');
        $live = new Schedule;
        app(ScheduleRegistrar::class)->register($live);
        $this->assertNotEmpty($live->events());
    }

    /**
     * ①② 명령 가드 — 스케줄 명령은 안내 후 0, stage 의 워커·재시작 신호는 1, live 는 통과.
     *
     * @scenario case=command_guard
     *
     * @effects stage_blocks_workers_and_schedules
     *
     * @return void 반환값 없음
     */
    public function test_command_guard_verdicts(): void
    {
        $this->role('stage');
        $this->assertSame(0, RuntimeRoleCommandGuard::verdict('schedule:run')['exit']);
        $this->assertSame(1, RuntimeRoleCommandGuard::verdict('queue:work')['exit']);
        $this->assertSame(1, RuntimeRoleCommandGuard::verdict('queue:restart')['exit']);

        $exit = null;
        RuntimeRoleCommandGuard::$exitUsing = function (int $code) use (&$exit): void {
            $exit = $code;
        };
        $output = new BufferedOutput;
        (new RuntimeRoleCommandGuard)->handle(new CommandStarting('reverb:start', new ArrayInput([]), $output));
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('reverb:start', $output->fetch());

        $this->role('live', false);
        $this->assertSame(0, RuntimeRoleCommandGuard::verdict('schedule:work')['exit']);
        $this->assertNull(RuntimeRoleCommandGuard::verdict('queue:work'), '스케줄 스위치는 워커를 막지 않는다');

        $this->role('live');
        $this->assertNull(RuntimeRoleCommandGuard::verdict('schedule:run'));
        $this->assertNull(RuntimeRoleCommandGuard::verdict('queue:work'));
    }

    /**
     * ② 내부 재시작 신호 — stage 면 queue:restart 를 부르지 않는다.
     *
     * @scenario case=queue_restart_signal
     *
     * @effects stage_blocks_workers_and_schedules
     *
     * @return void 반환값 없음
     */
    public function test_internal_queue_restart_is_skipped_on_stage(): void
    {
        $this->role('stage');
        Artisan::shouldReceive('call')->with('queue:restart')->never();

        app(CoreUpdateService::class)->signalQueueRestart();
        $this->addToAssertionCount(1);
    }

    /**
     * ③ 큐 연결 — stage 면 공유 설정과 무관하게 sync.
     *
     * @scenario case=queue_connection
     *
     * @effects stage_forces_sync_queue
     *
     * @return void 반환값 없음
     */
    public function test_stage_forces_sync_queue_connection(): void
    {
        $this->role('stage');
        config(['queue.default' => 'redis']);

        $provider = new SettingsServiceProvider($this->app);
        (new \ReflectionMethod($provider, 'applyDriverConfig'))->invoke($provider, app(JsonConfigRepository::class));

        $this->assertSame('sync', config('queue.default'));
    }

    /**
     * ④ 사이트맵 — stage 면 잡 무동작·명령 1·관리자 재생성 409, live 는 잡이 생성한다.
     *
     * @scenario case=sitemap
     *
     * @effects stage_skips_sitemap_seo_and_analytics
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_is_not_generated_on_stage(): void
    {
        $this->role('stage');
        $manager = Mockery::mock(SitemapManager::class);
        $manager->shouldNotReceive('regenerate');
        (new GenerateSitemapJob)->handle($manager);

        $this->assertSame(1, Artisan::call('seo:generate-sitemap'));

        $token = $this->extensionAdminToken(['core.settings.update']);
        // 수명주기 API 와 같은 라우트 미들웨어(`deny.stage`)가 막되, 안내는 사이트맵 문구다 — 확장·코어 변경 안내가 나오지 않는다 (#888)
        $this->withToken($token)->postJson('/api/admin/seo/sitemap/regenerate')
            ->assertStatus(409)->assertJsonPath('message', __('errors.stage_role_locked_sitemap'));
        $this->assertNotSame('errors.stage_role_locked_sitemap', __('errors.stage_role_locked_sitemap'), '사이트맵 거절 문구 키가 있어야 한다');
    }

    /**
     * ⑤⑥ SEO·분석 — stage 면 봇 판정 false, 수집은 204 이고 적재 훅을 부르지 않는다.
     *
     * @scenario case=seo_analytics
     *
     * @effects stage_skips_sitemap_seo_and_analytics
     *
     * @return void 반환값 없음
     */
    public function test_seo_and_analytics_are_off_on_stage(): void
    {
        $this->role('stage');
        $bot = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);
        $this->assertFalse(app(BotDetector::class)->isBot($bot));

        $fired = 0;
        $listener = function () use (&$fired): void {
            $fired++;
        };
        HookManager::addAction('core.analytics.tracking_request_received', $listener);
        try {
            $this->postJson('/api/analytics/collect', ['type' => 'pageview', 'path' => '/'])->assertStatus(204);
        } finally {
            HookManager::removeAction('core.analytics.tracking_request_received', $listener);
        }
        $this->assertSame(0, $fired);
    }

    /**
     * ⑦ 부트 설정 — 관리자·사용자 공개 설정에 실행 역할이 실린다.
     *
     * @scenario case=boot_payload
     *
     * @effects stage_role_exposed_to_admin_ui
     *
     * @return void 반환값 없음
     */
    public function test_frontend_config_exposes_runtime_role(): void
    {
        $this->role('stage');
        $this->assertSame('stage', app(FrontendConfigService::class)->build(FrontendConfigService::SCOPE_ADMIN)['runtime_role']);
        // 셸(`G7Config.appConfig`)이 쓰는 묶음에도 실린다 — 관리자 레이아웃은 `_global.appConfig.runtime_role` 로 읽는다
        $this->assertSame('stage', app(FrontendConfigService::class)->settingsBundle(FrontendConfigService::SCOPE_ADMIN)['appConfig']['runtime_role']);
        $this->assertSame('stage', ((array) app(FrontendConfigService::class)->build(FrontendConfigService::SCOPE_USER)['app_config'])['runtime_role']);
        $this->role('live');
        $this->assertSame('live', app(FrontendConfigService::class)->build(FrontendConfigService::SCOPE_ADMIN)['runtime_role']);
        $this->assertSame('live', app(FrontendConfigService::class)->settingsBundle(FrontendConfigService::SCOPE_ADMIN)['appConfig']['runtime_role']);
    }

    /**
     * ⑧ 수명주기 API — stage 면 409 `errors.stage_role_locked`, live 는 막지 않는다.
     *
     * @scenario case=lifecycle_api
     *
     * @effects stage_locks_lifecycle_api
     *
     * @return void 반환값 없음
     */
    public function test_lifecycle_api_is_locked_on_stage(): void
    {
        $token = $this->extensionAdminToken(['core.modules.install', 'core.modules.activate', 'core.system.update']);

        $this->role('stage');
        $this->withToken($token)->postJson('/api/admin/modules/install', ['module_name' => 'acme-none'])
            ->assertStatus(409)->assertJsonPath('message', __('errors.stage_role_locked'));
        $this->withToken($token)->postJson('/api/admin/core-update/start')->assertStatus(409);

        $this->role('live');
        $this->assertNotSame(409, $this->withToken($token)->postJson('/api/admin/modules/install', ['module_name' => 'acme-none'])->status());
    }

    /**
     * ⑧ 호환성 복구 — 복구는 확장을 다시 켜므로(활성화) stage 면 409, live 는 막지 않는다.
     *
     * @scenario case=lifecycle_api
     *
     * @effects stage_locks_lifecycle_api
     *
     * @return void 반환값 없음
     */
    public function test_compatibility_recover_api_is_locked_on_stage(): void
    {
        $token = $this->extensionAdminToken(['core.modules.activate']);

        $this->role('stage');
        $this->withToken($token)->postJson('/api/admin/extensions/module/acme-none/recover')
            ->assertStatus(409)->assertJsonPath('message', __('errors.stage_role_locked'));

        $this->role('live');
        $this->assertNotSame(409, $this->withToken($token)->postJson('/api/admin/extensions/module/acme-none/recover')->status());
    }

    /**
     * ⑧ 수명주기 경로 전수 — 등록된 라우트에서 수명주기 동작(설치·활성화·비활성화·제거·업데이트·업데이트 미리보기·호환성 복구·
     * 코어 업데이트·언어팩 설치/제거)을 모두 모아 하나도 빠짐없이 `deny.stage` 를 거치는지 본다. 새 경로가 생겨도 손으로 적은
     * 목록에 기대지 않는다.
     *
     * @scenario case=lifecycle_api
     *
     * @effects stage_locks_lifecycle_api
     *
     * @return void 반환값 없음
     */
    public function test_every_lifecycle_route_carries_deny_stage(): void
    {
        $lifecycle = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            $uri = $route->uri();
            $writes = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [];

            if (! $writes || ! str_starts_with($uri, 'api/admin/')) {
                continue;
            }

            $isExtensionLifecycle = preg_match('#^api/admin/(modules|plugins|templates|language-packs)/#', $uri) === 1
                && preg_match('#(^|[/.])(install(-from-[a-z]+)?|activate|bulk-activate|deactivate|uninstall|update(/preview)?)$#', $uri) === 1;
            $isLanguagePackUninstall = $route->methods() === ['DELETE'] && preg_match('#^api/admin/language-packs/\{[^}]+\}$#', $uri) === 1;
            $isRecover = preg_match('#^api/admin/extensions/\{type\}/\{identifier\}/recover$#', $uri) === 1;
            // 코어 업데이트 확인(check)은 결과만 돌려주는 조회라 검수 서버에서도 연다(⑨ 업데이트 확인과 같은 축).
            $isCoreUpdate = str_starts_with($uri, 'api/admin/core-update/') && $uri !== 'api/admin/core-update/check';

            if ($isExtensionLifecycle || $isLanguagePackUninstall || $isRecover || $isCoreUpdate) {
                $lifecycle[$name !== '' ? $name : implode('|', $route->methods()).' '.$uri] = $route;
            }
        }

        // 모집단이 실제로 잡혔는지 — 경로 이름·꼴이 바뀌어 아무것도 안 걸리면 검사가 헛돈다
        foreach (['api.admin.modules.install', 'api.admin.plugins.update.preview', 'api.admin.templates.uninstall', 'api.admin.extensions.recover', 'api.admin.core-update.start', 'api.admin.language-packs.uninstall', 'api.admin.language-packs.install-from-bundled'] as $expected) {
            $this->assertArrayHasKey($expected, $lifecycle, "수명주기 경로 모집단에 {$expected} 가 잡혀야 한다");
        }

        $missing = [];
        foreach ($lifecycle as $key => $route) {
            if (! in_array('deny.stage', $route->gatherMiddleware(), true)) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, '검수 서버 잠금(deny.stage)이 빠진 수명주기 경로');
    }

    /**
     * ⑨ 업데이트 확인 — stage 면 확인 결과는 돌려주되 공유 DB 의 업데이트 표시(`update_available`·`latest_version`)는 쓰지 않는다.
     * live 는 종전처럼 쓴다.
     *
     * @scenario case=update_check
     *
     * @effects stage_update_check_keeps_shared_rows
     *
     * @return void 반환값 없음
     */
    public function test_update_check_does_not_write_shared_rows_on_stage(): void
    {
        $this->role('stage');
        $result = $this->moduleManagerWithRecord(0)->checkAllModulesForUpdates();
        $this->assertSame(1, $result['checked_count'], '검수 서버도 확인 결과는 돌려준다');

        $this->role('live');
        $this->assertSame(1, $this->moduleManagerWithRecord(1)->checkAllModulesForUpdates()['checked_count']);
    }

    /**
     * 설치 기록 하나만 아는 저장소 대역으로 모듈 관리자를 만듭니다.
     *
     * @param  int  $expectedWrites  업데이트 표시 쓰기(`updateByIdentifier`) 기대 횟수
     * @return ModuleManager 모듈 관리자
     */
    private function moduleManagerWithRecord(int $expectedWrites): ModuleManager
    {
        $record = (new Module)->forceFill(['identifier' => 'acme-stage888', 'version' => '1.0.0']);
        $repository = Mockery::mock(ModuleRepositoryInterface::class);
        $repository->shouldReceive('getAllKeyedByIdentifier')->andReturn(new EloquentCollection(['acme-stage888' => $record]));
        $repository->shouldReceive('findByIdentifier')->with('acme-stage888')->andReturn($record);
        $repository->shouldReceive('updateByIdentifier')->times($expectedWrites);
        $this->app->instance(ModuleRepositoryInterface::class, $repository);

        return $this->app->build(ModuleManager::class);
    }

    /**
     * 서버별 스케줄 스위치 — 웹 크론·방문 트리거의 공통 판정이 꺼지고, 설정 API 가 이 서버의 값을 싣는다.
     *
     * @scenario case=scheduler_switch
     *
     * @effects scheduler_switch_disables_web_triggers
     *
     * @return void 반환값 없음
     */
    public function test_scheduler_switch_disables_web_triggers_and_is_reported(): void
    {
        config(['core.settings.system.cron_mode' => 'auto']);
        $this->role('live', false);
        $this->assertFalse(app(WebCronPolicy::class)->webCallAllowed());

        $token = $this->extensionAdminToken(['core.settings.read']);
        $this->withToken($token)->getJson('/api/admin/settings')->assertOk()->assertJsonPath('data.scheduler_enabled_on_this_server', false);

        $this->role('live', true);
        $this->withToken($token)->getJson('/api/admin/settings')->assertJsonPath('data.scheduler_enabled_on_this_server', true);
    }
}
