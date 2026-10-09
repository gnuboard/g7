/**
 * 결제창 닫힘 보고·재결제 준비 (NHN KCP)
 *
 * 두 요청은 결제대기 주문을 실패·취소로 바꾸므로 서버가 주문 소유자만 받아 준다(KVE-2026-2434). 요청은
 * `G7Core.api` 로만 보낸다 — 회원 Bearer 와 401 처리는 코어가 맡고, 비회원은 공개 상태의 조회 토큰을 싣는다.
 * 플러그인이 로그인 토큰을 직접 읽거나 fetch 로 따로 보내지 않는다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/payment-close-report-ownership.yaml
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    preparePaymentRetry,
    rememberPendingClose,
    reportPaymentFailureOnReturn,
    reportPaymentWindowClosed,
    toApiPath,
} from '../paymentCloseReport';

/** 서버(client-config)가 내려주는 보고 주소 — 사이트 주소로 만든 절대 URL 이다 */
const SITE_CLOSE_REPORT_URL = 'https://shop.example/plugins/sirsoft-pay_nhnkcp/payment/close-report';

function windowRecord(): Record<string, any> {
    return window as unknown as Record<string, any>;
}

/**
 * 공개 API 만 가진 코어를 꾸민다.
 *
 * @param post G7Core.api.post 대역
 * @param global 전역 상태
 */
function installCore(post: ReturnType<typeof vi.fn>, global: Record<string, unknown> = {}): void {
    windowRecord().G7Core = {
        api: { post },
        state: { get: () => global, subscribe: () => () => undefined },
    };
}

afterEach(() => {
    delete windowRecord().G7Core;
    vi.restoreAllMocks();
    vi.useRealTimers();
    window.sessionStorage.clear();
    window.localStorage.clear();
});

