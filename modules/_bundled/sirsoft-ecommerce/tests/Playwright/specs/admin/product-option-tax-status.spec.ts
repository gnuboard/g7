/**
 * 상품 옵션별 과세 종류(영세) 브라우저 왕복 (#612).
 *
 * @scenario product-option-tax-status
 * @effects option_tax_status_persists_after_reload,
 *          cart_summary_shows_zero_rated_amount
 *
 * 매트릭스 (시나리오 매니페스트 product-option-tax-status.yaml 와 1:1):
 *   - 관리자 상품 수정 화면에서 옵션 하나의 「과세 종류」를 영세로 바꿔 저장 → 새로고침해도 영세로 남고,
 *     나머지 옵션의 「기본값 따름」(빈 값)은 그대로다
 *   - 과세 옵션 + 영세 옵션을 장바구니에 담으면 세금 상세에 「영세 금액」 줄이 영세분 금액으로 보인다
 *
 * 데이터는 spec 이 API 로 만들고 finally 에서 지운다(상품·장바구니).
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const PRODUCT_API = '/api/modules/sirsoft-ecommerce/admin/products';
const CATEGORY_API = '/api/modules/sirsoft-ecommerce/admin/categories';
const CART_API = '/api/modules/sirsoft-ecommerce/cart';
const editUrl = (id: number) => `/admin/ecommerce/products/${id}/edit`;

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
 * 과세(따름) 11,000원 · 영세 10,000원 옵션 두 개를 가진 과세 상품을 만듭니다.
 *
 * @param page 관리자 토큰을 심은 페이지
 * @param suffix 상품 코드 접미
 * @param exportTaxStatus 수출용 옵션의 과세 종류 (null = 상품 따름)
 * @returns 생성 응답
 */
async function createProduct(page: any, suffix: string, exportTaxStatus: string | null = null): Promise<ApiResult> {
  const categories = await api(page, 'GET', `${CATEGORY_API}?per_page=1`);
  const categoryId = categories.body?.data?.data?.[0]?.id ?? categories.body?.data?.[0]?.id;
  expect(categoryId, '카테고리가 1건 이상 있어야 상품을 만들 수 있다').toBeTruthy();

  const option = (code: string, value: string, price: number, taxStatus: string | null) => ({
    option_code: `E2E-ZR-${suffix}-${code}`,
    option_name: { ko: value },
    option_values: [{ key: { ko: '구성' }, value: { ko: value } }],
    list_price: price,
    selling_price: price,
    stock_quantity: 999,
    is_default: code === '001',
    is_active: true,
    tax_status: taxStatus,
  });

  return api(page, 'POST', PRODUCT_API, {
    name: { ko: `E2E 영세 옵션 ${suffix}` },
    product_code: `E2E-ZR-${suffix}`,
    category_ids: [categoryId],
    list_price: 11000,
    selling_price: 11000,
    stock_quantity: 999,
    sales_status: 'on_sale',
    display_status: 'visible',
    tax_status: 'taxable',
    options: [option('001', '내수용', 11000, null), option('002', '수출용', 10000, exportTaxStatus)],
  });
}

