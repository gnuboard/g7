/**
 * 구매자 클레임 신청서 작성 — 교환 (#599 S11 · S15b 확장 · D29·D6·D12·P13)
 *
 * 브라우저로만 드러나는 축을 본다:
 *
 *  ① **잘못된 유형으로 들어오면 화면이 서는가.** 라우트 가드가 없으면 빈 신청서가 열리고
 *     제출에서야 거절된다. 이 축은 주문이 없어도 성립하므로 항상 실행한다.
 *  ② **[교환상품 변경] 버튼이 상품마다 있는가** (D29). 레이아웃 정적 검사는 반복 안에 있다는
 *     것까지 본다 — 실제로 상품 수만큼 그려지는지는 렌더해야 알 수 있다.
 *  ③ **사유를 고른 뒤에야 미리보기가 나가는가.** 토글마다 요청을 쏘면 왕복이 겹쳐 옛 응답이
 *     새 선택 위에 덮인다. 네트워크를 감시해야만 확인된다.
 *  ④ **교환 추가옵션**(선물 포장 등)을 고르면 그 이름이 그 상품 줄에 남고, **같은 선택이 그대로
 *     미리보기 요청에 실리는가** (S15b 확장 · §8-3). 화면이 이름만 보여 주고 요청에 싣지 않으면
 *     예고와 청구가 갈리는데, 접수는 성공하므로 어긋난 사실이 어디에도 남지 않는다.
 *
 * **2026-09-02(S15b) 변경 — 회원 주문을 API 로 훑어 발견하던 방식을 걷어냈다.**
 * `playwright:issue-token` 은 호출마다 새 회원을 만들므로 그 회원의 주문 목록은 **어떤 환경에서도
 * 항상 0건**이었다. ②③ 이 회차마다 skip 되어 "green" 이 화면을 한 번도 그리지 않았다는 뜻이 되고
 * 있었다(실측: 3건 중 2건 영구 skip). 이제 서버 계약이 고정한 응답을 세워 화면을 실제로 그린다.
 *
 *
 * 매니페스트 마킹(@scenario/@effects)은 **테스트마다** 그 케이스 위에 둔다 — 한 docblock 에
 * 여러 줄을 적으면 마킹 추출기가 첫 줄만 읽어 나머지 조합이 조용히 미커버로 남는다.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import {
  API,
  MOCK_ORDER,
  grantFunctionalConsent,
  mockClaimable,
  mockOrder,
  mockPreview,
  waitForApp,
} from '../../fixtures/claim-screens';
import type { Page } from '@playwright/test';

/** 교환이 열린 상품 (한 건) */
const EXCHANGE_ITEM = {
  option: { id: 910, name: '반팔 티셔츠', optionName: '화이트 / M', unitPrice: 29_000 },
  availableQuantity: 2,
  types: ['exchange'],
};

/** 추가옵션 그룹 — 값 하나가 유료다 */
const GIFT_WRAP = {
  id: 55,
  name: '선물 포장',
  values: [{ id: 551, name: '리본 포장', price: 2_000 }],
};

/**
 * [교환상품 변경] 모달이 읽는 상품 응답.
 *
 * 추가옵션 블록은 **'다른 옵션으로 교환' 모드에서만** 열리고, 그 모드의 적용은 매칭된 옵션을
 * 요구한다 — 그래서 옵션 그룹·조합을 함께 세운다(같은 값 = 동가 교환).
 */
const CHANGE_PRODUCT = {
  id: 5910,
  product_name: '반팔 티셔츠',
  option_groups: [{ name: '사이즈', name_localized: '사이즈', values: ['M', 'L'], values_localized: ['M', 'L'] }],
  options: [
    {
      id: 911,
      option_name: '사이즈: L',
      option_name_localized: '사이즈: L',
      option_values: [{ key: '사이즈', value: 'L' }],
      option_values_localized: { 사이즈: 'L' },
      // 재고가 없으면 [적용]이 잠긴다 — 서버도 exchange_target_out_of_stock 으로 거절한다.
      stock_quantity: 5,
    },
  ],
  additional_options: [GIFT_WRAP],
};

/**
 * 커스텀 드롭다운(composite Select)에서 한 값을 고릅니다.
 *
 * `options` 를 지정한 Select 는 네이티브 `<select>` 가 아니라 버튼 + 목록으로 렌더되므로
 * `selectOption()` 이 닿지 않는다 — 트리거를 열고 항목을 누른다.
 *
 * @param page Playwright 페이지
 * @param currentLabel 지금 트리거에 보이는 글자 (플레이스홀더 등)
 * @param optionLabel 고를 항목의 글자
 * @param index 같은 글자를 가진 트리거가 여럿일 때의 자리 (0 부터)
 * @returns 완료 Promise
 */
