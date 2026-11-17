<?php

namespace Tests\Unit\Repositories;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 로그인 실패 횟수 증가가 동시 요청에서 사라지지 않는지 검증한다 (KVE 동형 O8).
 *
 * 수정 전: 메모리의 횟수에 1 을 더해 저장해, 같은 회원을 먼저 읽은 두 요청이 서로를 덮어 실패가 덜 세졌다 —
 * 동시 오답으로 5회 잠금을 우회할 수 있었다.
 */
class UserRepositoryFailedAttemptsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 회원을 따로 읽은 두 요청이 각각 1 씩 올리면 2 가 된다 (수정 전 1).
     *
     * @effects failed_attempts_not_lost
     *
     * @return void 반환값 없음
     */
    public function test_concurrent_increments_are_not_lost(): void
    {
        $user = User::factory()->create(['failed_login_attempts' => 0]);
        $first = User::findOrFail($user->id);
        $second = User::findOrFail($user->id);
        $repository = app(UserRepositoryInterface::class);

        $a = $repository->incrementFailedAttempts($first, 5);
        $b = $repository->incrementFailedAttempts($second, 5);

        $this->assertSame(1, $a);
        $this->assertSame(2, $b);
        $this->assertSame(2, (int) $user->fresh()->failed_login_attempts);
        $this->assertSame(2, (int) $second->failed_login_attempts);
    }

    /**
     * 직전 실패가 차단 시간보다 오래됐으면 1 부터 다시 센다 (기존 규칙 유지).
     *
     * @effects stale_failures_restart_from_one
     *
     * @return void 반환값 없음
     */
    public function test_stale_failures_restart_from_one(): void
    {
        $user = User::factory()->create(['failed_login_attempts' => 4, 'last_failed_login_at' => now()->subMinutes(30)]);

        $count = app(UserRepositoryInterface::class)->incrementFailedAttempts($user, 5);

        $this->assertSame(1, $count);
        $this->assertSame(1, (int) $user->fresh()->failed_login_attempts);
    }
}
