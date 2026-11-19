<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 관리자 회원 상세 「SSO」 탭 데이터 — `sso` 키(연결, 추가 속성, 매핑 역할, IdP 이메일과 다름), 권한 없으면 없음.
 *
 * @since 1.0.0
 */
class MemberDetailInjectionTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 회원 상세 응답에 SSO 정보가 실리는지 확인한다.
     *
     * @scenario viewer=accounts_view, link=active+email_differs, attributes=department
     *
     * @effects sso_key_in_detail, email_differs_flag, attributes_listed, mapped_roles
     *
     * @return void 반환값 없음
     */
    public function test_detail_has_sso_section(): void
    {
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember(['email' => 'old@corp798.test']);
        $this->linkAccount($member, $idp, 'D-1', ['email' => 'new@corp798.test', 'linked_via' => 'jit']);
        DB::table('sso_user_attributes')->insert(['user_id' => $member->id, 'idp_id' => $idp->id, 'key' => 'department', 'value' => '인사팀', 'synced_at' => now()]);
        $role = $this->makeRole('sso-mapped-798');
        $member->roles()->attach($role->id);
        DB::table('sso_role_grants')->insert(['user_id' => $member->id, 'role_id' => $role->id, 'idp_id' => $idp->id, 'granted_at' => now()]);

        Sanctum::actingAs($this->makeAdmin(['core.users.read', 'sirsoft-sso.accounts.view']));
        $sso = $this->getJson('/api/admin/users/'.$member->uuid)->assertOk()->json('data.sso');

        $this->assertSame('corp', $sso['accounts'][0]['idp']['key']);
        $this->assertSame('jit', $sso['accounts'][0]['linked_via']);
        $this->assertTrue($sso['accounts'][0]['email_differs']);
        $this->assertSame('department', $sso['attributes'][0]['key']);
        $this->assertSame('인사팀', $sso['attributes'][0]['value']);
        $this->assertSame(['sso-mapped-798'], $sso['mapped_roles']);
        $this->assertStringNotContainsString('D-1', json_encode($sso));
    }

    /**
     * 회원 연결 열람 권한이 없으면 SSO 정보를 싣지 않는지 확인한다.
     *
     * @scenario viewer=no_accounts_view
     *
     * @effects sso_key_absent
     *
     * @return void 반환값 없음
     */
    public function test_hidden_without_permission(): void
    {
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember();
        $this->linkAccount($member, $idp);

        Sanctum::actingAs($this->makeAdmin(['core.users.read']));
        $data = $this->getJson('/api/admin/users/'.$member->uuid)->assertOk()->json('data');

        $this->assertArrayNotHasKey('sso', $data);
    }
}
