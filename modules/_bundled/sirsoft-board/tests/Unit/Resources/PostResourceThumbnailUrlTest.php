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
    private function createPostWithAttachment(string $mimeType, array $postAttributes = []): array
    {
        $postId = $this->createTestPost($postAttributes);

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

    /**
     * 비밀글의 썸네일 URL은 열람 권한이 없으면 방출되지 않아야 합니다 (KVE-2026-1894).
     *
     * 서빙은 막혀 있어 이미지가 보이지는 않지만, URL 에 실린 첨부 해시 자체가
     * 목록·상세 응답으로 나가 있었다. 필드는 남기고 값만 가린다.
     *
     * @effects secret_post_thumbnail_hash_not_exposed
     */
    #[Test]
    public function thumbnail_is_null_for_secret_post_without_permission(): void
    {
        ['post' => $post] = $this->createPostWithAttachment('image/jpeg', ['is_secret' => true]);

        $response = (new PostResource($post))->toArray(Request::create('/'));

        $this->assertArrayHasKey('thumbnail', $response, 'thumbnail 키 자체는 유지되어야 합니다.');
        $this->assertNull($response['thumbnail']);
    }

    /**
     * 비밀글이 아니면 썸네일 URL이 그대로 유지되어야 합니다 (선택적 차단 회귀 방지).
     *
     * @effects secret_post_thumbnail_hash_not_exposed
     */
    #[Test]
    public function thumbnail_is_preserved_for_non_secret_post(): void
    {
        ['post' => $post, 'attachment' => $attachment] = $this->createPostWithAttachment('image/jpeg');

        $response = (new PostResource($post))->toArray(Request::create('/'));

        $this->assertSame(
            '/api/modules/sirsoft-board/boards/'.$this->board->slug.'/attachment/'.$attachment->hash.'/preview',
            $response['thumbnail'],
        );
    }
}
