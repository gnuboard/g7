<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 관리자 회원 목록 주입 — 「SSO IdP」 확장 필터와 「부서」, 「직급」 열(페이지당 속성 조회 1번).
 *
 * @since 1.0.0
 */
class MemberListFilterAndColumnsTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * IdP 필터가 그 IdP 와 연결된 회원만 남기는지 확인한다.
     *
     * @scenario filter=ext.sirsoft-sso.idp
     *
     * @effects only_linked_members
     *
     * @return void 반환값 없음
     */
    public function test_idp_filter(): void
    {
        $idp = $this->makeIdp('corp');
        $linked = $this->makeMember();
        $this->linkAccount($linked, $idp);
        $this->makeMember();

        Sanctum::actingAs($this->makeAdmin(['core.users.read', 'sirsoft-sso.accounts.view']));
        $rows = $this->getJson('/api/admin/users?per_page=50&ext_filters[]=sirsoft-sso.idp=corp')->assertOk()->json('data.data');

        $this->assertSame([$linked->uuid], array_column($rows, 'uuid'));
    }

    /**
     * 「SSO 연결 없음」 필터가 회사 계정 연결이 하나도 없는 회원만 남기고, 회원 연결 열람 권한이 없으면 조건을 무시하는지 확인한다.
     *
     * @scenario filter=ext.sirsoft-sso.unlinked, permission=accounts_view|none
     *
     * @effects only_unlinked_members, unlinked_filter_ignored_without_permission
     *
     * @return void 반환값 없음
     */
    public function test_unlinked_filter(): void
    {
        $idp = $this->makeIdp('corp');
        $linked = $this->makeMember();
        $this->linkAccount($linked, $idp);
        $unlinked = $this->makeMember();

        Sanctum::actingAs($this->makeAdmin(['core.users.read', 'sirsoft-sso.accounts.view']));
        $uuids = array_column($this->getJson('/api/admin/users?per_page=100&ext_filters[]=sirsoft-sso.unlinked=1')->assertOk()->json('data.data'), 'uuid');
        $this->assertContains($unlinked->uuid, $uuids);
        $this->assertNotContains($linked->uuid, $uuids);

        Sanctum::actingAs($this->makeAdmin(['core.users.read']));
        $uuids = array_column($this->getJson('/api/admin/users?per_page=100&ext_filters[]=sirsoft-sso.unlinked=1')->assertOk()->json('data.data'), 'uuid');
        $this->assertContains($linked->uuid, $uuids);
    }

    /**
     * 부서, 직급 열 값이 실리고 속성 조회가 페이지당 1번인지 확인한다.
     *
     * @scenario columns=department+title, rows=3
     *
     * @effects row_values, single_attribute_query
     *
     * @return void 반환값 없음
     */
    public function test_columns_single_query(): void
    {
        $idp = $this->makeIdp('corp');
        $members = [$this->makeMember(), $this->makeMember(), $this->makeMember()];
        foreach ($members as $i => $member) {
            $this->linkAccount($member, $idp);
            DB::table('sso_user_attributes')->insert([
                ['user_id' => $member->id, 'idp_id' => $idp->id, 'key' => 'department', 'value' => '부서'.$i, 'synced_at' => now()],
                ['user_id' => $member->id, 'idp_id' => $idp->id, 'key' => 'title', 'value' => '직급'.$i, 'synced_at' => now()],
            ]);
        }

        Sanctum::actingAs($this->makeAdmin(['core.users.read', 'sirsoft-sso.accounts.view']));
        DB::enableQueryLog();
        $rows = $this->getJson('/api/admin/users?per_page=50&ext_filters[]=sirsoft-sso.idp=corp')->assertOk()->json('data.data');
        $attributeQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'sso_user_attributes') && ! str_contains($q['query'], 'information_schema'));
        DB::disableQueryLog();

        $byUuid = array_column($rows, null, 'uuid');
        foreach ($members as $i => $member) {
            $this->assertSame('부서'.$i, $byUuid[$member->uuid]['sso_department']);
            $this->assertSame('직급'.$i, $byUuid[$member->uuid]['sso_title']);
        }
        $this->assertCount(1, $attributeQueries);
    }
}
