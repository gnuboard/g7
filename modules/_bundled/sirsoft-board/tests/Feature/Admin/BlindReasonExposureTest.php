<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 블라인드 사유 노출 테스트 (#413-69-1 / #413-70-2 / #413-71-1)
 *
 * 검증 목적:
 * - 관리(admin.manage) 권한자: 게시글/댓글 상세 응답의 action_logs 에 블라인드 사유 포함
 * - 민감 필드(admin_id, ip_address)는 action_logs 항목에서 제외
 * - 비관리 권한자(read 만): action_logs 키 자체 미노출 (사유 누출 방지)
 *
 * @group board
 * @group admin
 */
class BlindReasonExposureTest extends BoardTestCase
{
    private User $adminWithManage;

    protected function getTestBoardSlug(): string
    {
        return 'blind-reason-exposure';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '블라인드 사유 노출 테스트 게시판', 'en' => 'Blind Reason Exposure Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        $this->adminWithManage = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.manage",
            "sirsoft-board.{$slug}.admin.posts.read",
        ]);
    }

    private function postUrl(string $suffix = ''): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/admin/board/{$slug}/posts{$suffix}";
    }

    private function userPostUrl(string $suffix = ''): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/boards/{$slug}/posts{$suffix}";
    }

    private function userCommentUrl(int $postId, string $suffix = ''): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/boards/{$slug}/posts/{$postId}/comments{$suffix}";
    }

    /**
     * 관리 권한자가 블라인드된 게시글 상세 조회 시 사유가 action_logs 에 포함된다.
     */
    public function test_manager_sees_blind_reason_in_post_action_logs(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);

        $this->actingAs($this->adminWithManage)->patchJson(
            $this->postUrl("/{$postId}/blind"),
            ['reason' => '운영 정책 위반으로 블라인드 처리']
        )->assertStatus(200);

        $response = $this->actingAs($this->adminWithManage)->getJson($this->postUrl("/{$postId}"));

        $response->assertStatus(200);
        $logs = $response->json('data.action_logs');

        $this->assertIsArray($logs, '관리자 응답에 action_logs 가 포함되어야 합니다.');

        $blind = collect($logs)->firstWhere('action', 'blind');
        $this->assertNotNull($blind, 'blind 액션 로그가 있어야 합니다.');
        $this->assertSame('운영 정책 위반으로 블라인드 처리', $blind['reason']);

        // 민감 필드는 노출되지 않아야 한다.
        $this->assertArrayNotHasKey('admin_id', $blind, 'admin_id 는 노출되면 안 됩니다.');
        $this->assertArrayNotHasKey('ip_address', $blind, 'ip_address 는 노출되면 안 됩니다.');
    }

    /**
     * 일반 사용자(유저 화면)에게는 action_logs 가 노출되지 않는다 (사유 누출 방지).
     *
     * 블라인드 글은 유저가 상세 진입 자체가 막히므로, 블라인드→복원으로 published 상태이면서
     * 처리 이력(action_logs)이 남은 게시글을 유저가 조회할 때 사유가 새지 않는지 검증한다.
     */
    public function test_regular_user_does_not_see_post_action_logs(): void
    {
        $this->setGuestPermissions(['posts.read']);

        $postId = $this->createTestPost(['status' => 'published']);

        // action_logs 누적: Service 로 직접 블라인드→복원 (게스트 조회 전 인증 상태 잔존 방지)
        $service = app(PostService::class);
        $service->blindPost($this->board->slug, $postId, '민감 사유 — 누출 금지', 'admin');
        $service->restorePost($this->board->slug, $postId, '복원', 'admin');

        // 비로그인 사용자(게스트)로 유저 화면 상세 조회
        $response = $this->getJson($this->userPostUrl("/{$postId}"));

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('data.action_logs'),
            '유저 화면 응답에는 action_logs 가 노출되면 안 됩니다.'
        );
    }

    /**
     * 관리 권한자가 블라인드 처리한 댓글 응답에 사유가 action_logs 에 포함된다. (#413-71-1)
     */
    public function test_manager_sees_blind_reason_in_comment_action_logs(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);
        $commentId = $this->createTestComment($postId);

        $response = $this->actingAs($this->adminWithManage)->patchJson(
            $this->postUrl("/{$postId}/comments/{$commentId}/blind"),
            ['reason' => '댓글 운영 정책 위반으로 블라인드']
        );

        $response->assertStatus(200);
        $logs = $response->json('data.action_logs');

        $this->assertIsArray($logs, '관리자 댓글 응답에 action_logs 가 포함되어야 합니다.');

        $blind = collect($logs)->firstWhere('action', 'blind');
        $this->assertNotNull($blind, 'blind 액션 로그가 있어야 합니다.');
        $this->assertSame('댓글 운영 정책 위반으로 블라인드', $blind['reason']);

        // 민감 필드는 노출되지 않아야 한다.
        $this->assertArrayNotHasKey('admin_id', $blind, 'admin_id 는 노출되면 안 됩니다.');
        $this->assertArrayNotHasKey('ip_address', $blind, 'ip_address 는 노출되면 안 됩니다.');
    }

    /**
     * 유저 화면 댓글 목록에는 action_logs 가 노출되지 않는다 (사유 누출 방지). (#413-71-1)
     */
    public function test_regular_user_does_not_see_comment_action_logs(): void
    {
        $this->setGuestPermissions(['posts.read', 'comments.read']);

        $postId = $this->createTestPost(['status' => 'published']);
        $commentId = $this->createTestComment($postId);

        // action_logs 누적: Service 로 직접 블라인드→복원 (게스트 조회 전 인증 상태 잔존 방지)
        $service = app(CommentService::class);
        $service->blindComment($this->board->slug, $commentId, '민감 사유 — 누출 금지', 'admin');
        $service->restoreComment($this->board->slug, $commentId, '복원', 'admin');

        // 비로그인 사용자(게스트)로 유저 화면 댓글 목록 조회
        $response = $this->getJson($this->userCommentUrl($postId));

        $response->assertStatus(200);

        $comments = $response->json('data') ?? [];
        $this->assertNotEmpty($comments, '댓글 목록이 비어 있으면 안 됩니다.');

        foreach ($comments as $comment) {
            $this->assertNull(
                $comment['action_logs'] ?? null,
                '유저 화면 댓글에는 action_logs 사유가 노출되면 안 됩니다.'
            );
        }
    }
}
