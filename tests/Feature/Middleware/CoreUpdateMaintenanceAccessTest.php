<?php

namespace Tests\Feature\Middleware;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\CoreUpdate\CoreUpdateMaintenanceAccess;
use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateRunState;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UnknownEmailLockService;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * 관리자 화면 코어 업데이트가 사이트를 점검 모드로 둔 동안 우회 쿠키가 없는 관리자가 이어 가는 통로.
 *
 * 되돌리기 도중 브라우저를 닫으면 남은 조각을 부를 주체가 없고, 다른 브라우저·기기에는 점검 우회 쿠키가 없어 `/admin/login`
 * 부터 503 이었다 — 진행·되돌리기 면제 경로는 로그인 토큰이 있어야 해 새 기기에서는 쓸 수 없다(실측: 비밀 주소로 쿠키를 받은
 * 뒤에야 로그인·탭 열기 → 자동 재개 → `rolled_back`). 점검 화면에 관리자 확인 칸을 두고, 코어 업데이트 권한 관리자로 확인되면
 * 우회 쿠키만 발급한다(로그인 세션은 만들지 않는다). 통로는 웹 업데이트가 진행·되돌리기·복구 중일 때만 열린다.
 *
 * 시나리오 SSoT: tests/scenarios/core-update-maintenance-access.yaml
 */
class CoreUpdateMaintenanceAccessTest extends TestCase
{
    use RefreshDatabase;

    /** 점검 비밀값 */
    private const SECRET = 'w9-012-secret';

    /** 격리된 웹 실행 상태 경로 */
    private string $statePath;

