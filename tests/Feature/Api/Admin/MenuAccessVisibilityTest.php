<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

/**
 * 역할에 지정한 메뉴가 실제로 보이는 경로 — 사이드바와 메뉴 관리 목록 (7.1.0 실측 후속)
 *
 * ① 사이드바(`GET /api/admin/menus/active`)는 「내가 접근 가능한 메뉴」 조회라 메뉴 관리 조회
 *    권한(core.menus.read) 없이 관리자 영역 접근만으로 받는다. 역할 폼 「접근 가능 메뉴」로 메뉴를
 *    지정받은 역할이 그 권한이 없다는 이유로 사이드바가 통째로 비던 결함(실측 O-D1)의 회귀 가드이며,
 *    응답이 자기 역할의 메뉴로만 좁혀지는지(다른 역할 메뉴 비노출) 보안 대조를 함께 둔다.
 * ② 역할이 0개인 메뉴는 메뉴 수정 권한을 전체 범위로 가진 관리자의 관리 목록에 나와 「지정된 역할
 *    없음 — 관리자만 복구 가능」 표시와 복구 경로에 닿을 수 있어야 한다(실측 O-D7). 범위 제한·읽기
 *    전용 운영자에게는 종전대로 나오지 않는다.
 *
 * 시나리오 SSoT: tests/scenarios/role-menu-bulk-assignment.yaml
 */
