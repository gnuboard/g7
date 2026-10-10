/**
 * 토스페이먼츠 결제 실패 보고 (close-report)
 *
 * 결제창에서 실패·닫힘으로 돌아오면 저장해 둔 주문 정보로 서버에 보고한다. 서버는 주문 소유자만 받아
 * 준다 — 회원은 `G7Core.api` 가 싣는 Bearer, 비회원은 쇼핑몰 계약 `_global.guestOrderToken` 을 실은
 * `X-Guest-Order-Token` 이다. 로그인 토큰·템플릿 저장소를 직접 읽지 않고, 코어 클라이언트를 우회하는
 * raw fetch 도 쓰지 않는다(#639).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    forgetPendingClose,
    rememberPendingClose,
    reportPaymentFailureOnReturn,
} from '../paymentCloseReport';

const REPORT_URL = '/api/plugins/sirsoft-tosspayments/payment/close-report';

function windowRecord(): Record<string, any> {
    return window as unknown as Record<string, any>;
}

/**
 * 화면 주소를 바꿔 결제 리턴 상황을 재현한다.
 */
function setLocation(pathname: string, search: string): void {
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { pathname, search, origin: 'https://shop.example' },
    });
}

/**
 * G7Core 모의 객체를 설치한다.
 *
 * @param globalState 전역 상태 (`_global`) — 기본은 템플릿 초기화가 끝나 토큰 키가 null 로 선 상태
 * @returns api.post 모의 함수
 */
function installG7Core(globalState: Record<string, unknown> = { guestOrderToken: null }) {
    const apiPost = vi.fn().mockResolvedValue({ success: true });
    windowRecord().G7Core = {
        api: { post: apiPost },
        state: { get: vi.fn(() => globalState) },
    };

    return apiPost;
}

const CONTEXT = {
    orderId: 'ORD-TOSS-1001',
    amount: 10000,
};

