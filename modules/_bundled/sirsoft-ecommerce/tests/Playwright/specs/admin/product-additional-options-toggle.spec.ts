/**
 * 상품폼 추가옵션 "사용/미사용" 토글 + 비우기 확인 모달.
 *
 * @scenario admin-product-additional-options-toggle
 * @effects not_use_opens_clear_confirm_modal,
 *          clear_modal_renders_cancel_and_confirm_buttons,
 *          confirm_clears_options_and_toggle_switches_to_not_use,
 *          cancel_keeps_options_and_modal_closes
 *
 * §13-D-FAIL 회귀(확인 모달 footer 버튼 미렌더 + 비우기 후 토글 미갱신)를 브라우저에서 직접 차단한다.
 * 같은 회귀를 구조적으로 고정하는 하위 테스트는 그대로 유지된다 —
 * 레이아웃 렌더링 테스트(productOptionsAdditionalToggle.test.tsx)가 footer 버튼의 children 배치와
 * 확인 버튼의 clearAdditionalOptions 핸들러 호출을, 핸들러 단위 테스트
 * (optionHandlers.test.ts > clearAdditionalOptionsHandler)가 form 객체 통째 교체를 검증한다.
 *
 * 추가옵션 행 컨테이너는 testid 가 아니라 레이아웃이 이미 가진 DOM id(#additional_options_content)로
 * 잡는다 — 그 노드에 props 를 새로 만들어 붙이면 서빙 단계에서 props 가 [] 로 버려진다(실측).
 *
 * 탭 클릭이 없는 이유: 상품폼 탭 네비게이션은 enableScrollSpy 방식이고 추가옵션 섹션에는
 * 조건부 렌더(if)가 없다 — 모든 섹션이 처음부터 DOM 에 있고 탭은 스크롤만 이동시킨다.
 *
 * 매트릭스(시나리오 매니페스트 admin-product-additional-options-toggle.yaml 와 1:1):
 *   - "미사용" 클릭(N행)      : 확인 모달 노출(취소/확인 버튼 렌더)
 *   - 확인                    : 옵션 비워짐 + 토글 "미사용" active 전환 + 행 소멸 + 모달 닫힘
 *   - 취소                    : 옵션 유지 + 모달만 닫힘
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import { findProductWithAdditionalOptionValues } from '../../fixtures/admin-product-lookup';

// 대상 상품은 실행 시점에 찾는다 (숫자 id 경로 — product_code 직접 진입은 detail API 405).
const editUrl = (id: number) => `/admin/ecommerce/products/${id}/edit`;

test.describe('상품폼 추가옵션 토글 + 비우기 확인 모달', () => {
  test('미사용 클릭 — 확인 모달이 열리고 취소/확인 버튼이 렌더된다 (§13-D-FAIL footer)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto('/admin/ecommerce/products');
    const productId = await findProductWithAdditionalOptionValues(page);
    test.skip(productId === null, '추가옵션 선택지를 보유한 상품이 없어 검증할 수 없습니다');
    await page.goto(editUrl(productId as number));

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
    await page.goto('/admin/ecommerce/products');
    const productId = await findProductWithAdditionalOptionValues(page);
    test.skip(productId === null, '추가옵션 선택지를 보유한 상품이 없어 검증할 수 없습니다');
    await page.goto(editUrl(productId as number));

    await page.getByTestId('additional-option-not-use').click();
    await page.getByTestId('additional-clear-confirm').click();

    // clearAdditionalOptions 가 form 을 통째 교체 → 행 소멸 + "미사용" active
    await expect(page.locator('#additional_options_content')).not.toBeVisible();
    await expect(page.getByTestId('additional-option-not-use')).toHaveClass(/active/);
    await expect(page.getByTestId('additional-option-use')).not.toHaveClass(/active/);
  });

  test('취소 — 옵션이 유지되고 모달만 닫힌다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto('/admin/ecommerce/products');
    const productId = await findProductWithAdditionalOptionValues(page);
    test.skip(productId === null, '추가옵션 선택지를 보유한 상품이 없어 검증할 수 없습니다');
    await page.goto(editUrl(productId as number));

    await page.getByTestId('additional-option-not-use').click();
    await page.getByTestId('additional-clear-cancel').click();

    // 비우기 미실행 — 행 유지 + 모달 닫힘
    await expect(page.getByTestId('additional-clear-confirm')).not.toBeVisible();
    await expect(page.locator('#additional_options_content')).toBeVisible();
  });
});
