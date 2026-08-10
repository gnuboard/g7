/**
 * 추가옵션 추가금의 표시통화 환산 — 기본통화 ≠ 표시통화 쇼핑몰에서 추가금이
 * 상품가와 같은 통화로 표시되고 합계에도 환산되어 더해지는지 검증.
 * 템플릿 sirsoft-basic (유저 화면). (skeleton, placeholder)
 *
 * @scenario currency-symbol-display
 * @effects additional_option_adjustment_uses_configured_symbol,
 *          detail_total_converts_additional_option_amount,
 *          cart_line_additional_option_uses_display_currency,
 *          checkout_line_additional_option_uses_display_currency
 *
 * 배경(회귀): 추가옵션 추가금은 쇼핑몰 기본 통화 기준으로 저장되는데, 서버가 그 값을
 * 통화별로 환산해 주지 않았고 화면 합계 계산은 그 값을 KRW 로 간주했다. 기본통화가 KRW 인
 * 쇼핑몰에서만 우연히 맞았고, 기본통화 JPY + 표시통화 KRW 인 쇼핑몰에서는
 * "환산된 상품가 + 환산 안 된 추가금" 이라는 통화가 섞인 합계가 나왔다
 * (상품가 ₩114,000 + 추가금 ¥5,000 → ₩119,000 표기).
 *
 * e2e:allow 재현에 쇼핑몰 전역 통화 설정(기본통화를 KRW 가 아닌 통화로 전환 + 환율 지정)이
 *           필요해 공용 개발 사이트를 흔들지 않고는 자동화할 수 없다. 통화 설정 시드가
 *           갖춰지기 전까지는 아래 커버리지로 대체한다.
 *           (1) 서버 계약 — tests/Unit/Resources/AdditionalOptionMultiCurrencyTest.php
 *               (상품상세·장바구니 응답의 multi_currency_price_adjustment, 음수 추가금 부호 유지) green.
 *           (2) 합계 계산 — templates/_bundled/sirsoft-basic/src/handlers/__tests__/
 *               productOptionsAdditionalCurrency.test.ts
 *               (표시통화 합계에 환산 추가금 가산, 수량 배수, 미선택 0, 항목 생성 시 통화별 합계 적재) green.
 *           (3) 폴백 — productOptionsAdditional.test.ts 의 기존 케이스가 서버 맵이 없는
 *               응답에서 기본통화 피봇 환산으로 종전 값을 유지함을 고정 green.
 *           라이브 검수는 Chrome MCP 실측으로 기록됨 — 기본통화 JPY / 표시통화 KRW 상태에서
 *           수정 전 ₩119,000·"+¥3,000" → 수정 후 ₩142,500·"+28,500원" 확인.
 *
 * 활성화 조건:
 *   1. 기본통화가 KRW 가 아닌 쇼핑몰 통화 설정 시드(예: JPY 기본 + KRW 환율)
 *   2. _purchase_card 추가옵션 Select / 추가옵션 금액 라인에 data-testid
 *   3. _cart_item·_checkout_items 추가옵션 라인에 data-testid
 *   4. PLAYWRIGHT_BASE_URL = 실 도메인, test.describe.skip → test.describe
 *
 * 매트릭스:
 *   T1 상품상세 선택지 라벨 — 표시통화로 환산 표기 (기준통화 기호가 남지 않는다)
 *   T2 상품상세 "추가옵션 금액" + 총 금액 — 상품가와 같은 통화로 합산
 *   T3 장바구니 줄 — 추가옵션 라벨이 그 줄의 상품가·소계와 같은 통화
 *   T4 주문서 줄 — 동일
 *   T5 통화 전환 — 표시통화를 바꾸면 추가금 표기·합계가 함께 따라간다
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

// 추가옵션 보유 시드 상품 상세 (실 도메인 시드 후 경로 확정)
const PRODUCT_PATH = '/shop/products/28';

test.describe.skip('추가옵션 추가금 표시통화 환산 (기본통화 ≠ 표시통화, skeleton)', () => {
  test('T1/T2 상품상세: 추가옵션 라벨과 총 금액이 표시통화로 환산된다', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto(PRODUCT_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 기본옵션 선택 → 추가옵션 블럭 노출
    await page.getByTestId('option-select-0').selectOption({ index: 1 });
    await page.getByTestId('option-select-1').selectOption({ index: 1 });

    // 추가옵션 선택지 라벨에 기준통화 기호(¥)가 남아 있으면 안 된다
    const addOption = page.getByTestId('add-option-0-10');
    await expect(addOption).toBeVisible();
    await expect(addOption).not.toContainText('¥');

    await addOption.selectOption({ label: /선물 포장/ });

    // 추가옵션 금액 라인과 총 금액이 같은 통화(표시통화)로 표기된다
    const additionalAmount = page.getByTestId('additional-options-amount');
    await expect(additionalAmount).toContainText('원');

    const total = page.getByTestId('purchase-total-amount');
    await expect(total).toContainText('₩');
  });

  test('T3 장바구니: 추가옵션 라벨이 그 줄의 소계와 같은 통화로 표기된다', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/cart');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const line = page.getByTestId('cart-item-additional-option').first();
    await expect(line).toContainText('원');
    await expect(line).not.toContainText('¥');
  });

  test('T4 주문서: 추가옵션 라벨이 표시통화로 표기된다', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/checkout');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const line = page.getByTestId('checkout-item-additional-option').first();
    await expect(line).toContainText('원');
    await expect(line).not.toContainText('¥');
  });

  test('T5 통화 전환: 표시통화를 바꾸면 추가금 표기와 합계가 함께 따라간다', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto(PRODUCT_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.getByTestId('option-select-0').selectOption({ index: 1 });
    await page.getByTestId('option-select-1').selectOption({ index: 1 });
    await page.getByTestId('add-option-0-10').selectOption({ label: /선물 포장/ });

    const before = await page.getByTestId('additional-options-amount').innerText();

    await page.getByTestId('currency-switcher').click();
    await page.getByRole('option', { name: 'USD' }).click();

    await expect(page.getByTestId('additional-options-amount')).not.toHaveText(before);
    await expect(page.getByTestId('additional-options-amount')).toContainText('$');
  });
});
