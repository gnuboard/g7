/**
 * 반품 [완료 처리] 창 — 환불 예정에 사용 마일리지 반환액이 함께 보인다 (EC-694, 실측 2026-10-07, 재실측 2026-10-08).
 *
 * 마일리지를 함께 쓴 주문의 반품을 완료하면 환불신청서는 현금 + 사용 마일리지 반환으로 만들어지는데, 완료 창은
 * 현금 환불액만 그려(「환불 예정 50,000원 · 완료와 동시에 1건(합계 50,000원) 생성」) 운영자는 마일리지 반환을
 * 모른 채 완료했다. 회원 주문 → 배송완료 → 반품 신청 → 회수도착까지 실제 서버 경로로 만든 뒤 화면의 창을 잰다.
 * 완료는 누르지 않는다(창의 표기만 검증).
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import { issueToken } from '../../../../../../../tests/Playwright/fixtures/auth';

type ApiResult = { status: number; body: string };

/** 회원이 쓸 마일리지 — 창에서 「1,000원」 으로 읽힌다 */
const POINTS = 1000;

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
 * API 를 부르고 성공을 단언한 뒤 `data` 를 돌려준다.
 *
 * @param page 대상 페이지
 * @param label 실패 메시지 머리
 * @param path 요청 경로
 * @param init 메서드와 본문
 * @returns 응답 data
 */
async function ok(page: Page, label: string, path: string, init: { method?: string; body?: unknown } = {}): Promise<any> {
  const res = await api(page, path, init);
  expect(res.status, `${label} 실패: ${res.body.slice(0, 400)}`).toBeLessThan(300);

  return JSON.parse(res.body)?.data;
}

