/**
 * 관리자 주문 상세 — 진행단계 스텝퍼 (탭 위 상시 노출)
 *
 * 브라우저로만 드러나는 축을 본다. 칸 폭이 라벨 길이를 따라가면 언어마다 들쭉날쭉해지고, 모바일에서
 * 한 줄에 몰아넣으면 가로 스크롤이 생기며, Icon 에 직접 준 `hidden` 은 Font Awesome 의 display 규칙에
 * 밀려 숨겨지지 않는다 — 어느 쪽도 jsdom 에서는 드러나지 않는다. 꺾쇠는 두 원 사이 정중앙에 선다.
 *
 * 매니페스트 마킹(@scenario/@effects)은 테스트마다 그 케이스 위에 둔다.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

/**
 * 첫 주문의 상세로 진입한다 (주문번호 하드코딩 회피).
 *
 * @param page Playwright 페이지
 * @returns 완료 Promise
 */
async function gotoFirstOrderDetail(page: Page): Promise<void> {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/admin/ecommerce/orders');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const orderNumber = page.locator('table tbody tr span.cursor-pointer').first();
  await orderNumber.waitFor({ state: 'visible', timeout: 30_000 });
  await orderNumber.click();

  await page.waitForURL(/\/admin\/ecommerce\/orders\/[\w-]+$/, { timeout: 30_000 });
}

