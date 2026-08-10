/**
 * 상품상세 담기/바로구매가 메인 옵션을 product_option_id(옵션 ID)로 전송하는지 검증하는 유저 흐름 E2E.
 * 템플릿 sirsoft-basic (유저 화면). (skeleton, placeholder)
 *
 * @scenario cart-add-option-id
 * @effects add_to_cart_sends_product_option_id,
 *          buy_now_direct_items_sends_product_option_id,
 *          cart_reflects_exact_selected_option,
 *          foreign_product_option_id_rejected
 *
 * 배경: 담기/바로구매가 메인 옵션을 로케일 값 조합(option_values)으로 전송하고 서버가
 *       getLocalizedOptionValues() 문자열 동등비교로 역매칭하던 방식은, 클라·서버 로케일
 *       불일치나 관리자 옵션 값 텍스트 수정 시 매칭 실패(option_values_not_found)를 유발했다.
 *       옵션 식별을 product_option_id 기반으로 전환해(클라가 이미 보유한 SelectedItem.optionId
 *       전송), 서버는 요청 상품(product_id)의 옵션 집합 내에서 ID 로만 조회한다.
 *
 * e2e:allow 유저 담기/바로구매 흐름 — data-testid 보강 + 실 도메인 옵션 상품 시드 후 활성화한다.
 *           현재 커버리지:
 *           (1) 레이아웃 payload 구조 — templates/_bundled/sirsoft-basic/src/__tests__/layouts/
 *               shop-buy-now-direct-checkout.test.tsx ("direct_items 가 product_option_id: item.optionId
 *               전송, option_values 미사용"), shopAdditionalOptions.test.tsx ("담기/구매 body 가
 *               product_option_id 전송, option_values 미사용") green.
 *           (2) 백엔드 계약 — modules/_bundled/sirsoft-ecommerce PHPUnit:
 *               CartControllerTest("다른 상품 product_option_id 주입 거부", 일괄 담기/기본옵션),
 *               CheckoutControllerTest(direct_items), CartServiceTest/TempOrderServiceTest green.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다:
 *   1. 옵션 2조합(예: 빨강/L · 파랑/M) 보유 상품 시드 + PRODUCT_URL 확정
 *   2. _purchase_card 옵션 Select 에 data-testid="option-group-{idx}", 담기/바로구매 버튼 data-testid
 *   3. _cart_item 옵션 라벨에 data-testid
 *   4. PLAYWRIGHT_BASE_URL = 실 도메인, test.describe.skip → test.describe
 *
 * 매트릭스:
 *   T1 옵션 상품 담기 → POST /cart body.items[].product_option_id 가 선택 옵션 ID 와 일치, option_values 미포함
 *   T2 바로구매 → POST /checkout direct_items[].product_option_id 전송 → 체크아웃 진입
 *   T3 담은 뒤 장바구니에 "정확히 선택한 옵션"(빨강/L)이 반영 (로케일/텍스트 변경과 무관)
 *   T4 위변조: 타 상품 옵션 ID 주입 → 서버 거부(장바구니 미생성)
 */
import { test, expect } from '@playwright/test';

// 옵션 조합 보유 시드 상품 상세 (실 도메인 시드 후 경로 확정)
const PRODUCT_URL = '/shop/products/{OPTION_PRODUCT_ID}';

test.describe.skip('유저 담기/바로구매 옵션 ID 전송 (placeholder — data-testid 보강 + 시드 후 활성화)', () => {
  test('T1 옵션 상품 담기 요청이 product_option_id 를 전송한다 (option_values 미사용)', async ({ page }) => {
    await page.goto(PRODUCT_URL);
    await page.getByTestId('option-group-0').selectOption({ index: 1 });

    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/cart') && r.method() === 'POST'),
      page.getByTestId('add-to-cart').click(),
    ]);
    const body = request.postDataJSON();
    expect(body.items?.[0]?.product_option_id).toEqual(expect.any(Number));
    expect(body.items?.[0]).not.toHaveProperty('option_values');
  });

  test('T2 바로구매가 direct_items.product_option_id 로 체크아웃한다', async ({ page }) => {
    await page.goto(PRODUCT_URL);
    await page.getByTestId('option-group-0').selectOption({ index: 1 });

    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/checkout') && r.method() === 'POST'),
      page.getByTestId('buy-now').click(),
    ]);
    const body = request.postDataJSON();
    expect(body.direct_items?.[0]?.product_option_id).toEqual(expect.any(Number));
    expect(body.direct_items?.[0]).not.toHaveProperty('option_values');
  });

  test('T3 담은 뒤 장바구니에 정확히 선택한 옵션이 반영된다', async ({ page }) => {
    await page.goto(PRODUCT_URL);
    await page.getByTestId('option-group-0').selectOption({ label: /빨강/ });
    await page.getByTestId('add-to-cart').click();
    await page.goto('/shop/cart');
    await expect(page.getByTestId('cart-item').first()).toContainText(/빨강/);
  });
});
