/**
 * 상품상세 옵션 품절 표시/차단 (MP07 §2 — U8/A34, computed 방식).
 *
 * @scenario shop-option-soldout-display
 * @effects dropdown_sold_out_value_disabled,
 *          dropdown_sold_out_label_suffix,
 *          conservative_partial_not_disabled,
 *          admin_zero_stock_reflects_in_dropdown
 *
 * 배경: 상품상세 옵션 드롭다운에서 재고 없는 옵션을 "(품절)" 비활성으로 표시한다.
 *       show.json 의 computed.optionChoices(백엔드 is_sold_out 플래그를 읽는 순수 인라인
 *       배열식)가 그룹별 선택지를 만들고, _purchase_card.json 의 select 가 그 결과를 참조한다.
 *       여러 옵션 조합 상품에서는 "그 값을 포함하면서 현재 상위 선택과 호환되는 조합이 전부
 *       품절"일 때만 비활성(보수적).
 *
 * 본 spec 의 UI 매트릭스는 다음 사전 작업 완료 후 활성화한다(data-testid 보강):
 *   1. 상품상세 옵션 select(option-select)의 option 요소에 품절 식별 마커
 *      (예: data-testid="option-{groupIndex}-value-{value}" + disabled 속성)
 *   2. 시드: 재고0 옵션 1건 + 정상 옵션 1건 보유 상품(ecommerce-seed)
 *   3. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 mp07-cart-add-availability.yaml 와 1:1):
 *   - 단일 그룹 재고0 옵션  : 드롭다운 disabled + "(품절)" 라벨
 *   - 다중 그룹 보수적 판정  : 일부만 품절이면 선택 가능, 호환 조합 전부 품절이면 비활성
 *   - A34 관리자 재고0 저장 → 유저 상세 새로고침 → 품절 반영
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

test.describe.skip('상품상세 옵션 품절 표시 (computed — placeholder, data-testid 보강 후 활성화)', () => {
  test('재고0 옵션은 드롭다운에서 비활성 + "(품절)" 라벨', async ({ page }) => {
    await page.goto('/shop/products/{seededProductId}');
    const soldOut = page.getByTestId('option-0-value-품절색상');
    await expect(soldOut).toBeDisabled();
    await expect(soldOut).toContainText('품절');
  });

  test('다중 그룹 — 호환 조합 일부만 품절이면 선택 가능', async ({ page }) => {
    await page.goto('/shop/products/{seededMultiOptionProductId}');
    // 빨강(빨강-L 정상 존재) 선택 가능
    await expect(page.getByTestId('option-0-value-빨강')).toBeEnabled();
  });

  test('A34 — 관리자 옵션 재고0 저장 → 유저 상세 새로고침 시 품절 반영', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto('/shop/products/{seededProductId}');
    await page.reload();
    await expect(page.getByTestId('option-0-value-품절색상')).toBeDisabled();
  });
});
