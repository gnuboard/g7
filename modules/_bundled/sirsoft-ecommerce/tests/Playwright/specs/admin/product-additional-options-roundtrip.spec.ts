/**
 * 상품폼 추가옵션 선택지 round-trip (관리자 수정폼).
 *
 * @scenario product-additional-options
 * @effects edit_form_loads_existing_option_values,
 *          save_persists_option_values_without_loss,
 *          create_redirects_to_numeric_id_edit_url,
 *          redirected_edit_form_loads_data
 *
 * 회귀#1 (관리자 수정폼이 기존 추가옵션 선택지(values)를 미로드 → 저장 시 선택지 영구 소실) 을
 * 브라우저에서 직접 차단한다. 로드와 저장-후-영속 두 축을 모두 재현한다.
 *
 * e2e:allow 회귀#2(생성 저장 후 navigate 가 product_code 대신 숫자 id 를 쓴다) 축은 브라우저
 *           시험으로 켜지 않는다 — 상품 생성 폼은 name(다국어)·product_code·category_ids·
 *           list_price·selling_price·stock_quantity 에 더해 option_groups/options 를 필수로
 *           요구하고, 옵션 생성은 MultilingualTagInput(모달 기반 composite) → generateOptions →
 *           생성된 행별 가격·재고 입력을 거쳐야 한다. 그 흐름을 브라우저로 몰아 넣으면 검증하려는
 *           navigate 한 줄보다 폼 조작 자체가 훨씬 자주 깨져 회귀 신호가 묻힌다.
 *           대신 레이아웃 구조 테스트
 *           resources/js/__tests__/layouts/productOptionsAdditionalToggle.test.tsx
 *           ("회귀: 상품 생성/저장 후 navigate 는 id 기반") 가 생성모드 navigate path 가
 *           result.data.id 를 쓰고 product_code 를 쓰지 않음을 고정한다.
 *           라이브 재검(Playwright MCP)으로 신규 생성 → /admin/ecommerce/products/314/edit
 *           (숫자 id) 리다이렉트 + 리다이렉트된 폼의 데이터 로드를 확인했다.
 *
 * 탭 클릭이 없는 이유: 상품폼 탭 네비게이션은 enableScrollSpy 방식이고 추가옵션 섹션에는
 * 조건부 렌더(if)가 없다 — 모든 섹션이 처음부터 DOM 에 있고 탭은 스크롤만 이동시킨다.
 *
 * 매트릭스 (시나리오 매니페스트 product-additional-options.yaml 와 1:1):
 *   - 수정폼 진입: 기존 선택지(이름/추가금)가 round-trip 로드된다 (회귀#1)
 *   - 저장 후 재로드: 선택지가 소실 없이 영속된다 (회귀#1)
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import { findProductWithAdditionalOptionValues } from '../../fixtures/admin-product-lookup';

// 대상 상품은 실행 시점에 찾는다 (숫자 id 경로 — product_code 직접 진입은 detail API 405).
const editUrl = (id: number) => `/admin/ecommerce/products/${id}/edit`;

test.describe('상품폼 추가옵션 선택지 round-trip', () => {
  test('수정폼 진입 — 기존 추가옵션 선택지가 round-trip 로드된다 (회귀#1)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto('/admin/ecommerce/products');
    const productId = await findProductWithAdditionalOptionValues(page);
    test.skip(productId === null, '추가옵션 선택지를 보유한 상품이 없어 검증할 수 없습니다');
    await page.goto(editUrl(productId as number));

    // 그룹의 첫 선택지가 이름·추가금을 보유한 채 렌더되어야 한다 (values 미로드 시 0행 → 저장 시 소실)
    await expect(page.getByTestId('additional-value-0-0')).toBeVisible({ timeout: 15_000 });
  });

  test('저장 후 재로드 — 추가옵션 선택지가 소실 없이 영속된다 (회귀#1)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto('/admin/ecommerce/products');
    const productId = await findProductWithAdditionalOptionValues(page);
    test.skip(productId === null, '추가옵션 선택지를 보유한 상품이 없어 검증할 수 없습니다');
    await page.goto(editUrl(productId as number));

    const firstValue = page.getByTestId('additional-value-0-0');
    await expect(firstValue).toBeVisible({ timeout: 15_000 });

    // 저장 전 선택지 개수를 센다 — values 미로드 회귀에서는 저장 시 이 행들이 통째로 사라진다
    const before = await page.getByTestId(/^additional-value-0-/).count();
    expect(before).toBeGreaterThan(0);

    const save = page.getByTestId('product-save');
    await expect(save).toBeEnabled();
    await save.click();

    // 저장 완료를 응답으로 확인한 뒤 재로드한다 (토스트 문구는 로케일에 의존하므로 쓰지 않는다)
    await page.waitForResponse(
      (res) =>
        res.url().includes('/api/modules/sirsoft-ecommerce/admin/products/') &&
        res.request().method() === 'PUT',
      { timeout: 20_000 },
    );

    await page.goto(editUrl(productId as number));
    await expect(page.getByTestId('additional-value-0-0')).toBeVisible({ timeout: 15_000 });
    expect(await page.getByTestId(/^additional-value-0-/).count()).toBe(before);
  });
});
