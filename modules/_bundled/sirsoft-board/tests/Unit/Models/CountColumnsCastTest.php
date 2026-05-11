<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Models;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 카운트 컬럼 모델 보강 회귀 테스트 (이슈 #304 Phase 2)
 *
 * 마이그레이션으로 추가된 카운트 컬럼이 모델 fillable·casts 양쪽에 반영되어
 * mass-assignment + integer cast 가 정상 동작하는지 검증한다.
 *
 * 대상 컬럼:
 * - boards.posts_count, boards.comments_count
 * - board_posts.replies_count, board_posts.comments_count, board_posts.attachments_count
 * - board_comments.replies_count
 */
class CountColumnsCastTest extends BoardTestCase
{
    // =========================================================================
    // Board 모델
    // =========================================================================

    #[Test]
    public function board_posts_count_is_fillable_and_cast_to_integer(): void
    {
        $board = Board::create([
            'slug' => 'cast-board-posts',
            'name' => ['ko' => '캐스트 테스트', 'en' => 'Cast Test'],
            'is_active' => true,
            'posts_count' => '5',
        ]);

        $this->assertSame(5, $board->refresh()->posts_count, 'posts_count 는 정수로 캐스트되어야 합니다');
    }

    #[Test]
    public function board_comments_count_is_fillable_and_cast_to_integer(): void
    {
        $board = Board::create([
            'slug' => 'cast-board-comments',
            'name' => ['ko' => '캐스트 테스트', 'en' => 'Cast Test'],
            'is_active' => true,
            'comments_count' => '3',
        ]);

        $this->assertSame(3, $board->refresh()->comments_count, 'comments_count 는 정수로 캐스트되어야 합니다');
    }

    // =========================================================================
    // Post 모델
    // =========================================================================

    #[Test]
    public function post_replies_count_is_fillable_and_cast_to_integer(): void
    {
        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => '캐스트 테스트',
            'content' => '내용',
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'replies_count' => '2',
        ]);

        $this->assertSame(2, $post->refresh()->replies_count, 'replies_count 는 정수로 캐스트되어야 합니다');
    }

    #[Test]
    public function post_comments_count_is_fillable_and_cast_to_integer(): void
    {
        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => '캐스트 테스트',
            'content' => '내용',
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'comments_count' => '7',
        ]);

        $this->assertSame(7, $post->refresh()->comments_count, 'comments_count 는 정수로 캐스트되어야 합니다');
    }

    #[Test]
    public function post_attachments_count_is_fillable_and_cast_to_integer(): void
    {
        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => '캐스트 테스트',
            'content' => '내용',
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'attachments_count' => '4',
        ]);

        $this->assertSame(4, $post->refresh()->attachments_count, 'attachments_count 는 정수로 캐스트되어야 합니다');
    }

    // =========================================================================
    // Comment 모델
    // =========================================================================

    #[Test]
    public function comment_replies_count_is_fillable_and_cast_to_integer(): void
    {
        $postId = $this->createTestPost();

        $comment = Comment::create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'author_name' => '테스트',
            'content' => '댓글',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'replies_count' => '6',
        ]);

        $this->assertSame(6, $comment->refresh()->replies_count, 'Comment.replies_count 는 정수로 캐스트되어야 합니다');
    }

    // =========================================================================
    // 정수 캐스트 — DB 에서 문자열로 읽혀도 정수로 반환
    // =========================================================================

    #[Test]
    public function post_count_columns_are_cast_to_integer_when_loaded_from_db(): void
    {
        $postId = DB::table('board_posts')->insertGetId([
            'board_id' => $this->board->id,
            'title' => '로드 캐스트 테스트',
            'content' => '내용',
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'replies_count' => 1,
            'comments_count' => 2,
            'attachments_count' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $post = Post::findOrFail($postId);

        $this->assertSame(1, $post->replies_count);
        $this->assertSame(2, $post->comments_count);
        $this->assertSame(3, $post->attachments_count);
    }
}
