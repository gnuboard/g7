import { afterEach, describe, expect, it, vi } from 'vitest';
import { preparePaymentRetry, reportPaymentWindowClosed } from '../paymentCloseReport';

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
            buyer_email: 'buyer@example.com',
            buyer_phone: '01012345678',
            payment_method: 'card',
        }, 'kcp-window-closed');

        expect(apiPost).toHaveBeenCalledWith('/plugins/sirsoft-pay_nhnkcp/payment/close-report', {
            oid: 'ORD-KCP-CLOSE-001',
            price: 10000,
            buyer_email: 'buyer@example.com',
            buyer_phone: '01012345678',
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
            buyer_email: 'buyer@example.com',
            buyer_phone: '01012345678',
            payment_method: 'nhnkcp_kakaopay',
        });

        expect(apiPost).toHaveBeenCalledWith('/plugins/sirsoft-pay_nhnkcp/payment/retry', {
            oid: 'ORD-KCP-RETRY-001',
            price: 20000,
            buyer_email: 'buyer@example.com',
            buyer_phone: '01012345678',
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
