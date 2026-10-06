/**
 * requestPayment 핸들러 테스트
 *
 * 나이스페이먼츠 결제창 호출 핸들러의 입력 검증 및 에러 경로 동작을 검증합니다.
 * SDK 로드/goPay 호출/모바일 redirect 등 외부 부수효과 의존 흐름은
 * tests/scenarios 매니페스트(통합 시나리오)에서 다루며, 본 단위 테스트는
 * "초기 가드 + catch 블록 정상 호출" 두 축에 집중합니다.
 *
 * 결제수단은 액션 인자(`paymentMethod` → `pgPaymentData.payment_method`)로만 받고, SignData 발급은 G7Core.api 로
 * 보낸다 — 엔진 내부 화면 상태(`window.__templateApp`)·로그인 토큰 직접 읽기·원시 fetch 를 쓰지 않는다(#639).
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/public-core-api-only.yaml
 */
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { assertTrustedSdkUrl, KNOWN_SDK_HOSTS, requestPaymentHandler } from '../../handlers/requestPayment';

/** 서버(buildPgPaymentData)가 내려주는 모양 — 결제수단 ID 를 함께 싣는다 */
const PG_PAYMENT = {
    order_number: 'ORD-001',
    order_name: 'Test Order',
    amount: 10000,
    payment_method: 'card',
    customer_email: 'buyer@example.com',
    customer_phone: '01012345678',
};

