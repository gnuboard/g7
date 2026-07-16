<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 신고 관리 컨트롤러의 전용 FormRequest 검증 회귀 테스트 (#413-74 구조 정리)
 *
 * index/reporters/getStatusCounts 가 base Request 주입에서 전용 FormRequest 로 전환되며
 * 입력 검증이 컨트롤러 인라인 검사에서 FormRequest 로 이관되었다. 이 전환이 기존 동작
 * (빈 ids → 422, 잘못된 target_status → 422, 정상 조회 200)을 깨지 않음을 보장한다.
 */
class ReportRequestValidationTest extends ModuleTestCase
{
    private User $admin;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        config(['telescope.enabled' => false]);
        App::setLocale('ko');

        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        DB::table('users')->where('is_super', false)->delete();

        $this->admin = User::factory()->create();

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

        $this->board = Board::updateOrCreate(
            ['slug' => 'report-request-validation-board'],
            [
                'name' => ['ko' => '신고 검증 테스트 게시판', 'en' => 'Report Validation Test Board'],
                'slug' => 'report-request-validation-board',
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
    }

    /**
     * status-counts 요청에 ids 가 비어 있으면 422 로 거부됨을 검증합니다.
     * (기존 컨트롤러 인라인 empty($ids) 검사 → StatusCountsRequest 이관)
     */
    public function test_status_counts_with_empty_ids_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/modules/sirsoft-board/admin/reports/status-counts', [
                'ids' => [],
            ]);

        $response->assertStatus(422);
    }

    /**
     * status-counts 요청에 ids 키 자체가 없어도 422 로 거부됨을 검증합니다.
     */
    public function test_status_counts_without_ids_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/modules/sirsoft-board/admin/reports/status-counts', []);

        $response->assertStatus(422);
    }

    /**
     * status-counts 요청의 target_status 가 허용되지 않은 값이면 422 로 거부됨을 검증합니다.
     */
    public function test_status_counts_with_invalid_target_status_is_rejected_with_422(): void
    {
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'status' => ReportStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/modules/sirsoft-board/admin/reports/status-counts', [
                'ids' => [$report->id],
                'target_status' => 'not-a-real-status',
            ]);

        $response->assertStatus(422);
    }

    /**
     * 유효한 ids 로 status-counts 를 요청하면 200 으로 상태별 집계가 반환됨을 검증합니다.
     * (전용 FormRequest 전환이 정상 경로를 깨지 않음)
     */
    public function test_status_counts_with_valid_ids_succeeds(): void
    {
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'status' => ReportStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/modules/sirsoft-board/admin/reports/status-counts', [
                'ids' => [$report->id],
                'target_status' => 'review',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 전용 FormRequest 전환 후에도 신고 목록 조회(index)가 정상 200 임을 검증합니다.
     */
    public function test_index_still_succeeds_after_request_refactor(): void
    {
        Report::factory()->create([
            'board_id' => $this->board->id,
            'status' => ReportStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/reports');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 전용 FormRequest 전환 후에도 신고자 목록 조회(reporters)가 정상 200 임을 검증합니다.
     */
    public function test_reporters_still_succeeds_after_request_refactor(): void
    {
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'status' => ReportStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/reporters");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}
