/**
 * 마이페이지 쿠폰함 — 카드에 발급 경로·남은 날이 보이고, 빈 목록이면 쿠폰존 링크로 SPA 이동한다 (#952 C)
 *
 * 쿠폰함 응답은 page.route 로 고정한다. 서버 계약(쿠폰 블록·상태 필터)은 PHPUnit UserCouponBoxResponseTest.
 *
 * 소속 시나리오(마커 아님 — 평문): mypage-coupons
 * 효과 요약(마커 아님 — 평문): coupon_box_card_block, coupon_box_empty_links_zone
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

const box = (items: unknown[]) => ({
  success: true,
  message: 'ok',
  data: { coupons: { data: items, pagination: { current_page: 1, last_page: 1, per_page: 10, total: items.length, has_more_pages: false } } },
});

const issue = {
  id: 7,
  coupon_code: 'AU-I952E2E',
  status: 'available',
  issue_source: 'signup',
  issue_source_label: '가입',
  issued_at: '2026-11-20T00:00:00+00:00',
  expired_at: '2099-12-31T14:59:59+00:00',
  expires_in_days: 5,
  coupon: {
    id: 3,
    localized_name: 'i952 가입 쿠폰',
    localized_description: '',
    target_type: 'order_amount',
    target_type_label: '주문금액',
    target_scope: 'all',
    target_scope_label: '전체',
    discount_type: 'fixed',
    discount_value: 3000,
    benefit_formatted: '3,000원 할인',
    min_order_amount: 0,
    min_order_amount_formatted: '0원',
    is_combinable: false,
    included_product_names: [],
    included_product_count: 0,
    included_category_names: [],
    included_category_count: 0,
    valid_period_formatted: '발급일로부터 30일',
  },
};

test.describe('마이페이지 쿠폰함 (#952)', () => {
  // @scenario case=mypage_coupons_browser
  // @effects coupon_box_card_block
  test('카드에 쿠폰명·발급 경로·남은 날이 보인다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    await page.route('**/api/modules/sirsoft-ecommerce/user/coupons?**', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(box([issue])) }));
    await page.route('**/api/modules/sirsoft-ecommerce/user/coupons', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(box([issue])) }));

    await page.goto('/mypage/coupons');
    await clearOverlays(page);
    const card = page.locator('#coupon_card_0');
    await expect(card).toBeVisible({ timeout: 30_000 });
    await expect(card).toContainText('i952 가입 쿠폰');
    await expect(card).toContainText('가입');
    await expect(page.locator('#coupon_dday_0')).toBeVisible();
  });

  // @scenario case=mypage_coupons_empty_browser
  // @effects coupon_box_empty_links_zone
  test('빈 쿠폰함의 쿠폰존 링크를 누르면 새로고침 없이 쿠폰존으로 간다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    await page.route('**/api/modules/sirsoft-ecommerce/user/coupons**', (route) => {
      if (route.request().url().includes('/downloadable')) return route.continue();

      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(box([])) });
    });

    await page.goto('/mypage/coupons');
    await clearOverlays(page);
    const link = page.locator('#coupon_zone_link');
    await expect(link).toBeVisible({ timeout: 30_000 });
    await page.evaluate(() => {
      (window as unknown as { __i952?: string }).__i952 = 'same-document';
    });
    await link.click();
    await page.waitForURL(/\/coupons$/, { timeout: 30_000 });
    // 같은 문서에서 이동했다(전체 새로고침이면 표식이 사라진다)
    expect(await page.evaluate(() => (window as unknown as { __i952?: string }).__i952)).toBe('same-document');
  });
});
