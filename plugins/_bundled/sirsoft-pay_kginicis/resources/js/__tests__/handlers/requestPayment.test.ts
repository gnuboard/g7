/**
 * requestPayment 핸들러 테스트
 *
 * KG 이니시스 표준결제창 호출 핸들러의 입력 검증 및 에러 경로 동작을 검증합니다.
 * SDK 로드/INIStdPay.pay 호출/모바일 redirect 등 외부 부수효과 의존 흐름은
 * tests/scenarios 매니페스트에서 다루며, 본 단위 테스트는 초기 가드 위주.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requestPaymentHandler } from '../../handlers/requestPayment';
import {
    clearMobilePaymentReturnPending,
    consumeMobilePaymentReturnPending,
} from '../../paymentDomCleanup';

const PG_PAYMENT = {
    order_number: 'ORD-001',
    order_name: 'Test Order',
    amount: 10000,
};

describe('requestPaymentHandler', () => {
    let apiGet: ReturnType<typeof vi.fn>;
    let setLocalSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        apiGet = vi.fn();
        setLocalSpy = vi.fn();
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet },
            state: { setLocal: setLocalSpy },
            toast: { error: vi.fn() },
        };
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        vi.restoreAllMocks();
    });

    it('pgPaymentData가 없으면 조기 반환', async () => {
        await requestPaymentHandler({ params: {} });

        expect(apiGet).not.toHaveBeenCalled();
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('client config 응답에 data 가 없으면 catch 블록에서 setLocal 복구', async () => {
        apiGet.mockResolvedValue({}); // data 누락

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentErrorMessage: 'Failed to fetch KG Inicis client config',
            }),
        );
    });

    it('client config API 자체가 throw 하면 catch 블록에서 setLocal 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('Network error'));

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        // catch 블록은 setLocal로 결제 진행 상태를 복구
        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({ isSubmittingOrder: false })
        );
    });
});

describe('requestPaymentHandler — PC closeUrl', () => {
    const CLIENT_CONFIG = {
        data: {
            mid: 'INIpayTest',
            sdk_url: 'https://stgstdpay.inicis.com/stdjs/INIStdPay.js',
            japan_enabled: false,
            japan_mid: '',
            use_escrow: false,
            use_credit_point: false,
            callback_urls: {
                signature: '/api/plugins/sirsoft-pay_kginicis/payment/signature',
                callback: '/plugins/sirsoft-pay_kginicis/payment/callback',
                close: '/plugins/sirsoft-pay_kginicis/payment/close',
            },
        },
    };

    let apiGet: ReturnType<typeof vi.fn>;
    let apiPost: ReturnType<typeof vi.fn>;
    let paySpy: ReturnType<typeof vi.fn>;
    let originalUserAgent: PropertyDescriptor | undefined;

    beforeEach(() => {
        apiGet = vi.fn().mockResolvedValue(CLIENT_CONFIG);
        apiPost = vi.fn().mockResolvedValue({
            data: {
                signature: 'signature-stub',
                verification: 'verification-stub',
                mKey: 'mkey-stub',
            },
        });
        paySpy = vi.fn();
        (window as any).INIStdPay = { pay: paySpy };
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet, post: apiPost },
            state: { setLocal: vi.fn() },
            toast: { error: vi.fn() },
        };
        originalUserAgent = Object.getOwnPropertyDescriptor(window.navigator, 'userAgent');
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)',
            configurable: true,
        });
    });

    afterEach(() => {
        delete (window as any).INIStdPay;
        delete (window as Record<string, unknown>).G7Core;
        document.body.innerHTML = '';
        if (originalUserAgent) {
            Object.defineProperty(window.navigator, 'userAgent', originalUserAgent);
        }
        vi.restoreAllMocks();
    });

    function getLastPaymentFormFields(): Record<string, string> {
        const forms = document.body.querySelectorAll('form');
        const form = forms[forms.length - 1];
        if (!form) throw new Error('No payment form was created');
        const fields: Record<string, string> = {};
        form.querySelectorAll('input[type="hidden"]').forEach((el) => {
            const input = el as HTMLInputElement;
            fields[input.name] = input.value;
        });
        return fields;
    }

    it('X 버튼 취소 시 체크아웃 SPA를 새로고침하지 않도록 닫기 전용 closeUrl 을 전달', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'card',
            },
        });

        expect(paySpy).toHaveBeenCalledTimes(1);
        const fields = getLastPaymentFormFields();
        expect(fields.returnUrl).toBe(
            `${window.location.origin}/plugins/sirsoft-pay_kginicis/payment/callback`,
        );
        expect(fields.closeUrl).toBe(
            `${window.location.origin}/plugins/sirsoft-pay_kginicis/payment/close`,
        );
        expect(fields.closeUrl).not.toBe('');
        expect(fields.payViewType).toBe('overlay');
    });

    it('한국 표준 결제 폼은 KRW 주문을 WON 통화코드로만 전송한다', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: { ...PG_PAYMENT, currency: 'KRW' },
                paymentMethod: 'card',
            },
        });

        expect(paySpy).toHaveBeenCalledTimes(1);
        const fields = getLastPaymentFormFields();
        expect(fields.currency).toBe('WON');
    });

    it('USD 같은 제3통화는 한국 표준 결제로 보내지 않고 중단한다', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: { ...PG_PAYMENT, currency: 'USD' },
                paymentMethod: 'card',
            },
        });

        expect(apiPost).not.toHaveBeenCalled();
        expect(paySpy).not.toHaveBeenCalled();
        expect(document.body.querySelector('form')).toBeNull();
        expect((window as any).G7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentErrorMessage: 'KG Inicis supports only KRW standard payments or JPY Japan CBT payments.',
            }),
        );
    });

    it('라이브 표준 결제 설정이 미완료이면 결제창 호출 전에 중단한다', async () => {
        apiGet.mockResolvedValue({
            data: {
                ...CLIENT_CONFIG.data,
                standard_configured: false,
            },
        });

        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'card',
            },
        });

        expect(apiPost).not.toHaveBeenCalled();
        expect(paySpy).not.toHaveBeenCalled();
        expect(document.body.querySelector('form')).toBeNull();
        expect((window as any).G7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentErrorMessage: 'KG Inicis live standard payment is not configured.',
            }),
        );
    });

    it('비활성화된 KG 이니시스 간편결제 수단은 직접 결제 호출을 중단한다', async () => {
        apiGet.mockResolvedValue({
            data: {
                ...CLIENT_CONFIG.data,
                easy_pay_enabled_methods: ['kginicis_kakaopay'],
            },
        });

        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'kginicis_naverpay',
            },
        });

        expect(apiPost).not.toHaveBeenCalled();
        expect(paySpy).not.toHaveBeenCalled();
        expect((window as any).G7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentErrorMessage: 'Selected KG Inicis easy pay method is disabled.',
            }),
        );
    });

    it('이전 KG 결제 폼 잔재를 제거하고 새 폼만 생성한다', async () => {
        const staleForm = document.createElement('form');
        staleForm.id = 'kginicis_pay_form_stale';
        document.body.appendChild(staleForm);

        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'card',
            },
        });

        const kginicisForms = document.body.querySelectorAll('form[id^="kginicis_pay_form_"]');

        expect(kginicisForms).toHaveLength(1);
        expect(document.getElementById('kginicis_pay_form_stale')).toBeNull();
    });

    it('네이버페이는 KG 이니시스 다이렉트 호출 파라미터로 요청한다', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'kginicis_naverpay',
            },
        });

        const fields = getLastPaymentFormFields();

        expect(fields.gopaymethod).toBe('onlynaverpay');
        expect(fields.acceptmethod).toContain('cardonly');
        expect(fields.returnUrl).toBe(
            `${window.location.origin}/plugins/sirsoft-pay_kginicis/payment/callback` +
                '?selectedPaymentMethod=kginicis_naverpay',
        );
    });
});

/**
 * CBT (일본 엔 결제, JPPG) 흐름 회귀 테스트
 *
 * KG 이니시스 CBT 매뉴얼(https://manual.inicis.com/jppay/cbtauth.html)이 요구하는
 * 파라미터 형식을 준수하는지 검증한다.
 *
 * - cbtType: 'JPPG' 고정 (4 bytes)
 * - timestamp: yyyyMMddHHmmss (14 bytes, epoch ms 사용 금지)
 * - buyerTel: 선택이지만 customer_phone 이 있으면 전송
 * - extraData: JSON String (JPPG 결제창 표시 정보 포함)
 * - hashData plainText 순서는 백엔드 책임 (INIAPIKey+mid+timestamp+amount+orderId)
 */
