/**
 * NHN KCP 영수증 조회 응답의 공통 형식(data) 소비 — 주입 스크립트 (#599)
 *
 * 영수증 조회 API 가 다른 API 와 같은 공통 형식({ success, message, data })으로 바뀌면서 영수증 주소가 data 안으로
 * 들어갔다. 최상위에서 주소를 읽으면 버튼은 그려져도 누르면 빈 주소로 팝업을 만든다(오류 없음). 버튼 글자는 번역 키로 그린다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';
import { installOrderCompleteReceiptInjector } from '../orderCompleteReceiptInjector';

const RECEIPT = {
    receipt_url: 'https://receipt.example/kcp-1',
    cash_receipt_url: 'https://receipt.example/kcp-cash-1',
    payment_method_display_label: 'NaverPay (Credit Card)',
};

const EN: Record<string, string> = {
    'sirsoft-pay_nhnkcp.user.receipt_label': 'Receipt',
    'sirsoft-pay_nhnkcp.user.receipt_view': 'View Receipt',
    'sirsoft-pay_nhnkcp.user.cash_receipt_view': 'View Cash Receipt',
    'sirsoft-pay_nhnkcp.user.loading': 'Loading...',
};

/** 호출마다 새 응답 본문을 만든다 — 같은 Response 는 한 번만 읽힌다. */
function json(body: unknown): Response {
    return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
}

let popupArgs: Array<Record<string, unknown>>;

beforeEach(() => {
    vi.useFakeTimers();
    localStorage.clear();
    sessionStorage.clear();
    localStorage.setItem('auth_token', 'member-token');
    popupArgs = [];
    (window as any).KcpReceiptPopup = function KcpReceiptPopup(args: Record<string, unknown>) {
        popupArgs.push(args);
    };
    vi.spyOn(console, 'info').mockImplementation(() => {});
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete (window as any).__kcpMpShowInjectorInstalled;
    delete (window as any).__kcpOcReceiptInjectorInstalled;
    delete (window as any).KcpReceiptPopup;
    delete (window as any).G7Core;
    document.body.innerHTML = '';
});

describe('NHN KCP 영수증 조회 응답 — 주입 스크립트가 data 를 읽는다', () => {
    /**
     * 마이페이지 주문 상세의 영수증 버튼은 응답 data 의 주소로 팝업을 만들고 버튼 글자는 번역 키로 그린다.
     *
     * @scenario surface=mypage_injector
     * @effects injector_reads_envelope_data, receipt_buttons_translated
     */
    it('마이페이지 주문 상세: 버튼을 누르면 data 의 영수증 주소로 팝업을 만든다', async () => {
        (window as any).G7Core = {
            t: (key: string) => EN[key] ?? key,
            getState: () => ({
                currentDataContext: {
                    order: {
                        data: {
                            order_number: 'ORD-1',
                            payment: { pg_provider: 'nhnkcp', payment_method: 'card', transaction_id: 'T-1', paid_at: '2026-09-28 10:00:00' },
                        },
                    },
                },
            }),
        };
        document.body.innerHTML = '<div id="order_payment_info_panel"><div class="space-y-3"></div></div>';
        vi.spyOn(globalThis, 'fetch').mockImplementation(async () => json({ success: true, message: 'ok', data: RECEIPT }));
        history.replaceState({}, '', '/mypage/orders/ORD-1');

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2500);

        const row = document.getElementById('kcp-mp-receipt-row');
        expect(row).not.toBeNull();
        expect(row!.textContent).toContain('Receipt');
        const btn = row!.querySelector('button') as HTMLButtonElement;
        expect(btn.textContent).toBe('View Receipt');

        btn.click();
        await vi.advanceTimersByTimeAsync(0);

        expect(popupArgs).toEqual([{ url: RECEIPT.receipt_url, cash_url: RECEIPT.cash_receipt_url }]);
        expect(btn.textContent).toBe('View Receipt');
    });

    /**
     * 주문 완료 화면의 영수증·현금영수증 버튼도 응답 data 의 주소를 쓰고 버튼 글자는 번역 키로 그린다.
     *
     * @scenario surface=order_complete_injector
     * @effects injector_reads_envelope_data, receipt_buttons_translated
     */
    it('주문 완료: 영수증·현금영수증 버튼이 data 의 주소로 팝업을 만든다', async () => {
        (window as any).G7Core = { t: (key: string) => EN[key] ?? key };
        document.body.innerHTML = `
            <div>
                <button type="button" class="bg-blue-600 hover:bg-blue-700">order</button>
                <button type="button" class="bg-gray-600">continue</button>
            </div>
        `;
        vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: RequestInfo | URL) => {
            const url = String(input);
            if (url.includes('/api/modules/sirsoft-ecommerce/user/orders/')) {
                return json({
                    success: true,
                    data: { payment: { pg_provider: 'nhnkcp', transaction_id: 'T-2', paid_at: '2026-09-28 10:00:00', payment_method: 'vbank' } },
                });
            }

            return json({ success: true, message: 'ok', data: RECEIPT });
        });
        history.replaceState({}, '', '/shop/orders/ORD-2/complete');

        installOrderCompleteReceiptInjector();
        await vi.advanceTimersByTimeAsync(2000);

        const receiptBtn = document.getElementById('kcp-oc-receipt-btn') as HTMLButtonElement;
        const cashBtn = document.getElementById('kcp-oc-cash-receipt-btn') as HTMLButtonElement;
        expect(receiptBtn.textContent).toBe('View Receipt');
        expect(cashBtn.textContent).toBe('View Cash Receipt');

        receiptBtn.click();
        await vi.advanceTimersByTimeAsync(0);
        cashBtn.click();
        await vi.advanceTimersByTimeAsync(0);

        expect(popupArgs).toEqual([{ url: RECEIPT.receipt_url }, { cash_url: RECEIPT.cash_receipt_url }]);
    });
});
