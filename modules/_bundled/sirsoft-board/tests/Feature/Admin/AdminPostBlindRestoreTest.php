<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 관리자 게시글 블라인드/복원 API 테스트
 *
 * 검증 목적:
 * - blind: status=blinded, 미인증 401, 권한 없음 403, 이미 블라인드된 게시글 멱등성
 * - restore: status=published, 권한 없음 403
 * - 존재하지 않는 게시글 → 404
 * - 블라인드 시 posts_count 무변동 (카운트 불변 정책)
 */
#[Group('board')]
#[Group('admin')]
#[Group('post')]
class AdminPostBlindRestoreTest extends BoardTestCase
{
    private User $adminWithManage;

    private User $regularUser;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'admin-post-blind';
    }

    /**
     * 이 테스트에 맞춘 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '관리자 게시글 블라인드 테스트 게시판', 'en' => 'Admin Post Blind Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        $this->adminWithManage = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.manage",
        ]);

        $this->regularUser = $this->createUser();
    }

    // ==========================================
    // 블라인드 (blind)
    // ==========================================

    /**
     * admin.manage 권한으로 게시글 블라인드 → 200 + status=blinded
     *
     * @scenario bulk_action=blind, permission=manage, row_state=published
     *
     * @effects admin_post_single_blind_changes_status
     *
     * @return void 반환값 없음
     */
    public function test_admin_can_blind_post(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);

        $response = $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/blind"),
            ['reason' => '운영 정책 위반']
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('board_posts', [
            'id' => $postId,
            'status' => 'blinded',
        ]);
    }

    /**
     * 미인증 요청 → 401
     *
     * @scenario bulk_action=blind, permission=read, row_state=published
     *
     * @effects admin_post_blind_rejects_unauthenticated
     *
     * @return void 반환값 없음
     */
    public function test_unauthenticated_cannot_blind_post(): void
    {
        $postId = $this->createTestPost();

        $this->patchJson(
            $this->url("/{$postId}/blind"),
            []
        )->assertStatus(401);
    }

    /**
     * 권한 없는 사용자 → 403
     *
     * @scenario bulk_action=blind, permission=read, row_state=published
     *
     * @effects admin_post_blind_requires_manage_permission
     *
     * @return void 반환값 없음
     */
    public function test_user_without_permission_cannot_blind_post(): void
    {
        $postId = $this->createTestPost();

        $this->actingAs($this->regularUser)->patchJson(
            $this->url("/{$postId}/blind"),
            []
        )->assertStatus(403);
    }

    /**
     * 존재하지 않는 게시글 → 404
     *
     * @scenario bulk_action=blind, permission=manage, row_state=deleted
     *
     * @effects admin_post_blind_missing_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_blind_nonexistent_post_returns_404(): void
    {
        $this->actingAs($this->adminWithManage)->patchJson(
            $this->url('/99999/blind'),
            []
        )->assertStatus(404);
    }

    /**
     * 이미 블라인드된 게시글 재블라인드 → 멱등성 보장 (200, status 유지)
     *
     * @scenario bulk_action=blind, permission=manage, row_state=blinded
     *
     * @effects admin_post_blind_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_blind_already_blinded_post_is_idempotent(): void
    {
        $postId = $this->createTestPost(['status' => 'blinded']);

        $response = $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/blind"),
            []
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('board_posts', [
            'id' => $postId,
            'status' => 'blinded',
        ]);
    }

    /**
     * 블라인드 처리 시 boards.posts_count 무변동 (카운트 불변 정책)
     *
     * @scenario bulk_action=blind, permission=manage, row_state=published
     *
     * @effects admin_post_blind_keeps_posts_count
     *
     * @return void 반환값 없음
     */
    public function test_blind_post_does_not_change_posts_count(): void
    {
        DB::table('boards')->where('id', $this->board->id)->update(['posts_count' => 5]);
        $postId = $this->createTestPost(['status' => 'published']);

        $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/blind"),
            []
        )->assertStatus(200);

        $count = DB::table('boards')->where('id', $this->board->id)->value('posts_count');
        $this->assertEquals(5, $count, '블라인드 후 posts_count가 변경되면 안 됩니다.');
    }

    /**
     * 블라인드 사유 1000자 → 통과 (200) (#413-71-2)
     *
     * @scenario bulk_action=blind, permission=manage, row_state=published
     *
     * @effects admin_post_blind_reason_length_boundary
     *
     * @return void 반환값 없음
     */
    public function test_blind_post_reason_at_max_length_passes(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);

        $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/blind"),
            ['reason' => str_repeat('가', 1000)]
        )->assertStatus(200);
    }

    /**
     * 블라인드 사유 1001자 → 검증 실패 (422) (#413-71-2)
     *
     * @scenario bulk_action=blind, permission=manage, row_state=published
     *
     * @effects admin_post_blind_reason_length_boundary
     *
     * @return void 반환값 없음
     */
    public function test_blind_post_reason_over_max_length_fails(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);

        $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/blind"),
            ['reason' => str_repeat('가', 1001)]
        )->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    // ==========================================
    // 복원 (restore)
    // ==========================================

    /**
     * admin.manage 권한으로 블라인드된 게시글 복원 → 200 + status=published
     *
     * @scenario bulk_action=restore, permission=manage, row_state=blinded
     *
     * @effects admin_post_single_restore_publishes
     *
     * @return void 반환값 없음
     */
    public function test_admin_can_restore_blinded_post(): void
    {
        $postId = $this->createTestPost(['status' => 'blinded']);

        $response = $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/restore"),
            []
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('board_posts', [
            'id' => $postId,
            'status' => 'published',
        ]);
    }

    /**
     * 권한 없는 사용자 복원 → 403
     *
     * @scenario bulk_action=restore, permission=read, row_state=blinded
     *
     * @effects admin_post_restore_requires_manage_permission
     *
     * @return void 반환값 없음
     */
    public function test_user_without_permission_cannot_restore_post(): void
    {
        $postId = $this->createTestPost(['status' => 'blinded']);

        $this->actingAs($this->regularUser)->patchJson(
            $this->url("/{$postId}/restore"),
            []
        )->assertStatus(403);
    }

    /**
     * 존재하지 않는 게시글 복원 → 404
     *
     * @scenario bulk_action=restore, permission=manage, row_state=deleted
     *
     * @effects admin_post_restore_missing_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_restore_nonexistent_post_returns_404(): void
    {
        $this->actingAs($this->adminWithManage)->patchJson(
            $this->url('/99999/restore'),
            []
        )->assertStatus(404);
    }

    /**
     * 이미 published 상태 게시글 복원 → 멱등성 보장 (200, status 유지)
     *
     * @scenario bulk_action=restore, permission=manage, row_state=published
     *
     * @effects admin_post_restore_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_restore_already_published_post_is_idempotent(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);

        $response = $this->actingAs($this->adminWithManage)->patchJson(
            $this->url("/{$postId}/restore"),
            []
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('board_posts', [
            'id' => $postId,
            'status' => 'published',
        ]);
    }

    // ==========================================
    // Helper
    // ==========================================

    /**
     * 관리자 게시글 API 주소를 만듭니다.
     *
     * @param  string  $suffix  게시글 경로 뒤에 붙일 부분
     * @return string API 주소
     */
    private function url(string $suffix): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/admin/board/{$slug}/posts{$suffix}";
    }
}
