<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 로그인 시도 제한 / 계정 잠금 통합 테스트.
 *
 * 보안 환경설정의 `login_attempt_enabled / max_login_attempts /
 * login_lockout_time` 키가 실제 인증 흐름에 적용되는지 검증합니다.
 *
 * 시나리오 매트릭스 (axis × axis):
 *  - login_attempt_enabled ∈ {true, false}
 *  - 잠금 상태 ∈ {none, active, expired}
 *  - 자격 증명 ∈ {valid, invalid}
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 환경설정을 테스트 친화적 값으로 강제
        $this->setSecuritySettings([
            'login_attempt_enabled' => true,
            'max_login_attempts' => 3,
            'login_lockout_time' => 5,
            'auth_token_lifetime' => 30,
        ]);

        // RateLimiter 캐시 초기화 — 다른 테스트 잔여 카운트 영향 방지
        Cache::flush();
    }

    /**
     * @test
     */
    public function 비밀번호_3회_연속_실패_후_4회째_시도_시_423_으로_차단된다(): void
    {
        $user = User::factory()->create([
            'email' => 'lockme@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'lockme@example.com',
                'password' => 'wrong-password',
            ]);
            $response->assertStatus(401);
        }

        // 4회째 — 잠금 상태로 423 Locked 응답
        $response = $this->postJson('/api/auth/login', [
            'email' => 'lockme@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(423);
        $response->assertJsonPath('success', false);

        $user->refresh();
        $this->assertNotNull($user->locked_until);
        $this->assertTrue($user->locked_until->isFuture());
    }

    /**
     * @test
     */
    public function 잠금_해제_시각이_지나면_정상_로그인_가능하다(): void
    {
        $user = User::factory()->create([
            'email' => 'expired@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'locked_until' => now()->subMinutes(1), // 이미 만료
            'failed_login_attempts' => 0,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'expired@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $user->refresh();
        $this->assertNull($user->locked_until);
        $this->assertSame(0, (int) $user->failed_login_attempts);
    }

    /**
     * @test
     */
    public function 정상_로그인_성공_시_실패_카운터가_리셋된다(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'failed_login_attempts' => 2,
            'last_failed_login_at' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'reset@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertSame(0, (int) $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertNull($user->last_failed_login_at);
    }

    /**
     * @test
     */
    public function login_attempt_enabled_가_OFF_이면_무제한_시도_허용된다(): void
    {
        $this->setSecuritySettings([
            'login_attempt_enabled' => false,
            'max_login_attempts' => 3,
            'login_lockout_time' => 5,
            'auth_token_lifetime' => 30,
        ]);

        $user = User::factory()->create([
            'email' => 'unlimited@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        // 5번 실패해도 잠금 안 됨
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'unlimited@example.com',
                'password' => 'wrong-password',
            ]);
            $response->assertStatus(401);
        }

        // 정상 비밀번호로 로그인 가능
        $response = $this->postJson('/api/auth/login', [
            'email' => 'unlimited@example.com',
            'password' => 'correct-password',
        ]);
        $response->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->locked_until);
    }

    /**
     * @test
     */
    public function 카운터가_임계값_도달하면_잠금_시각과_함께_카운터가_0으로_리셋된다(): void
    {
        $user = User::factory()->create([
            'email' => 'threshold@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'threshold@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $user->refresh();
        // 잠금 처리 시 카운터 0 으로 리셋 (다음 윈도우 시작점)
        $this->assertSame(0, (int) $user->failed_login_attempts);
        $this->assertNotNull($user->locked_until);
        $this->assertTrue($user->locked_until->isFuture());
    }

    /**
     * @test
     */
    public function 존재하지_않는_이메일은_DB에_카운트_저장되지_않는다(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        // 존재하지 않는 이메일에 대해서는 user 가 없으므로 DB 변경 없음 — IP throttle 이 백업
        $this->assertDatabaseMissing('users', ['email' => 'ghost@example.com']);
    }

    private function setSecuritySettings(array $values): void
    {
        $existing = (array) config('g7_settings.core.security', []);
        config(['g7_settings.core.security' => array_merge($existing, $values)]);
    }
}
