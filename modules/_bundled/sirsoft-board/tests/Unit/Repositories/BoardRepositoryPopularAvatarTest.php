<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Repositories;

use App\Models\Attachment as CoreAttachment;
use App\Models\User;
use Modules\Sirsoft\Board\Repositories\BoardRepository;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 인기 게시물 아바타 URL 호출측 회귀 테스트 (#100 URL 조립 단일화)
 *
 * urlForHash() 헬퍼 자체가 아니라 **호출측**(BoardRepository getPopularPosts)이
 * 조인으로 얻은 hash 를 코어 단일 지점에 위임해 응답 문자열이 기존과 동일하게
 * 직렬화되는지 고정합니다.
 */
class BoardRepositoryPopularAvatarTest extends BoardTestCase
{
    /**
     * @effects board_response_strings_unchanged
     */
    #[Test]
    public function popular_posts_avatar_serializes_core_attachment_url(): void
    {
        $user = User::factory()->create();

        $avatar = CoreAttachment::create([
            'attachmentable_type' => User::class,
            'attachmentable_id' => $user->id,
            'original_filename' => 'avatar.png',
            'stored_filename' => 'stored-avatar.png',
            'disk' => 'local',
            'path' => 'avatars/avatar.png',
            'mime_type' => 'image/png',
            'size' => 100,
            'collection' => 'avatar',
        ]);

        $postId = $this->createTestPost([
            'user_id' => $user->id,
            'view_count' => 99,
            'comments_count' => 0,
            'replies_count' => 0,
            'attachments_count' => 0,
        ]);

        $posts = app(BoardRepository::class)->getPopularPosts('year', 10);

        $target = collect($posts)->firstWhere('id', $postId);
        $this->assertNotNull($target, '생성한 게시글이 인기 게시물 목록에 있어야 합니다');
        $this->assertSame('/api/attachment/'.$avatar->hash, $target['author']['avatar']);
    }

    /**
     * @effects board_response_strings_unchanged
     */
    #[Test]
    public function popular_posts_avatar_is_null_without_avatar_attachment(): void
    {
        $user = User::factory()->create();

        $postId = $this->createTestPost([
            'user_id' => $user->id,
            'view_count' => 98,
            'comments_count' => 0,
            'replies_count' => 0,
            'attachments_count' => 0,
        ]);

        $posts = app(BoardRepository::class)->getPopularPosts('year', 10);

        $target = collect($posts)->firstWhere('id', $postId);
        $this->assertNotNull($target);
        $this->assertNull($target['author']['avatar']);
    }
}