#[Group('core')]
#[Group('admin')]
class MenuAccessVisibilityTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    /**
     * 주어진 회원으로 GET 요청을 보냅니다.
     *
     * @param  User  $actor  요청자
     * @param  string  $uri  요청 경로
     * @return TestResponse 응답
     */
    private function getAs(User $actor, string $uri): TestResponse
    {
        return $this->actingAs($actor)->getJson($uri);
    }

    /**
     * 메뉴 관리 권한 없이 대시보드 권한만 가진 역할과 그 역할의 회원을 만듭니다.
     *
     * @return array{0: User, 1: Role} [회원, 역할]
     */
    private function dashboardOnlyMember(): array
    {
        $role = $this->roleWith(['core.dashboard.read' => null]);
        $user = User::factory()->create();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return [$user->fresh(), $role];
    }

    /**
     * 메뉴에서 역할을 모두 떼어 역할 0개 메뉴로 만듭니다.
     *
     * @param  Menu  $menu  대상 메뉴
     * @return Menu 역할이 없는 메뉴
     */
    private function stripRoles(Menu $menu): Menu
    {
        $menu->roles()->detach();

        return $menu->fresh();
    }

    /**
     * 관리 목록 응답에서 최상위 행을 슬러그로 찾습니다.
     *
     * @param  TestResponse  $response  목록 응답
     * @param  string  $slug  찾을 슬러그
     * @return array<string, mixed>|null 행 (없으면 null)
     */
    private function rowOf(TestResponse $response, string $slug): ?array
    {
        return collect($response->json('data.data'))->firstWhere('slug', $slug);
    }

    /**
     * 메뉴 관리 조회 권한이 없는 역할도 자기 역할에 지정된 메뉴를 사이드바로 받고,
     * 응답은 자기 역할의 메뉴로만 좁혀진다 — 다른 역할에만 붙은 상위·하위 메뉴는 나오지 않는다.
     *
     * @effects sidebar_without_menu_read_permission, sidebar_limited_to_own_roles
     *
     * @return void 반환값 없음
     */
    public function test_sidebar_returns_only_own_role_menus_without_menus_read(): void
    {
        [$viewer, $viewerRole] = $this->dashboardOnlyMember();
        $otherRole = $this->roleWith(['core.dashboard.read' => null]);

        $own = $this->menu([], [$viewerRole]);
        $ownChild = $this->menu(['parent_id' => $own->id], [$viewerRole]);
        $foreignChild = $this->menu(['parent_id' => $own->id], [$otherRole]);
        $foreign = $this->menu([], [$otherRole]);

        $response = $this->getAs($viewer, '/api/admin/menus/active');

        $response->assertOk();
        $top = collect($response->json('data'));
        $this->assertSame([$own->id], $top->pluck('id')->all());
        $this->assertSame([$ownChild->id], collect($top->first()['children'] ?? [])->pluck('id')->all());
        $this->assertNotContains($foreign->id, $top->pluck('id')->all());
        $this->assertNotContains($foreignChild->id, collect($top->first()['children'] ?? [])->pluck('id')->all());
    }

    /**
     * 사이드바와 달리 메뉴 관리 화면의 목록·계층·상세는 종전대로 core.menus.read 를 요구하고,
     * 관리자 영역에 들어올 수 없는 회원·비로그인은 사이드바도 받지 못한다.
     *
     * @effects menu_management_still_requires_menus_read
     *
     * @return void 반환값 없음
     */
    public function test_menu_management_endpoints_and_non_admin_still_rejected(): void
    {
        [$viewer, $viewerRole] = $this->dashboardOnlyMember();
        $menu = $this->menu([], [$viewerRole]);

        $this->getAs($viewer, '/api/admin/menus')->assertForbidden();
        $this->getAs($viewer, '/api/admin/menus/hierarchy')->assertForbidden();
        $this->getAs($viewer, '/api/admin/menus/'.$menu->id)->assertForbidden();

        $member = User::factory()->create();
        $this->getAs($member, '/api/admin/menus/active')->assertForbidden();
    }

    /**
     * 역할이 0개인 메뉴(상위·하위)는 메뉴 수정 권한을 전체 범위로 가진 관리자의 관리 목록에
     * `role_ids: []` 로 나온다 — 화면의 「역할 없음」·「지정된 역할 없음」 표시와 복구 경로.
     *
     * @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=parent_with_children
     *
     * @effects roleless_menu_visible_to_full_scope_manager
     *
     * @return void 반환값 없음
     */
    public function test_roleless_menus_listed_for_full_scope_manager(): void
    {
        $actor = $this->actor('super_admin');
        $roleless = $this->stripRoles($this->menu());
        $rolelessChild = $this->stripRoles($this->menu(['parent_id' => $roleless->id]));
        $assigned = $this->menu();
        $rolelessUnderAssigned = $this->stripRoles($this->menu(['parent_id' => $assigned->id]));

        $response = $this->getAs($actor, '/api/admin/menus');

        $response->assertOk();
        $row = $this->rowOf($response, $roleless->slug);
        $this->assertNotNull($row, '역할 0개 최상위 메뉴가 목록에 없다');
        $this->assertSame([], $row['role_ids']);
        $this->assertContains($rolelessChild->id, collect($row['children'])->pluck('id')->all());
        $this->assertContains($rolelessUnderAssigned->id, collect($this->rowOf($response, $assigned->slug)['children'])->pluck('id')->all());
    }

    /**
     * 메뉴 수정 권한이 범위 제한(본인)인 운영자와 읽기 전용 운영자에게는 역할 0개 메뉴가
     * 종전대로 보이지 않는다 — 자기 역할이 붙은 메뉴만 보는 정책은 그대로다.
     *
     * @scenario entry=menu_bulk_add, actor=scoped_admin, menu_shape=top_level
     *
     * @effects roleless_menu_hidden_from_limited_manager
     *
     * @return void 반환값 없음
     */
    public function test_roleless_menus_hidden_from_scoped_and_read_only_managers(): void
    {
        $scoped = $this->actor('scoped_admin');
        $scopedRole = $scoped->roles()->first();
        $own = $this->menu(['created_by' => $scoped->id], [$scopedRole]);
        $roleless = $this->stripRoles($this->menu(['created_by' => $scoped->id]));

        $slugs = collect($this->getAs($scoped, '/api/admin/menus')->assertOk()->json('data.data'))->pluck('slug')->all();
        $this->assertContains($own->slug, $slugs);
        $this->assertNotContains($roleless->slug, $slugs);

        $reader = User::factory()->create();
        $readerRole = $this->roleWith(['core.menus.read' => null]);
        $reader->roles()->attach($readerRole->id, ['assigned_at' => now()]);
        $this->menu([], [$readerRole]);

        $readerSlugs = collect($this->getAs($reader->fresh(), '/api/admin/menus')->assertOk()->json('data.data'))->pluck('slug')->all();
        $this->assertNotContains($roleless->slug, $readerSlugs);
    }

    /**
     * 역할 0개 메뉴를 전체 범위 관리자가 저장하면 관리자 역할이 다시 붙어 복구된다
     * (표시가 약속하는 「관리자 복구」 경로가 실제로 닫히지 않음을 함께 확인).
     *
     * @effects roleless_menu_visible_to_full_scope_manager, update_menu_keeps_admin_role
     *
     * @return void 반환값 없음
     */
    public function test_full_scope_manager_restores_roleless_menu_by_saving(): void
    {
        $actor = $this->actor('super_admin');
        $roleless = $this->stripRoles($this->menu());

        $this->actingAs($actor)->putJson('/api/admin/menus/'.$roleless->id, [
            'name' => ['ko' => '복구 메뉴', 'en' => 'Restored Menu'],
            'slug' => $roleless->slug,
            'url' => $roleless->url,
            'icon' => $roleless->icon,
            'roles' => [],
        ])->assertOk();

        $this->assertSame(['admin'], $this->roleIdentifiersOf($roleless));
    }
}
