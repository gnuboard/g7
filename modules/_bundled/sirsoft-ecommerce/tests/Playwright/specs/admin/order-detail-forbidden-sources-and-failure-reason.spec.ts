/**
 * 주문 상세 — 다른 권한의 선택지 403 은 알림 없이 넘어가고, 처리 실패는 서버 사유만 띄운다 (실측 2026-10-07, 재실측 2026-10-08).
 *
 * ① 주문 조회 권한만 가진 운영자가 주문 상세에 들어가면 운송장 입력의 택배사 목록(설정 권한)과 취소/환불 창의
 *    사유 목록(설정·클레임 권한)이 403 을 받는다. 오류 처리가 없어 들어갈 때마다 「해당 권한이 없습니다.」 알림이
 *    떴다. 코어 셸(메뉴·알림) 403 도 같은 알림을 띄우므로(코어 결함 D10, 별도 보고) 그 두 권한은 주어 이커머스
 *    출처만 가른다.
 * ② [입금확인] 이 본문 없는 500 을 받으면 axios 원문 「Request failed with status code 500」 이 알림과 입금액 칸
 *    오류로 그대로 나왔다. 번역된 「입금 확인에 실패했습니다.」 로 넘긴다.
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import { issueScopedToken } from '../../../../../../../tests/Playwright/fixtures/auth';

type ApiResult = { status: number; body: string };

/**
 * 앱이 쓰는 것과 같은 Bearer 헤더로 API 를 부른다.
 *
 * @param page 대상 페이지
 * @param path 요청 경로
 * @param init 메서드와 본문
 * @returns 응답 상태와 본문
 */
async function api(page: Page, path: string, init: { method?: string; body?: unknown } = {}): Promise<ApiResult> {
  return page.evaluate(
    async ({ path, init }) => {
      const res = await fetch(path, {
        method: init.method ?? 'GET',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'Accept-Language': 'ko',
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        },
        body: init.body === undefined ? undefined : JSON.stringify(init.body),
      });
      return { status: res.status, body: await res.text() };
    },
    { path, init },
  );
}

/**
 * 재고가 있는 옵션 하나로 바로 구매 무통장 주문을 만든다 (회원이 밟는 서버 경로 그대로).
 *
 * @param page 대상 페이지(인증됨)
 * @returns 주문번호
 */
async function placeDbankOrder(page: Page): Promise<string> {
  const settings = JSON.parse((await api(page, '/api/modules/sirsoft-ecommerce/settings/payment')).body);
  const account = settings?.data?.order_settings?.bank_accounts?.[0];
  expect(account, '등록된 입금 계좌가 없습니다.').toBeTruthy();

  const list = JSON.parse((await api(page, '/api/modules/sirsoft-ecommerce/products?per_page=30')).body);
  for (const candidate of (list?.data?.data ?? []) as Array<{ id: number }>) {
    const detail = JSON.parse((await api(page, `/api/modules/sirsoft-ecommerce/products/${candidate.id}`)).body);
    const option = ((detail?.data?.options ?? []) as Array<{ id?: number; stock_quantity?: number }>)
      .find((o) => typeof o.id === 'number' && (o.stock_quantity ?? 0) >= 1);
    if (!option) continue;

    const checkout = await api(page, '/api/modules/sirsoft-ecommerce/checkout', {
      method: 'POST',
      body: { direct_items: [{ product_id: candidate.id, product_option_id: option.id, quantity: 1, additional_option_selections: [] }] },
    });
    if (checkout.status >= 300) continue;
    const data = JSON.parse(checkout.body).data;

    const placed = await api(page, '/api/modules/sirsoft-ecommerce/user/orders', {
      method: 'POST',
      body: {
        temp_order_id: data.temp_order_id,
        orderer: { name: '주문상세시험', phone: '01012345678', email: 'order-detail-forbidden@example.test' },
        shipping: {
          country_code: 'KR', recipient_name: '주문상세시험', recipient_phone: '01012345678',
          zipcode: '06236', address: '서울특별시 강남구 테헤란로 152', address_detail: '10층',
        },
        payment_method: 'dbank',
        depositor_name: '주문상세시험',
        dbank: { bank_code: account.bank_code, account_number: account.account_number, account_holder: account.account_holder },
        expected_total_amount: data.calculation.summary.final_amount,
        save_shipping_address: false,
      },
    });
    expect(placed.status, `주문 생성 실패: ${placed.body}`).toBeLessThan(300);
    const placedData = JSON.parse(placed.body)?.data;

    return (placedData?.order?.order_number ?? placedData?.order_number) as string;
  }

  throw new Error('주문할 수 있는 상품이 없습니다.');
}

test.describe('주문 상세 — 권한 밖 선택지와 처리 실패 안내', () => {
  // 주문 생성(상품 탐색·주문서·주문)과 관리자 상세 첫 진입이 함께 들어 기본 30초로는 모자란다.
  test.describe.configure({ timeout: 180_000 });

  // @scenario source=background
  // @effects detail_setting_sources_suppress_403_toast
  test('주문 조회 권한만 있는 운영자가 상세에 들어가도 「해당 권한이 없습니다」 알림이 뜨지 않는다', async ({ page, memberToken }) => {
    await authenticatePage(page, memberToken);
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');
    const orderNumber = await placeDbankOrder(page);

    // 코어 셸(메뉴·알림) 권한은 준다 — 그 403 이 띄우는 알림은 코어 결함(D10)이라 이커머스 출처만 가른다.
    await authenticatePage(page, issueScopedToken('sirsoft-ecommerce.orders.read', 'core.menus.read', 'core.notifications.read'));
    const forbidden: string[] = [];
    page.on('response', (r) => { if (r.status() === 403) forbidden.push(r.url()); });

    await page.goto(`/admin/ecommerce/orders/${orderNumber}`);
    await expect(page.getByText(orderNumber).first()).toBeVisible({ timeout: 60_000 });
    await page.waitForTimeout(5_000);

    // 게이트는 그대로 403 을 준다 — 막힌 것은 알림뿐이다.
    expect(forbidden.some((u) => u.includes('/admin/shipping-carriers/active'))).toBe(true);
    expect(forbidden.some((u) => u.includes('/admin/claim-reasons/active'))).toBe(true);
    await expect(page.getByText('해당 권한이 없습니다.')).toHaveCount(0);
  });

  // @scenario failure=server_5xx
  // @effects order_detail_failure_hides_non_reason_text
  test('[입금확인] 이 본문 없는 500 을 받으면 axios 원문 대신 「입금 확인에 실패했습니다.」', async ({ page, orderManageToken }) => {
    await authenticatePage(page, orderManageToken);
    await page.goto('/admin/ecommerce/orders');
    await page.waitForLoadState('domcontentloaded');
    const orderNumber = await placeDbankOrder(page);

    await page.goto(`/admin/ecommerce/orders/${orderNumber}`);
    await page.getByRole('button', { name: '입금확인' }).click({ timeout: 90_000 });
    const dialog = page.getByRole('dialog');
    await dialog.waitFor({ state: 'visible' });
    await dialog.locator('input[name="amount"]').fill('1');

    await page.route('**/confirm-deposit', (route) => route.fulfill({ status: 500, body: '' }));
    const failed = page.waitForResponse((r) => r.url().includes('/confirm-deposit'));
    await dialog.getByRole('button', { name: '입금확인' }).click();
    expect((await failed).status()).toBe(500);

    await expect(page.getByText('입금 확인에 실패했습니다.').first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('Request failed with status code 500')).toHaveCount(0);
  });
});
