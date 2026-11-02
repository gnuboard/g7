<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Models\IdentityPolicy;
use App\Models\User;
use App\Services\Auth\ReauthenticationService;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 옛 화면(7.1.x 템플릿)의 비밀번호 칸 요청을 본인인증 요구(428)로 받는 호환 처리 (PO 결정 2026-11-02)
 *
 * 비밀번호 없는 회원(외부 인증 전용)을 모르는 옛 화면은 비밀번호 확인 관문에 `{password}` 를, 비밀번호 변경·관리자 본인 수정에
 * `current_password` 를 무엇이든 넣어 보낸다. 서버는 그 값을 쓰지 않고:
 *   - 재확인 관문(정보 수정 진입·앱 키 재생성·관리자 본인 비밀번호 첫 설정)은 422 대신 428 을 돌려준다. 엔진이 인증 창을 열고
 *     확인이 끝나면 `?verification_token=` 을 붙여 같은 본문으로 다시 보낸다 — 이 시험은 그 재요청을 그대로 흉내 낸다.
 *   - 비밀번호 변경·정보 수정은 현재 비밀번호를 버려 새 화면이 보내지 않은 것과 같은 상태로 만든다(본인 확인은 기존 정책).
 * 토큰은 기존 재확인 서비스가 본인·목적 확인 후 한 번만 소비한다. 비밀번호 있는 회원은 종전 그대로다.
 */
