<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Repositories;

// ModuleTestCase 수동 로드 (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 마이페이지 「내가 댓글 단 글」 목록 지연 조인 회귀 테스트
 *
 * 이 목록은 `board_posts ⋈ board_comments`(1:N 증폭) + 최근 댓글 상관 서브쿼리 + `groupBy`
 * 조합이라 다른 목록과 성질이 다르다. 두 가지가 조용히 깨질 수 있다.
 *
 *   ① 최근 댓글 서브쿼리가 inner(OFFSET 스캔)에 남으면, 건너뛸 행 전체에 대해 상관
 *      서브쿼리가 실행돼 뒤쪽 페이지의 비용이 그대로 남는다.
 *   ② 그룹 쿼리의 총 건수를 `count()` 로 세면 첫 그룹의 행 수가 총 건수로 잡힌다.
 *      검색/게시판 필터가 걸리면 캐시 total 을 쓰지 않아 이 경로가 실제로 탄다.
 *
 * @scenario case=user_commented_activity_deferred_join
 *
 * @effects inner_excludes_wide_columns_from_offset_scan, grouped_query_total_counts_groups_not_first_group_rows
 */
class UserCommentedActivityDeferredJoinTest extends BoardTestCase
{
    /** 수집된 실행 쿼리 SQL 목록 */
    private array $queries = [];

    /**
     * 대상 저장소를 돌려줍니다.
     *
     * @return PostRepositoryInterface 게시글 저장소
     */
    private function repository(): PostRepositoryInterface
    {
        return $this->app->make(PostRepositoryInterface::class);
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

    public function test_offset_scan_excludes_latest_comment_subquery(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();

        for ($i = 1; $i <= 25; $i++) {
            $postId = $this->createTestPost(['user_id' => $other->id, 'title' => "글 {$i}"]);
            $this->createTestComment($postId, ['user_id' => $user->id, 'content' => "댓글 {$i}"]);
        }

        $this->startCapture();
        $this->repository()->getUserActivities($user->id, ['activity_type' => 'commented'], 10);

        $offsetQueries = $this->queriesContaining('offset');

        $this->assertNotEmpty($offsetQueries, 'inner 는 OFFSET 으로 ID 집합을 구해야 한다');

        foreach ($offsetQueries as $sql) {
            $this->assertStringNotContainsString(
                'as `lc`',
                $sql,
                'OFFSET 스캔 구간에 최근 댓글 서브쿼리가 남으면 건너뛸 행마다 상관 서브쿼리가 실행된다'
            );
            $this->assertStringNotContainsString(
                'substring',
                strtolower($sql),
                'OFFSET 스캔 구간에서 본문을 읽으면 안 된다'
            );
        }
    }

    public function test_latest_comment_columns_are_available_in_result(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();

        $postId = $this->createTestPost(['user_id' => $other->id, 'title' => '대상 글']);
        $this->createTestComment($postId, [
            'user_id' => $user->id,
            'content' => '예전 댓글',
            'created_at' => now()->subDay(),
        ]);
        $this->createTestComment($postId, [
            'user_id' => $user->id,
            'content' => '가장 최근 댓글',
            'created_at' => now(),
        ]);

        $items = $this->repository()
            ->getUserActivities($user->id, ['activity_type' => 'commented'], 10)
            ->items();

        $this->assertCount(1, $items, '같은 글에 댓글을 여러 번 달아도 목록에는 글 1건으로 접혀야 한다');
        $this->assertSame(2, $items[0]['activity_count'], '해당 글에 단 댓글 수가 집계되어야 한다');
    }

    public function test_total_counts_posts_not_comments_when_filter_bypasses_cache(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();

        // 첫 글에만 댓글 3건 — 그룹 수(3)와 첫 그룹의 행 수(3)가 우연히 같아지지 않도록
        // 나머지 글은 댓글 1건씩 둔다.
        $firstPost = $this->createTestPost(['user_id' => $other->id, 'title' => '검색어 글 A']);
        for ($i = 0; $i < 3; $i++) {
            $this->createTestComment($firstPost, ['user_id' => $user->id, 'content' => '검색어 댓글']);
        }

        foreach (['검색어 글 B', '검색어 글 C', '검색어 글 D', '검색어 글 E'] as $title) {
            $postId = $this->createTestPost(['user_id' => $other->id, 'title' => $title]);
            $this->createTestComment($postId, ['user_id' => $user->id, 'content' => '검색어 댓글']);
        }

        // 검색 필터가 걸리면 서비스가 캐시 total 을 담지 않으므로 trait 이 직접 COUNT 한다.
        $paginator = $this->repository()->getUserActivities(
            $user->id,
            ['activity_type' => 'commented', 'search' => '검색어'],
            10
        );

        $this->assertSame(5, $paginator->total(), '총 건수는 댓글 수가 아니라 글(그룹) 수여야 한다');
        $this->assertCount(5, $paginator->items());
    }

    public function test_pages_do_not_overlap(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();

        for ($i = 1; $i <= 15; $i++) {
            $postId = $this->createTestPost([
                'user_id' => $other->id,
                'title' => "글 {$i}",
                'created_at' => now()->subMinutes(20 - $i),
            ]);
            $this->createTestComment($postId, ['user_id' => $user->id, 'content' => "댓글 {$i}"]);
        }

        $paginator = $this->repository()->getUserActivities($user->id, ['activity_type' => 'commented'], 10);

        $this->assertSame(15, $paginator->total());
        $this->assertSame(2, $paginator->lastPage());
        $this->assertCount(10, $paginator->items());
    }
}
