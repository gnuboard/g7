/**
 * NHN KCP — PG 전송 주소·제출 주소·영수증 창
 *
 * - PG 로 보내는 결과 수신 주소는 서버가 사이트 주소로 만든 절대 URL 만 쓴다(브라우저 현재 주소로 조립하지 않는다).
 * - 모바일 결제 제출 주소(pay_url)는 KCP 도메인만, 외부 영수증 창은 https 만 opener 를 끊고 연다.
 * - 핸들러 수준: 서버가 상대 경로·허용 밖 제출 주소를 주면 결제 폼을 제출하지 않고, 절대 URL 은 브라우저
 *   주소와 달라도 그대로 승인키 발급(Ret_URL)에 싣는다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requireSiteAbsoluteUrl, assertTrustedPgFormAction, requestPaymentHandler } from '../handlers/requestPayment';
import { openExternalReceiptWindow } from '../index';

/** 서버(client-config)가 사이트 주소로 만들어 내려주는 결과 수신 주소 — 브라우저(jsdom) 주소와 다른 출처 */
const SITE_CALLBACK_URL = 'https://shop.example.test/plugins/sirsoft-pay_nhnkcp/payment/callback';
const APPROVAL_KEY_PATH = '/plugins/sirsoft-pay_nhnkcp/mobile/approval-key';
const KCP_MOBILE_PAY_URL = 'https://testsmpay.kcp.co.kr/pay/mobileGW.kcp';

const PG_PAYMENT = {
    order_number: 'ORD-HOST-001',
    order_name: 'Host Trust Order',
    amount: 10000,
    payment_method: 'card',
};

let apiGet: ReturnType<typeof vi.fn>;
let apiPost: ReturnType<typeof vi.fn>;
let modalOpen: ReturnType<typeof vi.fn>;
let submitSpy: ReturnType<typeof vi.spyOn>;
let originalUserAgent: PropertyDescriptor | undefined;

/**
 * 모바일 결제 흐름(승인키 발급 → pay_url 로 폼 제출)을 쓰도록 G7Core·UA 를 모킹합니다.
 *
 * @param callbackUrl client-config 의 callback_urls.callback
 * @param payUrl 승인키 발급 응답의 pay_url
 */
function mockKcpMobileEnvironment(callbackUrl: string, payUrl: string = KCP_MOBILE_PAY_URL): void {
    apiGet = vi.fn().mockResolvedValue({
        data: {
            client_id: 'T0000',
            sdk_url: 'https://testpay.kcp.co.kr/plugin/payplus_web.jsp',
            callback_urls: { callback: callbackUrl },
        },
    });
    apiPost = vi.fn().mockResolvedValue({
        success: true,
        data: { pay_url: payUrl, fields: { approval_key: 'stub-approval-key' } },
    });
    modalOpen = vi.fn();
    (window as unknown as Record<string, unknown>).G7Core = {
        api: { get: apiGet, post: apiPost },
        state: { setLocal: vi.fn() },
        modal: { open: modalOpen },
        toast: { error: vi.fn() },
    };
    Object.defineProperty(window.navigator, 'userAgent', {
        value: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Mobile Safari/537.36',
        configurable: true,
    });
}

/**
 * 핸들러를 실행하고 끝나거나(거부 경로) 폼 제출 뒤 멈출 때까지(모바일 페이지 전환 경로) 기다립니다.
 *
 * 모바일 경로는 폼 제출 뒤 페이지 이동을 기다리며 영원히 끝나지 않으므로 짧은 대기와 경주시킨다.
 */
async function runHandlerUntilSettled(): Promise<void> {
    await Promise.race([
        requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } }),
        new Promise<void>((resolve) => setTimeout(resolve, 50)),
    ]);
}

beforeEach(() => {
    originalUserAgent = Object.getOwnPropertyDescriptor(window.navigator, 'userAgent');
    submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
    delete (window as unknown as Record<string, unknown>).G7Core;
    if (originalUserAgent) {
        Object.defineProperty(window.navigator, 'userAgent', originalUserAgent);
    } else {
        delete (window.navigator as unknown as Record<string, unknown>).userAgent;
    }
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('NHN KCP — PG 전송 주소·제출 주소·영수증 창', () => {
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
        expect(() => assertTrustedPgFormAction('https://testsmpay.kcp.co.kr/pay/mobileGW.kcp')).not.toThrow();
        expect(() => assertTrustedPgFormAction('http://testsmpay.kcp.co.kr/pay/mobileGW.kcp')).toThrow();
        expect(() => assertTrustedPgFormAction('https://evil.example/collect')).toThrow();
        expect(() => assertTrustedPgFormAction('https://kcp.co.kr.evil.example/x')).toThrow();
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
    it('client-config 가 상대 경로 결과 수신 주소를 주면 승인키를 받지 않고 결제 폼도 제출하지 않는다', async () => {
        mockKcpMobileEnvironment('/plugins/sirsoft-pay_nhnkcp/payment/callback');

        await runHandlerUntilSettled();

        expect(apiGet).toHaveBeenCalledTimes(1);
        expect(apiPost).toHaveBeenCalledTimes(0);
        expect(submitSpy).toHaveBeenCalledTimes(0);
        expect(modalOpen).toHaveBeenCalledWith('nhnkcp_payment_error_modal');
    });

    // @scenario host_header=forged, sink=front_pg_form_action
    // @effects front_pg_form_action_allowlisted
    it('승인키 응답의 제출 주소가 KCP 도메인이 아니면 결제 폼을 제출하지 않는다', async () => {
        mockKcpMobileEnvironment(SITE_CALLBACK_URL, 'https://evil.example/pay');

        await runHandlerUntilSettled();

        expect(apiPost).toHaveBeenCalledTimes(1);
        expect(submitSpy).toHaveBeenCalledTimes(0);
        expect(modalOpen).toHaveBeenCalledWith('nhnkcp_payment_error_modal');
    });

    // @scenario host_header=forged, sink=front_pg_urls
    // @effects front_pg_urls_use_server_site_address_unchanged
    it('브라우저 주소가 사이트 주소와 달라도 서버가 준 절대 URL 을 그대로 Ret_URL 로 싣는다', async () => {
        expect(window.location.origin).not.toBe('https://shop.example.test');
        mockKcpMobileEnvironment(SITE_CALLBACK_URL);

        await runHandlerUntilSettled();

        expect(apiPost).toHaveBeenCalledTimes(1);
        expect(apiPost.mock.calls[0][0]).toBe(APPROVAL_KEY_PATH);
        expect(apiPost.mock.calls[0][1].ret_url).toBe(SITE_CALLBACK_URL);
        expect(submitSpy).toHaveBeenCalledTimes(1);
        expect((submitSpy.mock.instances[0] as unknown as HTMLFormElement).action).toBe(KCP_MOBILE_PAY_URL);
    });
});
