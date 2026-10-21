/**
 * 쿠폰 받기 — 정액 할인·최소 주문 금액이 고른 통화로 보인다 (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 상품 상세 쿠폰 칩 → 받기 확인 창, 회원) + 이커머스 API `GET user/coupons/downloadable`.
 *
 * 결함(출시 전 실측): ① 상품 상세 받기 확인 창의 큰 글자가 기본 통화 문구(`benefit_formatted`)만 읽어, USD 로 보는 회원에게
 *   칩은 「$0.75 할인」 인데 확인 창은 「1,000원 할인」 이었다. ② 주문서 「쿠폰 다운로드」 창 목록 API 가 통화 맵
 *   (`multi_currency_discount_value`·`multi_currency_min_order_amount`)을 싣지 않아 창이 늘 기본 통화 숫자로 떨어졌다.
 *   고침: 확인 창은 `multi_currency_benefit_formatted[선택 통화]` 를 먼저 읽고, API 는 항목마다 통화 맵을 싣는다.
 *
 * 데이터: ① 은 상품 하나의 받을 수 있는 쿠폰 응답(`GET products/{코드}/downloadable-coupons`)을 이 브라우저에서 정액 쿠폰 1건으로
 *   모의하고, 현재 회원 응답(`GET /api/auth/user`)의 `ecommerce_preferred_currency` 를 이 브라우저에서만 USD 로 바꾼다 — 서버의 계정
 *   선호 통화는 바꾸지 않는다. 화면 전역 상태를 직접 USD 로 쓰면 늦게 오는 회원 응답이 계정 통화(1순위,
 *   `sirsoft-ecommerce.initPreferredCurrency`)로 되덮어 시점에 따라 갈린다(2026-10-18 첫 실행 실측). 「받기」 는 누르지 않는다.
 *   ② 는 그 계정 토큰으로 목록 API 를 GET 해 응답 모양만 본다. 쓰기 요청은 가로챈다. 계정은 실행 환경변수로만 받는다.
 *
 * @scenario-manifest tests/scenarios/e2e-coupon-download-multi-currency.yaml
 *
 * 실행(계정 필요):
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; $env:G7_E2E_TOKEN='<일회용 토큰>'
 *   npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/coupon-download-multi-currency.spec.ts --workers=1 --trace=off
 */
import { test, expect } from '@playwright/test';
import {
  ACCOUNT_SKIP_REASON,
  authenticateWith,
  dismissCookieBanner,
  envAccountToken,
  guardWrites,
  hasEnvAccount,
  waitForSpa,
} from '../fixtures/env-account';

test.describe.configure({ timeout: 120_000 });

/** 모의 정액 쿠폰 — 통화별 혜택 문구 */
const FAKE_COUPON = {
  id: 990301,
  coupon_id: 990301,
  localized_name: 'E2E 정액 쿠폰',
  benefit_formatted: '1,000원 할인',
  multi_currency_benefit_formatted: { KRW: '1,000원 할인', USD: '$0.75 할인' },
  multi_currency_min_order_amount: { KRW: { price: 10000, formatted: '10,000원' }, USD: { price: 7.5, formatted: '$7.50' } },
  is_downloaded: false,
};

