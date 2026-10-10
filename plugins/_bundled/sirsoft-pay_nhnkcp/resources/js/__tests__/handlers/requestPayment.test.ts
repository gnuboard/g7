/**
 * requestPayment 핸들러 테스트
 *
 * NHN KCP 결제창 호출 핸들러의 입력 검증 및 에러 경로 동작을 검증합니다.
 * iframe SDK 로드 / KCP_Pay_Execute 호출 등 외부 부수효과 의존 흐름은
 * tests/scenarios 매니페스트(통합 시나리오)에서 다루며, 본 단위 테스트는
 * "초기 가드 + catch 블록 정상 호출" 두 축에 집중합니다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/request-payment-handler.yaml
 */
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { assertTrustedSdkUrl, KNOWN_SDK_HOSTS,
    buildKcpEasyPayReturnFields,
    buildKcpTaxFields,
    buildKcpVbankExpireFields,
    finishCancelledPayment,
    isSupportedKcpCurrency,
    requestPaymentHandler,
    resolveKcpPcPayMethod,
    watchKcpPaymentFrameClosure,
} from '../../handlers/requestPayment';

const PG_PAYMENT = {
    order_number: 'ORD-001',
    order_name: 'Test Order',
    amount: 10000,
    payment_method: 'card',
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
        (window as unknown as Record<string, unknown>).G7Core = {
            api: { get: apiGet },
            state: { setLocal: setLocalSpy },
            modal: { open: modalOpenSpy },
            toast: { error: vi.fn() },
        };
    });

    afterEach(() => {
        delete (window as unknown as Record<string, unknown>).G7Core;
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    // @scenario area=entry_guard
    // @effects early_return_without_payment_data
    it('pgPaymentData가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        await requestPaymentHandler({ params: {} });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('pgPaymentData is required')
        );
        expect(apiGet).not.toHaveBeenCalled();
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    // @scenario area=entry_guard
    // @effects apple_pay_blocked_off_ios
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

    // @scenario area=currency
    // @effects non_krw_blocked
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

    // @scenario area=client_config
    // @effects client_config_failure_handled
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

    // @scenario area=client_config
    // @effects client_config_failure_handled
    it('client config API 자체가 throw 하면 catch 블록에서 setLocal 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('Network error'));

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        // catch 블록은 액션 인자의 결제수단(pgPaymentData.payment_method)으로 setLocal 복구
        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentMethod: 'card',
            })
        );
    });

    // @scenario area=payment_method_source
    // @effects payment_method_from_action_args
    it('서버가 실은 payment_method 가 vbank 면 catch 시 그 값으로 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('boom'));

        await requestPaymentHandler({
            params: {
                pgPaymentData: { ...PG_PAYMENT, payment_method: 'vbank' },
            },
        });

        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentMethod: 'vbank',
            })
        );
    });

    // @scenario area=payment_method_source
    // @effects payment_method_from_action_args
    it('결제수단은 액션 인자로만 정한다 — 호출부 paymentMethod 가 우선이고, 옛 pay_method 키나 화면 상태는 보지 않는다', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('boom'));
        (window as any).G7Core.state.getLocal = vi.fn(() => ({ paymentMethod: 'nhnkcp_kakaopay' }));

        await requestPaymentHandler({
            params: { pgPaymentData: { ...PG_PAYMENT, payment_method: 'vbank' }, paymentMethod: 'bank' },
        });
        expect(setLocalSpy).toHaveBeenLastCalledWith(expect.objectContaining({ paymentMethod: 'bank' }));

        expect((window as any).G7Core.state.getLocal).not.toHaveBeenCalled();
    });

    // @scenario area=payment_method_source
    // @effects payment_method_from_action_args
    it('결제수단이 액션 인자에도 서버 값에도 없으면 신용카드로 대신 열지 않고 결제 오류로 끝낸다', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const { payment_method: _omit, ...withoutMethod } = PG_PAYMENT;

        await requestPaymentHandler({
            params: { pgPaymentData: { ...withoutMethod, pay_method: 'vbank' } },
        });

        expect(apiGet, '결제창 설정을 부르지 않는다').not.toHaveBeenCalled();
        expect(setLocalSpy).toHaveBeenCalledWith(expect.objectContaining({ isSubmittingOrder: false }));
        expect(modalOpenSpy).toHaveBeenCalledWith('nhnkcp_payment_error_modal');
    });

    // @scenario area=frame_close
    // @effects frame_close_detected_once
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

    // @scenario area=frame_close
    // @effects frame_close_detected_once
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

    // @scenario area=tax_split
    // @effects tax_fields_rebalanced
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

    // @scenario area=currency
    // @effects non_krw_blocked
    it('KCP 통화 가드는 빈 값과 KRW만 허용', () => {
        expect(isSupportedKcpCurrency(undefined)).toBe(true);
        expect(isSupportedKcpCurrency('KRW')).toBe(true);
        expect(isSupportedKcpCurrency('usd')).toBe(false);
    });

    // @scenario area=easy_pay_fields
    // @effects easy_pay_method_returned
    it('간편결제 원 결제수단을 KCP callback 반환 필드에 싣는다', () => {
        expect(buildKcpEasyPayReturnFields('nhnkcp_naverpay', true)).toEqual({
            param_opt_1: 'nhnkcp_naverpay',
            nhnkcp_easy_pay_method: 'nhnkcp_naverpay',
        });
        expect(buildKcpEasyPayReturnFields('card', false)).toEqual({});
        expect(buildKcpEasyPayReturnFields('nhnkcp_unknown', true)).toEqual({});
    });

    // 서버는 pay_method 를 보내지 않고 payment_method 만 싣는다 — 그 키로 PC 결제창 수단을 정해야
    // 가상계좌·계좌이체 주문이 카드 결제창으로 열리지 않는다(2026-10-01 테스트 상점 실측: 가상계좌 주문에 카드 창).
    // @scenario area=pc_pay_method
    // @effects pc_window_method_from_server
    it('PC 결제창 수단은 서버가 저장한 payment_method 로 정한다', () => {
        expect(resolveKcpPcPayMethod('vbank', false)).toBe('001000000000');
        expect(resolveKcpPcPayMethod('bank', false)).toBe('010000000000');
        expect(resolveKcpPcPayMethod('phone', false)).toBe('000010000000');
        expect(resolveKcpPcPayMethod('card', false)).toBe('100000000000');
        // 간편결제는 카드 비트마스크 + direct 파라미터
        expect(resolveKcpPcPayMethod('nhnkcp_kakaopay', true)).toBe('100000000000');
        // 모르는 수단은 카드로 떨어진다(종전과 같은 폴백)
        expect(resolveKcpPcPayMethod('unknown', false)).toBe('100000000000');
    });

    /**
     * 가상계좌 계좌 만료 = 주문 입금 기한(쇼핑몰 자동취소 기한). KCP 는 「발급일로부터 N일 · 그날 HHMMSS 까지」로 받는다.
     * 일수는 한국 날짜 기준이다 — 브라우저 시간대가 달라도, 한국 자정을 넘긴 뒤여도 같은 기한을 가리켜야 한다.
     *
     * @scenario surface=pc, due=present
     * @effects vcnt_expire_term_carries_order_vbank_due
     */
    it('가상계좌 만료 기간은 주문 입금 기한까지의 한국 날짜 일수와 시각이다', () => {
        const due = '2026-10-10T13:39:23+09:00';
        // 2026-10-07 13:39 KST 발급 → 3일 뒤 13:39:23
        expect(buildKcpVbankExpireFields(due, 7, Date.UTC(2026, 9, 7, 4, 39, 23))).toEqual({
            vcnt_expire_term: '3',
            vcnt_expire_term_time: '133923',
        });
        // UTC 로는 10-07 이지만 한국은 10-08 01:00 — 2일
        expect(buildKcpVbankExpireFields(due, 7, Date.UTC(2026, 9, 7, 16, 0, 0))).toEqual({
            vcnt_expire_term: '2',
            vcnt_expire_term_time: '133923',
        });
    });

    /**
     * @scenario surface=pc, due=absent
     * @effects missing_due_falls_back_to_the_plugin_days
     */
    it('주문 입금 기한이 없거나 오늘 이전이면 종전 예비값(플러그인 일수)으로 연다', () => {
        expect(buildKcpVbankExpireFields(undefined, 7)).toEqual({ vcnt_expire_term: '7' });
        expect(buildKcpVbankExpireFields(undefined, undefined)).toEqual({ vcnt_expire_term: '3' });
        expect(buildKcpVbankExpireFields('2026-10-07T23:00:00+09:00', 7, Date.UTC(2026, 9, 7, 4, 0, 0))).toEqual({ vcnt_expire_term: '7' });
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
 * 클레임 추가결제 (이커머스 EC-455 계약 · KC-16)
 *
 * 클레임 결제의 `pg_payment_data.order_number` 는 주문번호가 아니라 결제번호(CP…)다. 주문 전용
 * 재시도 준비(`/payment/retry`)·닫힘 보고(`/payment/close-report`)에 그 값을 보내면 서버가 주문을
 * 찾지 못해 404 를 주고, 결제창이 열리지 않은 채 신청서 화면이 「결제 진행 중…」에 묶인다
 * (2026-10-06 S2 실측 CP20261006-2209526581). 결제창을 닫았을 때는 서버가 준
 * `claim_cancel_return_url`(신청서 상세 `?claim_payment=cancelled`)로 돌아간다.
 */
describe('클레임 추가결제', () => {
    const CLAIM_RETURN = 'https://shop.example.test/mypage/claims/EX20261006-0001?claim_payment=cancelled';
    const CLAIM_PAYMENT = { ...PG_PAYMENT, order_number: 'CP20261006-0001', claim_cancel_return_url: CLAIM_RETURN };
    let apiPost: ReturnType<typeof vi.fn>;
    let originalLocation: Location;
    let hrefSet: string[];

    beforeEach(() => {
        apiPost = vi.fn().mockResolvedValue({ success: true });
        originalLocation = window.location;
        hrefSet = [];
        Object.defineProperty(window, 'location', {
            value: {
                origin: 'https://shop.example.test',
                pathname: '/mypage/claims/EX20261006-0001',
                search: '',
                get href() { return 'https://shop.example.test/mypage/claims/EX20261006-0001'; },
                set href(v: string) { hrefSet.push(v); },
            },
            configurable: true,
            writable: true,
        });
        (window as unknown as Record<string, unknown>).G7Core = {
            api: {
                get: vi.fn().mockResolvedValue({ data: {
                    client_id: 'T0000',
                    // 신뢰하지 않는 SDK 주소 — 재시도 준비 뒤 결제창 단계에서 흐름을 끊는다.
                    sdk_url: 'https://evil.example/kcp.js',
                    callback_urls: {
                        callback: 'https://shop.example.test/plugins/sirsoft-pay_nhnkcp/payment/callback',
                        close_report: 'https://shop.example.test/api/plugins/sirsoft-pay_nhnkcp/payment/close-report',
                    },
                } }),
                post: apiPost,
            },
            state: { setLocal: vi.fn() },
            modal: { open: vi.fn() },
            toast: { error: vi.fn() },
        };
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        Object.defineProperty(window, 'location', { value: originalLocation, configurable: true, writable: true });
        delete (window as unknown as Record<string, unknown>).G7Core;
        vi.restoreAllMocks();
    });

    it('클레임 결제는 주문 전용 재시도 준비(/payment/retry)를 부르지 않는다', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: CLAIM_PAYMENT } });

        const urls = apiPost.mock.calls.map((c) => String(c[0]));
        expect(urls.some((u) => u.includes('/payment/retry'))).toBe(false);
    });

    it('주문 결제는 종전대로 재시도 준비를 부른다', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        const urls = apiPost.mock.calls.map((c) => String(c[0]));
        expect(urls.some((u) => u.includes('/payment/retry'))).toBe(true);
    });

    it('클레임 결제창 취소는 닫힘 보고 없이 claim_cancel_return_url 로 이동한다', async () => {
        await finishCancelledPayment(CLAIM_PAYMENT, {
            closeReportUrl: 'https://shop.example.test/api/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            oid: CLAIM_PAYMENT.order_number,
            price: CLAIM_PAYMENT.amount,
        }, 'card', 'kcp-payment-frame-closed');

        expect(hrefSet).toEqual([CLAIM_RETURN]);
        expect(apiPost).not.toHaveBeenCalled();
    });

    it('주문 결제창 취소는 닫힘을 보고하고 이동하지 않는다', async () => {
        await finishCancelledPayment(PG_PAYMENT, {
            closeReportUrl: 'https://shop.example.test/api/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            oid: PG_PAYMENT.order_number,
            price: PG_PAYMENT.amount,
        }, 'card', 'kcp-payment-frame-closed');

        expect(hrefSet).toEqual([]);
        expect(apiPost.mock.calls.map((c) => String(c[0])).some((u) => u.includes('/payment/close-report'))).toBe(true);
    });
});

describe('SDK URL 신뢰 호스트 게이트', () => {
    const TRUSTED_SDK_URL = 'https://pay.kcp.co.kr/plugin/payplus_web.jsp';

    // @scenario area=sdk_host
    // @effects sdk_host_allowlisted
    it('신뢰 호스트 https URL 은 통과한다', () => {
        expect(() => assertTrustedSdkUrl(TRUSTED_SDK_URL)).not.toThrow();
    });

    // @scenario area=sdk_host
    // @effects sdk_host_allowlisted
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

    // @scenario area=sdk_host
    // @effects sdk_host_allowlisted
    it('manifest trusted_script_hosts 와 KNOWN_SDK_HOSTS 가 일치한다', () => {
        const manifest = JSON.parse(
            readFileSync(resolve(__dirname, '../../../../plugin.json'), 'utf-8')
        );

        expect([...(manifest.trusted_script_hosts ?? [])].sort()).toEqual(
            [...KNOWN_SDK_HOSTS].sort()
        );
    });

    // @scenario area=sdk_host
    // @effects sdk_host_allowlisted
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
