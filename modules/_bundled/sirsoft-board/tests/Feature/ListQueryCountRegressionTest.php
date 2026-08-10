<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 게시판 목록 조회의 쿼리 수 회귀 테스트 (#519)
 *
 * 행 수를 늘려도 쿼리 수가 늘지 않는지 단언한다 — 그것이 N+1 의 정의이고,
 * 정상적인 구조 변경(관계 추가 등)에는 반응하지 않는다.
 *
 * @scenario case=list_query_count
 *
 * @effects post_list_query_count_stable,
 *          comment_tree_query_count_stable
 */
class ListQueryCountRegressionTest extends BoardTestCase
{
    use CountsQueries;

    /**
     * 게시글을 원하는 수만큼 만듭니다.
     *
     * @param  int  $count  생성할 수
     * @param  string  $prefix  제목 접두
     */
    private function seedPosts(int $count, string $prefix): void
    {
        for ($i = 0; $i < $count; $i++) {
            Post::create([
                'board_id' => $this->board->id,
                'title' => $prefix.' '.$i,
                'content' => '본문',
                'author_name' => '작성자',
                'status' => 'published',
                'ip_address' => '127.0.0.1',
            ]);
        }
    }

    /**
     * 게시글 목록: 글 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * @effects post_list_query_count_stable
     */
    public function test_post_list_query_count_is_stable(): void
    {
        $repository = app(PostRepositoryInterface::class);
        $this->seedPosts(5, '초기');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->paginate($this->board->slug, [], 50, false, $this->board),
            grow: fn () => $this->seedPosts(10, '추가'),
            context: '게시글 목록',
        );
    }

    /**
     * 댓글 트리: 댓글 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * 종전에는 부모 댓글을 관계로 다시 불러 같은 집합을 한 번 더 가져왔다.
     *
     * @effects comment_tree_query_count_stable
     */
    public function test_comment_tree_query_count_is_stable(): void
    {
        $repository = app(CommentRepositoryInterface::class);

        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => '댓글 트리',
            'content' => '본문',
            'author_name' => '작성자',
            'status' => 'published',
            'ip_address' => '127.0.0.1',
        ]);

        $seedComments = function (int $count, string $prefix) use ($post) {
            for ($i = 0; $i < $count; $i++) {
                Comment::create([
                    'board_id' => $this->board->id,
                    'post_id' => $post->id,
                    'content' => $prefix.' '.$i,
                    'author_name' => '댓글쓴이',
                    'ip_address' => '127.0.0.1',
                ]);
            }
        };

        $seedComments(5, '초기');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->getByPostId($this->board->slug, $post->id, boardId: $this->board->id),
            grow: fn () => $seedComments(10, '추가'),
            context: '댓글 트리',
        );
    }
}
