import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    forgetPendingClose,
    rememberPendingClose,
    reportPaymentFailureOnReturn,
} from '../paymentCloseReport';

function windowRecord(): Record<string, any> {
    return window as unknown as Record<string, any>;
}

/**
 * 화면 주소를 바꿔 결제 리턴 상황을 재현한다.
 */
function setLocation(pathname: string, search: string): void {
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { pathname, search, origin: 'https://shop.example' },
    });
}

const CONTEXT = {
    orderId: 'ORD-TOSS-1001',
    amount: 10000,
};

describe('토스페이먼츠 결제 실패 보고', () => {
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
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&message=cancelled&orderId=ORD-TOSS-1001');

        await reportPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-tosspayments/payment/close-report',
            expect.objectContaining({
                orderId: 'ORD-TOSS-1001',
                amount: 10000,
                code: 'PAY_PROCESS_CANCELED',
            }),
        );
        // 주문자 연락처는 소유 증명이 아니므로 보내지 않는다 (KVE-2026-2434).
        expect(apiPost.mock.calls[0][1]).not.toHaveProperty('buyer_email');
        expect(apiPost.mock.calls[0][1]).not.toHaveProperty('buyer_phone');
    });

    it('보고 후에는 저장분을 지워 중복 보고하지 않는다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-1001');

        await reportPaymentFailureOnReturn();
        await reportPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledTimes(1);
    });

    it('저장해 둔 정보가 없으면 아무것도 보내지 않는다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-1001');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
    });

    it('다른 주문번호로 돌아왔으면 보고하지 않는다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-OTHER');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
    });

    it('결제 완료 화면으로 돌아오면 보고하지 않고 저장분만 지운다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/orders/ORD-TOSS-1001/complete', '');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
        expect(window.sessionStorage.getItem('g7:sirsoft-tosspayments:pendingClose')).toBeNull();
    });

    it('실패 표시가 전혀 없으면 판단하지 않고 저장분을 남겨 둔다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
        expect(window.sessionStorage.getItem('g7:sirsoft-tosspayments:pendingClose')).not.toBeNull();
    });

    it('G7Core API 가 없으면 fetch 로 보고한다', async () => {
        const fetchSpy = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        windowRecord().fetch = fetchSpy;

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=REJECT_CARD_COMPANY&orderId=ORD-TOSS-1001');

        await reportPaymentFailureOnReturn();

        expect(fetchSpy).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-tosspayments/payment/close-report',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('보고가 실패해도 예외를 던지지 않는다', async () => {
        const apiPost = vi.fn().mockRejectedValue(new Error('network down'));
        windowRecord().G7Core = { api: { post: apiPost } };
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-1001');

        await expect(reportPaymentFailureOnReturn()).resolves.toBeUndefined();
    });

    it('forgetPendingClose 는 저장분을 지운다', () => {
        rememberPendingClose(CONTEXT);
        expect(window.sessionStorage.getItem('g7:sirsoft-tosspayments:pendingClose')).not.toBeNull();

        forgetPendingClose();

        expect(window.sessionStorage.getItem('g7:sirsoft-tosspayments:pendingClose')).toBeNull();
    });
});

/**
 * 소유자 증명 헤더 (KVE-2026-2434)
 *
 * close-report 는 주문을 실패·취소로 바꾸므로 서버가 주문 소유자만 받아 준다. 회원은 G7Core.api 가
 * Bearer 를 싣고, 비회원은 체크아웃이 저장해 둔 조회 토큰(X-Guest-Order-Token)을 실어야 한다.
 */
describe('소유자 증명 헤더', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
        window.localStorage.clear();
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-1001');
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
        rememberPendingClose(CONTEXT);

        await reportPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-tosspayments/payment/close-report',
            expect.objectContaining({ orderId: 'ORD-TOSS-1001' }),
            { headers: { 'X-Guest-Order-Token': '1999999999|guest-token' } },
        );
    });

    it('회원이면 비회원 토큰을 싣지 않는다 — Bearer 는 G7Core.api 몫이다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };
        window.localStorage.setItem('auth_token', 'member-token');
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|guest-token');
        rememberPendingClose(CONTEXT);

        await reportPaymentFailureOnReturn();

        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });

    it('토큰이 없으면 헤더 없이 보내고 거부돼도 예외를 흘리지 않는다', async () => {
        const apiPost = vi.fn().mockRejectedValue(new Error('403'));
        windowRecord().G7Core = { api: { post: apiPost } };
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        rememberPendingClose(CONTEXT);

        await expect(reportPaymentFailureOnReturn()).resolves.toBeUndefined();

        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });

    it('G7Core.api 가 없으면 fetch 헤더에 조회 토큰을 싣는다', async () => {
        const fetchSpy = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        windowRecord().fetch = fetchSpy;
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|guest-token');
        rememberPendingClose(CONTEXT);

        await reportPaymentFailureOnReturn();

        const init = fetchSpy.mock.calls[0][1] as RequestInit;
        expect((init.headers as Record<string, string>)['X-Guest-Order-Token']).toBe('1999999999|guest-token');
    });
});
