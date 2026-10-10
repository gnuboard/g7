/**
 * 상태를 먼저 올려 둔 무통장 주문의 입금확인 (실측 2026-10-03).
 *
 * 운영자가 주문 목록의 일괄 변경으로 결제대기 → 결제완료 → 상품준비중까지 먼저 올린 뒤
 * 주문 상세에서 [입금확인]으로 입금을 기록하는 순서를 실브라우저로 밟는다.
 *
 * 종전에는 결제 기록만 입금완료가 되고 주문의 결제금액은 0 으로 남았다 — 결제 기록이
 * 입금완료라 [입금확인]이 사라져 되돌릴 화면이 없고, 회원의 환불 신청 화면이 환불 예정액을
 * 0원으로 계산했다. 「결제완료로 처리」를 체크하면 상품준비중 주문이 결제완료로 되돌아갔다.
 *
 * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
 * @effects manual_deposit_fills_order_paid_amount_when_status_already_past_payment,
 *          manual_deposit_does_not_regress_order_already_past_payment
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

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
 * 재고가 있는 옵션 하나로 바로 구매 무통장 주문을 만든다.
 *
 * 주문 생성은 검증 대상의 전제라 회원이 밟는 서버 경로(바로 구매 → 주문)를 그대로 부른다.
 *
 * @param page 대상 페이지(인증됨)
 * @returns 주문번호와 주문 id
 */
async function placeDbankOrder(page: Page): Promise<{ orderNumber: string; orderId: number }> {
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
        orderer: { name: '입금확인시험', phone: '01012345678', email: 'deposit-past-payment@example.test' },
        shipping: {
          country_code: 'KR', recipient_name: '입금확인시험', recipient_phone: '01012345678',
          zipcode: '06236', address: '서울특별시 강남구 테헤란로 152', address_detail: '10층',
        },
        payment_method: 'dbank',
        depositor_name: '입금확인시험',
        dbank: { bank_code: account.bank_code, account_number: account.account_number, account_holder: account.account_holder },
        expected_total_amount: data.calculation.summary.final_amount,
        save_shipping_address: false,
      },
    });
    expect(placed.status, `주문 생성 실패: ${placed.body}`).toBeLessThan(300);
    const placedData = JSON.parse(placed.body)?.data;
    const orderNumber = (placedData?.order?.order_number ?? placedData?.order_number) as string;
    const mine = JSON.parse((await api(page, `/api/modules/sirsoft-ecommerce/user/orders/${orderNumber}`)).body);

    return { orderNumber, orderId: mine?.data?.id as number };
  }

  throw new Error('주문할 수 있는 상품이 없습니다.');
}

/**
 * 주문 목록 일괄 변경과 같은 요청으로 주문 상태를 옮긴다.
 *
 * @param page 대상 페이지(인증됨)
 * @param orderId 주문 id
 * @param status 옮길 상태
 * @returns void
 */
async function moveStatus(page: Page, orderId: number, status: string): Promise<void> {
  const res = await api(page, '/api/modules/sirsoft-ecommerce/admin/orders/bulk', {
    method: 'PATCH',
    body: { ids: [orderId], order_status: status },
  });
  expect(res.status, `상태 변경 실패: ${res.body}`).toBe(200);
}

test.describe('상태를 먼저 올려 둔 무통장 주문의 입금확인', () => {
  for (const markComplete of [false, true]) {
    // @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
    // @effects manual_deposit_fills_order_paid_amount_when_status_already_past_payment, manual_deposit_does_not_regress_order_already_past_payment
    test(`입금을 기록하면 상품준비중 그대로 결제금액이 채워진다 (결제완료로 처리 ${markComplete ? '체크' : '미체크'} 요청)`, async ({ page, orderManageToken }) => {
      await authenticatePage(page, orderManageToken);
      await page.goto('/admin/ecommerce/orders');
      await page.waitForLoadState('domcontentloaded');

      const { orderNumber, orderId } = await placeDbankOrder(page);
      await moveStatus(page, orderId, 'payment_complete');
      await moveStatus(page, orderId, 'preparing');

      await page.goto(`/admin/ecommerce/orders/${orderNumber}`);
      await page.getByRole('button', { name: '입금확인' }).click();
      const dialog = page.getByRole('dialog');
      await dialog.waitFor({ state: 'visible' });

      // 결제 이후 주문에는 「결제완료로 처리」 체크 대신 상태를 둔다는 안내가 선다.
      await expect(dialog.getByTestId('confirm-deposit-past-payment-hint')).toContainText('상품준비중');
      await expect(dialog.locator('input[name="mark_order_complete"]')).toHaveCount(0);

      // 체크 요청 갈래는 화면에서 고를 수 없으므로, 창이 보내는 요청에 값을 실어 서버 판정을 잰다.
      if (markComplete) {
        await page.route('**/confirm-deposit', async (route) => {
          const body = JSON.parse(route.request().postData() ?? '{}');
          await route.continue({ postData: JSON.stringify({ ...body, mark_order_complete: true }) });
        });
      }

      const confirmed = page.waitForResponse((r) => r.url().includes('/confirm-deposit') && r.request().method() === 'PATCH');
      await dialog.getByRole('button', { name: '입금확인' }).click();
      expect((await confirmed).status()).toBe(200);
      await expect(dialog).toBeHidden({ timeout: 30_000 });

      const order = JSON.parse((await api(page, `/api/modules/sirsoft-ecommerce/admin/orders/${orderNumber}`)).body).data;
      expect(order.order_status).toBe('preparing');
      expect(Number(order.total_paid_amount)).toBeGreaterThan(0);
      expect(Number(order.total_due_amount)).toBe(0);
    });
  }
});
