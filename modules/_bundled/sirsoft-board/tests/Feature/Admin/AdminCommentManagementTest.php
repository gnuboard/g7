<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 관리자 댓글 관리 API 테스트
 *
 * 검증 목적:
 * - 댓글 생성/수정/삭제 (CRUD) HTTP 레벨 응답 코드 및 DB 반영
 * - 블라인드/복원 기능 동작 및 상태 전이
 * - 권한 부재 시 403, 미인증 시 401 응답
 * - 블라인드 시 comments_count 무변동, 복원 시 실제 DB 카운트로 동기화
 *
 * @group board
 * @group admin
 * @group comment
 */
class AdminCommentManagementTest extends BoardTestCase
{
    private User $adminWithManage;

    private User $adminWithWriteOnly;

    private User $regularUser;

    protected function getTestBoardSlug(): string
    {
        return 'admin-comment-mgmt';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '관리자 댓글 관리 테스트 게시판', 'en' => 'Admin Comment Management Test Board'],
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

        // admin.manage + admin.comments.write 권한 보유 관리자
        // createAdminUser는 admin 역할에 권한을 attach하므로 먼저 생성
        $this->adminWithManage = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.manage",
            "sirsoft-board.{$slug}.admin.comments.write",
        ]);

        // admin.comments.write 전용 관리자 — 별도 역할에 write 권한만 부여
        // (createAdminUser는 admin 역할을 공유하므로 manage 권한 오염 방지를 위해 직접 생성)
        $this->adminWithWriteOnly = $this->createWriteOnlyAdminUser($slug);

        // 권한 없는 일반 사용자
        $this->regularUser = $this->createUser();
    }

    /**
     * admin.write + admin.comments.write 권한만 가진 관리자를 생성합니다.
     * (manage 권한 없음)
     * 별도 역할을 만들어 admin 역할의 권한 오염을 방지합니다.
     *
     * controller의 authorizeCommentModification은 'admin.write' identifier를 확인하므로
     * 'sirsoft-board.{slug}.admin.write' 권한을 부여해야 본인 댓글 수정/삭제 가능.
     */
    private function createWriteOnlyAdminUser(string $slug): User
    {
        $user = User::factory()->create();

        // write-only 전용 역할 생성 (type=admin → isAdmin() 통과)
        $role = Role::create([
            'identifier' => 'board-write-only-' . $slug,
            'name' => ['ko' => 'Write Only', 'en' => 'Write Only'],
            'type' => 'admin',
        ]);

        foreach ([
            "sirsoft-board.{$slug}.admin.write",
            "sirsoft-board.{$slug}.admin.comments.write",
        ] as $identifier) {
            $perm = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->attach($perm->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    // ==========================================
    // 댓글 생성 (store)
    // ==========================================

    /**
     * admin.comments.write 권한 보유 시 댓글 생성 → 201
     */
    public function test_admin_can_create_comment(): void
    {
        $postId = $this->createTestPost();

        $response = $this->actingAs($this->adminWithManage)->postJson(
            $this->url("/{$postId}/comments"),
            ['content' => '관리자가 작성한 댓글입니다.']
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('board_comments', [
            'post_id' => $postId,
            'board_id' => $this->board->id,
            'content' => '관리자가 작성한 댓글입니다.',
            'status' => 'published',
        ]);
    }

    /**
     * 미인증 요청 → 401
     */
    public function test_unauthenticated_cannot_create_comment(): void
    {
        $postId = $this->createTestPost();

        $this->postJson(
            $this->url("/{$postId}/comments"),
            ['content' => '댓글 내용']
        )->assertStatus(401);
    }

    /**
     * 권한 없는 사용자 → 403
     */
    public function test_user_without_permission_cannot_create_comment(): void
    {
        $postId = $this->createTestPost();

        $this->actingAs($this->regularUser)->postJson(
            $this->url("/{$postId}/comments"),
            ['content' => '댓글 내용']
        )->assertStatus(403);
    }

    /**
     * use_comment=false 게시판 → 403
     */
    public function test_cannot_create_comment_when_comments_disabled(): void
    {
        $this->updateBoardSettings(['use_comment' => false]);
        $postId = $this->createTestPost();

        $this->actingAs($this->adminWithManage)->postJson(
            $this->url("/{$postId}/comments"),
            ['content' => '댓글 내용']
        )->assertStatus(403);
    }

    // ==========================================
    // 댓글 수정 (update)
    // ==========================================

    /**
     * admin.manage 권한으로 다른 사용자 댓글 수정 → 200
     */
    public function test_admin_manage_can_update_any_comment(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $author->id,
            'content' => '원래 내용',
        ]);

        $response = $this->actingAs($this->adminWithManage)->putJson(
            $this->url("/{$postId}/comments/{$commentId}"),
            ['content' => '수정된 내용']
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'content' => '수정된 내용',
        ]);
    }

    /**
     * admin.write 권한으로 본인 댓글 수정 → 200
     */
    public function test_admin_write_can_update_own_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $this->adminWithWriteOnly->id,
            'content' => '원래 내용',
        ]);

        $response = $this->actingAs($this->adminWithWriteOnly)->putJson(
            $this->url("/{$postId}/comments/{$commentId}"),
            ['content' => '수정된 내용']
        );

        $response->assertStatus(200);
    }

    /**
     * admin.write 권한으로 타인 댓글 수정 → 403
     */
    public function test_admin_write_cannot_update_others_comment(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $author->id,
            'content' => '타인 댓글',
        ]);

        $this->actingAs($this->adminWithWriteOnly)->putJson(
            $this->url("/{$postId}/comments/{$commentId}"),
            ['content' => '수정 시도']
        )->assertStatus(403);
    }

    /**
     * admin.write 권한으로 비회원 댓글 수정 → 403
     */
    public function test_admin_write_cannot_update_guest_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => null,
            'content' => '비회원 댓글',
        ]);

        $this->actingAs($this->adminWithWriteOnly)->putJson(
            $this->url("/{$postId}/comments/{$commentId}"),
            ['content' => '수정 시도']
        )->assertStatus(403);
    }

    // ==========================================
    // 댓글 삭제 (destroy)
    // ==========================================

    /**
     * admin.manage 권한으로 댓글 삭제 → 200 + soft delete
     */
    public function test_admin_manage_can_delete_any_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        $this->actingAs($this->adminWithManage)->deleteJson(
            $this->url("/{$postId}/comments/{$commentId}")
        )->assertStatus(200);

        $this->assertSoftDeleted('board_comments', ['id' => $commentId]);
    }

    /**
     * admin.write 권한으로 본인 댓글 삭제 → 200
     */
    public function test_admin_write_can_delete_own_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $this->adminWithWriteOnly->id,
        ]);

        $this->actingAs($this->adminWithWriteOnly)->deleteJson(
            $this->url("/{$postId}/comments/{$commentId}")
        )->assertStatus(200);

        $this->assertSoftDeleted('board_comments', ['id' => $commentId]);
    }

    /**
     * 권한 없는 사용자 삭제 → 403
     */
    public function test_user_without_permission_cannot_delete_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        $this->actingAs($this->regularUser)->deleteJson(
            $this->url("/{$postId}/comments/{$commentId}")
        )->assertStatus(403);
    }

    // ==========================================
    // 댓글 블라인드 (blind)
    // ==========================================

    /**
     * admin.manage 권한으로 댓글 블라인드 → 200 + status=blinded
     */
    public function test_admin_manage_can_blind_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['status' => 'published']);

        $response = $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/comments/{$commentId}/blind"),
            ['reason' => '운영 정책 위반']
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'status' => 'blinded',
        ]);
    }

    /**
     * 블라인드 시 comments_count 무변동 (카운트 불변 정책)
     */
    public function test_blind_comment_does_not_change_comments_count(): void
    {
        $postId = $this->createTestPost();
        DB::table('board_posts')->where('id', $postId)->update(['comments_count' => 2]);

        $commentId = $this->createTestComment($postId, ['status' => 'published']);

        $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/comments/{$commentId}/blind"),
            []
        )->assertStatus(200);

        $count = DB::table('board_posts')->where('id', $postId)->value('comments_count');
        $this->assertEquals(2, $count, '블라인드 처리 후 comments_count가 변경되면 안 됩니다.');
    }

    /**
     * admin.comments.write 권한만으로 블라인드 → 403 (manage 필요)
     */
    public function test_admin_write_cannot_blind_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        $this->actingAs($this->adminWithWriteOnly)->patchJson(
            $this->url("/{$postId}/comments/{$commentId}/blind"),
            []
        )->assertStatus(403);
    }

    /**
     * use_comment=false 게시판 블라인드 → 403
     */
    public function test_cannot_blind_comment_when_comments_disabled(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);
        $this->updateBoardSettings(['use_comment' => false]);

        $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/comments/{$commentId}/blind"),
            []
        )->assertStatus(403);
    }

    // ==========================================
    // 댓글 복원 (restore)
    // ==========================================

    /**
     * admin.manage 권한으로 블라인드된 댓글 복원 → 200 + status=published
     */
    public function test_admin_manage_can_restore_blinded_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['status' => 'blinded']);

        $response = $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/comments/{$commentId}/restore"),
            []
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'status' => 'published',
        ]);
    }

    /**
     * 복원 시 comments_count가 실제 published 댓글 수로 동기화된다
     *
     * PostCountSyncListener(after_restore, sync:true)가 실제 DB 카운트로 재동기화.
     * published 댓글 1개 + blinded 댓글 복원 → published 2개 → comments_count=2
     */
    public function test_restore_comment_syncs_comments_count(): void
    {
        $postId = $this->createTestPost();
        // published 댓글 1개 (카운트에 포함)
        $this->createTestComment($postId, ['status' => 'published']);
        // blinded 댓글 1개 (복원 대상)
        $commentId = $this->createTestComment($postId, ['status' => 'blinded']);

        $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/comments/{$commentId}/restore"),
            []
        )->assertStatus(200);

        // 복원 후 published 2개 → comments_count=2
        $count = DB::table('board_posts')->where('id', $postId)->value('comments_count');
        $this->assertEquals(2, $count, '복원 후 comments_count는 실제 published 댓글 수와 일치해야 합니다.');
    }

    /**
     * admin.write 권한만으로 복원 → 403 (manage 필요)
     */
    public function test_admin_write_cannot_restore_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['status' => 'blinded']);

        $this->actingAs($this->adminWithWriteOnly)->patchJson(
            $this->url("/{$postId}/comments/{$commentId}/restore"),
            []
        )->assertStatus(403);
    }

    /**
     * 존재하지 않는 댓글 복원 → 404
     */
    public function test_restore_nonexistent_comment_returns_404(): void
    {
        $postId = $this->createTestPost();

        $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/comments/99999/restore"),
            []
        )->assertStatus(404);
    }

    // ==========================================
    // Helper
    // ==========================================

    /**
     * Admin comment API URL 생성
     */
    private function url(string $suffix): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/admin/board/{$slug}/posts{$suffix}";
    }
}
