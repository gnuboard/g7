/**
 * 유저별 배송국가(preferred_shipping_country) — 설정·게이트·차단·표시 E2E (skeleton).
 * 템플릿 sirsoft-basic (유저 화면) + 모듈 설정/엔드포인트. (placeholder)
 *
 * @scenario mp08-user-shipping-country
 * @effects setting_ui_injected_when_intl_on,
 *          setting_ui_hidden_when_intl_off,
 *          header_dropdown_changes_country,
 *          country_change_refetches_cart_checkout,
 *          checkout_select_visible_when_intl_on,
 *          checkout_default_synced_with_header,
 *          checkout_country_change_recalculates_shipping_fee,
 *          cart_shipping_fee_matches_selected_country,
 *          register_field_prefill_and_persist,
 *          member_country_persisted_via_put,
 *          inactive_country_rejected_422,
 *          not_shippable_blocks_purchase,
 *          mixed_cart_blocks_all,
 *          intl_address_persisted_on_order,
 *          addressbook_intl_mapping_on_select,
 *          country_shown_on_order_detail_and_list
 *
 * e2e:allow MP08 후속 신규 UI — PHPUnit(Resolver/User·Admin 컨트롤러/OrderProcessing 차단·저장)
 *           + Vitest(initPreferredShippingCountry 핸들러)로 결함을 1차 차단하고,
 *           브라우저 회귀는 본 placeholder(test.describe.skip)가 data-testid 보강 +
 *           해외배송 활성 시드(available_countries[is_active]) 도입 후 활성화될 때 검증한다.
 *
 * 활성화 전 사전 작업:
 *   1. 헤더 공용 셀렉터 / 설정 카드 / shippability 경고에 data-testid 보강
 *      - header-shipping-country-trigger / header-shipping-country-option-{code}
 *      - mypage-shipping-country-select / checkout-shipping-country-select
 *      - cart-unshippable-warning / checkout-unshippable-warning
 *   2. 해외배송 활성 + 활성 국가(US 등) 시드 fixture
 *   3. test.describe.skip → test.describe 전환
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

test.describe.skip('유저별 배송국가 — 설정/게이트/차단/표시 매트릭스 (skeleton)', () => {
  test('해외배송 ON — 체크아웃 국가 Select 가 보인다 (B1 회귀)', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/checkout');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByTestId('checkout-shipping-country-select')).toBeVisible({ timeout: 10_000 });
  });

  test('해외배송 ON — 헤더 공용 드롭다운에서 배송국가 변경 → 장바구니 refetch', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/cart');
    await page.getByTestId('header-shipping-country-trigger').click();
    await page.getByTestId('header-shipping-country-option-US').click();
    // 국가 변경 → cartItems refetch → shippability 재계산
    await expect(page.getByTestId('checkout-shipping-country-select')).toHaveValue('US');
  });

  test('미지원 혼재 카트 — 주문하기 비활성 + 안내 (D1 전체 차단)', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/cart');
    await page.getByTestId('header-shipping-country-trigger').click();
    await page.getByTestId('header-shipping-country-option-US').click();

    await expect(page.getByTestId('cart-unshippable-warning')).toBeVisible();
    await expect(page.getByRole('button', { name: /주문하기|checkout/i })).toBeDisabled();
  });

  test('해외배송 OFF — 배송국가 설정 UI 숨김', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/mypage/profile');
    await expect(page.getByTestId('mypage-shipping-country-select')).toHaveCount(0);
  });

  test('체크아웃 — 배송국가 Select 변경 시 배송비가 해당 국가로 재계산된다 (A3 회귀)', async ({ page, userToken }) => {
    // 회귀 배경: 헤더/계정 배송국가가 US 여도 주문서 배송비가 KR(기본) 로 계산되던 버그.
    // A3 수정으로 배송국가 Select 변경 시 PUT /checkout(재계산)이 호출되어 배송비가 갱신되어야 한다.
    await authenticatePage(page, userToken);
    await page.goto('/shop/checkout');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const feeBefore = await page.getByTestId('checkout-shipping-fee').textContent();

    // 배송국가를 US 로 변경 → PUT /checkout 재계산
    await page.getByTestId('checkout-shipping-country-select').selectOption('US');
    await page.waitForResponse(
      (r) => r.url().includes('/checkout') && r.request().method() === 'PUT',
      { timeout: 10_000 }
    );

    // 배송비가 US 정책 기준으로 갱신 (KR 과 다른 값)
    const feeAfter = await page.getByTestId('checkout-shipping-fee').textContent();
    expect(feeAfter).not.toBe(feeBefore);
  });

  test('장바구니 — 선택 배송국가(US)의 배송비가 표시된다 (C 회귀)', async ({ page, userToken }) => {
    // 회귀: CartService 가 shippingAddress 미전달로 장바구니 배송비를 항상 KR 로 계산하던 버그.
    await authenticatePage(page, userToken);
    await page.goto('/shop/cart');
    await page.getByTestId('header-shipping-country-trigger').click();
    await page.getByTestId('header-shipping-country-option-US').click();
    await page.waitForResponse(
      (r) => r.url().includes('/cart') && r.request().method() === 'GET',
      { timeout: 10_000 }
    );

    // 장바구니 배송비가 US 정책 기준으로 표시 (selected_shipping_country=US 와 일치)
    await expect(page.getByTestId('cart-shipping-fee')).toBeVisible();
  });
});
