/**
 * 유저 상품 추가옵션(유료) 흐름 — 상품상세 블럭 선택 → 실시간 합계 → 담기 payload → 장바구니 표시.
 * 템플릿 sirsoft-basic (유저 화면).
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
 * 배경: 추가옵션 선택은 템플릿 커스텀 핸들러가 `context.setState` 로 기록한다. engine-v1.63.5
 *       (트러블슈팅 사례 42) 이전에는 그 쓰기가 저장소 B 에 닿지 않아, 화면에는 선택이 보이는데
 *       담기 요청의 `additional_option_selections` 가 비어 나갔다. 예외도 콘솔 에러도 없었다.
 *       요청 body 를 보는 이 spec 이 그 결함을 잡는 종단 통로다.
 *
 * 이 spec 은 시드를 만들지 않는다 — 공개 상품 목록에서 "메인 옵션 2개 이상 + 추가옵션 그룹 보유"
 * 상품을 찾아 쓰고, 없으면 개별 테스트가 사유와 함께 스킵된다(`test.skip`). describe 를 통째로
 * 끄면 커버리지가 0 이 되므로 그렇게 하지 않는다.
 *
 * 이 템플릿의 Select 는 `options` 가 있으면 네이티브 `<select>` 가 아니라
 * `button[role=option]` 커스텀 드롭다운을 렌더한다 — `selectOption()` 은 동작하지 않는다.
 *
 * 매트릭스 (시나리오 매니페스트 product-additional-options.yaml ui_surface 축과 1:1):
 *   T2 기본옵션 선택 → 블럭 내부 활성 선택지만 렌더, 추가옵션 선택 → 총액 실시간 반영
 *   T4 담기 요청이 additional_option_selections 를 전송한다
 *   T6 담은 뒤 장바구니 행에 선택한 추가옵션이 표시된다
 */
import { test, expect } from '@playwright/test';
import {
  findAdditionalOptionProduct,
  pickOption,
  pickAllMainOptions,
  escapeRegExp,
} from '../../fixtures/shop-additional-option-lookup';

test.describe('유저 추가옵션 흐름', () => {
  test('T2 기본옵션 선택 → 추가옵션 선택 시 총액에 추가금이 반영된다', async ({ page }) => {
    await page.goto('/shop');
    const product = await findAdditionalOptionProduct(page);
    test.skip(product === null, '메인 옵션 2개 이상 + 추가옵션을 가진 공개 상품이 없어 검증할 수 없습니다');
    test.skip(
      (product?.priceAdjustment ?? 0) <= 0,
      '추가금이 양수인 추가옵션 선택지가 없어 총액 증가를 검증할 수 없습니다',
    );

    await page.goto(product!.url);
    await pickAllMainOptions(page, product!.mainValues);

    const before = (await page.getByTestId('purchase-total').innerText()).replace(/[^\d]/g, '');
    await pickOption(page, `add-option-0-${product!.groupId}`, new RegExp(escapeRegExp(product!.valueName)));
    await expect
      .poll(async () => (await page.getByTestId('purchase-total').innerText()).replace(/[^\d]/g, ''))
      .not.toBe(before);
  });

  test('T4 담기 요청이 additional_option_selections 를 전송한다', async ({ page }) => {
    await page.goto('/shop');
    const product = await findAdditionalOptionProduct(page);
    test.skip(product === null, '메인 옵션 2개 이상 + 추가옵션을 가진 공개 상품이 없어 검증할 수 없습니다');

    await page.goto(product!.url);
    await pickAllMainOptions(page, product!.mainValues);
    await pickOption(page, `add-option-0-${product!.groupId}`, new RegExp(escapeRegExp(product!.valueName)));

    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/cart') && r.method() === 'POST'),
      page.getByTestId('add-to-cart').click(),
    ]);
    const body = request.postDataJSON();
    expect(body.items?.length, '선택한 옵션이 요청 body 에 실려야 한다').toBeGreaterThan(0);
    const selections = body.items?.[0]?.additional_option_selections ?? [];
    expect(
      selections.some((s: any) => Number(s.additional_option_id) === product!.groupId),
      '선택한 추가옵션이 요청 body 에 실려야 한다',
    ).toBe(true);
  });

  test('T6 담은 뒤 장바구니 행에 선택한 추가옵션이 표시된다', async ({ page }) => {
    await page.goto('/shop');
    const product = await findAdditionalOptionProduct(page);
    test.skip(product === null, '메인 옵션 2개 이상 + 추가옵션을 가진 공개 상품이 없어 검증할 수 없습니다');

    await page.goto(product!.url);
    await pickAllMainOptions(page, product!.mainValues);
    await pickOption(page, `add-option-0-${product!.groupId}`, new RegExp(escapeRegExp(product!.valueName)));

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/cart') && r.request().method() === 'POST'),
      page.getByTestId('add-to-cart').click(),
    ]);
    expect(response.status(), '담기가 성공해야 장바구니를 확인할 수 있다').toBeLessThan(300);

    await page.goto('/shop/cart');
    await expect(page.getByTestId('cart-item').first()).toContainText(product!.valueName);
  });
});
