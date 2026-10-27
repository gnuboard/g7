<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Provisioning;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Services\Provisioning\DeprovisionService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 퇴사 처리 — 차단 + 토큰 0, 겸직(다른 IdP 활성 연결)이면 차단하지 않음, 이미 다른 이유로 차단된 회원은 차단 시각을 남기지 않음.
 *
 * @since 1.0.0
 */
class DeprovisionServiceTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 연결이 하나뿐인 회원은 차단되고 토큰이 모두 사라진다. 훅 1회.
     *
     * @scenario other_active_link=none
     *
     * @effects member_blocked, tokens_zero, blocked_user_at_recorded, after_deprovision_once
     *
     * @return void 반환값 없음
     */
    public function test_single_link_member_is_blocked_and_tokens_revoked(): void
    {
        $idp = $this->makeIdp();
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 'dep-single');
        $this->tokens($user, 3);
        $fired = 0;
        HookManager::addAction(DeprovisionService::ACTION_AFTER_DEPROVISION, function () use (&$fired) {
            $fired++;
        });

        $result = app(DeprovisionService::class)->deprovision($account, 'scim');

        $this->assertTrue($result['blocked']);
        $user->refresh();
        $account->refresh();
        $this->assertSame(UserStatus::Blocked->value, $user->status);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse($account->active);
        $this->assertNotNull($account->deprovisioned_at);
        $this->assertTrue($user->blocked_at->equalTo($account->blocked_user_at));
        $this->assertSame(1, $fired);

        app(DeprovisionService::class)->deprovision($account->refresh(), 'scim');
        $this->assertSame(1, $fired, '이미 비활성인 연결은 훅을 다시 내지 않는다');
    }

    /**
     * 다른 IdP 활성 연결이 있으면(겸직) 회원은 차단하지 않고 그 IdP 세션만 끝낸다.
     *
     * @scenario other_active_link=exists
     *
     * @effects member_kept_active, idp_sessions_ended, other_idp_session_kept
     *
     * @return void 반환값 없음
     */
    public function test_other_active_link_keeps_member(): void
    {
        $a = $this->makeIdp();
        $b = $this->makeIdp();
        $user = User::factory()->create();
        $accountA = $this->link($user, $a, 'dual-a');
        $this->link($user, $b, 'dual-b');
        [$tokenA, $tokenB] = $this->tokens($user, 2);
        $sessionA = SsoSession::query()->create(['idp_id' => $a->id, 'user_id' => $user->id, 'token_id' => $tokenA]);
        $sessionB = SsoSession::query()->create(['idp_id' => $b->id, 'user_id' => $user->id, 'token_id' => $tokenB]);

        $result = app(DeprovisionService::class)->deprovision($accountA, 'scim');

        $this->assertTrue($result['kept_by_other_link']);
        $this->assertFalse($result['blocked']);
        $user->refresh();
        $this->assertSame(UserStatus::Active->value, $user->status);
        $this->assertSame([$tokenB], $user->tokens()->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotNull($sessionA->fresh()->ended_at);
        $this->assertSame(SsoSession::END_DEPROVISIONED, $sessionA->fresh()->end_reason);
        $this->assertNull($sessionB->fresh()->ended_at);
        $this->assertNull($accountA->fresh()->blocked_user_at);
    }

    /**
     * 퇴사 전에 이미 다른 이유로 차단된 회원 — 토큰은 다시 회수하되 차단 시각은 남기지 않는다(재활성 때 풀지 않게).
     *
     * @scenario member_status=already_blocked
     *
     * @effects tokens_zero, blocked_user_at_null
     *
     * @return void 반환값 없음
     */
    public function test_already_blocked_member_is_not_marked_as_blocked_by_link(): void
    {
        $idp = $this->makeIdp();
        $user = User::factory()->create(['status' => UserStatus::Blocked->value, 'blocked_at' => now()->subDay()]);
        $account = $this->link($user, $idp, 'dep-blocked');
        $this->tokens($user, 1);

        $result = app(DeprovisionService::class)->deprovision($account, 'periodic');

        $this->assertFalse($result['blocked']);
        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertNull($account->fresh()->blocked_user_at);
    }
}
