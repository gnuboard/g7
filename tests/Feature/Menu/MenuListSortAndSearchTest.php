<?php

namespace Tests\Feature\Menu;

use App\Enums\MenuPermissionType;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 메뉴 목록 정렬·검색 회귀 테스트 (#492 D-22 · D-23).
 *
 * 두 결함이 함께 있었다.
 *
 *  1. **정렬 무시(D-22)** — 컨트롤러가 "필터가 없으면" `filters` 를 받지 않는 메서드로
 *     분기해 `sort_by`/`sort_order` 를 통째로 버렸다. 필터를 하나라도 걸어야 정렬이 먹었다.
 *  2. **한글 검색 0건(D-23)** — `name` 은 다국어 JSON 컬럼이고 유니코드 이스케이프
 *     (`{"ko":"대시보드"}`)로 저장된다. 컬럼 전체에 LIKE 를 걸면 한글
 *     검색어가 절대 매칭되지 않는다. 로케일 JSON 경로(`name->ko`)로 검색해야 한다.
 *
 * **HTTP 엔드포인트로 검증한다.** Service 를 직접 부르면 컨트롤러의 분기를 타지 않아
 * D-22 가 남아 있어도 통과한다(실제로 처음 작성했을 때 그렇게 통과했다).
 *
 * 부하 설계: 관리자 1명 + 메뉴 3건을 INSERT 로 심고 한 메서드에서 두 결함을 모두 단언한다.
 */
class MenuListSortAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private int $roleId;

    protected function setUp(): void
    {
        parent::setUp();

        // permission 미들웨어는 권한 행이 실제로 존재해야 통과한다(is_super 만으로는 403).
        // 이 테스트가 쓰는 읽기 권한 1건만 심어 픽스처를 최소로 유지한다.
        $role = Role::create([
            'identifier' => 'menu-reader',
            'name' => ['ko' => '메뉴 조회자', 'en' => 'Menu Reader'],
            'description' => ['ko' => '메뉴 조회 전용', 'en' => 'Menu read only'],
            'is_active' => true,
        ]);

        $permission = Permission::create([
            'identifier' => 'core.menus.read',
            'name' => ['ko' => '메뉴 조회', 'en' => 'Read Menus'],
            'description' => ['ko' => '메뉴를 조회할 수 있습니다.', 'en' => 'Can read menus.'],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'type' => 'admin',
        ]);

        $role->permissions()->attach($permission->id);

        $this->roleId = $role->id;

        $this->adminUser = User::factory()->create(['is_super' => true]);
        $this->adminUser->roles()->attach($role->id);
    }

    /**
     * 정렬·검색 판정용 메뉴를 INSERT 1회로 심습니다.
     *
     * `order` 순서와 이름 순서를 **일부러 어긋나게** 둔다 — 두 순서가 같으면 정렬이
     * 무시돼도 기본 정렬(order)과 결과가 같아 결함을 통과시킨다.
     */
    private function seedMenus(): void
    {
        $rows = [
            ['ko' => '대시보드', 'en' => 'Dashboard', 'slug' => 'reg-dashboard', 'order' => 1],
            ['ko' => '사용자 관리', 'en' => 'Users', 'slug' => 'reg-users', 'order' => 2],
            ['ko' => '게시판 관리', 'en' => 'Boards', 'slug' => 'reg-boards', 'order' => 3],
        ];

        DB::table((new Menu)->getTable())->insert(array_map(fn ($r) => [
            'name' => json_encode(['ko' => $r['ko'], 'en' => $r['en']]),
            'slug' => $r['slug'],
            'url' => '/'.$r['slug'],
            'order' => $r['order'],
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $rows));

        // 메뉴는 역할에 read 로 연결돼야 목록에 노출된다(`Menu::scopeAccessibleBy`).
        // 연결 행도 INSERT 1회로 심는다.
        $menuIds = Menu::whereIn('slug', array_column($rows, 'slug'))->pluck('id');

        DB::table('role_menus')->insert($menuIds->map(fn ($id) => [
            'menu_id' => $id,
            'role_id' => $this->roleId,
            'permission_type' => MenuPermissionType::Read->value,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }

    /**
     * 메뉴 목록 API 를 호출하고 이 테스트가 심은 메뉴의 slug 순서를 반환합니다.
     *
     * @param  string  $query  쿼리스트링 (앞의 ? 제외)
     * @return array<int, string> slug 순서
     */
    private function slugsFrom(string $query): array
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/menus'.($query === '' ? '' : '?'.$query));

        $response->assertOk();

        $slugs = [];
        $collect = function ($node) use (&$collect, &$slugs) {
            if (! is_array($node)) {
                return;
            }

            if (isset($node['slug']) && is_string($node['slug']) && str_starts_with($node['slug'], 'reg-')) {
                $slugs[] = $node['slug'];
            }

            foreach ($node as $child) {
                $collect($child);
            }
        };

        $collect($response->json());

        return $slugs;
    }

    /**
     * 필터 없이도 정렬이 적용되고, 한글 검색이 다국어 JSON 컬럼에서 매칭되어야 한다.
     */
    public function test_필터_없이도_정렬이_적용되고_한글검색이_매칭된다(): void
    {
        $this->seedMenus();

        // (D-22) 필터를 하나도 걸지 않은 상태에서 이름 오름차순.
        // 기대 순서는 이름순(게시판<대시보드<사용자 / Boards<Dashboard<Users)이고,
        // 심어둔 order 순서(대시보드1·사용자2·게시판3)와 다르다 — 정렬이 버려지면
        // order 순서가 나오므로 두 순서가 다른 것이 판정의 핵심이다.
        $this->assertSame(
            ['reg-boards', 'reg-dashboard', 'reg-users'],
            $this->slugsFrom('sort_by=name&sort_order=asc'),
            '필터 없이 정렬만 요청하면 정렬이 무시됩니다 — 기본 order 순서로 되돌아간 것입니다.'
        );

        // 방향을 뒤집으면 순서도 뒤집혀야 한다 (한쪽만 보면 우연한 일치를 못 걸러낸다)
        $this->assertSame(
            ['reg-users', 'reg-dashboard', 'reg-boards'],
            $this->slugsFrom('sort_by=name&sort_order=desc'),
            '정렬 방향이 반영되지 않았습니다.'
        );

        // (D-23) 한글 검색어가 JSON 이스케이프 저장값에서도 매칭되어야 한다
        $this->assertSame(
            ['reg-dashboard'],
            $this->slugsFrom('filters[0][field]=name&filters[0][value]=대시보드&filters[0][operator]=like'),
            '한글 검색이 0건이면 JSON 컬럼 전체에 LIKE 를 건 것입니다 — 로케일 경로로 검색해야 합니다.'
        );

        // 'all' 필드 검색도 같은 경로를 타야 한다
        $this->assertSame(
            ['reg-users'],
            $this->slugsFrom('filters[0][field]=all&filters[0][value]=사용자&filters[0][operator]=like'),
            "'all' 검색에서도 다국어 컬럼이 로케일 경로로 풀려야 합니다."
        );

        // 다른 로케일 값으로도 검색되어야 한다 (표시 로케일에만 묶이면 안 된다)
        $this->assertSame(
            ['reg-boards'],
            $this->slugsFrom('filters[0][field]=name&filters[0][value]=Boards&filters[0][operator]=like'),
            '지원 로케일 전체를 대상으로 검색해야 합니다.'
        );
    }
}
