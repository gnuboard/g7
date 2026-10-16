/**
 * 관리자 마일리지 내역 — 새 창 링크 (#757 결정: 「이미 새 창」 칸도 링크로)
 *
 * 주문번호와 회원·처리 관리자 이름 메뉴의 「회원 정보」·「관리자 정보」는 종전에 클릭 동작(openWindow)으로
 * 새 창을 열었다. 이제는 실제 주소를 가진 새 창 링크(`target="_blank"` · `rel="noopener noreferrer"`)다 —
 * 가운데 클릭·주소 복사가 같은 곳을 가리키고, 보통 클릭도 지금처럼 새 창이며 이 목록의 검색 조건은 그대로 남는다.
 *
 * 브라우저로만 드러나는 축: 행 값(`{{row.…}}`)이 **풀린 주소**가 링크에 실리는가. 레이아웃 시험은 식 글자만 본다.
 * 링크를 실제로 누르지는 않는다 — 새 창을 여는 것이 동작이라 주소·창 속성으로 잰다.
 *
 * 목록 응답은 가로챈다 — 계정의 실제 거래와 무관하게 주문·회원·처리자가 모두 있는 행 하나를 세운다.
 *
 * @scenario cell=order_number
 * @effects mileage_order_number_is_new_window_link, mileage_member_menus_are_new_window_links
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const LIST_URL = '/admin/ecommerce/mileage-transactions';
const LIST_API = '**/api/modules/sirsoft-ecommerce/admin/mileage-transactions?**';
const ROW = {
  id: 990701,
  number: 1,
  created_at_formatted: '2026-10-15 10:00',
  user_id: 77,
  user_uuid: 'e2e-member-uuid-0001',
  user_name: '새창회원',
  type: 'earn',
  type_label: '적립',
  amount: 1000,
  amount_formatted: '1,000',
  order_number: 'E2E-NW-ORDER-01',
  granted_by_uuid: 'e2e-admin-uuid-0001',
  granted_by_name: '새창관리자',
};

/**
 * 거래 목록 응답을 행 하나로 세웁니다(목록 API 의 GET 만 — 다른 요청은 그대로 보낸다).
 *
 * @param page Playwright 페이지
 */
async function mockOneRow(page: Page): Promise<void> {
  await page.route(LIST_API, async (route) => {
    if (route.request().method() !== 'GET') {
      await route.fallback();

      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: null,
        data: {
          data: [ROW],
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
          pagination: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
          abilities: { can_manage: true },
        },
      }),
    });
  });
}

/**
 * 새 창 링크의 창 속성을 잽니다.
 *
 * @param link 링크
 * @param href 기대 주소
 */
async function expectNewWindowLink(link: ReturnType<Page['locator']>, href: string): Promise<void> {
  await expect(link).toHaveAttribute('href', href);
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
}

test.describe('관리자 마일리지 내역 — 새 창 링크', () => {
  test.beforeEach(async ({ page, mileageManageToken }) => {
    await authenticatePage(page, mileageManageToken);
    await mockOneRow(page);
    await page.goto(`${LIST_URL}?page=1`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect(page.getByText(ROW.user_name).first()).toBeVisible({ timeout: 30_000 });
  });

  /**
   * @scenario cell=order_number
   * @effects mileage_order_number_is_new_window_link
   */
  test('주문번호는 그 주문 상세로 가는 새 창 링크다', async ({ page }) => {
    const orderLink = page.getByRole('link', { name: ROW.order_number, exact: true });
    await expect(orderLink).toBeVisible();
    await expectNewWindowLink(orderLink, `/admin/ecommerce/orders/${ROW.order_number}`);
  });

  /**
   * @scenario cell=member_menu
   * @effects mileage_member_menus_are_new_window_links
   */
  test('회원 이름 메뉴의 「회원 정보」는 그 회원 상세로 가는 새 창 링크다', async ({ page }) => {
    await page.getByText(ROW.user_name, { exact: true }).first().click();
    const item = page.getByRole('menuitem', { name: '회원 정보' });
    await expect(item).toBeVisible({ timeout: 10_000 });
    await expectNewWindowLink(item, `/admin/users/${ROW.user_uuid}`);
  });

  /**
   * @scenario cell=granted_by_menu
   * @effects mileage_member_menus_are_new_window_links
   */
  test('처리 관리자 이름 메뉴의 「관리자 정보」는 그 관리자 상세로 가는 새 창 링크다', async ({ page }) => {
    await page.getByText(ROW.granted_by_name, { exact: true }).first().click();
    const item = page.getByRole('menuitem', { name: '관리자 정보' });
    await expect(item).toBeVisible({ timeout: 10_000 });
    await expectNewWindowLink(item, `/admin/users/${ROW.granted_by_uuid}`);
  });
});
