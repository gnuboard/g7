<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Roles;

use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Services\RoleSyncService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 역할 동기화의 훅(§7 F-2) — 코어 일괄 역할 변경 훅(`core.user.before/after_bulk_update_roles`)만 나가고 단건 역할 변경 훅은 나가지 않는다.
 * 플러그인 훅 `sirsoft-sso.roles.after_sync` 는 바뀐 역할 identifier 와 매핑한 IdP 로 1회.
 *
 * @since 1.0.0
 */
class BulkRolesHookTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 붙이고 떼는 동기화 한 번씩 — bulk 훅 짝, 단건 훅 0, 플러그인 훅 인자.
     *
     * @scenario change=add|remove
     *
     * @effects bulk_hooks_fired, single_hook_not_fired, after_sync_identifiers, after_sync_idp
     *
     * @return void 반환값 없음
     */
    public function test_only_bulk_hooks_fire(): void
    {
        $this->role('user');
        $this->role('hr_manager');
        $idp = $this->makeIdp(['role_mapping' => [['group' => '인사팀', 'role' => 'hr_manager']]]);
        $user = User::factory()->create();
        $user->roles()->attach($this->role('user')->id);
        $account = $this->link($user, $idp, 'bulk-1');

        $fired = ['before_bulk' => 0, 'after_bulk' => 0, 'single' => 0];
        $sync = [];
        HookManager::addAction('core.user.before_bulk_update_roles', function () use (&$fired) {
            $fired['before_bulk']++;
        });
        HookManager::addAction('core.user.after_bulk_update_roles', function () use (&$fired) {
            $fired['after_bulk']++;
        });
        HookManager::addAction('core.user.after_update_roles', function () use (&$fired) {
            $fired['single']++;
        });
        HookManager::addAction(RoleSyncService::ACTION_AFTER_SYNC, function ($u, $added, $removed, $from = null) use (&$sync) {
            $sync[] = [$u->id, $added, $removed, $from?->key];
        });

        app(RoleSyncService::class)->sync($user, $account, $idp, ['인사팀'], RoleSyncService::TRIGGER_UPDATE);
        app(RoleSyncService::class)->sync($user, $account, $idp, [], RoleSyncService::TRIGGER_UPDATE);

        $this->assertSame(['before_bulk' => 2, 'after_bulk' => 2, 'single' => 0], $fired);
        $this->assertSame([[$user->id, ['hr_manager'], [], $idp->key], [$user->id, [], ['hr_manager'], $idp->key]], $sync);
    }
}
