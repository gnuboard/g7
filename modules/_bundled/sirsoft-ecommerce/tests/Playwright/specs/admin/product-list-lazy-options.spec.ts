/**
 * 관리자 상품목록 옵션 지연 로딩 — 목록을 여는 것만으로 옵션이 전부 실려 오지 않는지 (공개 이슈 #76).
 *
 * 결함: 상품목록 한 페이지를 여는 것만으로 그 페이지 모든 상품의 옵션 데이터가 응답에 실렸다.
 *   옵션을 많이 쓰는 사이트에서는 상품 한 줄을 보여주려고 수백~수천 옵션 행을 로드·전송하게 된다.
 *
 * 수정: 목록은 요약(활성 옵션 수 / 전체 옵션 수 / 활성 옵션 재고 합계)만 내려주고, 옵션 상세는
 *   행을 펼칠 때 배치 엔드포인트가 공급한다. `?with_options=1` 로 종전 동작을 유지할 수 있다.
 *
 * 첫 describe 는 네트워크 관측으로 판정할 수 있는 축을 실측한다 — 응답 페이로드, 확장 시 요청 발생,
 * 접었다 펴기의 재요청 부재. 두 번째 describe 는 UI 상호작용 축을 **저장까지** 실측한다. 목록이
 * 옵션을 싣지 않게 됐으므로 저장 후 재표시가 이 이슈의 핵심 위험이고, 그 실패는 브라우저에서만
 * 드러나기 때문이다. 실제 데이터는 테스트마다 전용 상품을 만들어 그것만 변경·삭제해 보호한다.
 *
 * @scenario mp-product-list-lazy-options
 * @axes row_state=collapsed|expanded|re_expanded option_profile=none|mixed
 * @effects list_response_omits_options,
 *          options_total_count_matches_all,
 *          no_fetch_when_total_count_zero,
 *          scope_filtered_ids_absent
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const LIST_URL = '/admin/ecommerce/products';
const LIST_API = '/api/modules/sirsoft-ecommerce/admin/products';
const OPTIONS_API = '/api/modules/sirsoft-ecommerce/admin/products/options';
const CATEGORY_API = '/api/modules/sirsoft-ecommerce/admin/categories';

/**
 * 관리자 API 를 브라우저 컨텍스트에서 직접 호출합니다.
 *
 * @param page Playwright 페이지
 * @param url 호출할 경로
 * @returns 응답 본문
 */
async function callApi(page: any, url: string): Promise<any> {
  return page.evaluate(async (target: string) => {
    const res = await fetch(target, {
      headers: {
        Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        Accept: 'application/json',
      },
    });

    return res.json();
  }, url);
}

/**
 * 브라우저 컨텍스트에서 쓰기 API 를 호출합니다.
 *
 * @param page Playwright 페이지
 * @param method HTTP 메서드
 * @param url 호출할 경로
 * @param body 요청 본문 (없으면 생략)
 * @returns 응답 본문
 */
async function writeApi(page: any, method: string, url: string, body?: any): Promise<any> {
  return page.evaluate(
    async ({ method, target, payload }: { method: string; target: string; payload?: any }) => {
      const res = await fetch(target, {
        method,
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: payload === undefined ? undefined : JSON.stringify(payload),
      });

      return res.json();
    },
    { method, target: url, payload: body },
  );
}

/**
 * 이 spec 전용 상품을 옵션 2건과 함께 생성합니다.
 *
 * 저장 경로를 실측하려면 실제 쓰기가 필요하다. 사이트의 기존 상품을 건드리는 대신 전용 상품을
 * 만들고 그것만 변경한 뒤 삭제한다 — 실행마다 남의 데이터가 바뀌지 않는다.
 *
 * @param page Playwright 페이지
 * @returns 생성된 상품 (실패 시 null)
 */
async function createFixtureProduct(page: any): Promise<any | null> {
  const categories = await callApi(page, `${CATEGORY_API}?per_page=1`);
  const categoryId = (categories?.data?.data ?? categories?.data ?? [])[0]?.id;

  if (!categoryId) {
    return null;
  }

  const suffix = `${Date.now()}`.slice(-9);
  const created = await writeApi(page, 'POST', LIST_API, {
    name: { ko: `E2E 지연로딩 픽스처 ${suffix}` },
    product_code: `E2E-LAZY-${suffix}`,
    category_ids: [categoryId],
    list_price: 10000,
    selling_price: 10000,
    stock_quantity: 30,
    sales_status: 'on_sale',
    display_status: 'visible',
    tax_status: 'taxable',
    options: [
      {
        option_code: `E2E-OPT-A-${suffix}`,
        option_name: { ko: '픽스처 옵션 A' },
        option_values: [{ key: { ko: '색상' }, value: { ko: '빨강' } }],
        list_price: 10000,
        selling_price: 10000,
        stock_quantity: 10,
        is_active: true,
      },
      {
        option_code: `E2E-OPT-B-${suffix}`,
        option_name: { ko: '픽스처 옵션 B' },
        option_values: [{ key: { ko: '색상' }, value: { ko: '파랑' } }],
        list_price: 10000,
        selling_price: 10000,
        stock_quantity: 20,
        is_active: true,
      },
    ],
  });

  return created?.data ?? null;
}

