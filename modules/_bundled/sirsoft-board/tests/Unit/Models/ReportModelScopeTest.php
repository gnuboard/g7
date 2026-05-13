<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Models;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\ReportType;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * Report 모델 스코프 단위 테스트
 *
 * 검증 목적:
 * - scopeByStatus: Enum 값으로 필터링
 * - scopeByStatus: 문자열로 필터링
 * - scopeByType: post/comment 타입 필터링
 * - scopeByBoard: 특정 게시판 신고만 조회
 * - scopeByDateRange: 날짜 범위 필터링
 * - 복합 스코프: 여러 조건 체이닝
 *
 * @group board
 * @group unit
 * @group model
 */
class ReportModelScopeTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'report-scope-test';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '신고 스코프 테스트 게시판', 'en' => 'Report Scope Test Board'],
            'is_active' => true,
            'use_report' => true,
        ];
    }

    private function createReport(array $attributes = []): Report
    {
        $postId = $this->createTestPost();

        $defaults = [
            'board_id' => $this->board->id,
            'target_type' => ReportType::Post,
            'target_id' => $postId,
            'reporter_id' => null,
            'reason_type' => 'spam',
            'reason_detail' => '테스트 신고',
            'status' => ReportStatus::Pending,
            'ip_address' => '127.0.0.1',
        ];

        return Report::create(array_merge($defaults, $attributes));
    }

    // ==========================================
    // scopeByStatus
    // ==========================================

    /**
     * scopeByStatus: Enum 인스턴스로 필터링
     */
    public function test_scope_by_status_with_enum(): void
    {
        $this->createReport(['status' => ReportStatus::Pending]);
        $this->createReport(['status' => ReportStatus::Review]);

        $results = Report::byStatus(ReportStatus::Pending)->get();

        $this->assertTrue($results->every(fn ($r) => $r->status === ReportStatus::Pending));
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    /**
     * scopeByStatus: 문자열로 필터링
     */
    public function test_scope_by_status_with_string(): void
    {
        $this->createReport(['status' => ReportStatus::Rejected]);
        $this->createReport(['status' => ReportStatus::Pending]);

        $results = Report::byStatus('rejected')
            ->where('board_id', $this->board->id)
            ->get();

        $this->assertTrue($results->every(fn ($r) => $r->status === ReportStatus::Rejected));
    }

    /**
     * scopeByStatus: 해당 상태 없으면 빈 컬렉션
     */
    public function test_scope_by_status_returns_empty_when_no_match(): void
    {
        $this->createReport(['status' => ReportStatus::Pending]);

        $results = Report::byStatus(ReportStatus::Suspended)
            ->where('board_id', $this->board->id)
            ->get();

        $this->assertCount(0, $results);
    }

    // ==========================================
    // scopeByType
    // ==========================================

    /**
     * scopeByType: Post 타입만 조회
     */
    public function test_scope_by_type_post(): void
    {
        $this->createReport(['target_type' => ReportType::Post]);

        // Comment 타입 신고 생성
        $commentId = $this->createTestComment($this->createTestPost());
        Report::create([
            'board_id' => $this->board->id,
            'target_type' => ReportType::Comment,
            'target_id' => $commentId,
            'reporter_id' => null,
            'reason_type' => 'spam',
            'reason_detail' => '댓글 신고',
            'status' => ReportStatus::Pending,
            'ip_address' => '127.0.0.1',
        ]);

        $results = Report::byType(ReportType::Post)
            ->where('board_id', $this->board->id)
            ->get();

        $this->assertTrue($results->every(fn ($r) => $r->target_type === ReportType::Post));
    }

    /**
     * scopeByType: 문자열로도 필터링 가능
     */
    public function test_scope_by_type_with_string(): void
    {
        $this->createReport(['target_type' => ReportType::Post]);

        $results = Report::byType('post')
            ->where('board_id', $this->board->id)
            ->get();

        $this->assertGreaterThanOrEqual(1, $results->count());
        $this->assertTrue($results->every(fn ($r) => $r->target_type === ReportType::Post));
    }

    // ==========================================
    // scopeByBoard
    // ==========================================

    /**
     * scopeByBoard: 특정 게시판 신고만 반환
     */
    public function test_scope_by_board_filters_correctly(): void
    {
        $this->createReport(['board_id' => $this->board->id]);

        $results = Report::byBoard($this->board->id)->get();

        $this->assertTrue($results->every(fn ($r) => $r->board_id === $this->board->id));
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    /**
     * scopeByBoard: 존재하지 않는 board_id → 빈 컬렉션
     */
    public function test_scope_by_board_returns_empty_for_nonexistent_board(): void
    {
        $results = Report::byBoard(99999999)->get();
        $this->assertCount(0, $results);
    }

    // ==========================================
    // scopeByDateRange
    // ==========================================

    /**
     * scopeByDateRange: 범위 내 신고만 반환
     */
    public function test_scope_by_date_range_includes_records_in_range(): void
    {
        $report = $this->createReport();
        DB::table('boards_reports')
            ->where('id', $report->id)
            ->update(['created_at' => '2025-01-15 12:00:00']);

        $results = Report::byDateRange('2025-01-01', '2025-01-31')
            ->where('board_id', $this->board->id)
            ->get();

        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    /**
     * scopeByDateRange: 범위 밖 신고는 제외
     */
    public function test_scope_by_date_range_excludes_records_outside_range(): void
    {
        $report = $this->createReport();
        DB::table('boards_reports')
            ->where('id', $report->id)
            ->update(['created_at' => '2024-06-01 12:00:00']);

        $results = Report::byDateRange('2025-01-01', '2025-12-31')
            ->where('board_id', $this->board->id)
            ->get();

        $this->assertCount(0, $results);
    }

    // ==========================================
    // 복합 스코프 체이닝
    // ==========================================

    /**
     * 복합 스코프: byBoard + byStatus 체이닝
     */
    public function test_chaining_by_board_and_by_status(): void
    {
        $this->createReport(['status' => ReportStatus::Pending]);
        $this->createReport(['status' => ReportStatus::Review]);

        $results = Report::byBoard($this->board->id)
            ->byStatus(ReportStatus::Pending)
            ->get();

        $this->assertTrue($results->every(
            fn ($r) => $r->board_id === $this->board->id && $r->status === ReportStatus::Pending
        ));
    }
}