test.describe('@sirsoft-ecommerce 관리자 주문 상세 진행단계', () => {
  // 목록 진입 → 상세 이동 → 데이터소스 도착까지 한 테스트 안에서 이어지므로 기본 30초를 넘긴다.
  test.describe.configure({ timeout: 90_000 });

  // @scenario surface=admin_detail, order_stage=in_progress, viewport=desktop
  // @effects order_progress_cells_uniform_width_with_chevrons, order_progress_card_mounted_above_tabs, order_progress_steps_carry_icon_and_variant
  test('진행단계가 탭 위에 서고 칸 폭이 균일하며 꺾쇠가 칸 사이 정중앙에만 선다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await gotoFirstOrderDetail(page);

    const cells = page.locator('[id^="order_progress_step_"]');
    await expect(cells.first()).toBeVisible({ timeout: 30_000 });
    const count = await cells.count();
    expect(count).toBeGreaterThanOrEqual(7);

    // 카드는 탭 내비게이션보다 위에 있다
    const cardTop = await page.locator('#order_progress_card').evaluate((el) => el.getBoundingClientRect().top);
    const tabTop = await page.locator('#tab_navigation').evaluate((el) => el.getBoundingClientRect().top);
    expect(cardTop).toBeLessThan(tabTop);

    // 칸마다 원 안에 서버 토큰이 해석된 글리프 하나 (토큰 원문 fa-status-* 는 없다)
    await expect(page.locator('[id^="order_progress_step_"] .rounded-full i[class*="fa-"]')).toHaveCount(count);
    await expect(page.locator('[id^="order_progress_step_"] i[class*="fa-status-"]')).toHaveCount(0);

    const states = await cells.evaluateAll((els) => els.map((el) => el.getAttribute('data-step-state')));
    expect(states.every((s) => ['done', 'current', 'upcoming', 'off_path'].includes(s ?? ''))).toBe(true);

    // PC — 칸 폭 균일 · 한 줄 · 꺾쇠는 마지막 칸을 뺀 칸 사이
    const widths = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().width)));
    expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1);
    const tops = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
    expect(new Set(tops).size).toBe(1);
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(count - 1);
    await expect(page.locator('[data-step-chevron="mobile"]:visible')).toHaveCount(0);

    // 꺾쇠는 양쪽 원과 같은 거리에 선다
    const gaps = await cells.evaluateAll((els) => {
      const out: number[] = [];
      els.forEach((el, i) => {
        const chevron = el.querySelector('[data-step-chevron="pc"] i');
        const next = els[i + 1];
        if (!chevron || !next) return;
        const a = (el.querySelector('.rounded-full') as Element).getBoundingClientRect();
        const b = (next.querySelector('.rounded-full') as Element).getBoundingClientRect();
        const g = chevron.getBoundingClientRect();
        out.push(Math.abs((g.left - a.right) - (b.left - g.right)));
      });
      return out;
    });
    expect(gaps.length).toBe(count - 1);
    expect(Math.max(...gaps)).toBeLessThanOrEqual(2);
  });

  // @scenario surface=admin_detail, order_stage=in_progress, viewport=mobile
  // @effects order_progress_wraps_on_mobile_without_scroll
  test('모바일 390 에서 4칸/줄로 줄바꿈되고 가로 스크롤이 없다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await gotoFirstOrderDetail(page);

    const cells = page.locator('[id^="order_progress_step_"]');
    await expect(cells.first()).toBeVisible({ timeout: 30_000 });
    const count = await cells.count();

    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(async () => {
      const tops = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
      return new Set(tops).size;
    }, { timeout: 10_000 }).toBe(Math.ceil(count / 4));

    const widths = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().width)));
    expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1);

    const box = await page.locator('#order_progress_steps').evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
    expect(box.scroll).toBeLessThanOrEqual(box.client + 1);

    const expectedMobile = [...Array(count - 1).keys()].filter((i) => i % 4 !== 3).length;
    await expect(page.locator('[data-step-chevron="mobile"]:visible')).toHaveCount(expectedMobile);
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(0);
  });

  // @scenario surface=admin_detail, order_stage=off_path, viewport=desktop
  // @effects order_progress_off_path_appends_last_cell, order_progress_chevrons_between_cells_only
  // @scenario surface=admin_detail, order_stage=off_path, viewport=mobile
  // @effects order_progress_wraps_on_mobile_without_scroll
  test('배송보류 주문은 순서 안에 현재 칸이 없고 이탈 칸이 마지막에 붙으며 모바일에서도 줄바꿈된다', async ({ page, ordersReadToken }) => {
    // 실서버 상세 응답의 진행단계만 이탈 상태로 바꿔 싣는다 — 이 축은 서버 배열 자체가 아니라
    // "이탈 칸을 화면이 어떻게 그리는가" 이므로, 어느 주문이 열리든 같은 배열을 보게 한다.
    await page.route('**/api/modules/sirsoft-ecommerce/admin/orders/*', async (route) => {
      const url = route.request().url();
      if (route.request().method() !== 'GET' || /\/(logs|claims)(\?|$)/.test(url) || url.includes('?')) {
        await route.fallback();
        return;
      }
      const response = await route.fetch();
      const body = await response.json();
      if (!body?.data?.progress_steps) {
        await route.fulfill({ response });
        return;
      }
      body.data.order_status = 'shipping_hold';
      // 열린 주문이 이미 이탈 상태(취소 등)면 서버 배열 끝에 이탈 칸이 있다 — 순서 7칸만 남기고 다시 붙인다.
      body.data.progress_steps = body.data.progress_steps
        .filter((s: any) => s.state !== 'off_path')
        .map((s: any, i: number) => ({ ...s, state: i < 2 ? 'done' : 'upcoming' }));
      body.data.progress_steps.push({ value: 'shipping_hold', label: '배송보류', state: 'off_path', icon: 'status-on-hold', variant: 'warning' });
      await route.fulfill({ response, body: JSON.stringify(body) });
    });

    await authenticatePage(page, ordersReadToken);
    await gotoFirstOrderDetail(page);

    const cells = page.locator('[id^="order_progress_step_"]');
    await expect(cells.first()).toBeVisible({ timeout: 30_000 });
    await expect(cells).toHaveCount(8);
    await expect(page.locator('[data-step-state="current"]')).toHaveCount(0);
    const last = page.locator('[data-step-state="off_path"]');
    await expect(last).toHaveAttribute('data-step-value', 'shipping_hold');
    await expect(last.locator('.rounded-full.border-2')).toHaveClass(/bg-red-500/);
    await expect(last.locator('i[class*="fa-pause"]')).toHaveCount(1);
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(7);

    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(async () => {
      const tops = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
      return new Set(tops).size;
    }, { timeout: 10_000 }).toBe(2);
    await expect(page.locator('[data-step-chevron="mobile"]:visible')).toHaveCount(6);
    const box = await page.locator('#order_progress_steps').evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
    expect(box.scroll).toBeLessThanOrEqual(box.client + 1);
  });
});