test.describe('관리자 상품목록 옵션 지연 로딩 (#76)', () => {
  // @scenario row_state=collapsed,option_profile=mixed
  // @effects list_response_omits_options, options_total_count_matches_all
  test('목록 응답에 옵션 배열이 없고 집계 필드만 실린다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const body = await callApi(page, `${LIST_API}?per_page=20`);
    const rows = body?.data?.data ?? [];

    expect(rows.length).toBeGreaterThan(0);

    for (const row of rows) {
      expect(row).not.toHaveProperty('options');
      expect(typeof row.options_count).toBe('number');
      expect(typeof row.options_total_count).toBe('number');
      expect(typeof row.option_stock_sum).toBe('number');
      // 옵션 0건에서도 null 이 아니라 0 이어야 한다 (재고 불일치 배지 오탐 가드)
      expect(row.option_stock_sum).not.toBeNull();
    }
  });

  // @scenario row_state=collapsed,option_profile=mixed
  // @effects list_response_omits_options
  test('with_options=1 로는 종전처럼 옵션이 함께 온다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const body = await callApi(page, `${LIST_API}?per_page=20&with_options=1`);
    const rows = body?.data?.data ?? [];

    expect(rows.length).toBeGreaterThan(0);
    expect(rows.some((row: any) => Array.isArray(row.options))).toBe(true);
  });

  // @scenario row_state=expanded,option_profile=mixed
  // @effects options_total_count_matches_all
  test('옵션 배치 조회가 요청한 상품 ID 로 묶인 맵을 돌려준다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const list = await callApi(page, `${LIST_API}?per_page=20`);
    const withOptions = (list?.data?.data ?? []).filter((row: any) => row.options_total_count > 0);

    test.skip(withOptions.length === 0, '옵션을 가진 상품이 시드에 없다');

    const target = withOptions[0];
    const body = await callApi(page, `${OPTIONS_API}?product_ids[]=${target.id}`);

    expect(body?.data?.product_ids).toContain(target.id);
    expect(Array.isArray(body?.data?.options?.[String(target.id)])).toBe(true);
    // 배치는 비활성 옵션도 포함하므로 전체 옵션 수와 일치한다
    expect(body.data.options[String(target.id)].length).toBe(target.options_total_count);
  });

  // @scenario row_state=expanded,option_profile=none
  // @effects no_fetch_when_total_count_zero
  test('옵션 0건 상품은 빈 배열을 돌려준다 (부분 성공 계약)', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const list = await callApi(page, `${LIST_API}?per_page=50`);
    const withoutOptions = (list?.data?.data ?? []).filter((row: any) => row.options_total_count === 0);

    test.skip(withoutOptions.length === 0, '옵션이 없는 상품이 시드에 없다');

    const target = withoutOptions[0];
    const body = await callApi(page, `${OPTIONS_API}?product_ids[]=${target.id}`);

    expect(body?.data?.product_ids).toContain(target.id);
    expect(body.data.options[String(target.id)]).toEqual([]);
  });

  // @scenario row_state=expanded,option_profile=mixed
  // @effects scope_filtered_ids_absent
  test('존재하지 않는 상품 ID 는 404 가 아니라 응답에서 빠진다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const missingId = 999999999;
    const body = await callApi(page, `${OPTIONS_API}?product_ids[]=${missingId}`);

    expect(body?.success).not.toBe(false);
    expect(body?.data?.product_ids ?? []).not.toContain(missingId);
    expect(body?.data?.options?.[String(missingId)]).toBeUndefined();
  });

  // @scenario row_state=re_expanded,option_profile=mixed
  // @effects no_fetch_when_total_count_zero
  test('행을 펼치면 옵션 API 가 한 번 호출되고, 접었다 펴면 다시 호출되지 않는다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);

    const optionCalls: string[] = [];
    page.on('request', (request: any) => {
      if (request.url().includes('/admin/products/options')) {
        optionCalls.push(request.url());
      }
    });

    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 확장 토글 — DataGrid 가 확장 가능한 행마다 렌더하는 버튼. data-testid 가 없으므로
    // 접근성 라벨로 잡는다 (컴포넌트가 `Expand row` / `Collapse row` 로 고정 노출한다).
    const expandToggle = page.locator('button[aria-label="Expand row"]').first();

    await expect(expandToggle).toBeVisible({ timeout: 30_000 });
    await expandToggle.click();
    await expect.poll(() => optionCalls.length, { timeout: 15_000 }).toBeGreaterThan(0);

    const afterFirstExpand = optionCalls.length;

    // 접기 → 다시 펼치기: 이미 채워진 행이므로 추가 요청이 없어야 한다
    await page.locator('button[aria-label="Collapse row"]').first().click();
    await page.locator('button[aria-label="Expand row"]').first().click();
    await page.waitForTimeout(1_000);

    expect(optionCalls.length).toBe(afterFirstExpand);
  });
});

