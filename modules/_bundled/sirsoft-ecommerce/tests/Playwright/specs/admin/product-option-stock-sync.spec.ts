/**
 * 옵션 생성/추가/삭제 시 원 상품 재고 자동 동기화 (skeleton, placeholder).
 *
 * @scenario mp-option-stock-sync
 * @effects product_stock_synced_on_generate,
 *          product_stock_synced_on_add_row,
 *          product_stock_synced_on_delete,
 *          product_stock_synced_on_field_update,
 *          has_options_set_on_generate,
 *          has_options_cleared_on_delete_all
 *
 * 배경: 옵션 생성(generateOptions)·행 추가(addOptionRow)·삭제(deleteOption) 시 원 상품
 *   재고(form.stock_quantity)가 활성 옵션 재고 합계로 즉시 갱신되지 않아, 사용자가 옵션 재고를
 *   한 번 조작(updateFormOptionField)해야만 반영되던 결함의 회귀를 차단한다.
 *   상품 재고 입력칸은 옵션 보유 시 읽기전용(disabled)으로, form.stock_quantity 표시값이
 *   옵션 합계와 항상 일치해야 한다.
 *
 * e2e:allow 옵션 목록 변경 시 상품 재고 자동 동기화 회귀를 핸들러 단위 테스트가 구조적으로 차단한다.
 *           - optionHandlers.generateOptions.test.ts: 옵션 N개 생성 시 form.stock_quantity = 옵션 합계,
 *             카테시안 곱(2×2=4) 합계, 수정 모드 0, 기존 옵션 병합 합계 검증
 *           - productOptionHandlers.test.ts: addOptionRow 행 추가 시 상품 재고 합계 + has_options=true,
 *             updateFormOptionField 재고/활성 변경 시 합산(기존)
 *           - optionHandlers.test.ts: deleteOption 삭제 시 남은 활성 옵션 합계 재계산 +
 *             마지막 옵션 삭제 시 has_options=false
 *           본 placeholder spec(test.describe.skip)은 옵션 탭/재고 표시 data-testid 보강 후 활성화한다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 상품옵션 탭 버튼에 data-testid="product-tab-options"
 *   2. 옵션 일괄 생성 버튼에 data-testid="option-generate"
 *   3. 행 추가 버튼에 data-testid="option-add-row"
 *   4. 원 상품 재고 표시(읽기전용)에 data-testid="product-total-stock"
 *   5. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 mp-option-stock-sync.yaml 와 1:1):
 *   - 옵션 일괄 생성(색상 2값) : 상품 재고 = 2 (옵션당 기본 1 × 2)
 *   - 행 추가                 : 상품 재고 += 신규 행 재고
 *   - 옵션 삭제               : 상품 재고 = 남은 활성 옵션 합계
 *   - 옵션 전부 삭제          : has_options 해제
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

// 신규 상품 등록 폼 (옵션 미보유 → 생성으로 재고 동기화 검증)
const CREATE_URL = '/admin/ecommerce/products/create';

test.describe.skip('옵션 목록 변경 시 상품 재고 자동 동기화 (placeholder — data-testid 보강 후 활성화)', () => {
  test('옵션 2개 일괄 생성 시 원 상품 재고가 옵션 재고 합계(2)로 즉시 표시된다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(CREATE_URL);

    await page.getByTestId('product-tab-options').click();

    // 옵션명/값 입력 후 일괄 생성 (색상: 레드, 블루)
    await page.getByTestId('option-generate').click();

    // 원 상품 재고(읽기전용)가 사용자 조작 없이 옵션 합계로 표시됨
    await expect(page.getByTestId('product-total-stock')).toHaveValue('2', { timeout: 10_000 });
  });

  test('옵션 삭제 시 원 상품 재고가 남은 활성 옵션 합계로 재계산된다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(CREATE_URL);

    await page.getByTestId('product-tab-options').click();
    await page.getByTestId('option-generate').click();
    await expect(page.getByTestId('product-total-stock')).toHaveValue('2', { timeout: 10_000 });

    // 옵션 1개 삭제 → 상품 재고 1
    await page.getByTestId('option-delete').first().click();
    await expect(page.getByTestId('product-total-stock')).toHaveValue('1', { timeout: 10_000 });
  });
});
