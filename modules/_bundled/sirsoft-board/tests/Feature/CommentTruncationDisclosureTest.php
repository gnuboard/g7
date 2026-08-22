<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedCount;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 댓글 목록 절단 노출 계약 테스트 (#519)
 *
 * 한 글의 댓글은 상한을 넘으면 그 지점에서 끊긴다. 끊긴 사실을 알리지 않으면
 * 사용자에게는 "댓글이 그만큼뿐" 으로 보인다 — 오류도 빈 화면도 아니라서
 * 화면만 봐서는 알아챌 수 없는 유형이다.
 */
class CommentTruncationDisclosureTest extends BoardTestCase
{
    /**
     * 게시글 하나와 댓글 N 건을 만듭니다.
     *
     * @param  int  $commentCount  만들 댓글 수
     * @return Post 생성된 게시글
     */
    private function seedPostWithComments(int $commentCount): Post
    {
        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => '댓글 절단 테스트',
            'content' => '본문',
            'author_name' => '작성자',
            'status' => 'published',
            'ip_address' => '127.0.0.1',
        ]);

        for ($i = 0; $i < $commentCount; $i++) {
            Comment::create([
                'board_id' => $this->board->id,
                'post_id' => $post->id,
                'content' => '댓글 '.$i,
                'author_name' => '댓글쓴이',
                'ip_address' => '127.0.0.1',
            ]);
        }

        return $post;
    }

    /**
     * 상한 이하면 댓글 전량이 나오고 절단 표시가 없는지 확인
     */
    public function test_comments_are_not_truncated_under_cap(): void
    {
        config(['g7_settings.core.pagination.result_cap' => 100]);
        $post = $this->seedPostWithComments(5);

        $repository = app(CommentRepositoryInterface::class);
        $comments = $repository->getByPostId($this->board->slug, $post->id, boardId: $this->board->id);

        $this->assertCount(5, $comments);

        $count = $repository->countByPostId($this->board->slug, $post->id, boardId: $this->board->id);
        $this->assertInstanceOf(BoundedCount::class, $count);
        $this->assertSame(5, $count->total);
        $this->assertFalse($count->isTruncated());
    }

    /**
     * 상한을 넘으면 목록이 그 지점에서 끊기고 총 건수가 "이상" 으로 보고되는지 확인
     */
    public function test_comment_count_reports_truncation_over_cap(): void
    {
        config(['g7_settings.core.pagination.result_cap' => 3]);
        $post = $this->seedPostWithComments(7);

        $repository = app(CommentRepositoryInterface::class);

        $comments = $repository->getByPostId($this->board->slug, $post->id, boardId: $this->board->id);
        $this->assertLessThanOrEqual(3, $comments->count(), '상한을 넘겨 조회됐다 — 목록이 끊기지 않았다');

        $count = $repository->countByPostId($this->board->slug, $post->id, boardId: $this->board->id);
        $this->assertSame(3, $count->total);
        $this->assertSame(TotalRelation::AtLeast, $count->totalRelation());
        $this->assertTrue($count->isTruncated());
    }

    /**
     * 상세 응답이 절단 사실을 필드로 알리는지 확인
     *
     * 저장소가 정확도를 돌려줘도 화면까지 도달하지 않으면 아무 소용이 없다.
     */
    public function test_post_detail_response_discloses_truncation(): void
    {
        config(['g7_settings.core.pagination.result_cap' => 3]);
        $post = $this->seedPostWithComments(7);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$post->id}");

        $response->assertOk();
        $response->assertJsonPath('data.comments_truncated', true);
        $response->assertJsonPath('data.comments_total', 3);
        $response->assertJsonPath('data.comments_total_is_exact', false);
    }

    /**
     * 끊기지 않은 경우에는 절단 플래그가 서지 않는지 확인
     */
    public function test_post_detail_response_reports_no_truncation_when_under_cap(): void
    {
        config(['g7_settings.core.pagination.result_cap' => 100]);
        $post = $this->seedPostWithComments(4);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$post->id}");

        $response->assertOk();
        $response->assertJsonPath('data.comments_truncated', false);
        $response->assertJsonPath('data.comments_total_is_exact', true);
    }
}
