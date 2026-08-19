/**
 * 배송정책 구간 경계·단위 환산 (공개 이슈 #94).
 *
 * 관리자 화면이 "1~5개: 3,000원 / 6개~: 5,000원" 이라고 약속한 정책에서 수량 5가 어느 구간에도
 * 매칭되지 않아 배송비가 0원(무료배송)이 됐다. 상품 옵션은 g/cm³ 로 저장되는데 배송정책은
 * kg/L 로 설정되므로, 500g 상품에 "1kg당 1,000원" 을 걸면 500,000원이 청구됐다.
 *
 * 두 결함 모두 저장된 값은 정상이고 화면도 오류를 내지 않는다 — 금액만 틀린다. 그래서 이
 * spec 은 관리자 저장(검증 계약)과 상점 장바구니(계산 결과)를 한 흐름에서 확인한다.
 *
 * @scenario charge_policy=range_quantity, charge_policy=range_weight, charge_policy=per_weight,
 *           boundary=at_max, boundary=decimal, unit_source=g, tier_shape=inclusive
 * @effects fee_matches_admin_promise,
 *          boundary_value_charged_not_free,
 *          unit_converted_kg_l,
 *          invalid_config_rejected_422,
 *          tier_min_derived_from_previous_max,
 *          range_unit_label_displayed
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const POLICY_API = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';
const PRODUCT_API = '/api/modules/sirsoft-ecommerce/admin/products';
const CATEGORY_API = '/api/modules/sirsoft-ecommerce/admin/categories';
const CART_API = '/api/modules/sirsoft-ecommerce/cart';
const POLICY_FORM_URL = '/admin/ecommerce/shipping-policies/create';
const POLICY_EDIT_URL = (id: number) => `/admin/ecommerce/shipping-policies/${id}/edit`;

type ApiResult = { status: number; body: any };

/**
 * 로그인된 페이지 컨텍스트에서 API 를 호출합니다.
 *
 * @param page Playwright 페이지
 * @param method HTTP 메서드
 * @param url 요청 URL
 * @param body 요청 본문 (없으면 생략)
 * @returns 상태코드와 파싱된 응답
 */
async function api(page: any, method: string, url: string, body?: any): Promise<ApiResult> {
  return page.evaluate(
    async ({ method, url, body }: { method: string; url: string; body?: any }) => {
      const res = await fetch(url, {
        method,
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      });

      let parsed: any = null;
      try {
        parsed = await res.json();
      } catch {
        parsed = null;
      }

      return { status: res.status, body: parsed };
    },
    { method, url, body },
  );
}

/**
 * 국가별 설정 1건을 가진 배송정책 페이로드를 만듭니다.
 *
 * @param name 정책명
 * @param countrySetting 국가별 설정 오버라이드
 */
function policyPayload(name: string, countrySetting: Record<string, any>) {
  return {
    name: { ko: name },
    is_active: true,
    is_default: false,
    sort_order: 0,
    country_settings: [
      {
        country_code: 'KR',
        shipping_method: 'parcel',
        charge_policy: 'fixed',
        base_fee: 3000,
        extra_fee_enabled: false,
        is_active: true,
        ...countrySetting,
      },
    ],
  };
}