    /**
     * 점검 파일을 비우고 웹 실행 상태를 격리 경로로 돌린다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearDown();
        $this->statePath = storage_path('framework/testing/w9-012-'.uniqid().'/'.CoreUpdateRunState::FILENAME);
        File::ensureDirectoryExists(dirname($this->statePath));
        $this->app->instance(
            CoreUpdateMaintenanceAccess::class,
            new CoreUpdateMaintenanceAccess(app(UserRepositoryInterface::class), app(UnknownEmailLockService::class), new CoreUpdateRunState($this->statePath)),
        );
        RateLimiter::clear('core-update-maintenance-access:'.sha1('127.0.0.1'));
    }

    /**
     * 점검 파일과 격리 경로를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearDown();
        File::deleteDirectory(dirname($this->statePath));

        parent::tearDown();
    }

    /**
     * 실측 재현: 되돌리는 중 우회 쿠키가 없는 브라우저도 점검 화면에서 관리자 확인 칸을 보고, 코어 업데이트 권한 관리자로
     * 확인하면 우회 쿠키를 받아 업데이트 탭으로 간다. 그 쿠키로 점검 화면을 지난다.
     *
     * @scenario case=rolling_back_admin
     *
     * @effects resume_form_shown_while_web_update_active, update_admin_gets_bypass_cookie
     *
     * @return void 반환값 없음
     */
    public function test_admin_without_cookie_can_get_back_in_while_rolling_back(): void
    {
        $this->startRun(CoreUpdateRunState::STATUS_ROLLING_BACK);
        $this->down();
        $admin = $this->updateAdmin();

        $this->get('/admin/login')
            ->assertStatus(503)
            ->assertSee((string) __('maintenance.admin_access.title'))
            ->assertSee('/'.CoreUpdateMaintenanceAccess::PATH, false);

        $response = $this->post('/'.CoreUpdateMaintenanceAccess::PATH, ['email' => $admin->email, 'password' => 'password']);

        $response->assertRedirect('/admin/settings?tab=update');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'laravel_maintenance');
        $this->assertNotNull($cookie, '코어 업데이트 권한 관리자에게 우회 쿠키를 주지 않았다');
        $this->assertTrue(MaintenanceModeBypassCookie::isValid($cookie->getValue(), self::SECRET));
        $this->assertGuest();
    }

    /**
     * 틀린 비밀번호·코어 업데이트 권한 없는 계정은 쿠키 없이 점검 화면으로 돌아가 같은 실패 안내를 본다(사유를 가르지 않는다).
     * 5회 실패 뒤에는 맞는 비밀번호도 시도 제한으로 막는다.
     *
     * @scenario case=wrong_or_unprivileged
     *
     * @effects failed_attempt_gets_no_cookie, attempts_are_throttled
     *
     * @return void 반환값 없음
     */
    public function test_wrong_credentials_and_unprivileged_accounts_get_no_cookie(): void
    {
        $this->startRun(CoreUpdateRunState::STATUS_RUNNING);
        $this->down();
        $admin = $this->updateAdmin();
        $member = User::factory()->create();

        foreach ([['email' => $admin->email, 'password' => 'wrong'], ['email' => $member->email, 'password' => 'password']] as $input) {
            $response = $this->post('/'.CoreUpdateMaintenanceAccess::PATH, $input);
            $response->assertRedirect('/?maintenance_access=failed');
            $this->assertNull(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'laravel_maintenance'));
        }

        $this->get('/?maintenance_access=failed')->assertStatus(503)->assertSee((string) __('maintenance.admin_access.failed'));

        for ($i = 0; $i < 3; $i++) {
            $this->post('/'.CoreUpdateMaintenanceAccess::PATH, ['email' => $admin->email, 'password' => 'wrong']);
        }

        $this->post('/'.CoreUpdateMaintenanceAccess::PATH, ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/?maintenance_access=throttled');
    }

    /**
     * 확인 칸의 틀린 비밀번호는 일반 로그인 실패와 같은 계수기에 세어 같은 기준으로 계정을 잠그고, 잠긴 계정은 비밀번호가 맞아도
     * 통과하지 못한다(응답은 다른 실패와 같은 한 문장). 잠금은 일반 로그인에도 그대로 걸린다 — 이 칸이 잠금을 피하는 대입 통로가 아니다.
     *
     * @scenario case=lockout_shared
     *
     * @effects failures_share_login_lockout_counter, locked_account_refused_even_with_right_password
     *
     * @return void 반환값 없음
     */
    public function test_failures_share_the_login_lockout_and_locked_accounts_are_refused(): void
    {
        config([
            'g7_settings.core.security.login_attempt_enabled' => true,
            'g7_settings.core.security.max_login_attempts' => 3,
            'g7_settings.core.security.login_lockout_time' => 5,
        ]);
        $this->startRun(CoreUpdateRunState::STATUS_ROLLING_BACK);
        $this->down();
        $admin = $this->updateAdmin();

        // 실패 기록은 요청의 User-Agent 를 미들웨어가 넘긴 값으로 싣는다(전역 요청에 기대지 않음)
        $seenContexts = [];
        HookManager::addAction('core.auth.login_failed', function (string $email, array $context = []) use (&$seenContexts): void {
            $seenContexts[] = $context;
        }, 99);

        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('User-Agent', 'fxu-probe-agent')
                ->post('/'.CoreUpdateMaintenanceAccess::PATH, ['email' => $admin->email, 'password' => 'wrong'])
                ->assertRedirect('/?maintenance_access=failed');
        }

        $this->assertSame(['fxu-probe-agent', 'fxu-probe-agent'], array_column($seenContexts, 'user_agent'));
        $this->assertSame(['core_update_maintenance_access', 'core_update_maintenance_access'], array_column($seenContexts, 'source'));

        $admin->refresh();
        $this->assertSame(2, (int) $admin->failed_login_attempts, '확인 칸의 실패가 일반 로그인 실패 계수기에 세지지 않았다');

        // 세 번째 실패에서 일반 로그인과 같은 기준(3회)으로 잠긴다 — 잠그면서 계수는 0 으로 돌아간다(로그인 실패 리스너)
        $this->post('/'.CoreUpdateMaintenanceAccess::PATH, ['email' => $admin->email, 'password' => 'wrong'])
            ->assertRedirect('/?maintenance_access=failed');
        $admin->refresh();
        $this->assertTrue(app(UserRepositoryInterface::class)->isLocked($admin), '같은 기준(3회)으로 계정이 잠기지 않았다');
        $lockedUntil = $admin->locked_until?->toIso8601String();

        // IP 한도와 분리해 계정 잠금만 잰다
        RateLimiter::clear('core-update-maintenance-access:'.sha1('127.0.0.1'));

        $response = $this->post('/'.CoreUpdateMaintenanceAccess::PATH, ['email' => $admin->email, 'password' => 'password']);
        $response->assertRedirect('/?maintenance_access=failed');
        $this->assertNull(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'laravel_maintenance'), '잠긴 계정에 우회 쿠키를 줬다');

        $admin->refresh();
        $this->assertTrue(app(UserRepositoryInterface::class)->isLocked($admin));
        $this->assertSame(0, (int) $admin->failed_login_attempts, '잠긴 동안의 시도가 계수를 늘렸다');
        $this->assertSame($lockedUntil, $admin->locked_until?->toIso8601String(), '잠긴 동안의 시도가 잠금 해제 시각을 밀었다');
    }

    /**
     * 진행 중인 웹 업데이트가 없는 점검(`php artisan down` 등)에서는 확인 칸을 그리지 않고 통로도 503 이다 — 일반 점검의 우회 수단이 되지 않는다.
     *
     * @scenario case=no_active_web_update
     *
     * @effects resume_access_closed_without_active_web_update
     *
     * @return void 반환값 없음
     */
    public function test_access_is_closed_without_an_active_web_update(): void
    {
        $this->down();
        $admin = $this->updateAdmin();

        $this->get('/')->assertStatus(503)->assertDontSee((string) __('maintenance.admin_access.title'));
        $this->post('/'.CoreUpdateMaintenanceAccess::PATH, ['email' => $admin->email, 'password' => 'password'])->assertStatus(503);

        $this->startRun(CoreUpdateRunState::STATUS_COMPLETED);
        $this->get('/')->assertStatus(503)->assertDontSee((string) __('maintenance.admin_access.title'));
    }

    /**
     * 격리 경로에 웹 실행을 만들고 상태를 정한다.
     *
     * @param  string  $status  실행 상태
     * @return void 반환값 없음
     */
    private function startRun(string $status): void
    {
        $state = new CoreUpdateRunState($this->statePath);
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->patch(['status' => $status]);
    }

    /**
     * 점검 모드를 켠다(`php artisan down --secret` 이 쓰는 파일 형식).
     *
     * @return void 반환값 없음
     */
    private function down(): void
    {
        file_put_contents(storage_path('framework/down'), (string) json_encode([
            'except' => [], 'redirect' => null, 'retry' => null, 'refresh' => null, 'secret' => self::SECRET, 'status' => 503, 'template' => null,
        ]));
    }

    /**
     * 점검 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    private function clearDown(): void
    {
        $down = storage_path('framework/down');
        if (file_exists($down)) {
            unlink($down);
        }
    }

    /**
     * 코어 업데이트 권한을 가진 관리자를 만든다(비밀번호 `password`).
     *
     * @return User 관리자
     */
    private function updateAdmin(): User
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.system.update'],
            [
                'name' => json_encode(['ko' => 'core.system.update', 'en' => 'core.system.update']),
                'description' => json_encode(['ko' => 'core.system.update', 'en' => 'core.system.update']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
            ]
        );
        $role = Role::create([
            'identifier' => 'w9_012_admin_'.uniqid(),
            'name' => json_encode(['ko' => '시험 관리자', 'en' => 'Test admin']),
            'description' => json_encode(['ko' => '시험 관리자', 'en' => 'Test admin']),
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
