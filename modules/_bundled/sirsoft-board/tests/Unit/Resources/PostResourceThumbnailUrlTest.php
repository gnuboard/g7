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

    // ── 본문 첫 내부 이미지 캐시 폴백 (공개 이슈 #22) ──────────────

    /**
     * 이미지 첨부가 없으면 본문 캐시(content_thumbnail_url)로 폴백해야 합니다.
     *
     * @scenario image_source=content_internal_only, secrecy=normal
     *
     * @effects content_internal_image_fills_list_thumbnail
     */
    #[Test]
    public function thumbnail_falls_back_to_content_cache_without_attachment(): void
    {
        $postId = $this->createTestPost([
            'content' => '<img src="/storage/uploads/content.jpg">',
            'content_thumbnail_url' => '/storage/uploads/content.jpg',
        ]);

        $post = Post::with(['thumbnailAttachment', 'board'])->findOrFail($postId);

        $response = (new PostResource($post))->toArray(Request::create('/'));

        $this->assertSame('/storage/uploads/content.jpg', $response['thumbnail']);
    }

    /**
     * 이미지 첨부와 본문 캐시가 모두 있으면 첨부가 우선해야 합니다 (첨부 우선 정책).
     *
     * @scenario image_source=both, secrecy=normal
     *
     * @effects attachment_takes_precedence_over_content_image
     */
    #[Test]
    public function attachment_takes_precedence_over_content_cache(): void
    {
        ['post' => $post, 'attachment' => $attachment] = $this->createPostWithAttachment('image/jpeg', [
            'content_thumbnail_url' => '/storage/uploads/content.jpg',
        ]);

        $response = (new PostResource($post))->toArray(Request::create('/'));

        $this->assertSame(
            '/api/modules/sirsoft-board/boards/'.$this->board->slug.'/attachment/'.$attachment->hash.'/preview',
            $response['thumbnail'],
        );
    }

    /**
     * 비밀글은 본문 캐시가 있어도 썸네일이 null 이어야 합니다.
     *
     * 에디터 이미지는 공개 hash 서빙이라 첨부와 달리 서빙측 차단이 없다 —
     * 이 게이트가 유일한 차단선이므로 폴백은 반드시 게이트 뒤에 있어야 한다.
     *
     * @scenario image_source=content_internal_only, secrecy=secret_unauthorized
     *
     * @effects secret_post_thumbnail_stays_null
     */
    #[Test]
    public function secret_post_thumbnail_null_even_with_content_cache(): void
    {
        $postId = $this->createTestPost([
            'is_secret' => true,
            'content' => '<img src="/storage/uploads/secret.jpg">',
            'content_thumbnail_url' => '/storage/uploads/secret.jpg',
        ]);

        $post = Post::with(['thumbnailAttachment', 'board'])->findOrFail($postId);

        $response = (new PostResource($post))->toArray(Request::create('/'));

        $this->assertArrayHasKey('thumbnail', $response);
        $this->assertNull($response['thumbnail'], '비밀글은 본문 캐시 URL 도 방출되지 않아야 합니다.');
    }

    /**
     * 첨부도 본문 캐시도 없으면 null 이어야 합니다.
     *
     * @scenario image_source=none, secrecy=normal
     *
     * @effects external_only_content_yields_null
     */
    #[Test]
    public function thumbnail_is_null_without_attachment_and_cache(): void
    {
        $postId = $this->createTestPost(['content' => '<p>이미지 없음</p>']);

        $post = Post::with(['thumbnailAttachment', 'board'])->findOrFail($postId);

        $response = (new PostResource($post))->toArray(Request::create('/'));

        $this->assertNull($response['thumbnail']);
    }
}
