<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판/게시글/댓글 API 응답의 abilities 키 구조 테스트
 *
 * BoardResource는 user_abilities (can_* 형식), PostResource/CommentResource는
 * abilities (can_* 형식)를 반환해야 합니다.
 * 이전 underscore 형식(posts_write, manager 등)이 존재하지 않는지 검증합니다.
 */
class BoardUserAbilitiesTest extends BoardTestCase
{
    private User $adminUser;

    private User $regularUser;

    private User $postAuthor;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'user-abilities';
    }

    /**
     * 기본 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '사용자 권한 테스트 게시판', 'en' => 'User Abilities Test Board'],
            'is_active' => true,
            'use_comment' => true,
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

        // Admin 권한 생성 및 할당
        $adminPermissions = [];
        $adminPermissionDefs = [
            'admin.posts.read' => ['ko' => '관리자 게시글 조회', 'en' => 'Admin Posts Read'],
            'admin.posts.write' => ['ko' => '관리자 게시글 작성', 'en' => 'Admin Posts Write'],
            'admin.comments.read' => ['ko' => '관리자 댓글 조회', 'en' => 'Admin Comments Read'],
            'admin.comments.write' => ['ko' => '관리자 댓글 작성', 'en' => 'Admin Comments Write'],
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

        // User 권한 생성 및 할당
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
    // BoardResource: user_abilities 키 구조 테스트
    // ==========================================

    /**
     * 게시판 상세 API가 user_abilities 키를 can_* 형식으로 반환
     */
    #[Test]
    public function board_detail_returns_user_abilities_with_can_keys(): void
    {
        // When: include_user_abilities 파라미터와 함께 게시판 상세 조회
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}?include_user_abilities=1");

        // Then: user_abilities 키가 존재하고 can_* 형식 키를 포함
        $response->assertStatus(200);
        $userAbilities = $response->json('data.user_abilities');

        $this->assertNotNull($userAbilities, 'user_abilities 키가 존재해야 합니다');
        $this->assertIsArray($userAbilities);

        // can_* 형식 키 존재 확인
        $this->assertArrayHasKey('can_read', $userAbilities);
        $this->assertArrayHasKey('can_write', $userAbilities);
        $this->assertArrayHasKey('can_read_comments', $userAbilities);
        $this->assertArrayHasKey('can_write_comments', $userAbilities);
    }

    /**
     * 게시판 상세 API가 user_permissions 키를 반환하지 않음 (이전 형식)
     */
    #[Test]
    public function board_detail_does_not_return_old_user_permissions_key(): void
    {
        // When
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}?include_user_abilities=1");

        // Then: user_permissions 키가 존재하지 않음
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayNotHasKey('user_permissions', $data, 'user_permissions 키는 존재하지 않아야 합니다 (user_abilities로 대체됨)');
    }

    /**
     * 게시판 user_abilities에 이전 underscore 형식 키가 존재하지 않음
     */
    #[Test]
    public function board_user_abilities_does_not_contain_old_underscore_keys(): void
    {
        // When
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}?include_user_abilities=1");

        // Then: 이전 underscore 형식 키 미존재
        $response->assertStatus(200);
        $userAbilities = $response->json('data.user_abilities');

        // 이전 형식 키들이 존재하지 않는지 확인
        $oldKeys = ['posts_read', 'posts_write', 'comments_read', 'comments_write', 'manager', 'admin_manage'];
        foreach ($oldKeys as $oldKey) {
            $this->assertArrayNotHasKey($oldKey, $userAbilities, "이전 형식 키 '{$oldKey}'가 존재하지 않아야 합니다");
        }
    }

    /**
     * include_user_abilities 미전달 시 user_abilities가 null
     */
    #[Test]
    public function board_detail_returns_null_user_abilities_without_param(): void
    {
        // When: include_user_abilities 파라미터 없이 조회
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}");

        // Then: user_abilities가 null
        $response->assertStatus(200);
        $this->assertNull($response->json('data.user_abilities'), 'include_user_abilities 미전달 시 user_abilities는 null이어야 합니다');
    }

    // ==========================================
    // PostResource: abilities 키 구조 테스트 (목록)
    // ==========================================

    /**
     * 게시글 목록 API가 각 항목에 abilities.can_write, abilities.can_read 반환
     */
    #[Test]
    public function post_list_returns_items_with_abilities_can_keys(): void
    {
        // Given: 게시글 생성
        $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '테스트 게시글 1',
            'content' => '내용 1',
        ]);

        // When: 게시글 목록 조회
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts");

        // Then: 각 게시글에 abilities 키가 can_* 형식으로 존재
        $response->assertStatus(200);
        $posts = $response->json('data.data');

        $this->assertNotEmpty($posts, '게시글이 1개 이상 존재해야 합니다');

        $firstPost = $posts[0];
        $this->assertArrayHasKey('abilities', $firstPost, '게시글에 abilities 키가 존재해야 합니다');

        $abilities = $firstPost['abilities'];
        $this->assertArrayHasKey('can_read', $abilities);
        $this->assertArrayHasKey('can_write', $abilities);
    }

    /**
     * 게시글 목록 항목에 이전 underscore 형식 키가 존재하지 않음
     */
    #[Test]
    public function post_list_items_do_not_contain_old_underscore_keys(): void
    {
        // Given
        $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '테스트 게시글',
            'content' => '내용',
        ]);

        // When
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts");

        // Then
        $response->assertStatus(200);
        $posts = $response->json('data.data');
        $this->assertNotEmpty($posts);

        $abilities = $posts[0]['abilities'];
        $oldKeys = ['posts_read', 'posts_write', 'comments_read', 'comments_write', 'manager'];
        foreach ($oldKeys as $oldKey) {
            $this->assertArrayNotHasKey($oldKey, $abilities, "게시글 목록 abilities에 이전 형식 키 '{$oldKey}'가 존재하지 않아야 합니다");
        }
    }

    // ==========================================
    // PostResource: abilities 키 구조 테스트 (상세)
    // ==========================================

    /**
     * 게시글 상세 API가 abilities.can_manage, abilities.can_write_comments 반환
     */
    #[Test]
    public function post_detail_returns_abilities_with_can_manage_and_can_write_comments(): void
    {
        // Given
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '상세 테스트 게시글',
            'content' => '상세 내용',
        ]);

        // When: 게시글 상세 조회
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: abilities에 can_manage, can_write_comments 키 존재
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        $this->assertNotNull($abilities, 'abilities 키가 존재해야 합니다');
        $this->assertArrayHasKey('can_manage', $abilities);
        $this->assertArrayHasKey('can_write_comments', $abilities);
        $this->assertArrayHasKey('can_read', $abilities);
        $this->assertArrayHasKey('can_write', $abilities);
        $this->assertArrayHasKey('can_read_comments', $abilities);
    }

    /**
     * 게시글 상세 abilities에 이전 underscore 형식 키가 존재하지 않음
     */
    #[Test]
    public function post_detail_abilities_do_not_contain_old_underscore_keys(): void
    {
        // Given
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '테스트 게시글',
            'content' => '내용',
        ]);

        // When
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        $oldKeys = ['posts_read', 'posts_write', 'comments_read', 'comments_write', 'manager', 'admin_manage'];
        foreach ($oldKeys as $oldKey) {
            $this->assertArrayNotHasKey($oldKey, $abilities, "게시글 상세 abilities에 이전 형식 키 '{$oldKey}'가 존재하지 않아야 합니다");
        }
    }

    // ==========================================
    // CommentResource: abilities 키 구조 테스트
    // ==========================================

    /**
     * 댓글이 포함된 게시글 상세에서 댓글의 abilities.can_write 반환
     */
    #[Test]
    public function comment_in_post_detail_returns_abilities_with_can_write(): void
    {
        // Given: 게시글 + 댓글 생성
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '댓글 테스트 게시글',
            'content' => '내용',
        ]);

        $this->createTestComment($postId, [
            'user_id' => $this->regularUser->id,
            'author_name' => $this->regularUser->name,
            'content' => '테스트 댓글입니다.',
        ]);

        // When: 게시글 상세 조회 (댓글 포함)
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 댓글에 abilities 키가 존재하고 can_write 포함
        $response->assertStatus(200);
        $comments = $response->json('data.comments');

        $this->assertNotEmpty($comments, '댓글이 1개 이상 존재해야 합니다');

        $firstComment = $comments[0];
        $this->assertArrayHasKey('abilities', $firstComment, '댓글에 abilities 키가 존재해야 합니다');
        $this->assertArrayHasKey('can_write', $firstComment['abilities']);
    }

    /**
     * 댓글 abilities에 이전 underscore 형식 키가 존재하지 않음
     */
    #[Test]
    public function comment_abilities_do_not_contain_old_underscore_keys(): void
    {
        // Given
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '댓글 테스트 게시글',
            'content' => '내용',
        ]);

        $this->createTestComment($postId, [
            'user_id' => $this->regularUser->id,
            'author_name' => $this->regularUser->name,
            'content' => '테스트 댓글입니다.',
        ]);

        // When
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then
        $response->assertStatus(200);
        $comments = $response->json('data.comments');
        $this->assertNotEmpty($comments);

        $commentAbilities = $comments[0]['abilities'];
        $oldKeys = ['comments_write', 'comments_read', 'manager'];
        foreach ($oldKeys as $oldKey) {
            $this->assertArrayNotHasKey($oldKey, $commentAbilities, "댓글 abilities에 이전 형식 키 '{$oldKey}'가 존재하지 않아야 합니다");
        }
    }

    // ==========================================
    // Admin 컨텍스트 abilities 키 구조 테스트
    // ==========================================

    /**
     * Admin 게시글 상세에서도 abilities가 can_* 형식으로 반환
     */
    #[Test]
    public function admin_post_detail_returns_abilities_with_can_keys(): void
    {
        // Given
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => 'Admin 테스트 게시글',
            'content' => '내용',
        ]);

        // When: Admin API로 조회
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}");

        // Then: abilities에 can_* 형식 키 존재
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        $this->assertNotNull($abilities);
        $this->assertArrayHasKey('can_read', $abilities);
        $this->assertArrayHasKey('can_write', $abilities);
        $this->assertArrayHasKey('can_manage', $abilities);

        // 이전 형식 키 미존재
        $this->assertArrayNotHasKey('admin_posts_read', $abilities);
        $this->assertArrayNotHasKey('admin_posts_write', $abilities);
        $this->assertArrayNotHasKey('admin_manage', $abilities);
    }

    // ==========================================
    // 권한 값 정확성 테스트
    // ==========================================

    /**
     * 권한을 가진 사용자의 abilities 값이 true
     */
    #[Test]
    public function user_with_permissions_has_true_abilities(): void
    {
        // Given
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '권한 테스트 게시글',
            'content' => '내용',
        ]);

        // When: 권한을 가진 사용자가 조회
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 해당 권한 값이 true
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        $this->assertTrue($abilities['can_read'], 'posts.read 권한 보유자의 can_read는 true');
        $this->assertTrue($abilities['can_write'], 'posts.write 권한 보유자의 can_write는 true');
        $this->assertTrue($abilities['can_read_comments'], 'comments.read 권한 보유자의 can_read_comments는 true');
        $this->assertTrue($abilities['can_write_comments'], 'comments.write 권한 보유자의 can_write_comments는 true');
    }

    /**
     * 비회원의 abilities 값이 기본 권한에 따라 정확히 설정됨
     */
    #[Test]
    public function guest_user_abilities_reflect_default_permissions(): void
    {
        // Given: 게시글 생성
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '비회원 테스트 게시글',
            'content' => '내용',
        ]);

        // When: 비로그인 상태로 조회
        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: guest에 posts.read, posts.write 있음 / comments.read, comments.write 없음
        $response->assertStatus(200);
        $abilities = $response->json('data.abilities');

        $this->assertTrue($abilities['can_read'], 'guest에 posts.read 있으므로 can_read는 true');
        $this->assertTrue($abilities['can_write'], 'guest에 posts.write 있으므로 can_write는 true');
        $this->assertFalse($abilities['can_read_comments'], 'guest에 comments.read 없으므로 can_read_comments는 false');
        $this->assertFalse($abilities['can_write_comments'], 'guest에 comments.write 없으므로 can_write_comments는 false');
    }
}
