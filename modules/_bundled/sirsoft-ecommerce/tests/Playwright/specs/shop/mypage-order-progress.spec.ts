/**
 * 마이페이지 주문내역 — 목록 상단 건수 칸 · 상세 진행단계 스텝퍼
 *
 * 브라우저로만 드러나는 축을 본다. 건수 칸은 원 위에 배지가 겹쳐야 하고(겹침은 jsdom 이 못 본다),
 * 칸 클릭은 그 상태를 필터로 보내며, 상세 스텝퍼는 칸 폭이 균일하고 모바일에서 스크롤 없이 줄바꿈된다.
 *
 * 목록·상세 응답은 가로챈다 — 새로 발급된 회원은 주문이 0건이라 실측 대상이 없다.
 *
 * 매니페스트 마킹(@scenario/@effects)은 테스트마다 그 케이스 위에 둔다.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import { API, waitForApp } from '../../fixtures/claim-screens';
import type { Page } from '@playwright/test';

const ORDER_NUMBER = 'E2E20260908-0001';

const STATUS_SUMMARY = [
  { key: 'pending_payment', label: '결제대기', icon: 'status-payment-pending', variant: 'warning', count: 3 },
  { key: 'payment_complete', label: '결제완료', icon: 'status-paid', variant: 'info', count: 17 },
  { key: 'preparing', label: '상품준비·배송준비', icon: 'status-preparing', variant: 'info', count: 0 },
  { key: 'shipping', label: '배송중', icon: 'status-dispatch', variant: 'primary', count: 12 },
  { key: 'delivered', label: '배송완료', icon: 'status-delivered', variant: 'success', count: 20 },
  { key: 'confirmed', label: '구매확정', icon: 'status-completed', variant: 'success', count: 8 },
];

const PROGRESS_STEPS = [
  { value: 'pending_payment', label: '결제대기', state: 'done', icon: 'status-payment-pending', variant: 'warning' },
  { value: 'payment_complete', label: '결제완료', state: 'done', icon: 'status-paid', variant: 'info' },
  { value: 'preparing', label: '상품준비중', state: 'done', icon: 'status-preparing', variant: 'info' },
  { value: 'shipping_ready', label: '배송준비중', state: 'done', icon: 'status-dispatch-ready', variant: 'info' },
  { value: 'shipping', label: '배송중', state: 'current', icon: 'status-dispatch', variant: 'primary' },
  { value: 'delivered', label: '배송완료', state: 'upcoming', icon: 'status-delivered', variant: 'success' },
  { value: 'confirmed', label: '구매확정', state: 'upcoming', icon: 'status-completed', variant: 'success' },
];

/**
 * 목록·상세 응답을 세웁니다.
 *
 * @param page Playwright 페이지
 * @returns 목록 요청이 실어 보낸 status 쿼리 기록
 */
async function mockOrders(page: Page): Promise<string[]> {
  const statusQueries: string[] = [];

  await page.route(`**${API}/user/orders?*`, async (route) => {
    const url = new URL(route.request().url());
    statusQueries.push(url.searchParams.get('status') ?? '');
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: null,
        data: {
          data: [],
          statistics: Object.fromEntries(STATUS_SUMMARY.map((s) => [s.key, s.count])),
          status_summary: STATUS_SUMMARY,
          abilities: { can_create: true },
          pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: 0, to: 0, has_more_pages: false },
        },
      }),
    });
  });

  await page.route(`**${API}/user/orders/${ORDER_NUMBER}*`, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: null,
        data: {
          id: 990201,
          order_number: ORDER_NUMBER,
          order_status: 'shipping',
          order_status_label: '배송중',
          order_status_variant: 'primary',
          order_status_icon: 'status-dispatch',
          progress_steps: PROGRESS_STEPS,
          is_partially_cancelled: false,
          base_currency: 'KRW',
          currency_code: 'KRW',
          ordered_at_formatted: '2026-09-08 10:00',
          options: [],
          shippings: [],
          payments: [],
          abilities: {},
        },
      }),
    });
  });

  return statusQueries;
}

