/**
 * 클레임 신청서 진입 — `orders/0/claimable-options` 요청이 나가지 않는다 (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 `/mypage/orders/{주문번호}/claims/apply/{유형}`).
 *
 * 결함: 주소에 `?order_id` 가 없는 진입(주소 직접 입력·수정 주소)에서 claimable 데이터 소스가 주문 ID 를 모른 채 먼저
 *   `GET /user/orders/0/claimable-options` 를 불러 404 가 한 번 더 나갔다. 같은 id 의 데이터 소스 두 판을 배타 조건으로 두고, 식별자가
 *   없는 판은 order 응답이 주문 ID 를 세운 뒤에 부른다(레이아웃 정적 계약은 `__tests__/layouts/claim-apply-claimable-first-fetch.test.ts`).
 *
 * 데이터: 주문 상세·클레임 가능 상품 응답을 `page.route` 로 모의한다(실제 주문 불필요). 쓰기 요청은 가로챈다. 계정은 실행 환경변수로만.
 *
 * @scenario-manifest tests/scenarios/e2e-claim-apply-no-zero-claimable-request.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- claim-apply-no-zero-claimable-request --workers=1
 */
import { test, expect, type Page } from '@playwright/test';
import {
  ACCOUNT_SKIP_REASON,
  authenticateWith,
  envAccountToken,
  errorPage404,
  guardWrites,
  hasEnvAccount,
  waitForSpa,
} from '../fixtures/env-account';

test.describe.configure({ timeout: 90_000 });

const ORDER_NUMBER = 'E2EF17ORDER';
const ORDER_ID = 424242;

/**
 * 주문 상세·클레임 가능 상품을 모의하고 claimable 요청 경로를 기록한다.
 *
 * @param page Playwright 페이지
 * @returns claimable-options 요청 경로 목록
 */
async function stubOrderAndClaimable(page: Page): Promise<string[]> {
  const claimable: string[] = [];
  await page.route(
    (url) => url.pathname === `/api/modules/sirsoft-ecommerce/user/orders/${ORDER_NUMBER}`,
    (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { id: ORDER_ID, order_number: ORDER_NUMBER, options: [], abilities: {} } }),
    }),
  );
  await page.route(
    (url) => /^\/api\/modules\/sirsoft-ecommerce\/user\/orders\/[^/]+\/claimable-options$/.test(url.pathname),
    (route) => {
      const path = new URL(route.request().url()).pathname;
      claimable.push(path);
      if (path.includes('/orders/0/')) {
        return route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'not found' }) });
      }

      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { order_id: ORDER_ID, order_number: ORDER_NUMBER, items: [], claim_types: ['return'], reasons: [] } }),
      });
    },
  );

  return claimable;
}

test.describe('클레임 신청서 claimable 첫 호출', () => {
  test.skip(!hasEnvAccount(), ACCOUNT_SKIP_REASON);

  /**
   * @scenario case=address_without_order_id
   * @effects claimable_called_with_real_order_id, no_zero_order_claimable_request
   */
  test('주소 직접 입력(?order_id 없음) — order 응답 뒤 주문 ID 로 한 번 부르고 orders/0 요청은 없다', async ({ page, request }) => {
    const token = await envAccountToken(request);
    await authenticateWith(page, token!);
    await guardWrites(page);
    const claimable = await stubOrderAndClaimable(page);

    await page.goto(`/mypage/orders/${ORDER_NUMBER}/claims/apply/return`);
    await waitForSpa(page);

    await expect.poll(() => claimable.length, { message: 'claimable 요청이 나갔다', timeout: 30_000 }).toBeGreaterThan(0);
    await page.waitForTimeout(2_000);
    expect(claimable.filter((p) => p.includes('/orders/0/')), 'orders/0/claimable-options 요청').toEqual([]);
    expect(claimable.filter((p) => p.endsWith(`/orders/${ORDER_ID}/claimable-options`)).length, '주문 ID 로 부른 횟수').toBeGreaterThanOrEqual(1);
    await expect(errorPage404(page)).toHaveCount(0);
  });

  /**
   * @scenario case=address_with_order_id
   * @effects claimable_called_with_real_order_id, no_zero_order_claimable_request
   */
  test('주문 상세에서 온 진입(?order_id 있음) — 곧바로 그 ID 로 부르고 orders/0 요청은 없다', async ({ page, request }) => {
    const token = await envAccountToken(request);
    await authenticateWith(page, token!);
    await guardWrites(page);
    const claimable = await stubOrderAndClaimable(page);

    await page.goto(`/mypage/orders/${ORDER_NUMBER}/claims/apply/return?order_id=${ORDER_ID}`);
    await waitForSpa(page);

    await expect.poll(() => claimable.length, { message: 'claimable 요청이 나갔다', timeout: 30_000 }).toBeGreaterThan(0);
    await page.waitForTimeout(2_000);
    expect(claimable.filter((p) => p.includes('/orders/0/')), 'orders/0/claimable-options 요청').toEqual([]);
    expect(claimable[0], '첫 요청이 주문 ID').toBe(`/api/modules/sirsoft-ecommerce/user/orders/${ORDER_ID}/claimable-options`);
  });
});
