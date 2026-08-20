/**
 * 상품폼 추가옵션 "사용/미사용" 토글 + 비우기 확인 모달 (skeleton, placeholder).
 *
 * @scenario admin-product-additional-options-toggle
 * @effects use_toggle_adds_first_option_row,
 *          not_use_opens_clear_confirm_modal,
 *          clear_modal_renders_cancel_and_confirm_buttons,
 *          confirm_clears_options_and_toggle_switches_to_not_use,
 *          cancel_keeps_options_and_modal_closes
 *
 * e2e:allow §13-D-FAIL 회귀 수정(확인 모달 footer 버튼 미렌더 + 비우기 후 토글 미갱신) 신규 시나리오 axis 부재 —
 *           본 placeholder spec(test.describe.skip)이 data-testid 보강 후 활성화될 때 함께 검증된다.
 *           레이아웃 렌더링 테스트(productOptionsAdditionalToggle.test.tsx)가
 *           (1) 확인/save_template 모달의 slots.footer 부재 + footer 버튼이 children 말미
 *               flex-justify-end Div 안에 존재(Modal.tsx 가 slots 미렌더 → children 만 렌더)함을,
 *           (2) 확인 버튼이 dot-path 인라인 setState 가 아닌 clearAdditionalOptions 전용 핸들러를
 *               호출함을 구조적으로 회귀 차단한다.
 *           핸들러 단위 테스트(optionHandlers.test.ts > clearAdditionalOptionsHandler)가
 *           clear 가 form 객체를 새 참조로 통째 교체(add 핸들러와 동일 패턴)하여
 *           form 을 watch 하는 토글 className 파생식의 리렌더 누락을 차단함을 검증한다.
 *           라이브 재검(Playwright MCP, PW-ADDOPT-001 id 306)으로 미사용→확인 클릭 시
 *           취소/확인 버튼 렌더 + 비우기 후 토글 "미사용" active 전환 + "각인 문구" 행 소멸을 확증했다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 상품옵션 탭 버튼에 data-testid="product-tab-options"
 *   2. 추가옵션 "사용"/"미사용" 토글 버튼에 data-testid="additional-option-use" / "additional-option-not-use"
 *   3. 추가옵션 행 컨테이너(additional_options_content)에 data-testid="additional-options-content"
 *   4. 확인 모달(additional_options_clear_modal)의 취소/확인 버튼에
 *      data-testid="additional-clear-cancel" / "additional-clear-confirm"
 *   5. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 admin-product-additional-options-toggle.yaml 와 1:1):
 *   - "사용" 클릭(0행)        : 첫 추가옵션 행 추가 + "사용" active
 *   - "미사용" 클릭(N행)      : 확인 모달 노출(취소/확인 버튼 렌더)
 *   - 확인                    : 옵션 비워짐 + 토글 "미사용" active 전환 + 행 소멸 + 모달 닫힘
 *   - 취소                    : 옵션 유지 + 모달만 닫힘
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

// 추가옵션 1행을 보유한 시드 상품의 수정폼 (숫자 id 경로 — product_code 직접 진입은 detail API 405)
const EDIT_URL = '/admin/ecommerce/products/306/edit';

test.describe.skip('상품폼 추가옵션 토글 + 비우기 확인 모달 (placeholder — data-testid 보강 후 활성화)', () => {
  test('미사용 클릭 — 확인 모달이 열리고 취소/확인 버튼이 렌더된다 (§13-D-FAIL footer)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(EDIT_URL);

    await page.getByTestId('product-tab-options').click();
    await page.getByTestId('additional-option-not-use').click();

    // footer 버튼이 children 으로 이동했으므로 취소/확인 모두 렌더되어야 한다 (slots.footer 였을 땐 미렌더)
    await expect(page.getByTestId('additional-clear-cancel')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('additional-clear-confirm')).toBeVisible();
  });

  test('확인 — 옵션이 비워지고 토글이 "미사용" active 로 전환된다 (§13-D-FAIL 토글 미갱신)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(EDIT_URL);

    await page.getByTestId('product-tab-options').click();
    await page.getByTestId('additional-option-not-use').click();
    await page.getByTestId('additional-clear-confirm').click();

    // clearAdditionalOptions 가 form 을 통째 교체 → 행 소멸 + "미사용" active
    await expect(page.getByTestId('additional-options-content')).not.toBeVisible();
    await expect(page.getByTestId('additional-option-not-use')).toHaveClass(/active/);
    await expect(page.getByTestId('additional-option-use')).not.toHaveClass(/active/);
  });

  test('취소 — 옵션이 유지되고 모달만 닫힌다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(EDIT_URL);

    await page.getByTestId('product-tab-options').click();
    await page.getByTestId('additional-option-not-use').click();
    await page.getByTestId('additional-clear-cancel').click();

    // 비우기 미실행 — 행 유지 + 모달 닫힘
    await expect(page.getByTestId('additional-clear-confirm')).not.toBeVisible();
    await expect(page.getByTestId('additional-options-content')).toBeVisible();
  });
});
