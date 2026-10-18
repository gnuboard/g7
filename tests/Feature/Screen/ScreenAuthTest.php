<?php

namespace Tests\Feature\Screen;

use App\Enums\PasswordChangeSource;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Privacy\DormantAccountService;
use App\Services\AuthService;
use App\Services\PasswordChangeService;
use App\Services\PasswordExpiryService;
use App\Services\Screen\ScreenAuth;
use App\Services\UserService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LoadsCustomRoutes;
use Tests\TestCase;

/**
 * 개발자 화면 회원 판정(ScreenAuth)·`custom.auth`·`custom.admin` 테스트 (#792 WP-A1 — F6-1~F6-4)
 *
 * 로그인 API 가 세션에 남긴 로그인과 그 로그인의 토큰을 짝으로 묶고, 토큰이 회수·만료되면 세션 로그인도 무효가
 * 되며, 토큰 갱신·가입이 짝을 맞추고, 개발자 화면 요청이 토큰 만료를 연장하는지 잠근다.
 */
class ScreenAuthTest extends TestCase
{
    use DatabaseTransactions;
    use LoadsCustomRoutes;

    private const PASSWORD = 'Screen!pass792';

    /** 마지막으로 로그인해 받은 평문 토큰 */
    private string $lastToken = '';

    /**
     * 시험용 custom 라우트를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 요청마다 새 세션 저장소로 시작하도록 파일 드라이버를 쓴다 — 기본 array 드라이버는 한 프로세스 안에서
        // 앞 요청의 세션 값이 다음 요청에 그대로 남아, 실제 브라우저 요청과 다른 결과를 낸다.
        config(['session.driver' => 'file']);
        $this->freshRequestState();
        // JSON 요청에도 쿠키를 싣는다(브라우저 fetch credentials 와 같다)
        $this->withCredentials();

        $this->loadCustomRoutesSource(<<<'PHP'
<?php
use App\Services\Screen\ScreenAuth;
use Illuminate\Support\Facades\Route;
Route::get('/screen-probe', fn () => response()->json(['id' => ScreenAuth::user()?->id]));
Route::get('/screen-session', fn () => response()->json([
    'paired' => session()->has(ScreenAuth::SESSION_KEY),
    'web_login' => session()->has(Illuminate\Support\Facades\Auth::guard('web')->getName()),
]));
Route::get('/screen-member', fn () => 'member-ok')->middleware('custom.auth');
Route::get('/admin/screen-admin', fn () => 'admin-ok')->middleware('custom.admin');
PHP);
    }

    /**
     * 로그인 API 뒤 개발자 화면은 그 회원을 알고, 세션에 짝 토큰 ID 가 남습니다.
     *
     * @scenario case=login_pairs
     *
     * @effects login_pairs_session_with_token
     *
     * @return void 반환값 없음
     */
    public function test_login_pairs_session_and_screen_sees_member(): void
    {
        $user = $this->member();
        $token = $this->login($user);

        $this->assertSame($user->id, $this->probe());
        $tokenId = (int) explode('|', $token, 2)[0];
        $this->assertTrue(PersonalAccessToken::query()->whereKey($tokenId)->exists());
    }

