/**
 * 쿠폰존 — 비로그인은 공개 목록과 로그인 안내, 로그인 회원은 받기·받음 표시, 좁은 화면에서도 가로 넘침이 없다 (#952 C)
 *
 * 목록 응답은 page.route 로 고정한다 — 사이트의 실제 쿠폰 유무와 무관하게 화면 동작만 잰다. 받기 요청도 가로채
 * 성공으로 답하므로 실제 발급은 일어나지 않는다. 서버 계약(공개·회원 응답 모양·요청 제한)은 PHPUnit PublicCouponZoneTest.
 *
 * 소속 시나리오(마커 아님 — 평문): coupon-zone
 * 효과 요약(마커 아님 — 평문): coupon_zone_endpoint_by_login, coupon_zone_guest_login_prompt, coupon_zone_card_download_state
 */
import { test, expect } from '@playwright/test';

import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';
import { dismissCookieBanner } from '../../../../../../tests/Playwright/fixtures/member-fields';

/**
 * 사이트 팝업·쿠키 배너가 떠 있으면 닫는다 — 화면을 덮어 단추 누르기를 가로챈다.
 *
 * @param page 페이지
 */
async function clearOverlays(page: import('@playwright/test').Page): Promise<void> {
  await dismissCookieBanner(page);
  const close = page.getByRole('button', { name: '닫기', exact: true }).first();
  if (await close.isVisible().catch(() => false)) await close.click().catch(() => undefined);
}

const coupon = (id: number, downloaded = false) => ({
  id,
  coupon_id: id,
  localized_name: `i952 쿠폰 ${id}`,
  localized_description: '',
  target_type: 'product_amount',
  target_type_short_label: '상품',
  target_scope: 'all',
  discount_type: 'fixed',
  discount_value: 1000,
  discount_max_amount: null,
  min_order_amount: 0,
  min_order_amount_formatted: '0원',
  benefit_formatted: '1,000원 할인',
  multi_currency_benefit_formatted: {},
  multi_currency_discount_value: null,
  multi_currency_min_order_amount: null,
  multi_currency_discount_max_amount: null,
  is_combinable: false,
  valid_type: 'period',
  valid_days: null,
  valid_to: '2099-12-31T14:59:59+00:00',
  valid_period_formatted: '~ 2099-12-31',
  remaining_quantity: 10,
  is_downloaded: downloaded,
});

const page1 = (items: unknown[]) => ({
  success: true,
  message: 'ok',
  data: { data: items, current_page: 1, last_page: 1, per_page: 12, total: items.length, next_page_url: null },
});

test.describe('쿠폰존 (#952)', () => {
  // @scenario case=guest_browser
  // @effects coupon_zone_guest_login_prompt
  test('비로그인 — 공개 목록을 받고, 받기를 누르면 로그인 안내 창이 뜬다', async ({ page }) => {
    const publicCalls: string[] = [];
    await page.route('**/api/modules/sirsoft-ecommerce/coupons/downloadable**', (route) => {
      publicCalls.push(route.request().url());
      const { is_downloaded: _omit, ...rest } = coupon(1) as Record<string, unknown>;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(page1([rest])) });
    });

    await page.goto('/shop/coupons');
    await clearOverlays(page);
    await expect(page.locator('#coupon_zone_card_0')).toBeVisible({ timeout: 30_000 });
    expect(publicCalls.length).toBeGreaterThan(0);
    await expect(page.locator('#coupon_zone_login_hint')).toBeVisible();

    await page.locator('#coupon_zone_download_0').click();
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 10_000 });
  });

  // @scenario case=member_browser
  // @effects coupon_zone_card_download_state
  test('로그인 — 같은 공개 주소에 토큰을 실어 받은 여부를 받고, 받기에 성공하면 그 카드가 「받음」 으로 바뀐다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    await page.route('**/api/modules/sirsoft-ecommerce/coupons/downloadable**', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(page1([coupon(1, false), coupon(2, true)])) }));
    await page.route('**/api/modules/sirsoft-ecommerce/user/coupons/1/download', (route) =>
      route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ success: true, message: '받았습니다', data: { id: 99 } }) }));

    await page.goto('/shop/coupons');
    await clearOverlays(page);
    await expect(page.locator('#coupon_zone_card_0')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('#coupon_zone_downloaded_1')).toBeVisible();
    await expect(page.locator('#coupon_zone_login_hint')).toHaveCount(0);

    await page.locator('#coupon_zone_download_0').click();
    await expect(page.locator('#coupon_zone_downloaded_0')).toBeVisible({ timeout: 10_000 });
  });

  // @scenario case=guest_mobile_browser
  // @effects coupon_zone_mobile_no_overflow
  test('좁은 화면(375px)에서 가로 넘침이 없다', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await page.route('**/api/modules/sirsoft-ecommerce/coupons/downloadable**', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(page1([coupon(1), coupon(2), coupon(3)])) }));

    await page.goto('/shop/coupons');
    await clearOverlays(page);
    await expect(page.locator('#coupon_zone_card_0')).toBeVisible({ timeout: 30_000 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});
