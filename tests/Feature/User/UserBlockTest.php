<?php

namespace Tests\Feature\User;

use App\Enums\UserStatus;
use App\Exceptions\CannotModifySuperAdminException;
use App\Extension\HookManager;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 회원 차단과 해제 공개 진입점 `UserService::blockUser()` / `unblockUser()` (#798 K10 · D7 · D21)
 *
 * 차단은 이미 차단된 회원이어도 토큰을 전부 회수한다(프로비저닝이 다시 차단해도 남은 토큰이 없게). 액션 훅 두 개와 활동 로그 두 개가
 * 남는다. 행위자가 없는 시스템 경로도 쓸 수 있고, 최고관리자는 대상이 될 수 없다.
 */
class UserBlockTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 차단 → 토큰 0, 다시 차단해도 새 토큰 회수, 해제, 훅과 활동 로그.
     *
     * @scenario action=block|reblock|unblock, actor=none
     *
     * @effects block_revokes_tokens, reblock_revokes_again, unblock_restores_active, block_hooks_fired, block_activity_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 차단과_해제(): void
    {
        Auth::forgetGuards();
        $fired = [];
        HookManager::addAction('core.user.after_block', function (User $u, array $c) use (&$fired) {
            $fired[] = ['block', $c['source'] ?? null];
        });
        HookManager::addAction('core.user.after_unblock', function (User $u, array $c) use (&$fired) {
            $fired[] = ['unblock', $c['source'] ?? null];
        });

        $user = $this->makeMember(['email' => 'blk.'.Str::lower(Str::random(8)).'@example.test']);
        $this->issueToken($user);
        $service = app(UserService::class);

        $service->blockUser($user, ['reason_key' => 'test.deprovisioned', 'source' => 'test']);
        $this->assertSame(UserStatus::Blocked->value, $user->fresh()->status);
        $this->assertNotNull($user->fresh()->blocked_at);
        $this->assertSame(0, $user->tokens()->count());

        $this->issueToken($user->fresh());
        $service->blockUser($user->fresh(), ['reason_key' => 'test.deprovisioned', 'source' => 'test']);
        $this->assertSame(0, $user->tokens()->count(), '이미 차단이어도 회수한다');

        $service->unblockUser($user->fresh(), ['reason_key' => 'test.reactivated', 'source' => 'test']);
        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
        $this->assertNull($user->fresh()->blocked_at);

        $this->assertSame([['block', 'test'], ['block', 'test'], ['unblock', 'test']], $fired);
        $this->assertSame(2, ActivityLog::query()->where('action', 'user.block')->whereJsonContains('properties->user_uuid', (string) $user->uuid)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'user.unblock')->whereJsonContains('properties->user_uuid', (string) $user->uuid)->count());
    }

    /**
     * 최고관리자는 차단 대상이 될 수 없다.
     *
     * @scenario target=super_admin
     *
     * @effects super_admin_block_refused
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 최고관리자는_차단할_수_없다(): void
    {
        Auth::forgetGuards();
        $super = $this->makeAdmin(['email' => 'blk.super.'.Str::lower(Str::random(8)).'@example.test', 'is_super' => true]);

        $this->expectException(CannotModifySuperAdminException::class);

        try {
            app(UserService::class)->blockUser($super, ['reason_key' => 'test', 'source' => 'test']);
        } finally {
            $this->assertSame(UserStatus::Active->value, $super->fresh()->status);
        }
    }
}
