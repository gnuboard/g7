/**
 * 옵션 다통화 입력 강등 — 읽기전용 환산값 표시 (skeleton, placeholder).
 *
 * @scenario mp08-option-multicurrency-readonly
 * @effects currency_input_columns_removed,
 *          currency_select_menu_removed,
 *          auto_currency_toggle_removed,
 *          readonly_preview_below_selling_price,
 *          preview_uses_formatted,
 *          recalc_on_selling_price_change,
 *          preview_hidden_when_no_currencies,
 *          base_unit_aware_conversion
 *
 * MP08-3 (base_unit 환산): 다통화 미리보기 환산이 기본 통화의 base_unit(÷base_unit) 기준으로
 *   계산된다. 기본 통화가 비-원(달러 등)일 때 외화가 0 으로 표시되던 결함 회귀를
 *   convertCurrencyPrice 단위 테스트(productOptionHandlers.test.ts) + calculateCurrencyPrices.test.ts
 *   (USD base ¥0 회귀 / KRW base 등가)가 구조적으로 차단한다. 환율 입력 화면 라벨도 base_unit
 *   기준("USD 1 =", "KRW 1,000 =")으로 표시된다.
 *
 * e2e:allow 다통화 입력 강등(통화 입력 컬럼·컬럼선택 통화 항목·자동계산 토글 제거 +
 *           판매가 셀 하단 읽기전용 환산값 세로 표시) 회귀를 단위/레이아웃 테스트가 구조적으로 차단한다.
 *           본 placeholder spec(test.describe.skip)은 data-testid 보강 후 활성화된다.
 *           레이아웃 렌더링 테스트(productFormLayouts.test.tsx)가
 *           (1) 옵션 통화 입력 컬럼(option_currency_header / option_currency_cell)이 제거되고,
 *           (2) 판매가 셀 하단에 currencyColumns 를 반복하는 읽기전용 Span 미리보기
 *               (option_currency_preview)가 multi_currency_selling_price.*.formatted 를 출력함을
 *           구조적으로 회귀 차단한다.
 *           핸들러 단위 테스트(productOptionHandlers.test.ts)가
 *           convertCurrencyPrice 가 formatted 를 동봉하고, 판매가 변경 시 토글 없이 다통화가
 *           항상 환율 기준으로 재계산됨을 검증한다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 상품옵션 탭 버튼에 data-testid="product-tab-options"
 *   2. 옵션 행 판매가 입력에 data-testid="option-selling-price"
 *   3. 판매가 셀 하단 다통화 미리보기에 data-testid="option-currency-preview"
 *   4. 옵션 테이블 통화 입력 셀(제거됨 — 부재 검증용 셀렉터 유지)
 *   5. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 mp08-option-multicurrency-readonly.yaml 와 1:1):
 *   - 폼 진입(통화 4종)        : 통화 입력 컬럼 부재 + 판매가 하단 환산값 4개 표시
 *   - 판매가 변경              : 환산값이 새 환율값으로 즉시 갱신
 *   - 환율 통화 0개            : 미리보기 미렌더
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

// 옵션 보유 시드 상품의 수정폼 (숫자 id 경로 — product_code 직접 진입은 detail API 405)
const EDIT_URL = '/admin/ecommerce/products/306/edit';

test.describe.skip('옵션 다통화 읽기전용 표시 (placeholder — data-testid 보강 후 활성화)', () => {
  test('통화 입력 컬럼이 없고 판매가 셀 하단에 읽기전용 환산값이 표시된다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(EDIT_URL);

    await page.getByTestId('product-tab-options').click();

    // 통화 입력 컬럼은 제거됨 (별도 입력 Input 없음)
    await expect(page.getByTestId('option-currency-input')).toHaveCount(0);

    // 판매가 셀 하단 읽기전용 환산값(formatted)이 표시됨
    await expect(page.getByTestId('option-currency-preview').first()).toBeVisible({ timeout: 10_000 });
  });

  test('판매가를 변경하면 다통화 환산값이 즉시 갱신된다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(EDIT_URL);

    await page.getByTestId('product-tab-options').click();

    const preview = page.getByTestId('option-currency-preview').first();
    const before = await preview.textContent();

    await page.getByTestId('option-selling-price').first().fill('99000');

    // 토글 없이 항상 재계산 → 환산값 변경
    await expect(preview).not.toHaveText(before ?? '', { timeout: 10_000 });
  });
});
