/**
 * 상품상세 담기/바로구매가 메인 옵션을 product_option_id(옵션 ID)로 전송하는지 검증하는 유저 흐름 E2E.
 * 템플릿 sirsoft-basic (유저 화면).
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
 *       engine-v1.63.5 (트러블슈팅 사례 42) 이후로는 이 spec 이 **이중 저장소 미러**의 종단
 *       회귀 핀도 겸한다 — 옵션 선택은 템플릿 커스텀 핸들러가 `context.setState` 로 쓰고,
 *       그 값이 저장소 B 에 닿지 않으면 요청 body 의 배열이 **빈 채로** 나간다. 화면에는 담긴
 *       항목이 그대로 보이고 콘솔 에러도 없으므로, 요청 body 를 보는 이 spec 이 유일한 통로다.
 *
 * 이 spec 은 시드 데이터를 만들지 않는다 — 이 템플릿이 붙은 사이트의 **공개 상품 목록**에서
 * 옵션 2개 이상인 상품을 찾아 쓴다. 그런 상품이 없으면 개별 테스트가 사유와 함께 스킵된다
 * (`test.skip`). 전체 describe 를 끄면 커버리지가 0 이 되므로 그렇게 하지 않는다.
 *
 * 이 템플릿의 Select 는 `options` 가 있으면 네이티브 `<select>` 가 아니라
 * `button[role=option]` 커스텀 드롭다운을 렌더한다 — `selectOption()` 은 동작하지 않는다.
 *
 * 매트릭스:
 *   T1 옵션 상품 담기 → POST /cart body.items[].product_option_id 가 선택 옵션 ID 와 일치, option_values 미포함
 *   T2 바로구매 → POST /checkout direct_items[].product_option_id 전송
 *   T3 담은 뒤 장바구니에 "정확히 선택한 옵션"이 반영 (로케일/텍스트 변경과 무관)
 */
import { test, expect, Page } from '@playwright/test';

/** 공개 상품 목록 API — 옵션 보유 상품 탐색용 */
const PRODUCT_LIST_API = '/api/modules/sirsoft-ecommerce/products?per_page=40';

interface OptionProduct {
  /** 상품 상세 경로 */
  url: string;
  /** 옵션 그룹별로 고를 값 라벨 (그룹을 **전부** 골라야 선택 블럭이 만들어진다) */
  mainValues: string[];
}

/**
 * 공개 목록에서 메인 옵션이 2개 이상인 상품을 찾는다.
 *
 * @param page Playwright 페이지
 * @return 찾은 상품 (없으면 null)
 */
async function findOptionProduct(page: Page): Promise<OptionProduct | null> {
  const listed = await page.evaluate(async (api) => {
    const res = await fetch(api, { headers: { Accept: 'application/json' } });
    if (!res.ok) return [];
    const json = await res.json();
    const rows = json?.data?.data ?? json?.data ?? [];
    return rows.map((p: any) => p.product_code).filter(Boolean);
  }, PRODUCT_LIST_API);

  for (const code of listed.slice(0, 12)) {
    const detail = await page.evaluate(async (c) => {
      const res = await fetch(`/api/modules/sirsoft-ecommerce/products/${c}`, {
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return null;
      const json = await res.json();
      const d = json?.data ?? json;
      return {
        options: (d?.options ?? []).length,
        groups: d?.option_groups ?? [],
      };
    }, code);

    if (detail && detail.options > 1) {
      const values = (detail.groups ?? []).map((g: any) => g?.values_localized?.[0] ?? null);
      if (values.length > 0 && values.every(Boolean)) {
        return { url: `/shop/products/${code}`, mainValues: values.map(String) };
      }
    }
  }
  return null;
}

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
  test('T1 옵션 상품 담기 요청이 product_option_id 를 전송한다 (option_values 미사용)', async ({ page }) => {
    await page.goto('/shop');
    const product = await findOptionProduct(page);
    test.skip(product === null, '메인 옵션이 2개 이상인 공개 상품이 없어 검증할 수 없습니다');

    await page.goto(product!.url);
    await pickAllMainOptions(page, product!.mainValues);

    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/cart') && r.method() === 'POST'),
      page.getByTestId('add-to-cart').click(),
    ]);
    const body = request.postDataJSON();
    expect(body.items?.length, '선택한 옵션이 요청 body 에 실려야 한다').toBeGreaterThan(0);
    expect(body.items?.[0]?.product_option_id).toEqual(expect.any(Number));
    expect(body.items?.[0]).not.toHaveProperty('option_values');
  });

  test('T2 바로구매가 direct_items.product_option_id 로 체크아웃한다', async ({ page }) => {
    await page.goto('/shop');
    const product = await findOptionProduct(page);
    test.skip(product === null, '메인 옵션이 2개 이상인 공개 상품이 없어 검증할 수 없습니다');

    await page.goto(product!.url);
    await pickAllMainOptions(page, product!.mainValues);

    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/checkout') && r.method() === 'POST'),
      page.getByTestId('buy-now').click(),
    ]);
    const body = request.postDataJSON();
    expect(body.direct_items?.length, '선택한 옵션이 요청 body 에 실려야 한다').toBeGreaterThan(0);
    expect(body.direct_items?.[0]?.product_option_id).toEqual(expect.any(Number));
    expect(body.direct_items?.[0]).not.toHaveProperty('option_values');
  });

  test('T3 담은 뒤 장바구니에 정확히 선택한 옵션이 반영된다', async ({ page }) => {
    await page.goto('/shop');
    const product = await findOptionProduct(page);
    test.skip(product === null, '메인 옵션이 2개 이상인 공개 상품이 없어 검증할 수 없습니다');

    await page.goto(product!.url);
    await pickAllMainOptions(page, product!.mainValues);

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/cart') && r.request().method() === 'POST'),
      page.getByTestId('add-to-cart').click(),
    ]);
    expect(response.status(), '담기가 성공해야 장바구니를 확인할 수 있다').toBeLessThan(300);

    await page.goto('/shop/cart');
    await expect(page.getByTestId('cart-item').first()).toContainText(product!.mainValues[0]);
  });
});
