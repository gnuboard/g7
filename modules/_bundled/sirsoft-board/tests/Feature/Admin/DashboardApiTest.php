<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardStat;
use Modules\Sirsoft\Board\Services\BoardDashboardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 대시보드 API 테스트
 *
 * /api/modules/sirsoft-board/admin/dashboard/* 4 엔드포인트
 *  - overview / post-graph / recent-posts / pending-reports
 *  - 가드: admin 미들웨어 (admin 권한 보유자만)
 */
class DashboardApiTest extends ModuleTestCase
{
    private User $adminUser;

    private User $normalUser;

    private const BASE = '/api/modules/sirsoft-board/admin/dashboard';

    /**
     * 대시보드 권한을 가진 관리자와 일반 회원을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 대시보드의 「오늘」은 사이트 표준시의 하루다(siteToday). 사이트 날짜와 UTC 날짜가 같은 시각으로
        // 고정해 실행 시각과 무관하게 같은 결과를 낸다 — 날짜 경계 자체는 BoardSiteDayBoundaryTest 가 잰다.
        $this->travelTo(CarbonImmutable::parse('2030-01-15 03:00:00', 'UTC'));

        BoardStat::query()->delete();

        // 대시보드 라우트는 admin 미들웨어 + permission:admin,sirsoft-board.dashboard.view 가드를 사용한다.
        $this->adminUser = $this->createAdminUser(['sirsoft-board.boards.read', 'sirsoft-board.dashboard.view']);
        $this->normalUser = $this->createUser();

        Board::create([
            'name' => ['ko' => 'API 테스트', 'en' => 'API Test'],
            'slug' => 'api-test-'.uniqid(),
            'type' => 'basic',
        ]);
    }

    // ========== overview ==========

    /**
     * 로그인하지 않으면 「오늘」 현황 API 가 401 인지 확인합니다.
     *
     * @scenario module_state=active, data_state=no_posts, user_role=non_admin
     *
     * @effects non_admin_blocked_from_dashboard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_overview_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/overview')->assertStatus(401);
    }

    /**
     * 관리자가 아니면 「오늘」 현황 API 를 막는지 확인합니다.
     *
     * @scenario module_state=active, data_state=no_posts, user_role=non_admin
     *
     * @effects non_admin_blocked_from_dashboard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_overview_rejects_non_admin_user(): void
    {
        $this->actingAs($this->normalUser)->getJson(self::BASE.'/overview')->assertStatus(403);
    }

    /**
     * 관리자에게 사이트 하루 「오늘」 행의 새 글·댓글 수를 돌려주는지 확인합니다.
     *
     * @scenario case=dashboard_permission_gate
     *
     * @effects dashboard_requires_view_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_overview_returns_today_counts_for_admin(): void
    {
        BoardStat::create([
            // 집계 행의 「오늘」은 사이트 표준시의 하루(7.1.0 O-CF3)
            'date' => app(BoardDashboardService::class)->siteToday()->toDateString(),
            'post_count' => 5,
            'comment_count' => 12,
        ]);

        $this->actingAs($this->adminUser)->getJson(self::BASE.'/overview')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['today_posts', 'today_comments']])
            ->assertJsonPath('data.today_posts', 5)
            ->assertJsonPath('data.today_comments', 12);
    }

    // ========== post-graph ==========

    /**
     * 로그인하지 않으면 추이 그래프 API 가 401 인지 확인합니다.
     *
     * @scenario module_state=active, data_state=no_posts, user_role=non_admin
     *
     * @effects non_admin_blocked_from_dashboard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_post_graph_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/post-graph')->assertStatus(401);
    }

    /**
     * 관리자에게 사이트 하루 기준 7일 추이와 합계를 돌려주는지 확인합니다.
     *
     * @scenario module_state=active, data_state=has_posts, user_role=admin_with_dashboard_permission
     *
     * @effects post_graph_chart_renders_7_days_from_post_graph_api, total_posts_displays_sum_of_7_days, total_comments_displays_sum_of_7_days
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_post_graph_returns_schema_for_admin(): void
    {
        $today = app(BoardDashboardService::class)->siteToday();
        for ($i = 0; $i < 7; $i++) {
            BoardStat::create([
                'date' => $today->subDays($i)->toDateString(),
                'post_count' => 1,
                'comment_count' => 2,
            ]);
        }

        $this->actingAs($this->adminUser)->getJson(self::BASE.'/post-graph')
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'days' => [['date', 'post_count', 'comment_count']],
                    'total_posts',
                    'total_comments',
                    'posts_change',
                    'comments_change',
                    'updated_at',
                ],
            ])
            ->assertJsonCount(7, 'data.days')
            ->assertJsonPath('data.total_posts', 7)
            ->assertJsonPath('data.total_comments', 14);
    }

    // ========== recent-posts ==========

    /**
     * 로그인하지 않으면 최신글 API 가 401 인지 확인합니다.
     *
     * @scenario module_state=active, data_state=no_posts, user_role=non_admin
     *
     * @effects non_admin_blocked_from_dashboard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_recent_posts_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/recent-posts')->assertStatus(401);
    }

    /**
     * 관리자에게 최신글 목록을 돌려주는지 확인합니다.
     *
     * @scenario module_state=active, data_state=has_posts, user_role=admin_with_dashboard_permission
     *
     * @effects latest_posts_card_renders_recent_posts_api_items
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_recent_posts_returns_collection_for_admin(): void
    {
        $this->actingAs($this->adminUser)->getJson(self::BASE.'/recent-posts')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data']);
    }

    /**
     * 최신글 개수 상한을 넘는 요청을 거부하는지 확인합니다.
     *
     * @scenario module_state=active, data_state=has_posts, user_role=admin_with_dashboard_permission
     *
     * @effects latest_posts_card_renders_recent_posts_api_items, recent_posts_limit_validated
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_recent_posts_rejects_limit_over_max(): void
    {
        $this->actingAs($this->adminUser)
            ->getJson(self::BASE.'/recent-posts?limit=999')
            ->assertStatus(422);
    }

    /**
     * 정수가 아닌 최신글 개수 요청을 거부하는지 확인합니다.
     *
     * @scenario module_state=active, data_state=has_posts, user_role=admin_with_dashboard_permission
     *
     * @effects latest_posts_card_renders_recent_posts_api_items, recent_posts_limit_validated
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_recent_posts_rejects_non_integer_limit(): void
    {
        $this->actingAs($this->adminUser)
            ->getJson(self::BASE.'/recent-posts?limit=abc')
            ->assertStatus(422);
    }

    // ========== pending-reports ==========

    /**
     * 로그인하지 않으면 미처리 신고 API 가 401 인지 확인합니다.
     *
     * @scenario module_state=active, data_state=no_posts, user_role=non_admin
     *
     * @effects non_admin_blocked_from_dashboard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_pending_reports_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/pending-reports')->assertStatus(401);
    }

    /**
     * 관리자에게 미처리 신고 목록과 총 건수를 돌려주는지 확인합니다.
     *
     * @scenario module_state=active, data_state=has_reports, user_role=admin_with_dashboard_permission
     *
     * @effects pending_reports_card_renders_pending_reports_api_items, pending_reports_count_badge_shows_total
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_pending_reports_returns_items_and_total_for_admin(): void
    {
        $this->actingAs($this->adminUser)->getJson(self::BASE.'/pending-reports')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['items', 'total']]);
    }
}
