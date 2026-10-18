// e2e:allow 가상계좌 결제창은 외부 나이스페이먼츠 테스트 결제창으로 이어져 공용 E2E 로 재현하지 않는다. 대체 검증: Vitest 바이트 절단 단언 2건 + 브라우저 실측(한글 6자 주문자 → 결제창 예금주명 5자 「남궁홍길동」, 주문 입금대기)
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

        // 한 시험에서 결제를 여러 번 부르면 마지막 제출 폼을 본다(입금 기한·구매자명 시험)
        function getSubmittedField(name: string): string | null {
            const form = submitSpy.mock.instances[submitSpy.mock.instances.length - 1] as HTMLFormElement;
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

        /**
         * 가상계좌 계좌 만료 시각은 주문의 입금 기한(쇼핑몰 자동취소 기한)이다 — 넘기지 않으면 나이스가 자기
         * 기본 기한으로 계좌를 열고 콜백이 그 값으로 주문 기한을 덮어쓴다.
         *
         * @scenario due=present
         * @effects vbank_exp_date_carries_order_vbank_due
         */
        it('가상계좌는 주문 입금 기한을 VbankExpDate(YYYYMMDDHHmm) 로 넘긴다', async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: { ...PG_PAYMENT, vbank_due_at: '2026-10-10T13:39:23+09:00' }, paymentMethod: 'vbank' },
            });

            expect(getSubmittedField('VbankExpDate')).toBe('202610101339');
        });

        /**
         * @scenario due=absent
         * @effects missing_due_or_other_method_sends_nothing
         */
        it('입금 기한이 없거나 가상계좌가 아니면 VbankExpDate 를 보내지 않는다', async () => {
            await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'vbank' } });
            expect(getSubmittedField('VbankExpDate')).toBeNull();

            await requestPaymentHandler({
                params: { pgPaymentData: { ...PG_PAYMENT, vbank_due_at: '2026-10-10T13:39:23+09:00' }, paymentMethod: 'card' },
            });
            expect(getSubmittedField('VbankExpDate')).toBeNull();
        });

        /**
         * 나이스 가상계좌 결제창은 구매자명을 입금자명(예금주명) 기본값으로 쓰고 10바이트(EUC-KR — 한글 5자)를
         * 넘으면 「10bytes 이하」로 거절한다. 한글 6자 이상 주문자는 가상계좌로 결제할 수 없었다(후속 B 실측).
         *
         * @scenario case=vbank_over_limit
         * @effects vbank_buyer_name_cut_to_ten_euc_kr_bytes
         */
        it('가상계좌는 구매자명을 EUC-KR 10바이트(한글 5자) 안으로 글자 단위로 자른다', async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: { ...PG_PAYMENT, customer_name: '남궁홍길동이' }, paymentMethod: 'vbank' },
            });
            expect(getSubmittedField('BuyerName')).toBe('남궁홍길동');

            await requestPaymentHandler({
                params: { pgPaymentData: { ...PG_PAYMENT, customer_name: 'ab홍길동cd' }, paymentMethod: 'vbank' },
            });
            expect(getSubmittedField('BuyerName')).toBe('ab홍길동cd');

            await requestPaymentHandler({
                params: { pgPaymentData: { ...PG_PAYMENT, customer_name: 'abc홍길동cd' }, paymentMethod: 'vbank' },
            });
            expect(getSubmittedField('BuyerName')).toBe('abc홍길동c');
        });

        /**
         * @scenario case=within_limit_or_other_method
         * @effects buyer_name_kept_when_within_limit_or_other_method
         */
        it('한도 안의 이름과 가상계좌가 아닌 결제수단은 구매자명을 그대로 넘긴다', async () => {
            await requestPaymentHandler({
                params: { pgPaymentData: { ...PG_PAYMENT, customer_name: '홍길동' }, paymentMethod: 'vbank' },
            });
            expect(getSubmittedField('BuyerName')).toBe('홍길동');

            await requestPaymentHandler({
                params: { pgPaymentData: { ...PG_PAYMENT, customer_name: '남궁홍길동이' }, paymentMethod: 'card' },
            });
            expect(getSubmittedField('BuyerName')).toBe('남궁홍길동이');
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
/**
 * 클레임 추가결제 창을 닫으면 서버가 준 신청서 상세 주소로 돌아간다 (이커머스 EC-455 계약).
 *
 * 서버는 클레임 결제의 `pg_payment_data.claim_cancel_return_url` 에 회원/비회원 신청서 상세
 * (`?claim_payment=cancelled`)를 싣는다. PC 결제창을 닫아도 그 주소로 가지 않으면 신청서 상세의
 * [결제하기]가 「결제 진행 중…」으로 묶인 채 취소 안내도 뜨지 않는다
 * (2026-10-06 S2 실측 EX20261006-1447332830 · CP20261006-2149417325).
 */
describe('클레임 추가결제 창 닫힘 → 신청서 상세 복귀', () => {
    const CLAIM_RETURN = 'https://shop.example.test/mypage/claims/EX20261006-0001?claim_payment=cancelled';
    let originalLocation: Location;
    let hrefSet: string[];

    beforeEach(() => {
        originalLocation = window.location;
        hrefSet = [];
        const fakeLocation = {
            origin: 'https://shop.example.test',
            pathname: '/mypage/claims/EX20261006-0001',
            search: '',
            get href() { return 'https://shop.example.test/mypage/claims/EX20261006-0001'; },
            set href(v: string) { hrefSet.push(v); },
        };
        Object.defineProperty(window, 'location', { value: fakeLocation, configurable: true, writable: true });

        (window as Record<string, unknown>).goPay = vi.fn();
        (window as Record<string, unknown>).fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ ediDate: '20260101000000', signData: 'sig', mid: 'nicepay00m' }),
        });
        (window as Record<string, unknown>).G7Core = {
            api: {
                get: vi.fn((url: string) => url.includes('client-config')
                    ? Promise.resolve({ data: {
                        mid: 'nicepay00m',
                        sdk_url: `https://${KNOWN_SDK_HOSTS[0]}/v3/webstd/js/nicepay-3.0.js`,
                        sign_data_url: 'https://shop.example.test/api/plugins/sirsoft-pay_nicepayments/payment/sign-data',
                        callback_url: 'https://shop.example.test/plugins/sirsoft-pay_nicepayments/payment/callback',
                        close_report_url: 'https://shop.example.test/api/plugins/sirsoft-pay_nicepayments/payment/close-report',
                        useEscrow: false,
                    } })
                    : Promise.resolve({ data: {} })),
                // SignData 는 G7Core.api 로 받는다(#639 — 원시 fetch 를 쓰지 않는다). 그 밖의 POST(닫힘 보고)는 성공만 돌려준다.
                post: vi.fn((url: string) => url.includes('sign-data')
                    ? Promise.resolve({ ediDate: '20260101000000', signData: 'sig', mid: 'nicepay00m' })
                    : Promise.resolve({ success: true })),
            },
            state: { setLocal: vi.fn(), get: vi.fn(() => ({})) },
            toast: { error: vi.fn() },
        };
    });

    afterEach(() => {
        Object.defineProperty(window, 'location', { value: originalLocation, configurable: true, writable: true });
        delete (window as Record<string, unknown>).goPay;
        delete (window as Record<string, unknown>).fetch;
        delete (window as Record<string, unknown>).G7Core;
        delete (window as Record<string, unknown>).nicepayClose;
        vi.restoreAllMocks();
    });

    it('클레임 결제창을 닫으면 claim_cancel_return_url 로 이동한다', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: { ...PG_PAYMENT, order_number: 'CP20261006-0001', claim_cancel_return_url: CLAIM_RETURN } } });

        expect((window as Record<string, unknown>).goPay).toHaveBeenCalledTimes(1);
        (window as unknown as { nicepayClose: (c: string, m: string) => void }).nicepayClose('', '');
        await Promise.resolve();

        expect(hrefSet).toEqual([CLAIM_RETURN]);
    });

    it('주문 결제창 닫힘은 종전대로 이동하지 않는다 (claim_cancel_return_url 없음)', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        (window as unknown as { nicepayClose: (c: string, m: string) => void }).nicepayClose('', '');
        await Promise.resolve();

        expect(hrefSet).toEqual([]);
    });
});

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
