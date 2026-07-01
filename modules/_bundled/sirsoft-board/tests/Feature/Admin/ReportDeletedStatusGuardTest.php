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
 * 영구삭제(deleted) 신고건의 강제 상태변경 차단 회귀 테스트 (#413-74)
 *
 * 검수: 영구삭제된 신고건을 백엔드 API로 강제로 다른 상태로 변경 요청하면
 * 422를 반환해야 하는데 200으로 떨어지는 결함. (실제 DB 상태는 불변)
 *
 * 근본 원인:
 *  ① UpdateStatusRequest 의 status 검증 클로저가 라우트 파라미터를 잘못 읽어(route('id'))
 *     항상 null 을 조회 → canTransitionTo 검증이 통째로 무력화
 *  ② 단건 컨트롤러가 벌크 메서드(bulkUpdateStatus)를 호출 → deleted 항목을 조용히 스킵(200)
 *
 * deleted 는 ReportStatus 전환 규칙상 최종 상태(canTransitionTo 항상 false)이므로
 * 강제 변경 시도는 422 + invalid_status_transition 으로 거부되어야 한다.
 */
class ReportDeletedStatusGuardTest extends ModuleTestCase
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
            ['slug' => 'report-deleted-guard-board'],
            [
                'name' => ['ko' => '신고 차단 테스트 게시판', 'en' => 'Report Guard Test Board'],
                'slug' => 'report-deleted-guard-board',
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
     * 영구삭제된 신고건을 다른 상태로 강제 변경 요청하면 422 로 거부되고 상태가 불변임을 검증합니다.
     */
    public function test_force_status_change_on_deleted_report_is_rejected_with_422(): void
    {
        // Given: deleted(영구삭제) 상태의 신고
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'status' => ReportStatus::Deleted,
        ]);

        // When: review 로 강제 변경 시도
        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'review',
                'process_note' => '강제 변경 시도',
            ]);

        // Then: 422 거부 + DB 상태는 여전히 deleted (불변)
        $response->assertStatus(422);

        $this->assertDatabaseHas('boards_reports', [
            'id' => $report->id,
            'status' => 'deleted',
        ]);
    }

    /**
     * 영구삭제된 신고건을 pending/rejected/suspended 등 어떤 상태로 강제 요청해도 모두 422 임을 검증합니다.
     */
    public function test_force_status_change_on_deleted_report_is_rejected_for_all_targets(): void
    {
        foreach (['pending', 'rejected', 'suspended'] as $targetStatus) {
            $report = Report::factory()->create([
                'board_id' => $this->board->id,
                'status' => ReportStatus::Deleted,
            ]);

            $response = $this->actingAs($this->admin)
                ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                    'status' => $targetStatus,
                ]);

            $response->assertStatus(422);

            $this->assertDatabaseHas('boards_reports', [
                'id' => $report->id,
                'status' => 'deleted',
            ]);
        }
    }

    /**
     * 동일 상태로의 변경 시도(pending → pending)도 전환 규칙 위반으로 422 임을 검증합니다.
     * (A 수정으로 복원되는 전환 검증의 부수 효과 — 동일 상태 전환 차단)
     */
    public function test_same_status_transition_is_rejected_with_422(): void
    {
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'status' => ReportStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'pending',
            ]);

        $response->assertStatus(422);
    }

    /**
     * 정상 전환(pending → review)은 200 으로 처리되고 상태가 변경됨을 검증합니다.
     * (A 수정이 정상 전환을 깨지 않음을 보장하는 회귀 가드)
     */
    public function test_valid_status_transition_still_succeeds(): void
    {
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'status' => ReportStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'review',
                'process_note' => '검토 시작',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('boards_reports', [
            'id' => $report->id,
            'status' => 'review',
            'processed_by' => $this->admin->id,
        ]);
    }
}
