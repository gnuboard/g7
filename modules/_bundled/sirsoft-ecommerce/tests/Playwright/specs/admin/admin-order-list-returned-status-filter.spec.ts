/**
 * 주문 목록 주문 상태 필터 — 「반품완료」 칩
 *
 * 전량 반품을 마친 주문은 주문 상태가 「반품완료」가 된다. 주문 상태 칩에 그 값이 없으면 운영자는 그런 주문만 골라 볼 수
 * 없고 「배송완료」 필터에서도 빠진다 — 전체 목록의 배지로만 보인다. 칩을 눌러 요청 파라미터·응답 코드·결과 행 상태를 함께
 * 보고, 새로고침 뒤 칩 선택이 주소에서 복원되는지까지 본다.
 *
 * @scenario remaining=none, mix=returned_only
 * @effects returned_order_reachable_by_status_filter
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const LIST_URL = '/admin/ecommerce/orders';
const ORDERS_API = '/api/modules/sirsoft-ecommerce/admin/orders';

/**
 * 주문 목록이 그려질 때까지 기다립니다.
 *
 * @param page Playwright 페이지
 */
async function waitForOrderList(page: import('@playwright/test').Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#filter_card')).toBeVisible({ timeout: 30_000 });
}

test.describe('주문 목록 주문 상태 필터 — 반품완료', () => {
  // @scenario remaining=none, mix=returned_only
  // @effects returned_order_reachable_by_status_filter
  test('주문 상태 「반품완료」 칩이 반품완료 주문만 거르고 새로고침 뒤에도 남는다', async ({ page, request, ordersReadToken }) => {
    test.setTimeout(120_000);

    const headers = { Authorization: `Bearer ${ordersReadToken}`, Accept: 'application/json' };
    const returned = await request.get(`${ORDERS_API}?per_page=20&order_status[]=returned`, { headers });
    expect(returned.status(), '서버가 returned 를 모르면 칩을 누른 순간 목록 전체가 422 가 된다').toBe(200);
    const returnedTotal = (await returned.json())?.data?.pagination?.total;
    expect(returnedTotal, '200 인데 총 건수가 없다 — 응답 형태가 바뀌었다').not.toBeUndefined();

    await authenticatePage(page, ordersReadToken);
    await page.goto(LIST_URL);
    await waitForOrderList(page);

    const chip = page.locator('#f_029b_status_returned');
    await expect(chip, '주문 상태 필터에 「반품완료」 칩이 없다').toBeVisible({ timeout: 15_000 });
    await expect(chip).toContainText('반품완료');

    const listResponse = page.waitForResponse(
      (r) => r.url().includes('/admin/orders?') && decodeURIComponent(r.url()).includes('order_status[]=returned'),
      { timeout: 30_000 },
    );
    await chip.click();
    const search = page.locator('button:visible', { hasText: /^검색$/ }).first();
    if (await search.count()) {
      await search.click();
    }
    const response = await listResponse;
    expect(response.status()).toBe(200);
    expect(page.url()).toContain('order_status%5B%5D=returned');

    test.skip(!returnedTotal, '반품완료 주문이 한 건도 없다 — 칩·요청까지만 단언했다');

    // 필터 응답이 그려질 때까지 행 수로 기다린다 — 바로 읽으면 직전(필터 전) 목록을 센다.
    const rows = page.locator('table tbody tr:visible');
    await expect(rows, '반품완료 칩 결과 행 수가 서버 총 건수와 다르다').toHaveCount(Math.min(returnedTotal, 20), { timeout: 15_000 });
    for (const text of await rows.allInnerTexts()) {
      expect(text, '반품완료 칩 결과에 다른 상태의 주문이 섞였다').toContain('반품완료');
    }

    await page.reload();
    await waitForOrderList(page);
    await expect(page.locator('#f_029b_status_returned input[type=checkbox]')).toBeChecked({ timeout: 15_000 });
  });
});