test.describe('반품 완료 처리 창의 마일리지 반환 표기', () => {
  // 주문부터 회수도착까지 서버 경로를 모두 밟으므로 기본 30초로는 모자란다.
  test.describe.configure({ timeout: 240_000 });

  // @scenario claim_type=return, completion_choice=file_now
  // @effects completion_modal_shows_points_refund
  test('마일리지를 쓴 주문의 반품을 완료하려 하면 창이 「+ 마일리지」 와 반환 합계를 함께 보인다', async ({ page, memberToken }) => {
    const B = '/api/modules/sirsoft-ecommerce';
    const adminToken = issueToken(
      'sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.orders.update',
      'sirsoft-ecommerce.claims.read', 'sirsoft-ecommerce.claims.update-status',
      'sirsoft-ecommerce.mileage.read', 'sirsoft-ecommerce.mileage.manage',
    );

    // 1) 회원 식별 → 관리자가 마일리지 지급
    await authenticatePage(page, memberToken);
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');
    const member = JSON.parse((await api(page, '/api/auth/user')).body)?.data;
    await authenticatePage(page, adminToken);
    await ok(page, '마일리지 지급', `${B}/admin/mileage-transactions`, {
      method: 'POST', body: { user_id: member.uuid, action: 'earn', amount: POINTS, currency: 'KRW', memo: 'EC-694 E2E', use_default_expiry: true },
    });
    const carriers = await ok(page, '택배사', `${B}/admin/shipping-carriers/active`);
    const carrierId = carriers?.[0]?.value;

    // 2) 회원 — 마일리지를 쓴 무통장 주문
    await authenticatePage(page, memberToken);
    const settings = JSON.parse((await api(page, `${B}/settings/payment`)).body);
    const account = settings?.data?.order_settings?.bank_accounts?.[0];
    const list = JSON.parse((await api(page, `${B}/products?per_page=30`)).body);
    let order: { id: number; no: string; total: number } | null = null;
    for (const candidate of (list?.data?.data ?? []) as Array<{ id: number }>) {
      const detail = JSON.parse((await api(page, `${B}/products/${candidate.id}`)).body);
      const option = ((detail?.data?.options ?? []) as Array<{ id?: number; stock_quantity?: number }>)
        .find((o) => typeof o.id === 'number' && (o.stock_quantity ?? 0) >= 1);
      if (!option) continue;
      const checkout = await api(page, `${B}/checkout`, { method: 'POST', body: { direct_items: [{ product_id: candidate.id, product_option_id: option.id, quantity: 1, additional_option_selections: [] }], use_points: POINTS } });
      if (checkout.status >= 300) continue;
      const data = JSON.parse(checkout.body).data;
      const total = data.calculation.summary.final_amount;
      if (!(total > 0)) continue;
      const placed = await ok(page, '주문', `${B}/user/orders`, {
        method: 'POST',
        body: {
          temp_order_id: data.temp_order_id,
          orderer: { name: '반품시험', phone: '01012345678', email: 'claim-complete-points@example.test' },
          shipping: { country_code: 'KR', recipient_name: '반품시험', recipient_phone: '01012345678', zipcode: '06236', address: '서울특별시 강남구 테헤란로 152', address_detail: '10층' },
          payment_method: 'dbank', use_points: POINTS, depositor_name: '반품시험',
          dbank: { bank_code: account.bank_code, account_number: account.account_number, account_holder: account.account_holder },
          refund_bank: { bank_code: account.bank_code, account_number: '110130009999', holder: '반품시험' },
          expected_total_amount: total, save_shipping_address: false,
        },
      });
      order = { id: placed?.order?.id ?? placed?.id, no: placed?.order?.order_number ?? placed?.order_number, total };
      break;
    }
    expect(order, '마일리지를 쓸 수 있는 주문 상품이 없습니다.').toBeTruthy();

    // 3) 관리자 — 입금확인 → 배송중 → 배송완료
    await authenticatePage(page, adminToken);
    await ok(page, '입금확인', `${B}/admin/orders/${order!.no}/confirm-deposit`, { method: 'PATCH', body: { amount: order!.total, mark_order_complete: true } });
    const adminOrder = await ok(page, '주문 상세', `${B}/admin/orders/${order!.no}`);
    const items = (adminOrder.options ?? []).map((o: { id: number; quantity: number }) => ({ option_id: o.id, quantity: o.quantity }));
    await ok(page, '배송중', `${B}/admin/orders/${order!.no}/options/bulk-status`, { method: 'PATCH', body: { items, status: 'shipping', carrier_id: carrierId, tracking_number: '694000000101' } });
    await ok(page, '배송완료', `${B}/admin/orders/${order!.no}/options/bulk-status`, { method: 'PATCH', body: { items, status: 'delivered' } });

    // 4) 회원 — 반품 신청(마일리지 우선 환불)
    await authenticatePage(page, memberToken);
    const reasons = await ok(page, '반품 사유', `${B}/user/claim-reasons?type=return`);
    const reasonCode = (reasons?.[0]?.code ?? reasons?.data?.[0]?.code) as string;
    await ok(page, '반품 신청', `${B}/user/orders/${order!.id}/returns`, {
      method: 'POST',
      body: { items: (adminOrder.options ?? []).map((o: { id: number; quantity: number }) => ({ order_option_id: o.id, quantity: o.quantity })), reason_code: reasonCode, refund_priority: 'points_first' },
    });

    // 5) 관리자 — 승인 → 회수중 → 회수도착
    await authenticatePage(page, adminToken);
    const claims = await ok(page, '주문 클레임', `${B}/admin/orders/${order!.no}/claims`);
    const ret = (claims?.claims ?? []).find((c: { claim_type: string }) => c.claim_type === 'return');
    expect(ret, '반품 신청서를 찾지 못했습니다.').toBeTruthy();
    await ok(page, '승인', `${B}/admin/returns/${ret.id}/status`, { method: 'PATCH', body: { status: 'approved' } });
    await ok(page, '회수중', `${B}/admin/returns/${ret.id}/status`, { method: 'PATCH', body: { status: 'collecting', carrier_id: carrierId, tracking_number: '694000000102' } });
    await ok(page, '회수도착', `${B}/admin/returns/${ret.id}/status`, { method: 'PATCH', body: { status: 'collected' } });

    // 6) 화면 — 반품 상세 [완료 처리] 창
    await page.goto(`/admin/ecommerce/claims/${ret.claim_number}`);
    const completeButton = page.getByRole('button', { name: '완료 처리' });
    await completeButton.scrollIntoViewIfNeeded({ timeout: 90_000 });
    const preview = page.waitForResponse((r) => r.url().includes('/completion-preview'));
    await completeButton.click();
    expect((await preview).status()).toBe(200);

    const dialog = page.getByRole('dialog').last();
    await expect(dialog).toContainText('+ 마일리지 1,000원', { timeout: 30_000 });
    await expect(dialog).toContainText('사용 마일리지 1,000원도 함께 돌려줍니다');
  });
});
