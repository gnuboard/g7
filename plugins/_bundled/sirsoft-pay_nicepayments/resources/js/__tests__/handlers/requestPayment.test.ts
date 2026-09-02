/**
 * requestPayment 핸들러 테스트
 *
 * 나이스페이먼츠 결제창 호출 핸들러의 입력 검증 및 에러 경로 동작을 검증합니다.
 * SDK 로드/goPay 호출/모바일 redirect 등 외부 부수효과 의존 흐름은
 * tests/scenarios 매니페스트(통합 시나리오)에서 다루며, 본 단위 테스트는
 * "초기 가드 + catch 블록 정상 호출" 두 축에 집중합니다.
 */
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { assertTrustedSdkUrl, KNOWN_SDK_HOSTS, requestPaymentHandler } from '../../handlers/requestPayment';

const PG_PAYMENT = {
    order_number: 'ORD-001',
    order_name: 'Test Order',
    amount: 10000,
    customer_email: 'buyer@example.com',
    customer_phone: '01012345678',
};

describe('requestPaymentHandler', () => {
    let apiGet: ReturnType<typeof vi.fn>;
    let setLocalSpy: ReturnType<typeof vi.fn>;
    let toastErrorSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        apiGet = vi.fn();
        setLocalSpy = vi.fn();
        toastErrorSpy = vi.fn();
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet },
            state: { setLocal: setLocalSpy },
            toast: { error: toastErrorSpy },
        };
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        vi.restoreAllMocks();
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

    it('client config API 자체가 throw 하면 catch 블록에서 toast.error + setLocal 복구', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('Network error'));

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('requestPayment error'),
            expect.any(Error)
        );
        // catch 블록은 paymentMethod 기본값 'card' 로 setLocal 복구
        expect(setLocalSpy).toHaveBeenCalledWith({
            isSubmittingOrder: false,
            paymentMethod: 'card',
        });
        expect(toastErrorSpy).toHaveBeenCalledWith(
            expect.stringContaining('결제 중 오류가 발생')
        );
    });

    it('paramPaymentMethod 가 명시되면 catch 시 그 값으로 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('boom'));

        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'nicepay_kakaopay',
            },
        });

        expect(setLocalSpy).toHaveBeenCalledWith({
            isSubmittingOrder: false,
            paymentMethod: 'nicepay_kakaopay',
        });
    });

    it('catch 블록은 결제 진행 플래그를 반드시 false 로 리셋', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('fail'));
        (window as unknown as Record<string, unknown>).__sirsoftNicepayPaymentInProgress = true;

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(
            (window as unknown as Record<string, unknown>).__sirsoftNicepayPaymentInProgress
        ).toBe(false);
    });

    /**
     * 모바일 환경에서 카드 외 결제수단 선택 시 PayMethod 회귀 차단.
     *
     * 증상 (회귀 시): 모바일에서 가상계좌/계좌이체/휴대폰결제를 선택해도
     *   NicePay 모바일 결제창이 카드결제 화면으로 열림.
     * 원인 후보: requestPaymentHandler 의 paymentMethod → payMethodMap 매핑이
     *   isMobile 분기 진입 전에 적용되어야 하는데, 누락되거나 모바일 코드 경로에서
     *   덮어쓰이면 PayMethod 가 항상 CARD 로 고정.
     *
     * 검증 전략: navigator.userAgentData.mobile = true 로 모바일 판정 강제.
     *   fetch (signData) 와 apiGet (client-config + order) 을 mock 한 뒤,
     *   HTMLFormElement.prototype.submit 를 spy 로 가로채 form 의 hidden input
     *   PayMethod 값을 검증한다 (실제 NicePay 모바일 endpoint 로 redirect 되지 않음).
     */
    describe('모바일 환경 일반 결제수단 PayMethod 매핑', () => {
        const NICEPAY_MOBILE_HOST = 'web.nicepay.co.kr';

        let submitSpy: ReturnType<typeof vi.spyOn>;
        let fetchSpy: ReturnType<typeof vi.fn>;
        let originalUserAgentData: PropertyDescriptor | undefined;

        beforeEach(() => {
            originalUserAgentData = Object.getOwnPropertyDescriptor(navigator, 'userAgentData');
            Object.defineProperty(navigator, 'userAgentData', {
                value: { mobile: true },
                configurable: true,
            });

            apiGet.mockImplementation((url: string) => {
                if (url.includes('client-config/nicepayments')) {
                    return Promise.resolve({
                        data: {
                            mid: 'SRpaytestm',
                            sdk_url: 'https://example/sdk.js',
                            sign_data_url: '/api/plugins/sirsoft-pay_nicepayments/sign-data',
                            useEscrow: false,
                        },
                    });
                }
                if (url.includes('/user/orders/')) {
                    return Promise.resolve({ data: {} });
                }
                return Promise.resolve({});
            });

            fetchSpy = vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    ediDate: '20260101000000',
                    signData: 'fake-sig',
                    mid: 'SRpaytestm',
                }),
            });
            (window as unknown as Record<string, unknown>).fetch = fetchSpy;

            submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
        });

        afterEach(() => {
            if (originalUserAgentData) {
                Object.defineProperty(navigator, 'userAgentData', originalUserAgentData);
            } else {
                delete (navigator as unknown as Record<string, unknown>).userAgentData;
            }
            submitSpy.mockRestore();
            delete (window as unknown as Record<string, unknown>).fetch;
        });

        function getSubmittedPayMethod(): string | null {
            const form = submitSpy.mock.instances[0] as HTMLFormElement;
            const input = form.querySelector('input[name="PayMethod"]') as HTMLInputElement | null;
            return input?.value ?? null;
        }

        function getSubmittedFormAction(): string {
            const form = submitSpy.mock.instances[0] as HTMLFormElement;
            return form.action;
        }

        it("가상계좌(vbank)는 PayMethod='VBANK' 로 모바일 endpoint 에 POST", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'vbank' },
            });

            expect(submitSpy).toHaveBeenCalledTimes(1);
            expect(getSubmittedPayMethod()).toBe('VBANK');
            expect(getSubmittedFormAction()).toContain(NICEPAY_MOBILE_HOST);
        });

        it("계좌이체(bank)는 PayMethod='BANK' 로 모바일 endpoint 에 POST", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'bank' },
            });

            expect(submitSpy).toHaveBeenCalledTimes(1);
            expect(getSubmittedPayMethod()).toBe('BANK');
            expect(getSubmittedFormAction()).toContain(NICEPAY_MOBILE_HOST);
        });

        it("휴대폰결제(phone)는 PayMethod='CELLPHONE' 로 모바일 endpoint 에 POST", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'phone' },
            });

            expect(submitSpy).toHaveBeenCalledTimes(1);
            expect(getSubmittedPayMethod()).toBe('CELLPHONE');
            expect(getSubmittedFormAction()).toContain(NICEPAY_MOBILE_HOST);
        });

        it("신용카드(card)는 PayMethod='CARD'", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' },
            });

            expect(submitSpy).toHaveBeenCalledTimes(1);
            expect(getSubmittedPayMethod()).toBe('CARD');
        });

        it('SignData 요청은 주문자 이메일과 전화번호를 함께 전송', async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' },
            });

            expect(fetchSpy).toHaveBeenCalledTimes(1);
            const [, init] = fetchSpy.mock.calls[0];
            expect(JSON.parse(String(init?.body))).toEqual({
                amt: PG_PAYMENT.amount,
                moid: PG_PAYMENT.order_number,
                buyer_email: PG_PAYMENT.customer_email,
                buyer_phone: PG_PAYMENT.customer_phone,
            });
        });

        it("paymentMethod 미명시 시 _local.paymentMethod 로 fallback", async () => {
            (window as unknown as Record<string, unknown>).__templateApp = {
                globalState: { _local: { paymentMethod: 'vbank' } },
            };

            await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

            expect(getSubmittedPayMethod()).toBe('VBANK');

            delete (window as unknown as Record<string, unknown>).__templateApp;
        });

        it("간편결제(nicepay_kakaopay)는 PayMethod='CARD' + DirectKakao=Y", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'nicepay_kakaopay' },
            });

            const form = submitSpy.mock.instances[0] as HTMLFormElement;
            const reservedInput = form.querySelector('input[name="NicepayReserved"]') as HTMLInputElement | null;
            const mallReservedInput = form.querySelector('input[name="MallReserved"]') as HTMLInputElement | null;
            const mallReserved1Input = form.querySelector('input[name="MallReserved1"]') as HTMLInputElement | null;

            expect(getSubmittedPayMethod()).toBe('CARD');
            expect(reservedInput?.value).toBe('DirectKakao=Y');
            expect(mallReservedInput?.value).toBe('nicepay_easy_pay_method=nicepay_kakaopay');
            expect(mallReserved1Input?.value).toBe('nicepay_kakaopay');
        });

        it('모바일 form 은 acceptCharset=euc-kr', async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'vbank' },
            });

            const form = submitSpy.mock.instances[0] as HTMLFormElement;
            expect(form.acceptCharset).toBe('euc-kr');
        });
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
    const TRUSTED_SDK_URL = 'https://web.nicepay.co.kr/v3/webstd/js/nicepay-3.0.js';

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
