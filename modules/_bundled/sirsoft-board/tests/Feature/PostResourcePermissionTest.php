<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PostResource 권한 API 응답 구조 테스트
 *
 * Admin/User 페이지별 권한 분기 및 소유권 플래그 테스트
 */
class PostResourcePermissionTest extends BoardTestCase
{
    private User $adminUser;

    private User $regularUser;

    private User $postAuthor;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'permission-test';
    }

    /**
     * 기본 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '권한 테스트 게시판', 'en' => 'Permission Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트 사용자 생성
        $this->createTestUsers();

        // 권한 설정
        $this->setupPermissions();
    }

    /**
     * 테스트 사용자 생성
     */
    private function createTestUsers(): void
    {
        $this->adminUser = User::factory()->create();
        $this->regularUser = User::factory()->create();
        $this->postAuthor = User::factory()->create();
    }

    /**
     * 권한 설정
     */
    private function setupPermissions(): void
    {
        $slug = $this->board->slug;

        // Admin 권한 생성 및 할당 (라우트 미들웨어와 일치하는 식별자 사용)
        $adminPermissions = [];
        $adminPermissionDefs = [
            'admin.posts.read' => ['ko' => '관리자 게시글 조회', 'en' => 'Admin Posts Read'],
            'admin.posts.write' => ['ko' => '관리자 게시글 작성', 'en' => 'Admin Posts Write'],
            'admin.manage' => ['ko' => '관리자 관리', 'en' => 'Admin Manage'],
        ];

        foreach ($adminPermissionDefs as $action => $name) {
            $permission = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$action}"],
                [
                    'name' => $name,
                    'slug' => "sirsoft-board.{$slug}.{$action}",
                    'type' => 'admin',
                ]
            );
            $adminPermissions[] = $permission->id;
        }

        $adminRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-admin"],
            ['name' => ['ko' => '게시판 관리자', 'en' => 'Board Admin']]
        );

        $adminRole->permissions()->syncWithoutDetaching($adminPermissions);
        $this->adminUser->roles()->attach($adminRole->id);

        // User 권한 생성 및 할당 (라우트 미들웨어와 일치하는 식별자 사용)
        $userPermissions = [];
        $userPermissionDefs = [
            'posts.read' => ['ko' => '게시글 조회', 'en' => 'Read Posts'],
            'posts.write' => ['ko' => '게시글 작성', 'en' => 'Write Posts'],
            'comments.read' => ['ko' => '댓글 조회', 'en' => 'Read Comments'],
            'comments.write' => ['ko' => '댓글 작성', 'en' => 'Write Comments'],
        ];

        foreach ($userPermissionDefs as $action => $name) {
            $permission = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$action}"],
                [
                    'name' => $name,
                    'slug' => "sirsoft-board.{$slug}.{$action}",
                    'type' => 'user',
                ]
            );
            $userPermissions[] = $permission->id;
        }

        $userRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-user"],
            ['name' => ['ko' => '일반 사용자', 'en' => 'Regular User']]
        );

        $userRole->permissions()->syncWithoutDetaching($userPermissions);
        $this->regularUser->roles()->attach($userRole->id);
        $this->postAuthor->roles()->attach($userRole->id);
    }

    // ==========================================
    // Admin 페이지 권한 테스트
    // ==========================================

    /**
     * Admin 페이지에서는 admin_* 권한만 반환
     */
    #[Test]
    public function admin_page_returns_admin_permissions_only(): void
    {
        // Given: 회원 게시글 생성
        $postId = $this->createPost([
            'user_id' => $this->postAuthor->id,
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        // When: Admin API 호출
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}");

        // Then: admin 컨텍스트의 can_* abilities 포함
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        $this->assertArrayHasKey('can_read', $abilities);
        $this->assertArrayHasKey('can_write', $abilities);
        $this->assertArrayHasKey('can_manage', $abilities);

        // Admin 컨텍스트이므로 admin.* 권한 기반으로 체크됨
        // User 전용 키는 통합 can_* 키 사용으로 별도 존재하지 않음
        $this->assertTrue($abilities['can_read']);
        $this->assertTrue($abilities['can_write']);
        $this->assertTrue($abilities['can_manage']);
    }

    /**
     * Admin 페이지에서 User 권한 미반환
     */
    #[Test]
    public function admin_page_excludes_user_permissions(): void
    {
        // Given
        $postId = $this->createPost([
            'user_id' => $this->postAuthor->id,
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        // When
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}");

        // Then: abilities에 통합 can_* 키만 존재 (admin/user 구분 없음)
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        // 통합 키 사용으로 old-style 키는 존재하지 않음
        $this->assertArrayNotHasKey('posts_read', $abilities);
        $this->assertArrayNotHasKey('posts_write', $abilities);
        $this->assertArrayNotHasKey('comments_read', $abilities);
        $this->assertArrayNotHasKey('comments_write', $abilities);
    }

    // ==========================================
    // User 페이지 권한 테스트
    // ==========================================

    /**
     * User 페이지에서는 posts_*, comments_* 권한만 반환
     */
    #[Test]
    public function user_page_returns_user_permissions_only(): void
    {
        // Given
        $postId = $this->createPost([
            'user_id' => $this->postAuthor->id,
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        // When: User API 호출
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: User 컨텍스트의 can_* abilities 포함
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        $this->assertArrayHasKey('can_read', $abilities);
        $this->assertArrayHasKey('can_write', $abilities);
        $this->assertArrayHasKey('can_read_secret', $abilities);
        $this->assertArrayHasKey('can_read_comments', $abilities);
        $this->assertArrayHasKey('can_write_comments', $abilities);

        // User 컨텍스트이므로 user.* 권한 기반으로 체크됨
        $this->assertTrue($abilities['can_read']);
        $this->assertTrue($abilities['can_write']);
        $this->assertTrue($abilities['can_read_comments']);
        $this->assertTrue($abilities['can_write_comments']);
    }

    /**
     * User 페이지에서 Admin 권한 미반환
     */
    #[Test]
    public function user_page_excludes_admin_permissions(): void
    {
        // Given
        $postId = $this->createPost([
            'user_id' => $this->postAuthor->id,
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        // When
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: abilities에 통합 can_* 키만 존재 (admin/user 구분 없음)
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        // 통합 키 사용으로 old-style admin 키는 존재하지 않음
        $this->assertArrayNotHasKey('admin_posts_read', $abilities);
        $this->assertArrayNotHasKey('admin_posts_write', $abilities);
        $this->assertArrayNotHasKey('admin_manage', $abilities);
    }

    // ==========================================
    // 소유권 플래그 테스트
    // ==========================================

    /**
     * 본인 글: is_author = true
     */
    #[Test]
    public function is_author_true_for_own_post(): void
    {
        // Given: 작성자가 작성한 게시글
        $postId = $this->createPost([
            'user_id' => $this->postAuthor->id,
            'title' => 'My Post',
            'content' => 'My Content',
        ]);

        // When: 작성자 본인이 조회
        $response = $this->actingAs($this->postAuthor, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then
        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_author'));
        $this->assertFalse($response->json('data.is_guest_post'));
    }

    /**
     * 타인 글: is_author = false
     */
    #[Test]
    public function is_author_false_for_others_post(): void
    {
        // Given: 다른 사용자가 작성한 게시글
        $postId = $this->createPost([
            'user_id' => $this->postAuthor->id,
            'title' => 'Others Post',
            'content' => 'Others Content',
        ]);

        // When: 다른 사용자가 조회
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.is_author'));
        $this->assertFalse($response->json('data.is_guest_post'));
    }

    /**
     * 비회원 글: is_guest_post = true
     */
    #[Test]
    public function is_guest_post_true_for_guest_post(): void
    {
        // Given: 비회원이 작성한 게시글
        $postId = $this->createPost([
            'user_id' => null,
            'author_name' => 'Guest Author',
            'title' => 'Guest Post',
            'content' => 'Guest Content',
        ]);

        // When: 로그인 사용자가 조회
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.is_author'));
        $this->assertTrue($response->json('data.is_guest_post'));
    }

    /**
     * 회원 글: is_guest_post = false
     */
    #[Test]
    public function is_guest_post_false_for_member_post(): void
    {
        // Given: 회원이 작성한 게시글
        $postId = $this->createPost([
            'user_id' => $this->postAuthor->id,
            'title' => 'Member Post',
            'content' => 'Member Content',
        ]);

        // When
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.is_guest_post'));
    }

    // ==========================================
    // 비회원 권한 테스트
    // ==========================================

    /**
     * 비회원: guest_permissions에 따라 권한 체크
     */
    #[Test]
    public function guest_permissions_respected_for_non_authenticated_user(): void
    {
        // Given: 비회원 게시글
        $postId = $this->createPost([
            'user_id' => null,
            'author_name' => 'Guest',
            'title' => 'Public Post',
            'content' => 'Public Content',
        ]);

        // When: 비로그인 상태로 조회
        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: grantDefaultGuestPermissions()에서 posts.read, posts.write, attachments.upload 부여
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        $this->assertTrue($abilities['can_read']); // guest에 posts.read 있음
        $this->assertTrue($abilities['can_write']); // guest에 posts.write 있음
        $this->assertFalse($abilities['can_read_comments']); // guest에 comments.read 없음
        $this->assertFalse($abilities['can_write_comments']); // guest에 comments.write 없음
    }

    /**
     * 로그인 사용자: Gate로 권한 체크
     */
    #[Test]
    public function authenticated_user_permissions_check_via_gate(): void
    {
        // Given
        $postId = $this->createPost([
            'user_id' => $this->postAuthor->id,
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        // When: 로그인 사용자 조회
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: Role-Permission으로 체크
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        // regularUser는 posts.read, posts.write, comments.read, comments.write 권한 보유
        $this->assertTrue($abilities['can_read']);
        $this->assertTrue($abilities['can_write']);
        $this->assertTrue($abilities['can_read_comments']);
        $this->assertTrue($abilities['can_write_comments']);
    }

    // ==========================================
    // 답글 섹션 삭제 게시글 필터링 테스트
    // ==========================================

    /**
     * 일반 사용자는 답글 섹션에서 삭제된 답글을 볼 수 없다
     */
    #[Test]
    public function regular_user_cannot_see_deleted_reply_in_post_detail(): void
    {
        // Given: 원글 생성
        $parentId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '원글',
            'content' => '원글 내용',
        ]);

        // 정상 답글 생성
        $normalReplyId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'parent_id' => $parentId,
            'title' => '정상 답글',
            'content' => '정상 답글 내용',
            'status' => 'published',
        ]);

        // 삭제된 답글 생성 (status='deleted', deleted_at 설정)
        $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'parent_id' => $parentId,
            'title' => '삭제된 답글',
            'content' => '삭제된 답글 내용',
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        // When: 일반 사용자가 원글 상세 조회
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$parentId}");

        // Then: replies에 삭제된 답글이 포함되지 않음
        $response->assertStatus(200);
        $replies = $response->json('data.replies');
        $this->assertCount(1, $replies);
        $this->assertEquals($normalReplyId, $replies[0]['id']);
    }

    /**
     * 관리자는 답글 섹션에서 삭제된 답글을 볼 수 있다
     */
    #[Test]
    public function admin_user_can_see_deleted_reply_in_post_detail(): void
    {
        // Given: 원글 생성
        $parentId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '원글',
            'content' => '원글 내용',
        ]);

        // 정상 답글 생성
        $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'parent_id' => $parentId,
            'title' => '정상 답글',
            'content' => '정상 답글 내용',
            'status' => 'published',
        ]);

        // 삭제된 답글 생성
        $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'parent_id' => $parentId,
            'title' => '삭제된 답글',
            'content' => '삭제된 답글 내용',
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        // When: 관리자가 Admin API로 원글 상세 조회
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$parentId}");

        // Then: replies에 삭제된 답글이 포함됨
        $response->assertStatus(200);
        $replies = $response->json('data.replies');
        $this->assertCount(2, $replies);
    }

    // ==========================================
    // 헬퍼 메서드
    // ==========================================

    /**
     * 게시글 생성 헬퍼
     */
    private function createPost(array $attributes): int
    {
        $postId = $this->createTestPost($attributes);

        return $postId;
    }
}