test.describe('상품 옵션별 과세 종류 (#612)', () => {
  // @scenario surface=admin_detail, option_override=zero_rated
  // @effects option_tax_status_persists_after_reload
  test('옵션 과세 종류를 영세로 저장하면 새로고침 후에도 영세로 남는다', async ({ page, shippingBoundaryToken }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, shippingBoundaryToken);
    await page.goto('/admin/ecommerce/products');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const suffix = `${Date.now()}`.slice(-9);
    let productId: number | null = null;

    try {
      const created = await createProduct(page, suffix);
      expect(created.status, JSON.stringify(created.body?.errors ?? {})).toBe(201);
      productId = created.body.data.id;

      await page.goto(editUrl(productId as number));
      // 과세 종류 칸은 관리자 템플릿의 목록형 드롭다운이다 — 브라우저 기본 선택상자(combobox)가 아니라 단추(aria-haspopup=listbox)
      // 이고, 단추 이름은 「{행} {열} {고른 값}」(예: 「수출용 과세 종류 기본값 따름 (과세)」)으로 읽힌다. 고르기는 단추 → 항목.
      const exportSelect = page.getByRole('button', { name: /^수출용 과세 종류 / });
      const domesticSelect = page.getByRole('button', { name: /^내수용 과세 종류 / });
      await expect(exportSelect).toBeVisible({ timeout: 20_000 });
      await expect(exportSelect).toHaveAttribute('aria-haspopup', 'listbox');
      await expect(exportSelect).toContainText('기본값 따름');

      await exportSelect.click();
      await page.getByRole('option', { name: '영세', exact: true }).click();
      await expect(exportSelect).toContainText('영세');
      await expect(exportSelect).not.toContainText('기본값 따름');

      const saved = page.waitForResponse(
        (res) => res.url().includes(`${PRODUCT_API}/`) && res.request().method() === 'PUT',
        { timeout: 20_000 },
      );
      await page.getByTestId('product-save').click();
      expect((await saved).status()).toBe(200);

      await page.reload();
      await expect(exportSelect).toBeVisible({ timeout: 20_000 });
      await expect(exportSelect).toContainText('영세');
      await expect(exportSelect).not.toContainText('기본값 따름');
      await expect(domesticSelect).toContainText('기본값 따름');

      const detail = await api(page, 'GET', `${PRODUCT_API}/${productId}`);
      const statuses = (detail.body?.data?.options ?? []).map((o: any) => o.tax_status);
      expect(statuses).toEqual([null, 'zero_rated']);
    } finally {
      if (productId) {
        await api(page, 'DELETE', `${PRODUCT_API}/${productId}`);
      }
    }
  });

  // @scenario surface=public_detail, option_override=zero_rated
  // @effects cart_summary_shows_zero_rated_amount
  test('과세 + 영세 옵션을 담은 장바구니의 세금 상세에 「영세 금액」 줄이 보인다', async ({
    page,
    shippingBoundaryToken,
    customerToken,
  }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, shippingBoundaryToken);
    await page.goto('/admin/ecommerce/products');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const suffix = `${Date.now()}`.slice(-9);
    let productId: number | null = null;
    let cartItemIds: number[] = [];

    try {
      // 수출용만 영세로 지정한다 (내수용은 상품 기본값 과세를 따른다)
      const created = await createProduct(page, suffix, 'zero_rated');
      expect(created.status, JSON.stringify(created.body?.errors ?? {})).toBe(201);
      productId = created.body.data.id;
      const [domestic, exported] = created.body.data.options ?? [];

      await authenticatePage(page, customerToken);
      await page.goto('/shop/cart');
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      const added = await api(page, 'POST', CART_API, {
        product_id: productId,
        items: [
          { product_option_id: domestic.id, quantity: 1 },
          { product_option_id: exported.id, quantity: 1 },
        ],
      });
      expect(added.status, JSON.stringify(added.body ?? {})).toBe(201);
      cartItemIds = (added.body?.data?.items ?? []).map((i: any) => i.id);

      const query = await api(page, 'POST', `${CART_API}/query`, { selected_ids: cartItemIds });
      const zeroRated = query.body?.data?.calculation?.summary?.zero_rated_amount;
      expect(zeroRated, '영세 금액 = 영세 옵션 금액(배송비 영세 몫 포함)').toBeGreaterThanOrEqual(10000);

      await page.reload();
      // 세금 상세는 「상품 금액」 줄을 펼쳐야 보인다
      const toggle = page.locator('[aria-expanded]').filter({ hasText: '상품 금액' }).first();
      await expect(toggle).toBeVisible({ timeout: 20_000 });
      if ((await toggle.getAttribute('aria-expanded')) === 'false') {
        await toggle.click();
      }
      const zeroLine = page.getByText('영세 금액', { exact: true });
      await expect(zeroLine).toBeVisible();
      await expect(zeroLine.locator('xpath=..')).toContainText(Number(zeroRated).toLocaleString('ko-KR'));
    } finally {
      for (const id of cartItemIds) {
        await api(page, 'DELETE', `${CART_API}/${id}`);
      }
      await authenticatePage(page, shippingBoundaryToken);
      await page.goto('/admin/ecommerce/products');
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
      if (productId) {
        await api(page, 'DELETE', `${PRODUCT_API}/${productId}`);
      }
    }
  });
});
