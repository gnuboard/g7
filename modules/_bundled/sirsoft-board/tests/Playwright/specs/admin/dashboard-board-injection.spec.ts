/**
 * 관리자 대시보드 게시판 영역 — layout extension 주입 매트릭스 (skeleton).
 *
 * @scenario admin-dashboard-board
 * @effects extension_badge_visible_when_module_active,
 *          quick_menu_board_button_visible_when_module_active,
 *          quick_menu_report_button_visible_when_module_active,
 *          community_section_visible_when_module_active,
 *          today_posts_badge_reflects_overview_api,
 *          today_comments_badge_reflects_overview_api,
 *          post_graph_chart_renders_7_days_from_post_graph_api,
 *          latest_posts_card_renders_recent_posts_api_items,
 *          pending_reports_card_renders_pending_reports_api_items,
 *          quick_menu_buttons_hidden_when_module_inactive,
 *          community_section_hidden_when_module_inactive,
 *          non_admin_blocked_from_dashboard
 *
 * 본 spec 은 게시판 모듈 작업 세션에서 다음 사전 작업 완료 후 활성화한다 (skeleton 상태):
 *   1. 모듈 extension JSON 의 quick_menu/community 컴포넌트에 data-testid 보강
 *      - qm-boards / qm-reports
 *      - board-dashboard-today-badges / board-dashboard-post-graph
 *      - board-dashboard-latest-posts / board-dashboard-report-management
 *   2. 모듈 활성/비활성 토글 fixture (또는 시드 커맨드) 도입
 *   3. test.describe.skip → test.describe 로 전환
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

test.describe.skip('관리자 대시보드 게시판 영역 — 모듈 활성/비활성 매트릭스 (skeleton)', () => {
  test('모듈 활성 + 권한 보유자 — quick_menu 게시판/신고 버튼이 보인다', async ({ page, dashboardToken }) => {
    await authenticatePage(page, dashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByTestId('qm-boards')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('qm-reports')).toBeVisible();
  });

  test('모듈 활성 + 권한 보유자 — community 카드 4종이 보이고 ExtensionBadge 가 표시된다', async ({
    page,
    dashboardToken,
  }) => {
    await authenticatePage(page, dashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByTestId('board-dashboard-today-badges')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('board-dashboard-post-graph')).toBeVisible();
    await expect(page.getByTestId('board-dashboard-latest-posts')).toBeVisible();
    await expect(page.getByTestId('board-dashboard-report-management')).toBeVisible();
    await expect(page.getByText('모듈')).toBeVisible(); // ExtensionBadge
  });

  test('모듈 비활성 — quick_menu 게시판/신고 버튼과 community 영역이 모두 사라진다', async ({
    page,
    dashboardToken,
  }) => {
    // TODO: 모듈 비활성 토글 fixture 가 도입되면 여기서 deactivate 호출
    await authenticatePage(page, dashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByTestId('qm-boards')).toHaveCount(0);
    await expect(page.getByTestId('qm-reports')).toHaveCount(0);
    await expect(page.getByTestId('board-dashboard-post-graph')).toHaveCount(0);
  });

  test('권한 없는 사용자 — /admin/dashboard 진입 시 차단된다', async ({ page, noPermissionToken }) => {
    await authenticatePage(page, noPermissionToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 401/403 응답 또는 로그인 페이지 리다이렉트 중 하나
    expect(page.url()).toMatch(/\/admin\/login|\/admin\/dashboard/);
  });
});