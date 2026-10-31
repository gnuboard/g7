<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 표시 순서 동작 테스트 (#142)
 *
 * 순서를 정하지 않은 사이트(모두 0)는 만든 순서이고(같은 초는 id), 순서를 정한 뒤 새로 만들거나 복사한
 * 게시판은 맨 끝에 붙는다. 비활성 게시판도 자리를 가져 다시 켜면 그 자리에 나온다. 인기 게시판은 글 수 기준을 유지한다.
 */
class BoardDisplayOrderTest extends ModuleTestCase
{
    private BoardService $service;

    private User $superAdmin;

    /**
     * 다른 시험의 게시판을 비활성화하고 게시판을 만들 수 있는 관리자를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('boards')->update(['is_active' => false]);

        BoardType::firstOrCreate(
            ['slug' => 'basic'],
            ['name' => ['ko' => '기본', 'en' => 'Basic'], 'is_active' => true, 'is_default' => true]
        );

        $this->superAdmin = User::factory()->create(['is_super' => true, 'status' => 'active']);
        $this->superAdmin->roles()->attach(Role::where('identifier', 'admin')->firstOrFail()->id);

        $this->service = app(BoardService::class);
        $this->service->clearAllBoardCaches();
    }

    /**
     * 비회원에게 게시판 열람을 준다.
     *
     * @param  Board  $board  대상 게시판
     * @return Board 같은 게시판
     */
    private function readable(Board $board): Board
    {
        $permission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.posts.read"],
            ['name' => ['ko' => 'read', 'en' => 'read'], 'type' => 'user']
        );
        Role::where('identifier', 'guest')->first()->permissions()->syncWithoutDetaching([$permission->id]);
        PermissionMiddleware::clearGuestRoleCache();

        return $board;
    }

    /**
     * 비회원 메뉴의 slug 목록 중 주어진 게시판만 남겨 돌려준다.
     *
     * @param  array<int, Board>  $boards  시험 게시판
     * @return array<int, string> 메뉴 순서의 slug
     */
    private function menuOrder(array $boards): array
    {
        $this->service->clearAllBoardCaches();
        $this->app['auth']->forgetGuards();
        $own = array_map(fn (Board $b) => $b->slug, $boards);
        $slugs = collect($this->getJson('/api/modules/sirsoft-board/boards/board-menu')->assertOk()->json('data'))->pluck('slug')->all();

        return array_values(array_filter($slugs, fn ($s) => in_array($s, $own, true)));
    }

    /**
     * 서비스로 게시판을 만든다(생성 경로가 표시 순서를 매긴다).
     *
     * @param  string  $prefix  slug 접두
     * @return Board 만든 게시판
     */
    private function createViaService(string $prefix): Board
    {
        $this->actingAs($this->superAdmin);
        $board = $this->service->createBoard([
            'name' => ['ko' => $prefix, 'en' => $prefix],
            'slug' => $prefix.'-'.substr(md5(uniqid('', true)), 0, 8),
            'type' => 'basic',
        ]);
        $this->app['auth']->forgetGuards();

        return $this->readable($board->fresh());
    }

    /**
     * 모두 0 이면 메뉴는 만든 순서이고, 같은 초에 만든 게시판은 id 순서다.
     *
     * @scenario case=menu_all_zero_creation_order
     *
     * @effects creation_order_when_all_zero
     *
     * @return void 반환값 없음
     */
    public function test_menu_uses_creation_order_and_id_tiebreak_when_all_zero(): void
    {
        // @scenario surface=menu, state=all_zero, actor=full_scope, request=ok
        // @effects creation_order_when_all_zero
        $sameSecond = now()->subDay()->startOfSecond();
        $first = $this->readable(Board::factory()->create(['slug' => 'i142o-1-'.uniqid(), 'created_at' => $sameSecond]));
        $second = $this->readable(Board::factory()->create(['slug' => 'i142o-2-'.uniqid(), 'created_at' => $sameSecond]));
        $older = $this->readable(Board::factory()->create(['slug' => 'i142o-0-'.uniqid(), 'created_at' => $sameSecond->copy()->subHour()]));

        $this->assertSame([$older->slug, $first->slug, $second->slug], $this->menuOrder([$first, $second, $older]));
    }

    /**
     * 순서를 저장한 뒤 새로 만든 게시판은 맨 끝이다.
     *
     * @scenario case=new_board_appended
     *
     * @effects new_and_copied_boards_appended
     *
     * @return void 반환값 없음
     */
    public function test_new_board_is_appended_after_reorder(): void
    {
        // @scenario surface=menu, state=new_board, actor=full_scope, request=ok
        // @effects new_and_copied_boards_appended
        $a = $this->readable(Board::factory()->create(['slug' => 'i142o-a-'.uniqid()]));
        $b = $this->readable(Board::factory()->create(['slug' => 'i142o-b-'.uniqid()]));
        $ids = app(BoardRepositoryInterface::class)->getAllIds();
        $order = array_merge([$b->id, $a->id], array_values(array_diff($ids, [$a->id, $b->id])));
        $this->actingAs($this->superAdmin);
        $this->service->reorderBoards($order);

        $new = $this->createViaService('i142o-new');

        $this->assertSame(count($order) + 1, $new->display_order);
        $this->assertSame([$b->slug, $a->slug, $new->slug], $this->menuOrder([$a, $b, $new]));
    }

    /**
     * 순서를 저장한 뒤 게시판을 복사하면, 복사 폼 데이터에는 표시 순서가 없고 그 데이터로 만든 복사본은 맨 끝이다
     * (원본 자리를 물려받지 않는다).
     *
     * @scenario case=copied_board_appended
     *
     * @effects new_and_copied_boards_appended
     *
     * @return void 반환값 없음
     */
    public function test_copied_board_is_appended(): void
    {
        // @scenario surface=menu, state=copied_board, actor=full_scope, request=ok
        // @effects new_and_copied_boards_appended
        $original = $this->readable(Board::factory()->create(['slug' => 'i142o-orig-'.uniqid()]));
        $other = $this->readable(Board::factory()->create(['slug' => 'i142o-other-'.uniqid()]));
        $ids = app(BoardRepositoryInterface::class)->getAllIds();
        // 원본을 맨 앞으로 — 복사본이 원본 자리(1)를 물려받으면 맨 앞에 붙는다
        $order = array_merge([$original->id, $other->id], array_values(array_diff($ids, [$original->id, $other->id])));
        $this->actingAs($this->superAdmin);
        $this->service->reorderBoards($order);

        $copyData = $this->service->copyBoard($original->id);
        $this->assertArrayNotHasKey('display_order', $copyData);

        $copyData['slug'] = 'i142o-copy-'.substr(md5(uniqid('', true)), 0, 8);
        $copy = $this->readable($this->service->createBoard($copyData)->fresh());
        $this->app['auth']->forgetGuards();

        $this->assertSame(count($order) + 1, $copy->display_order);
        $this->assertSame([$original->slug, $other->slug, $copy->slug], $this->menuOrder([$original, $other, $copy]));
    }

    /**
     * 비활성 게시판도 자리를 가진다 — 다시 켜면 저장한 자리에 나온다.
     *
     * @scenario case=reactivated_board_keeps_position
     *
     * @effects reactivated_board_returns_to_saved_position
     *
     * @return void 반환값 없음
     */
    public function test_reactivated_board_returns_to_saved_position(): void
    {
        // @scenario surface=menu, state=reactivated, actor=full_scope, request=ok
        // @effects reactivated_board_returns_to_saved_position
        $a = $this->readable(Board::factory()->create(['slug' => 'i142o-a-'.uniqid()]));
        $hidden = $this->readable(Board::factory()->create(['slug' => 'i142o-h-'.uniqid(), 'is_active' => false]));
        $c = $this->readable(Board::factory()->create(['slug' => 'i142o-c-'.uniqid()]));
        $ids = app(BoardRepositoryInterface::class)->getAllIds();
        $order = array_merge([$c->id, $hidden->id, $a->id], array_values(array_diff($ids, [$a->id, $hidden->id, $c->id])));
        $this->actingAs($this->superAdmin);
        $this->service->reorderBoards($order);

        $this->assertSame([$c->slug, $a->slug], $this->menuOrder([$a, $hidden, $c]));

        DB::table('boards')->where('id', $hidden->id)->update(['is_active' => true]);

        $this->assertSame([$c->slug, $hidden->slug, $a->slug], $this->menuOrder([$a, $hidden, $c]));
    }

    /**
     * 인기 게시판은 표시 순서와 무관하게 글 수 순서를 유지한다(대조군).
     *
     * @scenario case=popular_boards_unchanged
     *
     * @effects popular_boards_keep_post_count_order
     *
     * @return void 반환값 없음
     */
    public function test_popular_boards_keep_post_count_order(): void
    {
        $few = $this->readable(Board::factory()->create(['slug' => 'i142o-few-'.uniqid(), 'posts_count' => 1]));
        $many = $this->readable(Board::factory()->create(['slug' => 'i142o-many-'.uniqid(), 'posts_count' => 9]));
        $ids = app(BoardRepositoryInterface::class)->getAllIds();
        $this->actingAs($this->superAdmin);
        $this->service->reorderBoards(array_merge([$few->id, $many->id], array_values(array_diff($ids, [$few->id, $many->id]))));
        $this->service->clearAllBoardCaches();
        $this->app['auth']->forgetGuards();

        $slugs = collect($this->getJson('/api/modules/sirsoft-board/boards/popular-boards?limit=20')->assertOk()->json('data'))->pluck('slug')->all();
        $own = array_values(array_filter($slugs, fn ($s) => in_array($s, [$few->slug, $many->slug], true)));

        $this->assertSame([$many->slug, $few->slug], $own);
    }

    /**
     * 표시 순서 마이그레이션은 두 번 실행해도 오류가 없다(이미 있으면 건너뛴다).
     *
     * @scenario case=migration_idempotent
     *
     * @effects migration_runs_twice_without_error
     *
     * @return void 반환값 없음
     */
    public function test_display_order_migration_is_idempotent(): void
    {
        $migration = require $this->getModuleBasePath().'/database/migrations/2026_10_19_000001_add_display_order_to_boards_table.php';

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('boards', 'display_order'));
        $this->assertTrue(Schema::hasIndex('boards', 'idx_boards_active_display_order'));
    }
}
