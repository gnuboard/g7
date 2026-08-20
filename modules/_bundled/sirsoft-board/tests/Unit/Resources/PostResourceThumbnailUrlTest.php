<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PostResource 썸네일 URL 호출측 회귀 테스트 (#100 URL 조립 단일화)
 *
 * previewUrlForSlug() 헬퍼 자체가 아니라 **호출측**(PostResource
 * getThumbnailUrlFromRelations)이 slug 인자를 올바르게 전달해 응답 문자열이
 * 기존과 동일하게 직렬화되는지 고정합니다 — 헬퍼 출력만 고정하면 인자 전달
 * 오류(슬러그 누락 등)가 전부 green 으로 통과합니다.
 */
class PostResourceThumbnailUrlTest extends BoardTestCase
{
    /**
     * 이미지 첨부를 가진 게시글을 생성합니다.
     *
     * @param  string  $mimeType  첨부 MIME 타입
     * @return array{post: Post, attachment: Attachment} 게시글/첨부
     */
    private function createPostWithAttachment(string $mimeType): array
    {
        $postId = $this->createTestPost();

        $attachment = Attachment::create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'original_filename' => 'thumb.jpg',
            'stored_filename' => 'stored-thumb.jpg',
            'disk' => 'modules',
            'path' => 'attachments/thumb.jpg',
            'mime_type' => $mimeType,
            'size' => 100,
            'collection' => 'attachments',
            'order' => 0,
        ]);

        $post = Post::with(['thumbnailAttachment', 'board'])->findOrFail($postId);

        return ['post' => $post, 'attachment' => $attachment];
    }

    /**
     * @effects board_response_strings_unchanged
     */
    #[Test]
    public function thumbnail_serializes_preview_url_with_board_slug(): void
    {
        ['post' => $post, 'attachment' => $attachment] = $this->createPostWithAttachment('image/jpeg');

        $response = (new PostResource($post))->toArray(Request::create('/'));

        $this->assertSame(
            '/api/modules/sirsoft-board/boards/'.$this->board->slug.'/attachment/'.$attachment->hash.'/preview',
            $response['thumbnail'],
        );
    }

    /**
     * @effects board_response_strings_unchanged
     */
    #[Test]
    public function thumbnail_is_null_for_non_image_attachment(): void
    {
        ['post' => $post] = $this->createPostWithAttachment('application/pdf');

        $response = (new PostResource($post))->toArray(Request::create('/'));

        $this->assertNull($response['thumbnail']);
    }
}
