<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 사용자 공개 게시글 API 테스트
 *
 * 타인의 공개 프로필에서 게시글 목록과 통계를 조회하는 API 테스트입니다.
 * BoardTestCase를 통해 테스트 보드와 데이터 정리를 자동으로 처리합니다.
 *
 * @group board
 * @group user-public-posts
 */
class UserPublicPostsApiTest extends BoardTestCase
{
    /**
     * 테스트용 사용자 (게시글 작성자)
     */
    private User $targetUser;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'user-public-posts';
    }

    /**
     * 기본 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '공개 게시글 테스트 게시판', 'en' => 'Public Posts Test Board'],
            'is_active' => true,
            'blocked_keywords' => [],
        ];
    }

    /**
     * 각 테스트 전 실행
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 다른 테스트 클래스에서 user_id=1로 커밋된 posts가 user_id 기반 API에 영향을 미치므로 전체 정리
        // (truncateUserTables() 후 새 user는 항상 id=1을 받으므로 타 테스트 posts와 충돌)
        DB::table('board_posts')->delete();
        DB::table('board_comments')->delete();

        // 테스트 대상 사용자 생성 (parent::setUp()에서 users 테이블 초기화됨)
        $this->targetUser = User::factory()->create();
    }

    /**
     * 각 테스트 후 실행
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * 비인증 사용자가 다른 사용자의 공개 게시글을 조회할 수 있다.
     */
    public function test_guest_can_fetch_user_public_posts(): void
    {
        // Given: 사용자가 게시글을 작성했을 때
        $this->createPost($this->board->slug, [
            'user_id' => $this->targetUser->id,
            'title' => '공개 게시글',
            'status' => PostStatus::Published->value,
        ]);

        // When: 비인증 사용자가 해당 사용자의 게시글을 조회하면
        $response = $this->getJson("/api/modules/sirsoft-board/users/{$this->targetUser->id}/posts");

        // Then: 성공 응답과 함께 게시글이 반환되어야 함
        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertEquals('공개 게시글', $data[0]['title']);
    }

    /**
     * 비밀글도 사용자 프로필 게시글 목록에 포함된다 (is_secret 배지로 구분).
     *
     * getUserPublicPosts는 모든 게시글을 표시하며 비밀글/블라인드는 배지로 구분합니다.
     */
    public function test_secret_posts_are_included_with_badge(): void
    {
        // Given: 공개글과 비밀글을 작성
        $this->createPost($this->board->slug, [
            'user_id' => $this->targetUser->id,
            'title' => '공개 게시글',
            'is_secret' => false,
            'status' => PostStatus::Published->value,
        ]);

        $this->createPost($this->board->slug, [
            'user_id' => $this->targetUser->id,
            'title' => '비밀 게시글',
            'is_secret' => true,
            'status' => PostStatus::Published->value,
        ]);

        // When: 사용자 게시글을 조회하면
        $response = $this->getJson("/api/modules/sirsoft-board/users/{$this->targetUser->id}/posts");

        // Then: 모든 게시글이 반환되며 is_secret 배지로 구분
        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertCount(2, $data);
    }

    /**
     * 블라인드 처리된 게시글도 사용자 프로필 게시글 목록에 포함된다 (status 배지로 구분).
     *
     * getUserPublicPosts는 모든 게시글을 표시하며 비밀글/블라인드는 배지로 구분합니다.
     */
    public function test_blinded_posts_are_included_with_badge(): void
    {
        // Given: 공개된 게시글과 블라인드 처리된 게시글
        $this->createPost($this->board->slug, [
            'user_id' => $this->targetUser->id,
            'title' => '발행된 게시글',
            'status' => PostStatus::Published->value,
        ]);

        $this->createPost($this->board->slug, [
            'user_id' => $this->targetUser->id,
            'title' => '블라인드 게시글',
            'status' => PostStatus::Blinded->value,
        ]);

        // When: 사용자 게시글을 조회하면
        $response = $this->getJson("/api/modules/sirsoft-board/users/{$this->targetUser->id}/posts");

        // Then: 모든 게시글이 반환되며 status 배지로 구분
        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertCount(2, $data);
    }

    /**
     * 게시글/댓글 통계를 조회할 수 있다.
     */
    public function test_can_fetch_user_posts_stats(): void
    {
        // Given: 게시글과 댓글 작성
        $post = $this->createPost($this->board->slug, [
            'user_id' => $this->targetUser->id,
            'title' => '테스트 게시글',
            'status' => PostStatus::Published->value,
        ]);

        $this->createComment($this->board->slug, $post->id, [
            'user_id' => $this->targetUser->id,
            'content' => '내 댓글 1',
            'status' => PostStatus::Published->value,
        ]);

        $this->createComment($this->board->slug, $post->id, [
            'user_id' => $this->targetUser->id,
            'content' => '내 댓글 2',
            'status' => PostStatus::Published->value,
        ]);

        // When: 통계를 조회하면
        $response = $this->getJson("/api/modules/sirsoft-board/users/{$this->targetUser->id}/posts/stats");

        // Then: 통계 데이터가 반환되어야 함
        $response->assertOk();

        $data = $response->json('data');
        $this->assertEquals(1, $data['posts_count']);
        $this->assertEquals(2, $data['comments_count']);
    }

    /**
     * 통계에서 블라인드 게시글/댓글은 제외된다.
     */
    public function test_stats_exclude_blinded_items(): void
    {
        // Given: 발행된 게시글과 블라인드 처리된 게시글
        $this->createPost($this->board->slug, [
            'user_id' => $this->targetUser->id,
            'title' => '발행된 게시글',
            'status' => PostStatus::Published->value,
        ]);

        $this->createPost($this->board->slug, [
            'user_id' => $this->targetUser->id,
            'title' => '블라인드 게시글',
            'status' => PostStatus::Blinded->value,
        ]);

        // When: 통계를 조회하면
        $response = $this->getJson("/api/modules/sirsoft-board/users/{$this->targetUser->id}/posts/stats");

        // Then: 발행된 게시글만 카운트되어야 함
        $response->assertOk();

        $data = $response->json('data');
        $this->assertEquals(1, $data['posts_count']);
    }

    /**
     * 페이지네이션이 정상 동작한다.
     */
    public function test_pagination_works(): void
    {
        // Given: 25개의 게시글 작성
        for ($i = 1; $i <= 25; $i++) {
            $this->createPost($this->board->slug, [
                'user_id' => $this->targetUser->id,
                'title' => "게시글 {$i}",
                'status' => PostStatus::Published->value,
            ]);
        }

        // When: 첫 페이지 조회
        $response = $this->getJson("/api/modules/sirsoft-board/users/{$this->targetUser->id}/posts?page=1");

        // Then: 20개의 항목과 페이지네이션 메타데이터가 있어야 함
        $response->assertOk();

        $this->assertCount(20, $response->json('data.data'));
        $this->assertEquals(1, $response->json('data.current_page'));
        $this->assertEquals(2, $response->json('data.last_page'));
        $this->assertEquals(25, $response->json('data.total'));
    }

    /**
     * per_page 파라미터로 페이지당 항목 수를 조절할 수 있다.
     */
    public function test_per_page_parameter_works(): void
    {
        // Given: 10개의 게시글 작성
        for ($i = 1; $i <= 10; $i++) {
            $this->createPost($this->board->slug, [
                'user_id' => $this->targetUser->id,
                'title' => "게시글 {$i}",
                'status' => PostStatus::Published->value,
            ]);
        }

        // When: per_page=5로 조회
        $response = $this->getJson("/api/modules/sirsoft-board/users/{$this->targetUser->id}/posts?per_page=5");

        // Then: 5개의 항목만 반환되어야 함
        $response->assertOk();
        $this->assertCount(5, $response->json('data.data'));
    }

    /**
     * 존재하지 않는 사용자의 게시글 조회 시 빈 배열이 반환된다.
     */
    public function test_returns_empty_for_non_existent_user(): void
    {
        // When: 존재하지 않는 사용자 ID로 조회
        $response = $this->getJson('/api/modules/sirsoft-board/users/999999/posts');

        // Then: 빈 배열이 반환되어야 함
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(0, $data);
    }

    /**
     * 헬퍼 메서드: 게시글 생성
     */
    private function createPost(string $slug, array $attributes = []): Post
    {
        return Post::create(array_merge([
            'board_id' => $this->board->id,
            'title' => 'Test Post',
            'content' => 'Test Content',
            'user_id' => $this->targetUser->id,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => PostStatus::Published->value,
        ], $attributes));
    }

    /**
     * 헬퍼 메서드: 댓글 생성
     */
    private function createComment(string $slug, int $postId, array $attributes = []): Comment
    {
        return Comment::create(array_merge([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'content' => 'Test Comment',
            'user_id' => $this->targetUser->id,
            'status' => PostStatus::Published->value,
        ], $attributes));
    }
}
