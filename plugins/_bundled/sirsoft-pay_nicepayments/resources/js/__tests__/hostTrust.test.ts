/**
 * 나이스페이먼츠 — PG 전송 주소·영수증 창
 *
 * - PG 로 보내는 결과 수신 주소는 서버가 사이트 주소로 만든 절대 URL 만 쓴다(브라우저 현재 주소로 조립하지 않는다).
 * - 영수증 링크는 레이아웃 조각이 새 창(target=_blank)으로 열고 opener 를 끊는다(rel=noopener noreferrer). 종전의 주입 스크립트
 *   영수증 창 도우미(openExternalReceiptWindow)는 #639 에서 조각 링크로 대체됐다.
 * - 핸들러 수준: 서버가 상대 경로를 주면 결제 폼을 제출하지 않고, 절대 URL 은 브라우저 주소와 달라도
 *   그대로 ReturnURL 에 싣는다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requireSiteAbsoluteUrl, requestPaymentHandler } from '../handlers/requestPayment';
import orderCompleteFragment from '../../extensions/user_order_complete_receipt.json';
import mypageFragment from '../../extensions/user_mypage_order_receipt.json';

/** 서버(client-config)가 사이트 주소로 만들어 내려주는 결과 수신 주소 — 브라우저(jsdom) 주소와 다른 출처 */
const SITE_CALLBACK_URL = 'https://shop.example.test/plugins/sirsoft-pay_nicepayments/payment/callback';

const PG_PAYMENT = {
    order_number: 'ORD-HOST-001',
    payment_method: 'card',
    order_name: 'Host Trust Order',
    amount: 10000,
    customer_email: 'buyer@example.com',
    customer_phone: '01012345678',
};

let toastError: ReturnType<typeof vi.fn>;
let submitSpy: ReturnType<typeof vi.spyOn>;
let originalUserAgentData: PropertyDescriptor | undefined;

/**
 * 모바일 결제 흐름(SignData 발급 → 나이스페이 모바일 결제창으로 폼 제출)을 쓰도록 G7Core·UA 를 모킹합니다.
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
            post: vi.fn().mockResolvedValue({ ediDate: '20260101000000', signData: 'fake-sig', mid: 'SRpaytestm' }),
        },
        state: { setLocal: vi.fn() },
        toast: { error: toastError },
    };
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
    // @effects front_receipt_link_opens_without_opener
    it('영수증 링크는 서버가 준 주소로만 새 창을 열고 opener 를 끊는다', () => {
        const links: Array<Record<string, any>> = [];
        const walk = (node: unknown): void => {
            if (!node || typeof node !== 'object') return;
            const value = node as Record<string, any>;
            if (value.name === 'A') links.push(value);
            Object.values(value).forEach(walk);
        };
        walk(orderCompleteFragment);
        walk(mypageFragment);

        expect(links.length).toBeGreaterThanOrEqual(5);
        for (const link of links) {
            // 주소는 영수증 조회 API 응답을 담은 화면 상태에서만 온다 — 조각에 결제사 주소를 적지 않는다
            expect(link.props.href, link.id).toMatch(/^\{\{_local\._np(?:Oc|Mp)\w+\}\}$/);
            expect(link.props.target, link.id).toBe('_blank');
            expect(String(link.props.rel).split(' '), link.id).toEqual(expect.arrayContaining(['noopener', 'noreferrer']));
        }
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