test.describe('배송정책 구간 경계·단위 환산 (#94)', () => {
  test('저장 검증: 무음 0원을 만드는 설정은 422 로 차단된다', async ({ page, shippingBoundaryToken }) => {
    await authenticatePage(page, shippingBoundaryToken);
    await page.goto(POLICY_FORM_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const suffix = `${Date.now()}`.slice(-9);
    const createdIds: number[] = [];

    /**
     * 저장 요청 결과를 확인하고, 만들어진 정책이 있으면 정리 대상에 등록합니다.
     *
     * 422 를 기대한 요청이 통과해 버리면 그 정책이 사이트에 그대로 남는다 — 실패한
     * 단언보다 잔여 데이터가 더 오래 남으므로 생성 여부와 무관하게 수집한다.
     */
    const track = (result: ApiResult): ApiResult => {
      if (result.body?.data?.id) {
        createdIds.push(result.body.data.id);
      }

      return result;
    };

    try {
      // ① 구간별 정책인데 구간이 없음 → 모든 주문이 0원이 된다
      const noTiers = track(await api(page, 'POST', POLICY_API, policyPayload(`E2E 구간없음 ${suffix}`, {
        charge_policy: 'range_quantity',
        base_fee: 0,
        ranges: { type: 'quantity', tiers: [] },
      })));
      expect(noTiers.status).toBe(422);

      // ② 중간 구간의 종료값 공란 → 뒤 구간이 영구히 도달 불가
      const middleMax = track(await api(page, 'POST', POLICY_API, policyPayload(`E2E 중간무제한 ${suffix}`, {
        charge_policy: 'range_weight',
        base_fee: 0,
        ranges: {
          type: 'weight',
          tiers: [
            { min: 0, max: null, fee: 3000 },
            { min: 2, max: null, fee: 5000 },
          ],
        },
      })));
      expect(middleMax.status).toBe(422);

      // ③ 조건부 무료인데 기준금액 공란
      const noThreshold = track(await api(page, 'POST', POLICY_API, policyPayload(`E2E 기준없음 ${suffix}`, {
        charge_policy: 'conditional_free',
        base_fee: 3000,
        free_threshold: null,
      })));
      expect(noThreshold.status).toBe(422);

      // ④ 단위당 정책인데 단위값 공란
      const noUnit = track(await api(page, 'POST', POLICY_API, policyPayload(`E2E 단위없음 ${suffix}`, {
        charge_policy: 'per_weight',
        base_fee: 1000,
        ranges: { type: 'per_weight' },
      })));
      expect(noUnit.status).toBe(422);

      // ⑤ 연속형 구간의 소수 경계는 정상 설정이다 (구 규칙에서는 저장조차 불가했다)
      const decimal = track(await api(page, 'POST', POLICY_API, policyPayload(`E2E 소수구간 ${suffix}`, {
        charge_policy: 'range_weight',
        base_fee: 0,
        ranges: {
          type: 'weight',
          tiers: [
            { min: 0, max: 2.5, fee: 3000 },
            { min: 2.5, max: null, fee: 5000 },
          ],
        },
      })));
      expect(decimal.status).toBe(201);
    } finally {
      for (const id of createdIds) {
        await api(page, 'DELETE', `${POLICY_API}/${id}`);
      }
    }
  });

  test('구간 폼 UI: 시작값 자동 파생·읽기전용, 단위 라벨, 단위값 선제 검증', async ({
    page,
    shippingBoundaryToken,
  }) => {
    await authenticatePage(page, shippingBoundaryToken);
    await page.goto(POLICY_FORM_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const suffix = `${Date.now()}`.slice(-9);
    let policyId: number | null = null;

    try {
      // 소수 경계를 가진 무게 구간 — 시작값이 직전 종료값과 같은 연속형이다
      const policy = await api(page, 'POST', POLICY_API, policyPayload(`E2E 폼UI ${suffix}`, {
        charge_policy: 'range_weight',
        base_fee: 0,
        ranges: {
          type: 'weight',
          tiers: [
            { min: 0, max: 2.5, fee: 3000 },
            { min: 2.5, max: null, fee: 5000 },
          ],
        },
      }));
      expect(policy.status, JSON.stringify(policy.body?.errors ?? {})).toBe(201);
      policyId = policy.body.data.id;

      await page.goto(POLICY_EDIT_URL(policyId as number));
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      const firstRow = page.locator('#range_tier_row_0');
      await expect(firstRow).toBeVisible({ timeout: 30_000 });

      // 시작값 칸은 파생값이라 운영자가 직접 고칠 수 없다
      await expect(firstRow.locator('input').first()).toHaveJSProperty('readOnly', true);

      // 구간 표 머리글의 단위는 부과정책(무게)에서 파생된다
      const headers = await page.locator('#range_tiers_table thead th').allInnerTexts();
      expect(headers[0], '시작값 머리글 단위').toContain('kg');
      expect(headers[1], '종료값 머리글 단위').toContain('kg');

      // 종료값을 고치면 다음 구간 시작값이 따라온다 (연속형 = 직전 종료값)
      const secondRowMin = page.locator('#range_tier_row_1').locator('input').first();
      await expect(secondRowMin).toHaveValue('2.5');

      await firstRow.locator('input').nth(1).fill('3.5');
      await expect(secondRowMin).toHaveValue('3.5', { timeout: 10_000 });

      // 부과정책을 단위당으로 바꾸면 단위 표기가 그 정책을 따라간다
      await page.locator('#field_charge_policy').getByRole('button').first().click();
      const listbox = page.getByRole('listbox');
      await expect(listbox).toBeVisible({ timeout: 10_000 });
      await listbox.getByRole('option', { name: '무게당 배송비', exact: true }).click();

      const unitField = page.locator('#field_unit_value');
      await expect(unitField).toBeVisible({ timeout: 10_000 });
      await expect(unitField).toContainText('kg');

      // 단위값을 비우면 저장 요청 없이 화면에서 먼저 오류가 뜬다
      const requestsBefore = page.waitForRequest(
        (req) => req.url().includes('/admin/shipping-policies') && req.method() !== 'GET',
        { timeout: 3_000 },
      ).catch(() => null);

      await unitField.locator('input').first().fill('');
      await expect(unitField).toContainText('단위값', { timeout: 10_000 });
      expect(await requestsBefore, '선제 검증은 저장 요청 없이 이뤄진다').toBeNull();
    } finally {
      if (policyId) {
        await page.goto(POLICY_FORM_URL);
        await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
        await api(page, 'DELETE', `${POLICY_API}/${policyId}`);
      }
    }
  });

  test('수량 구간의 상한은 포함이다 — 장바구니 4/5/6개 배송비', async ({
    page,
    shippingBoundaryToken,
    customerToken,
  }) => {
    await authenticatePage(page, shippingBoundaryToken);
    await page.goto(POLICY_FORM_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const suffix = `${Date.now()}`.slice(-9);
    let policyId: number | null = null;
    let productId: number | null = null;
    let optionId: number | null = null;

    try {
      // 공개 이슈 #94 본문과 동일한 설정: 1~5개 3,000원 / 6개~ 5,000원
      const policy = await api(page, 'POST', POLICY_API, policyPayload(`E2E 수량구간 ${suffix}`, {
        charge_policy: 'range_quantity',
        base_fee: 0,
        ranges: {
          type: 'quantity',
          tiers: [
            { min: 0, max: 5, fee: 3000 },
            { min: 6, max: null, fee: 5000 },
          ],
        },
      }));
      expect(policy.status).toBe(201);
      policyId = policy.body.data.id;

      // 목록 요약이 관리자에게 약속하는 문장 — 단위(개)가 붙어야 한다
      expect(String(policy.body.data.fee_summary)).toContain('개');

      const categories = await api(page, 'GET', `${CATEGORY_API}?per_page=1`);
      const categoryId = categories.body?.data?.data?.[0]?.id ?? categories.body?.data?.[0]?.id;
      expect(categoryId, '카테고리가 1건 이상 있어야 상품을 만들 수 있다').toBeTruthy();

      const product = await api(page, 'POST', PRODUCT_API, {
        name: { ko: `E2E 구간경계 상품 ${suffix}` },
        product_code: `E2E-BOUND-${suffix}`,
        category_ids: [categoryId],
        list_price: 10000,
        selling_price: 10000,
        stock_quantity: 999,
        sales_status: 'on_sale',
        display_status: 'visible',
        tax_status: 'taxable',
        shipping_policy_id: policyId,
        options: [
          {
            option_code: `E2E-BOUND-${suffix}-001`,
            option_name: { ko: '기본' },
            option_values: [{ key: { ko: '구성' }, value: { ko: '기본' } }],
            list_price: 10000,
            selling_price: 10000,
            stock_quantity: 999,
            is_default: true,
            is_active: true,
          },
        ],
      });
      expect(product.status, JSON.stringify(product.body?.errors ?? {})).toBe(201);
      productId = product.body.data.id;
      optionId = product.body.data.options?.[0]?.id;
      expect(optionId).toBeTruthy();

      // 구매자로 전환해 장바구니에서 실제 계산 결과를 본다
      await authenticatePage(page, customerToken);
      await page.goto('/shop/cart');
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      // product_id 는 최상위, 옵션·수량은 items[] (BulkAddToCartRequest 계약)
      const added = await api(page, 'POST', CART_API, {
        product_id: productId,
        items: [{ product_option_id: optionId, quantity: 4 }],
      });
      expect(added.status, JSON.stringify(added.body ?? {})).toBe(201);
      const cartItemId = added.body?.data?.items?.[0]?.id;
      expect(cartItemId).toBeTruthy();

      const expectations: Array<[number, number]> = [
        [4, 3000],
        [5, 3000], // 경계값 — 기존에는 어느 구간에도 걸리지 않아 0원이었다
        [6, 5000],
      ];

      for (const [quantity, expectedFee] of expectations) {
        await api(page, 'PATCH', `${CART_API}/${cartItemId}/quantity`, { quantity });

        const cart = await api(page, 'POST', `${CART_API}/query`, { selected_ids: [cartItemId] });
        expect(cart.status).toBe(200);

        const totalShipping = cart.body?.data?.calculation?.summary?.total_shipping;
        expect(totalShipping, `수량 ${quantity} 의 배송비`).toBe(expectedFee);
      }

      await api(page, 'DELETE', `${CART_API}/${cartItemId}`);
    } finally {
      await authenticatePage(page, shippingBoundaryToken);
      await page.goto(POLICY_FORM_URL);
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      if (productId) {
        await api(page, 'DELETE', `${PRODUCT_API}/${productId}`);
      }
      if (policyId) {
        await api(page, 'DELETE', `${POLICY_API}/${policyId}`);
      }
    }
  });

  test('무게당 정책은 g 저장값을 kg 으로 환산한다 — 500g 상품 1개', async ({
    page,
    shippingBoundaryToken,
    customerToken,
  }) => {
    await authenticatePage(page, shippingBoundaryToken);
    await page.goto(POLICY_FORM_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const suffix = `${Date.now()}`.slice(-9);
    let policyId: number | null = null;
    let productId: number | null = null;
    let optionId: number | null = null;

    try {
      const policy = await api(page, 'POST', POLICY_API, policyPayload(`E2E 무게당 ${suffix}`, {
        charge_policy: 'per_weight',
        base_fee: 1000,
        ranges: { type: 'per_weight', unit_value: 1 },
      }));
      expect(policy.status).toBe(201);
      policyId = policy.body.data.id;

      const categories = await api(page, 'GET', `${CATEGORY_API}?per_page=1`);
      const categoryId = categories.body?.data?.data?.[0]?.id ?? categories.body?.data?.[0]?.id;
      expect(categoryId).toBeTruthy();

      const product = await api(page, 'POST', PRODUCT_API, {
        name: { ko: `E2E 무게환산 상품 ${suffix}` },
        product_code: `E2E-WEIGHT-${suffix}`,
        category_ids: [categoryId],
        list_price: 10000,
        selling_price: 10000,
        stock_quantity: 999,
        sales_status: 'on_sale',
        display_status: 'visible',
        tax_status: 'taxable',
        shipping_policy_id: policyId,
        options: [
          {
            option_code: `E2E-WEIGHT-${suffix}-001`,
            option_name: { ko: '기본' },
            option_values: [{ key: { ko: '구성' }, value: { ko: '기본' } }],
            list_price: 10000,
            selling_price: 10000,
            stock_quantity: 999,
            // 상품 옵션의 저장 단위는 g — 정책의 1kg 과 단위가 다르다
            weight: 500,
            is_default: true,
            is_active: true,
          },
        ],
      });
      expect(product.status, JSON.stringify(product.body?.errors ?? {})).toBe(201);
      productId = product.body.data.id;
      optionId = product.body.data.options?.[0]?.id;

      // 저장한 무게가 응답에 실려야 관리자 수정 화면이 0 으로 덮어쓰지 않는다
      expect(Number(product.body.data.options?.[0]?.weight)).toBe(500);

      await authenticatePage(page, customerToken);
      await page.goto('/shop/cart');
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      const added = await api(page, 'POST', CART_API, {
        product_id: productId,
        items: [{ product_option_id: optionId, quantity: 1 }],
      });
      expect(added.status, JSON.stringify(added.body ?? {})).toBe(201);
      const cartItemId = added.body?.data?.items?.[0]?.id;

      const cart = await api(page, 'POST', `${CART_API}/query`, { selected_ids: [cartItemId] });
      // 500g = 0.5kg → ceil(0.5 / 1) = 1단위 × 1,000원. 기존 버그값은 500,000원이었다.
      expect(cart.body?.data?.calculation?.summary?.total_shipping).toBe(1000);

      await api(page, 'DELETE', `${CART_API}/${cartItemId}`);
    } finally {
      await authenticatePage(page, shippingBoundaryToken);
      await page.goto(POLICY_FORM_URL);
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      if (productId) {
        await api(page, 'DELETE', `${PRODUCT_API}/${productId}`);
      }
      if (policyId) {
        await api(page, 'DELETE', `${POLICY_API}/${policyId}`);
      }
    }
  });
});
