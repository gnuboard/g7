<?php

namespace Tests\Feature\User;

use App\Extension\HookManager;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 관리자의 회원 강제 로그아웃 `POST /api/admin/users/{user}/sessions/revoke` (#798 K16 · P9)
 *
 * 대상 회원의 토큰을 전부 지우고 액션 훅과 활동 로그를 남긴다. 회원 수정 권한이 없으면 403, 하위 관리자는 최고관리자를 대상으로 하지 못한다.
 */
class RevokeSessionsTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 최고관리자가 회원을 로그아웃시키면 토큰 0, 훅 1, 활동 로그 1, 대상의 다음 요청 401.
     *
     * @scenario actor=super_admin, target=member
     *
     * @effects revoke_deletes_tokens, revoke_hook_fired, revoke_activity_logged, target_next_request_401
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 회원을_모든_기기에서_로그아웃시킨다(): void
    {
        $fired = [];
        HookManager::addAction('core.user.after_sessions_revoked', function (User $u, ?User $actor) use (&$fired) {
            $fired[] = [$u->id, $actor?->id];
        });
        $admin = $this->makeAdmin(['email' => 'rv.admin.'.Str::lower(Str::random(8)).'@example.test', 'is_super' => true]);
        $member = $this->makeMember(['email' => 'rv.'.Str::lower(Str::random(8)).'@example.test']);
        $memberToken = $this->issueToken($member);
        $this->issueToken($member);

        $this->withToken($this->issueToken($admin))->postJson('/api/admin/users/'.$member->uuid.'/sessions/revoke')->assertOk();

        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame([[$member->id, $admin->id]], $fired);
        $this->assertSame(1, ActivityLog::query()->where('action', 'user.sessions_revoked')->whereJsonContains('properties->user_uuid', (string) $member->uuid)->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($memberToken)->getJson('/api/auth/user')->assertUnauthorized();
    }

    /**
     * 회원 수정 권한이 없는 관리자는 403 이다.
     *
     * @scenario actor=no_permission
     *
     * @effects revoke_requires_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 권한이_없으면_403(): void
    {
        $viewer = $this->makeLimitedAdmin(['core.users.read']);
        $member = $this->makeMember(['email' => 'rv.'.Str::lower(Str::random(8)).'@example.test']);
        $this->issueToken($member);

        $this->withToken($this->issueToken($viewer))->postJson('/api/admin/users/'.$member->uuid.'/sessions/revoke')->assertForbidden();
        $this->assertSame(1, $member->tokens()->count());
    }

    /**
     * 하위 관리자는 최고관리자를 로그아웃시키지 못한다.
     *
     * @scenario actor=delegated_admin, target=super_admin
     *
     * @effects revoke_super_admin_refused
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 최고관리자는_대상이_아니다(): void
    {
        $delegate = $this->makeLimitedAdmin(['core.users.read', 'core.users.update']);
        $super = $this->makeAdmin(['email' => 'rv.super.'.Str::lower(Str::random(8)).'@example.test', 'is_super' => true]);
        $this->issueToken($super);

        $this->withToken($this->issueToken($delegate))->postJson('/api/admin/users/'.$super->uuid.'/sessions/revoke')->assertForbidden();
        $this->assertSame(1, $super->tokens()->count());
    }
}
