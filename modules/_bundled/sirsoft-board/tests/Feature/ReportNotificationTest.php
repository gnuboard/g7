<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\ModuleManager;
use App\Listeners\NotificationHookListener;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GenericNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 신고 알림 통합 테스트 (이슈 #35 Phase 1 + Phase 2 + Phase 3)
 *
 * Phase 1: 신고 처리(블라인드/삭제/복원) 시 피신고자 알림 발송 검증
 * Phase 2: 신고 접수 시 관리자 알림 발송 검증 (postTitle, reasonType 내용 포함)
 * Phase 3: per_case/per_report 발송 범위 검증 (재신고/재활성화 시나리오)
 * 실제 API 호출을 통해 훅 → 리스너 → 알림 전체 흐름을 검증합니다.
 * 접수 알림 수신자는 게시판 관리자 + 사이트 운영자(admin 역할)입니다.
 * 시나리오 SSoT: tests/scenarios/report-notification.yaml
 */
class ReportNotificationTest extends ModuleTestCase
{
    private User $admin;

    private User $author;

    private Board $board;

    /**
     * 알림 정의 동기화·동적 훅 등록 후 신고 관리 관리자, 피신고자, 신고 가능한 게시판을 준비합니다.
     *
     * 테스트 환경에서는 부트 시점 훅 구독이 일어나지 않으므로 알림 정의를 직접 동기화하고
     * `NotificationHookListener` 의 동적 훅을 등록해 훅 → 리스너 → 알림 흐름을 복원한다.
     * 관리자는 `createAdminUser()` 로 만들어 admin 역할에 소속된다(관리자 알림 수신자).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config(['telescope.enabled' => false]);

        // module.php::getNotificationDefinitions() 를 SSoT 로 알림 정의 동기화 +
        // NotificationHookListener 동적 훅 등록 (테스트 환경에서는 부트 시점에 실행되지 않음 —
        // 알림 정의 기반 훅 구독을 수동으로 활성화하여 actual notification flow 복원)
        $this->syncBoardNotificationDefinitions();
        app(NotificationHookListener::class)->registerDynamicHooks();

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

        DB::table('boards_reports')->delete();
        DB::table('board_comments')->where('board_id', $this->board->id)->delete();
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();
    }

    // ── report_policy.notify_author_on_report_action = ON ──

    /**
     * 신고 처리 알림 정책 ON 에서 게시글 신고를 suspended(블라인드)로 처리하면 게시글 작성자에게 알림이 갑니다.
     *
     * @scenario case=post_action_notice
     *
     * @effects author_notified_when_post_report_is_suspended
     *
     * @return void 반환값 없음
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
     * 신고 처리 알림 정책 ON 에서 게시글 신고를 deleted(삭제)로 처리하면 게시글 작성자에게 알림이 갑니다.
     *
     * @scenario case=post_action_notice
     *
     * @effects author_notified_when_post_report_is_deleted
     *
     * @return void 반환값 없음
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
     * 신고 처리 알림 정책 ON 에서 블라인드된 게시글의 신고를 rejected(반려·복원)로 처리하면 작성자에게 알림이 갑니다.
     *
     * @scenario case=post_action_notice
     *
     * @effects author_notified_when_post_report_is_rejected_and_restored
     *
     * @return void 반환값 없음
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
     * 신고 처리 알림 정책 OFF 이면 게시글 신고를 처리해도 작성자에게 알림이 가지 않습니다.
     *
     * @scenario case=post_action_notice
     *
     * @effects author_not_notified_when_action_policy_is_off
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_게시글_신고_처리시_정책_off_알림_미발송(): void
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
     * 신고 처리 알림 정책 ON 에서 댓글 신고를 suspended 로 처리하면 댓글 작성자에게 알림이 갑니다.
     *
     * @scenario case=comment_action_notice
     *
     * @effects comment_author_notified_when_comment_report_is_suspended
     *
     * @return void 반환값 없음
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
     * 신고 처리 알림 정책 OFF 이면 댓글 신고를 처리해도 댓글 작성자에게 알림이 가지 않습니다.
     *
     * @scenario case=comment_action_notice
     *
     * @effects author_not_notified_when_action_policy_is_off
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_댓글_신고_처리시_정책_off_알림_미발송(): void
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
     * 신고 접수 관리자 알림 정책 ON 이면 admin 역할 소속 운영자에게 알림이 가고, 게시글 제목·신고 사유가 실립니다.
     *
     * 수신자는 그 게시판의 관리자와 사이트 운영자(`admin` 역할)다. 신고 사유는 번역된 라벨이라
     * 대소문자 무관으로 `spam` 을 포함하는지 본다.
     *
     * @scenario case=admin_received_notice
     *
     * @effects admin_notified_on_report_received_with_post_title_and_reason
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_신고_접수시_정책_on_권한자에게_관리자알림_발송(): void
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
        // post_title과 reason_type 내용도 검증 (reason_type 은 번역된 라벨 'Spam/Advertising' — 대소문자 무관 매칭)
        Notification::assertSentTo(
            $this->admin,
            GenericNotification::class,
            function (GenericNotification $notification) {
                $data = $notification->getData();

                return ($data['post_title'] ?? '') === '테스트 게시글'
                    && stripos($data['reason_type'] ?? '', 'spam') !== false;
            }
        );
    }

    /**
     * 신고 접수 관리자 알림 정책 OFF 이면 관리자 알림이 가지 않습니다.
     *
     * @scenario case=admin_received_notice
     *
     * @effects admin_not_notified_when_received_policy_is_off
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_신고_접수시_정책_off_관리자알림_미발송(): void
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
     * 신고 접수 관리자 알림 정책 ON 이어도 받을 운영자가 없으면 알림이 가지 않습니다 (super_admin 폴백 없음).
     *
     * 수신자는 그 게시판의 관리자와 사이트 운영자(`admin` 역할)다 — 게시판마다 관리자 역할이
     * 동적으로 생겨 권한 식별자로는 열거할 수 없어 1.3.0 에서 권한 기준에서 이 둘로 바꿨다.
     * admin 역할 소속을 비우고 게시판 관리자도 두지 않아, 최고 관리자에게 대신 보내지 않는지를 본다.
     *
     * @scenario case=admin_recipient_absent
     *
     * @effects no_fallback_to_super_admin_when_no_board_manager_or_admin_role_member
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_신고_접수시_정책_on_권한자없음_알림_미발송(): void
    {
        // Given: 관리자 알림 정책 ON + admin 역할 소속 사용자 0명 + 게시판 관리자 없음
        $this->setAdminReportPolicy(true);

        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $adminRole->users()->detach();
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
     * per_case 범위에서는 같은 케이스의 재신고부터 관리자 알림이 가지 않습니다.
     *
     * @scenario case=notice_scope
     *
     * @effects per_case_notifies_only_the_first_report_of_a_case
     *
     * @return void 반환값 없음
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
     * per_report 범위에서는 같은 케이스에 재신고가 들어올 때마다 관리자 알림이 갑니다.
     *
     * @scenario case=notice_scope
     *
     * @effects per_report_notifies_every_report
     *
     * @return void 반환값 없음
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
     * per_case 범위에서도 케이스 재활성화 후 첫 신고는 새 사이클의 첫 신고라 알림이 갑니다.
     *
     * 재활성화(last_activated_at 갱신) 이후 첫 신고 로그는 활성 사이클 내 1번째이므로
     * per_case 조건에서도 알림이 발송되어야 합니다.
     *
     * @scenario case=notice_scope
     *
     * @effects per_case_notifies_again_after_case_reactivation
     *
     * @return void 반환값 없음
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
     * 알림 리스너의 중복 처리 키를 초기화합니다 (per_report/per_case 재발송 시나리오용).
     *
     * 현 설계에는 per-request 내부 dedup 상태가 존재하지 않으므로 no-op.
     * 과거 어댑터에서 static 상태를 사용했을 때의 테스트 헬퍼 잔재로 호환성 보장용.
     *
     * @return void 반환값 없음
     */
    private function resetListenerProcessedKeys(): void
    {
        // no-op: NotificationHookListener 는 상태 없이 매 훅 호출마다 정의를 재평가함
    }

    /**
     * report_policy.notify_author_on_report_action 설정(피신고자 처리 알림)을 config 로 주입합니다.
     *
     * @param  bool  $enabled  알림 발송 여부
     * @return void 반환값 없음
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
     * report_policy.notify_admin_on_report 설정(신고 접수 관리자 알림)을 주입합니다.
     *
     * 자동 블라인드가 끼어들지 않게 auto_hide_threshold 를 충분히 높게 저장한 뒤 config 를 주입한다 —
     * 설정 저장이 config 미러를 다시 채우므로 순서를 바꾸면 주입이 덮인다.
     *
     * @param  bool  $enabled  알림 발송 여부
     * @param  string  $scope  발송 범위 ('per_case' | 'per_report')
     * @return void 반환값 없음
     */
    private function setAdminReportPolicy(bool $enabled, string $scope = 'per_case'): void
    {
        // checkAndApplyAutoHide()는 BoardSettingsService(파일)에서 읽으므로
        // auto_hide_threshold를 충분히 높게 설정하여 테스트 중 자동 블라인드 방지
        //
        // 순서 주의: 설정 저장은 config 미러를 저장본 기준으로 다시 채운다. 그래서 아래
        // config() 주입을 먼저 하면 이 호출이 그 주입을 덮어써 정책이 기본값(활성)으로 돌아간다.
        /** @var BoardSettingsService $settingsService */
        $settingsService = app(BoardSettingsService::class);
        $settingsService->setSetting('report_policy.auto_hide_threshold', 100);
        $settingsService->clearCache();

        // g7_module_settings()는 Config에서 읽으므로 config 주입으로 알림 정책 제어
        config([
            'g7_settings.modules.sirsoft-board.report_policy' => [
                'notify_admin_on_report' => $enabled,
                'notify_admin_on_report_scope' => $scope,
                'notify_admin_on_report_channels' => ['mail'],
            ],
        ]);
    }

    /**
     * 테스트용 게시글을 만듭니다.
     *
     * @param  int  $userId  작성자 ID
     * @param  string  $status  게시글 상태
     * @param  string  $triggerType  처리 유형
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
     * 테스트용 댓글을 만듭니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  int  $userId  작성자 ID
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

    /**
     * module.php::getNotificationDefinitions() 를 SSoT 로 게시판 알림 정의를 동기화합니다.
     *
     * declarative getter 패턴 도입(beta.4) 이후 별도 Seeder 가 없으므로 테스트 setUp
     * 에서 직접 NotificationSyncHelper 를 호출해 알림 정의를 시드합니다.
     *
     * @return void 반환값 없음
     */
    private function syncBoardNotificationDefinitions(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-board');
        if (! $module) {
            return;
        }

        $helper = app(NotificationSyncHelper::class);
        foreach ($module->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = 'sirsoft-board';

            $definition = $helper->syncDefinition($data);

            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }
    }
}
