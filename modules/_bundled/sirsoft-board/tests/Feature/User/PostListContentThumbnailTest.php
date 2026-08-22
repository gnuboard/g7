<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 목록 API 본문 썸네일 폴백 테스트 (공개 이슈 #22)
 *
 * 목록 SELECT(컬럼 프루닝)에 content_thumbnail_url 이 포함되어 첨부 없는
 * 본문이미지 글의 thumbnail 이 채워지는지, 첨부 우선·비밀글 게이트·생성/수정
 * 응답 즉시 반영까지 API 경계에서 고정합니다.
 */
class PostListContentThumbnailTest extends BoardTestCase
{
    private User $memberUser;

    protected function getTestBoardSlug(): string
    {
        return 'list-content-thumbnail';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '본문 썸네일 게시판', 'en' => 'Content Thumbnail Board'],
            'is_active' => true,
            'secret_mode' => 'enabled',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions();

        $this->memberUser = User::factory()->create(['email' => 'thumb-member@test.com']);
        $userRole = Role::where('identifier', 'user')->first();
        $this->memberUser->roles()->attach($userRole->id);
    }

    /**
     * 모델 이벤트를 경유해 게시글을 생성합니다 (saving 추출 경로 포함).
     *
     * @param  array  $attributes  덮어쓸 속성
     * @return Post 생성된 게시글
     */
    private function createPostViaModel(array $attributes = []): Post
    {
        return Post::create(array_merge([
            'board_id' => $this->board->id,
            'title' => '본문 썸네일 글',
            'content' => '<p>본문</p>',
            'content_mode' => 'html',
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
        ], $attributes));
    }

    /**
     * 목록 응답에서 특정 게시글 행을 찾습니다.
     *
     * @param  int  $postId  게시글 ID
     * @return array<string, mixed>|null 목록 행
     */
    private function findListItem(int $postId): ?array
    {
        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts");
        $response->assertStatus(200);

        foreach ($response->json('data.data') ?? [] as $item) {
            if (($item['id'] ?? null) === $postId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @scenario image_source=content_internal_only, secrecy=normal
     *
     * @effects content_internal_image_fills_list_thumbnail
     */
    #[Test]
    public function list_thumbnail_filled_from_content_image_without_attachment(): void
    {
        $post = $this->createPostViaModel([
            'content' => '<p>글</p><img src="/storage/uploads/list-content.jpg">',
        ]);

        $item = $this->findListItem($post->id);

        $this->assertNotNull($item, '목록에 게시글이 있어야 합니다.');
        $this->assertSame('/storage/uploads/list-content.jpg', $item['thumbnail']);
    }

    /**
     * @scenario image_source=both, secrecy=normal
     *
     * @effects attachment_takes_precedence_over_content_image
     */
    #[Test]
    public function list_thumbnail_prefers_attachment_over_content_image(): void
    {
        $post = $this->createPostViaModel([
            'content' => '<img src="/storage/uploads/content-image.jpg">',
        ]);

        $attachment = Attachment::create([
            'board_id' => $this->board->id,
            'post_id' => $post->id,
            'original_filename' => 'attach.jpg',
            'stored_filename' => 'stored-attach.jpg',
            'disk' => 'modules',
            'path' => 'attachments/attach.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'collection' => 'attachments',
            'order' => 0,
        ]);

        $item = $this->findListItem($post->id);

        $this->assertNotNull($item);
        $this->assertSame(
            '/api/modules/sirsoft-board/boards/'.$this->board->slug.'/attachment/'.$attachment->hash.'/preview',
            $item['thumbnail'],
            '이미지 첨부가 있으면 본문 캐시보다 첨부가 우선해야 합니다.'
        );
    }

    /**
     * @scenario image_source=content_internal_only, secrecy=secret_unauthorized
     *
     * @effects secret_post_thumbnail_stays_null
     */
    #[Test]
    public function secret_post_list_thumbnail_is_null_for_guest(): void
    {
        $post = $this->createPostViaModel([
            'is_secret' => true,
            'content' => '<img src="/storage/uploads/secret-content.jpg">',
        ]);

        $item = $this->findListItem($post->id);

        $this->assertNotNull($item, '비밀글도 목록 행 자체는 노출됩니다 (제목/배지).');
        $this->assertNull($item['thumbnail'], '권한 없는 조회에서 본문 캐시 URL 이 노출되면 안 됩니다.');

        // 응답 어디에도 이미지 URL 이 실리지 않아야 한다 (에디터 이미지는 공개 서빙)
        $raw = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts")->getContent();
        $this->assertStringNotContainsString('secret-content.jpg', $raw);
    }

    /**
     * @scenario image_source=content_external_only, secrecy=normal
     *
     * @effects external_only_content_yields_null
     */
    #[Test]
    public function external_only_content_yields_null_thumbnail_in_list(): void
    {
        $post = $this->createPostViaModel([
            'content' => '<img src="https://evil.example.org/x.jpg">',
        ]);

        $item = $this->findListItem($post->id);

        $this->assertNotNull($item);
        $this->assertNull($item['thumbnail']);
    }

    /**
     * @scenario image_source=attachment_only, secrecy=normal
     *
     * @effects attachment_takes_precedence_over_content_image
     */
    #[Test]
    public function attachment_only_post_keeps_existing_behavior(): void
    {
        $post = $this->createPostViaModel(['content' => '<p>첨부만 있는 글</p>']);

        $attachment = Attachment::create([
            'board_id' => $this->board->id,
            'post_id' => $post->id,
            'original_filename' => 'only.jpg',
            'stored_filename' => 'stored-only.jpg',
            'disk' => 'modules',
            'path' => 'attachments/only.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'collection' => 'attachments',
            'order' => 0,
        ]);

        $item = $this->findListItem($post->id);

        $this->assertNotNull($item);
        $this->assertSame(
            '/api/modules/sirsoft-board/boards/'.$this->board->slug.'/attachment/'.$attachment->hash.'/preview',
            $item['thumbnail'],
        );
    }

    /**
     * 생성 응답의 thumbnail 이 본문 이미지로 즉시 채워져야 합니다.
     *
     * @effects detail_and_og_share_same_fallback
     */
    #[Test]
    public function create_response_thumbnail_is_immediately_filled(): void
    {
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '에디터 이미지 글',
                'content' => '<p>본문</p><img src="/storage/uploads/created.jpg">',
                'content_mode' => 'html',
                'author_name' => '회원작성자',
            ]);

        $response->assertStatus(201);
        $this->assertSame('/storage/uploads/created.jpg', $response->json('data.thumbnail'));
    }

    /**
     * 수정 응답의 thumbnail 이 교체된 본문 이미지로 즉시 갱신되어야 합니다.
     *
     * @effects detail_and_og_share_same_fallback
     */
    #[Test]
    public function update_response_thumbnail_reflects_new_content(): void
    {
        $post = $this->createPostViaModel([
            'user_id' => $this->memberUser->id,
            'trigger_type' => 'user',
            'content' => '<img src="/storage/uploads/before.jpg">',
        ]);

        $response = $this->actingAs($this->memberUser)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$post->id}", [
                'title' => '수정된 글',
                'content' => '<img src="/storage/uploads/after.jpg">',
                'content_mode' => 'html',
            ]);

        $response->assertStatus(200);
        $this->assertSame('/storage/uploads/after.jpg', $response->json('data.thumbnail'));
    }
}
