<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Services\ReportService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * ReportService 단위 테스트
 */
class ReportServiceTest extends ModuleTestCase
{

    private ReportService $service;

    /** @var \Mockery\MockInterface&ReportRepositoryInterface */
    private $reportRepository;

    private PostRepositoryInterface $postRepository;

    private CommentRepositoryInterface $commentRepository;

    private BoardRepositoryInterface $boardRepository;

    private UserRepositoryInterface $userRepository;

    private User $user;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        // Telescope 비활성화 (테스트 환경)
        config(['telescope.enabled' => false]);

        // Mock Repository 생성 (ReportRepository만 Mock, 나머지는 실제 Repository 사용)
        $this->reportRepository = Mockery::mock(ReportRepositoryInterface::class);
        $this->postRepository = app(PostRepositoryInterface::class);
        $this->commentRepository = app(CommentRepositoryInterface::class);
        $this->boardRepository = app(BoardRepositoryInterface::class);
        $this->userRepository = app(UserRepositoryInterface::class);

        // ReportService 생성 (모든 의존성 주입)
        $this->service = new ReportService(
            $this->reportRepository,
            $this->postRepository,
            $this->commentRepository,
            $this->boardRepository,
            $this->userRepository,
            app(\App\Contracts\Extension\CacheInterface::class)
        );

        $this->user = User::factory()->create();
        $this->board = Board::firstOrCreate(
            ['slug' => 'report-svc-test'],
            [
                'name' => ['ko' => '신고 서비스 테스트 게시판'],
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * createReport() - 훅 실행 테스트
     */
    public function test_create_report_executes_hooks(): void
    {
        // Given
        $postId = $this->createTestPost();

        $hookCalled = false;
        HookManager::addAction('sirsoft-board.report.before_create', function () use (&$hookCalled) {
            $hookCalled = true;
        });

        // Repository Mock 설정 (1케이스 구조: findOrCreateCase + createLog)
        // board_id 없는 Report는 checkAndApplyAutoHide에서 early return됨
        $report = new Report;
        $this->reportRepository->shouldReceive('findOrCreateCase')
            ->once()
            ->andReturn(['report' => $report, 'created' => true]);

        $this->reportRepository->shouldReceive('createLog')
            ->once()
            ->andReturn(new ReportLog);

        // createLog 이후 countActiveReportsByTarget 항상 호출됨
        $this->reportRepository->shouldReceive('countActiveReportsByTarget')
            ->once()
            ->andReturn(1);

        $data = [
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->user->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '스팸입니다.',
        ];

        // When
        $this->service->createReport($data);

        // Then
        $this->assertTrue($hookCalled, 'before_create hook should be called');
    }

    /**
     * updateReportStatus() 메서드 테스트
     */
    public function test_update_report_status_changes_status(): void
    {
        // Given
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
            'status' => ReportStatus::Pending,
        ]);

        // Repository Mock 설정 (status 변경 시 findOrFail이 2번 호출됨: 147행, 190행)
        $this->reportRepository->shouldReceive('findOrFail')
            ->with($report->id)
            ->twice()
            ->andReturn($report);

        $this->reportRepository->shouldReceive('update')
            ->once()
            ->with($report->id, Mockery::on(function ($updateData) {
                // process_note는 이력에 포함 후 unset되므로 update 데이터에 없음
                return $updateData['status'] === ReportStatus::Review->value
                    && array_key_exists('processed_by', $updateData)
                    && array_key_exists('processed_at', $updateData)
                    && array_key_exists('process_histories', $updateData)
                    && ! array_key_exists('process_note', $updateData);
            }))
            ->andReturn($report);

        $data = [
            'status' => ReportStatus::Review->value,
            'process_note' => '검토 중입니다.',
        ];

        // When
        $result = $this->service->updateReportStatus($report->id, $data);

        // Then: 반환값 검증 + Mockery가 tearDown에서 update 호출 횟수 + 인수를 자동 검증
        $this->assertInstanceOf(Report::class, $result);
    }

