<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use App\Services\PasswordReconfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * 마이페이지 비밀번호 확인(`/api/me/verify-password`)이 회원별로 시도를 제한하는지 검증한다 (KVE 동형 L2).
 *
 * 수정 전: 열린 세션만 있으면 회원별 제한 없이 비밀번호를 계속 맞혀 볼 수 있었다(IP 제한만).
 */
class ProfileVerifyPasswordThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd!verify';

    /**
     * 잠금 기준을 3회로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'g7_settings.core.security.max_login_attempts' => 3,
            'g7_settings.core.security.login_lockout_time' => 5,
        ]);
    }

    /**
     * 회원을 만들고 토큰을 돌려줍니다.
     *
     * @return array{0: User, 1: string} 회원과 토큰
     */
    private function member(): array
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        RateLimiter::clear('password-reconfirm:'.$user->id);

        return [$user, $user->createToken('t')->plainTextToken];
    }

    /**
     * 비밀번호 확인을 요청합니다.
     *
     * @param  string  $token  Bearer 토큰
     * @param  string  $password  확인할 비밀번호
     * @return int 응답 상태 코드
     */
    private function verify(string $token, string $password): int
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/me/verify-password', ['password' => $password])->status();
    }

    /**
     * 기준 횟수만큼 틀리면 다음 요청은 계산 없이 429 를 받는다 (수정 전: 401 무한).
     *
     * @effects reconfirm_throttled_per_member
     *
     * @return void 반환값 없음
     */
    public function test_wrong_passwords_are_throttled(): void
    {
        [, $token] = $this->member();

        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->verify($token, 'wrong-'.$i);
        }

        $this->assertSame([401, 401, 401, 429], $statuses);
        $this->assertSame(429, $this->verify($token, self::PASSWORD));
    }

    /**
     * 맞히면 실패 기록이 지워진다.
     *
     * @effects reconfirm_success_clears
     *
     * @return void 반환값 없음
     */
    public function test_success_clears_failures(): void
    {
        [, $token] = $this->member();

        $this->verify($token, 'wrong-a');
        $this->verify($token, 'wrong-b');
        $this->assertSame(200, $this->verify($token, self::PASSWORD));

        $this->assertSame(401, $this->verify($token, 'wrong-c'));
        $this->assertSame(401, $this->verify($token, 'wrong-d'));
    }

    /**
     * 비밀번호를 대조하는 순간 이번 시도가 이미 세어져 있다 — 동시에 보낸 요청들이 같은 남은 횟수를 함께 통과해
     * 기준보다 많이 맞혀 보지 못하게 한다(검증 중 발견, 수정 전: 대조 뒤 실패일 때만 증가).
     *
     * @effects reconfirm_attempt_counted_before_check
     *
     * @return void 반환값 없음
     */
    public function test_attempt_is_counted_before_the_password_check(): void
    {
        [$user] = $this->member();
        $key = 'password-reconfirm:'.$user->id;
        $seen = null;

        Hash::shouldReceive('check')->once()->andReturnUsing(function () use ($key, &$seen) {
            $seen = RateLimiter::attempts($key);

            return true;
        });

        $this->assertTrue(app(PasswordReconfirmationService::class)->attempt($user, self::PASSWORD));
        $this->assertSame(1, $seen, '대조 시점에 이번 시도가 세어져 있지 않으면 동시 요청이 기준을 넘을 수 있다');
        $this->assertSame(0, RateLimiter::attempts($key), '성공하면 기록을 지워야 한다');
    }

    /**
     * 제한은 IP 가 아니라 회원 단위다 — 같은 IP 의 다른 회원은 영향을 받지 않는다.
     *
     * @effects reconfirm_throttled_per_member
     *
     * @return void 반환값 없음
     */
    public function test_limit_is_per_member_not_ip(): void
    {
        [, $first] = $this->member();
        [, $second] = $this->member();

        for ($i = 0; $i < 3; $i++) {
            $this->verify($first, 'wrong-'.$i);
        }

        $this->assertSame(429, $this->verify($first, 'wrong-x'));
        $this->assertSame(200, $this->verify($second, self::PASSWORD));
    }
}
