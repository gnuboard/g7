/**
 * requestPayment 핸들러 테스트
 *
 * NHN KCP 결제창 호출 핸들러의 입력 검증 및 에러 경로 동작을 검증합니다.
 * iframe SDK 로드 / KCP_Pay_Execute 호출 등 외부 부수효과 의존 흐름은
 * tests/scenarios 매니페스트(통합 시나리오)에서 다루며, 본 단위 테스트는
 * "초기 가드 + catch 블록 정상 호출" 두 축에 집중합니다.
 */
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { assertTrustedSdkUrl, KNOWN_SDK_HOSTS,
    buildKcpEasyPayReturnFields,
    buildKcpTaxFields,
    isSupportedKcpCurrency,
    requestPaymentHandler,
    watchKcpPaymentFrameClosure,
} from '../../handlers/requestPayment';

const PG_PAYMENT = {
    order_number: 'ORD-001',
    order_name: 'Test Order',
    amount: 10000,
    pay_method: 'card',
};

describe('requestPaymentHandler', () => {
    let apiGet: ReturnType<typeof vi.fn>;
    let setLocalSpy: ReturnType<typeof vi.fn>;
    let modalOpenSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        apiGet = vi.fn();
        setLocalSpy = vi.fn();
        modalOpenSpy = vi.fn();
        document.documentElement.lang = 'ko';
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet },
            state: { setLocal: setLocalSpy },
            modal: { open: modalOpenSpy },
            toast: { error: vi.fn() },
        };
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('pgPaymentData가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        await requestPaymentHandler({ params: {} });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('pgPaymentData is required')
        );
        expect(apiGet).not.toHaveBeenCalled();
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('iOS 모바일 기기가 아니면 Apple Pay 결제 요청을 client config 호출 전 차단', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'nhnkcp_applepay',
            },
        });

        expect(apiGet).not.toHaveBeenCalled();
        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                paymentErrorMessage: '애플페이는 IOS 기기에 모바일결제만 가능합니다.',
                isSubmittingOrder: false,
                paymentMethod: 'nhnkcp_applepay',
            }),
        );
        expect(modalOpenSpy).toHaveBeenCalledWith('nhnkcp_payment_error_modal');
    });

    it('KRW가 아닌 통화는 client config 호출 전 차단', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: { ...PG_PAYMENT, currency: 'USD' },
            },
        });

        expect(apiGet).not.toHaveBeenCalled();
        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                paymentErrorMessage: 'NHN KCP는 KRW 결제만 지원합니다. (USD)',
                isSubmittingOrder: false,
                paymentMethod: 'card',
            }),
        );
        expect(modalOpenSpy).toHaveBeenCalledWith('nhnkcp_payment_error_modal');
    });

    it('client config 응답에 data 가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockResolvedValue({}); // data 누락

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('Failed to fetch client config'),
            expect.anything()
        );
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('client config API 자체가 throw 하면 catch 블록에서 setLocal 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('Network error'));

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        // catch 블록은 paymentMethod를 pgPaymentData.pay_method 또는 'card' 로 setLocal 복구
        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentMethod: 'card',
            })
        );
    });

    it('pay_method 가 vbank 면 catch 시 그 값으로 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('boom'));

        await requestPaymentHandler({
            params: {
                pgPaymentData: { ...PG_PAYMENT, pay_method: 'vbank' },
            },
        });

        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentMethod: 'vbank',
            })
        );
    });

    it('KCP 결제 iframe 이 제거되면 닫힘 콜백을 한 번만 실행', async () => {
        vi.useFakeTimers();

        const iframe = document.createElement('iframe');
        Object.defineProperty(iframe, 'getBoundingClientRect', {
            value: () => ({ width: 100, height: 100 }),
        });
        document.body.appendChild(iframe);

        const onClose = vi.fn();
        const stop = watchKcpPaymentFrameClosure(iframe, onClose);

        iframe.remove();
        await vi.runOnlyPendingTimersAsync();

        expect(onClose).toHaveBeenCalledTimes(1);

        document.body.appendChild(iframe);
        iframe.remove();
        await vi.runOnlyPendingTimersAsync();

        expect(onClose).toHaveBeenCalledTimes(1);
        stop();
        vi.useRealTimers();
    });

    it('KCP 결제 iframe 감시를 중지하면 제거되어도 닫힘 콜백을 실행하지 않음', async () => {
        vi.useFakeTimers();

        const iframe = document.createElement('iframe');
        Object.defineProperty(iframe, 'getBoundingClientRect', {
            value: () => ({ width: 100, height: 100 }),
        });
        document.body.appendChild(iframe);

        const onClose = vi.fn();
        const stop = watchKcpPaymentFrameClosure(iframe, onClose);

        stop();
        iframe.remove();
        await vi.runOnlyPendingTimersAsync();

        expect(onClose).not.toHaveBeenCalled();
        vi.useRealTimers();
    });

    it('복합과세 분할 필드를 실결제액 기준으로 재배분', () => {
        const fields = buildKcpTaxFields({
            ...PG_PAYMENT,
            amount: 19000,
            tax_amount: 11000,
            vat_amount: 1000,
            tax_free_amount: 10000,
        });

        expect(fields).toEqual({
            tax_flag: 'TG03',
            comm_tax_mny: '8182',
            comm_vat_mny: '818',
            comm_free_mny: '10000',
        });
        expect(
            Number(fields.comm_tax_mny) + Number(fields.comm_vat_mny) + Number(fields.comm_free_mny),
        ).toBe(19000);
    });

    it('KCP 통화 가드는 빈 값과 KRW만 허용', () => {
        expect(isSupportedKcpCurrency(undefined)).toBe(true);
        expect(isSupportedKcpCurrency('KRW')).toBe(true);
        expect(isSupportedKcpCurrency('usd')).toBe(false);
    });

    it('간편결제 원 결제수단을 KCP callback 반환 필드에 싣는다', () => {
        expect(buildKcpEasyPayReturnFields('nhnkcp_naverpay', true)).toEqual({
            param_opt_1: 'nhnkcp_naverpay',
            nhnkcp_easy_pay_method: 'nhnkcp_naverpay',
        });
        expect(buildKcpEasyPayReturnFields('card', false)).toEqual({});
        expect(buildKcpEasyPayReturnFields('nhnkcp_unknown', true)).toEqual({});
    });
});

