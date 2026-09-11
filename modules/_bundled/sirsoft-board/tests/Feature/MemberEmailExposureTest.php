<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 응답의 회원 이메일 노출 회귀 테스트
 *
 * 게시판의 공개 경로(목록·상세·댓글·게시판 상세)는 인증 없이 열람된다.
 * 그 응답에 회원 이메일이 실리면 회원 주소록이 그대로 수집되므로,
 * 이메일은 관리자 화면 응답과 본인 열람에만 실려야 한다.
 */
class MemberEmailExposureTest extends BoardTestCase
{
    private User $author;

    private User $adminUser;

    protected function getTestBoardSlug(): string
    {
        return 'email-exposure';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '이메일 노출 테스트 게시판', 'en' => 'Email Exposure Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['email' => 'author-exposure@example.test']);
        $this->adminUser = User::factory()->create(['email' => 'admin-exposure@example.test']);

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.read', 'attachments.upload']);
        $this->setupAdminRole();
        $this->setupUserRole();
    }

    /**
     * 작성자 본인이 유저 라우트를 통과할 수 있는 역할/권한을 부여합니다.
     */
    private function setupUserRole(): void
    {
        $slug = $this->board->slug;

        $ids = [];
        foreach (['posts.read', 'comments.read'] as $action) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$action}"],
                [
                    'name' => ['ko' => $action, 'en' => $action],
                    'slug' => "sirsoft-board.{$slug}.{$action}",
                    'type' => 'user',
                ]
            )->id;
        }

        $role = Role::firstOrCreate(
            ['identifier' => "{$slug}-user"],
            ['name' => ['ko' => '일반 사용자', 'en' => 'Regular User']]
        );
        $role->permissions()->syncWithoutDetaching($ids);
        $this->author->roles()->attach($role->id);

        $this->resetPermissionMiddlewareCache();
    }

    /**
     * 관리자 라우트를 통과할 수 있는 역할/권한을 부여합니다.
     */
    private function setupAdminRole(): void
    {
        $slug = $this->board->slug;

        $ids = [];
        foreach (['admin.posts.read', 'admin.comments.read', 'admin.manage'] as $action) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$action}"],
                [
                    'name' => ['ko' => $action, 'en' => $action],
                    'slug' => "sirsoft-board.{$slug}.{$action}",
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::firstOrCreate(
            ['identifier' => "{$slug}-admin"],
            ['name' => ['ko' => '게시판 관리자', 'en' => 'Board Admin']]
        );
        $role->permissions()->syncWithoutDetaching($ids);
        $this->adminUser->roles()->attach($role->id);

        $this->resetPermissionMiddlewareCache();
    }

    #[Test]
    public function guest_post_detail_does_not_expose_author_email(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
            'title' => '공개 게시글',
        ]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('data.author.email'),
            '비인증 게시글 상세 응답에 작성자 이메일이 실렸다'
        );
    }

    #[Test]
    public function guest_post_list_does_not_expose_author_email(): void
    {
        $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
            'title' => '목록 노출 확인용',
        ]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts?per_page=5");

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->author->email,
            $response->getContent(),
            '비인증 게시글 목록 응답에 작성자 이메일이 실렸다'
        );
    }

    #[Test]
    public function guest_comment_list_does_not_expose_author_email(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
        ]);
        $this->createTestComment($postId, [
            'user_id' => $this->author->id,
            'author_name' => null,
            'content' => '댓글 내용',
        ]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments");

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->author->email,
            $response->getContent(),
            '비인증 댓글 목록 응답에 작성자 이메일이 실렸다'
        );
    }

    #[Test]
    public function guest_board_detail_does_not_expose_manager_email(): void
    {
        $slug = $this->board->slug;

        $managerRole = Role::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.manager"],
            ['name' => ['ko' => '게시판 매니저', 'en' => 'Board Manager']]
        );
        $this->author->roles()->attach($managerRole->id);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$slug}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->author->email,
            $response->getContent(),
            '비인증 게시판 상세 응답에 매니저 이메일이 실렸다'
        );
    }

    #[Test]
    public function admin_post_detail_still_exposes_author_email(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200);
        $this->assertSame(
            $this->author->email,
            $response->json('data.author.email'),
            '관리자 게시글 상세에서 작성자 이메일이 사라졌다 (관리자 화면이 이 값을 쓴다)'
        );
    }

    #[Test]
    public function author_sees_own_email_on_user_route(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
        ]);

        DB::table('board_posts')->where('id', $postId)->update(['user_id' => $this->author->id]);

        $response = $this->actingAs($this->author, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200);
        $this->assertSame(
            $this->author->email,
            $response->json('data.author.email'),
            '본인 글에서 자기 이메일이 가려졌다'
        );
    }
}
