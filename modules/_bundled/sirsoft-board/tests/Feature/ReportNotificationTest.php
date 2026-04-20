<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use App\Notifications\GenericNotification;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 신고 알림 통합 테스트 (이슈 #35 Phase 1 + Phase 2 + Phase 3)
 *
 * Phase 1: 신고 처리(블라인드/삭제/복원) 시 피신고자 알림 발송 검증
 * Phase 2: 신고 접수 시 관리자 알림 발송 검증 (postTitle, reasonType 내용 포함)
 * Phase 3: per_case/per_report 발송 범위 검증 (재신고/재활성화 시나리오)
 * 실제 API 호출을 통해 훅 → 리스너 → 알림 전체 흐름을 검증합니다.
 */
class ReportNotificationTest extends ModuleTestCase
{
    private User $admin;

    private User $author;

    private Board $board;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config(['telescope.enabled' => false]);

        // 테스트 잔여 데이터 정리
        DB::table('boards_reports')->delete();
        DB::table('users')->where('is_super', false)->delete();

        // 사용자 생성
        $this->admin = $this->createAdminUser([
            'sirsoft-board.reports.view',
            'sirsoft-board.reports.manage',
        ]);
        $this->author = User::factory()->create();

        // 게시판 생성
        $this->board = Board::updateOrCreate(
            ['slug' => 'notify-test-board'],
            [
                'name' => ['ko' => '알림 테스트 게시판', 'en' => 'Notification Test Board'],
                'slug' => 'notify-test-board',
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

        DB::table('boards_reports')->delete();
        DB::table('board_comments')->where('board_id', $this->board->id)->delete();
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();
    }

    // ── report_policy.notify_author_on_report_action = ON ──

    /**
     * 신고 처리 정책 ON + suspended 상태 → 게시글 작성자에게 ReportActionNotification 발송
     */
    #[Test]
    public function test_게시글_신고_suspended_처리시_알림_발송(): void
    {
        // Given: 신고 처리 알림 정책 ON
        $this->setReportPolicy(true);

        $postId = $this->createTestPost($this->author->id);

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Pending,
        ]);

        // When: suspended 상태로 변경 (신고 처리 → blind)
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'suspended',
                'process_note' => '신고 처리합니다.',
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);

        // 게시글 작성자에게 ReportActionNotification 발송 확인
        Notification::assertSentTo($this->author, GenericNotification::class);
    }

    /**
     * 신고 처리 정책 ON + deleted 상태 → 게시글 작성자에게 ReportActionNotification 발송
     */
    #[Test]
    public function test_게시글_신고_deleted_처리시_알림_발송(): void
    {
        // Given: 신고 처리 알림 정책 ON
        $this->setReportPolicy(true);

        $postId = $this->createTestPost($this->author->id);

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Pending,
        ]);

        // When: deleted 상태로 변경
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'deleted',
                'process_note' => '삭제 처리합니다.',
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);

        // 게시글 작성자에게 ReportActionNotification 발송 확인
        Notification::assertSentTo($this->author, GenericNotification::class);
    }

    /**
     * 신고 처리 정책 ON + rejected(복원) 상태 → 게시글 작성자에게 ReportActionNotification 발송
     */
    #[Test]
    public function test_게시글_신고_rejected_복원시_알림_발송(): void
    {
        // Given: 신고 처리 알림 정책 ON + 블라인드된 게시글 + suspended 신고
        $this->setReportPolicy(true);

        $postId = $this->createTestPost($this->author->id, status: 'blinded', triggerType: 'report');

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Suspended,
        ]);

        // When: rejected 상태로 변경 (복원)
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'rejected',
                'process_note' => '반려 처리합니다.',
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);

        // 게시글 작성자에게 ReportActionNotification 발송 확인
        Notification::assertSentTo($this->author, GenericNotification::class);
    }

    // ── report_policy.notify_author_on_report_action = OFF ──

    /**
     * 신고 처리 정책 OFF → 알림 미발송
     */
    #[Test]
    public function test_게시글_신고_처리시_정책OFF_알림_미발송(): void
    {
        // Given: 신고 처리 알림 정책 OFF
        $this->setReportPolicy(false);

        $postId = $this->createTestPost($this->author->id);

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Pending,
        ]);

        // When: suspended 상태로 변경
        $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'suspended',
            ]);

        // Then: 알림 미발송
        Notification::assertNotSentTo($this->author, GenericNotification::class);
    }

    // ── 댓글 신고 처리 ──

    /**
     * 댓글 신고 처리 정책 ON + suspended → 댓글 작성자에게 ReportActionNotification 발송
     */
    #[Test]
    public function test_댓글_신고_suspended_처리시_알림_발송(): void
    {
        // Given: 신고 처리 알림 정책 ON
        $this->setReportPolicy(true);

        $postId = $this->createTestPost(User::factory()->create()->id);
        $commentId = $this->createTestComment($postId, $this->author->id);

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'comment',
            'target_id' => $commentId,
            'status' => ReportStatus::Pending,
        ]);

        // When: suspended 상태로 변경
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'suspended',
                'process_note' => '댓글 블라인드 처리합니다.',
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);

        // 댓글 작성자에게 ReportActionNotification 발송 확인
        Notification::assertSentTo($this->author, GenericNotification::class);
    }

    /**
     * 댓글 신고 처리 정책 OFF → 댓글 작성자에게 알림 미발송
     */
    #[Test]
    public function test_댓글_신고_처리시_정책OFF_알림_미발송(): void
    {
        // Given: 신고 처리 알림 정책 OFF
        $this->setReportPolicy(false);

        $postId = $this->createTestPost(User::factory()->create()->id);
        $commentId = $this->createTestComment($postId, $this->author->id);

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'comment',
            'target_id' => $commentId,
            'status' => ReportStatus::Pending,
        ]);

        // When: suspended 상태로 변경
        $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'suspended',
            ]);

        // Then: 알림 미발송
        Notification::assertNotSentTo($this->author, GenericNotification::class);
    }

    // ── report_policy.notify_admin_on_report ──

    /**
     * 신고 접수 관리자 알림 정책 ON + reports.manage 권한 보유자 존재
     * → 관리자에게 ReportReceivedAdminNotification 발송 (postTitle, reasonType 내용 포함)
     */
    #[Test]
    public function test_신고_접수시_정책ON_권한자에게_관리자알림_발송(): void
    {
        // Given: 관리자 알림 정책 ON
        $this->setAdminReportPolicy(true);

        $postId = $this->createTestPost(User::factory()->create()->id);

        // When: 신고 접수
        $reporter = User::factory()->create();
        $response = $this->actingAs($reporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'spam',
                'reason_detail' => '스팸 게시글입니다.',
            ]);

        // Then: 성공 응답
        $response->assertStatus(201);

        // reports.manage 권한 보유자(admin)에게 알림 발송 확인
        // post_title과 reason_type 내용도 검증
        Notification::assertSentTo(
            $this->admin,
            GenericNotification::class,
            function (GenericNotification $notification) {
                $data = $notification->getData();

                return ($data['post_title'] ?? '') === '테스트 게시글'
                    && str_contains($data['reason_type'] ?? '', 'spam');
            }
        );
    }

    /**
     * 신고 접수 관리자 알림 정책 OFF → 관리자 알림 미발송
     */
    #[Test]
    public function test_신고_접수시_정책OFF_관리자알림_미발송(): void
    {
        // Given: 관리자 알림 정책 OFF
        $this->setAdminReportPolicy(false);

        $postId = $this->createTestPost(User::factory()->create()->id);

        // When: 신고 접수
        $reporter = User::factory()->create();
        $this->actingAs($reporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'spam',
                'reason_detail' => '스팸 게시글입니다.',
            ]);

        // Then: 관리자 알림 미발송
        Notification::assertNotSentTo($this->admin, GenericNotification::class);
    }

    /**
     * 신고 접수 관리자 알림 정책 ON + reports.manage 권한자 없음 → 알림 미발송
     */
    #[Test]
    public function test_신고_접수시_정책ON_권한자없음_알림_미발송(): void
    {
        // Given: 관리자 알림 정책 ON + admin 역할에서 권한 제거 (권한자 0명 상태 시뮬레이션)
        $this->setAdminReportPolicy(true);

        // admin 역할에서 reports.manage 권한 제거
        $permission = \App\Models\Permission::where('identifier', 'sirsoft-board.reports.manage')->first();
        $adminRole = \App\Models\Role::where('identifier', 'admin')->first();
        if ($permission && $adminRole) {
            $adminRole->permissions()->detach($permission->id);
        }

        $postId = $this->createTestPost(User::factory()->create()->id);

        // When: 신고 접수
        $reporter = User::factory()->create();
        $response = $this->actingAs($reporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'abuse',
                'reason_detail' => '욕설 게시글입니다.',
            ]);

        // Then: 성공 응답 + 알림 미발송 (super_admin 폴백 없음)
        $response->assertStatus(201);
        Notification::assertNothingSent();
    }

    // ── per_case / per_report 발송 범위 ──

    /**
     * per_case: 동일 케이스에 재신고 → 두 번째 신고부터 알림 미발송
     */
    #[Test]
    public function test_per_case_재신고시_두번째부터_알림_미발송(): void
    {
        // Given: per_case 범위로 관리자 알림 정책 ON
        $this->setAdminReportPolicy(true, 'per_case');

        $postId = $this->createTestPost(User::factory()->create()->id);

        // 첫 번째 신고 접수 → Report 생성 (케이스 신규)
        $firstReporter = User::factory()->create();
        $response = $this->actingAs($firstReporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'spam',
                'reason_detail' => '첫 번째 신고',
            ]);
        $response->assertStatus(201);

        // 첫 신고 시 알림 발송 확인
        Notification::assertSentTo($this->admin, GenericNotification::class);

        // 알림 초기화
        Notification::fake();

        // 두 번째 신고 접수 (동일 케이스 재신고)
        $secondReporter = User::factory()->create();
        $response = $this->actingAs($secondReporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'abuse',
                'reason_detail' => '두 번째 신고',
            ]);
        $response->assertStatus(201);

        // Then: 재신고 시 알림 미발송
        Notification::assertNothingSent();
    }

    /**
     * per_report: 동일 케이스에 재신고 → 매 신고마다 알림 발송
     */
    #[Test]
    public function test_per_report_재신고시_매번_알림_발송(): void
    {
        // Given: per_report 범위로 관리자 알림 정책 ON
        $this->setAdminReportPolicy(true, 'per_report');

        $postId = $this->createTestPost(User::factory()->create()->id);

        // 첫 번째 신고
        $firstReporter = User::factory()->create();
        $response = $this->actingAs($firstReporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'spam',
                'reason_detail' => '첫 번째 신고',
            ]);
        $response->assertStatus(201);

        Notification::assertSentTo($this->admin, GenericNotification::class);

        // 알림 초기화
        Notification::fake();

        // 두 번째 신고 (동일 케이스 재신고)
        $secondReporter = User::factory()->create();
        $response = $this->actingAs($secondReporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'abuse',
                'reason_detail' => '두 번째 신고',
            ]);
        $response->assertStatus(201);

        // Then: per_report 는 재신고도 알림 발송
        Notification::assertSentTo($this->admin, GenericNotification::class);
    }

    /**
     * per_case: 케이스 재활성화 후 첫 신고 → 알림 발송 (새 사이클 시작)
     *
     * 재활성화(last_activated_at 갱신) 이후 첫 신고 로그는 활성 사이클 내 1번째이므로
     * per_case 조건에서도 알림이 발송되어야 합니다.
     */
    #[Test]
    public function test_per_case_재활성화후_첫신고시_알림_발송(): void
    {
        // Given: per_case 범위 + 이미 신고 로그가 있는 케이스 (재활성화 상태)
        $this->setAdminReportPolicy(true, 'per_case');

        $postId = $this->createTestPost(User::factory()->create()->id);

        // 첫 신고 → 케이스 생성
        $firstReporter = User::factory()->create();
        $this->actingAs($firstReporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'spam',
                'reason_detail' => '첫 신고',
            ]);

        // 두 번째 신고 (같은 케이스, logs count = 2)
        $this->resetListenerProcessedKeys();
        $secondReporter = User::factory()->create();
        $this->actingAs($secondReporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'abuse',
                'reason_detail' => '두 번째 신고',
            ]);

        // 케이스 재활성화: last_activated_at을 현재 시각으로 업데이트 (이전 로그들을 이전 사이클로 격리)
        $report = Report::where('target_type', 'post')->where('target_id', $postId)->first();
        $reactivatedAt = now()->addSecond(); // 기존 로그보다 미래로 설정
        $report->update(['last_activated_at' => $reactivatedAt]);

        // 알림 초기화 (재활성화 이전 알림 무시)
        Notification::fake();

        // 재활성화 이후 첫 신고
        $thirdReporter = User::factory()->create();
        $response = $this->actingAs($thirdReporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'spam',
                'reason_detail' => '재활성화 후 첫 신고',
            ]);
        $response->assertStatus(201);

        // Then: 재활성화 후 첫 신고 → 새 사이클 시작이므로 알림 발송
        Notification::assertSentTo($this->admin, GenericNotification::class);
    }

    // ── 헬퍼 메서드 ──

    /**
     * report_policy.notify_author_on_report_action 설정을 주입합니다.
     *
     * @param bool $enabled 알림 발송 여부
     * @return void
     */
    private function setReportPolicy(bool $enabled): void
    {
        config([
            'g7_settings.modules.sirsoft-board.report_policy' => [
                'notify_author_on_report_action' => $enabled,
                'notify_author_on_report_action_channels' => ['mail'],
            ],
        ]);
    }

    /**
     * report_policy.notify_admin_on_report 설정을 주입합니다.
     *
     * @param bool $enabled 알림 발송 여부
     * @param string $scope 발송 범위 ('per_case' | 'per_report')
     * @return void
     */
    private function setAdminReportPolicy(bool $enabled, string $scope = 'per_case'): void
    {
        // g7_module_settings()는 Config에서 읽으므로 config 주입으로 알림 정책 제어
        config([
            'g7_settings.modules.sirsoft-board.report_policy' => [
                'notify_admin_on_report' => $enabled,
                'notify_admin_on_report_scope' => $scope,
                'notify_admin_on_report_channels' => ['mail'],
            ],
        ]);

        // checkAndApplyAutoHide()는 BoardSettingsService(파일)에서 읽으므로
        // auto_hide_threshold를 충분히 높게 설정하여 테스트 중 자동 블라인드 방지
        /** @var \Modules\Sirsoft\Board\Services\BoardSettingsService $settingsService */
        $settingsService = app(\Modules\Sirsoft\Board\Services\BoardSettingsService::class);
        $settingsService->setSetting('report_policy.auto_hide_threshold', 100);
        $settingsService->clearCache();
    }


    /**
     * 테스트용 게시글 생성 헬퍼
     *
     * @param int $userId 작성자 ID
     * @param string $status 게시글 상태
     * @param string $triggerType 처리 유형
     * @return int 생성된 게시글 ID
     */
    private function createTestPost(int $userId, string $status = 'published', string $triggerType = 'admin'): int
    {
        return DB::table('board_posts')->insertGetId([
            'board_id' => $this->board->id,
            'title' => '테스트 게시글',
            'content' => '테스트 내용',
            'user_id' => $userId,
            'author_name' => '테스트 작성자',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => $status,
            'trigger_type' => $triggerType,
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 테스트용 댓글 생성 헬퍼
     *
     * @param int $postId 게시글 ID
     * @param int $userId 작성자 ID
     * @return int 생성된 댓글 ID
     */
    private function createTestComment(int $postId, int $userId): int
    {
        return DB::table('board_comments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'user_id' => $userId,
            'author_name' => '테스트 댓글 작성자',
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
}
