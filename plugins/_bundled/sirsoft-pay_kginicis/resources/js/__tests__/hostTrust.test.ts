/**
 * KG 이니시스 — PG 전송 주소·제출 주소·영수증 창
 *
 * - PG 로 보내는 결과 수신 주소는 서버가 사이트 주소로 만든 절대 URL 만 쓴다(브라우저 현재 주소로 조립하지 않는다).
 * - 모바일·CBT 결제 제출 주소는 이니시스 도메인만, 외부 영수증 창은 https 만 opener 를 끊고 연다.
 * - 핸들러 수준: 서버가 상대 경로·허용 밖 제출 주소를 주면 결제 폼을 제출하지 않고, 절대 URL 은 브라우저
 *   주소와 달라도 그대로 P_NEXT_URL 에 싣는다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requireSiteAbsoluteUrl, assertTrustedPgFormAction, requestPaymentHandler } from '../handlers/requestPayment';
import { openExternalReceiptWindow } from '../receiptPopup';
import { clearMobilePaymentReturnPending } from '../paymentDomCleanup';

/** 서버(client-config)가 사이트 주소로 만들어 내려주는 주소의 출처 — 브라우저(jsdom) 주소와 다르다 */
const SITE_ORIGIN = 'https://shop.example.test';
const SITE_MOBILE_CALLBACK_URL = `${SITE_ORIGIN}/plugins/sirsoft-pay_kginicis/payment/mobile/callback`;
const INICIS_MOBILE_PAY_URL = 'https://mobile.inicis.com/smart/payment/';

const PG_PAYMENT = {
    payment_method: 'card',
    order_number: 'ORD-HOST-001',
    order_name: 'Host Trust Order',
    amount: 1000,
    customer_name: '홍길동',
    customer_phone: '01012345678',
    customer_email: 'test@test.com',
};

let apiPost: ReturnType<typeof vi.fn>;
let modalOpen: ReturnType<typeof vi.fn>;
let submitSpy: ReturnType<typeof vi.spyOn>;
let originalUserAgent: PropertyDescriptor | undefined;

/**
 * 한국 모바일 결제 흐름(서명 발급 → 결제 폼 제출)을 쓰도록 G7Core·UA 를 모킹합니다.
 *
 * @param mobileCallbackUrl client-config 의 callback_urls.mobile_callback
 * @param mobilePaymentUrl 서명 발급 응답의 mobile_payment_url (결제 폼 제출 주소)
 */
function mockInicisMobileEnvironment(mobileCallbackUrl: string, mobilePaymentUrl: string = INICIS_MOBILE_PAY_URL): void {
    apiPost = vi.fn().mockResolvedValue({
        data: { chkfake: 'chkfakestub', mobile_payment_url: mobilePaymentUrl },
    });
    modalOpen = vi.fn();
    (window as Record<string, unknown>).G7Core = {
        api: {
            get: vi.fn().mockResolvedValue({
                data: {
                    mid: 'INIpayTest',
                    japan_enabled: false,
                    japan_mid: '',
                    callback_urls: {
                        mobile_signature: `${SITE_ORIGIN}/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature`,
                        mobile_callback: mobileCallbackUrl,
                        mobile_vbank_notify: `${SITE_ORIGIN}/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify`,
                    },
                },
            }),
            post: apiPost,
        },
        state: { setLocal: vi.fn() },
        modal: { open: modalOpen },
        toast: { error: vi.fn() },
    };
    Object.defineProperty(window.navigator, 'userAgent', {
        value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        configurable: true,
    });
}

/**
 * 일본 CBT 결제 흐름(체크아웃 토큰·해시 발급 → cbtauth 로 폼 제출)을 쓰도록 G7Core 를 모킹합니다.
 *
 * @param cbtAuthUrl client-config 의 callback_urls.cbt_auth_url (결제 폼 제출 주소)
 */