describe('requestPaymentHandler — CBT (JPPG) 분기', () => {
    const CBT_PG_PAYMENT = {
        order_number: 'JP-ORD-001',
        order_name: 'JP Test Order',
        amount: 2,
        currency: 'JPY' as const,
        customer_name: 'Yamada Taro',
        customer_phone: '09012345678',
        customer_email: 'yamada@example.jp',
    };

    const CLIENT_CONFIG = {
        data: {
            mid: 'INIpayTest',
            japan_enabled: true,
            japan_configured: true,
            japan_mid: 'CBTTEST001',
            callback_urls: {
                cbt_checkout_token: '/api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token',
                cbt_hash_data: '/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data',
                cbt_callback: '/api/plugins/sirsoft-pay_kginicis/payment/cbt/callback',
                cbt_cvs_notify: '/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify',
                cbt_auth_url: 'https://devcbt.inicis.com/cbtauth',
            },
            cbt_extra_data: {
                paymentUI: { language: 'JP', colorTheme: 'blue2' },
                payment: {
                    paymethod: ['CARD', 'CVS', 'PAYpay'],
                    card: { payType: ['one'], installMonth: [3] },
                    cvs: {
                        notiUrl: 'https://configured.example.test/cvs-notify',
                        contactInfo: 'サンプル',
                        contactTelNum: '0120-123-456',
                        contactHours: '10:00-18:00',
                        customerKana: 'テスト',
                        customerFirstKana: 'タロウ',
                        paymentTermDay: 5,
                    },
                },
                gmoPayment: {
                    merchantName: 'サンプルストア',
                    merchantNameKana: 'サンプルストア',
                    merchantNameAlphabet: 'Sample Store',
                    merchantNameShort: 'サンプル',
                    contactName: 'サポート窓口',
                    contactEmail: 'support@example.com',
                    contactPhone: '0120-123-456',
                    contactOpeningHours: '10:00-18:00',
                },
            },
        },
    };

    let apiGet: ReturnType<typeof vi.fn>;
    let apiPost: ReturnType<typeof vi.fn>;
    let submitSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        apiGet = vi.fn().mockResolvedValue(CLIENT_CONFIG);
        apiPost = vi.fn().mockImplementation((url: string) => {
            if (url === CLIENT_CONFIG.data.callback_urls.cbt_checkout_token) {
                return Promise.resolve({ data: { checkout_token: 'checkout-token-stub' } });
            }

            return Promise.resolve({ data: { hash_data: 'sha512hashstub' } });
        });
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet, post: apiPost },
            state: { setLocal: vi.fn() },
            toast: { error: vi.fn() },
        };
        // form.submit 이 jsdom 에서 navigation 을 일으키지 않게 mock
        submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        // 테스트 사이에 form 잔재 제거
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    /**
     * 가장 최근에 submit 된 form 의 hidden field 값 맵 추출
     */
    function getLastSubmittedFormFields(): Record<string, string> {
        const forms = document.body.querySelectorAll('form');
        const form = forms[forms.length - 1];
        if (!form) throw new Error('No form was submitted');
        const fields: Record<string, string> = {};
        form.querySelectorAll('input[type="hidden"]').forEach((el) => {
            const input = el as HTMLInputElement;
            fields[input.name] = input.value;
        });
        return fields;
    }

    it('currency=JPY + japan_enabled + japan_mid 면 CBT 분기로 진입해 cbtauth 로 폼 전송', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        expect(apiPost).toHaveBeenCalledWith(
            CLIENT_CONFIG.data.callback_urls.cbt_checkout_token,
            expect.objectContaining({
                oid: CBT_PG_PAYMENT.order_number,
                price: CBT_PG_PAYMENT.amount,
                buyer_email: CBT_PG_PAYMENT.customer_email,
                buyer_phone: CBT_PG_PAYMENT.customer_phone,
            }),
        );
        expect(apiPost).toHaveBeenCalledWith(
            CLIENT_CONFIG.data.callback_urls.cbt_hash_data,
            expect.objectContaining({
                oid: CBT_PG_PAYMENT.order_number,
                price: CBT_PG_PAYMENT.amount,
                buyer_email: CBT_PG_PAYMENT.customer_email,
                buyer_phone: CBT_PG_PAYMENT.customer_phone,
                checkout_token: 'checkout-token-stub',
            }),
        );
        expect(submitSpy).toHaveBeenCalledTimes(1);
        const fields = getLastSubmittedFormFields();
        const form = document.body.querySelector('form')!;
        expect(form.action).toBe(CLIENT_CONFIG.data.callback_urls.cbt_auth_url);
        expect(form.method.toLowerCase()).toBe('post');
        expect(fields.cbtType).toBe('JPPG');
        expect(fields.mid).toBe(CLIENT_CONFIG.data.japan_mid);
        expect(fields.orderId).toBe(CBT_PG_PAYMENT.order_number);
        expect(fields.amount).toBe(String(CBT_PG_PAYMENT.amount));
        expect(fields.goodName).toBe(CBT_PG_PAYMENT.order_name);
        expect(fields.hashData).toBe('sha512hashstub');
        const extraData = JSON.parse(fields.extraData);
        expect(extraData.paymentUI.language).toBe('JP');
        expect(extraData.payment.paymethod).toEqual(['CARD']);
        expect(extraData.payment.isMobile).toBe('false');
        expect(extraData.payment.cvs.notiUrl).toBe(
            `${window.location.origin}${CLIENT_CONFIG.data.callback_urls.cbt_cvs_notify}`,
        );
        expect(extraData.gmoPayment.merchantName).toBe('サンプルストア');
    });

    it('일본 CBT 신용카드 선택 시 결제창 paymethod 를 CARD 로 제한', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: CBT_PG_PAYMENT,
                paymentMethod: 'card',
            },
        });

        const fields = getLastSubmittedFormFields();
        const extraData = JSON.parse(fields.extraData);
        expect(extraData.payment.paymethod).toEqual(['CARD']);
    });

    it('일본 PayPay 결제수단 선택 시 CBT 결제창 paymethod 를 PAYpay 로 제한', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: CBT_PG_PAYMENT,
                paymentMethod: 'kginicis_japan_paypay',
            },
        });

        const fields = getLastSubmittedFormFields();
        const extraData = JSON.parse(fields.extraData);
        expect(extraData.payment.paymethod).toEqual(['PAYpay']);
    });

    it('일본 편의점 결제수단 선택 시 CBT 결제창 paymethod 를 CVS 로 제한', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: CBT_PG_PAYMENT,
                paymentMethod: 'kginicis_japan_cvs',
            },
        });

        const fields = getLastSubmittedFormFields();
        const extraData = JSON.parse(fields.extraData);
        expect(extraData.payment.paymethod).toEqual(['CVS']);
        expect(extraData.payment.cvs.notiUrl).toBe(
            `${window.location.origin}${CLIENT_CONFIG.data.callback_urls.cbt_cvs_notify}`,
        );
    });

    it('일본 전용 결제수단은 JPY 주문에서만 허용', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: { ...CBT_PG_PAYMENT, currency: 'KRW' },
                paymentMethod: 'kginicis_japan_paypay',
            },
        });

        expect(apiPost).not.toHaveBeenCalled();
        expect(submitSpy).not.toHaveBeenCalled();
        expect((window as any).G7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentErrorMessage: 'KG Inicis Japan payment methods require a JPY order.',
            }),
        );
    });

    it('JPY 결제수단 제한 옵션이 꺼져 있으면 한국 KG 간편결제 선택 시 기존 CBT fallback 을 유지', async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: CBT_PG_PAYMENT,
                paymentMethod: 'kginicis_kakaopay',
            },
        });

        expect(apiPost).toHaveBeenCalled();
        expect(submitSpy).toHaveBeenCalledTimes(1);
        const fields = getLastSubmittedFormFields();
        const extraData = JSON.parse(fields.extraData);
        expect(extraData.payment.paymethod).toEqual(['CARD', 'CVS', 'PAYpay']);
    });

    it('JPY 결제수단 제한 옵션이 켜져 있으면 한국 KG 간편결제 선택 시 CBT 전체 paymethod 로 fallback 하지 않고 중단', async () => {
        apiGet.mockResolvedValue({
            data: {
                ...CLIENT_CONFIG.data,
                japan_restrict_jpy_payment_methods: true,
            },
        });

        await requestPaymentHandler({
            params: {
                pgPaymentData: CBT_PG_PAYMENT,
                paymentMethod: 'kginicis_kakaopay',
            },
        });

        expect(apiPost).not.toHaveBeenCalled();
        expect(submitSpy).not.toHaveBeenCalled();
        expect((window as any).G7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentErrorMessage: 'JPY orders can only use KG Inicis Japan CBT payment methods.',
            }),
        );
    });

    it('timestamp 가 yyyyMMddHHmmss 형식 (14자 숫자, epoch ms 아님)', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        const fields = getLastSubmittedFormFields();
        // 정규식: YYYY(2026 이상) MM(01-12) DD(01-31) HH(00-23) mm(00-59) ss(00-59)
        expect(fields.timestamp).toMatch(
            /^(20\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])([01]\d|2[0-3])([0-5]\d)([0-5]\d)$/,
        );
        expect(fields.timestamp).toHaveLength(14);
        // epoch ms (13자) 가 우연히 같은 정규식을 통과하지 않게 길이도 명시
        expect(fields.timestamp).not.toMatch(/^\d{13}$/);
        // hash-data 호출 시에도 같은 timestamp 가 전달되어야 함 (백엔드 hash 일관성)
        const hashCall = apiPost.mock.calls.find(([url]) => url === CLIENT_CONFIG.data.callback_urls.cbt_hash_data);
        const hashCallArgs = hashCall?.[1] as { timestamp: string };
        expect(hashCallArgs.timestamp).toBe(fields.timestamp);
    });

    it('buyerTel 은 customer_phone 값으로 전송 (매뉴얼 선택 파라미터)', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        const fields = getLastSubmittedFormFields();
        expect(fields.buyerName).toBe(CBT_PG_PAYMENT.customer_name);
        expect(fields.buyerTel).toBe(CBT_PG_PAYMENT.customer_phone);
        expect(fields.buyerEmail).toBe(CBT_PG_PAYMENT.customer_email);
    });

    it('customer_phone 누락 시 buyerTel 은 빈 문자열로 안전 처리', async () => {
        const noPhone = { ...CBT_PG_PAYMENT, customer_phone: undefined };

        await requestPaymentHandler({ params: { pgPaymentData: noPhone } });

        const fields = getLastSubmittedFormFields();
        expect(fields.buyerTel).toBe('');
    });

    it('returnUrl 은 현재 origin + cbt_callback + oid 쿼리', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        const fields = getLastSubmittedFormFields();
        expect(fields.returnUrl).toBe(
            `${window.location.origin}${CLIENT_CONFIG.data.callback_urls.cbt_callback}` +
                `?oid=${encodeURIComponent(CBT_PG_PAYMENT.order_number)}` +
                '&selectedPaymentMethod=card',
        );
    });

    it('JPY 주문에서 CBT 설정이 부족하면 한국 결제로 fallback 하지 않고 중단', async () => {
        apiGet.mockResolvedValue({
            data: { ...CLIENT_CONFIG.data, japan_mid: '', japan_configured: false },
        });

        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        expect(apiPost).not.toHaveBeenCalled();
        expect(submitSpy).not.toHaveBeenCalled();
        expect((window as any).G7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentErrorMessage: 'KG Inicis Japan CBT payment is not configured.',
            }),
        );
    });
});

