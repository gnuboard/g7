<?php

namespace Tests\Unit\Repositories;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 이메일 인증 기록 Repository 단위 테스트
 *
 * `email_verified_at` 은 일괄 대입 대상이 아니라 `update()` 로는 기록되지 않는다. 외부 인증 가입(소셜 가입)처럼
 * 이메일 소유를 확인한 경로가 이 메서드로 기록한다.
 */
class UserRepositoryEmailVerifiedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 이메일 인증을 기록하고, 이미 기록돼 있으면 처음 시각을 유지한다.
     *
     * @scenario case=repository_mark_twice
     *
     * @effects mark_email_verified_records_once
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이메일_인증을_기록하고_처음_시각을_유지한다(): void
    {
        $repository = app(UserRepositoryInterface::class);
        $user = User::factory()->create(['email_verified_at' => null]);

        $repository->update($user, ['email_verified_at' => now()]);
        $this->assertNull($user->refresh()->email_verified_at, '일괄 대입으로는 기록되지 않는다');

        $this->travelTo(now()->startOfMinute());
        $repository->markEmailVerified($user);
        $first = $user->refresh()->email_verified_at;
        $this->assertNotNull($first);

        $this->travel(5)->minutes();
        $repository->markEmailVerified($user);
        $this->assertTrue($first->equalTo($user->refresh()->email_verified_at), '이미 기록된 시각은 바꾸지 않는다');
    }
}
