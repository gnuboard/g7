<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 신고 남발 방지 Feature 통합 테스트
 *
 * StoreReportRequest의 withValidator()에서 적용되는
 * DailyReportLimitRule, RejectionLimitRule, CooldownRule의
 * API 레벨 422 응답을 검증합니다.
 */
class ReportAbusePreventionTest extends ModuleTestCase
{
    private User $reporter;

    private User $author;

    private Board $board;

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
        $this->reporter = $this->createUser();
        $this->author = $this->createUser();

        // 테스트 게시판 생성
        $this->board = Board::updateOrCreate(
            ['slug' => 'abuse-prevention-test'],
            [
                'name' => ['ko' => '남발 방지 테스트', 'en' => 'Abuse Prevention Test'],
                'slug' => 'abuse-prevention-test',
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

        // 쿨타임 캐시 초기화
        Cache::flush();

        // 기본 설정값 초기화
        $this->mockSettings = [
            'report_policy' => [
                'auto_hide_threshold' => 5,
                'auto_hide_target' => 'both',
                'daily_report_limit' => 10,
                'rejection_limit_count' => 5,
                'rejection_limit_days' => 30,
            ],
            'spam_security' => [
                'post_cooldown_seconds' => 0,
                'comment_cooldown_seconds' => 0,
                'report_cooldown_seconds' => 60,
                'view_count_cache_ttl' => 86400,
            ],
        ];
    }

    /**
     * BoardSettingsService를 mock으로 교체하고 테스트 설정값을 반환하도록 합니다.
     */
    private function mockBoardSettingsService(): void
    {
        $mock = $this->createMock(BoardSettingsService::class);
        $mock->method('getSettings')
            ->willReturnCallback(fn (string $category) => $this->mockSettings[$category] ?? []);

        $this->app->instance(BoardSettingsService::class, $mock);
    }

    // ==========================================
    // 일일 신고 횟수 제한 테스트
    // ==========================================

    /**
     * 일일 신고 횟수 미초과 시 정상 신고 가능
     */
    #[Test]
    public function can_report_when_daily_limit_not_exceeded(): void
    {
        // Given: daily_report_limit = 3, 쿨타임 비활성화
        $this->mockSettings['report_policy']['daily_report_limit'] = 3;
        $this->mockSettings['spam_security']['report_cooldown_seconds'] = 0;
        $this->mockBoardSettingsService();

        // 오늘 2건 신고 이력 생성
        $this->createReportsForToday(2);

        // When: 3번째 신고 시도
        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/reports",
                $this->validReportData()
            );

        // Then: 일일 제한 검증 통과 (422가 아님 — 대상 미존재 404 등)
        $this->assertNotEquals(422, $response->status());
    }

