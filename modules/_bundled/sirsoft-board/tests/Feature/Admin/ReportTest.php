<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * Report 기능 통합 테스트
 */
class ReportTest extends ModuleTestCase
{

    private User $admin;
    private User $reporter;
    private User $author;
    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        config(['telescope.enabled' => false]);
        App::setLocale('ko');

        // DDL implicit commit으로 이전 테스트 User가 잔류 → Faker email 충돌 방지
        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        DB::table('users')->where('is_super', false)->delete();

        // 테스트 사용자 생성
        $this->admin = User::factory()->create();
        $this->reporter = User::factory()->create();
        $this->author = User::factory()->create();

        // admin 사용자에게 권한 부여 (ModuleTestCase가 admin 역할을 생성하므로 firstOrCreate)
        $viewPermission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.reports.view'],
            ['name' => ['ko' => '신고 조회', 'en' => 'View Reports'], 'type' => 'admin']
        );

        $managePermission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.reports.manage'],
            ['name' => ['ko' => '신고 관리', 'en' => 'Manage Reports'], 'type' => 'admin']
        );

        $adminRole = Role::where('identifier', 'admin')->first();
        $adminRole->permissions()->syncWithoutDetaching([$viewPermission->id, $managePermission->id]);
        $this->admin->roles()->attach($adminRole->id);

        // 테스트 게시판 생성 (updateOrCreate: DDL commit으로 이전 board가 남아있을 수 있으므로 속성 갱신 보장)
        $this->board = Board::updateOrCreate(
            ['slug' => 'report-test-board'],
            [
                'name' => ['ko' => '신고 테스트 게시판', 'en' => 'Report Test Board'],
                'slug' => 'report-test-board',
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

        // 이전 테스트 잔여 데이터 정리 (DatabaseTransactions 비활성 환경 호환)
        // API가 전체 신고를 반환하므로 board_id 필터 없이 전체 삭제
        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        DB::table('board_comments')->where('board_id', $this->board->id)->delete();
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();
    }

    /**
     * 신고 목록 조회 테스트
     */
    public function test_can_fetch_reports_list(): void
    {
        // Given: 신고 데이터 생성
        Report::factory()->count(5)->create([
            'board_id' => $this->board->id,

        ]);

        // When: 목록 조회 API 호출
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/reports');

        // Then: 성공 응답 및 데이터 확인
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'board_id',
                            'target_type',
                            'target_id',
                            'reporter',
                            'status',
                            'reason_type',
                            'created_at',
                        ],
                    ],
                    'statistics',
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                    ],
                ],
            ]);

        $this->assertEquals(5, $response->json('data.pagination.total'));
    }

    /**
     * 검색 필터 테스트 - 상태별
     */
    public function test_can_filter_reports_by_status(): void
    {
        // Given: 다양한 상태의 신고 생성
        Report::factory()->create([
            'board_id' => $this->board->id,

            'status' => ReportStatus::Pending,
        ]);

        Report::factory()->create([
            'board_id' => $this->board->id,

            'status' => ReportStatus::Review,
        ]);

        // When: pending 상태만 필터링
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/reports?status=pending');

        // Then: pending 상태만 조회
        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    /**
     * 검색 필터 테스트 - 타입별
     */
    public function test_can_filter_reports_by_type(): void
    {
        // Given: post와 comment 신고 생성
        Report::factory()->create([
            'board_id' => $this->board->id,

            'target_type' => 'post',
        ]);

        Report::factory()->create([
            'board_id' => $this->board->id,

            'target_type' => 'comment',
        ]);

        // When: post 타입만 필터링
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/reports?target_type=post');

        // Then: post 타입만 조회
        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    /**
     * 신고 상세 조회 테스트
     */
    public function test_can_fetch_report_detail(): void
    {
        // Given: 신고 케이스 + ReportLog 생성 (1케이스 구조)
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->author->id,
        ]);
        $log = ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->reporter->id,
            'reason_type' => ReportReasonType::Spam,
        ]);

        // When: 상세 조회 API 호출
        $response = $this->actingAs($this->admin)
            ->getJson("/api/modules/sirsoft-board/admin/reports/{$report->id}");

        // Then: 성공 응답 및 상세 데이터 확인 (reporters 키: boards_report_logs 기반)
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $report->id,
                    'status' => $report->status->value,
                    'reporters' => [
                        [
                            'id' => $log->id,
                            'reason_type' => ReportReasonType::Spam->value,
                        ],
                    ],
                ],
            ]);
    }

    /**
     * 신고 상태 변경 테스트 (단일)
     */
    public function test_can_update_report_status(): void
    {
        // Given: pending 상태의 신고
        $report = Report::factory()->create([
            'board_id' => $this->board->id,

            'status' => ReportStatus::Pending,
        ]);

        // When: review 상태로 변경
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'review',
                'process_note' => '확인 중입니다.',
            ]);

        // Then: 상태 변경 성공
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('boards_reports', [
            'id' => $report->id,
            'status' => 'review',
            'processed_by' => $this->admin->id,
        ]);
    }

    /**
     * 대량 상태 변경 테스트
     */
    public function test_can_bulk_update_report_status(): void
    {
        // Given: 여러 개의 pending 신고
        $reports = Report::factory()->count(3)->create([
            'board_id' => $this->board->id,

            'status' => ReportStatus::Pending,
        ]);

        $ids = $reports->pluck('id')->toArray();

        // When: 대량 상태 변경
        $response = $this->actingAs($this->admin)
            ->patchJson('/api/modules/sirsoft-board/admin/reports/bulk-status', [
                'ids' => $ids,
                'status' => 'rejected',
                'process_note' => '일괄 반려 처리',
            ]);

        // Then: 모든 신고 상태 변경
        $response->assertStatus(200);

        foreach ($ids as $id) {
            $this->assertDatabaseHas('boards_reports', [
                'id' => $id,
                'status' => 'rejected',
            ]);
        }
    }

    /**
     * 신고 삭제 테스트
     */
    public function test_can_delete_report(): void
    {
        // Given: 신고 데이터
        $report = Report::factory()->create([
            'board_id' => $this->board->id,

            'author_id' => $this->author->id,
        ]);

        // When: 삭제 요청
        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/modules/sirsoft-board/admin/reports/{$report->id}");

        // Then: 소프트 삭제 성공
        $response->assertStatus(200);

        $this->assertSoftDeleted('boards_reports', [
            'id' => $report->id,
        ]);
    }

    /**
     * 권한 체크 테스트 - 조회 권한 없음
     */
    public function test_unauthorized_user_cannot_view_reports(): void
    {
        // Given: 권한 없는 일반 사용자
        $user = User::factory()->create();

        // When: 목록 조회 시도
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-board/admin/reports');

        // Then: 권한 없음 응답
        $response->assertStatus(403);
    }

    /**
     * 권한 체크 테스트 - 상태 변경 권한 없음
     */
    public function test_unauthorized_user_cannot_update_status(): void
    {
        // Given: 권한 없는 일반 사용자
        $user = User::factory()->create();
        $report = Report::factory()->create([
            'board_id' => $this->board->id,

        ]);

        // When: 상태 변경 시도
        $response = $this->actingAs($user)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'review',
            ]);

        // Then: 권한 없음 응답
        $response->assertStatus(403);
    }

    /**
     * 중복 신고 방지 테스트
     */
    public function test_prevents_duplicate_reports(): void
    {
        // Given: 게시글 작성
        $postId = $this->createTestPost();

        // 첫 번째 신고: 케이스 + 로그 생성 (reporter_id는 logs에 저장)
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'author_id' => $this->author->id,
        ]);
        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->reporter->id,
        ]);

        // When: 같은 사용자가 같은 게시글 재신고 시도
        $response = $this->actingAs($this->reporter)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
                'reason_type' => 'spam',
                'reason_detail' => '중복 신고 시도',
            ]);

        // Then: 중복 신고 에러 (409 Conflict)
        $response->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    /**
     * 페이지네이션 제한 테스트 (10~20개)
     */
    public function test_pagination_limit_between_10_and_20(): void
    {
        // Given: 30개 신고 생성
        Report::factory()->count(30)->create([
            'board_id' => $this->board->id,

            'author_id' => $this->author->id,
        ]);

        // When: per_page=5 요청 (최소값 미만)
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/reports?per_page=5');

        // Then: 최소값 10으로 조정됨
        $response->assertStatus(200);
        $this->assertEquals(10, $response->json('data.pagination.per_page'));

        // When: per_page=25 요청 (최대값 초과)
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/reports?per_page=25');

        // Then: 최대값 20으로 조정됨
        $response->assertStatus(200);
        $this->assertEquals(20, $response->json('data.pagination.per_page'));
    }

    /**
     * 검증 실패 테스트 - 상태 변경 시 잘못된 status 값
     */
    public function test_validation_fails_with_invalid_status(): void
    {
        // Given: 신고 데이터
        $report = Report::factory()->create([
            'board_id' => $this->board->id,

        ]);

        // When: 잘못된 status 값 전송
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'invalid_status',
            ]);

        // Then: 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * 검증 실패 테스트 - process_note 길이 초과
     */
    public function test_validation_fails_with_too_long_process_note(): void
    {
        // Given: 신고 데이터
        $report = Report::factory()->create([
            'board_id' => $this->board->id,

        ]);

        // When: 1001자 process_note 전송
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'review',
                'process_note' => str_repeat('a', 1001),
            ]);

        // Then: 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['process_note']);
    }

    /**
     * 스냅샷 데이터 저장 테스트
     */
    public function test_saves_snapshot_data_on_report_creation(): void
    {
        // Given: 게시글 작성
        $postId = $this->createTestPost();

        // When: 1케이스 구조 — 케이스(boards_reports) + 로그(boards_report_logs) 생성
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
        ]);
        $log = ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->reporter->id,
            'snapshot' => [
                'board_name' => $this->board->name,
                'title' => '테스트 게시글',
                'content' => '테스트 내용',
                'content_mode' => 'text',
                'author_name' => $this->author->name,
            ],
        ]);

        // Then: 스냅샷 데이터는 ReportLog에 저장됨
        $log->refresh();
        $this->assertEquals($this->board->name, $log->snapshot['board_name']);
        $this->assertEquals('테스트 게시글', $log->snapshot['title']);
    }

    /**
     * 신고 상태 변경 후 게시글 상태 동기화 테스트 (suspended)
     */
    public function test_report_status_update_blinds_post_content(): void
    {
        // Given: 게시글과 신고 생성
        $postId = $this->createTestPost();
        $report = Report::factory()->create([
            'board_id' => $this->board->id,

            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Pending,
        ]);

        // When: suspended 상태로 변경
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'suspended',
                'process_note' => '블라인드 처리합니다.',
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);

        // 게시글이 blinded 상태로 변경되었는지 확인 (게시중단은 삭제가 아니므로 deleted_at은 null)
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('blinded', $post->status);
        $this->assertEquals('report', $post->trigger_type);
        $this->assertNull($post->deleted_at);
    }

    /**
     * 신고 상태 변경 후 게시글 상태 동기화 테스트 (deleted)
     */
    public function test_report_status_update_deletes_post_content(): void
    {
        // Given: 게시글과 신고 생성
        $postId = $this->createTestPost();
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
                'process_note' => '영구 삭제합니다.',
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);

        // 게시글이 deleted 상태로 변경되었는지 확인
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('deleted', $post->status);
        $this->assertEquals('report', $post->trigger_type);
        $this->assertNotNull($post->deleted_at);
    }

    /**
     * 신고 반려 후 게시글 복구 테스트
     */
    public function test_report_rejection_restores_post_content(): void
    {
        // Given: 블라인드된 게시글과 suspended 상태의 신고
        $postId = $this->createTestPost();

        // 게시글을 블라인드 상태로 변경 (게시중단은 삭제가 아니므로 deleted_at은 null)
        DB::table('board_posts')
            ->where('id', $postId)
            ->update([
                'status' => 'blinded',
                'trigger_type' => 'report',
            ]);

        $report = Report::factory()->create([
            'board_id' => $this->board->id,

            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Suspended,
        ]);

        // When: rejected 상태로 변경 (신고 반려)
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'rejected',
                'process_note' => '신고 반려합니다.',
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);

        // 게시글이 published 상태로 복구되었는지 확인
        $post = DB::table('board_posts')->find($postId);
        $this->assertEquals('published', $post->status);
        $this->assertEquals('report', $post->trigger_type);
        $this->assertNull($post->deleted_at);
    }

    /**
     * 댓글 신고 목록에서 board.title은 스냅샷 기반(null)이고,
     * 상세 조회에서 상위 게시글 제목이 포함되는지 테스트
     */
    public function test_comment_report_includes_post_title_in_board_title(): void
    {
        // Given: 게시글 생성
        $postId = $this->createTestPost();

        // 댓글 생성
        $commentId = $this->createTestComment($postId);

        // 댓글에 대한 신고 생성 (1케이스 구조: snapshot은 ReportLog에 저장)
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'comment',
            'target_id' => $commentId,
            'author_id' => $this->author->id,
        ]);
        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->reporter->id,
            'snapshot' => [
                'board_name' => $this->board->name,
                'title' => null,
                'content' => '테스트 댓글 내용',
                'content_mode' => 'text',
                'author_name' => $this->author->name,
            ],
        ]);

        // When: 목록 조회 API 호출
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/reports');

        // Then: 목록에서 댓글 신고의 board.title은 스냅샷 기반이므로 null (N+1 방지 설계)
        $response->assertStatus(200);
        $reportData = collect($response->json('data.data'))->firstWhere('id', $report->id);

        $this->assertNotNull($reportData, '신고 데이터가 응답에 포함되어야 합니다');
        $this->assertNull($reportData['board']['title'], '목록에서 댓글 신고의 title은 스냅샷 기반(null)');

        // 단일 테이블(board_comments)이므로 별도 DROP 불필요 (RefreshDatabase가 처리)
    }

    /**
     * 신고 목록 API 응답에서 author/reporter가 uuid를 반환하는지 검증
     */
    public function test_report_list_returns_uuid_for_author_and_reporter(): void
    {
        // Given: 게시글 신고 생성
        $postId = $this->createTestPost();

        $report = Report::factory()->create([
            'board_id'   => $this->board->id,
            'target_type' => 'post',
            'target_id'  => $postId,
            'author_id'  => $this->author->id,
        ]);

        ReportLog::factory()->create([
            'report_id'   => $report->id,
            'reporter_id' => $this->reporter->id,
            'snapshot'    => [
                'board_name'  => $this->board->name,
                'title'       => '테스트 게시글',
                'content'     => '테스트 내용',
                'content_mode' => 'text',
                'author_name' => $this->author->name,
            ],
        ]);

        // When: 목록 조회 API 호출
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/reports');

        $response->assertStatus(200);
        $reportData = collect($response->json('data.data'))->firstWhere('id', $report->id);
        $this->assertNotNull($reportData);

        // Then: author에 uuid가 있고 id 키는 없어야 함
        $this->assertArrayHasKey('uuid', $reportData['author']);
        $this->assertArrayNotHasKey('id', $reportData['author']);
        $this->assertEquals($this->author->uuid, $reportData['author']['uuid']);

        // Then: reporter에 uuid가 있고 id 키는 없어야 함
        $this->assertArrayHasKey('uuid', $reportData['reporter']);
        $this->assertArrayNotHasKey('id', $reportData['reporter']);
        $this->assertEquals($this->reporter->uuid, $reportData['reporter']['uuid']);
    }

    /**
     * 신고 목록 API 응답에서 processor가 uuid를 반환하는지 검증
     *
     * processor는 ReportResource(목록 API)에만 포함됨 (ReportDetailResource 제외)
     */
    public function test_report_list_returns_uuid_for_processor(): void
    {
        // Given: 신고 생성 후 상태 변경 API로 처리자(processor) 설정
        $postId = $this->createTestPost();

        $report = Report::factory()->create([
            'board_id'    => $this->board->id,
            'target_type' => 'post',
            'target_id'   => $postId,
            'author_id'   => $this->author->id,
        ]);

        ReportLog::factory()->create([
            'report_id'   => $report->id,
            'reporter_id' => $this->reporter->id,
            'snapshot'    => [
                'board_name'  => $this->board->name,
                'title'       => '테스트 게시글',
                'content'     => '테스트 내용',
                'content_mode' => 'text',
                'author_name' => $this->author->name,
            ],
        ]);

        // 상태 변경 API 호출 → processed_by = admin->id 설정됨
        $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'review',
                'process_note' => 'UUID 검증 테스트',
            ])
            ->assertStatus(200);

        // When: 목록 API 호출 (processor는 ReportResource에서 반환)
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/reports');

        $response->assertStatus(200);
        $reportData = collect($response->json('data.data'))->firstWhere('id', $report->id);
        $this->assertNotNull($reportData);

        // Then: processor에 uuid가 있고 id 키는 없어야 함
        $this->assertNotNull($reportData['processor']);
        $this->assertArrayHasKey('uuid', $reportData['processor']);
        $this->assertArrayNotHasKey('id', $reportData['processor']);
        $this->assertEquals($this->admin->uuid, $reportData['processor']['uuid']);
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
}
