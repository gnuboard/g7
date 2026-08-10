<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Repositories;

// ModuleTestCase 수동 로드 (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Repositories\PostRepository;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시글 목록 지연 조인 회귀 테스트
 *
 * 목록은 이제 "이번 페이지의 원글 ID 를 먼저 구하고, 그 ID 에 대해서만 목록 컬럼과 관계를
 * 읽는" 2단계 조회다. 이 화면은 공지 병합·답글 트리·다음 페이지 판정이 얽혀 있어, 조회 방식
 * 변경이 조용히 깨지면 화면에서 빈 값이나 누락으로만 드러난다. 그 성질들을 고정한다.
 *
 * @scenario case=post_list_deferred_join
 *
 * @effects self_scoped_list_returns_only_own_rows, soft_deleted_rows_stay_excluded, with_trashed_scope_is_preserved
 */
class PostListDeferredJoinTest extends BoardTestCase
{
    private PostRepository $repository;

    /** 수집된 실행 쿼리 SQL 목록 */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(PostRepository::class);
    }

    /** 쿼리 로그 수집을 시작합니다. */
    private function startCapture(): void
    {
        $this->queries = [];

        DB::listen(function ($query) {
            $this->queries[] = $query->sql;
        });
    }

    /**
     * 수집된 쿼리 중 조건에 맞는 것만 돌려줍니다.
     *
     * @param  string  $needle  포함 문자열
     * @return array<int, string> 일치 쿼리 목록
     */
    private function queriesContaining(string $needle): array
    {
        return array_values(array_filter($this->queries, fn (string $sql) => str_contains($sql, $needle)));
    }

    public function test_offset_scan_does_not_read_content_preview(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->createTestPost(['title' => "게시글 {$i}"]);
        }

        $this->startCapture();
        $this->repository->paginate($this->board->slug, ['page' => 2], 10);

        $offsetQueries = $this->queriesContaining('offset');

        $this->assertNotEmpty($offsetQueries, '페이지 조회에는 OFFSET 쿼리가 있어야 한다');

        foreach ($offsetQueries as $sql) {
            // 본문 앞부분을 잘라 읽는 것도 오버플로 페이지를 그대로 읽으므로 프루닝이 아니다.
            // 잘라 읽기는 이번 페이지 분량(outer)에서만 일어나야 한다.
            $this->assertStringNotContainsString('SUBSTRING', $sql);
            $this->assertStringNotContainsString('content', $sql);
        }
    }

    public function test_page_one_shows_notices_and_page_two_does_not(): void
    {
        $this->createTestPost(['title' => '공지사항', 'is_notice' => true]);

        for ($i = 1; $i <= 25; $i++) {
            $this->createTestPost(['title' => "일반글 {$i}"]);
        }

        $page1 = $this->repository->paginate($this->board->slug, ['page' => 1], 10);
        $page2 = $this->repository->paginate($this->board->slug, ['page' => 2], 10);

        $titles1 = collect($page1->items())->pluck('title')->all();
        $titles2 = collect($page2->items())->pluck('title')->all();

        $this->assertSame('공지사항', $titles1[0], '공지는 1페이지 선두에 있어야 한다');
        $this->assertNotContains('공지사항', $titles2, '공지는 2페이지에 반복되지 않아야 한다');
    }

    public function test_replies_follow_their_parent_in_the_same_page(): void
    {
        $parentId = $this->createTestPost(['title' => '원글']);
        $this->createTestPost(['title' => '답글 1', 'parent_id' => $parentId, 'depth' => 1]);
        $this->createTestPost(['title' => '답글 2', 'parent_id' => $parentId, 'depth' => 1]);

        $result = $this->repository->paginate($this->board->slug, [], 10);
        $titles = collect($result->items())->pluck('title')->all();

        $this->assertSame(['원글', '답글 1', '답글 2'], $titles);
    }

    public function test_reply_content_preview_is_available_like_parent(): void
    {
        $parentId = $this->createTestPost(['title' => '원글', 'content' => '원글 본문입니다.']);
        $this->createTestPost([
            'title' => '답글',
            'content' => '답글 본문입니다.',
            'parent_id' => $parentId,
            'depth' => 1,
        ]);

        $result = $this->repository->paginate($this->board->slug, [], 10);
        $items = collect($result->items());

        // 원글과 답글은 같은 컬럼 목록으로 조회되므로 스키마가 어긋나면 안 된다
        // (한쪽만 바뀌면 화면에서 조용히 빈 값이 된다)
        foreach ($items as $item) {
            $this->assertNotNull($item->content_preview_raw ?? null);
        }
    }

    public function test_pages_do_not_overlap(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->createTestPost(['title' => "게시글 {$i}"]);
        }

        $page1 = $this->repository->paginate($this->board->slug, ['page' => 1], 10);
        $page2 = $this->repository->paginate($this->board->slug, ['page' => 2], 10);

        $ids1 = collect($page1->items())->pluck('id')->all();
        $ids2 = collect($page2->items())->pluck('id')->all();

        $this->assertCount(10, $ids1);
        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    public function test_last_page_reports_no_more_pages(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->createTestPost(['title' => "게시글 {$i}"]);
        }

        $page1 = $this->repository->paginate($this->board->slug, ['page' => 1], 10);
        $page2 = $this->repository->paginate($this->board->slug, ['page' => 2], 10);

        $this->assertTrue($page1->hasMorePages(), '1페이지에서는 다음 페이지가 있어야 한다');
        $this->assertFalse($page2->hasMorePages(), '마지막 페이지에서 다음 버튼이 남으면 안 된다');
    }

    public function test_soft_deleted_posts_are_excluded_by_default(): void
    {
        $this->createTestPost(['title' => '살아있는 글']);
        $this->createTestPost(['title' => '삭제된 글', 'deleted_at' => now()]);

        $result = $this->repository->paginate($this->board->slug, [], 10);
        $titles = collect($result->items())->pluck('title')->all();

        $this->assertContains('살아있는 글', $titles);
        $this->assertNotContains('삭제된 글', $titles);
    }

    public function test_with_trashed_includes_deleted_posts(): void
    {
        $this->createTestPost(['title' => '살아있는 글']);
        $this->createTestPost(['title' => '삭제된 글', 'deleted_at' => now()]);

        $result = $this->repository->paginate($this->board->slug, [], 10, true);
        $titles = collect($result->items())->pluck('title')->all();

        $this->assertContains('삭제된 글', $titles);
    }

    public function test_sort_order_is_applied_and_deterministic(): void
    {
        // 조회수가 모두 같은 상태 — 2차 정렬(id)이 없으면 페이지 경계가 흔들린다
        for ($i = 1; $i <= 12; $i++) {
            $this->createTestPost(['title' => "게시글 {$i}", 'view_count' => 5]);
        }

        $result = $this->repository->paginate(
            $this->board->slug,
            ['order_by' => 'view_count', 'order_direction' => 'desc'],
            5
        );

        $ids = collect($result->items())->pluck('id')->all();
        $sorted = $ids;
        rsort($sorted);

        $this->assertSame($sorted, $ids, '동률 정렬에서도 id 내림차순이 유지되어야 한다');
    }

    /**
     * self 스코프 사용자의 목록에 타인 글이 섞이지 않는지 검증합니다.
     *
     * 권한 스코프는 `PermissionHelper::applyPermissionScope()` 가 쿼리에 붙이는 where 다.
     * 지연 조인은 이 쿼리를 clone 해 inner/outer 두 번 실행하므로, 스코프가 한쪽에만
     * 걸리면 목록에 타인 글이 노출되거나(권한 누출) 페이지가 비게 된다. 이 실패는
     * 예외 없이 조용히 일어나므로 결과 집합으로 직접 단언한다.
     */
    public function test_self_scoped_list_returns_only_own_rows(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 1; $i <= 6; $i++) {
            $this->createTestPost(['title' => "내 글 {$i}", 'user_id' => $owner->id]);
            $this->createTestPost(['title' => "남의 글 {$i}", 'user_id' => $other->id]);
        }

        $permission = "sirsoft-board.{$this->board->slug}.admin.posts.read";

        // owner_key 는 config/board.php 의 권한 정의가 SSoT 다. 여기에 값을 적어 두면
        // 정의가 바뀌어 스코프가 꺼져도 테스트는 계속 통과한다.
        $scopeMeta = config('sirsoft-board.board_permission_definitions')['admin.posts.read']['scope'] ?? null;

        $this->assertNotNull($scopeMeta, 'admin.posts.read 에 scope 정의가 없다 — 스코프 필터가 적용되지 않는다');

        $this->grantScopedPermission($owner, $permission, 'self', $scopeMeta);

        // 권한 메타가 실제로 스코프를 켜는지 확인 — 이게 null 이면 아래 단언이 무의미해진다
        $this->assertSame(
            $scopeMeta['owner_key'],
            PermissionHelper::getOwnerKey($permission),
            "권한 {$permission} 의 owner_key 가 config 정의와 다르다"
        );
        $this->actingAs($owner);

        $result = $this->repository->paginate(
            $this->board->slug,
            ['scope_permission' => $permission],
            5
        );

        $titles = collect($result->items())->pluck('title')->all();
        $userIds = collect($result->items())->pluck('user_id')->unique()->all();

        $this->assertNotEmpty($titles, 'self 스코프에서도 본인 글은 조회되어야 한다');
        $this->assertSame([$owner->id], $userIds, 'self 스코프 목록에 타인 글이 섞이면 안 된다');

        foreach ($titles as $title) {
            $this->assertStringNotContainsString('남의 글', $title);
        }
    }

    /**
     * 사용자에게 지정한 스코프로 권한을 부여합니다.
     *
     * @param  User  $user  대상 사용자
     * @param  string  $identifier  권한 식별자
     * @param  string  $scopeType  스코프 유형 (self/role)
     * @param  array  $scopeMeta  config 의 scope 정의 (resource_route_key / owner_key)
     */
    private function grantScopedPermission(User $user, string $identifier, string $scopeType, array $scopeMeta): void
    {
        $permission = Permission::updateOrCreate(
            ['identifier' => $identifier],
            [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => 'module',
                'extension_identifier' => 'sirsoft-board',
                'type' => 'admin',
                'resource_route_key' => $scopeMeta['resource_route_key'],
                'owner_key' => $scopeMeta['owner_key'],
            ]
        );

        $role = Role::create([
            'identifier' => 'scoped_test_'.uniqid(),
            'name' => json_encode(['ko' => '스코프 테스트', 'en' => 'Scoped Test']),
            'is_active' => true,
        ]);

        $role->permissions()->attach($permission->id, ['scope_type' => $scopeType]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        $user->refresh();
    }
}