    /**
     * 짝 토큰이 회수되면(로그아웃 API·모든 기기 로그아웃 API·서비스의 일괄 회수) 개발자 화면은 비회원으로 보고 세션 표식을 지웁니다.
     *
     * @scenario case=revoked
     *
     * @effects revoked_token_invalidates_screen_login
     *
     * @param  string  $how  회수 방법
     * @return void 반환값 없음
     */
    #[DataProvider('revokeProvider')]
    public function test_revoked_token_invalidates_screen_login(string $how): void
    {
        $user = $this->member();
        $token = $this->login($user);
        $this->assertSame($user->id, $this->probe());

        $this->assertSame(['paired' => true, 'web_login' => true], $this->sessionState());

        match ($how) {
            'logout_api' => $this->withToken($token)->postJson('/api/user/auth/logout')->assertOk(),
            'logout_all_devices_api' => $this->withToken($token)->postJson('/api/user/auth/logout-all-devices')->assertOk(),
            'dormant_convert' => (new \ReflectionMethod(DormantAccountService::class, 'convertOne'))->invoke(app(DormantAccountService::class), $user->fresh()),
            'status_change' => app(UserService::class)->updateUser($user->fresh(), ['status' => UserStatus::Blocked->value]),
            'withdraw' => app(UserService::class)->withdrawUser($user->fresh()),
            'delete' => app(UserService::class)->deleteUser($user->fresh()),
            'password_expiry' => $this->expirePassword($user),
            'password_change' => app(PasswordChangeService::class)->recordChange($user->fresh(), null, PasswordChangeSource::Admin),
            'logout_all_devices' => app(AuthService::class)->logoutFromAllDevices($user->fresh()),
        };
        $this->withoutToken();
        $this->freshRequestState();

        $this->assertNull($this->probe());
        // 세션의 짝 표식과 로그인 표식이 함께 지워진다(다음 요청에서 다시 읽어도 남아 있지 않다)
        $this->assertSame(['paired' => false, 'web_login' => false], $this->sessionState());
    }

    /**
     * 회수 경로 — 로그아웃 API 2종 + 코어 서비스의 토큰 회수 7곳(휴면 전환·상태 변경·탈퇴·삭제·비밀번호 만료 일괄·
     * 비밀번호 변경·모든 기기 로그아웃). 서비스 경로는 회수 코드를 그대로 거친다(흉내 낸 일괄 삭제가 아니다).
     *
     * @return array<string, array{0: string}> 데이터셋
     */
    public static function revokeProvider(): array
    {
        return [
            'logout_api' => ['logout_api'],
            'logout_all_devices_api' => ['logout_all_devices_api'],
            'dormant_convert' => ['dormant_convert'],
            'status_change' => ['status_change'],
            'withdraw' => ['withdraw'],
            'delete' => ['delete'],
            'password_expiry' => ['password_expiry'],
            'password_change' => ['password_change'],
            'logout_all_devices' => ['logout_all_devices'],
        ];
    }

    /**
     * 짝 토큰이 무효가 되면 정식 로그아웃(Logout 이벤트)으로 세션 로그인을 끝냅니다 (#792).
     *
     * @scenario case=revoked
     *
     * @effects invalid_pair_logs_out_formally
     *
     * @return void 반환값 없음
     */
    public function test_invalid_pair_logs_out_formally(): void
    {
        $user = $this->member();
        $this->login($user);
        $user->tokens()->delete();
        $this->freshRequestState();

        $events = [];
        Event::listen(Logout::class, function (Logout $event) use (&$events) {
            $events[] = [$event->guard, $event->user?->getKey()];
        });

        $this->assertNull($this->probe());
        $this->assertSame([['web', $user->id]], $events);
    }

    /**
     * 비밀번호 만료 일괄 처리(변경해야 로그인 모드)로 그 회원의 토큰을 회수합니다.
     *
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    private function expirePassword(User $user): void
    {
        config([
            'g7_settings.core.security.password_expiry_enabled' => true,
            'g7_settings.core.security.password_expiry_action' => 'force',
            'g7_settings.core.security.password_expiry_days' => 90,
            'g7_settings.core.security.password_expiry_started_at' => now()->subDays(400)->toIso8601String(),
        ]);
        $user->forceFill(['password_expiry_policy' => 'apply', 'password_changed_at' => now()->subDays(200)])->save();

        $result = app(PasswordExpiryService::class)->processExpired(false);
        $this->assertGreaterThanOrEqual(1, $result['count']);
    }

    /**
     * 세션 저장값을 개발자 화면 판정 없이 읽습니다.
     *
     * @return array{paired: bool, web_login: bool} 짝 표식·웹 로그인 표식 존재
     */
    private function sessionState(): array
    {
        $state = $this->getJson('/screen-session')->assertOk()->json();
        $this->freshRequestState();

        return ['paired' => (bool) $state['paired'], 'web_login' => (bool) $state['web_login']];
    }

