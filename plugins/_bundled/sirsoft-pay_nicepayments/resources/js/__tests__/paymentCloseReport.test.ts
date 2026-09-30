import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reportPaymentWindowClosed } from '../paymentCloseReport';

function windowRecord(): Record<string, any> {
    return window as unknown as Record<string, any>;
}

describe('paymentCloseReport', () => {
    afterEach(() => {
        delete windowRecord().G7Core;
        vi.restoreAllMocks();
    });

    it('G7Core API로 나이스페이먼츠 결제창 닫힘을 보고한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
        };

        await reportPaymentWindowClosed({
            closeReportUrl: '/plugins/sirsoft-pay_nicepayments/payment/close-report',
            oid: 'ORD-NICE-CLOSE-001',
            price: 10000,
            payment_method: 'card',
        }, 'nicepay-window-closed');

        // 주문자 연락처는 소유 증명이 아니므로 보내지 않는다 (KVE-2026-2434).
        expect(apiPost).toHaveBeenCalledWith('/plugins/sirsoft-pay_nicepayments/payment/close-report', {
            oid: 'ORD-NICE-CLOSE-001',
            price: 10000,
            payment_method: 'card',
            reason: 'nicepay-window-closed',
        });
    });

    it('G7Core API가 없으면 /plugins 경로를 /api/plugins 경로로 변환해 fetch 한다', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));

        await reportPaymentWindowClosed({
            closeReportUrl: '/plugins/sirsoft-pay_nicepayments/payment/close-report',
            oid: 'ORD-NICE-CLOSE-002',
            price: 15000,
        });

        expect(fetchSpy).toHaveBeenCalledWith('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', expect.objectContaining({
            method: 'POST',
            keepalive: true,
        }));
    });
});

/**
 * 소유자 증명 헤더 (KVE-2026-2434)
 *
 * close-report 는 주문을 실패·취소로 바꾸므로 서버가 주문 소유자만 받아 준다. 회원은 G7Core.api 가
 * Bearer 를 싣고, 비회원은 체크아웃이 저장해 둔 조회 토큰(X-Guest-Order-Token)을 실어야 한다.
 */
describe('소유자 증명 헤더', () => {
    const CONTEXT = {
        closeReportUrl: '/plugins/sirsoft-pay_nicepayments/payment/close-report',
        oid: 'ORD-NICE-OWNER-001',
        price: 10000,
        payment_method: 'card',
    };

    beforeEach(() => {
        window.sessionStorage.clear();
        window.localStorage.clear();
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        vi.restoreAllMocks();
        window.sessionStorage.clear();
        window.localStorage.clear();
    });

    it('비회원이면 저장된 조회 토큰을 X-Guest-Order-Token 으로 싣는다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|guest-token');

        await reportPaymentWindowClosed(CONTEXT, 'nicepay-window-closed');

        expect(apiPost).toHaveBeenCalledWith(
            '/plugins/sirsoft-pay_nicepayments/payment/close-report',
            expect.objectContaining({ oid: 'ORD-NICE-OWNER-001' }),
            { headers: { 'X-Guest-Order-Token': '1999999999|guest-token' } },
        );
    });

    it('회원이면 비회원 토큰을 싣지 않는다 — Bearer 는 G7Core.api 몫이다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };
        window.localStorage.setItem('auth_token', 'member-token');
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|guest-token');

        await reportPaymentWindowClosed(CONTEXT, 'nicepay-window-closed');

        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });

    it('토큰이 없으면 헤더 없이 보내고 거부돼도 예외를 흘리지 않는다', async () => {
        const apiPost = vi.fn().mockRejectedValue(new Error('403'));
        windowRecord().G7Core = { api: { post: apiPost } };
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);

        await expect(reportPaymentWindowClosed(CONTEXT)).resolves.toBeUndefined();

        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });

    it('G7Core.api 가 없으면 같은 출처 fetch 에 조회 토큰을 싣고, 다른 출처에는 싣지 않는다', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|guest-token');

        await reportPaymentWindowClosed(CONTEXT);
        await reportPaymentWindowClosed({ ...CONTEXT, closeReportUrl: 'https://evil.example/payment/close-report' });

        const sameOrigin = fetchSpy.mock.calls[0][1] as RequestInit;
        const crossOrigin = fetchSpy.mock.calls[1][1] as RequestInit;
        expect((sameOrigin.headers as Record<string, string>)['X-Guest-Order-Token']).toBe('1999999999|guest-token');
        expect((crossOrigin.headers as Record<string, string>)['X-Guest-Order-Token']).toBeUndefined();
    });
});
