/**
 * 회원 클레임 신청 셸 — 주문 조회 404 를 주소(route.claim_number)로 가른다 (#753 X3).
 * 템플릿 sirsoft-basic (유저 화면).
 *
 * 작성 진입(`/mypage/orders/:order_number/claims/apply/:type`)의 404 는 열 수 없는 주문이라 오류 화면(404)을 세운다.
 * 수정 진입(`/mypage/claims/:claim_number/edit`)은 주소에 주문번호가 없어 첫 호출이 반드시 404 이고 그것이 정상 경로라
 * 오류 화면을 세우지 않는다. 종전에는 이 판정을 이커머스 전용 핸들러(claimApplyOrderMissing)가 했고, 코어
 * engine-v1.80.0 부터 레이아웃의 데이터소스 오류 처리 안 `<conditions>` 가 직접 한다.
 *
 * 회원 계정은 테스트 전용 토큰(권한 없음)으로 만들고, 주문·신청서 조회는 `page.route` 로 모의한다 — 실제 주문 데이터를 쓰지 않는다.
 *
 * @scenario claim-apply-order-missing
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'
 *   npx playwright test --config templates/_bundled/sirsoft-basic/tests/Playwright/playwright.config.ts claim-apply-order-missing
 */
import { test, expect, type Page } from '@playwright/test';
import { issueScopedToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

const ORDER_API = '**/api/modules/sirsoft-ecommerce/user/orders/**';
const CLAIM_API = '**/api/modules/sirsoft-ecommerce/user/claims/RT_PROBE753';

/** 주문 조회는 모두 404 로 답한다(작성 진입의 없는 주문 · 수정 진입의 첫 호출) */
async function stubOrders404(page: Page): Promise<string[]> {
  const seen: string[] = [];
  await page.route(ORDER_API, (route) => {
    seen.push(new URL(route.request().url()).pathname);
    return route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'not found' }) });
  });
  return seen;
}

/** 404 오류 화면이 떠 있는지 — 오류 레이아웃의 큰 「404」 표시로 판정(로케일 무관) */
function errorPage(page: Page) {
  return page.locator('div.text-8xl', { hasText: '404' });
}

test.describe('클레임 신청 셸 — 주문 조회 404 분기', () => {
  /**
   * @scenario entry=create
   * @effects create_404_shows_error_page
   */
  test('작성 주소의 없는 주문은 404 오류 화면을 세운다', async ({ page }) => {
    const token = issueScopedToken();
    await authenticatePage(page, token);
    const seen = await stubOrders404(page);

    await page.goto('/mypage/orders/NOPE753/claims/apply/return');

    await expect(errorPage(page)).toBeVisible({ timeout: 30_000 });
    expect(seen.some((p) => p.endsWith('/user/orders/NOPE753'))).toBe(true);
  });

  /**
   * @scenario entry=edit
   * @effects edit_first_404_is_silent
   */
  test('수정 주소의 첫 404 는 조용히 넘긴다 — 오류 화면 없이 수정 화면에 머문다', async ({ page }) => {
    const token = issueScopedToken();
    await authenticatePage(page, token);
    const seen = await stubOrders404(page);
    await page.route(CLAIM_API, (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { claim: { claim_number: 'RT_PROBE753', order_number: 'O753PROBE', type: 'return', is_editable: true, items: [] } } }),
    }));

    await page.goto('/mypage/claims/RT_PROBE753/edit');

    await expect.poll(() => seen.length, { timeout: 30_000 }).toBeGreaterThan(0);
    await page.waitForTimeout(2_000);
    await expect(errorPage(page)).toHaveCount(0);
    expect(new URL(page.url()).pathname).toBe('/mypage/claims/RT_PROBE753/edit');
  });
});