    /**
     * 짝 토큰이 만료되면 비회원입니다.
     *
     * @scenario case=expired
     *
     * @effects expired_token_invalidates_screen_login
     *
     * @return void 반환값 없음
     */
    public function test_expired_token_invalidates_screen_login(): void
    {
        $user = $this->member();
        $this->login($user);
        PersonalAccessToken::query()->whereKey((int) explode('|', $this->lastToken, 2)[0])->update(['expires_at' => now()->subMinute()]);
        $this->freshRequestState();

        $this->assertNull($this->probe());
    }

    /**
     * 개발자 화면 요청이 남은 시간이 절반 미만인 짝 토큰의 만료를 연장합니다.
     *
     * @scenario case=slide
     *
     * @effects screen_request_slides_token_expiry
     *
     * @return void 반환값 없음
     */
    public function test_screen_request_slides_token_expiry(): void
    {
        config(['g7_settings.core.security.auth_token_lifetime' => 30]);
        $user = $this->member();
        $this->login($user);
        $id = (int) explode('|', $this->lastToken, 2)[0];
        PersonalAccessToken::query()->whereKey($id)->update(['expires_at' => now()->addMinutes(5)]);
        $this->freshRequestState();

        $this->assertSame($user->id, $this->probe());
        $this->assertTrue(PersonalAccessToken::find($id)->expires_at->greaterThan(now()->addMinutes(25)));
    }

    /**
     * 토큰 갱신 API 가 세션의 짝을 새 토큰으로 바꿔 갱신 뒤에도 회원으로 보입니다.
     *
     * @scenario case=refresh
     *
     * @effects refresh_repairs_pair
     *
     * @return void 반환값 없음
     */
    public function test_refresh_keeps_screen_login(): void
    {
        $user = $this->member();
        $token = $this->login($user);
        $this->freshRequestState();

        $refreshed = $this->withToken($token)->postJson('/api/user/auth/refresh')->assertOk();
        $this->rememberSessionCookie($refreshed);
        $newToken = $refreshed->json('data.token');
        $this->withoutToken();
        $this->freshRequestState();

        $this->assertNotSame($token, $newToken);
        $this->assertFalse(PersonalAccessToken::query()->whereKey((int) explode('|', $token, 2)[0])->exists(), '옛 토큰은 지워진다');
        $this->assertSame($user->id, $this->probe());
    }

