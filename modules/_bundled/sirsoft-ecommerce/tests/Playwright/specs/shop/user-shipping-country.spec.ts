/**
 * 유저별 배송국가(ecommerce_preferred_shipping_country, 옛 키 preferred_shipping_country) — 설정·게이트·차단·표시 E2E.
 * 템플릿 sirsoft-basic (유저 화면) + 모듈 설정/엔드포인트.
 *
 * 배송국가는 이커머스가 회원 항목 집합(core.user)에 선언한 회원 항목이다. 내 정보 보기와 수정 화면에서는
 * 예전 주입 조각(설정 카드, `mypage-shipping-country-select`)이 아니라 **이커머스 확장별 카드**(보기
 * `#profile_view_extension_card_ext_sirsoft-ecommerce`, 수정 `#profile_extension_card_ext_sirsoft-ecommerce`) 안의 회원 항목 칸
 * (`#field_view_{키}`, `#field_{키}`)으로 그려진다. 해외배송을 끈 사이트에서는 항목 자체가 화면 계약에서 빠진다.
 * 저장된 국가가 지금 배송할 수 없는 국가면, 수정 화면은 칸 아래 안내(항목 계약의 `help`)로, 보기 화면은 값 아래 알림 줄
 * (`#mf_view_notice_{키}`, 항목 계약의 `ui.notice`)로 「적용되지 않는다」를 알린다.
 *
 * 내 정보 시험은 사이트 설정을 바꾸지 않는다 — 그 회원의 항목 계약(`GET /api/me/fields`)을 먼저 읽어 해외배송 켬과 끔 중
 * 지금 사이트의 상태를 판정하고, 맞지 않는 쪽 시험은 사유와 함께 건너뛴다. 회원은 비밀번호를 아는 시드 회원
 * (`playwright:seed-password-expiry --stage=fresh`)이고 afterAll 의 `--purge-users` 로 지운다.
 *
 * 소유 매니페스트: modules/_bundled/sirsoft-ecommerce/tests/scenarios/mp08-user-shipping-country.yaml. 시험마다 그 시험이 재는
 * 축 조합과 효과를 표식으로 단다(파일 머리에는 표식을 두지 않는다).
 *
 * e2e:allow MP08 후속 신규 UI(헤더 공용 셀렉터, 장바구니·주문서 차단) — PHPUnit(Resolver/User·Admin 컨트롤러/OrderProcessing 차단·저장)
 *           + Vitest(initPreferredShippingCountry 핸들러)로 결함을 1차 차단하고, 브라우저 회귀는 아래 placeholder(test.describe.skip)가
 *           data-testid 보강 + 해외배송 활성 시드(available_countries[is_active]) 도입 후 활성화될 때 검증한다.
 *
 * placeholder 활성화 전 사전 작업:
 *   1. 헤더 공용 셀렉터 / shippability 경고 / 주문서 국가 칸에 data-testid 보강
 *      - header-shipping-country-trigger / header-shipping-country-option-{code}
 *      - checkout-shipping-country-select
 *      - cart-unshippable-warning / checkout-unshippable-warning
 *   2. 해외배송 활성 + 활성 국가(US 등) 시드 fixture
 *   3. test.describe.skip → test.describe 전환
 */
