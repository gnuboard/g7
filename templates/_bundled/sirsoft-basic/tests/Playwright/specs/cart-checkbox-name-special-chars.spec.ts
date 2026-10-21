/**
 * 장바구니 상품 체크박스 이름에 특수문자 상품명이 그대로 실린다 (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 `/shop/cart`).
 *
 * 결함: 레이아웃 문자열 번역 `$t:common.select_item|name={{item.product.name}}` 의 값이 `&`·`|`·`=` 에서 잘려,
 *   「Tom & Jerry」 상품의 체크박스 이름이 「Tom 선택」 처럼 읽혔다. 엔진 번역 인자 해석(`translationParamValues`)을 고쳤다.
 *
 * 데이터: 서버 장바구니를 쓰지 않는다 — 장바구니 키 발급(`POST cart/key`)과 장바구니 조회(`POST cart/query`)를 `page.route` 로
 *   모의하고, 그 밖의 쓰기 요청도 가로채 서버를 바꾸지 않는다. 비회원 화면이라 계정이 필요 없다.
 *
 * @scenario-manifest tests/scenarios/e2e-cart-checkbox-name-special-chars.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- cart-checkbox-name-special-chars --workers=1
 */
import { test, expect } from '@playwright/test';
import { guardWrites, waitForSpa } from '../fixtures/env-account';

/** 번역 인자 구분자(`|`·`=`)·HTML 엔티티 문자(`&`)·치환 패턴(`$&`·`$'`)·중괄호를 모두 담은 상품명 */
const TRICKY_NAMES = ["Tom & Jerry | a=b $& $' {{x}} / 끝", '바지&셔츠 = 세트|2'];

/** 모의 장바구니 키 — 서버 형식(`ck_` + 영숫자 32자)과 같게 둔다 */
const FAKE_CART_KEY = 'ck_e2ef01e2ef01e2ef01e2ef01e2ef01ab';

/**
 * 장바구니 조회 모의 응답을 만든다.
 *
 * @param names 상품명 목록
 * @returns cart/query 응답 본문
 */
function cartBody(names: string[]) {
  const items = names.map((name, i) => ({
    id: 9100 + i,
    quantity: 1,
    product_option_id: 9200 + i,
    available: true,
    product: { id: 9300 + i, name, sales_status: 'on_sale', thumbnail_url: null },
    product_option: { id: 9200 + i, option_name: '기본', price: 1000, stock_quantity: 10 },
  }));

  return {
    success: true,
    data: { items, item_ids: items.map((it) => it.id), item_count: items.length, calculation: null },
  };
}

test.describe('장바구니 체크박스 이름 — 특수문자 상품명', () => {
  /**
   * @scenario case=guest_cart_mocked
   * @effects cart_checkbox_name_keeps_special_chars, cart_checkbox_name_not_truncated, cart_e2e_no_server_write
   */
  test('체크박스 접근 이름이 상품명 전체 + 「선택」 이다', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (m) => {
      if (m.type() === 'error') consoleErrors.push(m.text());
    });
    const blocked = await guardWrites(page);
    await page.addInitScript((key) => localStorage.setItem('g7_cart_key', key), FAKE_CART_KEY);
    await page.route('**/api/modules/sirsoft-ecommerce/cart/key', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_key: FAKE_CART_KEY } }) }),
    );
    let cartQueries = 0;
    await page.route('**/api/modules/sirsoft-ecommerce/cart/query**', (route) => {
      cartQueries += 1;

      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cartBody(TRICKY_NAMES)) });
    });

    await page.goto('/shop/cart');
    await waitForSpa(page);
    await expect.poll(() => cartQueries, { message: '장바구니 조회가 모의 응답을 탔다', timeout: 30_000 }).toBeGreaterThan(0);

    for (const name of TRICKY_NAMES) {
      await expect(page.getByRole('checkbox', { name: `${name} 선택`, exact: true })).toHaveCount(1, { timeout: 20_000 });
    }
    // 잘린 이름(첫 구분자 앞까지)으로 읽히는 체크박스가 없어야 한다
    await expect(page.getByRole('checkbox', { name: 'Tom 선택', exact: true })).toHaveCount(0);
    await expect(page.getByRole('checkbox', { name: '바지 선택', exact: true })).toHaveCount(0);
    // 쓰기 요청(분석 수집 등)은 guardWrites 가 서버에 닿기 전에 가짜 200 으로 답했다 — 서버 상태 불변
    expect(Array.isArray(blocked)).toBe(true);
    expect(consoleErrors.filter((t) => /translation|번역|TypeError/i.test(t)), '번역·타입 오류 콘솔 없음').toEqual([]);
  });
});
