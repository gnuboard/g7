import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    preparePaymentRetry,
    rememberPendingClose,
    reportPaymentFailureOnReturn,
    reportPaymentWindowClosed,
} from '../paymentCloseReport';

function windowRecord(): Record<string, any> {
    return window as unknown as Record<string, any>;
}

describe('paymentCloseReport', () => {
    afterEach(() => {
        delete windowRecord().G7Core;
        vi.restoreAllMocks();
    });

    it('G7Core API로 NHN KCP 결제창 닫힘을 보고한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
        };

        await reportPaymentWindowClosed({
            closeReportUrl: '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            oid: 'ORD-KCP-CLOSE-001',
            price: 10000,
            payment_method: 'card',
        }, 'kcp-window-closed');

        // 주문자 연락처는 소유 증명이 아니므로 보내지 않는다 (KVE-2026-2434).
        expect(apiPost).toHaveBeenCalledWith('/plugins/sirsoft-pay_nhnkcp/payment/close-report', {
            oid: 'ORD-KCP-CLOSE-001',
            price: 10000,
            payment_method: 'card',
            reason: 'kcp-window-closed',
        });
    });

    it('G7Core API가 없으면 /plugins 경로를 /api/plugins 경로로 변환해 fetch 한다', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));

        await reportPaymentWindowClosed({
            closeReportUrl: '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            oid: 'ORD-KCP-CLOSE-002',
            price: 15000,
        });

        expect(fetchSpy).toHaveBeenCalledWith('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', expect.objectContaining({
            method: 'POST',
            keepalive: true,
        }));
    });

    it('결제창을 열기 전 같은 주문 재시도 준비 API를 호출한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
        };

        await preparePaymentRetry({
            closeReportUrl: '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            oid: 'ORD-KCP-RETRY-001',
            price: 20000,
            payment_method: 'nhnkcp_kakaopay',
        });

        expect(apiPost).toHaveBeenCalledWith('/plugins/sirsoft-pay_nhnkcp/payment/retry', {
            oid: 'ORD-KCP-RETRY-001',
            price: 20000,
            payment_method: 'nhnkcp_kakaopay',
        });
    });

    it('재시도 준비 API가 실패하면 결제창을 열지 않도록 오류를 전파한다', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({
            errors: { message: ['Order is not retryable for NHN KCP payment.'] },
        }), {
            status: 409,
            headers: { 'Content-Type': 'application/json' },
        }));

        await expect(preparePaymentRetry({
            closeReportUrl: '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            oid: 'ORD-KCP-RETRY-002',
            price: 20000,
        })).rejects.toThrow('Order is not retryable for NHN KCP payment.');
    });
});

/**
 * 결제 실패 화면 복귀 시 보고
 *
 * 브라우저 리턴 콜백(authCallback)은 PG 서명도 IP 증명도 없어 주문 상태를 바꾸지 않는다.
 * 승인이 거절된 정당한 실패는 이 경로(소유권 대조 close-report)로만 기록된다.
 */
describe('결제 실패 화면 복귀 보고', () => {
    const CONTEXT = {
        closeReportUrl: '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
        oid: 'ORD-KCP-RETURN-001',
        price: 10000,
    };

    /**
     * 화면 주소를 바꿔 결제 리턴 상황을 재현한다.
     */
    function setLocation(pathname: string, search: string): void {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { pathname, search, origin: 'https://shop.example' },
        });
    }

    beforeEach(() => {
        window.sessionStorage.clear();
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        vi.restoreAllMocks();
        window.sessionStorage.clear();
    });

    it('실패 화면으로 돌아오면 저장해 둔 주문 정보로 보고한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=9999&message=%EC%8B%A4%ED%8C%A8&orderId=ORD-KCP-RETURN-001');

        await reportPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledWith(
            '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            expect.objectContaining({ oid: 'ORD-KCP-RETURN-001', price: 10000 }),
        );
    });

    it('두 번 호출해도 한 번만 보고한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=9999&orderId=ORD-KCP-RETURN-001');

        await reportPaymentFailureOnReturn();
        await reportPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledTimes(1);
    });

    it('다른 주문번호로 돌아왔으면 보고하지 않는다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=9999&orderId=ORD-KCP-OTHER');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
    });

    it('결제 완료 화면으로 돌아오면 보고하지 않고 저장분만 지운다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/orders/ORD-KCP-RETURN-001/complete', '');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
        expect(window.sessionStorage.getItem('g7:sirsoft-pay_nhnkcp:pendingClose')).toBeNull();
    });

    it('저장분이 없으면 아무것도 보내지 않는다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        setLocation('/shop/checkout', '?error=9999&orderId=ORD-KCP-RETURN-001');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
    });

    it('실패 표시가 없으면 판단하지 않고 저장분을 남겨 둔다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
        expect(window.sessionStorage.getItem('g7:sirsoft-pay_nhnkcp:pendingClose')).not.toBeNull();
    });
});

