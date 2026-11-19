<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Scim;

use App\Models\User;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoScimGroup;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimSchema;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * SCIM `/Groups` — 생성, 이름 중복 409, 소속 PATCH(add, remove 필터 경로, replace), 그룹 출처 `scim` 이면 역할 매핑 재적용, 삭제.
 *
 * @since 1.0.0
 */
class ScimGroupsTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 그룹 출처 scim + 「인사팀 → hr_manager」 IdP 와 회원 둘을 준비합니다.
     *
     * @return array{0: IdentityProvider, 1: string, 2: User, 3: SsoAccount, 4: User, 5: SsoAccount} 준비물
     */
    private function prepare(): array
    {
        $this->role('user');
        $this->role('hr_manager');
        $idp = $this->makeIdp(['group_source' => 'scim', 'role_mapping' => [['group' => '인사팀', 'role' => 'hr_manager']]]);
        $token = $this->issueScimToken($idp);
        $kim = User::factory()->create();
        $lee = User::factory()->create();
        $kim->roles()->attach($this->role('user')->id);
        $lee->roles()->attach($this->role('user')->id);

        return [$idp, $token, $kim, $this->link($kim, $idp, 'g-kim'), $lee, $this->link($lee, $idp, 'g-lee')];
    }

    /**
     * 생성(소속 포함) → 소속 회원에게 매핑 역할, 같은 이름 409, 목록 필터, excludedAttributes=members.
     *
     * @scenario method=POST, members=1, filter=displayName
     *
     * @effects group_created, role_synced_on_membership, scim_409, members_excluded
     *
     * @return void 반환값 없음
     */
    public function test_create_and_list(): void
    {
        [$idp, $token, $kim, $kimAccount] = $this->prepare();

        $created = $this->scim('POST', $idp, '/Groups', $token, [
            'schemas' => [ScimSchema::GROUP],
            'displayName' => '인사팀',
            'externalId' => 'grp-hr',
            'members' => [['value' => (string) $kimAccount->id], ['value' => '999999']],
        ])->assertStatus(201)->assertJsonPath('displayName', '인사팀');

        $this->assertSame([(string) $kimAccount->id], array_column($created->json('members'), 'value'));
        $this->assertContains('hr_manager', $this->roleIds($kim));

        $this->scim('POST', $idp, '/Groups', $token, ['displayName' => '인사팀'])->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');

        $list = $this->scim('GET', $idp, '/Groups?filter='.rawurlencode('displayName eq "인사팀"').'&excludedAttributes=members', $token)->assertOk();
        $list->assertJsonPath('totalResults', 1);
        $this->assertArrayNotHasKey('members', $list->json('Resources.0'));
    }

    /**
     * 소속 PATCH — add, remove(`members[value eq "…"]`), replace. 빠진 회원은 매핑 역할을 잃는다.
     *
     * @scenario op=add|remove_filter|replace
     *
     * @effects members_changed, role_removed_on_leave
     *
     * @return void 반환값 없음
     */
    public function test_patch_members(): void
    {
        [$idp, $token, $kim, $kimAccount, $lee, $leeAccount] = $this->prepare();
        $id = $this->scim('POST', $idp, '/Groups', $token, ['displayName' => '인사팀'])->json('id');

        $this->scim('PATCH', $idp, '/Groups/'.$id, $token, [
            'schemas' => [ScimSchema::PATCH_OP],
            'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => (string) $kimAccount->id], ['value' => (string) $leeAccount->id]]]],
        ])->assertOk();
        $this->assertContains('hr_manager', $this->roleIds($kim));
        $this->assertContains('hr_manager', $this->roleIds($lee));

        $this->scim('PATCH', $idp, '/Groups/'.$id, $token, [
            'schemas' => [ScimSchema::PATCH_OP],
            'Operations' => [['op' => 'Remove', 'path' => 'members[value eq "'.$kimAccount->id.'"]']],
        ])->assertOk();
        $this->assertNotContains('hr_manager', $this->roleIds($kim));

        $replaced = $this->scim('PATCH', $idp, '/Groups/'.$id, $token, [
            'schemas' => [ScimSchema::PATCH_OP],
            'Operations' => [['op' => 'replace', 'path' => 'members', 'value' => [['value' => (string) $kimAccount->id]]]],
        ])->assertOk();
        $this->assertSame([(string) $kimAccount->id], array_column($replaced->json('members'), 'value'));
        $this->assertContains('hr_manager', $this->roleIds($kim));
        $this->assertNotContains('hr_manager', $this->roleIds($lee));
    }

    /**
     * 이름 바꾸기(경로 없는 값 객체)와 삭제 — 삭제하면 소속 회원의 매핑 역할이 회수되고 소속 행이 사라진다.
     *
     * @scenario op=rename, method=DELETE
     *
     * @effects role_follows_rename, group_and_members_deleted
     *
     * @return void 반환값 없음
     */
    public function test_rename_and_delete(): void
    {
        [$idp, $token, $kim, $kimAccount] = $this->prepare();
        $id = $this->scim('POST', $idp, '/Groups', $token, ['displayName' => '인사팀', 'members' => [['value' => (string) $kimAccount->id]]])->json('id');

        $this->scim('PATCH', $idp, '/Groups/'.$id, $token, [
            'schemas' => [ScimSchema::PATCH_OP],
            'Operations' => [['op' => 'replace', 'value' => ['displayName' => '총무팀']]],
        ])->assertOk()->assertJsonPath('displayName', '총무팀');
        $this->assertNotContains('hr_manager', $this->roleIds($kim));

        $this->scim('PATCH', $idp, '/Groups/'.$id, $token, [
            'schemas' => [ScimSchema::PATCH_OP],
            'Operations' => [['op' => 'replace', 'path' => 'displayName', 'value' => '인사팀']],
        ])->assertOk();
        $this->assertContains('hr_manager', $this->roleIds($kim));

        $this->scim('DELETE', $idp, '/Groups/'.$id, $token)->assertNoContent();
        $this->assertNull(SsoScimGroup::query()->find($id));
        $this->assertNotContains('hr_manager', $this->roleIds($kim));
        $this->scim('GET', $idp, '/Groups/'.$id, $token)->assertStatus(404);
    }
}
