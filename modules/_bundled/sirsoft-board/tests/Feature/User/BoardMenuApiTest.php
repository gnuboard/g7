<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Helpers\PermissionHelper;
use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 네비게이션 메뉴 API 테스트
 *
 * 네비게이션 메뉴용 경량 API의 동작을 검증합니다. 메뉴는 호출자가 열람할 수 있는 게시판만 돌려주므로
 * (#142, 게시판 목록 화면과 같은 기준) 비회원 시점 검증용 게시판에는 guest 읽기 권한을 부여한다
 * (BoardListApiTest 와 같은 관례).
 */
class BoardMenuApiTest extends ModuleTestCase
{
    /**
     * 테스트 환경 설정
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // DDL implicit commit으로 커밋된 게시판 비활성화 (데이터 격리 보장)
        DB::table('boards')->update(['is_active' => false]);
        // 이 테스트 클래스에서 사용하는 슬러그 정리 (커밋된 경우 대비)
        DB::table('boards')->whereIn('slug', ['free', 'inactive', 'old', 'new', 'members-only', 'i142-public', 'i142-hidden'])->delete();

        // 메뉴 열람 권한 필터 대응 — 이 클래스가 쓰는 공개 슬러그에 guest·회원 읽기 권한 부여
        foreach (['free', 'inactive', 'old', 'new', 'i142-public', 'i142-hidden'] as $slug) {
            $this->grantRead($slug, ['guest', 'user']);
        }
        // 회원전용 게시판 — user 역할만 읽는다
        $this->grantRead('members-only', ['user']);
        PermissionMiddleware::clearGuestRoleCache();

        // board-menu 캐시 플러시 (이전 테스트 캐시가 남아있으면 데이터 격리 실패)
        app(BoardService::class)->clearAllBoardCaches();
    }

    /**
     * 역할들에 게시판 읽기 권한을 부여합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int, string>  $roles  역할 식별자 목록
     * @return void 반환값 없음
     */
    private function grantRead(string $slug, array $roles): void
    {
        $perm = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.posts.read"],
            ['name' => ['ko' => 'read', 'en' => 'read'], 'type' => 'user']
        );

        foreach ($roles as $identifier) {
            Role::where('identifier', $identifier)->first()?->permissions()->syncWithoutDetaching([$perm->id]);
        }
    }

    /**
     * 시험용 게시판 3종(공개·회원전용·비활성)을 만든다.
     *
     * @return void 반환값 없음
     */
    private function createAxisBoards(): void
    {
        Board::factory()->create(['slug' => 'i142-public', 'is_active' => true, 'name' => ['ko' => '공개', 'en' => 'Public']]);
        Board::factory()->create(['slug' => 'members-only', 'is_active' => true, 'name' => ['ko' => '회원전용', 'en' => 'Members']]);
        Board::factory()->create(['slug' => 'i142-hidden', 'is_active' => false, 'name' => ['ko' => '비활성', 'en' => 'Hidden']]);
    }

    /**
     * 메뉴를 요청해 slug 목록을 돌려준다.
     *
     * @param  User|null  $viewer  요청자 (null 이면 비회원)
     * @return array<int, string> 메뉴 slug 목록
     */
    private function menuSlugs(?User $viewer): array
    {
        if ($viewer === null) {
            // 앞 요청의 actingAs 가 남아 있으면 비회원 요청이 회원 요청이 된다
            $this->app['auth']->forgetGuards();
        }
        $request = $viewer ? $this->actingAs($viewer) : $this;

        return collect($request->getJson('/api/modules/sirsoft-board/boards/board-menu')->assertOk()->json('data'))
            ->pluck('slug')->all();
    }

    /**
     * 게시판 목록 화면(/boards)의 slug 집합을 돌려준다.
     *
     * @param  User|null  $viewer  요청자
     * @return array<int, string> 정렬한 slug 목록
     */
    private function publicListSlugs(?User $viewer): array
    {
        $request = $viewer ? $this->actingAs($viewer) : $this;
        $slugs = collect($request->getJson('/api/modules/sirsoft-board/boards')->assertOk()->json('data'))->pluck('slug')->all();
        sort($slugs);

        return $slugs;
    }

    /**
     * 메뉴 slug 를 정렬해 집합으로 비교할 수 있게 한다.
     *
     * @param  array<int, string>  $slugs  slug 목록
     * @return array<int, string> 정렬한 목록
     */
    private function sorted(array $slugs): array
    {
        sort($slugs);

        return $slugs;
    }

    /**
     * 네비게이션 메뉴 API가 활성화된 게시판만 반환하는지 테스트
     *
     * @effects inactive_board_never_listed
     *
     * @return void 반환값 없음
     */
    public function test_board_menu_returns_only_active_boards(): void
    {
        // Given: 활성화/비활성화 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
        ]);

        Board::factory()->create([
            'is_active' => false,
            'name' => ['ko' => '비활성 게시판', 'en' => 'Inactive Board'],
            'slug' => 'inactive',
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu');

        // Then: 활성화된 게시판만 반환
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'slug'],
                ],
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'slug' => 'free',
            ])
            ->assertJsonMissing([
                'slug' => 'inactive',
            ]);
    }

    /**
     * 네비게이션 메뉴 API가 최소 필드만 반환하는지 테스트
     *
     * @effects menu_minimal_fields_and_localized_names
     *
     * @return void 반환값 없음
     */
    public function test_board_menu_returns_minimal_fields_only(): void
    {
        // Given: 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
            'description' => ['ko' => '설명', 'en' => 'Description'],
            'type' => 'basic',
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu');

        // Then: id, name, slug만 반환
        $response->assertStatus(200);

        $data = $response->json('data.0');

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('slug', $data);

        // 다른 필드는 포함되지 않아야 함 (리스너용으로 더 읽는 display_order·created_at 도 응답에는 없다)
        $this->assertArrayNotHasKey('description', $data);
        $this->assertArrayNotHasKey('type', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayNotHasKey('permissions', $data);
        $this->assertArrayNotHasKey('display_order', $data);
        $this->assertArrayNotHasKey('created_at', $data);
    }

    /**
     * 표시 순서를 정하지 않았으면(모두 0) 메뉴는 만든 순서(오래된 순)다
     *
     * @effects menu_order_follows_display_order
     *
     * @return void 반환값 없음
     */
    public function test_board_menu_returns_boards_in_created_order_when_display_order_is_zero(): void
    {
        // Given: 게시판을 시간 순서대로 생성 (표시 순서 0)
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '오래된 게시판', 'en' => 'Old Board'],
            'slug' => 'old',
            'created_at' => now()->subDays(10),
        ]);

        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '새 게시판', 'en' => 'New Board'],
            'slug' => 'new',
            'created_at' => now(),
        ]);

        // When / Then: 오래된 순으로 정렬
        $this->assertSame(['old', 'new'], $this->menuSlugs(null));

        // 표시 순서를 정하면 그 순서를 따른다
        DB::table('boards')->where('slug', 'old')->update(['display_order' => 2]);
        DB::table('boards')->where('slug', 'new')->update(['display_order' => 1]);
        app(BoardService::class)->clearAllBoardCaches();

        $this->assertSame(['new', 'old'], $this->menuSlugs(null));
    }

    /**
     * 네비게이션 메뉴 API가 다국어를 올바르게 처리하는지 테스트
     *
     * @effects menu_minimal_fields_and_localized_names
     *
     * @return void 반환값 없음
     */
    public function test_board_menu_returns_localized_names(): void
    {
        // Given: 다국어 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
        ]);

        // When: 한국어로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu', [
            'Accept-Language' => 'ko',
        ]);

        // Then: 한국어 이름 반환
        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => '자유게시판',
            ]);

        // When: 영어로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu', [
            'Accept-Language' => 'en',
        ]);

        // Then: 영어 이름 반환
        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Free Board',
            ]);
    }

    /**
     * 게시판이 없을 때 빈 배열을 반환하는지 테스트
     *
     * @effects inactive_board_never_listed
     *
     * @return void 반환값 없음
     */
    public function test_board_menu_returns_empty_array_when_no_boards(): void
    {
        // Given: 게시판 없음

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu');

        // Then: 빈 배열 반환
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /**
     * 비회원(캐시 차가움): 회원전용·비활성 게시판은 메뉴에 없고, 게시판 목록 화면과 같은 집합이다.
     *
     * @scenario viewer=guest, cache=cold, board=public
     *
     * @effects guest_menu_excludes_members_only_board, inactive_board_never_listed, menu_matches_public_board_list_set
     *
     * @return void 반환값 없음
     */
    public function test_guest_menu_excludes_members_only_board_cold(): void
    {
        // @scenario viewer=guest, cache=cold, board=members_only
        // @effects guest_menu_excludes_members_only_board
        // @scenario viewer=guest, cache=cold, board=inactive
        // @effects inactive_board_never_listed
        $this->createAxisBoards();

        $menu = $this->menuSlugs(null);

        $this->assertSame(['i142-public'], $menu);
        $this->assertSame($this->sorted($menu), $this->publicListSlugs(null));
    }

    /**
     * 비회원(캐시 따뜻함 — 회원이 먼저 채움): 회원이 채운 공용 캐시가 비회원에게 새지 않는다.
     *
     * @scenario viewer=guest, cache=warm, board=public
     *
     * @effects guest_menu_excludes_members_only_board, inactive_board_never_listed
     *
     * @return void 반환값 없음
     */
    public function test_guest_menu_excludes_members_only_board_warm(): void
    {
        // @scenario viewer=guest, cache=warm, board=members_only
        // @effects guest_menu_excludes_members_only_board
        // @scenario viewer=guest, cache=warm, board=inactive
        // @effects inactive_board_never_listed
        $this->createAxisBoards();
        $this->menuSlugs($this->createUser()); // 회원이 캐시를 채운다

        $this->assertSame(['i142-public'], $this->menuSlugs(null));
    }

    /**
     * 회원(캐시 차가움): 회원전용 게시판을 받는다.
     *
     * @scenario viewer=member, cache=cold, board=public
     *
     * @effects member_menu_includes_members_only_board, menu_matches_public_board_list_set
     *
     * @return void 반환값 없음
     */
    public function test_member_menu_includes_members_only_board_cold(): void
    {
        // @scenario viewer=member, cache=cold, board=members_only
        // @effects member_menu_includes_members_only_board
        // @scenario viewer=member, cache=cold, board=inactive
        // @effects inactive_board_never_listed
        $this->createAxisBoards();
        $member = $this->createUser();

        $menu = $this->menuSlugs($member);

        $this->assertSame(['i142-public', 'members-only'], $this->sorted($menu));
        $this->assertSame($this->sorted($menu), $this->publicListSlugs($member));
    }

    /**
     * 회원(캐시 따뜻함): 캐시가 따뜻할 때 역할 권한이 바뀌면 다음 요청에 바로 반영된다(캐시 비우기 없이).
     *
     * @scenario viewer=member, cache=warm, board=public
     *
     * @effects member_menu_includes_members_only_board, warm_cache_reflects_permission_change
     *
     * @return void 반환값 없음
     */
    public function test_member_menu_reflects_permission_change_on_warm_cache(): void
    {
        // @scenario viewer=member, cache=warm, board=members_only
        // @effects member_menu_includes_members_only_board
        // @scenario viewer=member, cache=warm, board=inactive
        // @effects inactive_board_never_listed
        $this->createAxisBoards();
        $member = $this->createUser();
        $this->assertSame(['i142-public', 'members-only'], $this->sorted($this->menuSlugs($member)));

        // 회원 역할에서 회원전용 게시판 읽기 권한을 뺀다
        $perm = Permission::where('identifier', 'sirsoft-board.members-only.posts.read')->first();
        Role::where('identifier', 'user')->first()->permissions()->detach($perm->id);
        PermissionHelper::clearPermissionScopeCache();
        $member = User::find($member->id);

        $this->assertNotContains('members-only', $this->menuSlugs($member));
    }

    /**
     * 관리자(캐시 차가움): 관리자 역할도 게시판 읽기 권한을 기준으로 거른다(관리자 권한이 열람을 대신하지 않는다).
     *
     * @scenario viewer=admin, cache=cold, board=public
     *
     * @effects member_menu_includes_members_only_board, inactive_board_never_listed
     *
     * @return void 반환값 없음
     */
    public function test_admin_menu_cold(): void
    {
        // @scenario viewer=admin, cache=cold, board=members_only
        // @effects member_menu_includes_members_only_board
        // @scenario viewer=admin, cache=cold, board=inactive
        // @effects inactive_board_never_listed
        $this->createAxisBoards();
        $admin = $this->createAdminUser();
        $admin->roles()->attach(Role::where('identifier', 'user')->first()->id);

        $this->assertSame(['i142-public', 'members-only'], $this->sorted($this->menuSlugs($admin)));
    }

    /**
     * 관리자(캐시 따뜻함 — 비회원이 먼저 채움): 비회원이 채운 캐시라도 관리자는 자기 권한대로 받는다.
     *
     * @scenario viewer=admin, cache=warm, board=public
     *
     * @effects member_menu_includes_members_only_board, inactive_board_never_listed
     *
     * @return void 반환값 없음
     */
    public function test_admin_menu_warm(): void
    {
        // @scenario viewer=admin, cache=warm, board=members_only
        // @effects member_menu_includes_members_only_board
        // @scenario viewer=admin, cache=warm, board=inactive
        // @effects inactive_board_never_listed
        $this->createAxisBoards();
        $this->menuSlugs(null);
        $admin = $this->createAdminUser();
        $admin->roles()->attach(Role::where('identifier', 'user')->first()->id);

        $this->assertSame(['i142-public', 'members-only'], $this->sorted($this->menuSlugs($admin)));
    }
}
