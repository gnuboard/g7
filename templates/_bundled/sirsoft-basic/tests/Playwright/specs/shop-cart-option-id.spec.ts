/**
 * 상품상세 담기/바로구매가 메인 옵션을 product_option_id(옵션 ID)로 전송하는지 검증하는 유저 흐름 E2E.
 * 템플릿 sirsoft-basic (유저 화면).
 *
 * @scenario cart-add-option-id
 * 효과 요약(마커 아님 — 평문): 담기와 바로구매의 product_option_id 전송, 장바구니에 선택 옵션 반영
 *   — 효과 이름은 T1~T3 시험마다 마킹. 다른 상품의 옵션 ID 거부는 서버 검증이라 이 spec 이 재지 않는다.
 *
 * 배경: 담기/바로구매가 메인 옵션을 로케일 값 조합(option_values)으로 전송하고 서버가
 *       getLocalizedOptionValues() 문자열 동등비교로 역매칭하던 방식은, 클라·서버 로케일
 *       불일치나 관리자 옵션 값 텍스트 수정 시 매칭 실패(option_values_not_found)를 유발했다.
 *       옵션 식별을 product_option_id 기반으로 전환해(클라가 이미 보유한 SelectedItem.optionId
 *       전송), 서버는 요청 상품(product_id)의 옵션 집합 내에서 ID 로만 조회한다.
 *
 *       engine-v1.63.5 (트러블슈팅 사례 42) 이후로는 이 spec 이 **이중 저장소 미러**의 종단
 *       회귀 핀도 겸한다 — 옵션 선택은 템플릿 커스텀 핸들러가 `context.setState` 로 쓰고,
 *       그 값이 저장소 B 에 닿지 않으면 요청 body 의 배열이 **빈 채로** 나간다. 화면에는 담긴
 *       항목이 그대로 보이고 콘솔 에러도 없으므로, 요청 body 를 보는 이 spec 이 유일한 통로다.
 *
 * 대상 상품은 spec 이 이커머스 관리자 API 로 만들고 끝나면 지운다(fixtures/option-product-seed.ts).
 * 종전에는 공개 상품 목록 앞쪽에서 옵션 2개 이상인 상품을 찾았는데, 공유 개발 DB 의 목록 앞쪽을 다른 작업의
 * 시험 상품이 차지하면 재고 0 상품이 골려 담기가 거부되거나 상세 조회만으로 시간이 다 됐다(실측).
 *
 * 이 템플릿의 Select 는 `options` 가 있으면 네이티브 `<select>` 가 아니라
 * `button[role=option]` 커스텀 드롭다운을 렌더한다 — `selectOption()` 은 동작하지 않는다.
 *
 * 매트릭스:
 *   T1 옵션 상품 담기 → POST /cart body.items[].product_option_id 가 선택 옵션 ID 와 일치, option_values 미포함
 *   T2 바로구매 → POST /checkout direct_items[].product_option_id 전송
 *   T3 담은 뒤 장바구니에 "정확히 선택한 옵션"이 반영 (로케일/텍스트 변경과 무관)
 */
import { test, expect, Page, type APIRequestContext } from '@playwright/test';

import { issueToken } from '../../../../../../tests/Playwright/fixtures/auth';
import { seedOptionProduct, deleteSeededProduct, type SeededOptionProduct } from '../fixtures/option-product-seed';

/**
 * 커스텀 드롭다운 Select 에서 라벨로 항목을 고른다.
 *
 * 이 템플릿의 Select 는 트리거 버튼 + 포털로 띄운 `role=listbox` 목록이다.
 * `data-testid` 는 감싸는 Div 에 붙고, 목록은 포털이라 그 Div 바깥에 렌더된다.
 *
 * @param page Playwright 페이지
 * @param testId Select 래퍼의 data-testid
 * @param label 고를 항목 라벨
 * @return 없음
 */
async function pickOption(page: Page, testId: string, label: string): Promise<void> {
  await page.getByTestId(testId).getByRole('button').first().click();
  await page.getByRole('option', { name: label, exact: true }).click();
}

/**
 * 메인 옵션 그룹을 순서대로 전부 고른다.
 *
 * 하위 그룹 Select 는 상위 그룹이 선택될 때까지 disabled 이고, **모든** 그룹이 선택돼야
 * 선택 블럭이 만들어진다.
 *
 * @param page Playwright 페이지
 * @param values 그룹 순서대로의 값 라벨
 * @return 없음
 */