/**
 * 모바일 P_INI_PAYMENT 매핑 회귀 테스트
 *
 * KG 이니시스 모바일 표준결제 매뉴얼(https://manual.inicis.com/pay/stdpay_m.html#popup_7) 의
 * P_INI_PAYMENT 코드:
 *   신용카드   → CARD
 *   계좌이체   → BANK
 *   가상계좌   → VBANK
 *   휴대폰     → MOBILE   ← (PC 의 'HPP' 와 다름)
 *   도서문화   → BCSH
 *   비인증카드 → NOAUTHCARD
 *
 * 회귀: 휴대폰 결제수단의 P_INI_PAYMENT 값이 'HPP' 로 설정되어 PG 가
 * "잘못된 P_INI_PAYMENT 입니다." 응답을 반환하던 문제를 차단한다.
 */
describe('requestPaymentHandler — 모바일 P_INI_PAYMENT 매핑', () => {
    const PG_PAYMENT = {
        order_number: 'ORD-MOBILE-001',
        order_name: 'Mobile Test',
        amount: 1000,
        customer_name: '홍길동',
        customer_phone: '01012345678',
        customer_email: 'test@test.com',
    };

    const CLIENT_CONFIG = {
        data: {
            mid: 'INIpayTest',
            japan_enabled: false,
            japan_mid: '',
            callback_urls: {
                mobile_signature: '/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature',
                mobile_callback: '/plugins/sirsoft-pay_kginicis/payment/mobile/callback',
                mobile_vbank_notify: '/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify',
            },
        },
    };

    let apiGet: ReturnType<typeof vi.fn>;
    let apiPost: ReturnType<typeof vi.fn>;
    let submitSpy: ReturnType<typeof vi.spyOn>;
    let originalUserAgent: PropertyDescriptor | undefined;
    let originalPlatform: PropertyDescriptor | undefined;
    let originalMaxTouchPoints: PropertyDescriptor | undefined;

    function getLastSubmittedFormFields(): Record<string, string> {
        const forms = document.body.querySelectorAll('form');
        const form = forms[forms.length - 1];
        if (!form) throw new Error('No form was submitted');
        const fields: Record<string, string> = {};
        form.querySelectorAll('input[type="hidden"]').forEach((el) => {
            const input = el as HTMLInputElement;
            fields[input.name] = input.value;
        });
        return fields;
    }

    beforeEach(() => {
        apiGet = vi.fn().mockResolvedValue(CLIENT_CONFIG);
        apiPost = vi.fn().mockResolvedValue({
            data: { chkfake: 'chkfakestub', mobile_payment_url: 'https://mobile.inicis.com/smart/payment/' },
        });
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet, post: apiPost },
            state: { setLocal: vi.fn() },
            toast: { error: vi.fn() },
        };
        submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});

        // 모바일 UA 강제 (isMobileUserAgent → true)
        originalUserAgent = Object.getOwnPropertyDescriptor(window.navigator, 'userAgent');
        originalPlatform = Object.getOwnPropertyDescriptor(window.navigator, 'platform');
        originalMaxTouchPoints = Object.getOwnPropertyDescriptor(window.navigator, 'maxTouchPoints');
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
            configurable: true,
        });
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        document.body.innerHTML = '';
        clearMobilePaymentReturnPending();
        if (originalUserAgent) {
            Object.defineProperty(window.navigator, 'userAgent', originalUserAgent);
        } else {
            delete (window.navigator as unknown as Record<string, unknown>).userAgent;
        }
        if (originalPlatform) {
            Object.defineProperty(window.navigator, 'platform', originalPlatform);
        } else {
            delete (window.navigator as unknown as Record<string, unknown>).platform;
        }
        if (originalMaxTouchPoints) {
            Object.defineProperty(window.navigator, 'maxTouchPoints', originalMaxTouchPoints);
        } else {
            delete (window.navigator as unknown as Record<string, unknown>).maxTouchPoints;
        }
        vi.restoreAllMocks();
    });

    it("휴대폰 결제(phone) → P_INI_PAYMENT='MOBILE' (매뉴얼 표준)", async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'phone',
            },
        });

        expect(submitSpy).toHaveBeenCalledTimes(1);
        const fields = getLastSubmittedFormFields();
        expect(fields.P_INI_PAYMENT).toBe('MOBILE');
        // 회귀 차단: PC 의 HPP 값을 모바일에 잘못 매핑하지 않도록
        expect(fields.P_INI_PAYMENT).not.toBe('HPP');
    });

    it('모바일 결제 폼 제출 전 복귀 표시를 남겨 뒤로가기 시 blur 상태를 해제할 수 있게 한다', async () => {
        await requestPaymentHandler({
            params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' },
        });

        const form = document.body.querySelector('form') as HTMLFormElement | null;
        expect(form?.id).toMatch(/^kginicis_pay_form_mobile_/);
        expect(consumeMobilePaymentReturnPending()).toBe(true);
    });

    it('iPadOS 데스크탑 UA도 모바일 결제 폼으로 분기한다', async () => {
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 ' +
                '(KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            configurable: true,
        });
        Object.defineProperty(window.navigator, 'platform', {
            value: 'MacIntel',
            configurable: true,
        });
        Object.defineProperty(window.navigator, 'maxTouchPoints', {
            value: 5,
            configurable: true,
        });

        await requestPaymentHandler({
            params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'card' },
        });

        expect(submitSpy).toHaveBeenCalledTimes(1);
        const form = document.body.querySelector('form') as HTMLFormElement | null;
        const fields = getLastSubmittedFormFields();
        expect(form?.id).toMatch(/^kginicis_pay_form_mobile_/);
        expect(fields.P_INI_PAYMENT).toBe('CARD');
    });

    it.each([
        ['card', 'CARD'],
        ['vbank', 'VBANK'],
        ['bank', 'BANK'],
    ])("결제수단 %s → P_INI_PAYMENT='%s' (매뉴얼 표준 유지)", async (paymentMethod, expected) => {
        await requestPaymentHandler({
            params: { pgPaymentData: PG_PAYMENT, paymentMethod },
        });
        const fields = getLastSubmittedFormFields();
        expect(fields.P_INI_PAYMENT).toBe(expected);
    });

    /**
     * 회귀 — KG 이니시스 모바일 휴대폰결제 매뉴얼은 P_HPP_METHOD 가 필수
     * (manual.inicis.com/pay/stdpay_m.html). 누락 시 PG 가 MX1006 으로 반려.
     * 운영 검증으로 Playwright 재현 후 확인.
     */
    it("휴대폰결제 → P_HPP_METHOD='2' 필수 전송 (MX1006 회귀 차단)", async () => {
        await requestPaymentHandler({
            params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'phone' },
        });
        const fields = getLastSubmittedFormFields();
        expect(fields.P_INI_PAYMENT).toBe('MOBILE');
        // 실물상품 코드 '2' (G7 이커머스는 배송 상품 중심)
        expect(fields.P_HPP_METHOD).toBe('2');
    });

    it.each([
        ['card'],
        ['vbank'],
        ['bank'],
    ])("결제수단 %s → P_HPP_METHOD 미전송 (휴대폰결제 전용)", async (paymentMethod) => {
        await requestPaymentHandler({
            params: { pgPaymentData: PG_PAYMENT, paymentMethod },
        });
        const fields = getLastSubmittedFormFields();
        expect(fields.P_HPP_METHOD).toBeUndefined();
    });

    /**
     * 회귀 — KG 이니시스 모바일 표준결제 매뉴얼은 가상계좌 결제 시 P_NOTI_URL 필수
     * (manual.inicis.com/pay/stdpay_m.html). PC 가상계좌는 가맹점 어드민에 등록된
     * URL 로 통보되지만, 모바일은 요청에 P_NOTI_URL 을 직접 명시해야 KG 이니시스가
     * 입금 통보를 보낼 수 있다.
     */
    it("가상계좌(vbank) 결제 → P_NOTI_URL 필수 전송", async () => {
        await requestPaymentHandler({
            params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'vbank' },
        });
        const fields = getLastSubmittedFormFields();
        expect(fields.P_INI_PAYMENT).toBe('VBANK');
        expect(fields.P_NOTI_URL).toBe(
            `${window.location.origin}/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify`,
        );
    });

    it.each([
        ['card'],
        ['bank'],
        ['phone'],
    ])("결제수단 %s → P_NOTI_URL 미전송 (가상계좌 전용)", async (paymentMethod) => {
        await requestPaymentHandler({
            params: { pgPaymentData: PG_PAYMENT, paymentMethod },
        });
        const fields = getLastSubmittedFormFields();
        expect(fields.P_NOTI_URL).toBeUndefined();
    });

    it('모바일 네이버페이는 wcard 엔드포인트와 선택 결제수단 추적 쿼리를 전송한다', async () => {
        await requestPaymentHandler({
            params: { pgPaymentData: PG_PAYMENT, paymentMethod: 'kginicis_naverpay' },
        });

        const form = document.body.querySelector('form') as HTMLFormElement;
        const fields = getLastSubmittedFormFields();

        expect(form.action).toBe('https://mobile.inicis.com/smart/wcard/');
        expect(fields.P_INI_PAYMENT).toBeUndefined();
        expect(fields.P_RESERVED).toContain('d_npay=Y');
        expect(fields.P_SKIP_TERMS).toBe('Y');
        expect(fields.P_NEXT_URL).toBe(
            `${window.location.origin}/plugins/sirsoft-pay_kginicis/payment/mobile/callback` +
                `?orderId=${encodeURIComponent(PG_PAYMENT.order_number)}` +
                '&selectedPaymentMethod=kginicis_naverpay',
        );
    });
});
