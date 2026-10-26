<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Provisioning;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Services\Provisioning\DeprovisionService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 최고관리자 퇴사(W33) — 코어가 차단을 거절하므로 토큰 전량 회수 + 연결 비활성 + 알림 훅만. N일 뒤 탈퇴는 건너뛰고 한 번만 알린다.
 *
 * @since 1.0.0
 */
class SuperAdminDeprovisionTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 최고관리자는 차단되지 않고 토큰만 사라진다. 알림 훅 1회.
     *
     * @scenario target=super_admin
     *
     * @effects tokens_zero, member_not_blocked, super_admin_hook_once
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_tokens_revoked_without_block(): void
    {
        $idp = $this->makeIdp();
        $super = User::factory()->create(['is_super' => true]);
        $account = $this->link($super, $idp, 'super-1');
        $this->tokens($super, 2);
        $hooks = [];
        HookManager::addAction(DeprovisionService::ACTION_SUPER_ADMIN_DEPROVISIONED, function ($user, $acc, $source) use (&$hooks) {
            $hooks[] = [$user->id, $acc->id, $source];
        });

        $result = app(DeprovisionService::class)->deprovision($account, 'scim');

        $this->assertTrue($result['super_admin']);
        $this->assertFalse($result['blocked']);
        $super->refresh();
        $this->assertSame(UserStatus::Active->value, $super->status);
        $this->assertSame(0, $super->tokens()->count());
        $this->assertFalse($account->fresh()->active);
        $this->assertSame([[$super->id, $account->id, 'scim']], $hooks);
    }
}