test.describe('쿠폰 받기 다통화 표시', () => {
  test.skip(!hasEnvAccount(), ACCOUNT_SKIP_REASON);

  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  /**
   * @scenario case=product_detail_confirm_modal_usd
   * @effects confirm_modal_benefit_in_selected_currency, chip_and_modal_same_text
   */
  test('USD 로 볼 때 상품 상세 쿠폰 칩과 받기 확인 창이 같은 「$0.75 할인」 이다', async ({ page, request }) => {
    const token = (await envAccountToken(request))!;
    const list = await request.get('/api/modules/sirsoft-ecommerce/products?per_page=5', { headers: { Accept: 'application/json' } });
    const code = ((await list.json())?.data?.data ?? []).map((p: { product_code?: string }) => p.product_code).find(Boolean) as string | undefined;
    test.skip(!code, '공개 상품이 없어 잴 수 없다');

    await authenticateWith(page, token);
    const blocked = await guardWrites(page);
    // 계정 선호 통화 = USD 인 회원으로 보이게(이 브라우저에서만) — 회원 응답이 오면 initPreferredCurrency 가 1순위로 적용한다
    await page.route(
      (url) => url.pathname === '/api/auth/user',
      async (route) => {
        if (route.request().method() !== 'GET') return route.fallback();
        const res = await route.fetch();
        const body = await res.json();
        if (body?.data && typeof body.data === 'object') body.data.ecommerce_preferred_currency = 'USD';

        return route.fulfill({ response: res, json: body });
      },
    );
    await page.route(
      (url) => url.pathname === `/api/modules/sirsoft-ecommerce/products/${code}/downloadable-coupons`,
      (route) =>
        route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { data: [FAKE_COUPON] } }) }),
    );
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/shop/products/${code}`);
    await waitForSpa(page);
    await dismissCookieBanner(page);
    // 회원 응답이 와서 계정 통화가 적용될 때까지 — 로그인 회원·표시 통화 USD
    await expect.poll(() => page.evaluate(() => (window as any).G7Core.state.get()?.currentUser?.uuid ?? null), { message: '회원 응답', timeout: 30_000 }).not.toBeNull();
    await expect.poll(() => page.evaluate(() => (window as any).G7Core.state.get()?.preferredCurrency), { message: '표시 통화 USD', timeout: 30_000 }).toBe('USD');
    const chip = page.getByRole('button', { name: /\$0\.75 할인/ }).first();
    await expect(chip).toBeVisible({ timeout: 10_000 });
    await chip.click();

    const modal = page.getByRole('dialog', { name: '쿠폰 다운로드' });
    await expect(modal).toBeVisible();
    await expect(modal).toContainText('$0.75 할인');
    await expect(modal).not.toContainText('1,000원 할인');
    await expect(modal).toContainText(FAKE_COUPON.localized_name);
    expect(await page.evaluate(() => (window as any).G7Core.state.get()?.preferredCurrency), '창을 보는 동안 표시 통화가 USD 그대로').toBe('USD');
    await modal.getByRole('button', { name: '취소', exact: true }).click();
    expect(blocked.filter((b) => b.includes('/download')), '받기 요청 없음').toEqual([]);
  });

  /**
   * @scenario case=checkout_downloadable_api_shape
   * @effects downloadable_api_has_currency_maps
   */
  test('주문서 쿠폰 창 목록 API 항목마다 통화 맵 칸이 있다', async ({ request }) => {
    const token = (await envAccountToken(request))!;
    const res = await request.get('/api/modules/sirsoft-ecommerce/user/coupons/downloadable?page=1&per_page=8', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    expect(res.status(), '목록 API').toBe(200);
    const body = await res.json();
    const items = (body?.data?.data ?? body?.data ?? []) as Array<Record<string, unknown>>;
    test.skip(!Array.isArray(items) || items.length === 0, '그 계정이 받을 수 있는 쿠폰이 없어 응답 모양을 볼 수 없다');

    for (const it of items) {
      for (const key of ['multi_currency_benefit_formatted', 'multi_currency_discount_value', 'multi_currency_min_order_amount']) {
        expect(Object.prototype.hasOwnProperty.call(it, key), `항목 ${String(it.id ?? '?')} 의 ${key}`).toBe(true);
      }
    }
    // 정액 쿠폰이 있으면 통화 맵이 객체로 온다(정률은 null)
    const fixed = items.filter((it) => it.multi_currency_discount_value !== null);
    for (const it of fixed) {
      expect(typeof it.multi_currency_discount_value, `정액 항목 ${String(it.id ?? '?')} 통화 맵`).toBe('object');
    }
  });
});