describe('보고 주소 → G7Core.api 경로', () => {
    // @scenario case=site_absolute_url
    // @effects report_reaches_api_route
    it('사이트 절대 URL 은 같은 사이트의 /api 이하 경로로 바꾼다', () => {
        expect(toApiPath(SITE_CLOSE_REPORT_URL)).toBe('/plugins/sirsoft-pay_nhnkcp/payment/close-report');
        expect(toApiPath('/api/plugins/sirsoft-pay_nhnkcp/payment/retry')).toBe('/plugins/sirsoft-pay_nhnkcp/payment/retry');
        expect(toApiPath('/plugins/sirsoft-pay_nhnkcp/payment/retry')).toBe('/plugins/sirsoft-pay_nhnkcp/payment/retry');
    });

    // @scenario case=other_origin_url
    // @effects token_never_leaves_site
    it('다른 출처 주소가 와도 출처를 버리고 같은 사이트 API 로만 보낸다 (조회 토큰이 밖으로 새지 않는다)', async () => {
        const post = vi.fn().mockResolvedValue({ success: true });
        installCore(post, { guestOrderToken: 'guest-token' });
        const fetchSpy = vi.spyOn(globalThis, 'fetch');

        await reportPaymentWindowClosed({ closeReportUrl: 'https://evil.example/plugins/x/payment/close-report', oid: 'O-1', price: 1 });

        expect(post.mock.calls[0][0]).toBe('/plugins/x/payment/close-report');
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

describe('결제창 닫힘 보고·재결제 준비', () => {
    // @scenario case=member_report
    // @effects report_reaches_api_route, member_bearer_left_to_core
    it('회원: 연락처 없이 보고하고 Bearer 는 G7Core.api 에 맡긴다', async () => {
        const post = vi.fn().mockResolvedValue({ success: true });
        installCore(post);

        await reportPaymentWindowClosed({
            closeReportUrl: SITE_CLOSE_REPORT_URL,
            oid: 'ORD-KCP-CLOSE-001',
            price: 10000,
            payment_method: 'card',
        }, 'kcp-window-closed');

        // 주문자 연락처는 소유 증명이 아니므로 보내지 않는다 (KVE-2026-2434).
        expect(post).toHaveBeenCalledWith('/plugins/sirsoft-pay_nhnkcp/payment/close-report', {
            oid: 'ORD-KCP-CLOSE-001',
            price: 10000,
            payment_method: 'card',
            reason: 'kcp-window-closed',
        });
    });

    // @scenario case=guest_report
    // @effects guest_token_header_sent
    it('비회원: 공개 상태의 조회 토큰을 X-Guest-Order-Token 으로 싣는다', async () => {
        const post = vi.fn().mockResolvedValue({ success: true });
        installCore(post, { guestOrderToken: 'guest-token' });

        await reportPaymentWindowClosed({ closeReportUrl: SITE_CLOSE_REPORT_URL, oid: 'ORD-KCP-OWNER-001', price: 10000 });
        await preparePaymentRetry({ closeReportUrl: SITE_CLOSE_REPORT_URL, oid: 'ORD-KCP-OWNER-001', price: 10000 });

        expect(post.mock.calls[0][2]).toEqual({ headers: { 'X-Guest-Order-Token': 'guest-token' } });
        expect(post.mock.calls[1][0]).toBe('/plugins/sirsoft-pay_nhnkcp/payment/retry');
        expect(post.mock.calls[1][2]).toEqual({ headers: { 'X-Guest-Order-Token': 'guest-token' } });
    });

    // @scenario case=server_rejects
    // @effects report_failure_swallowed, retry_failure_blocks_payment
    it('보고 실패는 경고만 남기고, 재결제 준비 실패는 결제창을 열지 않도록 예외를 전파한다', async () => {
        const post = vi.fn().mockRejectedValue(new Error('Order is not retryable for NHN KCP payment.'));
        installCore(post);
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

        await expect(reportPaymentWindowClosed({ closeReportUrl: SITE_CLOSE_REPORT_URL, oid: 'O-1', price: 1 })).resolves.toBeUndefined();
        expect(warn).toHaveBeenCalled();

        await expect(preparePaymentRetry({ closeReportUrl: SITE_CLOSE_REPORT_URL, oid: 'O-1', price: 1 }))
            .rejects.toThrow('Order is not retryable for NHN KCP payment.');
    });

    // @scenario case=no_core_api
    // @effects no_raw_fetch_fallback
    it('G7Core.api 가 없으면 fetch 로 따로 보내지 않는다', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        window.localStorage.setItem('auth_token', 'member-token');

        await reportPaymentWindowClosed({ closeReportUrl: SITE_CLOSE_REPORT_URL, oid: 'O-1', price: 1 });
        await expect(preparePaymentRetry({ closeReportUrl: SITE_CLOSE_REPORT_URL, oid: 'O-1', price: 1 })).rejects.toThrow();

        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

/**
 * 결제 실패 화면 복귀 시 보고
 *
 * 브라우저 리턴 콜백(authCallback)은 PG 서명도 IP 증명도 없어 주문 상태를 바꾸지 않는다.
 * 승인이 거절된 정당한 실패는 이 경로(소유권 대조 close-report)로만 기록된다.
 */
describe('결제 실패 화면 복귀 보고', () => {
    const CONTEXT = {
        closeReportUrl: SITE_CLOSE_REPORT_URL,
        oid: 'ORD-KCP-RETURN-001',
        price: 10000,
    };

    /**
     * 화면 주소를 바꿔 결제 리턴 상황을 재현한다.
     *
     * @param pathname 경로
     * @param search 쿼리 문자열
     */
    function setLocation(pathname: string, search: string): void {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { pathname, search, origin: 'https://shop.example' },
        });
    }

    beforeEach(() => {
        window.sessionStorage.clear();
    });

    // @scenario case=return_report
    // @effects failure_reported_on_return
    it('실패 화면으로 돌아오면 저장해 둔 주문 정보로 한 번만 보고한다', async () => {
        const post = vi.fn().mockResolvedValue({ success: true });
        installCore(post, { guestOrderToken: 'guest-token' });

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=9999&message=%EC%8B%A4%ED%8C%A8&orderId=ORD-KCP-RETURN-001');

        await reportPaymentFailureOnReturn();
        await reportPaymentFailureOnReturn();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith(
            '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            expect.objectContaining({ oid: 'ORD-KCP-RETURN-001', price: 10000, reason: '실패' }),
            { headers: { 'X-Guest-Order-Token': 'guest-token' } },
        );
    });

    // @scenario case=token_wait
    // @effects waits_until_template_restores_token
    it('비회원 토큰이 아직 전역 상태에 없으면 템플릿이 되살릴 때까지 기다렸다가 싣는다', async () => {
        vi.useFakeTimers();
        const post = vi.fn().mockResolvedValue({ success: true });
        const global: Record<string, unknown> = {};
        let notify: (() => void) | null = null;
        windowRecord().G7Core = {
            api: { post },
            state: {
                get: () => global,
                subscribe: (listener: (s: Record<string, unknown>) => void) => {
                    notify = () => listener(global);

                    return () => { notify = null; };
                },
            },
        };

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=9999&orderId=ORD-KCP-RETURN-001');

        const reporting = reportPaymentFailureOnReturn();
        await vi.advanceTimersByTimeAsync(200);
        expect(post).not.toHaveBeenCalled();

        global.guestOrderToken = 'restored-token';
        notify?.();
        await reporting;

        expect(post.mock.calls[0][2]).toEqual({ headers: { 'X-Guest-Order-Token': 'restored-token' } });
    });

    // @scenario case=return_ignored
    // @effects stale_pending_ignored
    it('다른 주문번호로 돌아왔거나 저장분이 없으면 보고하지 않는다', async () => {
        const post = vi.fn();
        installCore(post);

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '?error=9999&orderId=ORD-KCP-OTHER');
        await reportPaymentFailureOnReturn();

        window.sessionStorage.clear();
        setLocation('/shop/checkout', '?error=9999&orderId=ORD-KCP-RETURN-001');
        await reportPaymentFailureOnReturn();

        expect(post).not.toHaveBeenCalled();
    });

    // @scenario case=return_ignored
    // @effects stale_pending_ignored
    it('결제 완료 화면이면 저장분만 지우고, 실패 표시가 없으면 판단하지 않고 남겨 둔다', async () => {
        const post = vi.fn();
        installCore(post);

        rememberPendingClose(CONTEXT);
        setLocation('/shop/orders/ORD-KCP-RETURN-001/complete', '');
        await reportPaymentFailureOnReturn();
        expect(window.sessionStorage.getItem('g7:sirsoft-pay_nhnkcp:pendingClose')).toBeNull();

        rememberPendingClose(CONTEXT);
        setLocation('/shop/checkout', '');
        await reportPaymentFailureOnReturn();
        expect(window.sessionStorage.getItem('g7:sirsoft-pay_nhnkcp:pendingClose')).not.toBeNull();

        expect(post).not.toHaveBeenCalled();
    });
});

/**
 * 서버가 주는 닫힘 보고 주소는 사이트 주소 기준 절대 URL 이다(`SiteUrl::absolute`).
 *
 * G7Core.api 는 `/api` 를 앞에 붙이는 경로를 받으므로 절대 URL 을 그대로 넘기면 `/plugins/...`(접두 없음)로
 * 나가 404 가 났다 — 결제창 닫힘 보고와 재시도 준비가 모두 실패했다.
 */
describe('사이트 주소 절대 URL 닫힘 보고·재시도', () => {
    // 앞 describe 가 window.location 을 바꿔 두므로 실행 시점의 출처로 만든다.
    const abs = (): string => `${window.location.origin}/plugins/sirsoft-pay_nhnkcp/payment/close-report`;

    afterEach(() => {
        delete windowRecord().G7Core;
        vi.restoreAllMocks();
    });

    it('같은 출처 절대 URL 은 G7Core.api 에 /plugins 경로로 넘긴다 (닫힘 보고)', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };

        await reportPaymentWindowClosed({ closeReportUrl: abs(), oid: 'ORD-KCP-ABS-001', price: 15000, payment_method: 'card' });

        expect(apiPost).toHaveBeenCalledWith('/plugins/sirsoft-pay_nhnkcp/payment/close-report', expect.objectContaining({ oid: 'ORD-KCP-ABS-001' }));
    });

    it('같은 출처 절대 URL 이어도 재시도 준비는 /plugins/.../payment/retry 로 넘긴다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };

        await preparePaymentRetry({ closeReportUrl: abs(), oid: 'ORD-KCP-ABS-002', price: 15000 });

        expect(apiPost).toHaveBeenCalledWith('/plugins/sirsoft-pay_nhnkcp/payment/retry', expect.objectContaining({ oid: 'ORD-KCP-ABS-002' }));
    });
});