async function pickFromDropdown(
  page: Page,
  currentLabel: string,
  optionLabel: string,
  index = 0,
): Promise<void> {
  // 트리거는 모달 안에서 찾는다 — 같은 글자의 버튼이 뒤 화면에도 있으면 모달 백드롭에 가려
  // 클릭이 영원히 재시도된다(실측). 모달 안에도 같은 플레이스홀더가 둘이지만(옵션 · 추가옵션)
  // 고를 때마다 그 트리거의 글자가 고른 값으로 바뀌므로, 남아 있는 첫 번째가 다음 차례다.
  const trigger = page.getByRole('dialog').getByRole('button', { name: currentLabel }).nth(index);
  await expect(trigger, `드롭다운 트리거(${currentLabel})가 없다`).toBeVisible({ timeout: 15_000 });
  await trigger.click();

  // 반대로 목록은 `createPortal` 로 body 에 붙는다 — 모달 **밖**이므로 listbox 로 찾는다.
  const option = page.getByRole('listbox').getByRole('option', { name: optionLabel });
  await expect(option, `드롭다운 항목(${optionLabel})이 없다`).toBeVisible({ timeout: 15_000 });
  await option.click();
}

/**
 * 신청서 작성 화면이 그려질 때까지 기다립니다.
 *
 * `networkidle` 은 쓰지 않는다 — SPA 는 실시간 연결이 붙어 무통신 구간이 오지 않을 수 있다.
 *
 * @param page Playwright 페이지
 * @returns 완료 Promise
 */
async function waitForApplyScreen(page: Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#claim_apply_items')).toBeVisible({ timeout: 30_000 });
}

/** 교환 사유를 고릅니다 (귀책이 배송비를 정하므로 미리보기의 선행 조건이다) */
async function pickReason(page: Page): Promise<void> {
  const reason = page.locator('#claim_apply_reason select').first();
  await expect(reason).toBeVisible({ timeout: 15_000 });
  const values = await reason.locator('option').evaluateAll((nodes) =>
    nodes.map((node) => (node as HTMLOptionElement).value).filter((value) => value !== ''),
  );
  expect(values.length, '교환 사유 카탈로그가 비어 미리보기를 유발할 수 없다').toBeGreaterThan(0);
  await reason.selectOption(values[0]);
}

