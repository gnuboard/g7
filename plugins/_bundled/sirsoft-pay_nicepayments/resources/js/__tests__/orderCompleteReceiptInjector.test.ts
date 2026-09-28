/**
 * 주문 완료 화면의 나이스페이먼츠 [영수증 조회] 버튼 (orderCompleteReceiptInjector)
 *
 * 영수증 조회 API 는 공통 응답 형식({ success, message, data })으로 응답한다. 버튼은 그 `data` 에서
 * 결제수단 표시 이름과 영수증 주소를 읽어야 한다 — 최상위에서 읽으면 결제수단 표기는 그대로 남고
 * 버튼을 눌러도 아무 창도 열리지 않는다(오류·로그 없음).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { installOrderCompleteReceiptInjector } from '../orderCompleteReceiptInjector';

const FLAG = '__nicepayOcReceiptInjectorInstalled';
const BTN_ID = 'nicepay-oc-receipt-btn';
const RECEIPT = 'https://receipt.example/oc-1';

/**
 * 요청 주소별로 새 응답을 돌려주는 fetch 목을 세웁니다(응답 본문은 한 번만 읽을 수 있다).
 *
 * @return fetch 스파이
 */
function mockFetch() {
    return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
        const url = String(input);
        const body = url.endsWith('/receipt')
            ? { success: true, message: 'ok', data: { receipt_url: RECEIPT, payment_method_display_label: '네이버페이 (신용카드)' } }
            : { success: true, data: { payment: { pg_provider: 'nicepayments', transaction_id: 'T-1', paid_at: '2026-09-28 10:00:00' } } };

        return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    localStorage.clear();
    sessionStorage.clear();
    localStorage.setItem('auth_token', 'member-token');
    history.replaceState({}, '', '/shop/orders/ORD-OC-1/complete');
    document.body.innerHTML = `
        <div id="order_payment_method_row"><span>결제수단</span><span>신용카드</span></div>
        <div><button type="button" class="bg-blue-600 hover:bg-blue-700">주문 상세</button><button type="button">쇼핑 계속</button></div>`;
    vi.spyOn(console, 'info').mockImplementation(() => {});
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete (window as any)[FLAG];
    document.body.innerHTML = '';
});

describe('NicePayments 주문 완료 영수증 버튼 — 공통 응답 형식', () => {
    it('결제수단 표시 이름을 응답 data 에서 읽어 바꾼다', async () => {
        mockFetch();
        installOrderCompleteReceiptInjector();
        await vi.advanceTimersByTimeAsync(1500);

        expect(document.body.textContent).toContain('네이버페이 (신용카드)');
        expect(document.getElementById(BTN_ID)).not.toBeNull();
    });

    it('[영수증 조회]는 응답 data 의 receipt_url 을 새 창으로 연다', async () => {
        mockFetch();
        const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null);
        installOrderCompleteReceiptInjector();
        await vi.advanceTimersByTimeAsync(1500);

        (document.getElementById(BTN_ID) as HTMLButtonElement).click();
        await vi.advanceTimersByTimeAsync(0);

        expect(openSpy).toHaveBeenCalledWith(RECEIPT, 'nicepay_receipt', expect.any(String));
    });
});
