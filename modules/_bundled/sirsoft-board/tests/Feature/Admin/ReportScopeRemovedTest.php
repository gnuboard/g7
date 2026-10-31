<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 신고 관리 권한의 범위 제거 무회귀 테스트 (#789)
 *
 * 신고 관리 권한(`reports.view`·`reports.manage`)의 범위 메타는 `owner_key = reporter_id` 였으나, 1케이스 구조 뒤
 * 케이스 테이블에 신고자 컬럼이 없어 「본인만」·「소유역할」이 어떤 의미로도 일관되게 동작하지 않았다(목록·상세·
 * 상태 변경은 막히고 일괄·건수·신고자 목록은 범위를 보지 않음). 범위를 두지 않기로 했다 — 권한 보유만으로 판정한다.
 *
 * 기존에 범위(본인만)를 지정했던 역할이 업데이트 뒤 모든 신고 화면을 그대로 쓸 수 있어야 한다.
 */
#[Group('board')]
#[Group('admin')]
#[Group('scope')]
class ReportScopeRemovedTest extends ModuleTestCase
{
    private User $operator;

    private Board $board;

    /**
     * 범위(본인만)를 지정해 둔 옛 부여 행을 가진 신고 담당 운영자와 신고 2건을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        PermissionHelper::clearPermissionScopeCache();
        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();

        $this->board = Board::updateOrCreate(
            ['slug' => 'report-scope-removed'],
            ['name' => ['ko' => '신고 범위 제거', 'en' => 'Report Scope Removed'], 'is_active' => true, 'use_report' => true, 'blocked_keywords' => []]
        );

        $role = Role::firstOrCreate(
            ['identifier' => 'report-scope-removed-operator'],
            ['name' => ['ko' => '신고 담당(본인만)', 'en' => 'Report Operator (self)']]
        );

        // 모듈 선언 그대로 권한 행을 만든다 — 메타가 선언에서 빠졌으면 행에도 없다
        $category = collect((new Module)->getPermissions()['categories'])->firstWhere('identifier', 'reports');
        foreach ($category['permissions'] as $permission) {
            $row = Permission::updateOrCreate(
                ['identifier' => 'sirsoft-board.reports.'.$permission['action']],
                [
                    'name' => $permission['name'],
                    'type' => $permission['type'] ?? 'admin',
                    'resource_route_key' => $category['resource_route_key'] ?? null,
                    'owner_key' => $category['owner_key'] ?? null,
                ]
            );
            // 업데이트 전에 「본인만」으로 저장해 둔 부여 행
            $role->permissions()->syncWithoutDetaching([$row->id => ['scope_type' => ScopeType::Self->value]]);
        }

        PermissionHelper::clearPermissionScopeCache();

        $this->operator = User::factory()->create();
        $this->operator->roles()->attach($role->id);

        Report::factory()->count(2)->create([
            'board_id' => $this->board->id,
            'status' => ReportStatus::Pending,
        ]);
    }

    /**
     * 모듈은 신고 관리 권한에 범위 메타를 선언하지 않는다(역할 화면에 범위 선택이 없다).
     *
     * @scenario case=report_scope_meta_not_declared
     *
     * @effects report_scope_meta_not_declared
     *
     * @return void 반환값 없음
     */
    public function test_module_declares_no_scope_meta_for_reports(): void
    {
        $category = collect((new Module)->getPermissions()['categories'])->firstWhere('identifier', 'reports');

        $this->assertArrayNotHasKey('resource_route_key', $category);
        $this->assertArrayNotHasKey('owner_key', $category);
    }

    /**
     * 범위를 지정했던 역할도 목록·상세·상태별 건수·신고자 목록을 권한 보유만으로 본다.
     *
     * @scenario case=report_read_paths_ignore_legacy_scope
     *
     * @effects report_read_paths_ignore_legacy_scope
     *
     * @return void 반환값 없음
     */
    public function test_legacy_scoped_role_reads_all_report_paths(): void
    {
        $ids = Report::query()->pluck('id')->all();

        $list = $this->actingAs($this->operator)->getJson('/api/modules/sirsoft-board/admin/reports');
        $list->assertOk();
        $this->assertCount(2, $list->json('data.data'));

        $this->actingAs($this->operator)->getJson("/api/modules/sirsoft-board/admin/reports/{$ids[0]}")->assertOk();
        $this->actingAs($this->operator)->getJson("/api/modules/sirsoft-board/admin/reports/{$ids[0]}/reporters")->assertOk();

        $counts = $this->actingAs($this->operator)
            ->postJson('/api/modules/sirsoft-board/admin/reports/status-counts', ['ids' => $ids]);
        $counts->assertOk();
    }

    /**
     * 범위를 지정했던 역할도 단건·일괄 상태 변경을 권한 보유만으로 한다.
     *
     * @scenario case=report_manage_paths_ignore_legacy_scope
     *
     * @effects report_manage_paths_ignore_legacy_scope
     *
     * @return void 반환값 없음
     */
    public function test_legacy_scoped_role_manages_reports(): void
    {
        $ids = Report::query()->pluck('id')->all();

        $this->actingAs($this->operator)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$ids[0]}/status", ['status' => 'review', 'process_note' => '확인 중'])
            ->assertOk();
        $this->assertDatabaseHas('boards_reports', ['id' => $ids[0], 'status' => 'review']);

        $this->actingAs($this->operator)
            ->patchJson('/api/modules/sirsoft-board/admin/reports/bulk-status', ['ids' => $ids, 'status' => 'rejected', 'process_note' => '일괄'])
            ->assertOk();
        foreach ($ids as $id) {
            $this->assertDatabaseHas('boards_reports', ['id' => $id, 'status' => 'rejected']);
        }
    }
}