import type { APIRequestContext, Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import { runSeedPasswordExpiry, seedAccount, type SeededAccount } from '../../../../../../../tests/Playwright/fixtures/password-expiry';
import { dismissCookieBanner, openProfileEdit } from '../../../../../../../tests/Playwright/fixtures/member-fields';

/** 배송국가 회원 항목 키 */
const SHIPPING_KEY = 'ecommerce_preferred_shipping_country';

/** 이커머스 확장별 카드의 묶음 키 (`ext_` + 확장 식별자) */
const ECOMMERCE_GROUP = 'ext_sirsoft-ecommerce';

test.describe('유저별 배송국가 — 내 정보의 이커머스 카드와 회원 항목', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });

  /** 비밀번호를 아는 시드 회원 (네 시험이 같이 쓴다) */
  let account: SeededAccount | null = null;

  test.afterAll(() => {
    if (account) runSeedPasswordExpiry(['--purge-users']);
  });

  /**
   * 시드 회원을 만들거나 이미 만든 것을 돌려준다.
   *
   * @returns 시드 회원
   */
  function member(): SeededAccount {
    if (!account) account = seedAccount('fresh', { issueToken: true });

    return account;
  }

  /**
   * 그 회원의 내 정보 항목 계약에서 배송국가 항목을 읽는다(없으면 해외배송을 끈 사이트).
   *
   * @param request 요청 문맥
   * @returns 배송국가 항목 계약 또는 null
   */
  async function shippingContract(request: APIRequestContext): Promise<Record<string, any> | null> {
    const res = await request.get('/api/me/fields', {
      headers: { Authorization: `Bearer ${member().otherDeviceToken}`, Accept: 'application/json', 'Accept-Language': 'ko' },
    });
    expect(res.status(), '내 정보 항목 계약').toBe(200);
    const fields = ((await res.json())?.data?.fields ?? []) as Array<Record<string, any>>;

    return fields.find((field) => field.key === SHIPPING_KEY) ?? null;
  }

  /**
   * 내 정보 보기 화면을 열고 항목이 그려질 때까지 기다린다.
   *
   * @param page 회원 토큰을 심은 페이지
   */
  async function openProfileView(page: Page): Promise<void> {
    const loaded = page.waitForResponse(
      (r) => new URL(r.url()).pathname === '/api/me/fields' && r.request().method() === 'GET',
      { timeout: 60_000 },
    );
    await page.goto('/mypage/profile');
    await loaded;
    await dismissCookieBanner(page);
    await expect(page.locator('[id^="field_view_"]').first()).toBeVisible({ timeout: 30_000 });
  }

  /**
   * @scenario actor=member, setting_location=profile_view, module_state=intl_on, shippability=all_shippable
   * @effects setting_ui_injected_when_intl_on, mypage_view_shows_saved_country
   */
  test('해외배송 켬 — 내 정보 보기의 이커머스 카드에 배송국가가 있고, 적용되지 않는 저장 값은 값 아래 알림으로 보인다', async ({ page, request }) => {
    const field = await shippingContract(request);
    test.skip(field === null, '해외배송을 끈 사이트라 배송국가 항목이 화면 계약에 없다(끔 시험이 잰다)');

    await authenticatePage(page, member().otherDeviceToken!);
    await openProfileView(page);

    const card = page.locator(`#profile_view_extension_card_${ECOMMERCE_GROUP}`);
    await expect(card.locator(`#field_view_${SHIPPING_KEY}`)).toBeVisible({ timeout: 20_000 });
    await expect(card.locator(`#field_view_${SHIPPING_KEY}`)).toContainText(String(field!.label));

    const notice = card.locator(`#mf_view_notice_${SHIPPING_KEY}`);
    if (field!.ui?.notice) {
      await expect(notice).toHaveText(String(field!.ui.notice));
    } else {
      await expect(notice).toHaveCount(0);
    }
  });

  /**
   * @scenario actor=member, setting_location=profile_view, module_state=intl_off, shippability=all_shippable
   * @effects setting_ui_hidden_when_intl_off
   */
  test('해외배송 끔 — 내 정보 보기에 배송국가 칸이 없다', async ({ page, request }) => {
    const field = await shippingContract(request);
    test.skip(field !== null, '해외배송을 켠 사이트라 배송국가 항목이 있다(켬 시험이 잰다)');

    await authenticatePage(page, member().otherDeviceToken!);
    await openProfileView(page);

    await expect(page.locator(`#field_view_${SHIPPING_KEY}`)).toHaveCount(0);
  });

  /**
   * @scenario actor=member, setting_location=profile_edit, module_state=intl_on, shippability=all_shippable
   * @effects setting_ui_injected_when_intl_on
   */
  test('해외배송 켬 — 내 정보 수정의 이커머스 카드에 배송국가 칸이 있고, 적용되지 않는 저장 값 안내는 칸 아래 안내(help)다', async ({ page, request }) => {
    const field = await shippingContract(request);
    test.skip(field === null, '해외배송을 끈 사이트라 배송국가 항목이 화면 계약에 없다(끔 시험이 잰다)');

    await authenticatePage(page, member().otherDeviceToken!);
    await openProfileEdit(page, member().password);

    const row = page.locator(`#profile_extension_card_${ECOMMERCE_GROUP} #field_${SHIPPING_KEY}`);
    await expect(row).toBeVisible({ timeout: 20_000 });
    await expect(row.locator('label').first()).toContainText(String(field!.label));
    if (field!.help) {
      await expect(row).toContainText(String(field!.help));
    }
    // 값 아래 알림 줄은 보기 화면 전용이다 — 수정 화면에는 같은 문장이 두 번 뜨지 않는다.
    await expect(page.locator(`#mf_view_notice_${SHIPPING_KEY}`)).toHaveCount(0);
  });

  /**
   * @scenario actor=member, setting_location=profile_edit, module_state=intl_off, shippability=all_shippable
   * @effects setting_ui_hidden_when_intl_off
   */
  test('해외배송 끔 — 내 정보 수정에 배송국가 칸이 없다', async ({ page, request }) => {
    const field = await shippingContract(request);
    test.skip(field !== null, '해외배송을 켠 사이트라 배송국가 항목이 있다(켬 시험이 잰다)');

    await authenticatePage(page, member().otherDeviceToken!);
    await openProfileEdit(page, member().password);

    await expect(page.locator('#profileEditForm [data-member-field]').first()).toBeVisible({ timeout: 20_000 });
    await expect(page.locator(`#field_${SHIPPING_KEY}`)).toHaveCount(0);
  });
});