class LegacyReauthChallengeTest extends TestCase
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
            'email' => 'legacy.'.Str::lower(Str::random(8)).'@example.test',
            'password' => null,
            'password_changed_at' => null,
        ]);
    }

    /**
     * 비밀번호 없는 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function passwordlessAdmin(): User
    {
        return $this->makeAdmin([
            'email' => 'legacy.admin.'.Str::lower(Str::random(6)).'@example.test',
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
     * 428 응답이 재확인 요구 형식(목적·정책 키·재요청 주소)인지 확인합니다.
     *
     * @param  TestResponse  $response  응답
     * @param  string  $method  재요청 메서드
     * @param  string  $url  재요청 주소
     * @return void 반환값 없음
     */
    private function assertReauthChallenge(TestResponse $response, string $method, string $url): void
    {
        $response->assertStatus(428)
            ->assertJsonPath('error_code', 'identity_verification_required')
            ->assertJsonPath('verification.purpose', 'sensitive_action')
            ->assertJsonPath('verification.policy_key', ReauthenticationService::CHALLENGE_POLICY_KEY)
            ->assertJsonPath('verification.provider_id', null)
            ->assertJsonPath('verification.challenge_start_url', '/api/identity/challenges')
            ->assertJsonPath('verification.return_request.method', $method)
            ->assertJsonPath('verification.return_request.url', $url);
    }

    /**
     * 정보 수정 진입 관문 — 비밀번호 칸 요청은 428, 같은 본문에 본인 토큰을 붙여 다시 보내면 통과, 같은 토큰은 다시 못 쓴다.
     *
     * @scenario gate=verify_password, password=none, method=password, replay=own_token|reused_token
     *
     * @effects legacy_verify_password_gets_428, legacy_replay_with_own_token_passes, legacy_replay_token_single_use
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정보_수정_진입_관문은_428_뒤_토큰_재요청으로_통과한다(): void
    {
        $user = $this->passwordless();
        $body = ['password' => 'anything'];

        $this->assertReauthChallenge($this->as($user, 'POST', '/api/me/verify-password', $body), 'POST', '/api/me/verify-password');

        $token = $this->verifiedToken($user);
        $replay = '/api/me/verify-password?verification_token='.$token;

        $this->as($user, 'POST', $replay, $body)->assertOk()->assertJsonPath('message', __('user.password_verified'));
        $this->as($user, 'POST', $replay, $body)
            ->assertStatus(422)
            ->assertJsonPath('message', __('user.reauthentication_invalid'));
    }

    /**
     * 빈 본문(옛 화면에 코어 호환 심이 붙인 「본인 확인 계속하기」)도 428 로 본인인증을 연다 — 비밀번호 있는 회원의 빈 본문은 종전대로 422.
     *
     * @scenario gate=verify_password, password=none, method=none, replay=own_token
     *
     * @effects legacy_empty_body_gets_428, legacy_replay_with_own_token_passes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 빈_본문의_본인_확인_계속하기도_428_로_연다(): void
    {
        $user = $this->passwordless();

        $this->assertReauthChallenge($this->as($user, 'POST', '/api/me/verify-password', []), 'POST', '/api/me/verify-password');

        $token = $this->verifiedToken($user);
        $this->as($user, 'POST', '/api/me/verify-password?verification_token='.$token, [])->assertOk();

        $holder = $this->makeMember(['email' => 'legacy.empty.'.Str::lower(Str::random(6)).'@example.test']);
        $this->as($holder, 'POST', '/api/me/verify-password', [])->assertStatus(422);
    }

    /**
     * 남의 토큰을 붙인 재요청은 거절한다 (토큰은 그 회원 본인 것만).
     *
     * @scenario gate=verify_password, password=none, method=password, replay=other_token
     *
     * @effects legacy_replay_rejects_other_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 남의_토큰으로는_통과하지_못한다(): void
    {
        $user = $this->passwordless();
        $otherToken = $this->verifiedToken($this->passwordless());

        $this->as($user, 'POST', '/api/me/verify-password?verification_token='.$otherToken, ['password' => 'anything'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('user.reauthentication_invalid'));
    }

    /**
     * 비밀번호 있는 회원은 종전 그대로다 — 맞으면 200, 틀리면 401(428 로 바뀌지 않는다).
     *
     * @scenario gate=verify_password|change_password, password=set, method=password
     *
     * @effects password_holder_contract_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_있는_회원은_종전_그대로다(): void
    {
        $holder = $this->makeMember(['email' => 'legacy.holder.'.Str::lower(Str::random(6)).'@example.test']);

        $this->as($holder, 'POST', '/api/me/verify-password', ['password' => 'Passw0rd!old'])->assertOk();
        $this->as($holder, 'POST', '/api/me/verify-password', ['password' => 'wrong'])->assertStatus(401);

        $this->as($holder, 'PUT', '/api/me/password', [
            'current_password' => 'wrong',
            'password' => 'Second!Pass123',
            'password_confirmation' => 'Second!Pass123',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->assertTrue(Hash::check('Passw0rd!old', $holder->fresh()->password));
    }

    /**
     * 앱 키 재생성 관문 — 비밀번호 없는 최고관리자의 비밀번호 칸 요청은 428, 남의 토큰을 붙인 재요청은 거절한다.
     *
     * 실제 재생성은 환경 파일을 다시 쓰므로 통과 판정은 서비스로 잰다.
     *
     * @scenario gate=regenerate_app_key, password=none, method=password, replay=other_token|own_token
     *
     * @effects legacy_app_key_gets_428, legacy_replay_rejects_other_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 앱_키_재생성_관문도_428_로_받는다(): void
    {
        $super = $this->passwordlessAdmin();
        $super->forceFill(['is_super' => true])->save();
        $super = $super->fresh();

        $this->assertReauthChallenge(
            $this->as($super, 'POST', '/api/admin/settings/regenerate-app-key', ['password' => 'anything']),
            'POST',
            '/api/admin/settings/regenerate-app-key',
        );

        $this->as($super, 'POST', '/api/admin/settings/regenerate-app-key?verification_token='.$this->verifiedToken($this->passwordless()), ['password' => 'anything'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('user.reauthentication_invalid'));

        $this->assertSame(
            ReauthenticationService::PASSED,
            app(ReauthenticationService::class)->check($super, ['password' => 'anything', 'verification_token' => $this->verifiedToken($super)]),
        );
    }

    /**
     * 관리자 본인 수정 — 옛 화면이 현재 비밀번호를 채워 비밀번호를 처음 정하면 428, 토큰 재요청으로 저장되고 토큰은 한 번만 쓴다.
     * 현재 비밀번호를 비워 보낸 요청(새 화면)은 종전대로 토큰 필수 422 다.
     *
     * @scenario gate=admin_self_update, password=none, method=current_password|none, replay=own_token|reused_token
     *
     * @effects legacy_admin_self_update_gets_428, legacy_replay_with_own_token_passes, legacy_replay_token_single_use, new_screen_admin_contract_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_본인_수정은_현재_비밀번호를_버리고_428_로_받는다(): void
    {
        $admin = $this->passwordlessAdmin();
        $uri = '/api/admin/users/'.$admin->uuid;
        $payload = [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_ids' => $admin->roles->pluck('id')->all(),
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ];

        // 새 화면 — 현재 비밀번호 칸이 비어 있다
        $this->as($admin, 'PUT', $uri, $payload + ['current_password' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['verification_token']);

        // 옛 화면 — 현재 비밀번호 칸에 무엇이든 넣었다
        $legacy = $payload + ['current_password' => 'anything'];
        $this->assertReauthChallenge($this->as($admin, 'PUT', $uri, $legacy), 'PUT', $uri);
        $this->assertFalse($admin->fresh()->hasPassword());

        // 다른 입력 오류가 있으면 본인인증보다 그 오류를 먼저 보인다
        $this->as($admin, 'PUT', $uri, ['password_confirmation' => 'Mismatch!123'] + $legacy)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $token = $this->verifiedToken($admin);
        $this->as($admin, 'PUT', $uri.'?verification_token='.$token, $legacy)->assertOk();
        $this->assertTrue(Hash::check('First!Pass123', $admin->fresh()->password));

        $this->assertSame(
            ReauthenticationService::TOKEN_INVALID,
            app(ReauthenticationService::class)->check($admin->fresh(), ['verification_token' => $token]),
        );
    }

    /**
     * 관리자 본인 수정 재요청에 남의 토큰을 붙이면 거절한다.
     *
     * @scenario gate=admin_self_update, password=none, method=current_password, replay=other_token
     *
     * @effects legacy_replay_rejects_other_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_본인_수정_재요청에_남의_토큰은_통하지_않는다(): void
    {
        $admin = $this->passwordlessAdmin();
        $uri = '/api/admin/users/'.$admin->uuid.'?verification_token='.$this->verifiedToken($this->passwordless());

        $this->as($admin, 'PUT', $uri, [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_ids' => $admin->roles->pluck('id')->all(),
            'current_password' => 'anything',
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ])->assertStatus(422)->assertJsonValidationErrors(['verification_token']);

        $this->assertFalse($admin->fresh()->hasPassword());
    }

    /**
     * 비밀번호 첫 설정 — 옛 화면이 보낸 현재 비밀번호는 버린다(422 로 거절하지 않는다). 정보 수정 API 도 같다.
     *
     * @scenario gate=change_password|profile_update, password=none, method=current_password
     *
     * @effects legacy_current_password_dropped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_첫_설정에서_현재_비밀번호는_버린다(): void
    {
        $user = $this->passwordless();

        $this->as($user, 'PUT', '/api/me/password', [
            'current_password' => 'anything',
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ])->assertOk();
        $this->assertTrue(Hash::check('First!Pass123', $user->fresh()->password));

        $other = $this->passwordless();
        $this->as($other, 'PUT', '/api/me', [
            'name' => $other->name,
            'email' => $other->email,
            'current_password' => 'anything',
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ])->assertOk();
        $this->assertTrue($other->fresh()->hasPassword());
    }

    /**
     * 비밀번호 변경 본인인증 정책을 켜면 옛 화면 요청도 그 정책의 428 을 받고, 토큰 재요청으로 비밀번호를 처음 정한다.
     *
     * @scenario gate=change_password, password=none, method=current_password, policy=enabled, replay=own_token
     *
     * @effects legacy_change_password_follows_idv_policy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_변경은_기존_본인인증_정책을_따른다(): void
    {
        $this->seed(IdentityPolicySeeder::class);
        IdentityPolicy::where('key', 'core.profile.password_change')->first()?->update(['enabled' => true]);
        IdentityPolicy::where('key', 'core.profile.contact_change')->first()?->update(['enabled' => false]);

        $user = $this->passwordless();
        $body = [
            'current_password' => 'anything',
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ];

        $this->as($user, 'PUT', '/api/me/password', $body)
            ->assertStatus(428)
            ->assertJsonPath('verification.policy_key', 'core.profile.password_change')
            ->assertJsonPath('verification.return_request.url', '/api/me/password');
        $this->assertFalse($user->fresh()->hasPassword());

        $this->as($user, 'PUT', '/api/me/password?verification_token='.$this->verifiedToken($user), $body)->assertOk();
        $this->assertTrue(Hash::check('First!Pass123', $user->fresh()->password));
    }
}
