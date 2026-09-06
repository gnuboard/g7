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
    buyer_email: 'buyer@example.com',
    buyer_phone: '01012345678',
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

    it('실패 화면으로 돌아오면 저장해 둔 구매자 정보로 보고한다', async () => {
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
                buyer_email: 'buyer@example.com',
                buyer_phone: '01012345678',
                code: 'PAY_PROCESS_CANCELED',
            }),
        );
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
