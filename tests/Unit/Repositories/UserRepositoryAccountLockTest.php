<?php

namespace Tests\Unit\Repositories;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 계정 잠금 Repository 단위 테스트 (C1)
 *
 * 보안 환경설정의 `login_lockout_time = 0` 은 관리자 UI 가 "무한대" 로 안내하는 값입니다.
 * 수정 전에는 `max(1, $minutes)` 로 0 이 1 분으로 승격되어 영구 잠금이 1 분 뒤 자동 해제됐습니다.
 */
class UserRepositoryAccountLockTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): UserRepositoryInterface
    {
        return app(UserRepositoryInterface::class);
    }

    /**
     * @test
     */
    public function 잠금시간_0_은_영구_잠금으로_기록된다(): void
    {
        $user = User::factory()->create();

        $lockedUntil = $this->repository()->lockAccount($user, 0);

        $user->refresh();

        // 영구 잠금은 해제 시각이 없다 (NULL = 잠금 없음 규약과 컬럼으로 분리)
        $this->assertNull($lockedUntil);
        $this->assertNull($user->locked_until);
        $this->assertTrue((bool) $user->locked_permanently);
        $this->assertTrue($this->repository()->isLocked($user));
    }

    /**
     * @test
     */
    public function 영구_잠금은_시간이_지나도_해제되지_않는다(): void
    {
        $user = User::factory()->create();
        $this->repository()->lockAccount($user, 0);

        $this->travel(10)->years();

        $this->assertTrue($this->repository()->isLocked($user->fresh()));
    }

    /**
     * @test
     */
    public function 로그인_시도_초기화는_영구_잠금도_해제한다(): void
    {
        $user = User::factory()->create();
        $this->repository()->lockAccount($user, 0);

        $this->repository()->resetLoginAttempts($user->fresh());

        $user->refresh();
        $this->assertFalse((bool) $user->locked_permanently);
        $this->assertNull($user->locked_until);
        $this->assertFalse($this->repository()->isLocked($user));
    }

    /**
     * @test
     */
    public function 유한_잠금은_기존과_동일하게_동작한다(): void
    {
        $user = User::factory()->create();

        $lockedUntil = $this->repository()->lockAccount($user, 5);

        $this->assertNotNull($lockedUntil);
        $this->assertFalse((bool) $user->fresh()->locked_permanently);
        $this->assertTrue($this->repository()->isLocked($user->fresh()));

        $this->travel(6)->minutes();

        $this->assertFalse($this->repository()->isLocked($user->fresh()));
    }

    /**
     * @test
     */
    public function 음수_잠금시간도_영구_잠금으로_처리된다(): void
    {
        $user = User::factory()->create();

        $this->repository()->lockAccount($user, -10);

        $this->assertTrue((bool) $user->fresh()->locked_permanently);
        $this->assertTrue($this->repository()->isLocked($user->fresh()));
    }
}
