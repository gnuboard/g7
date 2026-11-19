<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 관리자 계정 연결 제한(W26) — 관리자, 최고관리자 계정 대상 단건 연결은 최고관리자만, CSV 는 언제나 건너뛴다.
 *
 * @since 1.0.0
 */
class AccountsAdminTargetTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 위임 관리자는 관리자 계정에 연결할 수 없고 최고관리자는 할 수 있는지 확인한다.
     *
     * @scenario target=admin|super, actor=delegate|super
     *
     * @effects delegate_403, super_201
     *
     * @return void 반환값 없음
     */
    public function test_admin_target_needs_super(): void
    {
        $idp = $this->makeIdp('corp');
        $targetAdmin = $this->makeAdmin(['sirsoft-sso.settings.view']);
        $targetSuper = $this->makeSuper();

        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view', 'sirsoft-sso.accounts.provision']));
        foreach ([$targetAdmin, $targetSuper] as $i => $target) {
            $response = $this->postJson('/api/plugins/sirsoft-sso/admin/accounts', ['idp_id' => $idp->id, 'subject' => 'A-'.$i, 'user_id' => $target->id]);
            $response->assertStatus(403);
            $this->assertSame('admin_target_manual_only', $response->json('errors.code'));
        }
        $this->assertDatabaseMissing('sso_accounts', ['user_id' => $targetAdmin->id]);

        Sanctum::actingAs($this->makeSuper());
        $this->postJson('/api/plugins/sirsoft-sso/admin/accounts', ['idp_id' => $idp->id, 'subject' => 'A-9', 'user_id' => $targetAdmin->id])->assertStatus(201);
    }
}
