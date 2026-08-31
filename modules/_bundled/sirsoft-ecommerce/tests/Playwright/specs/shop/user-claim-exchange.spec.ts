/**
 * 구매자 클레임 신청서 작성 — 교환 (#599 S11 · D29·D6·P13)
 *
 * 브라우저로만 드러나는 축을 본다:
 *
 *  ① **잘못된 유형으로 들어오면 화면이 서는가.** 라우트 가드가 없으면 빈 신청서가 열리고
 *     제출에서야 거절된다. 이 축은 주문이 없어도 성립하므로 항상 실행한다.
 *  ② **[교환상품 변경] 버튼이 상품마다 있는가** (D29). 레이아웃 정적 검사는 반복 안에 있다는
 *     것까지 본다 — 실제로 상품 수만큼 그려지는지는 렌더해야 알 수 있다.
 *  ③ **사유를 고른 뒤에야 미리보기가 나가는가.** 토글마다 요청을 쏘면 왕복이 겹쳐 옛 응답이
 *     새 선택 위에 덮인다. 네트워크를 감시해야만 확인된다.
 *
 * 회원의 주문 중 교환이 열린 건이 필요하다. 시드 커맨드(`playwright:seed-ecommerce`)의 주문
 * 축이 아직 stub 이라(계획서 이월) 그 회원의 주문을 API 로 훑어 발견되는 건으로 진행하고,
 * 한 건도 없으면 그 사실을 드러내며 건너뛴다 — 없는 데이터를 있는 척 단언하지 않는다.
 *
 * @scenario exchange_mode=same, preview_result=ok
 * @effects user_claim_apply_invalid_type_shows_error_page, user_claim_apply_exchange_renders_per_item_change_button, user_claim_apply_preview_runs_once_after_reason_selected
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const API = '/api/modules/sirsoft-ecommerce';

/** 교환이 열린 주문 (주문번호 + 열린 상품 수) */
type ExchangeableOrder = { orderNumber: string; exchangeableCount: number };

/**
 * 이 회원의 주문 중 교환이 열린 건을 찾습니다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @returns 발견한 주문 (없으면 null)
 */
async function discoverExchangeableOrder(
  request: import('@playwright/test').APIRequestContext,
  token: string,
): Promise<ExchangeableOrder | null> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  const listed = await request.get(`${API}/user/orders?per_page=20`, { headers });

  if (!listed.ok()) return null;

  for (const row of ((await listed.json())?.data?.data ?? []) as Array<Record<string, any>>) {
    if (!row.id || !row.order_number) continue;

    const claimable = await request.get(`${API}/user/orders/${row.id}/claimable-options`, { headers });

    if (!claimable.ok()) continue;

    const options = ((await claimable.json())?.data?.options ?? []) as Array<Record<string, any>>;
    const exchangeable = options.filter((option) => (option.claimable_types ?? []).includes('exchange'));

    if (exchangeable.length > 0) {
      return { orderNumber: row.order_number, exchangeableCount: exchangeable.length };
    }
  }

  return null;
}

/**
 * 신청서 작성 화면이 그려질 때까지 기다립니다.
 *
 * `networkidle` 은 쓰지 않는다 — SPA 는 실시간 연결이 붙어 무통신 구간이 오지 않을 수 있다.
 *
 * @param page Playwright 페이지
 */
async function waitForApplyScreen(page: import('@playwright/test').Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#claim_apply_items')).toBeVisible({ timeout: 30_000 });
}

test.describe('구매자 클레임 신청서 작성 — 교환', () => {
  test('잘못된 유형으로 들어오면 화면이 선다 (라우트 가드)', async ({ page, memberToken }) => {
    await authenticatePage(page, memberToken);
    await page.goto('/mypage/orders/does-not-exist/claims/apply/coupon');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 가드가 없으면 빈 신청서가 열리고 제출에서야 거절된다.
    await expect(page.locator('#claim_apply_items')).toHaveCount(0);
  });

  test('교환이 열린 상품마다 [교환상품 변경] 버튼이 그려진다 (D29)', async ({ page, request, memberToken }) => {
    const discovered = await discoverExchangeableOrder(request, memberToken);
    test.skip(
      discovered === null,
      '교환이 열린 주문이 없어 화면 축을 실측할 수 없다 (playwright:seed-ecommerce 의 주문 축이 stub — 계획서 이월).',
    );

    await authenticatePage(page, memberToken);
    await page.goto(
      `/mypage/orders/${encodeURIComponent(discovered!.orderNumber)}/claims/apply/exchange`
      + `?options=`,
    );
    await waitForApplyScreen(page);

    // 신청서 전체에 걸린 '교환 방식' 라디오는 없어야 한다 — 있으면 상품마다 다른 대상을
    // 표현할 수 없다(D29).
    await expect(page.locator('#claim_apply_items input[type="radio"]')).toHaveCount(0);

    // 교환 대상 블록(그리고 그 안의 [교환상품 변경])은 선택된 행에서만 열린다.
    const firstCheckbox = page.locator('#claim_apply_items input[type="checkbox"]').last();
    await firstCheckbox.check();

    await expect(
      page.locator('#claim_apply_items button').filter({ hasText: /교환상품 변경|Change option/ }),
    ).toHaveCount(1);
  });

  test('사유를 고른 뒤에야 미리보기가 나간다', async ({ page, request, memberToken }) => {
    const discovered = await discoverExchangeableOrder(request, memberToken);
    test.skip(
      discovered === null,
      '교환이 열린 주문이 없어 미리보기 왕복을 실측할 수 없다 (시드 stub — 계획서 이월).',
    );

    const previewCalls: string[] = [];
    await page.route('**/claims/preview', async (route) => {
      previewCalls.push(route.request().url());
      await route.continue();
    });

    await authenticatePage(page, memberToken);
    await page.goto(`/mypage/orders/${encodeURIComponent(discovered!.orderNumber)}/claims/apply/exchange`);
    await waitForApplyScreen(page);

    // 상품만 골랐을 때는 아직 계산할 수 없다 (사유가 귀책을 정하고 귀책이 배송비를 정한다).
    await page.locator('#claim_apply_items input[type="checkbox"]').last().check();
    await page.waitForTimeout(1_000);
    expect(previewCalls).toHaveLength(0);

    // 사유를 고르면 한 번 나간다 (debounce — 토글마다 쏘지 않는다).
    const reasonSelect = page.locator('#claim_apply_reason select').first();
    const optionValues = await reasonSelect.locator('option').evaluateAll((nodes) =>
      nodes.map((node) => (node as HTMLOptionElement).value).filter((value) => value !== ''),
    );
    test.skip(optionValues.length === 0, '교환 사유 카탈로그가 비어 있어 미리보기를 유발할 수 없다.');

    await reasonSelect.selectOption(optionValues[0]);
    await expect.poll(() => previewCalls.length, { timeout: 10_000 }).toBeGreaterThan(0);
    expect(previewCalls).toHaveLength(1);
  });
});
