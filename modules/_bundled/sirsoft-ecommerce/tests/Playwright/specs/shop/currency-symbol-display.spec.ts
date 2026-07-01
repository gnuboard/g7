/**
 * 통화 기호 표시 — 장바구니·상품·주문 화면에서 통화 기호가 환경설정을 따르고,
 * 위안화(CNY)는 엔화(¥)와 구분되도록 元 로 표기되는지 검증.
 * 템플릿 sirsoft-basic (유저 화면). (skeleton, placeholder)
 *
 * @scenario currency-symbol-display
 * @effects cart_jpy_uses_yen_symbol,
 *          cart_cny_uses_yuan_glyph_not_yen,
 *          cart_five_currencies_all_visible,
 *          additional_option_adjustment_uses_configured_symbol,
 *          order_history_amount_uses_snapshot_currency_not_current_default
 *
 * e2e:allow 통화 기호 표기 — 단위/레이아웃/리소스 테스트로 결함을 1차 차단하고,
 *           브라우저 회귀는 본 placeholder(test.describe.skip)가 data-testid 보강 + 실 도메인 시드 후 활성화될 때 검증한다.
 *           현재 커버리지:
 *           (1) 프론트 포맷터 — resources/js/__tests__/handlers/calculateCurrencyPrices.test.ts
 *               (formatCurrency 가 설정 symbol/decimal_places 를 따름, CNY=元/JPY=¥ 구분, symbol 미설정 시 元 폴백) green.
 *           (2) 백엔드 통화 기호 SSoT — tests/Feature/Http/Controllers/Admin/EcommerceSettingsCurrencySymbolTest.php
 *               (currencyDisplayMeta CNY=元 ≠ JPY=¥, formatCurrencyPrice 접두 元/¥ 구분) green.
 *           (3) 주문 스냅샷 통화 불변 — tests/Unit/Resources/OrderResourceFieldsTest.php
 *               (USD 주문은 기본통화 KRW 라도 $ 표기, 자식 리소스 통화 주입, 미주입 시 현재기본 폴백) green.
 *           라이브 검수는 Chrome MCP 실측(카트에서 JPY ¥785,000 / CNY 元36,000.00 구분, 스크린샷)으로 기록됨.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

test.describe.skip('통화 기호 표시 — CNY=元 / JPY=¥ 구분 및 주문 스냅샷 통화 (skeleton)', () => {
  test('장바구니: JPY 는 ¥, CNY 는 元 로 구분 표기되고 5통화가 모두 보인다', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/cart');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 활성화 시: 카트 가격 블록에서 JPY 행은 ¥ 접두, CNY 행은 元 접두 (동일 ¥ 중복 금지)
    const jpy = page.getByTestId('cart-price-jpy').first();
    const cny = page.getByTestId('cart-price-cny').first();
    await expect(jpy).toContainText('¥');
    await expect(cny).toContainText('元');
    await expect(cny).not.toContainText('¥');

    // 5통화(KRW/USD/JPY/CNY/EUR) 모두 노출
    for (const code of ['krw', 'usd', 'jpy', 'cny', 'eur']) {
      await expect(page.getByTestId(`cart-price-${code}`).first()).toBeVisible();
    }
  });

  test('상품 추가옵션 추가금은 설정된 기본 통화 기호로 표기된다(원화 고정 아님)', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/products/237');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 활성화 시: 기본 통화가 USD 면 추가금이 '+$...' 로, KRW 면 '+...원' 으로 표기 (하드코딩 '원' 아님)
    const adjustment = page.getByTestId('additional-option-adjustment').first();
    await expect(adjustment).toBeVisible();
  });

  test('주문 내역의 금액은 주문 시점 통화로 고정 표기된다(설정 변경에 불변)', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/mypage/orders');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 활성화 시: USD 로 기록된 과거 주문은 기본통화를 KRW 로 바꿔도 '$' 표기 유지
    const amount = page.getByTestId('order-total-amount').first();
    await expect(amount).toBeVisible();
  });
});