function mockInicisCbtEnvironment(cbtAuthUrl: string): void {
    apiPost = vi.fn().mockImplementation((url: string) => (
        url.endsWith('/checkout-token')
            ? Promise.resolve({ data: { checkout_token: 'checkout-token-stub' } })
            : Promise.resolve({ data: { hash_data: 'sha512hashstub' } })
    ));
    modalOpen = vi.fn();
    (window as Record<string, unknown>).G7Core = {
        api: {
            get: vi.fn().mockResolvedValue({
                data: {
                    mid: 'INIpayTest',
                    japan_enabled: true,
                    japan_configured: true,
                    japan_mid: 'CBTTEST001',
                    callback_urls: {
                        cbt_checkout_token: `${SITE_ORIGIN}/api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token`,
                        cbt_hash_data: `${SITE_ORIGIN}/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data`,
                        cbt_callback: `${SITE_ORIGIN}/plugins/sirsoft-pay_kginicis/payment/cbt/callback`,
                        cbt_cvs_notify: `${SITE_ORIGIN}/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify`,
                        cbt_auth_url: cbtAuthUrl,
                    },
                },
            }),
            post: apiPost,
        },
        state: { setLocal: vi.fn() },
        modal: { open: modalOpen },
        toast: { error: vi.fn() },
    };
}

/**
 * 가장 최근에 붙은 결제 폼의 hidden 필드 값을 읽습니다.
 *
 * @returns 필드 이름 → 값
 */
function lastFormFields(): Record<string, string> {
    const forms = document.body.querySelectorAll('form');
    const form = forms[forms.length - 1];
    const fields: Record<string, string> = {};
    form?.querySelectorAll('input[type="hidden"]').forEach((el) => {
        const input = el as HTMLInputElement;
        fields[input.name] = input.value;
    });

    return fields;
}

beforeEach(() => {
    originalUserAgent = Object.getOwnPropertyDescriptor(window.navigator, 'userAgent');
    submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
});

