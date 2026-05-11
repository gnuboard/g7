<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Repositories\ReportRepository;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * ReportRepository 단위 테스트
 */
class ReportRepositoryTest extends ModuleTestCase
{

    private ReportRepository $repository;
    private User $user;
    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        // Telescope 비활성화 (테스트 환경)
        config(['telescope.enabled' => false]);

        // Phase 8: DDL implicit commit으로 이전 테스트 데이터가 남아있을 수 있으므로 정리
        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();

        $this->repository = new ReportRepository;
        $this->user = User::factory()->create();
        $this->board = Board::firstOrCreate(
            ['slug' => 'report-repo-test'],
            [
                'name' => ['ko' => '신고 레포지토리 테스트 게시판'],
                'type' => 'list',
                'per_page' => 20,
                'per_page_mobile' => 10,
                'order_by' => 'created_at',
                'order_direction' => 'DESC',
                'secret_mode' => 'disabled',
                'use_comment' => true,
                'use_reply' => false,
                'use_file_upload' => false,
                'permissions' => [],
                'notify_admin_on_post' => false,
                'notify_author_on_comment' => false,
            ]
        );
    }

    /**
     * paginate() 메서드 테스트
     */
    public function test_paginate_returns_paginated_reports(): void
    {
        // Given: 15개 신고 생성
        Report::factory()->count(15)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

        ]);

        // When: per_page=10으로 페이지네이션
        $result = $this->repository->paginate([], 10);

        // Then: 10개 조회, 2페이지 존재
        $this->assertCount(10, $result->items());
        $this->assertEquals(15, $result->total());
        $this->assertEquals(10, $result->perPage());
    }

    /**
     * paginate() 메서드 - perPage 최소값 제한
     */
    public function test_paginate_enforces_minimum_per_page(): void
    {
        // Given
        Report::factory()->count(20)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

        ]);

        // When: per_page=5 요청 (최소값은 Repository에서 처리하지 않음, 그대로 5)
        $result = $this->repository->paginate([], 5);

        // Then: 5개씩 페이지네이션
        $this->assertEquals(5, $result->perPage());
    }

    /**
     * paginate() 메서드 - perPage 최대값 제한
     */
    public function test_paginate_enforces_maximum_per_page(): void
    {
        // Given
        Report::factory()->count(30)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

        ]);

        // When: per_page=25 요청 (최대값은 Repository에서 처리하지 않음, 그대로 25)
        $result = $this->repository->paginate([], 25);

        // Then: 25개씩 페이지네이션
        $this->assertEquals(25, $result->perPage());
    }

    /**
     * paginate() - 상태 필터링
     */
    public function test_paginate_filters_by_status(): void
    {
        // Given
        Report::factory()->count(3)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

            'status' => ReportStatus::Pending,
        ]);

        Report::factory()->count(2)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

            'status' => ReportStatus::Review,
        ]);

        // When
        $result = $this->repository->paginate(['status' => 'pending'], 15);

        // Then: pending 상태만 조회됨
        $this->assertEquals(3, $result->total());
    }

    /**
     * paginate() - 타입 필터링
     */
    public function test_paginate_filters_by_target_type(): void
    {
        // Given
        Report::factory()->count(3)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

            'target_type' => 'post',
        ]);

        Report::factory()->count(2)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

            'target_type' => 'comment',
        ]);

        // When
        $result = $this->repository->paginate(['type' => 'post'], 15);

        // Then
        $this->assertEquals(3, $result->total());
    }

    /**
     * paginate() - 검색 (게시판명)
     */
    public function test_paginate_searches_by_board_name(): void
    {
        // Given
        $anotherBoard = Board::firstOrCreate(
            ['slug' => 'report-repo-another'],
            [
                'name' => ['ko' => '다른 게시판'],
                'type' => 'list',
                'per_page' => 20,
                'per_page_mobile' => 10,
                'order_by' => 'created_at',
                'order_direction' => 'DESC',
                'secret_mode' => 'disabled',
                'use_comment' => true,
                'use_reply' => false,
                'use_file_upload' => false,
                'permissions' => [],
                'notify_admin_on_post' => false,
                'notify_author_on_comment' => false,
            ]
        );

        // board_name 검색은 board 관계의 JSON name 컬럼 기반 ($this->board name: '신고 레포지토리 테스트 게시판')
        Report::factory()->count(2)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
        ]);

        // anotherBoard name: '다른 게시판' → '테스트' 검색에 미히트
        Report::factory()->count(1)->create([
            'board_id' => $anotherBoard->id,
            'author_id' => $this->user->id,
        ]);

        // MySQL InnoDB FULLTEXT 내부 cache 강제 플러시 — Fresh 삽입 직후 ngram 토큰이
        // 아직 인덱싱되지 않아 LIKE 가 아닌 FULLTEXT 검색은 0 건 반환. ALTER TABLE ENGINE=InnoDB 로
        // flush 강제 (이커머스 ProductSearchIntegrationTest 와 동일한 회피책).
        $boardsTable = DB::connection()->getTablePrefix().(new Board)->getTable();
        DB::statement("ALTER TABLE `{$boardsTable}` ENGINE=InnoDB");

        // When
        $result = $this->repository->paginate([
            'search_field' => 'board_name',
            'search' => '테스트',
        ], 15);

        // Then
        $this->assertEquals(2, $result->total());
    }

    /**
     * create() 메서드 테스트
     */
    public function test_create_saves_report_with_snapshot(): void
    {
        // Given: 1케이스 구조 — boards_reports에는 케이스 정보만, snapshot은 boards_report_logs에 저장
        $caseData = [
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => 1,
            'author_id' => $this->user->id,
            'status' => ReportStatus::Pending,
        ];

        // When: 케이스 생성
        $report = $this->repository->create($caseData);

        // Then: 케이스 저장 확인
        $report->refresh();
        $this->assertEquals($this->board->id, $report->board_id);
        $this->assertEquals('post', $report->target_type->value);
        $this->assertEquals(ReportStatus::Pending, $report->status);

        // snapshot은 createLog로 별도 저장
        $log = $this->repository->createLog([
            'report_id' => $report->id,
            'reporter_id' => $this->user->id,
            'snapshot' => [
                'board_name' => '테스트 게시판',
                'title' => '테스트 제목',
                'content' => '테스트 내용',
                'content_mode' => 'text',
                'author_name' => '작성자',
            ],
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '스팸입니다.',
        ]);

        $this->assertEquals('테스트 게시판', $log->snapshot['board_name']);
        $this->assertEquals('테스트 제목', $log->snapshot['title']);
    }

    /**
     * update() 메서드 테스트
     */
    public function test_update_modifies_report(): void
    {
        // Given
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

            'status' => ReportStatus::Pending,
        ]);

        // When
        $updated = $this->repository->update($report->id, [
            'status' => ReportStatus::Review,
            'processed_by' => $this->user->id,
        ]);

        // Then
        $this->assertEquals(ReportStatus::Review, $updated->status);
        $this->assertEquals($this->user->id, $updated->processed_by);
    }

    /**
     * bulkUpdateStatus() 메서드 테스트
     */
    public function test_bulk_update_status_modifies_multiple_reports(): void
    {
        // Given
        $reports = Report::factory()->count(3)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

            'status' => ReportStatus::Pending,
        ]);

        $ids = $reports->pluck('id')->toArray();

        // When
        $this->repository->bulkUpdateStatus($ids, [
            'status' => ReportStatus::Rejected,
            'processed_by' => $this->user->id,
        ]);

        // Then
        foreach ($ids as $id) {
            $this->assertDatabaseHas('boards_reports', [
                'id' => $id,
                'status' => 'rejected',
            ]);
        }
    }

    /**
     * delete() 메서드 - 소프트 삭제
     */
    public function test_delete_soft_deletes_report(): void
    {
        // Given
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

        ]);

        // When
        $this->repository->delete($report->id);

        // Then
        $this->assertSoftDeleted('boards_reports', [
            'id' => $report->id,
        ]);
    }

    /**
     * countTodayReportsByUser() — 오늘 신고 건수 조회
     */
    public function test_count_today_reports_by_user(): void
    {
        // Given: 사용자가 오늘 3건 신고 (1케이스 구조: logs에 reporter_id 기록)
        $reports = Report::factory()->count(3)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
        ]);
        foreach ($reports as $report) {
            ReportLog::factory()->create([
                'report_id' => $report->id,
                'reporter_id' => $this->user->id,
            ]);
        }

        // 다른 사용자의 신고 2건
        $otherUser = User::factory()->create();
        $otherReports = Report::factory()->count(2)->create([
            'board_id' => $this->board->id,
            'author_id' => $otherUser->id,
        ]);
        foreach ($otherReports as $report) {
            ReportLog::factory()->create([
                'report_id' => $report->id,
                'reporter_id' => $otherUser->id,
            ]);
        }

        // When
        $count = $this->repository->countTodayReportsByUser($this->user->id);

        // Then: 본인 신고만 카운트
        $this->assertEquals(3, $count);
    }

    /**
     * countRejectedReportsByUser() — 반려 건수 조회
     */
    public function test_count_rejected_reports_by_user(): void
    {
        // Given: 사용자가 반려 케이스 4건에 신고 (logs 기준, report.status=rejected + processed_at 기준)
        $rejectedReports = Report::factory()->count(4)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
            'status' => ReportStatus::Rejected,
            'processed_at' => now(),
        ]);
        foreach ($rejectedReports as $report) {
            ReportLog::factory()->create([
                'report_id' => $report->id,
                'reporter_id' => $this->user->id,
            ]);
        }

        // 대기 중 케이스 2건 (반려 아님 → 카운트 제외)
        $pendingReports = Report::factory()->count(2)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
            'status' => ReportStatus::Pending,
        ]);
        foreach ($pendingReports as $report) {
            ReportLog::factory()->create([
                'report_id' => $report->id,
                'reporter_id' => $this->user->id,
            ]);
        }

        // When: 30일 이내 반려 건수
        $count = $this->repository->countRejectedReportsByUser($this->user->id, 30);

        // Then
        $this->assertEquals(4, $count);
    }

    /**
     * countRejectedReportsByUser() — 기간 외 반려는 미포함
     */
    public function test_count_rejected_reports_excludes_old_records(): void
    {
        // Given: 60일 전 반려 케이스 2건 (processed_at 기준 → 기간 밖)
        $oldReports = Report::factory()->count(2)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
            'status' => ReportStatus::Rejected,
            'processed_at' => now()->subDays(60),
        ]);
        foreach ($oldReports as $report) {
            ReportLog::factory()->create([
                'report_id' => $report->id,
                'reporter_id' => $this->user->id,
            ]);
        }

        // 최근 반려 케이스 1건 (processed_at = now → 기간 내)
        $recentReport = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
            'status' => ReportStatus::Rejected,
            'processed_at' => now(),
        ]);
        ReportLog::factory()->create([
            'report_id' => $recentReport->id,
            'reporter_id' => $this->user->id,
        ]);

        // When: 30일 이내만 조회
        $count = $this->repository->countRejectedReportsByUser($this->user->id, 30);

        // Then: 최근 1건만
        $this->assertEquals(1, $count);
    }

    /**
     * forceDelete() 메서드 - 영구 삭제
     */
    public function test_force_delete_permanently_removes_report(): void
    {
        // Given
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

        ]);

        // When
        $this->repository->forceDelete($report->id);

        // Then
        $this->assertDatabaseMissing('boards_reports', [
            'id' => $report->id,
        ]);
    }

    /**
     * hasUserReported() — 신고한 경우 true 반환
     */
    public function test_has_user_reported_returns_true_when_already_reported(): void
    {
        // Given: 사용자가 게시글을 신고한 케이스 + 로그 생성
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => 999,
            'author_id' => $this->user->id,
        ]);
        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->user->id,
        ]);

        // When
        $result = $this->repository->hasUserReported($this->user->id, $this->board->id, 'post', 999);

        // Then
        $this->assertTrue($result);
    }

    /**
     * hasUserReported() — 신고하지 않은 경우 false 반환
     */
    public function test_has_user_reported_returns_false_when_not_reported(): void
    {
        // Given: 다른 사용자가 신고한 케이스만 존재
        $otherUser = User::factory()->create();
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => 998,
            'author_id' => $otherUser->id,
        ]);
        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $otherUser->id,
        ]);

        // When: $this->user는 신고한 적 없음
        $result = $this->repository->hasUserReported($this->user->id, $this->board->id, 'post', 998);

        // Then
        $this->assertFalse($result);
    }

    /**
     * hasUserReported() — 다른 대상(target_id)은 false 반환
     */
    public function test_has_user_reported_returns_false_for_different_target(): void
    {
        // Given: target_id=997 신고
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => 997,
            'author_id' => $this->user->id,
        ]);
        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->user->id,
        ]);

        // When: target_id=996 조회
        $result = $this->repository->hasUserReported($this->user->id, $this->board->id, 'post', 996);

        // Then
        $this->assertFalse($result);
    }

    /**
     * getStatistics() 메서드 테스트
     */
    public function test_get_statistics_returns_counts(): void
    {
        // Given
        Report::factory()->count(3)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

            'status' => ReportStatus::Pending,
        ]);

        Report::factory()->count(2)->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,

            'status' => ReportStatus::Review,
        ]);

        // When
        $stats = $this->repository->getStatistics([]);

        // Then
        $this->assertEquals(3, $stats['by_status']['pending']);
        $this->assertEquals(2, $stats['by_status']['review']);
        $this->assertEquals(5, $stats['total']);
    }
}