    /**
     * 일일 신고 횟수 초과 시 422 응답 반환
     */
    #[Test]
    public function returns_422_when_daily_limit_exceeded(): void
    {
        // Given: daily_report_limit = 3, 쿨타임 비활성화
        $this->mockSettings['report_policy']['daily_report_limit'] = 3;
        $this->mockSettings['spam_security']['report_cooldown_seconds'] = 0;
        $this->mockBoardSettingsService();

        // 오늘 3건 신고 이력 생성 (한도 도달)
        $this->createReportsForToday(3);

        // When: 4번째 신고 시도
        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/reports",
                $this->validReportData()
            );

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason_type']);
    }

    /**
     * daily_report_limit = 0이면 제한 없음
     */
    #[Test]
    public function no_daily_limit_when_set_to_zero(): void
    {
        // Given: daily_report_limit = 0 (무제한), 쿨타임 비활성화
        $this->mockSettings['report_policy']['daily_report_limit'] = 0;
        $this->mockSettings['spam_security']['report_cooldown_seconds'] = 0;
        $this->mockBoardSettingsService();

        // 오늘 100건 신고 이력 생성
        $this->createReportsForToday(100);

        // When: 101번째 신고 시도
        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/reports",
                $this->validReportData()
            );

        // Then: 일일 제한 검증 통과
        $this->assertNotEquals(422, $response->status());
    }

    // ==========================================
    // 반려 누적 제한 테스트
    // ==========================================

    /**
     * 반려 누적 초과 시 422 응답 반환
     */
    #[Test]
    public function returns_422_when_rejection_limit_exceeded(): void
    {
        // Given: rejection_limit_count = 2, rejection_limit_days = 30, 쿨타임 비활성화
        $this->mockSettings['report_policy']['rejection_limit_count'] = 2;
        $this->mockSettings['report_policy']['rejection_limit_days'] = 30;
        $this->mockSettings['spam_security']['report_cooldown_seconds'] = 0;
        $this->mockBoardSettingsService();

        // 30일 내 반려 2건 생성
        $this->createRejectedReports(2, now()->subDays(15));

        // When: 새 신고 시도
        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/reports",
                $this->validReportData()
            );

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason_type']);
    }

    /**
     * 반려 건이 기간 밖이면 제한 없음
     */
    #[Test]
    public function no_rejection_limit_when_outside_period(): void
    {
        // Given: rejection_limit_count = 2, rejection_limit_days = 30, 쿨타임 비활성화
        $this->mockSettings['report_policy']['rejection_limit_count'] = 2;
        $this->mockSettings['report_policy']['rejection_limit_days'] = 30;
        $this->mockSettings['spam_security']['report_cooldown_seconds'] = 0;
        $this->mockBoardSettingsService();

        // 31일 전 반려 2건 (기간 밖)
        $this->createRejectedReports(2, now()->subDays(31));

        // When: 새 신고 시도
        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/reports",
                $this->validReportData()
            );

        // Then: 반려 누적 검증 통과
        $this->assertNotEquals(422, $response->status());
    }

    /**
     * rejection_limit_count = 0이면 제한 없음
     */
    #[Test]
    public function no_rejection_limit_when_set_to_zero(): void
    {
        // Given: rejection_limit_count = 0 (무제한), 쿨타임 비활성화
        $this->mockSettings['report_policy']['rejection_limit_count'] = 0;
        $this->mockSettings['spam_security']['report_cooldown_seconds'] = 0;
        $this->mockBoardSettingsService();

        // 반려 10건 생성
        $this->createRejectedReports(10, now()->subDays(5));

        // When: 새 신고 시도
        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/reports",
                $this->validReportData()
            );

        // Then: 반려 누적 검증 통과
        $this->assertNotEquals(422, $response->status());
    }

    // ==========================================
    // 연속 신고 쿨타임 테스트
    // ==========================================

    /**
     * 쿨타임 내 신고 시 422 응답 반환
     */
    #[Test]
    public function returns_422_when_cooldown_active(): void
    {
        // Given: 쿨타임 60초 설정
        $this->mockSettings['spam_security']['report_cooldown_seconds'] = 60;
        $this->mockBoardSettingsService();

        // 쿨타임 캐시 활성화 (이전에 신고한 것처럼)
        $identifier = $this->reporter->id;
        Cache::put("report_cooldown_{$this->board->slug}_{$identifier}", true, 60);

        // When: 신고 시도
        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/reports",
                $this->validReportData()
            );

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason_type']);
    }

    /**
     * 쿨타임 만료 후 정상 신고 가능
     */
    #[Test]
    public function can_report_after_cooldown_expired(): void
    {
        // Given: 쿨타임 60초 설정, 캐시 비어있음 (만료됨)
        $this->mockSettings['spam_security']['report_cooldown_seconds'] = 60;
        $this->mockBoardSettingsService();
        Cache::flush();

        // When: 신고 시도
        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/reports",
                $this->validReportData()
            );

        // Then: 쿨타임 검증 통과 (422가 아님)
        $this->assertNotEquals(422, $response->status());
    }

    /**
     * report_cooldown_seconds = 0이면 쿨타임 비활성화
     */
    #[Test]
    public function no_cooldown_when_set_to_zero(): void
    {
        // Given: 쿨타임 비활성화
        $this->mockSettings['spam_security']['report_cooldown_seconds'] = 0;
        $this->mockBoardSettingsService();

        // When: 신고 시도
        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/reports",
                $this->validReportData()
            );

        // Then: 쿨타임 검증 통과
        $this->assertNotEquals(422, $response->status());
    }

    // ==========================================
    // 헬퍼 메서드
    // ==========================================

    /**
     * 유효한 신고 데이터 반환
     *
     * @return array<string, string>
     */
    private function validReportData(): array
    {
        return [
            'reason_type' => ReportReasonType::Spam->value,
            'reason_detail' => '테스트 신고 사유입니다.',
        ];
    }

    /**
     * 오늘 날짜 기준 신고 이력 생성
     *
     * countTodayReportsByUser()는 boards_report_logs.reporter_id + created_at 기준으로 집계하므로
     * boards_reports(케이스) + boards_report_logs(개별 신고 로그)를 모두 생성해야 합니다.
     *
     * @param  int  $count  생성할 건수
     */
    private function createReportsForToday(int $count): void
    {
        static $targetIdCounter = 10000;

        for ($i = 0; $i < $count; $i++) {
            $report = Report::factory()->create([
                'board_id' => $this->board->id,
                'target_id' => ++$targetIdCounter,
                'status' => ReportStatus::Pending,
                'last_reported_at' => now(),
            ]);

            ReportLog::factory()->create([
                'report_id' => $report->id,
                'reporter_id' => $this->reporter->id,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * 반려 상태 신고 이력 생성
     *
     * countRejectedReportsByUser()는 boards_report_logs.reporter_id + boards_reports.status/processed_at 기준으로
     * 집계하므로 boards_reports(반려 케이스) + boards_report_logs(개별 신고 로그)를 모두 생성해야 합니다.
     *
     * @param  int  $count  생성할 건수
     * @param  \Illuminate\Support\Carbon  $date  생성 날짜
     */
    private function createRejectedReports(int $count, $date): void
    {
        static $rejectedTargetIdCounter = 20000;

        for ($i = 0; $i < $count; $i++) {
            $report = Report::factory()->rejected()->create([
                'board_id' => $this->board->id,
                'target_id' => ++$rejectedTargetIdCounter,
                'processed_by' => $this->author->id,
                'processed_at' => $date,
                'last_reported_at' => $date,
            ]);

            ReportLog::factory()->create([
                'report_id' => $report->id,
                'reporter_id' => $this->reporter->id,
                'created_at' => $date,
            ]);
        }
    }
}