test.describe('@sirsoft-ecommerce 마이페이지 주문 진행단계', () => {
  // 목록 진입 → 상세 이동 → 데이터소스 도착까지 한 테스트 안에서 이어지므로 기본 30초를 넘긴다.
  test.describe.configure({ timeout: 90_000 });

  // @scenario surface=mypage_list, order_stage=in_progress, viewport=desktop
  // @effects order_summary_cells_render_server_summary, order_summary_badge_overlaps_circle, order_summary_click_filters_by_status
  test('목록 상단 건수 칸이 원 위 배지로 그려지고 클릭하면 그 상태로 필터한다', async ({ page, memberToken }) => {
    const queries = await mockOrders(page);
    await authenticatePage(page, memberToken);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/mypage/orders');
    await waitForApp(page);

    const cells = page.locator('[id^="order_summary_step_"]');
    await expect(cells.first()).toBeVisible({ timeout: 30_000 });
    await expect(cells).toHaveCount(6);

    // 원 안 아이콘 + 오른쪽 위 배지가 원과 겹친다 (0 건인 칸은 배지 없음)
    await expect(page.locator('[id^="order_summary_step_"] .rounded-full i[class*="fa-"]')).toHaveCount(6);
    await expect(page.locator('[data-summary-badge]')).toHaveCount(5);
    const overlap = await page.locator('[data-summary-key="payment_complete"]').evaluate((el) => {
      const circle = (el.querySelector('.rounded-full') as Element).getBoundingClientRect();
      const badge = (el.querySelector('[data-summary-badge]') as Element).getBoundingClientRect();
      return { intersects: badge.left < circle.right && badge.top < circle.bottom && badge.right > circle.left, text: el.querySelector('[data-summary-badge]')?.textContent };
    });
    expect(overlap.intersects).toBe(true);
    expect(overlap.text).toBe('17');

    // 칸 폭 균일 · 한 줄 · 꺾쇠 5
    const widths = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().width)));
    expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1);
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(5);

    // 클릭 → 그 상태로 필터 (주소 + 목록 재요청)
    await page.locator('[data-summary-key="shipping"]').click();
    await page.waitForURL(/status=shipping/, { timeout: 15_000 });
    await expect.poll(() => queries.includes('shipping'), { timeout: 15_000 }).toBe(true);
    await expect(page.locator('[data-summary-key="shipping"] .rounded-full.border-2')).toHaveClass(/shadow-md/);
  });

  // @scenario surface=mypage_list, order_stage=in_progress, viewport=mobile
  // @effects order_summary_wraps_on_mobile_without_scroll
  test('목록 상단 건수 칸이 모바일 390 에서 3칸/줄로 줄바꿈되고 가로 스크롤이 없다', async ({ page, memberToken }) => {
    await mockOrders(page);
    await authenticatePage(page, memberToken);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/mypage/orders');
    await waitForApp(page);

    const cells = page.locator('[id^="order_summary_step_"]');
    await expect(cells.first()).toBeVisible({ timeout: 30_000 });
    const tops = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
    expect(new Set(tops).size).toBe(2);

    const doc = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(doc.scroll).toBeLessThanOrEqual(doc.client + 1);
    await expect(page.locator('[data-step-chevron="mobile"]:visible')).toHaveCount(4);
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(0);
  });

  // @scenario surface=mypage_detail, order_stage=in_progress, viewport=desktop
  // @effects order_progress_stepper_renders_server_steps, order_progress_cells_uniform_width_with_chevrons
  // @scenario surface=mypage_detail, order_stage=in_progress, viewport=mobile
  // @effects order_progress_wraps_on_mobile_without_scroll
  test('상세 상태 헤더 아래 진행단계가 서버 배열대로 서고 모바일에서 줄바꿈된다', async ({ page, memberToken }) => {
    await mockOrders(page);
    await authenticatePage(page, memberToken);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/mypage/orders/${ORDER_NUMBER}`);
    await waitForApp(page);

    const cells = page.locator('[id^="order_progress_step_"]');
    await expect(cells.first()).toBeVisible({ timeout: 30_000 });
    await expect(cells).toHaveCount(7);
    await expect(page.locator('[data-step-state="current"]')).toHaveAttribute('data-step-value', 'shipping');
    await expect(page.locator('[id^="order_progress_step_"] i[class*="fa-status-"]')).toHaveCount(0);

    const widths = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().width)));
    expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1);
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(6);

    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(async () => {
      const tops = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
      return new Set(tops).size;
    }, { timeout: 10_000 }).toBe(2);
    const box = await page.locator('#order_progress_steps').evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
    expect(box.scroll).toBeLessThanOrEqual(box.client + 1);
    await expect(page.locator('[data-step-chevron="mobile"]:visible')).toHaveCount(5);
  });

  // @scenario surface=mypage_detail, order_stage=off_path, viewport=desktop
  // @effects order_progress_off_path_appends_last_cell, order_progress_chevrons_between_cells_only
  // @scenario surface=mypage_detail, order_stage=off_path, viewport=mobile
  // @effects order_progress_wraps_on_mobile_without_scroll
  test('취소 주문 상세는 순서 안에 현재 칸이 없고 빨간 이탈 칸이 마지막에 붙으며 모바일에서도 줄바꿈된다', async ({ page, memberToken }) => {
    await mockOrders(page);
    // 결제 뒤 취소 — 서버는 결제완료까지 done, 나머지 upcoming, 마지막에 off_path 칸을 붙인다.
    await page.route(`**${API}/user/orders/${ORDER_NUMBER}*`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          message: null,
          data: {
            id: 990202,
            order_number: ORDER_NUMBER,
            order_status: 'cancelled',
            order_status_label: '주문취소',
            order_status_variant: 'danger',
            order_status_icon: 'status-cancelled',
            progress_steps: [
              ...PROGRESS_STEPS.slice(0, 2),
              ...PROGRESS_STEPS.slice(2).map((s) => ({ ...s, state: 'upcoming' })),
              { value: 'cancelled', label: '주문취소', state: 'off_path', icon: 'status-cancelled', variant: 'danger' },
            ],
            is_partially_cancelled: false,
            base_currency: 'KRW',
            currency_code: 'KRW',
            ordered_at_formatted: '2026-09-08 10:00',
            options: [],
            shippings: [],
            payments: [],
            abilities: {},
          },
        }),
      });
    });
    await authenticatePage(page, memberToken);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/mypage/orders/${ORDER_NUMBER}`);
    await waitForApp(page);

    const cells = page.locator('[id^="order_progress_step_"]');
    await expect(cells.first()).toBeVisible({ timeout: 30_000 });
    await expect(cells).toHaveCount(8);
    await expect(page.locator('[data-step-state="current"]')).toHaveCount(0);
    const last = page.locator('[data-step-state="off_path"]');
    await expect(last).toHaveAttribute('data-step-value', 'cancelled');
    await expect(last.locator('.rounded-full.border-2')).toHaveClass(/bg-red-500/);
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(7);

    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(async () => {
      const tops = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
      return new Set(tops).size;
    }, { timeout: 10_000 }).toBe(2);
    await expect(page.locator('[data-step-chevron="mobile"]:visible')).toHaveCount(6);
    const doc = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(doc.scroll).toBeLessThanOrEqual(doc.client + 1);
  });
});