describe('토스페이먼츠 결제 실패 보고', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        vi.restoreAllMocks();
        vi.useRealTimers();
        window.sessionStorage.clear();
    });

    /**
     * @scenario case=failed_no_token
     * @effects failure_reported_once_with_saved_order
     */
    it('실패 화면으로 돌아오면 저장해 둔 주문 정보로 보고한다', async () => {
        const apiPost = installG7Core();

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&message=cancelled&orderId=ORD-TOSS-1001');

        await reportPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledWith(
            REPORT_URL,
            expect.objectContaining({
                orderId: 'ORD-TOSS-1001',
                amount: 10000,
                code: 'PAY_PROCESS_CANCELED',
            }),
        );
        // 주문자 연락처는 소유 증명이 아니므로 보내지 않는다 (KVE-2026-2434).
        expect(apiPost.mock.calls[0][1]).not.toHaveProperty('buyer_email');
        expect(apiPost.mock.calls[0][1]).not.toHaveProperty('buyer_phone');
    });

    /**
     * @scenario case=failed_no_token
     * @effects failure_reported_once_with_saved_order
     */
    it('보고 후에는 저장분을 지워 중복 보고하지 않는다', async () => {
        const apiPost = installG7Core();

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-1001');

        await reportPaymentFailureOnReturn();
        await reportPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledTimes(1);
    });

    /**
     * @scenario case=none_saved
     * @effects unrelated_return_not_reported
     */
    it('저장해 둔 정보가 없으면 아무것도 보내지 않는다', async () => {
        const apiPost = installG7Core();

        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-1001');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=other_order
     * @effects unrelated_return_not_reported
     */
    it('다른 주문번호로 돌아왔으면 보고하지 않는다', async () => {
        const apiPost = installG7Core();

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-OTHER');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=completed
     * @effects unrelated_return_not_reported
     */
    it('결제 완료 화면으로 돌아오면 보고하지 않고 저장분만 지운다', async () => {
        const apiPost = installG7Core();

        rememberPendingClose(CONTEXT);
        setLocation('/shop/orders/ORD-TOSS-1001/complete', '');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
        expect(window.sessionStorage.getItem('g7:sirsoft-tosspayments:pendingClose')).toBeNull();
    });

    /**
     * @scenario case=unmarked
     * @effects unrelated_return_not_reported
     */
    it('실패 표시가 전혀 없으면 판단하지 않고 저장분을 남겨 둔다', async () => {
        const apiPost = installG7Core();

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '');

        await reportPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
        expect(window.sessionStorage.getItem('g7:sirsoft-tosspayments:pendingClose')).not.toBeNull();
    });

    /**
     * @scenario case=failed_no_api
     * @effects no_raw_fetch_fallback
     */
    it('G7Core.api 가 없으면 fetch 로 직접 보내지 않는다', async () => {
        const fetchSpy = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        windowRecord().fetch = fetchSpy;
        windowRecord().G7Core = { state: { get: () => ({ guestOrderToken: 'tok' }) } };
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=REJECT_CARD_COMPANY&orderId=ORD-TOSS-1001');

        await expect(reportPaymentFailureOnReturn()).resolves.toBeUndefined();

        expect(fetchSpy).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=failed_no_token
     * @effects failure_reported_once_with_saved_order
     */
    it('보고가 실패해도 예외를 던지지 않는다', async () => {
        const apiPost = installG7Core();
        apiPost.mockRejectedValue(new Error('network down'));
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-1001');

        await expect(reportPaymentFailureOnReturn()).resolves.toBeUndefined();
    });

    /**
     * @scenario case=failed_no_token
     * @effects failure_reported_once_with_saved_order
     */
    it('forgetPendingClose 는 저장분을 지운다', () => {
        rememberPendingClose(CONTEXT);
        expect(window.sessionStorage.getItem('g7:sirsoft-tosspayments:pendingClose')).not.toBeNull();

        forgetPendingClose();

        expect(window.sessionStorage.getItem('g7:sirsoft-tosspayments:pendingClose')).toBeNull();
    });
});

/**
 * 소유자 증명 헤더 (KVE-2026-2434)
 *
 * close-report 는 주문을 실패·취소로 바꾸므로 서버가 주문 소유자만 받아 준다. 회원은 G7Core.api 가
 * Bearer 를 싣고, 비회원은 쇼핑몰 계약 `_global.guestOrderToken` 을 실어야 한다.
 */
describe('소유자 증명 헤더', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
        window.localStorage.clear();
        setLocation('/shop/checkout', '?error=PAY_PROCESS_CANCELED&orderId=ORD-TOSS-1001');
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        vi.restoreAllMocks();
        vi.useRealTimers();
        window.sessionStorage.clear();
        window.localStorage.clear();
    });

    /**
     * @scenario case=failed_guest_token
     * @effects guest_token_read_from_global_contract, no_private_storage_read
     */
    it('_global.guestOrderToken 을 X-Guest-Order-Token 으로 싣고 브라우저 저장소는 읽지 않는다', async () => {
        const apiPost = installG7Core({ guestOrderToken: '1999999999|guest-token' });
        // 템플릿 저장소·로그인 토큰에 다른 값을 넣어 두어, 그 값을 읽으면 드러나게 한다
        window.localStorage.setItem('auth_token', 'member-token');
        rememberPendingClose(CONTEXT);
        window.sessionStorage.setItem('g7_guest_order_token', 'stale-session-token');
        const getItem = vi.spyOn(Storage.prototype, 'getItem');

        await reportPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledWith(
            REPORT_URL,
            expect.objectContaining({ orderId: 'ORD-TOSS-1001' }),
            { headers: { 'X-Guest-Order-Token': '1999999999|guest-token' } },
        );
        const readKeys = getItem.mock.calls.map(([key]) => key);
        expect(readKeys).not.toContain('auth_token');
        expect(readKeys).not.toContain('g7_guest_order_token');
    });

    /**
     * @scenario case=failed_no_token
     * @effects guest_token_read_from_global_contract
     */
    it('계약 값이 비어 있으면 헤더 없이 보내고(회원 Bearer 는 G7Core.api 몫) 거부돼도 예외를 흘리지 않는다', async () => {
        const apiPost = installG7Core({ guestOrderToken: null });
        apiPost.mockRejectedValue(new Error('403'));
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        rememberPendingClose(CONTEXT);

        await expect(reportPaymentFailureOnReturn()).resolves.toBeUndefined();

        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });

    /**
     * @scenario case=failed_contract_late
     * @effects report_waits_for_contract_before_sending, guest_token_read_from_global_contract
     */
    it('플러그인이 화면 초기화보다 먼저 돌면 템플릿이 토큰을 올릴 때까지 기다렸다가 싣는다', async () => {
        vi.useFakeTimers();
        const globalState: Record<string, unknown> = {};
        const apiPost = installG7Core(globalState);
        rememberPendingClose(CONTEXT);

        const pending = reportPaymentFailureOnReturn();
        await vi.advanceTimersByTimeAsync(300);
        expect(apiPost).not.toHaveBeenCalled();

        // 템플릿 init_actions 가 계약 값을 올린다
        globalState.guestOrderToken = '1999999999|late-token';
        await vi.advanceTimersByTimeAsync(100);
        await pending;

        expect(apiPost).toHaveBeenCalledWith(
            REPORT_URL,
            expect.any(Object),
            { headers: { 'X-Guest-Order-Token': '1999999999|late-token' } },
        );
    });

    /**
     * @scenario case=failed_contract_absent
     * @effects report_waits_for_contract_before_sending
     */
    it('계약 키를 세우지 않는 템플릿이면 상한(5초) 뒤 헤더 없이 보낸다', async () => {
        vi.useFakeTimers();
        const apiPost = installG7Core({});
        rememberPendingClose(CONTEXT);

        const pending = reportPaymentFailureOnReturn();
        await vi.advanceTimersByTimeAsync(4900);
        expect(apiPost).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(100);
        await pending;

        expect(apiPost).toHaveBeenCalledTimes(1);
        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });
});