test.describe.skip('유저별 배송국가 — 헤더·장바구니·주문서 매트릭스 (skeleton)', () => {
  /**
   * @scenario actor=member, setting_location=checkout_select, module_state=intl_on, shippability=all_shippable
   * @effects checkout_select_visible_when_intl_on
   */
  test('해외배송 ON — 체크아웃 국가 Select 가 보인다 (B1 회귀)', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/checkout');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByTestId('checkout-shipping-country-select')).toBeVisible({ timeout: 10_000 });
  });

  /**
   * @scenario actor=member, setting_location=header_dropdown, module_state=intl_on, shippability=all_shippable
   * @effects header_dropdown_changes_country, country_change_refetches_cart_checkout
   */
  test('해외배송 ON — 헤더 공용 드롭다운에서 배송국가 변경 → 장바구니 refetch', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/cart');
    await page.getByTestId('header-shipping-country-trigger').click();
    await page.getByTestId('header-shipping-country-option-US').click();
    // 국가 변경 → cartItems refetch → shippability 재계산
    await expect(page.getByTestId('checkout-shipping-country-select')).toHaveValue('US');
  });

  /**
   * @scenario actor=member, setting_location=header_dropdown, module_state=intl_on, shippability=mixed
   * @effects mixed_cart_blocks_all, not_shippable_blocks_purchase
   */
  test('미지원 혼재 카트 — 주문하기 비활성 + 안내 (D1 전체 차단)', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/cart');
    await page.getByTestId('header-shipping-country-trigger').click();
    await page.getByTestId('header-shipping-country-option-US').click();

    await expect(page.getByTestId('cart-unshippable-warning')).toBeVisible();
    await expect(page.getByRole('button', { name: /주문하기|checkout/i })).toBeDisabled();
  });

  /**
   * @scenario actor=member, setting_location=checkout_select, module_state=intl_on, shippability=all_shippable
   * @effects checkout_country_change_recalculates_shipping_fee
   */
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

  /**
   * @scenario actor=member, setting_location=header_dropdown, module_state=intl_on, shippability=all_shippable
   * @effects cart_shipping_fee_matches_selected_country
   */
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