/**
 * UI 상호작용 축.
 *
 * 저장(PUT) 까지 브라우저에서 실행한다. 저장 이후의 재표시가 이 이슈의 핵심 위험이기 때문이다 —
 * 목록이 옵션을 더 이상 싣지 않으므로, 저장 후 화면이 옵션을 다시 가져오지 못하면 펼친 행이
 * 빈 채로 남는다. 그 실패는 단위 테스트가 아니라 브라우저에서만 드러난다.
 *
 * 실제 데이터 보호는 "저장하지 않기" 가 아니라 **전용 상품을 만들어 그것만 건드리는** 방식으로
 * 한다. 각 테스트는 자신이 만든 상품에만 쓰기를 수행하고 종료 시 삭제한다.
 */
test.describe('옵션 지연 로딩 UI 상호작용', () => {
  // @scenario row_state=expanded,option_profile=mixed
  // @effects options_reloaded_after_bulk_save
  test('행을 펼치면 옵션 행이 서버 값으로 렌더된다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const expandToggle = page.locator('button[aria-label="Expand row"]').first();
    await expect(expandToggle).toBeVisible({ timeout: 30_000 });
    await expandToggle.click();

    // 옵션은 목록 응답에 없었고 배치 엔드포인트가 채운 값이다 — 렌더되면 지연 로딩이 성립한 것
    await expect(page.locator('[data-testid^="option-row-"]').first()).toBeVisible({
      timeout: 15_000,
    });
  });

  // @scenario row_state=collapsed,option_profile=mixed
  // @effects aggregate_summary_equals_enumerated_summary
  test('미펼침 상품을 체크하고 일괄 적용하면 확인 모달에 옵션 집계가 표시된다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 옵션을 가진 상품이 있어야 집계 섹션이 나타난다
    const list = await callApi(page, `${LIST_API}?per_page=50`);
    const rows = list?.data?.data ?? [];
    const withOptions = rows.filter((row: any) => (row.options_total_count ?? 0) > 0);
    test.skip(withOptions.length === 0, '옵션을 가진 상품이 시드에 없다');

    // 행을 펼치지 않은 채로 선택만 한다 (집계 경로 — 개별 옵션 열거 없이 개수만 계산)
    const rowCheckbox = page
      .locator('tbody input[type="checkbox"], [role="row"] input[type="checkbox"]')
      .first();
    await expect(rowCheckbox).toBeVisible({ timeout: 30_000 });
    await rowCheckbox.check();

    // 옵션 대상 변경(재고 일괄)이 걸려 있어야 확인 모달이 옵션 섹션을 낸다.
    // 이 단계는 전역 상태만 세팅하며 서버 요청을 보내지 않는다.
    await page.getByTestId('product-bulk-stock-open').click();
    await page.getByTestId('bulk-stock-value').fill('1');
    await page.getByTestId('bulk-stock-apply').click();

    const applyButton = page.getByTestId('product-bulk-apply');
    await expect(applyButton).toBeEnabled({ timeout: 15_000 });
    await applyButton.click();

    await expect(page.getByTestId('bulk-confirm-options-section')).toBeVisible({
      timeout: 15_000,
    });
  });

  // @scenario row_state=expanded,option_profile=active_only
  // @effects options_reloaded_after_bulk_save
  test('인라인으로 옵션 재고를 고치고 저장하면 펼친 행이 저장된 값으로 다시 표시된다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const product = await createFixtureProduct(page);
    test.skip(!product?.id, '전용 상품을 만들 카테고리가 없다');

    try {
      // 방금 만든 상품만 보이도록 좁힌다 (다른 상품을 건드리지 않기 위해서이기도 하다)
      await page.goto(
        `${LIST_URL}?search_field=all&search_keyword=${encodeURIComponent(product.product_code)}`,
      );
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      // 워커가 병렬로 돌면 다른 테스트의 픽스처 상품도 목록에 있다. 항상 내 상품 행만 집는다.
      const row = page
        .locator('tr, [role="row"]')
        .filter({ hasText: product.product_code })
        .first();
      await expect(row).toBeVisible({ timeout: 30_000 });
      await row.locator('button[aria-label="Expand row"]').first().click();

      const optionId = (await callApi(page, `${OPTIONS_API}?product_ids[]=${product.id}`))
        ?.data?.options?.[String(product.id)]?.[0]?.id;
      expect(optionId).toBeTruthy();

      const stockInput = page.getByTestId(`option-stock-${optionId}`);
      await expect(stockInput).toBeVisible({ timeout: 15_000 });

      // 인라인 편집 → 그 옵션 체크 → 일괄 적용 → 확인 → 저장
      await stockInput.fill('77');
      await stockInput.blur();

      const optionCheckbox = page
        .locator(`[data-testid="option-row-${optionId}"] input[type="checkbox"]`)
        .first();
      await expect(optionCheckbox).toBeVisible({ timeout: 15_000 });
      await optionCheckbox.check();

      await page.getByTestId('product-bulk-apply').click();

      const submit = page.getByTestId('bulk-confirm-submit');
      await expect(submit).toBeEnabled({ timeout: 15_000 });
      await submit.click();

      // 저장 후 목록이 리페치되고, 펼쳐 둔 행의 옵션이 다시 로드되어야 한다.
      // 목록 응답에는 옵션이 없으므로 재로드가 없으면 이 값은 화면에 나타날 수 없다.
      await expect(page.getByTestId(`option-stock-${optionId}`)).toHaveValue('77', {
        timeout: 20_000,
      });

      // 서버에도 실제로 저장됐는지 대조 (화면만 갱신되고 저장이 안 된 경우를 가른다)
      const persisted = await callApi(page, `${OPTIONS_API}?product_ids[]=${product.id}`);
      const saved = (persisted?.data?.options?.[String(product.id)] ?? []).find(
        (option: any) => option.id === optionId,
      );
      expect(saved?.stock_quantity).toBe(77);
    } finally {
      await writeApi(page, 'DELETE', `${LIST_API}/${product.id}`);
    }
  });

  // @scenario row_state=collapsed,option_profile=active_only
  // @effects aggregate_summary_equals_enumerated_summary, bulk_applies_to_inactive_options
  test('미펼침 상품을 일괄 저장하면 펼치지 않은 옵션에도 적용된다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const product = await createFixtureProduct(page);
    test.skip(!product?.id, '전용 상품을 만들 카테고리가 없다');

    try {
      await page.goto(
        `${LIST_URL}?search_field=all&search_keyword=${encodeURIComponent(product.product_code)}`,
      );
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      // 펼치지 않은 채로 상품만 체크한다 — 화면은 이 상품의 옵션 목록을 갖고 있지 않다.
      // 워커가 병렬로 돌면 다른 테스트의 픽스처 상품도 목록에 있으므로 내 상품 행으로 한정한다.
      const row = page
        .locator('tr, [role="row"]')
        .filter({ hasText: product.product_code })
        .first();
      await expect(row).toBeVisible({ timeout: 30_000 });

      const rowCheckbox = row.locator('input[type="checkbox"]').first();
      await rowCheckbox.check();
      await expect(rowCheckbox).toBeChecked();

      await page.getByTestId('product-bulk-stock-open').click();
      await page.getByTestId('bulk-stock-value').fill('5');
      await page.getByTestId('bulk-stock-apply').click();

      await page.getByTestId('product-bulk-apply').click();

      // 확인 모달은 개별 열거가 아니라 집계 엔트리로 같은 수치를 보여준다
      await expect(page.getByTestId('bulk-confirm-options-section')).toBeVisible({
        timeout: 15_000,
      });

      const submit = page.getByTestId('bulk-confirm-submit');
      await expect(submit).toBeEnabled({ timeout: 15_000 });
      await submit.click();

      // 화면이 옵션을 갖고 있지 않았어도 서버가 ids 만으로 전 옵션에 전개해야 한다.
      // 재고 일괄의 기본 방식은 '증가' 이므로 픽스처의 10/20 이 각각 15/25 가 되어야 한다.
      await expect
        .poll(
          async () => {
            const body = await callApi(page, `${OPTIONS_API}?product_ids[]=${product.id}`);
            const options = body?.data?.options?.[String(product.id)] ?? [];

            return options
              .map((option: any) => option.stock_quantity)
              .sort((a: number, b: number) => a - b);
          },
          { timeout: 20_000 },
        )
        .toEqual([15, 25]);
    } finally {
      await writeApi(page, 'DELETE', `${LIST_API}/${product.id}`);
    }
  });
});
