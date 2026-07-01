import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { patchAdminPaymentMethodDisplay } from '../adminOrderPaymentDisplayInjector';
import { installMypageOrderShowInjector, patchMypagePaymentMethodDisplay } from '../mypageOrderShowInjector';
import { installOrderCompleteReceiptInjector } from '../orderCompleteReceiptInjector';

describe('order payment display injectors', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        localStorage.clear();
        sessionStorage.clear();
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.innerHTML = '';
        delete (window as Record<string, unknown>).__kginicisOrderShowInjectorInstalled;
        delete (window as Record<string, unknown>).__kginicisOcReceiptInjectorInstalled;
        delete (window as any).G7Core;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('마이페이지 주문 상세의 결제 방법 행을 간편결제 표시로 바꾼다', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="flex items-center justify-between">
                <span class="text-gray-500">결제 방법</span>
                <span class="text-gray-900">신용카드</span>
            </div>
        `;

        expect(patchMypagePaymentMethodDisplay(container, '네이버페이 (신용카드)')).toBe(true);
        expect(container.textContent?.replace(/\s+/g, '')).toContain('결제방법네이버페이(신용카드)');
        expect(container.querySelector('[data-kginicis-payment-method-patched="true"]')).not.toBeNull();
    });

    it('관리자 주문 상세의 결제수단 행과 상단 배지를 간편결제 표시로 바꾼다', () => {
        const root = document.createElement('div');
        root.innerHTML = `
            <span class="inline-flex rounded-full font-medium">신용카드</span>
            <div>
                <span class="text-xs block">결제수단</span>
                <span class="text-sm font-semibold block">신용카드</span>
                <span class="text-xs text-gray-500">(일시불)</span>
            </div>
        `;

        expect(patchAdminPaymentMethodDisplay(root, {
            _pay_method_label: '네이버페이 (신용카드)',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: '네이버페이',
        })).toBe(true);

        const text = root.textContent?.replace(/\s+/g, '');
        expect(text).toContain('네이버페이결제수단네이버페이(신용카드,일시불)');
    });

    it('관리자 주문 상세는 간편결제 정보가 없으면 기존 표시를 건드리지 않는다', () => {
        const root = document.createElement('div');
        root.innerHTML = `
            <span class="inline-flex rounded-full font-medium">신용카드</span>
            <div><span>결제수단</span><span>신용카드</span></div>
        `;

        expect(patchAdminPaymentMethodDisplay(root, {
            _pay_method_label: '신용카드',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: null,
        })).toBe(false);
        expect(root.textContent?.replace(/\s+/g, '')).toBe('신용카드결제수단신용카드');
    });

    it('결제완료 페이지에서 비회원 토큰 없이 receipt cookie fallback 응답만으로 영수증 버튼을 붙인다', async () => {
        vi.useFakeTimers();
        history.pushState(null, '', '/shop/orders/ORD-COOKIE-500/complete');
        document.body.innerHTML = `
            <div id="actions">
                <button type="button" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white">주문상세</button>
                <button type="button" class="px-4 py-2 bg-gray-100">계속 쇼핑</button>
            </div>
        `;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                receipt_type: 'inicis_receipt',
                receipt_url: 'https://iniweb.inicis.com/receipt',
                receipt_view_label: '영수증 조회',
                payment_method_display_label: '네이버페이 (신용카드)',
            }),
        } as Response);
        globalThis.fetch = fetchMock;

        installOrderCompleteReceiptInjector();
        await vi.advanceTimersByTimeAsync(1300);

        const receiptButton = document.getElementById('kginicis-oc-receipt-btn');
        expect(receiptButton?.textContent).toBe('영수증 조회');
        expect(document.getElementById('actions')?.textContent?.replace(/\s+/g, '')).toContain(
            '영수증조회계속쇼핑',
        );
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/api/plugins/sirsoft-pay_kginicis/user/orders/ORD-COOKIE-500/receipt',
        );
        expect(fetchMock.mock.calls[0][1].headers.Authorization).toBeUndefined();
        expect(fetchMock.mock.calls[0][1].headers['X-Guest-Order-Token']).toBeUndefined();
    });

    it('비회원 주문 상세에서 order state 를 못 잡아도 receipt API 응답만으로 영수증 행을 붙인다', async () => {
        vi.useFakeTimers();
        history.pushState(null, '', '/shop/guest/orders/ORD-GUEST-RECEIPT');
        (window as any).G7Core = {
            getState: () => ({ currentDataContext: {} }),
        };
        document.body.innerHTML = `
            <section id="order_payment_info_panel">
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span>결제 방법</span>
                        <span>신용카드</span>
                    </div>
                </div>
            </section>
        `;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                receipt_type: 'inicis_receipt',
                receipt_url: 'https://iniweb.inicis.com/receipt',
                receipt_label: '영수증',
                receipt_view_label: '영수증 조회',
                payment_method_display_label: '신용카드',
            }),
        } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2100);

        const receiptRow = document.getElementById('kginicis-mp-receipt-row');
        expect(receiptRow?.textContent?.replace(/\s+/g, '')).toContain('영수증영수증조회');
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-pay_kginicis/user/orders/ORD-GUEST-RECEIPT/receipt',
            expect.objectContaining({ credentials: 'same-origin' }),
        );

        delete (window as any).G7Core;
    });

    it('영어 비회원 주문 상세의 Payment Information 영역에도 영수증 행을 붙인다', async () => {
        vi.useFakeTimers();
        history.pushState(null, '', '/shop/guest/orders');
        (window as any).G7Core = {
            getState: () => ({
                currentDataContext: {
                    order: {
                        data: {
                            order_number: 'ORD-GUEST-EN',
                            payment: {
                                pg_provider: 'kginicis',
                                payment_status: 'paid',
                                transaction_id: 'StdpayCARDINIpayTest',
                            },
                        },
                    },
                },
            }),
        };
        document.body.innerHTML = `
            <section>
                <div>
                    <h3>Payment Information</h3>
                </div>
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span>Payment Method</span>
                        <span>Credit Card</span>
                    </div>
                </div>
            </section>
        `;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                receipt_type: 'inicis_receipt',
                receipt_url: 'https://iniweb.inicis.com/receipt',
                receipt_label: 'Receipt',
                receipt_view_label: 'View Receipt',
                payment_method_display_label: 'Naver Pay (Credit Card)',
            }),
        } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        history.replaceState(null, '', '/shop/guest/orders/ORD-GUEST-EN');
        await vi.advanceTimersByTimeAsync(1100);

        const receiptRow = document.getElementById('kginicis-mp-receipt-row');
        expect(receiptRow?.textContent?.replace(/\s+/g, '')).toContain('ReceiptViewReceipt');
        expect(document.body.textContent?.replace(/\s+/g, '')).toContain('PaymentMethodNaverPay(CreditCard)');
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-pay_kginicis/user/orders/ORD-GUEST-EN/receipt',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
    });
});
