<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 사용자 게시판 삭제 글/댓글 포함 토글 테스트
 *
 * manager 권한 보유 + del=1 파라미터가 있을 때만
 * 삭제된 게시글/댓글이 목록에 노출되는지 검증합니다.
 */
class PostDeletedToggleTest extends BoardTestCase
{
    private User $regularUser;

    private User $managerUser;

    protected function getTestBoardSlug(): string
    {
        return 'deleted-toggle';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '삭제 토글 테스트 게시판', 'en' => 'Deleted Toggle Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        // 일반 사용자 (posts.read 권한만)
        $this->regularUser = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $readPerm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.posts.read"],
                ['name' => ['ko' => '게시글 조회', 'en' => 'Read Posts'], 'type' => 'user']
            );
            $userRole->permissions()->syncWithoutDetaching([$readPerm->id]);
            $this->regularUser->roles()->attach($userRole->id);
        }

        // manager 권한 사용자 (posts.read + manager)
        $this->managerUser = User::factory()->create();
        $managerRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-manager"],
            ['name' => ['ko' => '게시판 매니저', 'en' => 'Board Manager']]
        );
        foreach (['posts.read', 'comments.read', 'manager'] as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $managerRole->permissions()->syncWithoutDetaching([$perm->id]);
        }
        $this->managerUser->roles()->attach($managerRole->id);
    }

    // ==========================================
    // can_view_deleted abilities 키 테스트
    // ==========================================

    /**
     * manager 권한 사용자는 abilities.can_view_deleted = true 반환
     */
    public function test_manager_receives_can_view_deleted_true_in_abilities(): void
    {
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts");

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.abilities.can_view_deleted'));
    }

    /**
     * 일반 사용자는 abilities.can_view_deleted = false 반환
     */
    public function test_regular_user_receives_can_view_deleted_false_in_abilities(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts");

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.abilities.can_view_deleted'));
    }

    // ==========================================
    // include_deleted 토글 테스트 (게시글 목록)
    // ==========================================

    /**
     * manager + del=1 → 삭제된 게시글 목록에 포함
     */
    public function test_manager_with_include_deleted_sees_deleted_posts(): void
    {
        // Given: 일반 게시글 1개 + 삭제된 게시글 1개
        $normalPostId = $this->createTestPost(['title' => '일반 게시글']);
        $deletedPostId = $this->createTestPost([
            'title' => '삭제된 게시글',
            'deleted_at' => now(),
        ]);

        // When: manager가 del=1로 목록 조회
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts?del=1");

        $response->assertStatus(200);

        $postIds = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertContains($deletedPostId, $postIds, '삭제된 게시글이 목록에 포함되어야 합니다');
        $this->assertContains($normalPostId, $postIds, '일반 게시글도 목록에 포함되어야 합니다');
    }

    /**
     * manager라도 include_deleted 파라미터 없으면 삭제된 게시글 미포함
     */
    public function test_manager_without_include_deleted_does_not_see_deleted_posts(): void
    {
        // Given: 삭제된 게시글 생성
        $deletedPostId = $this->createTestPost([
            'title' => '삭제된 게시글',
            'deleted_at' => now(),
        ]);

        // When: include_deleted 파라미터 없이 조회
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts");

        $response->assertStatus(200);

        $postIds = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertNotContains($deletedPostId, $postIds, '삭제된 게시글이 목록에 포함되지 않아야 합니다');
    }

    /**
     * 일반 사용자는 del=1 파라미터를 넘겨도 삭제된 게시글 미포함
     */
    public function test_regular_user_cannot_see_deleted_posts_even_with_include_deleted(): void
    {
        // Given: 삭제된 게시글 생성
        $deletedPostId = $this->createTestPost([
            'title' => '삭제된 게시글',
            'deleted_at' => now(),
        ]);

        // When: 일반 사용자가 del=1로 조회 시도
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts?del=1");

        $response->assertStatus(200);

        $postIds = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertNotContains($deletedPostId, $postIds, '일반 사용자에게는 삭제된 게시글이 노출되지 않아야 합니다');
    }

    // ==========================================
    // include_deleted_comments 토글 테스트 (댓글)
    // ==========================================

    /**
     * manager + del_cmt=1 → 삭제된 댓글 상세에 포함
     */
    public function test_manager_with_include_deleted_comments_sees_deleted_comments(): void
    {
        // Given: 게시글 + 일반 댓글 + 삭제된 댓글
        $postId = $this->createTestPost(['title' => '댓글 테스트 게시글']);
        $normalCommentId = $this->createTestComment($postId, ['content' => '일반 댓글']);
        $deletedCommentId = $this->createTestComment($postId, [
            'content' => '삭제된 댓글',
            'deleted_at' => now(),
        ]);

        // When: manager가 del_cmt=1로 게시글 상세 조회
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}?del_cmt=1");

        $response->assertStatus(200);

        $commentIds = collect($response->json('data.comments'))->pluck('id')->toArray();
        $this->assertContains($deletedCommentId, $commentIds, '삭제된 댓글이 포함되어야 합니다');
        $this->assertContains($normalCommentId, $commentIds, '일반 댓글도 포함되어야 합니다');
    }

    /**
     * manager라도 include_deleted_comments 없으면 삭제 댓글 미포함
     */
    public function test_manager_without_include_deleted_comments_does_not_see_deleted_comments(): void
    {
        // Given
        $postId = $this->createTestPost(['title' => '댓글 테스트 게시글']);
        $deletedCommentId = $this->createTestComment($postId, [
            'content' => '삭제된 댓글',
            'deleted_at' => now(),
        ]);

        // When: include_deleted_comments 파라미터 없이 조회
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200);

        $commentIds = collect($response->json('data.comments'))->pluck('id')->toArray();
        $this->assertNotContains($deletedCommentId, $commentIds, '삭제된 댓글이 포함되지 않아야 합니다');
    }

    /**
     * 일반 사용자는 del_cmt=1을 넘겨도 삭제 댓글 미포함
     */
    public function test_regular_user_cannot_see_deleted_comments_even_with_include_deleted_comments(): void
    {
        // Given
        $postId = $this->createTestPost(['title' => '댓글 테스트 게시글']);
        $deletedCommentId = $this->createTestComment($postId, [
            'content' => '삭제된 댓글',
            'deleted_at' => now(),
        ]);

        // When: 일반 사용자가 del_cmt=1로 조회 시도
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}?del_cmt=1");

        $response->assertStatus(200);

        $commentIds = collect($response->json('data.comments'))->pluck('id')->toArray();
        $this->assertNotContains($deletedCommentId, $commentIds, '일반 사용자에게는 삭제된 댓글이 노출되지 않아야 합니다');
    }
}
