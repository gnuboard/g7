<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Dashboard;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 대시보드 permission 가드 + 미처리 신고 스코프 테스트 (⑱-1 + N-5)
 *
 * - ⑱-1: 대시보드 4종(overview/post-graph/recent-posts/pending-reports)은
 *   permission:admin,sirsoft-board.dashboard.view 가드를 요구한다.
 * - N-5: 미처리 신고 집계는 비활성 게시판을 제외한다(board-active 스코프).
 */
class DashboardPermissionTest extends ModuleTestCase
{
    private const BASE = '/api/modules/sirsoft-board/admin/dashboard';

    private const ENDPOINTS = ['overview', 'post-graph', 'recent-posts', 'pending-reports'];

    private User $adminWithout;

    private User $adminWith;

    protected function setUp(): void
    {
        parent::setUp();

        // adminWithout: admin 이지만 dashboard.view 없음.
        // createAdminUser 는 공유 'admin' 역할에 권한을 붙이므로, 여기서 쓰면 adminWith 가
        // 그 역할에 dashboard.view 를 추가한 순간 adminWithout 도 갖게 된다(권한 격리 불가).
        // 따라서 dashboard.view 가 없는 격리 역할을 직접 만든다.
        $this->adminWithout = $this->createIsolatedAdminWithoutDashboardView();
        // adminWith: dashboard.view 보유 (공유 admin 역할 사용 — 프로덕션 기본 상태와 동일)
        $this->adminWith = $this->createAdminUser(['sirsoft-board.boards.read', 'sirsoft-board.dashboard.view']);
    }

    /**
     * dashboard.view 가 없는 권한-격리 관리자(고유 역할)를 생성합니다.
     *
     * admin 가드(isAdmin) 통과를 위해 admin.access 를, 무해한 admin 권한으로
     * boards.read 를 부여하되 dashboard.view 는 부여하지 않습니다.
     *
     * @return User 대시보드 권한이 없는 관리자
     */
    private function createIsolatedAdminWithoutDashboardView(): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'identifier' => 'board-dash-guard-'.$user->id,
            'name' => ['ko' => '대시보드 미보유 관리자', 'en' => 'Admin without dashboard'],
        ]);
        $user->roles()->attach($role->id);

        foreach (['admin.access', 'sirsoft-board.boards.read'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $user;
    }

    // ========== ⑱-1 permission 가드 ==========

    /**
     * @scenario case=dashboard_permission_gate
     *
     * @effects dashboard_requires_view_permission
     */
    #[Test]
    public function admin_without_dashboard_view_is_forbidden_on_all_endpoints(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $this->actingAs($this->adminWithout)
                ->getJson(self::BASE.'/'.$endpoint)
                ->assertStatus(403);
        }
    }

    /**
     * @scenario case=dashboard_permission_gate
     *
     * @effects dashboard_requires_view_permission
     */
    #[Test]
    public function admin_with_dashboard_view_can_access_all_endpoints(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $this->actingAs($this->adminWith)
                ->getJson(self::BASE.'/'.$endpoint)
                ->assertStatus(200);
        }
    }

    /**
     * @scenario case=dashboard_permission_gate
     *
     * @effects dashboard_requires_view_permission
     */
    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $this->getJson(self::BASE.'/'.$endpoint)->assertStatus(401);
        }
    }

    // ========== N-5 미처리 신고 board-active 스코프 ==========

    /**
     * @scenario case=pending_reports_scope
     *
     * @effects pending_reports_scoped_to_active_boards
     */
    #[Test]
    public function pending_reports_excludes_inactive_board_reports(): void
    {
        $activeBoard = Board::factory()->create(['is_active' => true]);
        $inactiveBoard = Board::factory()->create(['is_active' => false]);

        $this->createPendingReport($activeBoard);
        $this->createPendingReport($inactiveBoard);

        $response = $this->actingAs($this->adminWith)
            ->getJson(self::BASE.'/pending-reports');

        $response->assertStatus(200);

        // 비활성 게시판 신고는 제외되어 총 1건, 목록도 1건이어야 한다.
        $this->assertSame(1, $response->json('data.total'), '미처리 신고 총계는 활성 게시판 신고만 세야 한다.');
        $this->assertCount(1, $response->json('data.items'), '미처리 신고 목록은 활성 게시판 신고만 담아야 한다.');
    }

    /**
     * 지정 게시판에 미처리(pending) 신고 케이스를 생성합니다.
     *
     * @param  Board  $board  대상 게시판
     * @return Report 생성된 신고
     */
    private function createPendingReport(Board $board): Report
    {
        return Report::create([
            'board_id' => $board->id,
            'target_type' => 'post',
            'target_id' => 1,
            'status' => ReportStatus::Pending,
            'last_reported_at' => now(),
        ]);
    }
}