test.describe('구매자 클레임 신청서 작성 — 교환', () => {
  test('잘못된 유형으로 들어오면 화면이 선다 (라우트 가드)', async ({ page, memberToken }) => {
    // @scenario exchange_mode=same, preview_result=rejected
    // @effects user_claim_apply_invalid_type_shows_error_page
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
    await page.goto('/mypage/orders/does-not-exist/claims/apply/coupon');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 가드가 없으면 빈 신청서가 열리고 제출에서야 거절된다.
    await expect(page.locator('#claim_apply_items')).toHaveCount(0);
  });

  test('교환이 열린 상품마다 [교환상품 변경] 버튼이 그려진다 (D29)', async ({ page, memberToken }) => {
    // @scenario exchange_mode=same, preview_result=ok
    // @effects user_claim_apply_exchange_renders_per_item_change_button
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
    await mockOrder(page);
    await mockClaimable(page, [EXCHANGE_ITEM]);

    await page.goto(`/mypage/orders/${encodeURIComponent(MOCK_ORDER.number)}/claims/apply/exchange`);
    await waitForApplyScreen(page);

    // 신청서 전체에 걸린 '교환 방식' 라디오는 없어야 한다 — 있으면 상품마다 다른 대상을
    // 표현할 수 없다(D29).
    await expect(page.locator('#claim_apply_items input[type="radio"]')).toHaveCount(0);

    // 교환 대상 블록(그리고 그 안의 [교환상품 변경])은 선택된 행에서만 열린다.
    await page.locator('#claim_apply_items input[type="checkbox"]').last().check();

    await expect(
      page.locator('#claim_apply_items button').filter({ hasText: /교환상품 변경|Change option/ }),
    ).toHaveCount(1);
  });

  test('사유를 고른 뒤에야 미리보기가 나간다', async ({ page, memberToken }) => {
    // @scenario exchange_mode=same, preview_result=ok
    // @effects user_claim_apply_preview_runs_once_after_reason_selected
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
    await mockOrder(page);
    await mockClaimable(page, [EXCHANGE_ITEM]);
    const calls = await mockPreview(page, () => ({
      claim_type: 'exchange',
      requires_payment: true,
      shipping_fee: { fee: 6_000, extra_fee: 0, groups: [] },
      price_difference: 0,
      additional_product_amount: 0,
      refund_product_amount: 0,
      total_charge_amount: 6_000,
      refund_before_deduction: 0,
      deducted_shipping_fee: 0,
      adjustment: { refund_total: 0 },
      items: [{ order_option_id: EXCHANGE_ITEM.option.id, price_difference: 0 }],
    }));

    await page.goto(`/mypage/orders/${encodeURIComponent(MOCK_ORDER.number)}/claims/apply/exchange`);
    await waitForApplyScreen(page);

    // 상품만 골랐을 때는 아직 계산할 수 없다 (사유가 귀책을 정하고 귀책이 배송비를 정한다).
    await page.locator('#claim_apply_items input[type="checkbox"]').last().check();
    await page.waitForTimeout(1_000);
    expect(calls, '사유 없이 미리보기가 나갔다').toHaveLength(0);

    // 사유를 고르면 한 번 나간다 (debounce — 토글마다 쏘지 않는다).
    await pickReason(page);
    await expect.poll(() => calls.length, { timeout: 15_000 }).toBeGreaterThan(0);
    expect(calls, '토글마다 요청이 나갔다 (debounce 미동작)').toHaveLength(1);
  });

  test('교환 추가옵션은 이름이 상품 줄에 남고 같은 선택이 미리보기에 실린다 (§8-3)', async ({
    page,
    memberToken,
  }) => {
    // @scenario exchange_mode=change, preview_result=ok
    // @effects claim_apply_additional_options_reach_the_preview_request
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
    await mockOrder(page);
    await mockClaimable(page, [EXCHANGE_ITEM]);

    // [교환상품 변경] 모달은 상품을 다시 읽어 옵션·추가옵션 카탈로그를 그린다.
    await page.route(`**${API}/products/*`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: CHANGE_PRODUCT }),
      });
    });

    const calls = await mockPreview(page, () => ({
      claim_type: 'exchange',
      requires_payment: true,
      shipping_fee: { fee: 6_000, extra_fee: 0, groups: [] },
      price_difference: 2_000,
      additional_product_amount: 2_000,
      refund_product_amount: 0,
      total_charge_amount: 8_000,
      refund_before_deduction: 0,
      deducted_shipping_fee: 0,
      adjustment: { refund_total: 0 },
      items: [{ order_option_id: EXCHANGE_ITEM.option.id, price_difference: 2_000 }],
    }));

    await page.goto(`/mypage/orders/${encodeURIComponent(MOCK_ORDER.number)}/claims/apply/exchange`);
    await waitForApplyScreen(page);

    await page.locator('#claim_apply_items input[type="checkbox"]').last().check();
    await pickReason(page);
    await expect.poll(() => calls.length, { timeout: 15_000 }).toBeGreaterThan(0);
    const beforePick = calls.length;

    await page
      .locator('#claim_apply_items button')
      .filter({ hasText: /교환상품 변경|Change option/ })
      .first()
      .click();

    // 추가옵션 블록은 '다른 옵션으로 교환' 모드에서만 열린다 — 포장은 그 모드의 하위 축이다.
    const dialog = page.getByRole('dialog');
    const changeMode = dialog.locator('input[type="radio"][value="change"]');
    await expect(changeMode, '교환 방식 라디오가 모달에 없다').toBeVisible({ timeout: 15_000 });
    await changeMode.check();

    await pickFromDropdown(page, '선택하세요', 'L');
    await expect(
      dialog.getByText('선물 포장', { exact: true }),
      '추가옵션 그룹이 모달에 그려지지 않았다',
    ).toBeVisible({ timeout: 15_000 });
    await pickFromDropdown(page, '선택하세요', '리본 포장');

    await dialog.getByRole('button', { name: '적용' }).click();

    // ① 고른 추가옵션의 **이름**이 그 상품 줄에 남는다 — 접수 전이라 서버 스냅샷이 아직 없다.
    await expect
      .poll(async () => (await page.locator('#claim_apply_items').innerText()).includes('리본 포장'), {
        timeout: 15_000,
      })
      .toBe(true);

    // ② 그 선택이 미리보기 요청에 그대로 실린다 — 화면이 이름만 보여 주고 요청에 싣지 않으면
    //    예고와 청구가 서로 다른 것을 계산한다.
    await expect.poll(() => calls.length, { timeout: 15_000 }).toBeGreaterThan(beforePick);

    const latest = calls[calls.length - 1];
    const sentItems = (latest?.items ?? []) as Array<Record<string, any>>;
    const sent = sentItems.find(
      (item) => Number(item.order_option_id) === EXCHANGE_ITEM.option.id,
    );

    expect(sent, '미리보기 요청에 그 상품 줄이 없다').toBeTruthy();
    expect(
      sent?.custom_options?.selections?.[String(GIFT_WRAP.id)],
      '고른 추가옵션이 미리보기 요청에 실리지 않았다',
    ).toBe(GIFT_WRAP.values[0].id);
  });
});