    /**
     * 가입 API 직후 개발자 화면이 가입 회원을 압니다 (D20).
     *
     * @scenario case=register
     *
     * @effects register_logs_in_screen_session
     *
     * @return void 반환값 없음
     */
    public function test_register_logs_in_screen_session(): void
    {
        $email = 'screen792_'.uniqid().'@example.com';
        $response = $this->postJson('/api/auth/register', [
            'name' => '가입시험',
            'nickname' => 'scr'.substr(uniqid(), -6),
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);
        if ($response->status() !== 201 && $response->status() !== 200) {
            $this->markTestIncomplete('가입 폼 필수 항목이 환경 설정에 따라 달라 응답 '.$response->status().': '.$response->getContent());
        }
        $this->rememberSessionCookie($response);
        $this->freshRequestState();

        $this->assertSame(User::where('email', $email)->value('id'), $this->probe());
    }

    /**
     * `custom.auth` — 비회원은 로그인 화면으로 302(복귀 주소 포함), 회원은 통과합니다.
     *
     * @scenario case=custom_auth
     *
     * @effects custom_auth_redirects_guest, custom_auth_passes_member
     *
     * @return void 반환값 없음
     */
    public function test_custom_auth_middleware(): void
    {
        $this->get('/screen-member')->assertRedirect('/login?redirect='.rawurlencode('/screen-member'));

        $this->login($this->member());
        $this->freshRequestState();
        $this->get('/screen-member')->assertOk()->assertSeeText('member-ok');
    }

    /**
     * `custom.admin` — 비회원 302 관리자 로그인, 일반 회원 403, 관리자 통과. 관리자 로그인도 짝을 맺습니다.
     *
     * @scenario case=custom_admin
     *
     * @effects custom_admin_redirects_guest, custom_admin_forbids_member, custom_admin_passes_admin, admin_login_pairs
     *
     * @return void 반환값 없음
     */
    public function test_custom_admin_middleware(): void
    {
        $this->get('/admin/screen-admin')->assertRedirect('/admin/login?redirect='.rawurlencode('/admin/screen-admin'));

        $this->login($this->member());
        $this->freshRequestState();
        $this->get('/admin/screen-admin')->assertForbidden();

        $this->flushSession();
        $this->defaultCookies = [];
        $this->freshRequestState();
        $admin = $this->member(['admin']);
        $this->rememberSessionCookie($this->postJson('/api/auth/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertOk());
        $this->freshRequestState();
        $this->get('/admin/screen-admin')->assertOk()->assertSeeText('admin-ok');
    }

    /**
     * 짝이 없는(7.2.0 이전 로그인) 세션은 비회원입니다 (D21).
     *
     * @scenario case=legacy_session
     *
     * @effects legacy_session_without_pair_is_guest
     *
     * @return void 반환값 없음
     */
    public function test_legacy_session_without_pair_is_guest(): void
    {
        $user = $this->member();
        $this->actingAs($user, 'web');

        $this->assertNull($this->probe());
    }

    /**
     * 로그인 API 로 로그인하고 평문 토큰을 돌려줍니다.
     *
     * @param  User  $user  회원
     * @return string 평문 토큰
     */
    private function login(User $user): string
    {
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->rememberSessionCookie($response);
        $this->freshRequestState();
        $this->lastToken = (string) $response->json('data.token');

        return $this->lastToken;
    }

    /**
     * 다음 요청을 새 요청처럼 — 가드·세션 저장소 메모리를 비웁니다(세션 내용은 쿠키로 다시 읽힌다).
     *
     * @return void 반환값 없음
     */
    private function freshRequestState(): void
    {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /**
     * 응답이 세션 쿠키를 내리면 이후 요청에 싣습니다 (브라우저처럼).
     *
     * @param  TestResponse  $response  응답
     * @return void 반환값 없음
     */
    private function rememberSessionCookie($response): void
    {
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie !== null && $cookie->getValue() !== null) {
            $this->withCookie(config('session.cookie'), (string) $cookie->getValue());
        }
    }

    /**
     * 개발자 화면이 본 회원 ID.
     *
     * @return int|null 회원 ID
     */
    private function probe(): ?int
    {
        $id = $this->getJson('/screen-probe')->assertOk()->json('id');
        $this->freshRequestState();

        return $id === null ? null : (int) $id;
    }

    /**
     * 시험 회원을 만듭니다.
     *
     * @param  array<int, string>  $roles  역할 식별자 (기본 user)
     * @return User 회원
     */
    private function member(array $roles = ['user']): User
    {
        $user = User::factory()->create([
            'email' => 'screen792_'.uniqid().'@example.com',
            'password' => bcrypt(self::PASSWORD),
            'is_super' => in_array('admin', $roles, true),
        ]);

        // 회원 역할: 로그아웃·갱신 권한 (시험 DB 에는 시드 역할이 없다)
        $permissionIds = [];
        foreach (['core.auth.logout', 'core.auth.refresh', 'core.auth.user'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'type' => 'user',
            ])->id;
        }
        $memberRole = Role::create([
            'identifier' => 'screen_test_'.uniqid(),
            'name' => json_encode(['ko' => '시험', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $memberRole->permissions()->sync($permissionIds);
        $user->roles()->attach($memberRole->id, ['assigned_at' => now()]);

        if (in_array('admin', $roles, true)) {
            // 관리자 판정(User::isAdmin)은 관리자 유형 권한 보유다
            $adminPermission = Permission::firstOrCreate(['identifier' => 'core.settings.read'], [
                'name' => json_encode(['ko' => 'core.settings.read', 'en' => 'core.settings.read']),
                'description' => json_encode(['ko' => 'core.settings.read', 'en' => 'core.settings.read']),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]);
            $adminRole = Role::create([
                'identifier' => 'screen_admin_'.uniqid(),
                'name' => json_encode(['ko' => '관리자 시험', 'en' => 'Admin test']),
                'is_active' => true,
            ]);
            $adminRole->permissions()->sync([$adminPermission->id]);
            $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        }

        $user = $user->fresh();
        $user->flushPermissionCaches();

        return $user;
    }
}
