<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 표시 순서 저장 API 테스트 (#142)
 *
 * `PUT admin/boards/order` 는 전체 게시판 id 목록을 새 순서로 받아 1..N 으로 다시 매긴다. 현재 집합과 다르면 422,
 * 게시판 수정 권한의 범위가 제한된 사람은 403 이다. 저장하면 메뉴·게시판 목록·관리자 목록·통합검색 선택칸이 새 순서를 따른다.
 */
class BoardReorderApiTest extends ModuleTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-board/admin/boards/order';

    private User $admin;

    /**
     * @var array<int, Board>
     */
    private array $boards = [];

    /**
     * 게시판 수정 권한(범위 제한 없음)을 가진 관리자와 시험 게시판 3개를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('boards')->update(['is_active' => false]);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->roles()->attach(Role::where('identifier', 'admin')->firstOrFail()->id);
        $this->admin->roles()->attach(Role::where('identifier', 'user')->firstOrFail()->id);
        $this->grantAdminRole(['sirsoft-board.boards.read', 'sirsoft-board.boards.update']);

        foreach (['a', 'b', 'c'] as $i => $suffix) {
            $board = Board::factory()->create([
                'slug' => "i142r-{$suffix}-".uniqid(),
                'name' => ['ko' => "순서{$suffix}", 'en' => "Order{$suffix}"],
                'created_at' => now()->subMinutes(10 - $i),
            ]);
            $this->grantRead($board);
            $this->boards[] = $board;
        }
        PermissionMiddleware::clearGuestRoleCache();
        app(BoardService::class)->clearAllBoardCaches();
    }

    /**
     * admin 역할에 권한을 붙입니다 (테스트의 admin 역할은 빈 역할로 시작한다).
     *
     * @param  array<int, string>  $identifiers  권한 식별자
     * @return void 반환값 없음
     */
    private function grantAdminRole(array $identifiers): void
    {
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    /**
     * 비회원·회원에게 게시판 열람을 준다.
     *
     * @param  Board  $board  대상 게시판
     * @return void 반환값 없음
     */
    private function grantRead(Board $board): void
    {
        $permission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.posts.read"],
            ['name' => ['ko' => 'read', 'en' => 'read'], 'type' => 'user']
        );
        foreach (['guest', 'user'] as $identifier) {
            Role::where('identifier', $identifier)->first()->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    /**
     * 전체 게시판 id 를 「시험 게시판을 뒤집은 순서 + 나머지」로 만든다.
     *
     * @return array<int, int> 새 순서의 전체 게시판 id
     */
    private function reversedOrder(): array
    {
        $mine = array_reverse(array_map(fn (Board $b) => $b->id, $this->boards));
        $others = array_values(array_diff(app(BoardRepositoryInterface::class)->getAllIds(), $mine));

        return array_merge($mine, $others);
    }

    /**
     * 순서 저장 요청을 보낸다.
     *
     * @param  User  $actor  요청자
     * @param  array<int, mixed>  $ids  게시판 id 목록
     * @return TestResponse 응답
     */
    private function reorder(User $actor, array $ids): TestResponse
    {
        return $this->actingAs($actor)->putJson(self::ENDPOINT, ['board_ids' => $ids]);
    }

    /**
     * 시험 게시판 slug 만 남긴다.
     *
     * @param  array<int, string>  $slugs  slug 목록
     * @return array<int, string> 시험 게시판 slug (나온 순서 그대로)
     */
    private function mine(array $slugs): array
    {
        $own = array_map(fn (Board $b) => $b->slug, $this->boards);

        return array_values(array_filter($slugs, fn ($s) => in_array($s, $own, true)));
    }

    /**
     * 저장하면 1..N 으로 다시 매기고 응답에 새 순서가 실린다. 사용자 메뉴·게시판 목록 화면이 바로 새 순서다.
     *
     * @scenario case=reorder_ok_renumbers
     *
     * @effects renumbered_one_to_n, surfaces_follow_display_order
     *
     * @return void 반환값 없음
     */
    public function test_reorder_renumbers_and_surfaces_follow(): void
    {
        // @scenario surface=menu, state=reordered, actor=full_scope, request=ok
        // @effects renumbered_one_to_n
        // @scenario surface=public_list, state=reordered, actor=full_scope, request=ok
        // @effects surfaces_follow_display_order
        $order = $this->reversedOrder();

        $response = $this->reorder($this->admin, $order)->assertOk();
        $response->assertJsonPath('message', __('sirsoft-board::messages.boards.reorder_success'));

        $saved = collect($response->json('data.boards'))->pluck('display_order', 'id')->all();
        foreach ($order as $index => $id) {
            $this->assertSame($index + 1, $saved[$id]);
        }

        $expected = array_reverse(array_map(fn (Board $b) => $b->slug, $this->boards));
        $this->app['auth']->forgetGuards();
        $menu = collect($this->getJson('/api/modules/sirsoft-board/boards/board-menu')->json('data'))->pluck('slug')->all();
        $list = collect($this->getJson('/api/modules/sirsoft-board/boards')->json('data'))->pluck('slug')->all();
        $this->assertSame($expected, $this->mine($menu));
        $this->assertSame($expected, $this->mine($list));
    }

    /**
     * 저장하면 활동 로그 한 건(`board.reorder`, 개수)이 남고, 조회 후 훅이 커밋 뒤에 한 번 발행되며, SEO 캐시가 비워진다.
     *
     * @scenario case=reorder_logs_activity_and_after_hook
     *
     * @effects activity_log_board_reorder, after_reorder_after_commit, seo_cache_invalidated
     *
     * @return void 반환값 없음
     */
    public function test_reorder_logs_activity_dispatches_after_hook_and_clears_seo_cache(): void
    {
        $seo = Mockery::mock(SeoCacheManagerInterface::class)->shouldIgnoreMissing();
        $seo->shouldReceive('invalidateByUrl')->with('/boards')->atLeast()->once();
        $seo->shouldReceive('invalidateByLayout')->with('home')->atLeast()->once();
        $this->app->instance(SeoCacheManagerInterface::class, $seo);

        $after = [];
        HookManager::addAction('sirsoft-board.board.after_reorder', function (array $ids, array $before) use (&$after) {
            $after[] = ['ids' => $ids, 'before' => $before, 'level' => DB::transactionLevel()];
        });

        // 시험은 바깥 트랜잭션 안에서 돈다 — 커밋 뒤 콜백은 이 깊이로 돌아온 뒤 실행되고, 저장 트랜잭션 안이면 한 단계 깊다
        $baseLevel = DB::transactionLevel();
        $order = $this->reversedOrder();
        $this->reorder($this->admin, $order)->assertOk();

        $this->assertCount(1, $after);
        $this->assertSame($order, $after[0]['ids']);
        $this->assertArrayHasKey($this->boards[0]->id, $after[0]['before']);
        $this->assertSame($baseLevel, $after[0]['level'], 'after_reorder 는 저장 트랜잭션이 커밋된 뒤 발행된다');

        $logs = DB::table('activity_logs')->where('action', 'board.reorder')->get();
        $this->assertCount(1, $logs);
        $this->assertSame($this->admin->id, (int) $logs[0]->user_id);
        $this->assertSame('sirsoft-board::activity_log.description.board_reorder', $logs[0]->description_key);
        $this->assertSame(['count' => count($order)], json_decode($logs[0]->description_params, true));
    }

    /**
     * 관리자 게시판 목록의 기본 정렬은 표시 순서다(정렬을 지정하지 않을 때).
     *
     * @scenario case=admin_list_default_order
     *
     * @effects surfaces_follow_display_order
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_default_order_is_display_order(): void
    {
        // @scenario surface=admin_list, state=reordered, actor=full_scope, request=ok
        // @effects surfaces_follow_display_order
        $this->reorder($this->admin, $this->reversedOrder())->assertOk();

        $slugs = collect($this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/boards?per_page=999')->assertOk()->json('data.data'))
            ->pluck('slug')->all();

        $this->assertSame(array_reverse(array_map(fn (Board $b) => $b->slug, $this->boards)), $this->mine($slugs));

        // 다른 컬럼을 고르면 그 정렬을 따른다(만든 순서 오름차순)
        $byCreated = collect($this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/boards?per_page=999&sort_by=created_at&sort_order=asc')->json('data.data'))
            ->pluck('slug')->all();
        $this->assertSame(array_map(fn (Board $b) => $b->slug, $this->boards), $this->mine($byCreated));
    }

    /**
     * 순서를 한 번도 정하지 않았으면(모두 0) 관리자 게시판 목록 기본 정렬과 통합검색 게시판 선택칸도 만든 순서다
     * — 관리자 목록은 종전 최신 순에서 바뀐다.
     *
     * @scenario case=all_zero_admin_and_search_creation_order
     *
     * @effects creation_order_when_all_zero
     *
     * @return void 반환값 없음
     */
    public function test_all_zero_admin_list_and_search_filter_use_creation_order(): void
    {
        // @scenario surface=admin_list, state=all_zero, actor=full_scope, request=ok
        // @effects creation_order_when_all_zero
        // @scenario surface=search_filter, state=all_zero, actor=full_scope, request=ok
        // @effects creation_order_when_all_zero
        $created = array_map(fn (Board $b) => $b->slug, $this->boards);

        $admin = collect($this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/boards?per_page=999')->assertOk()->json('data.data'))
            ->pluck('slug')->all();
        $this->assertSame($created, $this->mine($admin));

        $search = array_column(app(BoardService::class)->getActiveBoardsListForFilter($this->admin), 'slug');
        $this->assertSame($created, $this->mine($search));
    }

    /**
     * 통합검색의 게시판 선택칸도 표시 순서를 따른다.
     *
     * @scenario case=search_filter_follows_order
     *
     * @effects surfaces_follow_display_order
     *
     * @return void 반환값 없음
     */
    public function test_search_filter_boards_follow_display_order(): void
    {
        // @scenario surface=search_filter, state=reordered, actor=full_scope, request=ok
        // @effects surfaces_follow_display_order
        $this->reorder($this->admin, $this->reversedOrder())->assertOk();

        $slugs = array_column(app(BoardService::class)->getActiveBoardsListForFilter($this->admin), 'slug');

        $this->assertSame(array_reverse(array_map(fn (Board $b) => $b->slug, $this->boards)), $this->mine($slugs));
    }

    /**
     * 목록이 현재 게시판 집합과 다르면(하나 빠짐) 422 로 거부하고 순서를 바꾸지 않는다.
     *
     * @scenario case=reorder_stale_set_rejected
     *
     * @effects stale_or_invalid_set_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_reorder_rejects_stale_set(): void
    {
        // @scenario surface=menu, state=all_zero, actor=full_scope, request=stale_set
        // @effects stale_or_invalid_set_rejected_422
        $order = $this->reversedOrder();
        array_pop($order);
        $before = app(BoardRepositoryInterface::class)->getDisplayOrderMap();

        $this->reorder($this->admin, $order)
            ->assertStatus(422)
            ->assertJsonPath('errors.board_ids.0', __('sirsoft-board::validation.boards.reorder_stale'));

        $this->assertSame($before, app(BoardRepositoryInterface::class)->getDisplayOrderMap());
    }

    /**
     * 같은 id 가 두 번 오면 422(distinct)다.
     *
     * @scenario case=reorder_duplicate_rejected
     *
     * @effects stale_or_invalid_set_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_reorder_rejects_duplicate_ids(): void
    {
        // @scenario surface=menu, state=all_zero, actor=full_scope, request=duplicate
        // @effects stale_or_invalid_set_rejected_422
        $order = $this->reversedOrder();
        $order[1] = $order[0];

        $this->reorder($this->admin, $order)->assertStatus(422)->assertJsonValidationErrors(['board_ids.1']);
    }

    /**
     * 없는 id 가 섞이거나 목록이 비면 422 다.
     *
     * @scenario case=reorder_missing_rejected
     *
     * @effects stale_or_invalid_set_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_reorder_rejects_unknown_or_empty_ids(): void
    {
        // @scenario surface=menu, state=all_zero, actor=full_scope, request=missing
        // @effects stale_or_invalid_set_rejected_422
        $order = $this->reversedOrder();
        $order[0] = 99999999;

        $this->reorder($this->admin, $order)->assertStatus(422)->assertJsonValidationErrors(['board_ids']);
        $this->reorder($this->admin, [])->assertStatus(422)->assertJsonValidationErrors(['board_ids']);
    }

    /**
     * 게시판 수정 권한의 범위가 제한된(본인만) 운영자는 403 이고, 목록 응답의 can_reorder 도 false 다.
     *
     * @scenario case=scoped_actor_forbidden
     *
     * @effects scoped_or_unpermitted_forbidden_403, can_reorder_reflects_full_scope
     *
     * @return void 반환값 없음
     */
    public function test_scoped_actor_is_forbidden(): void
    {
        // @scenario surface=menu, state=all_zero, actor=scoped, request=ok
        // @effects scoped_or_unpermitted_forbidden_403
        $this->assertScopedActorForbidden('self');
    }

    /**
     * 게시판 수정 권한의 범위가 소유역할로 제한된 운영자도 403 이고, can_reorder 는 false 다.
     *
     * @scenario case=role_scoped_actor_forbidden
     *
     * @effects scoped_or_unpermitted_forbidden_403, can_reorder_reflects_full_scope
     *
     * @return void 반환값 없음
     */
    public function test_role_scoped_actor_is_forbidden(): void
    {
        // @scenario surface=menu, state=all_zero, actor=scoped, request=ok
        // @effects scoped_or_unpermitted_forbidden_403
        $this->assertScopedActorForbidden('role');
    }

    /**
     * 수정 권한은 범위 제한이 없어도 조회 권한이 「본인만」이면 403 이고 can_reorder 는 false 다.
     * 모달 목록은 조회 범위로 좁혀지는데 저장은 전체 게시판 목록을 요구하므로, 단추를 보여 주면 저장이 늘 실패한다.
     *
     * @scenario case=read_scoped_actor_forbidden
     *
     * @effects scoped_or_unpermitted_forbidden_403, can_reorder_reflects_full_scope
     *
     * @return void 반환값 없음
     */
    public function test_read_scoped_actor_with_full_update_is_forbidden(): void
    {
        // @scenario surface=menu, state=all_zero, actor=scoped, request=ok
        // @effects scoped_or_unpermitted_forbidden_403
        $this->assertScopedActorForbidden(null, 'self');
    }

    /**
     * 수정·조회 권한을 주어진 범위로 가진 운영자를 만들어 순서 저장 403 과 can_reorder=false 를 확인한다.
     *
     * @param  string|null  $updateScope  수정 권한 범위(null 제한 없음 / self 본인만 / role 소유역할)
     * @param  string|null  $readScope  조회 권한 범위 — 생략하면 수정 권한과 같다
     * @return void 반환값 없음
     */
    private function assertScopedActorForbidden(?string $updateScope, ?string $readScope = null): void
    {
        $readScope ??= $updateScope;
        $update = Permission::updateOrCreate(
            ['identifier' => 'sirsoft-board.boards.update'],
            ['name' => ['ko' => '게시판 수정', 'en' => 'Update Boards'], 'type' => 'admin', 'resource_route_key' => 'board', 'owner_key' => 'created_by']
        );
        $read = Permission::where('identifier', 'sirsoft-board.boards.read')->first();
        PermissionHelper::clearPermissionScopeCache();
        $role = Role::create(['identifier' => 'i142_scoped_'.uniqid(), 'name' => ['ko' => '범위 운영자', 'en' => 'Scoped'], 'is_active' => true]);
        $role->permissions()->attach($update->id, ['scope_type' => $updateScope, 'granted_at' => now()]);
        $role->permissions()->attach($read->id, ['scope_type' => $readScope, 'granted_at' => now()]);
        $operator = User::factory()->create(['status' => 'active']);
        $operator->roles()->attach($role->id);
        $operator = $operator->fresh();
        $before = app(BoardRepositoryInterface::class)->getDisplayOrderMap();

        $this->reorder($operator, $this->reversedOrder())->assertStatus(403);
        $this->assertSame($before, app(BoardRepositoryInterface::class)->getDisplayOrderMap());

        $abilities = $this->actingAs($operator)->getJson('/api/modules/sirsoft-board/admin/boards')->assertOk()->json('data.abilities');
        $this->assertFalse($abilities['can_reorder']);
    }

    /**
     * 게시판 수정 권한이 없으면 라우트 미들웨어가 403 으로 막는다.
     *
     * @scenario case=no_permission_forbidden
     *
     * @effects scoped_or_unpermitted_forbidden_403
     *
     * @return void 반환값 없음
     */
    public function test_actor_without_update_permission_is_forbidden(): void
    {
        // @scenario surface=menu, state=all_zero, actor=no_permission, request=ok
        // @effects scoped_or_unpermitted_forbidden_403
        $reader = $this->createAdminUser();
        $role = Role::create(['identifier' => 'i142_reader_'.uniqid(), 'name' => ['ko' => '읽기', 'en' => 'Read'], 'is_active' => true]);
        $role->permissions()->attach(Permission::where('identifier', 'sirsoft-board.boards.read')->first()->id);
        $reader->roles()->sync([$role->id]);

        $before = app(BoardRepositoryInterface::class)->getDisplayOrderMap();

        $this->reorder($reader->fresh(), $this->reversedOrder())->assertStatus(403);
        $this->assertSame($before, app(BoardRepositoryInterface::class)->getDisplayOrderMap());
    }

    /**
     * 범위 제한 없는 수정 권한자는 목록 응답의 can_reorder 가 true 다.
     *
     * @scenario case=can_reorder_ability
     *
     * @effects can_reorder_reflects_full_scope
     *
     * @return void 반환값 없음
     */
    public function test_full_scope_actor_can_reorder(): void
    {
        $abilities = $this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/boards')->assertOk()->json('data.abilities');

        $this->assertTrue($abilities['can_reorder']);
    }

    /**
     * `PUT admin/boards/order` 는 와일드카드 `{board}` 보다 먼저 등록돼 게시판 수정으로 해석되지 않는다.
     *
     * @scenario case=route_not_shadowed_by_board_wildcard
     *
     * @effects order_route_resolves_before_wildcard
     *
     * @return void 반환값 없음
     */
    public function test_order_route_is_not_resolved_as_board_wildcard(): void
    {
        $route = app('router')->getRoutes()->match(Request::create(self::ENDPOINT, 'PUT'));

        $this->assertSame('reorder', $route->getActionMethod());
        $this->assertStringEndsWith('admin.boards.order', $route->getName());
    }
}
