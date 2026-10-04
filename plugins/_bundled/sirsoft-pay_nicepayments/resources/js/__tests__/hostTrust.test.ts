/**
 * 나이스페이먼츠 — PG 전송 주소·영수증 창
 *
 * - PG 로 보내는 결과 수신 주소는 서버가 사이트 주소로 만든 절대 URL 만 쓴다(브라우저 현재 주소로 조립하지 않는다).
 * - 외부 영수증 창은 https 만, opener 를 끊고 연다.
 * - 핸들러 수준: 서버가 상대 경로를 주면 결제 폼을 제출하지 않고, 절대 URL 은 브라우저 주소와 달라도
 *   그대로 ReturnURL 에 싣는다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requireSiteAbsoluteUrl, requestPaymentHandler } from '../handlers/requestPayment';
import { openExternalReceiptWindow } from '../orderCompleteReceiptInjector';

/** 서버(client-config)가 사이트 주소로 만들어 내려주는 결과 수신 주소 — 브라우저(jsdom) 주소와 다른 출처 */
const SITE_CALLBACK_URL = 'https://shop.example.test/plugins/sirsoft-pay_nicepayments/payment/callback';

const PG_PAYMENT = {
    order_number: 'ORD-HOST-001',
    order_name: 'Host Trust Order',
    amount: 10000,
    customer_email: 'buyer@example.com',
    customer_phone: '01012345678',
};

let toastError: ReturnType<typeof vi.fn>;
let submitSpy: ReturnType<typeof vi.spyOn>;
let originalUserAgentData: PropertyDescriptor | undefined;

/**
 * 모바일 결제 흐름(SignData 발급 → 나이스페이 모바일 결제창으로 폼 제출)을 쓰도록 G7Core·fetch·UA 를 모킹합니다.
 *
 * @param callbackUrl client-config 의 callback_url
 */
function mockNicepayMobileEnvironment(callbackUrl: string): void {
    toastError = vi.fn();
    (window as Record<string, unknown>).G7Core = {
        api: {
            get: vi.fn().mockImplementation((url: string) => {
                if (url.includes('client-config/nicepayments')) {
                    return Promise.resolve({
                        data: {
                            mid: 'SRpaytestm',
                            sdk_url: 'https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js',
                            sign_data_url: 'https://shop.example.test/api/plugins/sirsoft-pay_nicepayments/sign-data',
                            callback_url: callbackUrl,
                            useEscrow: false,
                        },
                    });
                }

                return Promise.resolve({ data: {} });
            }),
        },
        state: { setLocal: vi.fn() },
        toast: { error: toastError },
    };
    (window as unknown as Record<string, unknown>).fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ ediDate: '20260101000000', signData: 'fake-sig', mid: 'SRpaytestm' }),
    });
    Object.defineProperty(navigator, 'userAgentData', { value: { mobile: true }, configurable: true });
}

/**
 * 제출된 결제 폼의 hidden 필드 값을 읽습니다.
 *
 * @param name 필드 이름
 * @returns 필드 값 (없으면 null)
 */
function submittedField(name: string): string | null {
    const form = submitSpy.mock.instances[0] as unknown as HTMLFormElement;
    const input = form.querySelector(`input[name="${name}"]`) as HTMLInputElement | null;

    return input?.value ?? null;
}

beforeEach(() => {
    originalUserAgentData = Object.getOwnPropertyDescriptor(navigator, 'userAgentData');
    submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
    delete (window as Record<string, unknown>).G7Core;
    delete (window as unknown as Record<string, unknown>).fetch;
    if (originalUserAgentData) {
        Object.defineProperty(navigator, 'userAgentData', originalUserAgentData);
    } else {
        delete (navigator as unknown as Record<string, unknown>).userAgentData;
    }
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('나이스페이먼츠 — PG 전송 주소·영수증 창', () => {
    // @scenario host_header=forged, sink=front_pg_urls
    // @effects front_pg_urls_require_site_absolute
    it('결과 수신 주소는 http(s) 절대 URL 만 받고 상대·실행 스킴은 결제를 멈춘다', () => {
        expect(requireSiteAbsoluteUrl('https://shop.example.test/plugins/x/payment/callback')).toBe('https://shop.example.test/plugins/x/payment/callback');
        expect(() => requireSiteAbsoluteUrl('/plugins/x/payment/callback')).toThrow();
        expect(() => requireSiteAbsoluteUrl('javascript:alert(1)')).toThrow();
        expect(() => requireSiteAbsoluteUrl(undefined)).toThrow();
    });

    // @scenario host_header=forged, sink=front_receipt_window
    // @effects front_receipt_window_has_no_opener_and_https_only
    it('영수증 창은 https 만 opener 를 끊은 빈 창에 싣는다', () => {
        const popup = { opener: window as unknown, location: { href: '' } };
        const open = vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window);

        expect(openExternalReceiptWindow('https://receipt.example.com/r?id=1', 'r', 'width=800')).toBe(true);
        expect(open).toHaveBeenCalledWith('', 'r', 'width=800');
        expect(popup.opener).toBeNull();
        expect(popup.location.href).toBe('https://receipt.example.com/r?id=1');

        open.mockClear();
        expect(openExternalReceiptWindow('http://receipt.example.com/r', 'r', 'width=800')).toBe(false);
        expect(openExternalReceiptWindow('javascript:alert(1)', 'r', 'width=800')).toBe(false);
        expect(open).not.toHaveBeenCalled();
    });

    // @scenario host_header=forged, sink=front_pg_urls
    // @effects front_pg_urls_require_site_absolute
    it('client-config 가 상대 경로 결과 수신 주소를 주면 결제 폼을 제출하지 않는다', async () => {
        mockNicepayMobileEnvironment('/plugins/sirsoft-pay_nicepayments/payment/callback');

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' } });

        expect(submitSpy).toHaveBeenCalledTimes(0);
        expect(toastError).toHaveBeenCalledTimes(1);
    });

    // @scenario host_header=forged, sink=front_pg_urls
    // @effects front_pg_urls_use_server_site_address_unchanged
    it('브라우저 주소가 사이트 주소와 달라도 서버가 준 절대 URL 을 그대로 ReturnURL 로 싣는다', async () => {
        expect(window.location.origin).not.toBe('https://shop.example.test');
        mockNicepayMobileEnvironment(SITE_CALLBACK_URL);

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' } });

        expect(submitSpy).toHaveBeenCalledTimes(1);
        expect(submittedField('ReturnURL')).toBe(SITE_CALLBACK_URL);
    });
});