/**
 * 소유자 증명 헤더 (KVE-2026-2434)
 *
 * close-report·retry 는 주문 상태를 바꾸므로 서버가 주문 소유자만 받아 준다. 회원은 G7Core.api 가
 * Bearer 를 싣고, 비회원은 체크아웃이 저장해 둔 조회 토큰(X-Guest-Order-Token)을 실어야 한다.
 */
describe('소유자 증명 헤더', () => {
    const CONTEXT = {
        closeReportUrl: '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
        oid: 'ORD-KCP-OWNER-001',
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

        await reportPaymentWindowClosed(CONTEXT, 'kcp-window-closed');

        expect(apiPost).toHaveBeenCalledWith(
            '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            expect.objectContaining({ oid: 'ORD-KCP-OWNER-001' }),
            { headers: { 'X-Guest-Order-Token': '1999999999|guest-token' } },
        );
    });

    it('sessionStorage 가 비어 있으면 _global.guestOrderToken 폴백을 싣는다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
            state: { get: (key: string) => (key === '_global' ? { guestOrderToken: 'global-token' } : undefined) },
        };

        await reportPaymentWindowClosed(CONTEXT, 'kcp-window-closed');

        expect(apiPost.mock.calls[0][2]).toEqual({ headers: { 'X-Guest-Order-Token': 'global-token' } });
    });

    it('회원이면 비회원 토큰을 싣지 않는다 — Bearer 는 G7Core.api 몫이다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };
        window.localStorage.setItem('auth_token', 'member-token');
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|guest-token');

        await reportPaymentWindowClosed(CONTEXT, 'kcp-window-closed');

        expect(apiPost).toHaveBeenCalledTimes(1);
        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });

    it('토큰이 없으면 헤더 없이 보내고 예외를 흘리지 않는다 (서버 거부 시 만료 잡이 정리)', async () => {
        const apiPost = vi.fn().mockRejectedValue(new Error('403'));
        windowRecord().G7Core = { api: { post: apiPost } };
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

        await expect(reportPaymentWindowClosed(CONTEXT, 'kcp-window-closed')).resolves.toBeUndefined();

        expect(apiPost.mock.calls[0]).toHaveLength(2);
        expect(warn).toHaveBeenCalled();
    });

    it('재시도 준비 요청에도 비회원 조회 토큰을 싣는다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|guest-token');

        await preparePaymentRetry(CONTEXT);

        expect(apiPost).toHaveBeenCalledWith(
            '/plugins/sirsoft-pay_nhnkcp/payment/retry',
            expect.any(Object),
            { headers: { 'X-Guest-Order-Token': '1999999999|guest-token' } },
        );
    });

    it('G7Core.api 가 없으면 같은 출처 fetch 에 조회 토큰을 싣는다', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|guest-token');

        await reportPaymentWindowClosed(CONTEXT, 'kcp-window-closed');

        const init = fetchSpy.mock.calls[0][1] as RequestInit;
        expect((init.headers as Record<string, string>)['X-Guest-Order-Token']).toBe('1999999999|guest-token');
    });

    it('G7Core.api 가 없고 보고 주소가 다른 출처면 조회 토큰을 싣지 않는다', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|guest-token');

        await reportPaymentWindowClosed({ ...CONTEXT, closeReportUrl: 'https://evil.example/payment/close-report' });

        const init = fetchSpy.mock.calls[0][1] as RequestInit;
        expect((init.headers as Record<string, string>)['X-Guest-Order-Token']).toBeUndefined();
    });
});
