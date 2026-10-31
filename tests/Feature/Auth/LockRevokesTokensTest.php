<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 잠긴 계정의 토큰 회수 (#798 K13 · P9)
 *
 * 로그인 실패로 계정이 잠기는 순간 그 회원의 토큰이 전부 지워진다 — 잠긴 계정이 이미 받아 둔 토큰으로 계속 쓰지 못한다. 영구 잠금도 같다.
 * 비밀번호 없는 회원은 지금처럼 잠기지 않는다.
 */
class LockRevokesTokensTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 메일을 막고 시도 제한을 켭니다 (5회, 5분).
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
    }

    /**
     * 틀린 비밀번호로 n 회 로그인합니다.
     *
     * @param  User  $user  회원
     * @param  int  $times  횟수
     * @return void 반환값 없음
     */
    private function failLogins(User $user, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-'.$i]);
        }
    }

    /**
     * 5회 실패로 잠기면 토큰이 0 이 되고 그 토큰의 다음 요청은 401, 해제 뒤 다시 로그인된다.
     *
     * @scenario lock=timed
     *
     * @effects lock_revokes_tokens, revoked_token_401, relogin_after_unlock
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 잠기면_토큰이_회수된다(): void
    {
        $user = $this->makeMember(['email' => 'lk.'.Str::lower(Str::random(8)).'@example.test']);
        $token = $this->issueToken($user);

        $this->failLogins($user, 5);

        $this->assertNotNull($user->fresh()->locked_until);
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/user')->assertUnauthorized();

        $this->travel(6)->minutes();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])->assertOk();
        $this->travelBack();
    }

    /**
     * 영구 잠금도 토큰을 회수한다.
     *
     * @scenario lock=permanent
     *
     * @effects permanent_lock_revokes_tokens
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 영구_잠금도_회수한다(): void
    {
        $this->setSecurity(['login_lockout_time' => 0]);
        $user = $this->makeMember(['email' => 'lk.'.Str::lower(Str::random(8)).'@example.test']);
        $this->issueToken($user);

        $this->failLogins($user, 5);

        $this->assertTrue((bool) $user->fresh()->locked_permanently);
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * 비밀번호 없는 회원은 잠기지 않고 토큰도 남는다 (#40 회귀).
     *
     * @scenario password=none
     *
     * @effects passwordless_not_locked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_없는_회원은_잠기지_않는다(): void
    {
        $user = $this->makeMember(['email' => 'lk.'.Str::lower(Str::random(8)).'@example.test']);
        $user->forceFill(['password' => null])->save();
        $this->issueToken($user);

        $this->failLogins($user, 5);

        $this->assertNull($user->fresh()->locked_until);
        $this->assertSame(1, $user->tokens()->count());
    }
}
