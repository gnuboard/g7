/**
 * 비회원 현금영수증 — 발급 결과 표시 + 결제 통화 금액 정합 회귀.
 *
 * 배경 (2026-08-07 검수 2에서 브라우저 실측으로 확인된 3건):
 *
 *  1. 비회원 주문상세 응답에 `cash_receipt`/`cash_receipts` 가 없어, 발급에 성공해도 카드가
 *     영구히 "발급된 현금영수증이 없습니다" 로 남고 영수증 URL 에 도달할 방법이 없었다.
 *     (회원용 OrderResource 에는 있고 GuestOrderResource 에만 빠져 있었다. 두 화면은 같은
 *     확장 레이아웃 mypage_order_cash_receipt.json 을 공유한다.)
 *
 *  2. 관리자 입금확인 모달이 base 통화 금액(total_due_amount)을 입금액으로 채워 넣는데
 *     서버는 결제 통화 실청구액으로 검증해, base≠결제 통화 주문은 **항상** 422 였다.
 *
 *  3. 현금영수증 발급액이 base 통화 금액이라, 구매자가 실제로 낸 금액과 다른 금액으로
 *     세금 증빙이 발행됐다.
 *
 * 세 결함 모두 오류 로그·콘솔·네트워크 실패 없이 "값만 조용히 어긋나는" 형태라
 * 단위 테스트만으로는 화면 도달을 증명하지 못한다. 그래서 종단 spec 을 둔다.
 *
 * 실행 전제 (환경 의존이라 기본 skip):
 *   - 이커머스 설정 > 주문/결제 > 현금영수증 프로바이더 지정
 *   - 해당 결제 플러그인의 테스트 API 키 설정 (샌드박스 실호출)
 *   - 무통장입금 결제수단 활성 + 입금 계좌 1건 이상
 *   위 전제가 갖춰진 환경에서 test.describe.skip → test.describe 로 바꿔 실행한다.
 *
 * @scenario guest_cash_receipt_issue
 * @effects cash_receipt_issued, order_detail_reflects_receipt, payment_currency_amount_consistent
 */
import { test, expect } from '@playwright/test';

const ORDER_NUMBER = process.env.G7_GUEST_ORDER_NUMBER ?? '';
const ORDER_PHONE = process.env.G7_GUEST_ORDER_PHONE ?? '';
const ORDER_PASSWORD = process.env.G7_GUEST_ORDER_PASSWORD ?? '';

/**
 * 비회원 주문 조회로 주문상세에 진입한다.
 */
async function lookupGuestOrder(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/shop/guest/orders');
  await page.locator('input[name="order_number"]').fill(ORDER_NUMBER);
  await page.locator('input[name="orderer_phone"]').fill(ORDER_PHONE);
  await page.locator('input[name="guest_lookup_password"]').fill(ORDER_PASSWORD);
  await page.getByRole('button', { name: '주문 조회' }).click();
  await expect(page).toHaveURL(new RegExp(`/shop/guest/orders/${ORDER_NUMBER}`));
}

test.describe.skip('비회원 현금영수증', () => {
  // @scenario guest_cash_receipt_issue:issue_then_render
  // @effects cash_receipt_issued, order_detail_reflects_receipt
  test('발급에 성공하면 주문상세 카드가 즉시 발급완료로 바뀌고 영수증 링크가 노출된다', async ({ page }) => {
    await lookupGuestOrder(page);

    // 발급 전: 미발급 안내 + 발급 버튼
    await expect(page.getByText('발급된 현금영수증이 없습니다')).toBeVisible();

    const issueResponse = page.waitForResponse(
      (r) => r.url().includes('/guest/orders/') && r.url().includes('/cash-receipt') && r.request().method() === 'POST'
    );

    await page.getByRole('button', { name: '현금영수증 발급', exact: true }).first().click();
    await page.locator('input[name="identifier"]').fill(ORDER_PHONE.replace(/\D/g, ''));
    await page.getByRole('button', { name: '현금영수증 발급', exact: true }).last().click();

    const response = await issueResponse;
    expect(response.status()).toBe(200);

    // 결함 1 회귀: 재조회 없이도 카드가 발급완료로 갱신되어야 한다.
    await expect(page.getByText('발급된 현금영수증이 없습니다')).not.toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('발급일시')).toBeVisible();
    await expect(page.getByText('소득공제용')).toBeVisible();

    // 영수증 URL 에 실제로 도달 가능해야 한다 (링크가 없으면 구매자는 증빙을 볼 수 없다).
    const receiptLink = page.getByRole('link', { name: '영수증 보기' });
    await expect(receiptLink).toBeVisible();
    await expect(receiptLink).toHaveAttribute('href', /receipts?\//);
  });

  // @scenario guest_cash_receipt_issue:amount_matches_charge
  // @effects payment_currency_amount_consistent
  test('발급 금액이 구매자가 실제로 청구받은 결제 통화 금액과 일치한다', async ({ page }) => {
    // 주문상세 응답의 결제 통화 실청구액과 발급 응답의 금액이 같아야 한다.
    // base 통화 금액을 쓰면 두 값이 갈라진다(결함 3).
    const detailPromise = page.waitForResponse(
      (r) => /\/user\/orders\/[^/]+$/.test(r.url()) && r.request().method() === 'GET'
    );

    await lookupGuestOrder(page);

    const detail = await (await detailPromise).json();
    const chargeAmount: number = detail?.data?.total_due_charge_amount;
    expect(typeof chargeAmount).toBe('number');

    const receipt = detail?.data?.cash_receipt;

    // 결함 1 회귀: 비회원 응답에도 키가 존재해야 한다 (없으면 화면이 판정 불가).
    expect(detail?.data).toHaveProperty('cash_receipt');
    expect(detail?.data).toHaveProperty('cash_receipts');

    if (receipt) {
      expect(receipt.amount).toBe(chargeAmount);
    }
  });
});

test.describe.skip('관리자 무통장 입금확인 — 결제 통화 정합', () => {
  // @scenario guest_cash_receipt_issue:deposit_confirm_prefill
  // @effects payment_currency_amount_consistent
  test('입금확인 모달의 기본 입금액이 서버 검증 금액과 같아 첫 시도에 저장된다', async ({ page }) => {
    await page.goto(`/admin/ecommerce/orders/${ORDER_NUMBER}`);

    await page.getByRole('button', { name: '입금확인' }).first().click();

    // 결함 2 회귀: prefill 이 base 금액이면 서버가 422 로 되돌린다.
    const amountInput = page.locator('input[type="number"]').first();
    await expect(amountInput).not.toHaveValue('');

    const confirmResponse = page.waitForResponse(
      (r) => r.url().includes('/confirm-deposit') && r.request().method() === 'PATCH'
    );
    await page.getByRole('button', { name: '입금확인', exact: true }).last().click();

    const response = await confirmResponse;
    expect(response.status()).toBe(200);
  });
});
