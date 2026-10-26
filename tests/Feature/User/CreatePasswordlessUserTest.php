<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 관리자 「비밀번호 없이 만들기」 (#798 K11 · P7)
 *
 * 관리자가 회사 계정으로만 로그인할 회원을 미리 만든다. `create_without_password=true` 면 비밀번호를 받지 않고(`prohibited`) 비밀번호 없는
 * 회원이 된다 — 비밀번호 로그인은 실패하고 재설정 메일로 처음 만들 수 있다. 기존 경로(비밀번호 필수)는 그대로다.
 */
class CreatePasswordlessUserTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 메일을 막고 시도 제한을 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();
    }

    /**
     * 최고관리자 토큰을 만듭니다.
     *
     * @return string 토큰
     */
    private function adminToken(): string
    {
        return $this->issueToken($this->makeAdmin(['email' => 'cpu.admin.'.Str::lower(Str::random(8)).'@example.test', 'is_super' => true]));
    }

    /**
     * 비밀번호 없이 만들면 201 이고 `has_password=false`, 그 회원의 비밀번호 로그인은 실패하며 재설정 메일은 받는다.
     *
     * @scenario mode=passwordless
     *
     * @effects passwordless_created, passwordless_cannot_password_login, passwordless_can_request_reset
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_없이_만든다(): void
    {
        $email = 'cpu.'.Str::lower(Str::random(8)).'@example.test';

        $this->withToken($this->adminToken())->postJson('/api/admin/users', [
            'name' => '사전 등록',
            'email' => $email,
            'create_without_password' => true,
        ])->assertCreated()->assertJsonPath('data.has_password', false);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertNull($user->getRawOriginal('password'));

        // 관리자 요청의 Bearer 헤더·sanctum 기본 가드가 다음 요청에 남지 않게 한다(실서버는 요청마다 새로 시작한다)
        $this->flushHeaders();
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'anything'])->assertUnauthorized();
        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $email)->count());
    }

    /**
     * 비밀번호 없이 만들기와 비밀번호를 함께 보내면 422 다.
     *
     * @scenario mode=passwordless_with_password
     *
     * @effects passwordless_with_password_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 둘_다_보내면_422(): void
    {
        $this->withToken($this->adminToken())->postJson('/api/admin/users', [
            'name' => '사전 등록',
            'email' => 'cpu.'.Str::lower(Str::random(8)).'@example.test',
            'create_without_password' => true,
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    /**
     * 기존 경로는 그대로 — 비밀번호가 없으면 422, 있으면 201 + `has_password=true`.
     *
     * @scenario mode=default
     *
     * @effects default_create_requires_password
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 기존_경로는_그대로다(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->postJson('/api/admin/users', [
            'name' => '일반',
            'email' => 'cpu.'.Str::lower(Str::random(8)).'@example.test',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->withToken($token)->postJson('/api/admin/users', [
            'name' => '일반',
            'email' => 'cpu.'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
        ])->assertCreated()->assertJsonPath('data.has_password', true);
    }
}
