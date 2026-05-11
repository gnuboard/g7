<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PostResource attachment_count 응답 키 회귀 테스트 (이슈 #304 Phase 2)
 *
 * 목적:
 * - PostResource 응답에 attachment_count (정수) 가 노출되는지 검증
 * - has_attachment (boolean) 와 attachment_count > 0 의 의미가 일치하는지 검증
 * - has_attachment 는 목록 화면 호환성을 위해 그대로 유지됨을 확인
 */
class PostResourceAttachmentCountTest extends BoardTestCase
{
    #[Test]
    public function attachment_count_is_exposed_as_integer(): void
    {
        $postId = $this->createTestPostWithCounts(['attachments_count' => 4]);

        $response = $this->resourceArray($postId);

        $this->assertArrayHasKey('attachment_count', $response, 'PostResource 응답에 attachment_count 키가 있어야 합니다');
        $this->assertSame(4, $response['attachment_count']);
    }

    #[Test]
    public function attachment_count_is_zero_when_no_attachments(): void
    {
        $postId = $this->createTestPostWithCounts(['attachments_count' => 0]);

        $response = $this->resourceArray($postId);

        $this->assertSame(0, $response['attachment_count']);
        $this->assertFalse($response['has_attachment']);
    }

    #[Test]
    public function has_attachment_remains_true_when_attachment_count_is_positive(): void
    {
        $postId = $this->createTestPostWithCounts(['attachments_count' => 2]);

        $response = $this->resourceArray($postId);

        $this->assertTrue($response['has_attachment'], 'attachment_count > 0 인 경우 has_attachment 는 true 여야 합니다');
        $this->assertGreaterThan(0, $response['attachment_count']);
    }

    #[Test]
    public function has_attachment_and_attachment_count_are_consistent(): void
    {
        // attachments_count = 0 → has_attachment = false, attachment_count = 0
        $zeroId = $this->createTestPostWithCounts(['attachments_count' => 0]);
        $zeroResp = $this->resourceArray($zeroId);
        $this->assertSame($zeroResp['has_attachment'], $zeroResp['attachment_count'] > 0);

        // attachments_count = 5 → has_attachment = true, attachment_count = 5
        $manyId = $this->createTestPostWithCounts(['attachments_count' => 5]);
        $manyResp = $this->resourceArray($manyId);
        $this->assertSame($manyResp['has_attachment'], $manyResp['attachment_count'] > 0);
    }

    #[Test]
    public function comment_count_and_reply_count_remain_singular(): void
    {
        $postId = $this->createTestPostWithCounts([
            'comments_count' => 3,
            'replies_count' => 2,
            'attachments_count' => 1,
        ]);

        $response = $this->resourceArray($postId);

        $this->assertArrayHasKey('comment_count', $response);
        $this->assertArrayHasKey('reply_count', $response);
        $this->assertArrayHasKey('attachment_count', $response);
        $this->assertSame(3, $response['comment_count']);
        $this->assertSame(2, $response['reply_count']);
        $this->assertSame(1, $response['attachment_count']);
    }

    /**
     * board_posts 행을 직접 생성하고 카운트 컬럼을 지정합니다.
     */
    private function createTestPostWithCounts(array $counts): int
    {
        return DB::table('board_posts')->insertGetId(array_merge([
            'board_id' => $this->board->id,
            'title' => '카운트 테스트',
            'content' => '내용',
            'user_id' => null,
            'author_name' => '테스트',
            'password' => null,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'replies_count' => 0,
            'comments_count' => 0,
            'attachments_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $counts));
    }

    /**
     * Post 모델 인스턴스 → PostResource toArray 결과를 반환합니다.
     */
    private function resourceArray(int $postId): array
    {
        $post = Post::findOrFail($postId);
        $request = Request::create('/');

        return (new PostResource($post))->toArray($request);
    }
}
