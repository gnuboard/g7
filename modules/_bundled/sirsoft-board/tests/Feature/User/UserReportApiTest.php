<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 사용자 신고 API 테스트
 *
 * 검증 목적:
 * - 게시글 신고: 201, 미인증 401, use_report=false → 403, 중복 신고 → 409
 * - 댓글 신고: 201, 본인 댓글 신고 → 403
 * - 블라인드/삭제된 대상 신고 → 403
 * - 유효하지 않은 reason_type → 422
 *
 * URL:
 * - POST /api/modules/sirsoft-board/boards/{slug}/posts/{id}/reports
 * - POST /api/modules/sirsoft-board/boards/{slug}/comments/{id}/reports
 */
#[Group('board')]
#[Group('user')]
#[Group('report')]
class UserReportApiTest extends BoardTestCase
{
    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'user-report-api';
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
            'name' => ['ko' => '신고 API 테스트 게시판', 'en' => 'Report API Test Board'],
            'is_active' => true,
            'use_report' => true,
            'use_comment' => true,
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

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.write']);
    }

    // ==========================================
    // 게시글 신고
    // ==========================================

    /**
     * 회원이 다른 사람의 게시글 신고 → 201
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects authenticated_user_can_report_post
     *
     * @return void 반환값 없음
     */
    public function test_authenticated_user_can_report_post(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $response = $this->actingAs($reporter)->postJson(
            $this->postReportUrl($postId),
            ['reason_type' => 'spam', 'reason_detail' => '스팸 게시글입니다.']
        );

        $response->assertStatus(201);
        // boards_reports는 집계 레코드, 개별 신고자는 boards_report_logs에 기록
        $this->assertDatabaseHas('boards_reports', [
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
        ]);
    }

    /**
     * 미인증 요청 → 401
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects unauthenticated_cannot_report_post
     *
     * @return void 반환값 없음
     */
    public function test_unauthenticated_cannot_report_post(): void
    {
        $postId = $this->createTestPost();

        $this->postJson(
            $this->postReportUrl($postId),
            ['reason_type' => 'spam', 'reason_detail' => '스팸입니다.']
        )->assertStatus(401);
    }

    /**
     * use_report=false 게시판 → 403
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects report_disabled_board_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_report_disabled_board_returns_403(): void
    {
        $this->updateBoardSettings(['use_report' => false]);

        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reporter)->postJson(
            $this->postReportUrl($postId),
            ['reason_type' => 'spam', 'reason_detail' => '스팸입니다.']
        )->assertStatus(403);
    }

    /**
     * 본인 게시글 신고 → 403
     *
     * @scenario operation=submit_post_report, condition=own_target
     *
     * @effects cannot_report_own_post
     *
     * @return void 반환값 없음
     */
    public function test_cannot_report_own_post(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $user->id]);

        $this->actingAs($user)->postJson(
            $this->postReportUrl($postId),
            ['reason_type' => 'spam', 'reason_detail' => '스팸입니다.']
        )->assertStatus(403);
    }

    /**
     * 블라인드된 게시글 신고 → 403
     *
     * @scenario operation=submit_post_report, condition=blinded_target
     *
     * @effects cannot_report_blinded_post
     *
     * @return void 반환값 없음
     */
    public function test_cannot_report_blinded_post(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id, 'status' => 'blinded']);

        $this->actingAs($reporter)->postJson(
            $this->postReportUrl($postId),
            ['reason_type' => 'spam', 'reason_detail' => '스팸입니다.']
        )->assertStatus(403);
    }

    /**
     * 동일 게시글 중복 신고 → 409
     *
     * 첫 신고 성공 후 쿨다운이 기록되므로, 별도 사용자로 각 신고 시도.
     * DB에 직접 신고 레코드를 삽입한 후 재시도해 쿨다운 422와 구분.
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects duplicate_post_report_returns_409
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_post_report_returns_409(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        // boards_reports에 직접 신고 집계 레코드 삽입 (쿨다운 우회)
        $reportId = DB::table('boards_reports')->insertGetId([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'author_id' => $author->id,
            'status' => 'pending',
            'metadata' => json_encode([]),
            'last_reported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // reporter_id는 boards_report_logs에 기록
        DB::table('boards_report_logs')->insert([
            'report_id' => $reportId,
            'reporter_id' => $reporter->id,
            'reason_type' => 'spam',
            'reason_detail' => '스팸입니다.',
            'snapshot' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 같은 reporter가 같은 게시글 재신고 → 409
        $this->actingAs($reporter)->postJson(
            $this->postReportUrl($postId),
            ['reason_type' => 'abuse', 'reason_detail' => '욕설입니다.']
        )->assertStatus(409);
    }

    /**
     * 유효하지 않은 reason_type → 422
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects invalid_reason_type_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_invalid_reason_type_returns_422(): void
    {
        $reporter = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($reporter)->postJson(
            $this->postReportUrl($postId),
            ['reason_type' => 'invalid_reason', 'reason_detail' => '상세 내용']
        )->assertStatus(422);
    }

    /**
     * reason_detail 누락 → 422
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects missing_reason_detail_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_missing_reason_detail_returns_422(): void
    {
        $reporter = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($reporter)->postJson(
            $this->postReportUrl($postId),
            ['reason_type' => 'spam']
        )->assertStatus(422);
    }

    // ==========================================
    // 댓글 신고
    // ==========================================

    /**
     * 회원이 다른 사람의 댓글 신고 → 201
     *
     * @scenario operation=submit_comment_report, condition=reportable_target
     *
     * @effects authenticated_user_can_report_comment
     *
     * @return void 반환값 없음
     */
    public function test_authenticated_user_can_report_comment(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $response = $this->actingAs($reporter)->postJson(
            $this->commentReportUrl($commentId),
            ['reason_type' => 'abuse', 'reason_detail' => '욕설 댓글입니다.']
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('boards_reports', [
            'board_id' => $this->board->id,
            'target_type' => 'comment',
            'target_id' => $commentId,
        ]);
    }

    /**
     * 본인 댓글 신고 → 403
     *
     * @scenario operation=submit_comment_report, condition=own_target
     *
     * @effects cannot_report_own_comment
     *
     * @return void 반환값 없음
     */
    public function test_cannot_report_own_comment(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $user->id]);

        $this->actingAs($user)->postJson(
            $this->commentReportUrl($commentId),
            ['reason_type' => 'abuse', 'reason_detail' => '욕설입니다.']
        )->assertStatus(403);
    }

    /**
     * 블라인드된 댓글 신고 → 403
     *
     * @scenario operation=submit_comment_report, condition=blinded_target
     *
     * @effects cannot_report_blinded_comment
     *
     * @return void 반환값 없음
     */
    public function test_cannot_report_blinded_comment(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $author->id,
            'status' => 'blinded',
        ]);

        $this->actingAs($reporter)->postJson(
            $this->commentReportUrl($commentId),
            ['reason_type' => 'abuse', 'reason_detail' => '욕설입니다.']
        )->assertStatus(403);
    }

    // ==========================================
    // Helper
    // ==========================================

    /**
     * 게시글 신고 API 주소를 만듭니다.
     *
     * @param  int  $postId  게시글 ID
     * @return string API 주소
     */
    private function postReportUrl(int $postId): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/boards/{$slug}/posts/{$postId}/reports";
    }

    /**
     * 댓글 신고 API 주소를 만듭니다.
     *
     * @param  int  $commentId  댓글 ID
     * @return string API 주소
     */
    private function commentReportUrl(int $commentId): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/boards/{$slug}/comments/{$commentId}/reports";
    }
}
