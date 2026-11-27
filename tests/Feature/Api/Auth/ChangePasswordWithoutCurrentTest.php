<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 없는 회원의 비밀번호 처음 설정 테스트 (K5③④)
 *
 * 비밀번호가 없는 회원(외부 인증 전용)은 확인할 현재 비밀번호가 없으므로 마이페이지에서 현재 비밀번호 없이 새 비밀번호를
 * 정한다. 옛 화면이 현재 비밀번호를 채워 보내면 그 값은 버리고, 비밀번호가 있는 회원의 규칙(현재 비밀번호 필수)은 그대로다.
 */
class ChangePasswordWithoutCurrentTest extends TestCase
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
     * 비밀번호 없는 회원을 만듭니다 ('user' 역할 포함).
     *
     * @return User 회원
     */
    private function passwordlessMember(): User
    {
        return $this->makeMember([
            'email' => 'pwless.'.Str::lower(Str::random(8)).'@example.test',
            'password' => null,
            'password_changed_at' => null,
        ]);
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
        return $this->withHeader('Authorization', 'Bearer '.$this->issueToken($user))->json($method, $uri, $data);
    }

    /**
     * 비밀번호 없는 회원은 현재 비밀번호 없이 새 비밀번호를 정하고, 그 비밀번호로 로그인된다.
     *
     * @scenario password=none, current_password=absent
     *
     * @effects first_password_set_without_current, password_login_succeeds_after_set, change_date_recorded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_없는_회원은_현재_비밀번호_없이_설정한다(): void
    {
        $user = $this->passwordlessMember();

        $this->as($user, 'PUT', '/api/me/password', [
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasPassword());
        $this->assertNotNull($fresh->password_changed_at);

        // 앞 요청이 바꿔 둔 기본 가드(sanctum)를 되돌린다 — 같은 앱 인스턴스에서 로그인 API 를 이어 부르기 위함.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'First!Pass123'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');
    }

    /**
     * 비밀번호 없는 회원이 현재 비밀번호를 보내면(옛 화면) 그 값은 버리고 보내지 않은 것과 같이 처리한다.
     *
     * @scenario password=none, current_password=present
     *
     * @effects current_password_dropped_for_passwordless
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_없는_회원이_보낸_현재_비밀번호는_버린다(): void
    {
        $user = $this->passwordlessMember();

        $this->as($user, 'PUT', '/api/me/password', [
            'current_password' => 'anything',
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ])->assertOk();

        $this->assertTrue($user->fresh()->hasPassword());
    }

    /**
     * 비밀번호가 있는 회원은 종전대로 현재 비밀번호가 필요하다.
     *
     * @scenario password=set, current_password=absent
     *
     * @effects current_password_required_for_password_holder
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_있는_회원은_현재_비밀번호가_필요하다(): void
    {
        $user = $this->makeMember(['email' => 'pwset.'.Str::lower(Str::random(8)).'@example.test']);

        $this->as($user, 'PUT', '/api/me/password', [
            'password' => 'Second!Pass123',
            'password_confirmation' => 'Second!Pass123',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    }

    /**
     * 정보 수정 API 도 같은 분기를 따른다 — 비밀번호 없는 회원은 현재 비밀번호 없이 새 비밀번호를 함께 저장한다.
     *
     * @scenario password=none|set, path=profile_update
     *
     * @effects profile_update_password_branch
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정보_수정_API_도_같은_분기를_따른다(): void
    {
        $user = $this->passwordlessMember();

        $this->as($user, 'PUT', '/api/me', [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ])->assertOk();
        $this->assertTrue($user->fresh()->hasPassword());

        $holder = $this->makeMember(['email' => 'pwset.'.Str::lower(Str::random(8)).'@example.test']);
        $this->as($holder, 'PUT', '/api/me', [
            'name' => $holder->name,
            'email' => $holder->email,
            'password' => 'Second!Pass123',
            'password_confirmation' => 'Second!Pass123',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    }

    /**
     * 비밀번호 없는 회원이 정보 수정 진입 관문에 비밀번호를 보내면(옛 화면) 본인인증을 요구한다(428).
     *
     * @scenario password=none, gate=verify_password, method=password
     *
     * @effects verify_password_challenges_passwordless
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_없는_회원의_비밀번호_확인은_본인인증을_요구한다(): void
    {
        $user = $this->passwordlessMember();

        $this->as($user, 'POST', '/api/me/verify-password', ['password' => 'anything'])
            ->assertStatus(428)
            ->assertJsonPath('error_code', 'identity_verification_required')
            ->assertJsonPath('verification.purpose', 'sensitive_action');
    }
}
