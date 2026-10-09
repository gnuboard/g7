<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 읽는 사람의 행동(조회·스크랩·반응)이 게시글·댓글의 수정 시각을 바꾸지 않는지 검증합니다.
 *
 * 글의 수정 시각(`updated_at`)은 글을 고친 시각이다 — 보유기간 IP 파기도 이 값을 건드리지 않고,
 * 사이트맵의 `lastmod` 도 이 값을 쓴다. 그런데 조회수·스크랩 수·반응 집계 칸을 Eloquent 빌더로
 * 올리면 `updated_at` 이 함께 갱신돼, 관리자나 회원이 글을 열기만 해도 수정 시각이 바뀌었다
 * (7.1.0 브라우저 실측 O-H6: 관리자 상세 열기 전 16:21:57 → 연 뒤 16:22:34).
 */
class PostUpdatedAtReaderCounterTest extends BoardTestCase
{
    /**
     * 테스트 게시판 slug
     *
     * @return string 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'updated-at-reader';
    }

    /**
     * 수정 시각을 과거 고정값으로 둔 게시글을 만듭니다.
     *
     * @return int 게시글 ID
     */
    private function createPostWithFixedUpdatedAt(): int
    {
        return $this->createTestPost([
            'title' => '수정 시각 고정 글',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 03:04:05',
        ]);
    }

    /**
     * 게시글의 현재 수정 시각 문자열을 읽습니다.
     *
     * @param  int  $postId  게시글 ID
     * @return string|null 수정 시각
     */
    private function postUpdatedAt(int $postId): ?string
    {
        return DB::table('board_posts')->where('id', $postId)->value('updated_at');
    }

    /**
     * 조회수를 올려도 게시글 수정 시각은 그대로다.
     *
     * @scenario counter=view_count,path=repository
     *
     * @effects reader_counter_keeps_post_updated_at
     *
     * @return void 반환값 없음
     */
    public function test_view_count_increment_keeps_updated_at(): void
    {
        $postId = $this->createPostWithFixedUpdatedAt();

        $count = app(PostRepositoryInterface::class)->incrementViewCount($this->board->slug, $postId, $this->board->id);

        $this->assertSame(1, $count);
        $this->assertSame('2026-01-02 03:04:05', $this->postUpdatedAt($postId));
    }

    /**
     * 관리자 게시글 상세를 열어도 게시글 수정 시각은 그대로다(조회수는 오른다).
     *
     * @scenario counter=view_count,path=admin_detail_api
     *
     * @effects admin_detail_open_keeps_post_updated_at
     *
     * @return void 반환값 없음
     */
    public function test_opening_admin_post_detail_keeps_updated_at(): void
    {
        $postId = $this->createPostWithFixedUpdatedAt();
        $admin = $this->createAdminUser(["sirsoft-board.{$this->board->slug}.admin.posts.read"]);

        $this->actingAs($admin)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}")
            ->assertStatus(200);

        $this->assertSame(1, (int) DB::table('board_posts')->where('id', $postId)->value('view_count'));
        $this->assertSame('2026-01-02 03:04:05', $this->postUpdatedAt($postId));
    }

    /**
     * 스크랩 수를 올리고 내려도 게시글 수정 시각은 그대로다.
     *
     * @scenario counter=scrap_count,path=repository
     *
     * @effects reader_counter_keeps_post_updated_at
     *
     * @return void 반환값 없음
     */
    public function test_scrap_count_change_keeps_updated_at(): void
    {
        $postId = $this->createPostWithFixedUpdatedAt();
        $repository = app(PostRepositoryInterface::class);

        $this->assertSame(1, $repository->incrementScrapCount($postId));
        $this->assertSame('2026-01-02 03:04:05', $this->postUpdatedAt($postId));

        $this->assertSame(0, $repository->decrementScrapCount($postId));
        $this->assertSame('2026-01-02 03:04:05', $this->postUpdatedAt($postId));
    }

    /**
     * 반응 집계를 다시 써도 게시글·댓글 수정 시각은 그대로다.
     *
     * @scenario counter=reaction_counts,path=repository
     *
     * @effects reaction_recalculation_keeps_comment_updated_at
     *
     * @return void 반환값 없음
     */
    public function test_reaction_recalculation_keeps_updated_at(): void
    {
        $postId = $this->createPostWithFixedUpdatedAt();
        $commentId = $this->createTestComment($postId, [
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 03:04:05',
        ]);
        $repository = app(ReactionRepositoryInterface::class);

        $repository->recalculateReactionCounts(ReactionService::TARGET_POST, $postId);
        $repository->recalculateReactionCounts(ReactionService::TARGET_COMMENT, $commentId);

        $this->assertSame('2026-01-02 03:04:05', $this->postUpdatedAt($postId));
        $this->assertSame('2026-01-02 03:04:05', DB::table('board_comments')->where('id', $commentId)->value('updated_at'));
    }
}
