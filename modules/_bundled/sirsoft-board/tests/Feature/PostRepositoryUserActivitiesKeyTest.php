<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PostRepository 사용자 활동 응답 키 단수형 회귀 테스트 (이슈 #304 Phase 2)
 *
 * 목적:
 * - PostResource 를 거치지 않고 raw 응답을 만드는 메서드도
 *   PostResource 와 동일하게 단수형 키(`comment_count`)를 사용해야 한다.
 * - 검증 대상: getUserActivities('authored') / getUserActivities('commented')
 */
class PostRepositoryUserActivitiesKeyTest extends BoardTestCase
{
    private function repository(): PostRepositoryInterface
    {
        return $this->app->make(PostRepositoryInterface::class);
    }

    #[Test]
    public function authored_activity_response_uses_singular_comment_count_key(): void
    {
        $user = $this->createUser();

        $postId = $this->createTestPost([
            'user_id' => $user->id,
            'title' => '내 글',
        ]);

        // comments_count DB 컬럼에 직접 값 세팅 (단순 키 매핑 테스트)
        DB::table('board_posts')->where('id', $postId)->update(['comments_count' => 4]);

        $paginator = $this->repository()->getUserActivities(
            $user->id,
            ['activity_type' => 'authored', 'board_slug' => $this->board->slug],
            20
        );

        $items = $paginator->items();
        $this->assertNotEmpty($items, 'authored 활동에 게시글이 1건 이상 있어야 합니다');

        $first = $items[0];
        $this->assertArrayHasKey('comment_count', $first, 'authored 활동 응답은 단수형 comment_count 키를 사용해야 합니다');
        $this->assertArrayNotHasKey('comments_count', $first, 'authored 활동 응답에 복수형 comments_count 키가 노출되어서는 안 됩니다');
        $this->assertSame(4, $first['comment_count']);
    }

    #[Test]
    public function commented_activity_response_uses_singular_comment_count_key(): void
    {
        $user = $this->createUser();

        // 다른 사용자의 글에 댓글을 작성
        $otherUser = $this->createUser();
        $postId = $this->createTestPost([
            'user_id' => $otherUser->id,
            'title' => '다른 사람 글',
        ]);

        $this->createTestComment($postId, [
            'user_id' => $user->id,
            'content' => '내가 단 댓글',
        ]);

        DB::table('board_posts')->where('id', $postId)->update(['comments_count' => 7]);

        $paginator = $this->repository()->getUserActivities(
            $user->id,
            ['activity_type' => 'commented', 'board_slug' => $this->board->slug],
            20
        );

        $items = $paginator->items();
        $this->assertNotEmpty($items, 'commented 활동에 게시글이 1건 이상 있어야 합니다');

        $first = $items[0];
        $this->assertArrayHasKey('comment_count', $first, 'commented 활동 응답은 단수형 comment_count 키를 사용해야 합니다');
        $this->assertArrayNotHasKey('comments_count', $first, 'commented 활동 응답에 복수형 comments_count 키가 노출되어서는 안 됩니다');
        $this->assertSame(7, $first['comment_count']);
    }
}
