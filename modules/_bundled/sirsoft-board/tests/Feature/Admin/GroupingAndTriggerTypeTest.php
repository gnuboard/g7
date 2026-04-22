<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\ReportService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 1케이스 구조 + trigger_type 기록 통합 테스트
 *
 * 검증 대상:
 * - getGroupedReports(): 케이스 단위 목록 조회, 상태 필터
 * - createReport(): 케이스 조회/생성, 재신고 재활성화
 * - deleteComment() trigger_type 기록
 */
class GroupingAndTriggerTypeTest extends ModuleTestCase
{
    private User $reporter1;

    private User $reporter2;

    private User $author;

    private User $admin;

    private Board $board;

    private ReportService $reportService;

    /** @var array<string, array<string, mixed>> */
    private array $mockSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['telescope.enabled' => false]);
        App::setLocale('ko');

        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        DB::table('users')->where('is_super', false)->delete();

        $this->reporter1 = $this->createUser();
        $this->reporter2 = $this->createUser();
        $this->author = $this->createUser();
        $this->admin = $this->createAdminUser();

        $this->board = Board::updateOrCreate(
            ['slug' => 'grouping-test'],
            [
                'name' => ['ko' => '그룹핑 테스트 게시판', 'en' => 'Grouping Test Board'],
                'slug' => 'grouping-test',
                'type' => 'list',
                'per_page' => 20,
                'per_page_mobile' => 10,
                'order_by' => 'created_at',
                'order_direction' => 'DESC',
                'secret_mode' => 'disabled',
                'use_comment' => true,
                'use_reply' => false,
                'use_file_upload' => false,
                'use_report' => true,
                'blocked_keywords' => [],
                'notify_admin_on_post' => false,
                'notify_author_on_comment' => false,
            ]
        );

        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        DB::table('board_comments')->where('board_id', $this->board->id)->delete();
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();

        // 자동 블라인드 비활성화 (테스트 간섭 방지)
        $this->mockSettings = [
            'report_policy' => [
                'auto_hide_threshold' => 0,
                'auto_hide_target' => 'both',
                'daily_report_limit' => 0,
                'rejection_limit_count' => 0,
                'rejection_limit_days' => 30,
            ],
            'spam_security' => [
                'post_cooldown_seconds' => 0,
                'comment_cooldown_seconds' => 0,
                'report_cooldown_seconds' => 0,
                'view_count_cache_ttl' => 86400,
            ],
        ];

        $this->setupWithMockedSettings();
    }

    // ==========================================
    // 1케이스 구조: 게시글당 케이스 1개 보장
    // ==========================================

    /**
     * 같은 게시글에 복수 신고가 접수되어도 케이스는 1개만 생성됩니다.
     */
    #[Test]
    public function same_target_creates_only_one_case(): void
    {
        // Given: 게시글 1개
        $postId = $this->createTestPost();

        // When: 2명이 같은 게시글 신고
        $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->reporter1->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '스팸',
        ]);

        $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->reporter2->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Abuse,
            'reason_detail' => '욕설',
        ]);

        // Then: boards_reports에 케이스 1개만 존재
        $caseCount = DB::table('boards_reports')
            ->where('board_id', $this->board->id)
            ->where('target_type', 'post')
            ->where('target_id', $postId)
            ->count();
        $this->assertEquals(1, $caseCount, '같은 게시글 케이스는 1개만 생성');

        // boards_report_logs에는 2건
        $logCount = DB::table('boards_report_logs')
            ->whereIn('report_id', DB::table('boards_reports')
                ->where('board_id', $this->board->id)
                ->where('target_type', 'post')
                ->where('target_id', $postId)
                ->pluck('id'))
            ->count();
        $this->assertEquals(2, $logCount, '신고자 로그는 2건');
    }

    /**
     * getGroupedReports()는 케이스 단위로 목록을 반환합니다.
     */
    #[Test]
    public function paginate_grouped_returns_cases_per_target(): void
    {
        // Given: 게시글 2개에 각각 신고
        $postId1 = $this->createTestPost();
        $postId2 = $this->createTestPost();

        $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId1,
            'reporter_id' => $this->reporter1->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '스팸',
        ]);

        $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId2,
            'reporter_id' => $this->reporter2->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Abuse,
            'reason_detail' => '욕설',
        ]);

        // When: 전체 목록 조회
        $result = $this->reportService->getGroupedReports([]);

        // Then: 케이스 2개 반환
        $this->assertCount(2, $result->items(), '게시글 2개 → 케이스 2개');
    }

    /**
     * getGroupedReports()는 상태 필터를 지원합니다.
     */
    #[Test]
    public function paginate_grouped_filters_by_status(): void
    {
        // Given: 게시글 2개, 각각 pending/rejected 케이스
        $postId1 = $this->createTestPost();
        $postId2 = $this->createTestPost();

        // pending 케이스
        $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId1,
            'reporter_id' => $this->reporter1->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '스팸',
        ]);

        // rejected 케이스 (직접 생성)
        $rejectedCase = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId2,
            'author_id' => $this->author->id,
            'status' => ReportStatus::Rejected,
        ]);

        // When: pending 필터로 목록 조회
        $result = $this->reportService->getGroupedReports(['status' => 'pending']);

        // Then: pending 케이스만 반환
        $ids = $result->pluck('id')->toArray();
        $this->assertNotContains($rejectedCase->id, $ids, 'rejected 케이스는 pending 필터에서 제외');
        $this->assertCount(1, $result->items(), 'pending 케이스 1개만');
    }

    // ==========================================
    // 재신고: 반려 후 재신고 시 케이스 재활성화
    // ==========================================

    /**
     * 반려된 케이스에 재신고하면 케이스가 pending으로 재활성화됩니다.
     */
    #[Test]
    public function rejected_case_is_reactivated_on_new_report(): void
    {
        // Given: 게시글 신고 → 케이스 생성 → 반려
        $postId = $this->createTestPost();

        $report = $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->reporter1->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '스팸',
        ]);

        $this->actingAs($this->admin);
        $this->reportService->updateReportStatus($report->id, ['status' => 'rejected', 'admin_reason' => '증거 불충분']);

        $rejectedCase = Report::find($report->id);
        $this->assertEquals(ReportStatus::Rejected, $rejectedCase->status, '반려 상태 확인');

        // When: 새 신고자가 재신고
        $reporter3 = $this->createUser();
        $reactivated = $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $reporter3->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Abuse,
            'reason_detail' => '욕설',
        ]);

        // Then: 같은 케이스 ID로 pending 재활성화
        $this->assertEquals($report->id, $reactivated->id, '케이스 ID 동일 (새 케이스 생성 아님)');
        $this->assertEquals(ReportStatus::Pending, $reactivated->status, '재신고 후 pending 재활성화');

        // boards_reports에는 여전히 1개 케이스
        $caseCount = DB::table('boards_reports')
            ->where('target_type', 'post')
            ->where('target_id', $postId)
            ->count();
        $this->assertEquals(1, $caseCount, '케이스는 여전히 1개');
    }

    // ==========================================
    // review 상태 전파
    // ==========================================

    /**
     * review 상태 케이스에 재신고하면 케이스는 review 상태를 유지합니다.
     */
    #[Test]
    public function case_stays_review_when_additional_report_arrives(): void
    {
        // Given: 게시글에 신고 → 케이스 생성 → review로 상태 변경
        $postId = $this->createTestPost();

        $report = $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->reporter1->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '스팸',
        ]);

        // review 상태로 변경
        $this->actingAs($this->admin);
        $this->reportService->updateReportStatus($report->id, ['status' => 'review']);

        // When: 추가 신고 접수
        $updatedCase = $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->reporter2->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Abuse,
            'reason_detail' => '욕설',
        ]);

        // Then: 케이스는 review 상태 유지
        $this->assertEquals(
            ReportStatus::Review,
            $updatedCase->status,
            '활성(review) 케이스에 재신고해도 review 유지'
        );
    }

    /**
     * pending 케이스에 추가 신고 시 pending 상태를 유지합니다.
     */
    #[Test]
    public function case_stays_pending_when_additional_report_arrives(): void
    {
        // Given: 게시글에 신고 → pending 케이스
        $postId = $this->createTestPost();

        $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->reporter1->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '스팸',
        ]);

        // When: 추가 신고
        $updatedCase = $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->reporter2->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Abuse,
            'reason_detail' => '욕설',
        ]);

        // Then: pending 유지
        $this->assertEquals(
            ReportStatus::Pending,
            $updatedCase->status,
            'pending 케이스에 재신고해도 pending 유지'
        );
    }

    // ==========================================
    // deleteComment trigger_type 기록
    // ==========================================

    /**
     * 관리자가 댓글 삭제 시 trigger_type='admin'이 기록됩니다.
     */
    #[Test]
    public function delete_comment_records_admin_trigger_type(): void
    {
        // Given: 댓글 생성
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        // When: CommentService::deleteComment() — triggerType='admin'
        $commentService = $this->app->make(CommentService::class);
        $commentService->deleteComment('grouping-test', $commentId, 'admin');

        // Then: trigger_type='admin' 기록
        $comment = DB::table('board_comments')
            ->where('id', $commentId)
            ->where('board_id', $this->board->id)
            ->first();

        $this->assertEquals('admin', $comment->trigger_type, 'admin 삭제 시 trigger_type=admin');
        $this->assertEquals('deleted', $comment->status, '삭제된 댓글 status=deleted');
    }

    /**
     * 사용자가 댓글 삭제 시 trigger_type='user'가 기록됩니다.
     */
    #[Test]
    public function delete_comment_records_user_trigger_type(): void
    {
        // Given: 댓글 생성
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        // When: CommentService::deleteComment() — triggerType='user'
        $commentService = $this->app->make(CommentService::class);
        $commentService->deleteComment('grouping-test', $commentId, 'user');

        // Then: trigger_type='user' 기록
        $comment = DB::table('board_comments')
            ->where('id', $commentId)
            ->where('board_id', $this->board->id)
            ->first();

        $this->assertEquals('user', $comment->trigger_type, '사용자 삭제 시 trigger_type=user');
    }

    /**
     * TriggerType::User enum 케이스가 정상 동작합니다.
     */
    #[Test]
    public function trigger_type_user_case_exists(): void
    {
        $this->assertEquals('user', TriggerType::User->value);
        $this->assertNotNull(TriggerType::tryFrom('user'));
        $this->assertNotEmpty(TriggerType::User->label());
    }

    // ==========================================
    // 헬퍼 메서드
    // ==========================================

    private function setupWithMockedSettings(): void
    {
        $mock = $this->createMock(BoardSettingsService::class);
        $mock->method('getSettings')
            ->willReturnCallback(fn (string $category) => $this->mockSettings[$category] ?? []);

        $this->app->instance(BoardSettingsService::class, $mock);
        $this->reportService = $this->app->make(ReportService::class);
    }

    private function createTestPost(): int
    {
        return DB::table('board_posts')->insertGetId([
            'board_id' => $this->board->id,
            'title' => '그룹핑 테스트 게시글',
            'content' => '테스트 내용',
            'user_id' => $this->author->id,
            'author_name' => $this->author->name,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTestComment(int $postId): int
    {
        return DB::table('board_comments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'user_id' => $this->author->id,
            'author_name' => $this->author->name,
            'content' => '테스트 댓글',
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'depth' => 0,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
