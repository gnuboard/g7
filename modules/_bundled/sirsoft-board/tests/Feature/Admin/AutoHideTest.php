<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Services\ReportService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 자동 블라인드 처리 Feature 통합 테스트
 *
 * ReportService::createReport() 호출 시 auto_hide_threshold 도달 여부에 따라
 * 게시글/댓글이 자동으로 blinded 상태가 되는지 검증합니다.
 *
 * 1케이스 구조:
 * - boards_reports: 게시글/댓글당 1개 케이스
 * - boards_report_logs: 신고자별 기록
 * - threshold 카운트: last_activated_at 이후 logs 건수 기준
 */
class AutoHideTest extends ModuleTestCase
{
    private User $reporter1;

    private User $reporter2;

    private User $reporter3;

    private User $author;

    private Board $board;

    private ReportService $reportService;

    /**
     * BoardSettingsService mock용 설정값
     *
     * @var array<string, array<string, mixed>>
     */
    private array $mockSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['telescope.enabled' => false]);
        App::setLocale('ko');

        // DDL implicit commit으로 이전 테스트 데이터 잔류 → 충돌 방지
        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        DB::table('users')->where('is_super', false)->delete();

        // 테스트 사용자 생성
        $this->reporter1 = $this->createUser();
        $this->reporter2 = $this->createUser();
        $this->reporter3 = $this->createUser();
        $this->author = $this->createUser();

        // 테스트 게시판 생성
        $this->board = Board::updateOrCreate(
            ['slug' => 'auto-hide-test'],
            [
                'name' => ['ko' => '자동 블라인드 테스트', 'en' => 'Auto Hide Test'],
                'slug' => 'auto-hide-test',
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

        $this->ensureBoardPartitions($this->board->id);

        // 이전 잔여 데이터 정리
        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        DB::table('board_comments')->where('board_id', $this->board->id)->delete();
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();

        // 기본 설정값 (threshold=3, target=both, 남발방지 비활성화)
        $this->mockSettings = [
            'report_policy' => [
                'auto_hide_threshold' => 3,
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
    }

    /**
     * BoardSettingsService를 mock으로 교체하고 ReportService를 resolve합니다.
     * checkAndApplyAutoHide()는 app(BoardSettingsService::class)로 resolve하므로
     * mock 교체 후 ReportService를 다시 만들어야 합니다.
     */
    private function setupWithMockedSettings(): void
    {
        $mock = $this->createMock(BoardSettingsService::class);
        $mock->method('getSettings')
            ->willReturnCallback(fn (string $category) => $this->mockSettings[$category] ?? []);

        $this->app->instance(BoardSettingsService::class, $mock);
        $this->reportService = $this->app->make(ReportService::class);
    }

    // ==========================================
    // 자동 블라인드 - 게시글
    // ==========================================

    /**
     * threshold 도달 시 게시글 자동 블라인드 처리
     */
    #[Test]
    public function blinds_post_when_report_threshold_reached(): void
    {
        // Given: threshold=3, 게시글 생성
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();

        // 1번째, 2번째 신고 → threshold 미도달
        $this->submitReportForPost($postId, $this->reporter1);
        $this->submitReportForPost($postId, $this->reporter2);

        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('published', $post->status, '2건 신고 후 published 유지');

        // When: 3번째 신고 → threshold 도달
        $this->submitReportForPost($postId, $this->reporter3);

        // Then: 자동 블라인드 적용
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('blinded', $post->status, '3건 신고 후 blinded');
        $this->assertEquals('auto_hide', $post->trigger_type, 'trigger_type이 auto_hide');
    }

    /**
     * threshold 미만 신고 시 게시글 published 유지
     */
    #[Test]
    public function keeps_post_published_when_below_threshold(): void
    {
        // Given: threshold=3
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();

        // When: 2건 신고 (threshold-1)
        $this->submitReportForPost($postId, $this->reporter1);
        $this->submitReportForPost($postId, $this->reporter2);

        // Then: published 유지
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('published', $post->status);
    }

    /**
     * threshold=0이면 자동 블라인드 비활성화
     */
    #[Test]
    public function does_not_blind_when_threshold_is_zero(): void
    {
        // Given: threshold=0 (비활성화)
        $this->mockSettings['report_policy']['auto_hide_threshold'] = 0;
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();

        // When: 3건 신고 (threshold=0이므로 비활성)
        $this->submitReportForPost($postId, $this->reporter1);
        $this->submitReportForPost($postId, $this->reporter2);
        $this->submitReportForPost($postId, $this->reporter3);

        // Then: published 유지
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('published', $post->status);
    }

    // ==========================================
    // 자동 블라인드 - 댓글
    // ==========================================

    /**
     * threshold 도달 시 댓글 자동 블라인드 처리
     */
    #[Test]
    public function blinds_comment_when_report_threshold_reached(): void
    {
        // Given: threshold=3, 댓글 생성
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        // When: 3건 신고 → threshold 도달
        $this->submitReportForComment($commentId, $this->reporter1);
        $this->submitReportForComment($commentId, $this->reporter2);
        $this->submitReportForComment($commentId, $this->reporter3);

        // Then: 자동 블라인드 적용
        $comment = DB::table('board_comments')->find($commentId);
        $this->assertEquals('blinded', $comment->status, '댓글 3건 신고 후 blinded');
        $this->assertEquals('auto_hide', $comment->trigger_type, 'trigger_type이 auto_hide');
    }

    // ==========================================
    // auto_hide_target 설정 테스트
    // ==========================================

    /**
     * auto_hide_target='post'이면 댓글에 미적용
     */
    #[Test]
    public function does_not_blind_comment_when_target_is_post_only(): void
    {
        // Given: threshold=3, target='post'
        $this->mockSettings['report_policy']['auto_hide_target'] = 'post';
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        // When: 댓글 3건 신고 (target='post'이므로 댓글은 미적용)
        $this->submitReportForComment($commentId, $this->reporter1);
        $this->submitReportForComment($commentId, $this->reporter2);
        $this->submitReportForComment($commentId, $this->reporter3);

        // Then: 댓글 published 유지
        $comment = DB::table('board_comments')->find($commentId);
        $this->assertEquals('published', $comment->status);
    }

    /**
     * auto_hide_target='comment'이면 게시글에 미적용
     */
    #[Test]
    public function does_not_blind_post_when_target_is_comment_only(): void
    {
        // Given: threshold=3, target='comment'
        $this->mockSettings['report_policy']['auto_hide_target'] = 'comment';
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();

        // When: 게시글 3건 신고 (target='comment'이므로 게시글 미적용)
        $this->submitReportForPost($postId, $this->reporter1);
        $this->submitReportForPost($postId, $this->reporter2);
        $this->submitReportForPost($postId, $this->reporter3);

        // Then: 게시글 published 유지
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('published', $post->status);
    }

    // ==========================================
    // 멱등성 테스트
    // ==========================================

    /**
     * 이미 blinded 상태에서 추가 신고 시 상태 변경 없음 (멱등성)
     *
     * blindPost()의 멱등성 체크로 이미 blinded 상태면 early return하므로
     * trigger_type이 변경되지 않음을 검증합니다.
     */
    #[Test]
    public function does_not_duplicate_action_log_when_already_blinded(): void
    {
        // Given: 이미 blinded 상태의 게시글 (trigger_type='admin'으로 수동 블라인드 상태 가정)
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();

        // 게시글을 미리 blinded 상태로 변경 (수동 블라인드 가정: trigger_type='admin')
        DB::table('board_posts')
            ->where('id', $postId)
            ->where('board_id', $this->board->id)
            ->update(['status' => 'blinded', 'trigger_type' => 'admin']);

        // When: threshold 도달 신고 추가 (이미 blinded이므로 멱등성 체크로 skip)
        $this->submitReportForPost($postId, $this->reporter1);
        $this->submitReportForPost($postId, $this->reporter2);
        $this->submitReportForPost($postId, $this->reporter3);

        // Then: 게시글은 여전히 blinded이고 trigger_type도 변경되지 않음 (admin 유지)
        $post = DB::table('board_posts')
            ->where('id', $postId)
            ->where('board_id', $this->board->id)
            ->first();
        $this->assertEquals('blinded', $post->status, '이미 blinded 상태 유지');
        $this->assertEquals('admin', $post->trigger_type, '멱등성: trigger_type이 admin에서 변경되지 않음');
    }

    // ==========================================
    // 카운트 기준 테스트 (last_activated_at 이후 logs만 카운트)
    // ==========================================

    /**
     * 반려 후 재신고 시 이전 사이클 신고는 카운트에서 제외됩니다.
     *
     * 1케이스 구조: 반려된 케이스에 재신고하면 last_activated_at이 갱신되고
     * 카운트는 last_activated_at 이후 logs만 기준으로 합니다.
     */
    #[Test]
    public function rejected_cycle_logs_not_counted_after_reactivation(): void
    {
        // Given: threshold=3, 게시글 신고 2건 → 케이스 생성
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();

        $report = $this->submitReportForPost($postId, $this->reporter1);
        $this->submitReportForPost($postId, $this->reporter2);

        // 케이스 반려 처리 (admin으로 로그인)
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $this->reportService->updateReportStatus($report->id, ['status' => 'rejected', 'admin_reason' => '증거 불충분']);

        // When: 재신고 1건 → 재활성화 (last_activated_at 갱신, 새 사이클 시작)
        // 현재 사이클은 1건 (threshold 3 미도달)
        $reporter4 = $this->createUser();
        $this->submitReportForPost($postId, $reporter4);

        // Then: 재활성화 후 1건만 카운트 → published 유지 (threshold 3 미도달)
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('published', $post->status, '재활성화 후 현재 사이클 1건만 카운트');
    }

    // ==========================================
    // trigger_type 기록 확인
    // ==========================================

    /**
     * 자동 블라인드 시 trigger_type='auto_hide' 기록
     */
    #[Test]
    public function records_auto_hide_trigger_type(): void
    {
        // Given: threshold=3
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();

        // When: 3건 신고
        $this->submitReportForPost($postId, $this->reporter1);
        $this->submitReportForPost($postId, $this->reporter2);
        $this->submitReportForPost($postId, $this->reporter3);

        // Then: trigger_type이 'auto_hide'
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('auto_hide', $post->trigger_type);
    }

    // ==========================================
    // 자동 블라인드 복구 테스트
    // ==========================================

    /**
     * 자동 블라인드된 게시글: 케이스 반려 시 자동 복구
     *
     * 1케이스 구조에서는 케이스 1개이므로 반려하면 즉시 복구됩니다.
     */
    #[Test]
    public function restores_auto_hide_post_when_case_is_rejected(): void
    {
        // Given: threshold=3, 게시글 3건 신고 → 자동 블라인드
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();

        $report = $this->submitReportForPost($postId, $this->reporter1);
        $this->submitReportForPost($postId, $this->reporter2);
        $this->submitReportForPost($postId, $this->reporter3);

        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('blinded', $post->status, '3건 신고 후 blinded');
        $this->assertEquals('auto_hide', $post->trigger_type);

        // 관리자로 로그인
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        // When: 케이스 반려 (1케이스 구조 — 1번만 반려하면 됨)
        $this->reportService->updateReportStatus($report->id, ['status' => 'rejected', 'admin_reason' => '테스트 반려']);

        // Then: 케이스 반려 → 게시글 자동 복구
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('published', $post->status, '케이스 반려 후 published로 복구');
    }

    /**
     * 수동 블라인드(admin)된 게시글: 케이스 반려 시 복구됨
     */
    #[Test]
    public function restores_manual_blind_post_when_case_is_rejected(): void
    {
        // Given: threshold=0(비활성), 수동 블라인드된 게시글에 신고
        $this->mockSettings['report_policy']['auto_hide_threshold'] = 0;
        $this->setupWithMockedSettings();
        $postId = $this->createTestPost();

        // 수동 블라인드 (trigger_type='admin')
        DB::table('board_posts')
            ->where('id', $postId)
            ->where('board_id', $this->board->id)
            ->update(['status' => 'blinded', 'trigger_type' => 'admin']);

        // 신고 1건 제출
        $report = $this->submitReportForPost($postId, $this->reporter1);

        // 관리자로 로그인
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        // When: 반려 (케이스 1개 → 즉시 복구)
        $this->reportService->updateReportStatus($report->id, ['status' => 'rejected', 'admin_reason' => '반려']);

        // Then: trigger_type 무관 → 복구됨
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('published', $post->status, '수동 블라인드도 케이스 반려 시 복구됨');
    }

    // ==========================================
    // 헬퍼 메서드
    // ==========================================

    /**
     * 테스트용 게시글을 생성합니다.
     */
    private function createTestPost(): int
    {
        return DB::table('board_posts')->insertGetId([
            'board_id' => $this->board->id,
            'title' => '자동 블라인드 테스트 게시글',
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

    /**
     * 테스트용 댓글을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return int 생성된 댓글 ID
     */
    private function createTestComment(int $postId): int
    {
        return DB::table('board_comments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'user_id' => $this->author->id,
            'author_name' => $this->author->name,
            'content' => '테스트 댓글 내용',
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'depth' => 0,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ReportService::createReport()를 통해 게시글 신고를 제출합니다.
     * 자동 블라인드 로직(checkAndApplyAutoHide)이 실행됩니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  User  $reporter  신고자
     */
    private function submitReportForPost(int $postId, User $reporter): Report
    {
        return $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $reporter->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '테스트 신고',
        ]);
    }

    /**
     * ReportService::createReport()를 통해 댓글 신고를 제출합니다.
     * 자동 블라인드 로직(checkAndApplyAutoHide)이 실행됩니다.
     *
     * @param  int  $commentId  댓글 ID
     * @param  User  $reporter  신고자
     */
    private function submitReportForComment(int $commentId, User $reporter): Report
    {
        return $this->reportService->createReport([
            'board_id' => $this->board->id,
            'target_type' => 'comment',
            'target_id' => $commentId,
            'reporter_id' => $reporter->id,
            'author_id' => $this->author->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '테스트 신고',
        ]);
    }
}
