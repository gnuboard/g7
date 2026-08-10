/**
 * 유저 상품 추가옵션(유료) 흐름 — 상품상세 블럭 선택 → 담기/바로구매 → 장바구니 모달 재선택 → 체크아웃/주문 표시.
 * 템플릿 sirsoft-basic (유저 화면). (skeleton, placeholder)
 *
 * @scenario product-additional-options
 * @effects detail_block_renders_active_values_only,
 *          detail_realtime_subtotal_includes_additional,
 *          detail_required_unselected_blocks_submit,
 *          submit_sends_additional_option_selections,
 *          cart_item_displays_selected_additional,
 *          cart_modal_reselect_and_patch,
 *          order_display_snapshot_additional_rows
 *
 * e2e:allow 세션 C(유저 흐름 + 표시) 신규 UI — 단위/레이아웃 렌더 테스트로 결함을 1차 차단하고,
 *           브라우저 회귀는 본 placeholder(test.describe.skip)가 data-testid 보강 + 실 도메인 시드 후 활성화될 때 검증한다.
 *           현재 커버리지:
 *           (1) 핸들러 로직 — templates/_bundled/sirsoft-basic/src/handlers/__tests__/productOptionsAdditional.test.ts
 *               (블럭별 추가옵션 선택/해제, 추가금×수량 배수 D6, 다통화 환산, payload 변환) green.
 *           (2) 레이아웃 구조/렌더 — templates/_bundled/sirsoft-basic/src/__tests__/layouts/shopAdditionalOptions.test.tsx
 *               (블럭 내부 그룹 iteration, setBlockAdditionalOption 호출, 담기 body 의 additional_option_selections,
 *                필수 미선택 가드, 레거시 자유텍스트 스텁 제거, 장바구니/체크아웃/주문완료/마이페이지 스냅샷 표시) green.
 *           (3) 관리자 주문서 스냅샷 별행 — resources/js/__tests__/layouts/adminOrderInfoAdditionalOptions.test.ts green.
 *           백엔드 계약(담기/옵션변경/체크아웃 입력, CartItemResource/OrderOptionResource 출력, 422 reason)은
 *           세션 A PHPUnit 으로 검증됨.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다:
 *   1. 추가옵션 그룹 2개(필수 "각인" / 선택 "포장") × 선택지 변종(추가금 0/양수/비활성) 보유 상품 시드 (§12.C 전제)
 *   2. _purchase_card 블럭 추가옵션 Select 에 data-testid="add-option-{itemIndex}-{groupId}"
 *   3. 담기/바로구매 버튼 + 필수 미선택 토스트에 data-testid
 *   4. _cart_item 추가옵션 라인 + _modal_cart_option_change 재선택 Select 에 data-testid
 *   5. PLAYWRIGHT_BASE_URL = 실 도메인, test.describe.skip → test.describe
 *
 * 매트릭스 (시나리오 매니페스트 product-additional-options.yaml ui_surface 축과 1:1):
 *   T1 상품상세: 기본옵션 미선택 → 추가옵션 미노출 (D10)
 *   T2 기본옵션 선택 → 블럭 내부 활성 선택지만 렌더(V6 비활성 제외), 추가옵션 선택 → 소계·총액 실시간(옵션가+추가옵션×수량)
 *   T3 같은 옵션 2블럭 → 블럭별 독립 추가옵션, 수량 3 → 추가금×3 (D6)
 *   T4 담기/바로구매 → additional_option_selections 전송, 필수 미선택 → 422 additional_option_required / 잘못된 value → 422 additional_option_invalid
 *   T5 장바구니 합산 키 — (옵션+추가옵션 해시) 동일 합산 / 상이 별개 행 (D3)
 *   T6 새로고침 → 장바구니 추가옵션 영속(CartItemResource.additional_options)
 *   T7 옵션변경 모달 추가옵션 재선택 → 실시간 재계산 → PATCH → 부모 정합
 *   표시: 체크아웃/주문완료/마이페이지/관리자주문서 스냅샷 별행 (D14), 과거 주문(추가옵션 없음) 깨짐 0
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

// 추가옵션 보유 시드 상품 상세 (실 도메인 시드 후 경로 확정)
const PRODUCT_URL = '/shop/products/{ADDOPT_PRODUCT_ID}';

test.describe.skip('유저 추가옵션 흐름 (placeholder — data-testid 보강 + 시드 후 활성화)', () => {
  test('T2 기본옵션 선택 → 블럭 내부 추가옵션 선택 시 총액이 추가금만큼 증가한다', async ({ page }) => {
    await page.goto(PRODUCT_URL);
    // 기본옵션 선택 → 블럭 생성
    await page.getByTestId('option-group-0').selectOption({ index: 1 });
    // 추가옵션(각인 추가 +5000) 선택
    await page.getByTestId('add-option-0-1').selectOption({ label: /각인 추가/ });
    // 총액에 +5,000 반영
    await expect(page.getByTestId('purchase-total')).toContainText('5,000');
  });

  test('T4 필수 추가옵션 미선택 시 담기 차단 토스트', async ({ page }) => {
    await page.goto(PRODUCT_URL);
    await page.getByTestId('option-group-0').selectOption({ index: 1 });
    // 필수 그룹 미선택 상태로 담기
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByText(/필수 추가옵션/)).toBeVisible();
  });

  test('T7 장바구니 옵션변경 모달에서 추가옵션 재선택 후 PATCH 정합', async ({ page, customerToken }) => {
    await authenticatePage(page, customerToken);
    await page.goto('/shop/cart');
    await page.getByTestId('cart-change-option').first().click();
    await page.getByTestId('modal-add-option-1').selectOption({ label: /각인 추가/ });
    await page.getByTestId('modal-apply').click();
    // 장바구니 행에 변경된 추가옵션 반영
    await expect(page.getByTestId('cart-item').first()).toContainText(/각인 추가/);
  });
});
