<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Repositories;

// ModuleTestCase 수동 로드 (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Repositories\CommentRepository;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 마이페이지 「내가 쓴 댓글」 목록 지연 조인 회귀 테스트
 *
 * 이 목록은 게시글 목록과 달리 **삭제된 게시글의 댓글 제외**(whereExists)와
 * **캐시된 총 건수 주입**이 함께 걸려 있다. 조회 방식이 2단계로 바뀌면서 두 성질 중
 * 하나라도 inner/outer 한쪽에만 적용되면 화면에서는 "이미 지운 글의 댓글이 보인다" 또는
 * "마지막 페이지가 비어 있다" 로만 드러난다. 그 성질들을 고정한다.
 *
 * @scenario case=user_comment_list_deferred_join
 *
 * @effects soft_deleted_excluded, page_boundary_stable, cached_total_skips_count
 */
class CommentListDeferredJoinTest extends BoardTestCase
{
    private CommentRepository $repository;

    /** 수집된 실행 쿼리 SQL 목록 */
    private array $queries = [];

    /** 댓글 작성자로 사용할 사용자 ID */
    private int $userId = 4242;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(CommentRepository::class);
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

    /**
     * 이 사용자의 댓글을 생성합니다.
     *
     * @param  int  $postId  대상 게시글 ID
     * @param  array<string, mixed>  $attributes  추가 속성
     * @return int 생성된 댓글 ID
     */
    private function createUserComment(int $postId, array $attributes = []): int
    {
        return $this->createTestComment($postId, array_merge(['user_id' => $this->userId], $attributes));
    }

    public function test_soft_deleted_comments_are_excluded(): void
    {
        $postId = $this->createTestPost();
        $this->createUserComment($postId, ['content' => '살아있는 댓글']);
        $this->createUserComment($postId, ['content' => '삭제된 댓글', 'deleted_at' => now()]);

        $result = $this->repository->getUserComments($this->userId, [], 10);
        $contents = collect($result->items())->pluck('content')->all();

        $this->assertContains('살아있는 댓글', $contents);
        $this->assertNotContains('삭제된 댓글', $contents);
        $this->assertSame(1, $result->total());
    }

    public function test_comments_of_soft_deleted_posts_are_excluded(): void
    {
        $livePostId = $this->createTestPost(['title' => '살아있는 글']);
        $deletedPostId = $this->createTestPost(['title' => '삭제된 글', 'deleted_at' => now()]);

        $this->createUserComment($livePostId, ['content' => '보여야 하는 댓글']);
        $this->createUserComment($deletedPostId, ['content' => '숨겨져야 하는 댓글']);

        $result = $this->repository->getUserComments($this->userId, [], 10);
        $contents = collect($result->items())->pluck('content')->all();

        $this->assertSame(['보여야 하는 댓글'], $contents);
        $this->assertSame(1, $result->total(), '삭제된 글의 댓글은 총 건수에서도 빠져야 한다');
    }

    public function test_other_users_comments_are_not_included(): void
    {
        $postId = $this->createTestPost();
        $this->createUserComment($postId, ['content' => '내 댓글']);
        $this->createTestComment($postId, ['user_id' => $this->userId + 1, 'content' => '남의 댓글']);

        $result = $this->repository->getUserComments($this->userId, [], 10);

        $this->assertSame(['내 댓글'], collect($result->items())->pluck('content')->all());
    }

    public function test_pages_do_not_overlap_and_last_page_is_reported(): void
    {
        $postId = $this->createTestPost();

        for ($i = 1; $i <= 15; $i++) {
            $this->createUserComment($postId, [
                'content' => "댓글 {$i}",
                'created_at' => now()->subMinutes(20 - $i),
            ]);
        }

        // 저장소는 페이지 번호를 인자로 받지 않고 Paginator 가 요청에서 해석한다.
        // 단위 테스트에는 요청 컨텍스트가 없으므로 리졸버로 현재 페이지를 지정한다.
        Paginator::currentPageResolver(fn () => 1);
        $page1 = $this->repository->getUserComments($this->userId, [], 10);

        Paginator::currentPageResolver(fn () => 2);
        $page2 = $this->repository->getUserComments($this->userId, [], 10);

        Paginator::currentPageResolver(fn () => 1);

        $this->assertSame(15, $page1->total());
        $this->assertSame(2, $page1->lastPage());
        $this->assertCount(10, $page1->items());
        $this->assertCount(5, $page2->items(), '마지막 페이지는 남은 5건만 있어야 한다');

        $firstIds = collect($page1->items())->pluck('id')->all();
        $secondIds = collect($page2->items())->pluck('id')->all();

        $this->assertEmpty(array_intersect($firstIds, $secondIds), '페이지 간 중복 행이 없어야 한다');
        $this->assertCount(15, array_unique(array_merge($firstIds, $secondIds)), '누락된 행이 없어야 한다');
    }

    public function test_search_filter_counts_only_matching_rows(): void
    {
        $postId = $this->createTestPost();
        $this->createUserComment($postId, ['content' => '검색어 포함 댓글 A']);
        $this->createUserComment($postId, ['content' => '검색어 포함 댓글 B']);
        $this->createUserComment($postId, ['content' => '무관한 댓글']);

        $result = $this->repository->getUserComments($this->userId, ['search' => '검색어'], 10);

        $this->assertSame(2, $result->total(), '검색 조건이 총 건수에 반영되어야 한다');
        $this->assertCount(2, $result->items());
    }

    public function test_cached_total_skips_the_count_query(): void
    {
        $postId = $this->createTestPost();
        $this->createUserComment($postId, ['content' => '댓글']);

        $this->startCapture();
        $result = $this->repository->getUserComments($this->userId, ['cached_total' => 999], 10);

        $this->assertEmpty($this->queriesContaining('count(*)'), '캐시된 total 이 있으면 COUNT 를 실행하지 않아야 한다');
        $this->assertSame(999, $result->total());
    }

    public function test_offset_scan_does_not_read_comment_content(): void
    {
        $postId = $this->createTestPost();

        for ($i = 1; $i <= 25; $i++) {
            $this->createUserComment($postId, ['content' => "댓글 본문 {$i}"]);
        }

        $this->startCapture();
        $this->repository->getUserComments($this->userId, [], 10);

        $offsetQueries = $this->queriesContaining('offset');

        $this->assertNotEmpty($offsetQueries, 'inner 는 OFFSET 으로 ID 집합을 구해야 한다');

        foreach ($offsetQueries as $sql) {
            $this->assertStringNotContainsString(
                'content`',
                $sql,
                'OFFSET 스캔 구간에서 댓글 본문을 읽으면 안 된다'
            );
        }
    }
}