describe('requestPaymentHandler', () => {
    let apiGet: ReturnType<typeof vi.fn>;
    let apiPost: ReturnType<typeof vi.fn>;
    let setLocalSpy: ReturnType<typeof vi.fn>;
    let toastErrorSpy: ReturnType<typeof vi.fn>;
    let globalState: Record<string, unknown>;

    beforeEach(() => {
        apiGet = vi.fn();
        apiPost = vi.fn();
        setLocalSpy = vi.fn();
        toastErrorSpy = vi.fn();
        globalState = {};
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet, post: apiPost },
            state: { setLocal: setLocalSpy, get: () => globalState },
            toast: { error: toastErrorSpy },
        };
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        vi.restoreAllMocks();
    });

    // @scenario concern=payment_entry
    // @effects payment_entry_guarded
    it('pgPaymentData가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        await requestPaymentHandler({ params: {} });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('pgPaymentData is required')
        );
        expect(apiGet).not.toHaveBeenCalled();
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    // @scenario concern=payment_entry
    // @effects payment_method_from_action_argument_only
    it('결제수단이 액션 인자에 없으면 결제를 시작하지 않는다 — 화면 상태나 card 로 추측하지 않는다', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        (window as unknown as Record<string, unknown>).__templateApp = {
            globalState: { _local: { paymentMethod: 'vbank' } },
        };

        try {
            const { payment_method: _omit, ...withoutMethod } = PG_PAYMENT;
            await requestPaymentHandler({ params: { pgPaymentData: withoutMethod } });

            expect(apiGet).not.toHaveBeenCalled();
            expect(setLocalSpy).toHaveBeenCalledWith({ isSubmittingOrder: false });
            expect(toastErrorSpy).toHaveBeenCalledWith(expect.stringContaining('결제 중 오류가 발생'));
        } finally {
            delete (window as unknown as Record<string, unknown>).__templateApp;
        }
    });

    // @scenario concern=payment_entry
    // @effects payment_entry_guarded
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

    // @scenario concern=payment_entry
    // @effects payment_entry_guarded
    it('client config API 자체가 throw 하면 catch 블록에서 toast.error + setLocal 복구', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('Network error'));

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('requestPayment error'),
            expect.any(Error)
        );
        // 서버가 내려준 결제수단(pgPaymentData.payment_method)으로 복구
        expect(setLocalSpy).toHaveBeenCalledWith({
            isSubmittingOrder: false,
            paymentMethod: 'card',
        });
        expect(toastErrorSpy).toHaveBeenCalledWith(
            expect.stringContaining('결제 중 오류가 발생')
        );
    });

    // @scenario concern=payment_entry
    // @effects payment_method_from_action_argument_only
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

    // @scenario concern=payment_entry
    // @effects payment_entry_guarded
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
     *   G7Core.api.post (signData) 와 apiGet (client-config + order) 을 mock 한 뒤,
     *   HTMLFormElement.prototype.submit 를 spy 로 가로채 form 의 hidden input
     *   PayMethod 값을 검증한다 (실제 NicePay 모바일 endpoint 로 redirect 되지 않음).
     */
    describe('모바일 환경 일반 결제수단 PayMethod 매핑', () => {
        const NICEPAY_MOBILE_HOST = 'web.nicepay.co.kr';
        const SIGN_DATA_URL = 'https://shop.example.test/plugins/sirsoft-pay_nicepayments/payment/sign-data';

        let submitSpy: ReturnType<typeof vi.spyOn>;
        let fetchSpy: ReturnType<typeof vi.spyOn>;
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
                            sign_data_url: SIGN_DATA_URL,
                            callback_url: 'https://shop.example.test/plugins/sirsoft-pay_nicepayments/payment/callback',
                            useEscrow: false,
                        },
                    });
                }
                if (url.includes('/user/orders/')) {
                    return Promise.resolve({ data: {} });
                }
                return Promise.resolve({});
            });

            apiPost.mockResolvedValue({
                ediDate: '20260101000000',
                signData: 'fake-sig',
                mid: 'SRpaytestm',
            });
            fetchSpy = vi.spyOn(globalThis, 'fetch');

            submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
        });

        afterEach(() => {
            if (originalUserAgentData) {
                Object.defineProperty(navigator, 'userAgentData', originalUserAgentData);
            } else {
                delete (navigator as unknown as Record<string, unknown>).userAgentData;
            }
            submitSpy.mockRestore();
        });

        function getSubmittedField(name: string): string | null {
            const form = submitSpy.mock.instances[0] as HTMLFormElement;
            const input = form.querySelector(`input[name="${name}"]`) as HTMLInputElement | null;
            return input?.value ?? null;
        }

        function getSubmittedFormAction(): string {
            const form = submitSpy.mock.instances[0] as HTMLFormElement;
            return form.action;
        }

        // @scenario concern=pay_method_mapping
        // @effects mobile_pay_method_mapped
        it("가상계좌(vbank)는 PayMethod='VBANK' 로 모바일 endpoint 에 POST", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'vbank' },
            });

            expect(submitSpy).toHaveBeenCalledTimes(1);
            expect(getSubmittedField('PayMethod')).toBe('VBANK');
            expect(getSubmittedFormAction()).toContain(NICEPAY_MOBILE_HOST);
        });

        // @scenario concern=pay_method_mapping
        // @effects mobile_pay_method_mapped
        it("계좌이체(bank)는 PayMethod='BANK' 로 모바일 endpoint 에 POST", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'bank' },
            });

            expect(submitSpy).toHaveBeenCalledTimes(1);
            expect(getSubmittedField('PayMethod')).toBe('BANK');
            expect(getSubmittedFormAction()).toContain(NICEPAY_MOBILE_HOST);
        });

        // @scenario concern=pay_method_mapping
        // @effects mobile_pay_method_mapped
        it("휴대폰결제(phone)는 PayMethod='CELLPHONE' 로 모바일 endpoint 에 POST", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'phone' },
            });

            expect(submitSpy).toHaveBeenCalledTimes(1);
            expect(getSubmittedField('PayMethod')).toBe('CELLPHONE');
            expect(getSubmittedFormAction()).toContain(NICEPAY_MOBILE_HOST);
        });

        // @scenario concern=pay_method_mapping
        // @effects mobile_pay_method_mapped
        it("신용카드(card)는 PayMethod='CARD'", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' },
            });

            expect(submitSpy).toHaveBeenCalledTimes(1);
            expect(getSubmittedField('PayMethod')).toBe('CARD');
        });

        // @scenario concern=pay_method_mapping
        // @effects payment_method_from_action_argument_only
        it('paymentMethod 미명시 시 서버가 내려준 pgPaymentData.payment_method 를 쓴다 — 엔진 화면 상태는 보지 않는다', async () => {
            (window as unknown as Record<string, unknown>).__templateApp = {
                globalState: { _local: { paymentMethod: 'card' } },
            };

            try {
                await requestPaymentHandler({ params: { pgPaymentData: { ...PG_PAYMENT, payment_method: 'vbank' } } });

                expect(getSubmittedField('PayMethod')).toBe('VBANK');
            } finally {
                delete (window as unknown as Record<string, unknown>).__templateApp;
            }
        });

        // @scenario concern=sign_data_member
        // @effects sign_data_via_core_api
        it('SignData 요청은 G7Core.api 로 주문자 이메일과 전화번호를 함께 전송 — 원시 fetch 를 쓰지 않는다', async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' },
            });

            expect(apiPost).toHaveBeenCalledTimes(1);
            const [url, body] = apiPost.mock.calls[0];
            expect(url).toBe(SIGN_DATA_URL);
            expect(body).toEqual({
                amt: PG_PAYMENT.amount,
                moid: PG_PAYMENT.order_number,
                buyer_email: PG_PAYMENT.customer_email,
                buyer_phone: PG_PAYMENT.customer_phone,
            });
            expect(fetchSpy).not.toHaveBeenCalled();
            expect(getSubmittedField('SignData')).toBe('fake-sig');
        });

        // @scenario concern=sign_data_guest
        // @effects sign_data_via_core_api, guest_token_read_from_public_state_contract
        it('비회원이면 SignData 요청에 공개 상태 계약의 조회 토큰을 싣는다 — 실패 주문 재결제 복구의 소유자 증명', async () => {
            globalState.guestOrderToken = '1999999999|guest-token';

            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' },
            });

            const headers = (apiPost.mock.calls[0][2]?.headers ?? {}) as Record<string, string>;
            expect(headers['X-Guest-Order-Token']).toBe('1999999999|guest-token');
            expect(headers.Authorization).toBeUndefined();
        });

        // @scenario concern=sign_data_member
        // @effects sign_data_via_core_api
        it('회원 Bearer 는 플러그인이 싣지 않는다 — 저장소의 로그인 토큰을 읽지 않고 G7Core.api 에 맡긴다', async () => {
            window.localStorage.setItem('auth_token', 'member-token');

            try {
                await requestPaymentHandler({
                    params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' },
                });

                const headers = (apiPost.mock.calls[0][2]?.headers ?? {}) as Record<string, string>;
                expect(headers.Authorization).toBeUndefined();
                expect(headers['X-Guest-Order-Token']).toBeUndefined();
            } finally {
                window.localStorage.removeItem('auth_token');
            }
        });

        // @scenario concern=sign_data_member
        // @effects payment_entry_guarded
        it('SignData 발급이 실패하면 결제 폼을 제출하지 않고 오류를 알린다', async () => {
            vi.spyOn(console, 'error').mockImplementation(() => {});
            apiPost.mockRejectedValue(new Error('403'));

            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' },
            });

            expect(submitSpy).not.toHaveBeenCalled();
            expect(toastErrorSpy).toHaveBeenCalledWith(expect.stringContaining('결제 중 오류가 발생'));
        });

        // @scenario concern=pay_method_mapping
        // @effects mobile_pay_method_mapped
        it("간편결제(nicepay_kakaopay)는 PayMethod='CARD' + DirectKakao=Y", async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'nicepay_kakaopay' },
            });

            expect(getSubmittedField('PayMethod')).toBe('CARD');
            expect(getSubmittedField('NicepayReserved')).toBe('DirectKakao=Y');
            expect(getSubmittedField('MallReserved')).toBe('nicepay_easy_pay_method=nicepay_kakaopay');
            expect(getSubmittedField('MallReserved1')).toBe('nicepay_kakaopay');
        });

        // @scenario concern=pay_method_mapping
        // @effects mobile_pay_method_mapped
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

    // @scenario concern=sdk_host
    // @effects sdk_host_gated
    it('신뢰 호스트 https URL 은 통과한다', () => {
        expect(() => assertTrustedSdkUrl(TRUSTED_SDK_URL)).not.toThrow();
    });

    // @scenario concern=sdk_host
    // @effects sdk_host_gated
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

    // @scenario concern=sdk_host
    // @effects sdk_host_gated
    it('manifest trusted_script_hosts 와 KNOWN_SDK_HOSTS 가 일치한다', () => {
        const manifest = JSON.parse(
            readFileSync(resolve(__dirname, '../../../../plugin.json'), 'utf-8')
        );

        expect([...(manifest.trusted_script_hosts ?? [])].sort()).toEqual(
            [...KNOWN_SDK_HOSTS].sort()
        );
    });

    // @scenario concern=sdk_host
    // @effects sdk_host_gated
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
