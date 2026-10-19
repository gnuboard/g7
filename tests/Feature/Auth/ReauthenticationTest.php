<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\ReauthenticationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 비밀번호 확인 관문의 이메일 인증번호 대체 (#40 K9 · R9)
 *
 * 비밀번호가 없는 회원(외부 인증 전용)은 비밀번호 확인 관문 3곳(마이페이지 정보 수정 진입 · 앱 키 재생성 · 관리자 본인
 * 정보 수정)을 이메일 인증번호로 통과한다 — 코어 본인인증(목적 `sensitive_action`)으로 받은 `verification_token` 을
 * 관문 요청에 싣는다. 토큰은 본인 것이어야 하고 한 번만 쓸 수 있다. 비밀번호 회원은 종전대로 비밀번호로 통과한다.
 */
class ReauthenticationTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 메일 발송을 막고 로그인 시도 제한을 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
    }

    /**
     * 비밀번호 없는 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function passwordless(): User
    {
        return $this->makeMember([
            'email' => 'reauth.'.Str::lower(Str::random(8)).'@example.test',
            'password' => null,
            'password_changed_at' => null,
        ]);
    }

    /**
     * 회원 본인의 재확인(sensitive_action) 인증을 마치고 토큰을 돌려줍니다.
     *
     * @param  User  $user  회원
     * @return string 본인 확인 토큰
     */
    private function verifiedToken(User $user): string
    {
        $provider = new TestIdentityProvider(app(IdentityVerificationLogRepositoryInterface::class));
        $challenge = $provider->requestChallenge($user, ['purpose' => 'sensitive_action']);

        return (string) $provider->verify($challenge->id, ['code' => TestIdentityProvider::FIXED_CODE])->claims['verification_token'];
    }

    /**
     * 토큰으로 요청합니다.
     *
     * @param  User  $user  회원
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  주소
     * @param  array<string, mixed>  $data  본문
     * @return TestResponse 응답
     */
    private function as(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->issueToken($user))->json($method, $uri, $data);
    }

    /**
     * 비밀번호 회원은 비밀번호로, 비밀번호 없는 회원은 본인 인증 토큰으로 정보 수정 관문을 통과한다.
     *
     * @scenario gate=verify_password, password=set|none, method=password|token
     *
     * @effects password_holder_passes_with_password, passwordless_passes_with_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정보_수정_관문은_비밀번호_또는_인증번호로_통과한다(): void
    {
        $holder = $this->makeMember(['email' => 'reauth.holder.'.Str::lower(Str::random(6)).'@example.test']);
        $this->as($holder, 'POST', '/api/me/verify-password', ['password' => 'Passw0rd!old'])->assertOk();
        $this->as($holder, 'POST', '/api/me/verify-password', ['password' => 'wrong'])->assertStatus(401);

        $passwordless = $this->passwordless();
        $this->as($passwordless, 'POST', '/api/me/verify-password', ['password' => 'x'])->assertStatus(422);
        $this->as($passwordless, 'POST', '/api/me/verify-password', ['verification_token' => $this->verifiedToken($passwordless)])->assertOk();
    }

    /**
     * 인증 토큰은 한 번만 쓸 수 있고 다른 회원의 토큰은 통하지 않는다.
     *
     * @scenario gate=verify_password, token=reused|other_user
     *
     * @effects token_single_use, token_bound_to_user
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 토큰은_한_번만_본인만_쓴다(): void
    {
        $user = $this->passwordless();
        $token = $this->verifiedToken($user);

        $this->as($user, 'POST', '/api/me/verify-password', ['verification_token' => $token])->assertOk();
        $this->as($user, 'POST', '/api/me/verify-password', ['verification_token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', __('user.reauthentication_invalid'));

        $other = $this->passwordless();
        $this->as($user, 'POST', '/api/me/verify-password', ['verification_token' => $this->verifiedToken($other)])
            ->assertStatus(422);
    }

    /**
     * 다른 목적(가입 등)으로 받은 인증 토큰은 재확인에 쓰지 못한다.
     *
     * @scenario token=wrong_purpose
     *
     * @effects token_bound_to_sensitive_action
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 다른_목적의_토큰은_통하지_않는다(): void
    {
        $user = $this->passwordless();
        $provider = new TestIdentityProvider(app(IdentityVerificationLogRepositoryInterface::class));
        $challenge = $provider->requestChallenge($user, ['purpose' => 'signup']);
        $token = (string) $provider->verify($challenge->id, ['code' => TestIdentityProvider::FIXED_CODE])->claims['verification_token'];

        $this->assertSame(ReauthenticationService::TOKEN_INVALID, app(ReauthenticationService::class)->check($user, ['verification_token' => $token]));
    }

    /**
     * 앱 키 재생성 관문 — 비밀번호 없는 최고관리자가 비밀번호를 보내면 「비밀번호 미설정」, 남의 토큰이면 거절한다.
     *
     * @scenario gate=regenerate_app_key, password=none, method=password|other_token
     *
     * @effects app_key_gate_reports_not_set, app_key_gate_rejects_other_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 앱_키_재생성_관문도_같은_판정을_쓴다(): void
    {
        $super = $this->superAdmin();

        $this->as($super, 'POST', '/api/admin/settings/regenerate-app-key', ['password' => 'anything'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('user.password_not_set'));

        $this->as($super, 'POST', '/api/admin/settings/regenerate-app-key', ['verification_token' => $this->verifiedToken($this->passwordless())])
            ->assertStatus(422)
            ->assertJsonPath('message', __('user.reauthentication_invalid'));

        // 위 두 요청이 422 까지 갔다 = 최고관리자 관문을 통과했다. 코어에 최고관리자 역할이 없어도 통과해야 한다.
        $this->assertFalse(Role::where('identifier', 'super_admin')->exists());

        // 통과 판정은 서비스로 잰다 — 실제 재생성은 환경 파일을 다시 쓰므로 여기서 하지 않는다.
        $this->assertSame(
            ReauthenticationService::PASSED,
            app(ReauthenticationService::class)->check($super, ['verification_token' => $this->verifiedToken($super)]),
        );
    }

    /**
     * 앱 키 재생성은 최고관리자 표지(`is_super`)로 판정한다 — 최고관리자가 아닌 관리자는 확인 단계 전에 403,
     * 라우트에는 최고관리자 관문이 붙어 있고 존재하지 않는 역할 관문은 없다.
     *
     * @scenario gate=regenerate_app_key, actor=admin, password=set, method=password
     *
     * @effects app_key_gate_rejects_non_super_admin, app_key_route_carries_super_admin_gate
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 앱_키_재생성은_최고관리자만_한다(): void
    {
        $admin = $this->makeAdmin(['email' => 'reauth.notsuper.'.Str::lower(Str::random(6)).'@example.test']);
        $this->assertFalse($admin->isSuperAdmin());
        $this->assertTrue($admin->hasPermission('core.settings.update'));

        $this->as($admin, 'POST', '/api/admin/settings/regenerate-app-key', ['password' => 'Passw0rd!old'])
            ->assertStatus(403)
            ->assertJsonPath('message', __('auth.super_admin_required'));

        $middleware = app('router')->getRoutes()->getByName('api.admin.settings.regenerate-app-key')?->gatherMiddleware() ?? [];
        $this->assertContains('super.admin', $middleware);
        $this->assertNotContains('role:super_admin', $middleware);
    }

    /**
     * 관리자 본인 정보 수정 — 비밀번호 없는 관리자는 현재 비밀번호 대신 본인 인증 토큰으로 비밀번호를 처음 정한다.
     *
     * @scenario gate=admin_self_update, password=none, method=none|current_password|token
     *
     * @effects admin_self_update_requires_token_for_passwordless, admin_self_update_sets_first_password
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_본인_수정은_인증번호로_비밀번호를_처음_정한다(): void
    {
        $admin = $this->makeAdmin([
            'email' => 'reauth.admin.'.Str::lower(Str::random(6)).'@example.test',
            'password' => null,
            'password_changed_at' => null,
        ]);
        $payload = [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_ids' => $admin->roles->pluck('id')->all(),
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ];

        $this->as($admin, 'PUT', '/api/admin/users/'.$admin->uuid, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['verification_token']);
        $this->as($admin, 'PUT', '/api/admin/users/'.$admin->uuid, $payload + ['current_password' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
        $this->assertFalse($admin->fresh()->hasPassword());

        $token = $this->verifiedToken($admin);
        $this->as($admin, 'PUT', '/api/admin/users/'.$admin->uuid, $payload + ['verification_token' => $token])->assertOk();
        $this->assertTrue($admin->fresh()->hasPassword());

        // 같은 토큰은 다시 쓸 수 없다.
        $this->assertSame(ReauthenticationService::TOKEN_INVALID, app(ReauthenticationService::class)->check($admin->fresh(), ['verification_token' => $token]));
    }

    /**
     * 비밀번호 없는 최고관리자를 만듭니다 — 기본 설치와 같은 모양(관리자 역할 + 최고관리자 표지, 별도 역할 없음).
     *
     * @return User 최고관리자
     */
    private function superAdmin(): User
    {
        $user = $this->makeAdmin([
            'email' => 'reauth.super.'.Str::lower(Str::random(6)).'@example.test',
            'password' => null,
            'password_changed_at' => null,
        ]);
        $user->forceFill(['is_super' => true])->save();

        return $user->fresh();
    }
}