async function pickAllMainOptions(page: Page, values: string[]): Promise<void> {
  for (let i = 0; i < values.length; i++) {
    await pickOption(page, `option-group-${i}`, values[i]);
  }
}

test.describe('유저 담기/바로구매 옵션 ID 전송', () => {
  // 세 시험 모두 상품 화면 하이드레이션 → 옵션 선택 → 담기(바로구매)를 거치고, beforeAll 은 시험 상품 생성을 맡는다.
  // 실측: 옵션 칸 클릭 가능까지 9.2초, 상품 생성 POST 최대 17초 — 기본 30초로는 공유 개발 서버에서 여유가 없다.
  test.describe.configure({ timeout: 90_000 });

  /** 시험 상품을 만들고 지우는 관리자 요청 문맥 — 페이지는 비회원 그대로 둔다 */
  let api: APIRequestContext;
  let seedToken = '';
  let product: SeededOptionProduct;

  // 상품 생성 POST 는 공유 개발 서버에서 실측 17초까지 걸려 세 시험이 한 상품을 같이 쓴다.
  // 담기·바로구매는 재고를 줄이지 않고(재고 999) 장바구니 행은 상품 삭제 시 함께 지워진다.
  // beforeAll 은 시험 단위 `request` 를 받지 못하므로 config 의 baseURL 로 요청 문맥을 직접 연다.
  test.beforeAll(async ({ playwright }, testInfo) => {
    api = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL, ignoreHTTPSErrors: true });
    seedToken = issueToken();
    product = await seedOptionProduct(api, seedToken);
  });

  test.afterAll(async () => {
    if (product) await deleteSeededProduct(api, seedToken, product.id);
    await api?.dispose();
  });

  // @scenario case=add_to_cart
  // @effects add_to_cart_sends_product_option_id
  test('T1 옵션 상품 담기 요청이 product_option_id 를 전송한다 (option_values 미사용)', async ({ page }) => {
    await page.goto(product.url);
    await pickAllMainOptions(page, product.mainValues);

    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/cart') && r.method() === 'POST'),
      page.getByTestId('add-to-cart').click(),
    ]);
    const body = request.postDataJSON();
    expect(body.items?.length, '선택한 옵션이 요청 body 에 실려야 한다').toBeGreaterThan(0);
    expect(body.items?.[0]?.product_option_id).toEqual(expect.any(Number));
    expect(body.items?.[0]).not.toHaveProperty('option_values');
  });

  // @scenario case=buy_now
  // @effects buy_now_direct_items_sends_product_option_id
  test('T2 바로구매가 direct_items.product_option_id 로 체크아웃한다', async ({ page }) => {
    await page.goto(product.url);
    await pickAllMainOptions(page, product.mainValues);

    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/checkout') && r.method() === 'POST'),
      page.getByTestId('buy-now').click(),
    ]);
    const body = request.postDataJSON();
    expect(body.direct_items?.length, '선택한 옵션이 요청 body 에 실려야 한다').toBeGreaterThan(0);
    expect(body.direct_items?.[0]?.product_option_id).toEqual(expect.any(Number));
    expect(body.direct_items?.[0]).not.toHaveProperty('option_values');
  });

  // @scenario case=cart_reflects_option
  // @effects cart_reflects_exact_selected_option
  test('T3 담은 뒤 장바구니에 정확히 선택한 옵션이 반영된다', async ({ page }) => {
    await page.goto(product.url);
    await pickAllMainOptions(page, product.mainValues);

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/cart') && r.request().method() === 'POST'),
      page.getByTestId('add-to-cart').click(),
    ]);
    expect(response.status(), '담기가 성공해야 장바구니를 확인할 수 있다').toBeLessThan(300);

    await page.goto('/shop/cart');
    // 장바구니 목록은 화면 하이드레이션 뒤 조회 응답으로 그려진다(실측 약 8초) — 기본 단언 제한 5초로는 항목이 서기 전에 끝난다.
    await expect(page.getByTestId('cart-item').first()).toContainText(product.mainValues[0], { timeout: 20000 });
  });
});