/**
 * SDK 주입 출처 게이트 (동적 스크립트 주입 정책).
 *
 * PG SDK 는 서비스 SDK 라 자체 호스팅할 수 없다. 그래서 **주입 직전에** 호스트를
 * 확인하는 것이 유일한 게이트다. 확장자 없는 SDK URL 은 정적 검사에도 걸리지 않는다.
 *
 * manifest `trusted_script_hosts` 와 코드 상수가 어긋나면 그 차집합이 그대로
 * 사각이 되므로 두 목록의 일치도 함께 고정한다.
 */
describe('SDK URL 신뢰 호스트 게이트', () => {
    const TRUSTED_SDK_URL = 'https://pay.kcp.co.kr/plugin/payplus_web.jsp';

    it('신뢰 호스트 https URL 은 통과한다', () => {
        expect(() => assertTrustedSdkUrl(TRUSTED_SDK_URL)).not.toThrow();
    });

    it.each([
        ['미신뢰 호스트', 'https://cdn.evil.com/sdk.js'],
        ['http (평문)', TRUSTED_SDK_URL.replace('https://', 'http://')],
        ['protocol-relative', TRUSTED_SDK_URL.replace('https://', '//')],
        ['상대 경로', '/local/sdk.js'],
        ['빈 문자열', ''],
        ['javascript 스킴', 'javascript:alert(1)'],
        ['신뢰 호스트를 userinfo 로 위장', 'https://evil.com/@' + new URL(TRUSTED_SDK_URL).hostname + '/x.js'],
        ['신뢰 호스트를 서브도메인 접미로 위장', 'https://' + new URL(TRUSTED_SDK_URL).hostname + '.evil.com/x.js'],
    ])('%s 는 거부된다', (_label, url) => {
        expect(() => assertTrustedSdkUrl(url as string)).toThrow();
    });

    it('manifest trusted_script_hosts 와 KNOWN_SDK_HOSTS 가 일치한다', () => {
        const manifest = JSON.parse(
            readFileSync(resolve(__dirname, '../../../../plugin.json'), 'utf-8')
        );

        expect([...(manifest.trusted_script_hosts ?? [])].sort()).toEqual(
            [...KNOWN_SDK_HOSTS].sort()
        );
    });

    it('manifest 가 호스트별 사유를 함께 선언한다', () => {
        const manifest = JSON.parse(
            readFileSync(resolve(__dirname, '../../../../plugin.json'), 'utf-8')
        );

        for (const host of KNOWN_SDK_HOSTS) {
            expect(typeof manifest.trusted_script_hosts_reason?.[host]).toBe('string');
            expect(manifest.trusted_script_hosts_reason[host].length).toBeGreaterThan(0);
        }
    });
});