    /**
     * deleteReport() 메서드 테스트
     */
    public function test_delete_report_soft_deletes(): void
    {
        // Given
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
        ]);

        // Repository Mock 설정
        $this->reportRepository->shouldReceive('findOrFail')
            ->with($report->id)
            ->once()
            ->andReturn($report);

        $this->reportRepository->shouldReceive('delete')
            ->with($report->id)
            ->once()
            ->andReturn(true);

        // When
        $result = $this->service->deleteReport($report->id);

        // Then
        $this->assertTrue($result);
    }

    /**
     * updateReportStatus() - 게시글 상태 동기화 테스트 (suspended)
     */
    public function test_update_report_status_blinds_post_content(): void
    {
        // Given
        $postId = $this->createTestPost();
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Pending,
        ]);

        // status 변경 시 findOrFail이 2번 호출됨 (board 관계 로드 필요)
        $freshReport = $report->fresh();
        $freshReport->load('board');

        $this->reportRepository->shouldReceive('findOrFail')
            ->with($report->id)
            ->twice()
            ->andReturn($freshReport);

        $this->reportRepository->shouldReceive('update')
            ->once()
            ->andReturnUsing(function ($id, $data) use ($report) {
                $report->status = $data['status'];

                return $report;
            });

        $data = [
            'status' => ReportStatus::Suspended->value,
            'process_note' => '블라인드 처리합니다.',
        ];

        // When
        $this->service->updateReportStatus($report->id, $data);

        // Then: 게시글이 blinded 상태로 변경되었는지 확인 (게시중단은 삭제가 아니므로 deleted_at은 null)
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('blinded', $post->status);
        $this->assertEquals('report', $post->trigger_type);
        $this->assertNull($post->deleted_at);
    }

    /**
     * updateReportStatus() - 게시글 상태 동기화 테스트 (deleted)
     */
    public function test_update_report_status_deletes_post_content(): void
    {
        // Given
        $postId = $this->createTestPost();
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Pending,
        ]);

        // status 변경 시 findOrFail이 2번 호출됨 (board 관계 로드 필요)
        $freshReport = $report->fresh();
        $freshReport->load('board');

        $this->reportRepository->shouldReceive('findOrFail')
            ->with($report->id)
            ->twice()
            ->andReturn($freshReport);

        $this->reportRepository->shouldReceive('update')
            ->once()
            ->andReturnUsing(function ($id, $data) use ($report) {
                $report->status = $data['status'];

                return $report;
            });

        $data = [
            'status' => ReportStatus::Deleted->value,
            'process_note' => '영구 삭제합니다.',
        ];

        // When
        $this->service->updateReportStatus($report->id, $data);

        // Then: 게시글이 deleted 상태로 변경되었는지 확인
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('deleted', $post->status);
        $this->assertEquals('report', $post->trigger_type);
        $this->assertNotNull($post->deleted_at);
    }

    /**
     * updateReportStatus() - 게시글 복구 테스트 (suspended -> rejected)
     */
    public function test_update_report_status_restores_post_content(): void
    {
        // Given
        $postId = $this->createTestPost();

        // 먼저 게시글을 블라인드 상태로 만듦 (게시중단은 삭제가 아니므로 deleted_at은 null)
        DB::table('board_posts')
            ->where('id', $postId)
            ->update(['status' => 'blinded', 'trigger_type' => 'report']);

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Suspended,
        ]);

        // status 변경 시 findOrFail이 2번 호출됨 (board 관계 로드 필요)
        $freshReport = $report->fresh();
        $freshReport->load('board');

        $this->reportRepository->shouldReceive('findOrFail')
            ->with($report->id)
            ->twice()
            ->andReturn($freshReport);

        $this->reportRepository->shouldReceive('update')
            ->once()
            ->andReturnUsing(function ($id, $data) use ($report) {
                $report->status = $data['status'];

                return $report;
            });

        $data = [
            'status' => ReportStatus::Rejected->value,
            'process_note' => '신고 반려합니다.',
        ];

        // When
        $this->service->updateReportStatus($report->id, $data);

        // Then: 게시글이 published 상태로 복구되었는지 확인
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('published', $post->status);
        $this->assertEquals('report', $post->trigger_type);
        $this->assertNull($post->deleted_at);
    }

    /**
     * 댓글 신고 시 reportable에 상위 게시글 제목이 포함되는지 테스트
     */
    public function test_comment_report_reportable_includes_post_title(): void
    {
        // Given: 게시글 및 댓글 생성

        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        // 댓글에 대한 신고 생성 (1케이스 구조: snapshot은 ReportLog에 저장)
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'comment',
            'target_id' => $commentId,
            'author_id' => $this->user->id,
        ]);

        // snapshot은 ReportLog에 저장 (title은 null - 댓글에는 제목이 없으므로)
        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->user->id,
            'snapshot' => [
                'board_name' => $this->board->name,
                'title' => null,
                'content' => '테스트 댓글 내용',
                'content_mode' => 'text',
                'author_name' => $this->user->name,
            ],
        ]);

        // When: Service 메서드로 reportable 데이터 조회
        $reportable = $this->service->buildReportableData($report);

        // Then: post 키에 상위 게시글 정보가 포함되어야 함
        $this->assertNotNull($reportable['post'], '댓글 신고의 reportable에 post 데이터가 포함되어야 합니다');
        $this->assertEquals($postId, $reportable['post']['id']);
        $this->assertEquals('테스트 게시글', $reportable['post']['title']);

        // title은 null (댓글에는 제목이 없으므로)
        $this->assertNull($reportable['title']);

        // ReportResource에서 사용하는 fallback 로직 검증:
        // $reportable['title'] ?? $reportable['post']['title'] ?? ($this->snapshot['title'] ?? null)
        $firstLog = $report->logs()->first();
        $boardTitle = $reportable['title'] ?? $reportable['post']['title'] ?? ($firstLog?->snapshot['title'] ?? null);
        $this->assertEquals('테스트 게시글', $boardTitle, '댓글 신고 시 board.title에 상위 게시글 제목이 표시되어야 합니다');
    }

    /**
     * 게시글 신고 시 reportable의 title이 정상적으로 반환되는지 테스트
     */
    public function test_post_report_reportable_includes_post_title_directly(): void
    {
        // Given: 게시글 및 게시글 신고 생성
        $postId = $this->createTestPost();

        // 1케이스 구조: snapshot은 ReportLog에 저장
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'author_id' => $this->user->id,
        ]);

        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->user->id,
            'snapshot' => [
                'board_name' => $this->board->name,
                'title' => '테스트 게시글',
                'content' => '테스트 내용',
                'content_mode' => 'text',
                'author_name' => $this->user->name,
            ],
        ]);

        // When: Service 메서드로 reportable 데이터 조회
        $reportable = $this->service->buildReportableData($report);

        // Then: title에 게시글 제목이 직접 포함
        $this->assertEquals('테스트 게시글', $reportable['title']);
        $this->assertNull($reportable['post'], '게시글 신고에는 post 데이터가 없어야 합니다');

        // ReportResource fallback 로직 검증
        $firstLog = $report->logs()->first();
        $boardTitle = $reportable['title'] ?? $reportable['post']['title'] ?? ($firstLog?->snapshot['title'] ?? null);
        $this->assertEquals('테스트 게시글', $boardTitle);
    }

    /**
     * 테스트용 게시글 생성 헬퍼
     */
    private function createTestPost(): int
    {
        return DB::table('board_posts')->insertGetId([
            'board_id' => $this->board->id,
            'title' => '테스트 게시글',
            'content' => '테스트 내용',
            'user_id' => $this->user->id,
            'author_name' => $this->user->name,
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
     * getStatusCountsSummary() - rejected 시 신고자 여러 명인 blinded 케이스도 restorable_blind_count에 포함
     *
     * 수정 전: pending/review 조건으로 카운트 → 실제 복구 조건(suspended/deleted)과 불일치
     * 수정 후: suspended/deleted 케이스 + blinded 게시글 → 복구 대상으로 카운트
     */
    public function test_restorable_blind_count_includes_cases_with_multiple_reporters(): void
    {
        // Given: blinded 게시글에 대한 suspended 케이스 (반려 시 복구 대상)
        $postId = $this->createTestPost();
        DB::table('board_posts')->where('id', $postId)->update(['status' => 'blinded']);

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'author_id' => $this->user->id,
            'status' => ReportStatus::Suspended,
        ]);

        $this->reportRepository->shouldReceive('findOrFail')
            ->with($report->id)
            ->andReturn($report->fresh()->load('board'));

        $statusCounts = ['suspended' => ['count' => 1, 'label' => '게시중단']];

        // When
        $summary = $this->service->getStatusCountsSummary($statusCounts, [$report->id], 'rejected');

        // Then: blinded + suspended 케이스는 반려 시 복구 대상으로 카운트
        $this->assertGreaterThan(0, $summary['restorable_blind_count']);
    }

    /**
     * createReport() - 동일 대상 중복 신고 시 기존 케이스 재사용 (1케이스 구조)
     */
    public function test_create_report_reuses_existing_case_for_same_target(): void
    {
        // Given: 첫 번째 신고로 케이스 생성
        $postId = $this->createTestPost();

        $existingReport = new Report;
        $existingReport->id = 99;
        $existingReport->board_id = $this->board->id;
        $existingReport->target_type = \Modules\Sirsoft\Board\Enums\ReportType::Post;
        $existingReport->target_id = $postId;
        $existingReport->status = \Modules\Sirsoft\Board\Enums\ReportStatus::Pending;
        $existingReport->process_histories = [];

        // 두 번째 신고 시: created=false (기존 케이스 반환)
        $this->reportRepository->shouldReceive('findOrCreateCase')
            ->once()
            ->andReturn(['report' => $existingReport, 'created' => false]);

        $this->reportRepository->shouldReceive('createLog')
            ->once()
            ->andReturn(new ReportLog);

        $this->reportRepository->shouldReceive('countActiveReportsByTarget')
            ->once()
            ->andReturn(2);

        $data = [
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->user->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '두 번째 신고',
        ];

        // When
        $result = $this->service->createReport($data);

        // Then: 동일 Report 인스턴스 반환 (새 케이스 미생성)
        $this->assertInstanceOf(Report::class, $result);
        $this->assertEquals(99, $result->id);
    }

    /**
     * createReport() - rejected 케이스에 재신고 시 pending 재활성화
     */
    public function test_create_report_reactivates_rejected_case(): void
    {
        // Given: rejected 상태의 기존 케이스
        $postId = $this->createTestPost();

        $rejectedReport = new Report;
        $rejectedReport->id = 100;
        $rejectedReport->board_id = $this->board->id;
        $rejectedReport->target_type = \Modules\Sirsoft\Board\Enums\ReportType::Post;
        $rejectedReport->target_id = $postId;
        $rejectedReport->status = \Modules\Sirsoft\Board\Enums\ReportStatus::Rejected;
        $rejectedReport->process_histories = [['action' => 'rejected']];

        // 재신고: created=false, status=Rejected → isReactivation=true
        $this->reportRepository->shouldReceive('findOrCreateCase')
            ->once()
            ->andReturn(['report' => $rejectedReport, 'created' => false]);

        $this->reportRepository->shouldReceive('createLog')
            ->once()
            ->andReturn(new ReportLog);

        $this->reportRepository->shouldReceive('countActiveReportsByTarget')
            ->once()
            ->andReturn(1);

        $data = [
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reporter_id' => $this->user->id,
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => '재신고',
        ];

        // When: 예외 없이 처리되어야 함
        $result = $this->service->createReport($data);

        $this->assertInstanceOf(Report::class, $result);
    }

    /**
     * updateReportStatus() - comment 신고 블라인드 처리 (suspended)
     */
    public function test_update_report_status_blinds_comment_content(): void
    {
        // Given
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->user->id,
            'target_type' => 'comment',
            'target_id' => $commentId,
            'status' => ReportStatus::Pending,
        ]);

        $freshReport = $report->fresh();
        $freshReport->load('board');

        $this->reportRepository->shouldReceive('findOrFail')
            ->with($report->id)
            ->twice()
            ->andReturn($freshReport);

        $this->reportRepository->shouldReceive('update')
            ->once()
            ->andReturnUsing(function ($id, $data) use ($report) {
                $report->status = $data['status'];

                return $report;
            });

        // When
        $this->service->updateReportStatus($report->id, [
            'status' => ReportStatus::Suspended->value,
            'process_note' => '댓글 블라인드',
        ]);

        // Then: 댓글이 blinded 상태로 변경
        $comment = DB::table('board_comments')->find($commentId);
        $this->assertEquals('blinded', $comment->status);
        $this->assertEquals('report', $comment->trigger_type);
    }

    /**
     * 테스트용 댓글 생성 헬퍼
     *
     * @param  int  $postId  게시글 ID
     * @return int 생성된 댓글 ID
     */
    private function createTestComment(int $postId): int
    {
        return DB::table('board_comments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'user_id' => $this->user->id,
            'author_name' => $this->user->name,
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