afterEach(() => {
    delete (window as Record<string, unknown>).G7Core;
    if (originalUserAgent) {
        Object.defineProperty(window.navigator, 'userAgent', originalUserAgent);
    } else {
        delete (window.navigator as unknown as Record<string, unknown>).userAgent;
    }
    clearMobilePaymentReturnPending();
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('KG 이니시스 — PG 전송 주소·제출 주소·영수증 창', () => {
    // @scenario host_header=forged, sink=front_pg_urls
    // @effects front_pg_urls_require_site_absolute
    it('결과 수신 주소는 http(s) 절대 URL 만 받고 상대·실행 스킴은 결제를 멈춘다', () => {
        expect(requireSiteAbsoluteUrl('https://shop.example.test/plugins/x/payment/callback')).toBe('https://shop.example.test/plugins/x/payment/callback');
        expect(() => requireSiteAbsoluteUrl('/plugins/x/payment/callback')).toThrow();
        expect(() => requireSiteAbsoluteUrl('javascript:alert(1)')).toThrow();
        expect(() => requireSiteAbsoluteUrl(undefined)).toThrow();
    });

    // @scenario host_header=forged, sink=front_pg_form_action
    // @effects front_pg_form_action_allowlisted
    it('결제 정보를 POST 할 주소는 https + PG 도메인만', () => {
        expect(() => assertTrustedPgFormAction('https://mobile.inicis.com/smart/payment/')).not.toThrow();
        expect(() => assertTrustedPgFormAction('http://mobile.inicis.com/smart/payment/')).toThrow();
        expect(() => assertTrustedPgFormAction('https://evil.example/collect')).toThrow();
        expect(() => assertTrustedPgFormAction('https://inicis.com.evil.example/x')).toThrow();
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
    it('client-config 가 상대 경로 결과 수신 주소를 주면 모바일 결제 폼을 제출하지 않는다', async () => {
        mockInicisMobileEnvironment('/plugins/sirsoft-pay_kginicis/payment/mobile/callback');

        await requestPaymentHandler({ params: { pgPaymentData: { ...PG_PAYMENT, payment_method: 'card' } } });

        expect(submitSpy).toHaveBeenCalledTimes(0);
        expect(modalOpen).toHaveBeenCalledWith('kginicis_payment_error_modal');
    });

    // @scenario host_header=forged, sink=front_pg_form_action
    // @effects front_pg_form_action_allowlisted
    it('서명 응답의 모바일 제출 주소가 이니시스 도메인이 아니면 결제 폼을 제출하지 않는다', async () => {
        mockInicisMobileEnvironment(SITE_MOBILE_CALLBACK_URL, 'https://evil.example/pay');

        await requestPaymentHandler({ params: { pgPaymentData: { ...PG_PAYMENT, payment_method: 'card' } } });

        expect(apiPost).toHaveBeenCalledTimes(1);
        expect(submitSpy).toHaveBeenCalledTimes(0);
        expect(modalOpen).toHaveBeenCalledWith('kginicis_payment_error_modal');
    });

    // @scenario host_header=forged, sink=front_pg_form_action
    // @effects front_pg_form_action_allowlisted
    it('client-config 의 CBT 제출 주소가 이니시스 도메인이 아니면 결제 폼을 제출하지 않는다', async () => {
        mockInicisCbtEnvironment('https://evil.example/pay');

        await requestPaymentHandler({
            params: { pgPaymentData: { ...PG_PAYMENT, currency: 'JPY', payment_method: 'card' } },
        });

        expect(apiPost).toHaveBeenCalledTimes(2);
        expect(submitSpy).toHaveBeenCalledTimes(0);
        expect(modalOpen).toHaveBeenCalledWith('kginicis_payment_error_modal');
    });

    // @scenario host_header=forged, sink=front_pg_urls
    // @effects front_pg_urls_use_server_site_address_unchanged
    it('브라우저 주소가 사이트 주소와 달라도 서버가 준 절대 URL 을 그대로 P_NEXT_URL 로 싣는다', async () => {
        expect(window.location.origin).not.toBe(SITE_ORIGIN);
        mockInicisMobileEnvironment(SITE_MOBILE_CALLBACK_URL);

        await requestPaymentHandler({ params: { pgPaymentData: { ...PG_PAYMENT, payment_method: 'card' } } });

        expect(submitSpy).toHaveBeenCalledTimes(1);
        expect((submitSpy.mock.instances[0] as unknown as HTMLFormElement).action).toBe(INICIS_MOBILE_PAY_URL);
        expect(lastFormFields().P_NEXT_URL).toBe(`${SITE_MOBILE_CALLBACK_URL}?orderId=ORD-HOST-001`);
    });
});

/**
 * 서버가 주는 서명·CBT 발급 주소는 사이트 주소 기준 절대 URL 이다(`SiteUrl::absolute`).
 *
 * G7Core.api 는 받은 경로 앞에 `/api` 를 붙이고, 절대 URL 이면 그대로 보낸다. 같은 출처 절대 URL 을 그대로 넘기면
 * `/plugins/...`(접두 없음)로 나가 404 가 나 결제창이 열리지 않았다.
 */
describe('같은 출처 절대 URL 서명 발급', () => {
    it('모바일 서명 발급은 G7Core.api 에 /plugins 경로로 보낸다', async () => {
        const origin = window.location.origin;
        apiPost = vi.fn().mockResolvedValue({ data: { chkfake: 'chkfakestub', mobile_payment_url: INICIS_MOBILE_PAY_URL } });
        (window as Record<string, unknown>).G7Core = {
            api: {
                get: vi.fn().mockResolvedValue({
                    data: {
                        mid: 'INIpayTest',
                        japan_enabled: false,
                        japan_mid: '',
                        callback_urls: {
                            mobile_signature: `${origin}/plugins/sirsoft-pay_kginicis/payment/mobile/signature`,
                            mobile_callback: `${origin}/plugins/sirsoft-pay_kginicis/payment/mobile/callback`,
                            mobile_vbank_notify: `${origin}/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify`,
                        },
                    },
                }),
                post: apiPost,
            },
            state: { setLocal: vi.fn() },
            modal: { open: vi.fn() },
            toast: { error: vi.fn() },
        };
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
            configurable: true,
        });

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' } });

        expect(apiPost).toHaveBeenCalledWith('/plugins/sirsoft-pay_kginicis/payment/mobile/signature', expect.any(Object));
    });

    it('CBT 체크아웃 토큰·해시 발급도 /plugins 경로로 보낸다', async () => {
        const origin = window.location.origin;
        mockInicisCbtEnvironment('https://cbt.inicis.com/cbtauth');
        const get = ((window as Record<string, any>).G7Core.api.get) as ReturnType<typeof vi.fn>;
        const cfg = (await get()).data;
        cfg.callback_urls.cbt_checkout_token = `${origin}/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token`;
        cfg.callback_urls.cbt_hash_data = `${origin}/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data`;
        get.mockResolvedValue({ data: cfg });

        await requestPaymentHandler({
            params: { pgPaymentData: { ...PG_PAYMENT, currency: 'JPY' }, paymentMethod: 'card' },
        });

        const urls = apiPost.mock.calls.map((c: unknown[]) => c[0]);
        expect(urls).toContain('/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token');
        expect(urls).toContain('/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data');
    });
});
