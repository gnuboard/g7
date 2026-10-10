/**
 * 마이페이지 주문 상세 「주문 이력」에 구매확정일시가 실제로 렌더되는지 검증 (#599 S2-D8).
 *
 * 결함: 주문의 생애주기는 주문 → 결제 → 배송완료 → **구매확정** → (취소) 로 가는데,
 *   「주문 이력」 섹션이 구매확정일시만 빠뜨리고 있었다. 구매확정은 적립이 확정되고
 *   취소·환불이 막히는 전이라 고객에게 가장 중요한 분기 중 하나인데, 확정을 마친 주문을
 *   열어도 이력에는 「주문일시 · 결제일시」 두 줄만 섰다.
 *
 *   백엔드는 이미 값을 내보내고 있었다 — `OrderResource` 의 `confirmed_at_formatted`.
 *   DB 에도 값이 있었다. **화면 쪽 행과 라벨만 없었다.**
 *
 *   실측(2026-09-15 · 주문 107):
 *     order_status           = confirmed
 *     confirmed_at_formatted = "2026-09-15 16:52:28"  (API 응답에 실림)
 *     화면 「주문 이력」      = 주문일시 · 결제일시 **2줄뿐**
 *
 *   같은 확인에서 `delivered_at` 행이 부르는 라벨 키가 ko·en 양쪽에 없다는 것도 드러났다 —
 *   그 행이 뜨는 주문에서는 원문 키가 그대로 노출됐을 것이다.
 *
 * 단위/정적:
 *   - templates/_bundled/sirsoft-basic/__tests__/layouts/mypage-order-history-milestones.test.tsx
 *     가 레이아웃 JSON 의 행 구성·바인딩·라벨 키 실재를 잠근다.
 *   이 spec 은 브라우저 수준 — **API 가 준 값이 실제로 화면에 찍히는지**를 담당한다.
 *   레이아웃에 행이 있어도 DB 동기화(`template:refresh-layout`)가 빠지면 화면은 그대로인데,
 *   정적 테스트로는 그것을 잡을 수 없다(실측: 파일만 고치고 15분을 헤맸다).
 *
 * @scenario order_history_row=confirmed_at
 * @axes source=api,screen
 * @effects order_history_shows_purchase_confirmed_at
 */
import { test, expect, type Page } from '@playwright/test';

import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

/**
 * GDPR 쿠키 동의 배너를 닫습니다.
 *
 * 신규 컨텍스트에는 동의 이력이 없어 배너가 하단 고정으로 뜬다. 없으면 조용히 지나간다.
 *
 * @param page 대상 페이지
 */
async function dismissCookieBanner(page: Page): Promise<void> {
  const accept = page.getByRole('button', { name: '모두 동의' }).first();

  try {
    await accept.waitFor({ state: 'visible', timeout: 3000 });
  } catch {
    return;
  }

  await accept.click();
  await accept.waitFor({ state: 'hidden', timeout: 5000 });
}

/**
 * 구매확정(`confirmed`)까지 간 주문을 하나 고릅니다.
 *
 * 주문번호를 하드코딩하지 않는다 — 시드가 바뀌면 조용히 깨지고, 이 spec 이 보려는 것은
 * "특정 주문" 이 아니라 "확정된 주문이면 이력에 그 시각이 뜬다" 는 규칙이다.
 *
 * @param page 인증 토큰이 주입된 페이지
 * @returns 확정 주문의 주문번호와 API 가 준 확정일시 (없으면 null)
 */
async function findConfirmedOrder(page: Page): Promise<{ orderNumber: string; confirmedAt: string } | null> {
  return page.evaluate(async () => {
    const token = localStorage.getItem('auth_token');
    const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` };

    const list = await (await fetch('/api/modules/sirsoft-ecommerce/user/orders?per_page=50', { headers })).json();
    const rows = list?.data?.data ?? list?.data ?? [];

    for (const row of rows) {
      if (row?.order_status !== 'confirmed') continue;

      const detail = await (
        await fetch(`/api/modules/sirsoft-ecommerce/user/orders/${row.order_number}`, { headers })
      ).json();
      const confirmedAt = detail?.data?.confirmed_at_formatted ?? null;

      if (confirmedAt) {
        return { orderNumber: row.order_number as string, confirmedAt: confirmedAt as string };
      }
    }

    return null;
  });
}

test.describe('#599 S2-D8 주문 이력 구매확정일시', () => {
  /**
   * @scenario order_history_row=confirmed_at
   *
   * @effects order_history_shows_purchase_confirmed_at
   */
  test('확정 주문의 이력에 API 가 준 구매확정일시가 그대로 찍힌다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    await page.goto('/mypage/orders');
    await dismissCookieBanner(page);

    const target = await findConfirmedOrder(page);

    // 확정 주문이 없는 환경에서는 조용히 통과시키지 않고 건너뛴다 —
    // "아무것도 측정하지 않고 green" 이 되는 것을 막는다.
    test.skip(target === null, '구매확정(confirmed) 상태의 주문이 없어 측정할 수 없습니다.');

    await page.goto(`/mypage/orders/${target!.orderNumber}`);
    await dismissCookieBanner(page);

    // 이력 섹션이 그려진 것을 먼저 확정한다 — 렌더 전에 읽으면 아무것도 없는 채로 통과한다.
    await expect(page.getByText('주문 이력').first()).toBeVisible({ timeout: 15000 });

    const history = page.getByText('주문 이력').first().locator('xpath=ancestor::*[self::div][1]/..');

    // 라벨이 떠야 한다. 이것이 S2-D8 의 본체다.
    await expect(
      history.getByText('구매확정일시').first(),
      '주문 이력에 「구매확정일시」 행이 없습니다 — 확정을 마친 주문인데 그 시각이 화면에 남지 않습니다.',
    ).toBeVisible({ timeout: 10000 });

    // 값이 API 응답과 같아야 한다. 라벨만 있고 값이 비면 의미가 없다.
    await expect(
      history.getByText(target!.confirmedAt, { exact: false }).first(),
      `구매확정일시 값이 API 응답(${target!.confirmedAt})과 다릅니다.`,
    ).toBeVisible({ timeout: 10000 });
  });

  /**
   * @scenario order_history_row=label_keys_exist
   *
   * @effects order_history_labels_are_translated_in_every_locale
   */
  test('이력 섹션에 미치환 원문 키가 노출되지 않는다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    await page.goto('/mypage/orders');
    await dismissCookieBanner(page);

    const target = await findConfirmedOrder(page);
    test.skip(target === null, '구매확정(confirmed) 상태의 주문이 없어 측정할 수 없습니다.');

    await page.goto(`/mypage/orders/${target!.orderNumber}`);
    await dismissCookieBanner(page);

    await expect(page.getByText('주문 이력').first()).toBeVisible({ timeout: 15000 });

    const body = (await page.locator('body').innerText()) ?? '';

    // 라벨 키가 언어 파일에 없으면 `mypage.order_detail.<key>` 가 그대로 찍힌다.
    // S2-D8 확인 중 delivered_at 이 정확히 그 상태였다.
    expect(
      body,
      '화면에 미치환 원문 키가 노출됩니다 — 레이아웃이 부르는 라벨이 언어 파일에 없습니다.',
    ).not.toContain('mypage.order_detail.');
  });
});
