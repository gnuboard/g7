<?php

namespace Tests\Feature\Auth;

use App\Extension\Auth\PasswordAuthDecision;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\Auth\PasswordAuthPolicyService;
use App\Services\PasswordExpiryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 비밀번호 인증 정책 필터 `core.auth.password_auth_decision`
 *
 * 확장이 「이 식별자·회원은 비밀번호를 쓰지 못한다」고 답하면 코어가 비밀번호 로그인·재설정·설정·관리자 지정·로그인 중 변경을 막는다.
 * 식별자 기준 판정은 로그인 시도 전(실패 횟수 0), 회원 기준 판정은 비밀번호가 맞은 뒤(틀리면 일반 실패와 같다). 재설정 요청의 회원 기준
 * 거절은 일반 성공 응답 + 액션 1회 + 재설정 토큰 행 0. 거절 응답은 403 `errors.code = auth.password_auth_disabled` + 이동 경로.
 */
class PasswordAuthPolicyTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /** 정책이 막을 도메인 */
    private const BLOCKED_DOMAIN = 'corp798.test';

    /** 필터가 받은 판정 대상 */
    private array $subjects = [];

    /**
     * 메일을 막고 시도 제한을 초기화하며, 판정 대상을 기록하는 필터를 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();
        $this->setSecurity(['login_attempt_enabled' => true, 'max_login_attempts' => 5, 'login_lockout_time' => 5]);
        $this->subjects = [];
    }

    /**
     * 판정 필터를 등록합니다.
     *
     * @param  string  $basis  거절 기준 (identifier | user)
     * @param  array<int, string>  $purposes  거절할 목적 (빈 배열이면 전부)
     * @param  bool  $requireTwoFactor  허용하되 2단계 인증 요구
     * @return void 반환값 없음
     */
    private function policy(string $basis, array $purposes = [], bool $requireTwoFactor = false): void
    {
        HookManager::addFilter('core.auth.password_auth_decision', function ($decision, array $subject) use ($basis, $purposes, $requireTwoFactor) {
            $this->subjects[] = $subject;

            if ($requireTwoFactor) {
                return PasswordAuthDecision::allow(true);
            }

            if ($purposes !== [] && ! in_array($subject['purpose'], $purposes, true)) {
                return $decision;
            }

            $matches = $basis === 'identifier'
                ? str_ends_with(strtolower($subject['identifier']), '@'.self::BLOCKED_DOMAIN)
                : ($subject['user'] instanceof User && str_ends_with($subject['user']->email, '@'.self::BLOCKED_DOMAIN));

            return $matches
                ? PasswordAuthDecision::deny($basis, ['ko' => '회사 계정', 'en' => 'Company account'], '/plugins/test/login/corp')
                : $decision;
        });
    }

    /**
     * 막힌 도메인의 회원을 만듭니다.
     *
     * @param  bool  $admin  관리자로 만들지
     * @return User 회원
     */
    private function corpMember(bool $admin = false): User
    {
        $email = 'p.'.Str::lower(Str::random(8)).'@'.self::BLOCKED_DOMAIN;

        return $admin ? $this->makeAdmin(['email' => $email]) : $this->makeMember(['email' => $email]);
    }

    /**
     * 식별자 기준 거절은 시도 전이라 실패 횟수가 늘지 않고 403 안내와 이동 경로가 온다 (사용자·관리자 경로).
     *
     * @scenario purpose=login, basis=identifier, guard=user|admin
     *
     * @effects identifier_denial_before_attempt, denial_response_carries_redirect, failed_attempts_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 식별자_기준_거절은_시도_전이다(): void
    {
        $this->policy('identifier');
        $user = $this->corpMember();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertForbidden()
            ->assertJsonPath('errors.code', 'auth.password_auth_disabled')
            ->assertJsonPath('data.redirect_url', '/plugins/test/login/corp')
            ->assertJsonPath('data.method_label.ko', '회사 계정');

        $this->assertSame(0, (int) $user->fresh()->failed_login_attempts);
        $this->assertNull($this->subjects[0]['user']);
        $this->assertSame('login', $this->subjects[0]['purpose']);

        $admin = $this->corpMember(true);
        $this->postJson('/api/auth/admin/login', ['email' => $admin->email, 'password' => 'Passw0rd!old'])
            ->assertForbidden()
            ->assertJsonPath('errors.code', 'auth.password_auth_disabled');
    }

    /**
     * 회원 기준 거절은 비밀번호가 맞을 때만 드러나고, 틀리면 일반 실패와 같다. 거절 뒤 세션이 남지 않는다.
     *
     * @scenario purpose=login, basis=user, password=correct|wrong
     *
     * @effects user_denial_after_password, wrong_password_hides_denial, user_denial_leaves_no_session
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 회원_기준_거절은_비밀번호가_맞을_때만_드러난다(): void
    {
        $this->policy('user');
        $user = $this->corpMember();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnauthorized()
            ->assertJsonMissingPath('errors.code');
        $this->assertSame(1, (int) $user->fresh()->failed_login_attempts, '틀린 비밀번호는 평소처럼 센다');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])
            ->assertForbidden()
            ->assertJsonPath('errors.code', 'auth.password_auth_disabled');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse($this->app['auth']->guard('web')->check(), '거절 전 세션을 닫는다');
    }

    /**
     * 비상 계정처럼 허용하되 2단계 인증을 요구하면 사이트 2단계 설정이 꺼져 있어도 2단계 단계로 간다.
     *
     * @scenario purpose=login, decision=allow_require_two_factor, site_two_factor=off
     *
     * @effects require_two_factor_forces_challenge
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이단계_요구_판정은_사이트_설정과_무관하다(): void
    {
        $this->setSecurity(['two_factor_auth' => false]);
        $this->policy('identifier', requireTwoFactor: true);
        $user = $this->corpMember();

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])->assertOk();

        $this->assertTrue((bool) $response->json('data.two_factor_required'));
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 재설정 요청: 식별자 기준이면 화면에 안내(422), 회원 기준이면 일반 성공 + 액션 1회 + 토큰 행 0.
     *
     * @scenario purpose=reset_request, basis=identifier|user
     *
     * @effects reset_request_identifier_denial_shown, reset_request_user_denial_silent, reset_blocked_action_fired
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재설정_요청_거절(): void
    {
        $user = $this->corpMember();

        $this->policy('identifier', ['reset_request']);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'auth.password_auth_disabled');
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count());

        HookManager::resetAll();
        $fired = [];
        HookManager::addAction('core.auth.password_reset_blocked', function (User $u, PasswordAuthDecision $d) use (&$fired) {
            $fired[] = [$u->id, $d->basis];
        });
        $this->policy('user', ['reset_request']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->assertSame([[$user->id, 'user']], $fired);
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count());
    }

    /**
     * 재설정 완료와 토큰 확인은 거절이면 422 이고 비밀번호가 바뀌지 않는다.
     *
     * @scenario purpose=reset_complete
     *
     * @effects reset_complete_denied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재설정_완료_거절(): void
    {
        $user = $this->corpMember();
        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $user->email], ['token' => Hash::make($token), 'created_at' => now()]);
        $before = $user->getRawOriginal('password');

        $this->policy('user', ['reset_complete']);

        $this->postJson('/api/auth/validate-reset-token', ['token' => $token, 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'auth.password_auth_disabled');
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
        ])->assertStatus(422)->assertJsonPath('errors.code', 'auth.password_auth_disabled');

        $this->assertSame($before, $user->fresh()->getRawOriginal('password'));
    }

    /**
     * 관리자 재설정 화면(`redirect_prefix=admin`)의 토큰 확인·재설정 완료는 관리자 경로로 판정해, 거절 응답의 다른 로그인 수단 주소가
     * 관리자 로그인으로 온다(비밀번호 찾기의 `redirect_prefix` 와 같다). 값이 없으면 종전대로 사용자 경로이고, 다른 값은 받지 않는다.
     *
     * @scenario purpose=reset_complete, guard=user|admin
     *
     * @effects reset_complete_guard_follows_screen
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재설정_완료_판정은_재설정_화면의_경로를_따른다(): void
    {
        $user = $this->corpMember(true);
        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $user->email], ['token' => Hash::make($token), 'created_at' => now()]);
        $before = $user->getRawOriginal('password');

        // 실제 확장처럼 판정 경로(guard)로 그 수단 시작 주소를 만든다
        HookManager::addFilter('core.auth.password_auth_decision', function ($decision, array $subject) {
            $this->subjects[] = $subject;

            return $subject['purpose'] === 'reset_complete'
                ? PasswordAuthDecision::deny('user', ['ko' => '회사 계정', 'en' => 'Company account'], '/plugins/test/login/corp?guard='.$subject['guard'])
                : $decision;
        });

        $this->postJson('/api/auth/validate-reset-token', ['token' => $token, 'email' => $user->email, 'redirect_prefix' => 'admin'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'auth.password_auth_disabled')
            ->assertJsonPath('errors.redirect_url', '/plugins/test/login/corp?guard=admin');
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
            'redirect_prefix' => 'admin',
        ])->assertStatus(422)->assertJsonPath('errors.redirect_url', '/plugins/test/login/corp?guard=admin');
        $this->postJson('/api/auth/validate-reset-token', ['token' => $token, 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('errors.redirect_url', '/plugins/test/login/corp?guard=user');

        $guards = array_values(array_map(
            fn (array $subject) => $subject['guard'],
            array_filter($this->subjects, fn (array $subject) => $subject['purpose'] === 'reset_complete'),
        ));
        $this->assertSame(['admin', 'admin', 'user'], $guards);
        $this->assertSame($before, $user->fresh()->getRawOriginal('password'));

        $this->postJson('/api/auth/validate-reset-token', ['token' => $token, 'email' => $user->email, 'redirect_prefix' => 'other'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('redirect_prefix');
    }

    /**
     * 유효한 재설정 링크가 없으면 회원 기준 거절을 드러내지 않는다 — 이메일과 아무 토큰으로 그 회원이 회사 계정 전용인지
     * 알아낼 수 없어야 한다(로그인의 「비밀번호가 맞은 뒤에만」 과 같은 열거 방지).
     *
     * @scenario purpose=reset_complete, token=wrong
     *
     * @effects reset_complete_policy_hidden_without_valid_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재설정_완료_거절은_유효한_링크에서만_드러난다(): void
    {
        $user = $this->corpMember();
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $user->email], ['token' => Hash::make(Str::random(64)), 'created_at' => now()]);
        $before = $user->getRawOriginal('password');

        $this->policy('user', ['reset_complete']);

        $this->postJson('/api/auth/validate-reset-token', ['token' => 'wrong-token', 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonMissingPath('errors.code')
            ->assertJsonMissingPath('errors.method_label');
        $this->postJson('/api/auth/reset-password', [
            'token' => 'wrong-token',
            'email' => $user->email,
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
        ])->assertStatus(422)->assertJsonValidationErrors('token')->assertJsonMissingPath('errors.code');

        $this->assertSame($before, $user->fresh()->getRawOriginal('password'));
    }

    /**
     * 본인 설정(`set_password`)과 관리자 지정(`admin_set_password`)을 목적별로 막는다.
     *
     * @scenario purpose=set_password|admin_set_password
     *
     * @effects set_password_denied, admin_set_password_denied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 본인_설정과_관리자_지정_거절(): void
    {
        $this->policy('user', ['set_password', 'admin_set_password']);
        $user = $this->corpMember();
        $before = $user->getRawOriginal('password');

        $this->withToken($this->issueToken($user))->putJson('/api/me/password', [
            'current_password' => 'Passw0rd!old',
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
        ])->assertForbidden()->assertJsonPath('errors.code', 'auth.password_auth_disabled');

        $this->app['auth']->forgetGuards();
        $admin = $this->makeAdmin(['email' => 'p.admin.'.Str::lower(Str::random(6)).'@example.test', 'is_super' => true]);
        $this->withToken($this->issueToken($admin))->putJson('/api/admin/users/'.$user->uuid, [
            'name' => $user->name,
            'email' => $user->email,
            'role_ids' => $user->roles()->pluck('roles.id')->all(),
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
        ])->assertForbidden()->assertJsonPath('errors.code', 'auth.password_auth_disabled');

        $this->assertSame($before, $user->fresh()->getRawOriginal('password'));
        $purposes = array_column($this->subjects, 'purpose');
        $this->assertContains('set_password', $purposes);
        $this->assertContains('admin_set_password', $purposes);
    }

    /**
     * 비밀번호 인증이 막힌 회원에게는 만료 요구가 없고, 로그인 중 변경 관문도 막힌다.
     *
     * @scenario purpose=login_password_change, expiry=on
     *
     * @effects expiry_requirement_null_for_denied, login_password_change_denied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 막힌_회원의_만료_요구는_없다(): void
    {
        $this->enableExpiry();
        $this->policy('user', ['login_password_change']);
        $user = $this->corpMember();
        $user->forceFill(['password_changed_at' => now()->subDays(100)])->save();

        $this->assertNull(app(PasswordExpiryService::class)->loginRequirement($user->fresh()));
    }

    /**
     * 필터가 DTO 가 아닌 값을 돌려주면 허용하고 경고를 남긴다.
     *
     * @scenario filter=invalid_value
     *
     * @effects invalid_filter_value_allows_with_warning
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이상한_필터_값은_허용하고_경고한다(): void
    {
        Log::spy();
        HookManager::addFilter('core.auth.password_auth_decision', fn () => 'nope');

        $decision = app(PasswordAuthPolicyService::class)->decide(['identifier' => 'a@b.test', 'purpose' => 'login']);

        $this->assertTrue($decision->allowed);
        Log::shouldHaveReceived('warning')->once();
    }
}
