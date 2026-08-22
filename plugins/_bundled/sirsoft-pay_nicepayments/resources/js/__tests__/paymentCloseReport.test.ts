import { afterEach, describe, expect, it, vi } from 'vitest';
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
            buyer_email: 'buyer@example.com',
            buyer_phone: '01012345678',
            payment_method: 'card',
        }, 'nicepay-window-closed');

        expect(apiPost).toHaveBeenCalledWith('/plugins/sirsoft-pay_nicepayments/payment/close-report', {
            oid: 'ORD-NICE-CLOSE-001',
            price: 10000,
            buyer_email: 'buyer@example.com',
            buyer_phone: '01012345678',
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
