/**
 * 토스페이먼츠 — PG 전송 주소의 사이트 주소 고정
 *
 * - PG 로 보내는 결과 수신 주소는 서버가 사이트 주소로 만든 절대 URL 만 쓴다(브라우저 현재 주소로 조립하지 않는다).
 * - 핸들러 수준: 서버가 상대 경로를 주면 결제창을 열지 않고, 절대 URL 은 브라우저 주소와 달라도 그대로 SDK 에 싣는다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requireSiteAbsoluteUrl, requestPaymentHandler } from '../handlers/requestPayment';

/** 서버(client-config)가 사이트 주소로 만들어 내려주는 결과 수신 주소 — 브라우저(jsdom) 주소와 다른 출처 */
const SITE_SUCCESS_URL = 'https://shop.example.test/plugins/sirsoft-tosspayments/payment/success';
const SITE_FAIL_URL = 'https://shop.example.test/plugins/sirsoft-tosspayments/payment/fail';

const PG_PAYMENT = {
    order_number: 'ORD-HOST-001',
    order_name: 'Host Trust Order',
    amount: 10000,
};

let apiGet: ReturnType<typeof vi.fn>;
let modalOpen: ReturnType<typeof vi.fn>;
let sdkRequestPayment: ReturnType<typeof vi.fn>;

/**
 * client-config 가 주는 결과 수신 주소를 정해 G7Core·TossPayments SDK 를 모킹합니다.
 *
 * @param callbackUrls client-config 의 callback_urls
 */
function mockTossEnvironment(callbackUrls: { success: string; fail: string }): void {
    apiGet = vi.fn().mockResolvedValue({
        data: {
            client_key: 'test_ck_stub',
            sdk_url: 'https://js.tosspayments.com/v2/standard',
            callback_urls: callbackUrls,
        },
    });
    modalOpen = vi.fn();
    sdkRequestPayment = vi.fn().mockResolvedValue(undefined);
    (window as any).G7Core = {
        api: { get: apiGet, post: vi.fn() },
        state: { setLocal: vi.fn(), get: vi.fn().mockReturnValue({}) },
        modal: { open: modalOpen },
    };
    (window as any).TossPayments = vi.fn().mockReturnValue({
        payment: vi.fn().mockReturnValue({ requestPayment: sdkRequestPayment }),
    });
    (window as any).TossPayments.ANONYMOUS = 'ANONYMOUS';
}

beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).TossPayments;
    vi.restoreAllMocks();
});

describe('토스페이먼츠 — PG 전송 주소의 사이트 주소 고정', () => {
    // @scenario host_header=forged, sink=front_pg_urls
    // @effects front_pg_urls_require_site_absolute
    it('결과 수신 주소는 http(s) 절대 URL 만 받고 상대·실행 스킴은 결제를 멈춘다', () => {
        expect(requireSiteAbsoluteUrl('https://shop.example.test/plugins/x/payment/callback')).toBe('https://shop.example.test/plugins/x/payment/callback');
        expect(() => requireSiteAbsoluteUrl('/plugins/x/payment/callback')).toThrow();
        expect(() => requireSiteAbsoluteUrl('javascript:alert(1)')).toThrow();
        expect(() => requireSiteAbsoluteUrl(undefined)).toThrow();
    });

    // @scenario host_header=forged, sink=front_pg_urls
    // @effects front_pg_urls_require_site_absolute
    it('client-config 가 상대 경로 결과 수신 주소를 주면 핸들러가 결제창을 열지 않는다', async () => {
        mockTossEnvironment({
            success: '/plugins/sirsoft-tosspayments/payment/success',
            fail: '/plugins/sirsoft-tosspayments/payment/fail',
        });

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(apiGet).toHaveBeenCalledTimes(1);
        expect(sdkRequestPayment).toHaveBeenCalledTimes(0);
        expect(modalOpen).toHaveBeenCalledWith('tosspayments_payment_error_modal');
    });

    // @scenario host_header=forged, sink=front_pg_urls
    // @effects front_pg_urls_use_server_site_address_unchanged
    it('브라우저 주소가 사이트 주소와 달라도 서버가 준 절대 URL 을 그대로 SDK 에 싣는다', async () => {
        expect(window.location.origin).not.toBe('https://shop.example.test');
        mockTossEnvironment({ success: SITE_SUCCESS_URL, fail: SITE_FAIL_URL });

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(sdkRequestPayment).toHaveBeenCalledTimes(1);
        const payload = sdkRequestPayment.mock.calls[0][0];
        expect(payload.successUrl).toBe(SITE_SUCCESS_URL);
        expect(payload.failUrl).toBe(SITE_FAIL_URL);
    });
});
