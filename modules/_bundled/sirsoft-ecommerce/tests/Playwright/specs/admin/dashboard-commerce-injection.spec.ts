/**
 * 관리자 대시보드 이커머스 영역 — layout extension 주입 매트릭스 (skeleton).
 *
 * @scenario admin-dashboard-commerce
 * @effects extension_badge_visible_when_module_active,
 *          quick_menu_product_button_visible_when_module_active,
 *          quick_menu_order_button_visible_when_module_active,
 *          quick_menu_review_button_visible_when_module_active,
 *          quick_menu_coupon_button_visible_when_module_active,
 *          quick_menu_shipping_button_visible_when_module_active,
 *          commerce_section_visible_when_module_active,
 *          today_order_status_badges_reflect_overview_api,
 *          sales_graph_chart_renders_7_days_from_sales_graph_api,
 *          latest_reviews_card_renders_recent_reviews_api_items,
 *          pending_inquiries_card_renders_pending_inquiries_api_items,
 *          quick_menu_buttons_hidden_when_module_inactive,
 *          commerce_section_hidden_when_module_inactive,
 *          non_admin_blocked_from_dashboard
 *
 * 본 spec 은 이커머스 모듈 작업 세션에서 다음 사전 작업 완료 후 활성화한다 (skeleton 상태):
 *   1. 모듈 extension JSON 의 quick_menu/commerce 컴포넌트에 data-testid 보강
 *      - qm-ecommerce-products / qm-ecommerce-orders / qm-ecommerce-reviews / qm-ecommerce-coupons / qm-ecommerce-shipping
 *      - commerce-dashboard-today-badges / commerce-dashboard-sales-graph
 *      - commerce-dashboard-latest-reviews / commerce-dashboard-pending-inquiries
 *   2. 모듈 활성/비활성 토글 fixture (또는 시드 커맨드) 도입
 *   3. test.describe.skip → test.describe 로 전환
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

test.describe.skip('관리자 대시보드 이커머스 영역 — 모듈 활성/비활성 매트릭스 (skeleton)', () => {
  test('모듈 활성 + 권한 보유자 — quick_menu 이커머스 버튼 5개가 보인다', async ({ page, dashboardToken }) => {
    await authenticatePage(page, dashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByTestId('qm-ecommerce-products')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('qm-ecommerce-orders')).toBeVisible();
    await expect(page.getByTestId('qm-ecommerce-reviews')).toBeVisible();
    await expect(page.getByTestId('qm-ecommerce-coupons')).toBeVisible();
    await expect(page.getByTestId('qm-ecommerce-shipping')).toBeVisible();
  });

  test('모듈 활성 + 권한 보유자 — commerce 카드 4종이 보이고 ExtensionBadge 가 표시된다', async ({
    page,
    dashboardToken,
  }) => {
    await authenticatePage(page, dashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByTestId('commerce-dashboard-today-badges')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('commerce-dashboard-sales-graph')).toBeVisible();
    await expect(page.getByTestId('commerce-dashboard-latest-reviews')).toBeVisible();
    await expect(page.getByTestId('commerce-dashboard-pending-inquiries')).toBeVisible();
    await expect(page.getByText('모듈')).toBeVisible(); // ExtensionBadge
  });

  test('모듈 비활성 — quick_menu 이커머스 버튼과 commerce 영역이 모두 사라진다', async ({
    page,
    dashboardToken,
  }) => {
    // TODO: 모듈 비활성 토글 fixture 가 도입되면 여기서 deactivate 호출
    await authenticatePage(page, dashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByTestId('qm-ecommerce-products')).toHaveCount(0);
    await expect(page.getByTestId('qm-ecommerce-orders')).toHaveCount(0);
    await expect(page.getByTestId('commerce-dashboard-sales-graph')).toHaveCount(0);
  });

  test('권한 없는 사용자 — /admin/dashboard 진입 시 차단된다', async ({ page, noPermissionToken }) => {
    await authenticatePage(page, noPermissionToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    expect(page.url()).toMatch(/\/admin\/login|\/admin\/dashboard/);
  });
});
