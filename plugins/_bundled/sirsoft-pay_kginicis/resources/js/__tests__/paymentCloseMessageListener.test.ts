import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clearMobilePaymentReturnPending,
    consumeStandardPaySdkReloadFlag,
    hasMobilePaymentReturnPending,
    markMobilePaymentReturnPending,
} from '../paymentDomCleanup';
import {
    clearStandardPaymentCloseReportContext,
    installPaymentCloseMessageListener,
    markStandardPaymentCloseReportContext,
    reportStandardPaymentFailureOnReturn,
    resetCheckoutSubmittingState,
} from '../paymentCloseMessageListener';

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

/**
 * 전역 상태(`G7Core.state.get()`)를 흉내 낸다 — 쇼핑몰 경로 앞머리(`_global.shopBase`)는 템플릿 상태 계약이다.
 *
 * @param extra 함께 둘 전역 상태 값
 * @returns state.get 대역
 */
function globalState(extra: Record<string, unknown> = {}): () => Record<string, unknown> {
    return () => ({ shopBase: '/shop', ...extra });
}

describe('paymentCloseMessageListener', () => {
    const setLocal = vi.fn();

    beforeEach(() => {
        window.history.pushState({}, '', '/shop/checkout');
        windowRecord().G7Core = {
            state: {
                get: globalState(),
                setLocal,
            },
        };
        setLocal.mockClear();
        clearStandardPaymentCloseReportContext();
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        delete windowRecord().__sirsoftKginicisPaymentCloseListenerInstalled;
        clearStandardPaymentCloseReportContext();
        clearMobilePaymentReturnPending();
        document.body.innerHTML = '';
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    // @scenario case=close_message
    // @effects checkout_submit_state_reset_on_close
    it('KG closeUrl 메시지를 받으면 체크아웃 제출 상태를 해제한다', () => {
        const staleForm = document.createElement('form');
        staleForm.id = 'kginicis_pay_form_stale';
        document.body.appendChild(staleForm);

        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: {
                source: 'sirsoft-pay_kginicis',
                type: 'payment-window-closed',
                reason: 'inicis-close-url',
            },
        }));

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
        expect(document.getElementById('kginicis_pay_form_stale')).toBeNull();
        expect(consumeStandardPaySdkReloadFlag()).toBe(true);
    });

    // @scenario case=close_message
    // @effects close_reported_to_server
    it('KG closeUrl 메시지를 받으면 활성 주문의 결제창 닫힘을 서버에 보고한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
            state: { get: globalState(), setLocal },
        };
        markStandardPaymentCloseReportContext({
            closeReportUrl: '/api/plugins/sirsoft-pay_kginicis/payment/close-report',
            oid: 'ORD-CLOSE-001',
            price: 10000,
            payment_method: 'card',
        });
        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: {
                source: 'sirsoft-pay_kginicis',
                type: 'payment-window-closed',
                reason: 'inicis-close-url',
            },
        }));

        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(apiPost).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-pay_kginicis/payment/close-report',
            // 주문자 연락처는 소유 증명이 아니므로 보내지 않는다 (KVE-2026-2434).
            {
                oid: 'ORD-CLOSE-001',
                price: 10000,
                payment_method: 'card',
                reason: 'inicis-close-url',
            },
        );
        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    // @scenario case=iframe_only
    // @effects no_report_without_close_message
    it('closeUrl 메시지 없이 결제창 iframe 만 사라져도 close-report 를 호출하지 않는다', async () => {
        // 회귀 가드: iframe 존재 폴링 휴리스틱(B)을 다시 도입하지 않는다.
        // 성공(returnUrl 최상위 전송)·단순 이탈로 iframe 이 사라지는 경우 close-report 가
        // 발화하면 안 된다. 닫힘은 오직 closeUrl 의 postMessage(A)로만 보고된다.
        vi.useFakeTimers();
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
            state: { get: globalState(), setLocal },
        };
        markStandardPaymentCloseReportContext({
            closeReportUrl: '/api/plugins/sirsoft-pay_kginicis/payment/close-report',
            oid: 'ORD-NO-POLL-001',
            price: 10000,
        });
        installPaymentCloseMessageListener();

        const iframe = document.createElement('iframe');
        iframe.className = 'inipay_iframe';
        iframe.src = 'https://stgstdpay.inicis.com/payMain/pay';
        iframe.style.width = '320px';
        iframe.style.height = '240px';
        document.body.appendChild(iframe);

        await vi.advanceTimersByTimeAsync(3600);

        iframe.remove();
        await vi.advanceTimersByTimeAsync(3600);

        expect(apiPost).not.toHaveBeenCalled();
    });

    // @scenario case=foreign_origin
    // @effects foreign_messages_ignored
    it('다른 origin 메시지는 무시한다', () => {
        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: 'https://example.com',
            data: {
                source: 'sirsoft-pay_kginicis',
                type: 'payment-window-closed',
            },
        }));

        expect(setLocal).not.toHaveBeenCalled();
    });

    // @scenario case=non_checkout
    // @effects checkout_detected_by_shop_base_contract
    it('체크아웃 페이지가 아니면 상태를 변경하지 않는다', () => {
        window.history.pushState({}, '', '/shop/cart');

        resetCheckoutSubmittingState();

        expect(setLocal).not.toHaveBeenCalled();
    });

    // @scenario case=mobile_return
    // @effects mobile_return_resets_submit_state
    it('모바일 결제 페이지에서 브라우저 뒤로 돌아오면 체크아웃 제출 상태를 해제한다', () => {
        markMobilePaymentReturnPending();
        installPaymentCloseMessageListener();

        window.dispatchEvent(new Event('pageshow'));

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    // @scenario case=mobile_return
    // @effects mobile_return_resets_submit_state
    it('모바일 결제 복귀 표시가 이미 있으면 listener 설치 시점에도 제출 상태를 해제한다', () => {
        markMobilePaymentReturnPending();

        installPaymentCloseMessageListener();

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    // @scenario case=mobile_return
    // @effects mobile_return_resets_submit_state
    it('모바일 결제 복귀 표시가 없으면 pageshow 에서 상태를 건드리지 않는다', () => {
        installPaymentCloseMessageListener();

        window.dispatchEvent(new Event('pageshow'));

        expect(setLocal).not.toHaveBeenCalled();
    });

    // @scenario case=mobile_return
    // @effects mobile_return_resets_submit_state
    it('모바일 결제 복귀를 여러 번 반복해도 매번 표시를 소비하고 상태를 해제한다', () => {
        installPaymentCloseMessageListener();

        markMobilePaymentReturnPending();
        window.dispatchEvent(new Event('pageshow'));
        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });

        setLocal.mockClear();

        markMobilePaymentReturnPending();
        window.dispatchEvent(new Event('pageshow'));
        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    // @scenario case=mobile_return
    // @effects mobile_return_resets_submit_state
    it('복귀 시점에 G7Core가 준비되지 않았으면 표시를 유지했다가 다음 이벤트에서 해제한다', () => {
        delete windowRecord().G7Core;
        markMobilePaymentReturnPending();
        installPaymentCloseMessageListener();

        window.dispatchEvent(new Event('pageshow'));
        expect(hasMobilePaymentReturnPending()).toBe(true);
        expect(setLocal).not.toHaveBeenCalled();

        windowRecord().G7Core = {
            state: {
                get: globalState(),
                setLocal,
            },
        };
        window.dispatchEvent(new Event('focus'));

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
        expect(hasMobilePaymentReturnPending()).toBe(false);
    });
});

/**
 * 체크아웃 화면 판정 — 쇼핑몰 경로는 운영자가 바꿀 수 있어 템플릿 상태 계약(`_global.shopBase`)으로 판정한다.
 * 예전에는 `/shop/checkout` 글자를 맞춰 쇼핑몰 경로를 바꾼 설치본에서 결제창을 닫아도 버튼이 잠긴 채 남았다.
 */
describe('체크아웃 화면 판정 (_global.shopBase)', () => {
    const setLocal = vi.fn();

    afterEach(() => {
        delete windowRecord().G7Core;
        setLocal.mockClear();
        vi.restoreAllMocks();
    });

    // @scenario case=shop_base_custom
    // @effects checkout_detected_by_shop_base_contract
    it('쇼핑몰 경로가 /store 면 /store/checkout 에서 제출 상태를 해제하고 /shop/checkout 은 체크아웃으로 보지 않는다', () => {
        vi.spyOn(console, 'info').mockImplementation(() => {});
        windowRecord().G7Core = { state: { get: globalState({ shopBase: '/store' }), setLocal } };

        window.history.pushState({}, '', '/store/checkout');
        expect(resetCheckoutSubmittingState()).toBe(true);

        setLocal.mockClear();
        window.history.pushState({}, '', '/shop/checkout');
        expect(resetCheckoutSubmittingState()).toBe(false);
        expect(setLocal).not.toHaveBeenCalled();
    });

    // @scenario case=shop_base_root
    // @effects checkout_detected_by_shop_base_contract
    it('쇼핑몰이 루트(빈 앞머리)면 /checkout 을 체크아웃으로 본다', () => {
        vi.spyOn(console, 'info').mockImplementation(() => {});
        windowRecord().G7Core = { state: { get: globalState({ shopBase: '' }), setLocal } };

        window.history.pushState({}, '', '/checkout/');

        expect(resetCheckoutSubmittingState()).toBe(true);
    });

    // @scenario case=shop_base_unknown
    // @effects checkout_detected_by_shop_base_contract
    it('상태 계약이 아직 채워지지 않았으면 체크아웃으로 단정하지 않는다', () => {
        windowRecord().G7Core = { state: { get: () => ({}), setLocal } };
        window.history.pushState({}, '', '/shop/checkout');

        expect(resetCheckoutSubmittingState(undefined, false)).toBe(false);
        expect(setLocal).not.toHaveBeenCalled();
    });
});

/**
 * 결제 실패 화면 복귀 시 보고
 *
 * 브라우저 리턴 콜백(PC·모바일·해외결제)은 PG 서명도 IP 증명도 없어 주문 상태를 바꾸지 않는다.
 * 승인이 거절된 정당한 실패는 이 경로(소유권 대조 close-report)로만 기록된다.
 * 전체 페이지 이동으로 window 컨텍스트가 사라지므로 sessionStorage 로 이어받는다.
 */
/**
 * 클레임 추가결제 창 닫힘 → 신청서 상세 복귀 (이커머스 EC-455 계약)
 *
 * 클레임 결제는 신청서 화면(/mypage/claims/{N})에서 열리고 `order_number` 가 결제번호(CP…)다.
 * 체크아웃 화면만 보는 닫힘 처리로는 아무 일도 일어나지 않아, 신청서의 [결제하기]가
 * 「결제 진행 중…」에 묶이고 취소 안내도 없다(2026-10-06 S2 실측 CP20261006-2211334491).
 */
describe('클레임 추가결제 창 닫힘', () => {
    const CLAIM_RETURN = 'https://shop.example.test/mypage/claims/EX20261006-0001?claim_payment=cancelled';
    let originalLocation: Location;
    let hrefSet: string[];
    const apiPost = vi.fn();

    beforeEach(() => {
        originalLocation = window.location;
        hrefSet = [];
        apiPost.mockReset().mockResolvedValue({ success: true });
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
        windowRecord().G7Core = { api: { post: apiPost }, state: { setLocal: vi.fn() } };
        clearStandardPaymentCloseReportContext();
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        Object.defineProperty(window, 'location', { value: originalLocation, configurable: true, writable: true });
        delete windowRecord().G7Core;
        delete windowRecord().__sirsoftKginicisPaymentCloseListenerInstalled;
        clearStandardPaymentCloseReportContext();
        vi.restoreAllMocks();
    });

    it('신청서 화면에서 닫힘 메시지를 받으면 주문 닫힘 보고 없이 신청서 상세로 돌아간다', async () => {
        markStandardPaymentCloseReportContext({
            closeReportUrl: 'https://shop.example.test/api/plugins/sirsoft-pay_kginicis/payment/close-report',
            oid: 'CP20261006-0001',
            price: 6000,
            payment_method: 'card',
            claimCancelReturnUrl: CLAIM_RETURN,
        });
        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: 'https://shop.example.test',
            data: { source: 'sirsoft-pay_kginicis', type: 'payment-window-closed', reason: 'inicis-close-url' },
        }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(hrefSet).toEqual([CLAIM_RETURN]);
        expect(apiPost).not.toHaveBeenCalled();
    });

    it('신청서 복귀 주소가 다른 출처면 그 주소로 이동하지 않는다', async () => {
        markStandardPaymentCloseReportContext({
            closeReportUrl: 'https://shop.example.test/api/plugins/sirsoft-pay_kginicis/payment/close-report',
            oid: 'CP20261006-0002',
            price: 6000,
            payment_method: 'card',
            claimCancelReturnUrl: 'https://evil.example/phish',
        });
        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: 'https://shop.example.test',
            data: { source: 'sirsoft-pay_kginicis', type: 'payment-window-closed', reason: 'inicis-close-url' },
        }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(hrefSet).not.toContain('https://evil.example/phish');
    });
});

describe('결제 실패 화면 복귀 보고', () => {
    const CONTEXT = {
        closeReportUrl: '/api/plugins/sirsoft-pay_kginicis/payment/close-report',
        oid: 'ORD-KGI-RETURN-001',
        price: 10000,
    };

    /**
     * 화면 주소를 바꿔 결제 리턴 상황을 재현한다.
     */
    function setLocation(pathname: string, search: string): void {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { pathname, search, origin: 'https://shop.example' },
        });
    }

    beforeEach(() => {
        window.sessionStorage.clear();
        clearStandardPaymentCloseReportContext();
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        vi.restoreAllMocks();
        window.sessionStorage.clear();
        clearStandardPaymentCloseReportContext();
    });

    // @scenario case=failure_return
    // @effects failure_return_reported_once
    it('실패 화면으로 돌아오면 저장해 둔 주문 정보로 보고한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };

        markStandardPaymentCloseReportContext(CONTEXT);
        // 전체 페이지 이동으로 window 컨텍스트가 사라진 상황을 재현한다.
        delete windowRecord()['__sirsoftKginicisActiveStandardPaymentCloseContext'];

        setLocation('/shop/checkout', '?error=9999&message=fail&orderId=ORD-KGI-RETURN-001');

        await reportStandardPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-pay_kginicis/payment/close-report',
            expect.objectContaining({ oid: 'ORD-KGI-RETURN-001', price: 10000 }),
        );
    });

    // @scenario case=failure_return
    // @effects failure_return_reported_once
    it('두 번 호출해도 한 번만 보고한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };

        markStandardPaymentCloseReportContext(CONTEXT);
        setLocation('/shop/checkout', '?error=9999&orderId=ORD-KGI-RETURN-001');

        await reportStandardPaymentFailureOnReturn();
        await reportStandardPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalledTimes(1);
    });

    // @scenario case=failure_return
    // @effects failure_return_reported_once
    it('다른 주문번호로 돌아왔으면 보고하지 않는다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        markStandardPaymentCloseReportContext(CONTEXT);
        setLocation('/shop/checkout', '?error=9999&orderId=ORD-KGI-OTHER');

        await reportStandardPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
    });

    // @scenario case=failure_return
    // @effects failure_return_reported_once
    it('결제 완료 화면으로 돌아오면 보고하지 않고 저장분만 지운다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        markStandardPaymentCloseReportContext(CONTEXT);
        setLocation('/shop/orders/ORD-KGI-RETURN-001/complete', '');

        await reportStandardPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
        expect(window.sessionStorage.getItem('g7:sirsoft-pay_kginicis:pendingClose')).toBeNull();
    });

    // @scenario case=failure_return
    // @effects failure_return_reported_once
    it('상점이 실패 주소를 바꿔 체크아웃 경로가 아니어도 보고한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost } };

        markStandardPaymentCloseReportContext(CONTEXT);
        delete windowRecord()['__sirsoftKginicisActiveStandardPaymentCloseContext'];

        setLocation('/store/payment-failed', '?error=9999&orderId=ORD-KGI-RETURN-001');

        await reportStandardPaymentFailureOnReturn();

        expect(apiPost).toHaveBeenCalled();
    });

    // @scenario case=failure_return
    // @effects failure_return_reported_once
    it('저장분이 없으면 아무것도 보내지 않는다', async () => {
        const apiPost = vi.fn();
        windowRecord().G7Core = { api: { post: apiPost } };

        setLocation('/shop/checkout', '?error=9999&orderId=ORD-KGI-RETURN-001');

        await reportStandardPaymentFailureOnReturn();

        expect(apiPost).not.toHaveBeenCalled();
    });
});

/**
 * 소유자 증명 헤더 (KVE-2026-2434)
 *
 * close-report 는 주문을 실패·취소로 바꾸므로 서버가 주문 소유자만 받아 준다. 회원은 G7Core.api 가
 * Bearer 를 싣고, 비회원은 체크아웃이 저장해 둔 조회 토큰(X-Guest-Order-Token)을 실어야 한다.
 */
describe('소유자 증명 헤더', () => {
    const CONTEXT = {
        closeReportUrl: '/api/plugins/sirsoft-pay_kginicis/payment/close-report',
        oid: 'ORD-KGI-OWNER-001',
        price: 10000,
        payment_method: 'card',
    };

    /**
     * 결제창 닫힘 메시지를 보내 보고를 발생시킨다.
     */
    async function dispatchClose(): Promise<void> {
        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { source: 'sirsoft-pay_kginicis', type: 'payment-window-closed', reason: 'inicis-close-url' },
        }));
        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    beforeEach(() => {
        window.history.pushState({}, '', '/shop/checkout');
        window.sessionStorage.clear();
        window.localStorage.clear();
        clearStandardPaymentCloseReportContext();
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        delete windowRecord().__sirsoftKginicisPaymentCloseListenerInstalled;
        window.sessionStorage.clear();
        window.localStorage.clear();
        clearStandardPaymentCloseReportContext();
        vi.restoreAllMocks();
    });

    // @scenario case=guest_token_state
    // @effects guest_token_read_from_state_contract_only
    it('비회원이면 상태 계약(_global.guestOrderToken)의 조회 토큰을 X-Guest-Order-Token 으로 싣는다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
            state: { get: globalState({ guestOrderToken: '1999999999|guest-token' }), setLocal: vi.fn() },
        };
        markStandardPaymentCloseReportContext(CONTEXT);
        installPaymentCloseMessageListener();

        await dispatchClose();

        expect(apiPost).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-pay_kginicis/payment/close-report',
            expect.objectContaining({ oid: 'ORD-KGI-OWNER-001' }),
            { headers: { 'X-Guest-Order-Token': '1999999999|guest-token' } },
        );
    });

    // @scenario case=guest_token_storage_only
    // @effects guest_token_read_from_state_contract_only
    it('저장소에만 남은 토큰은 읽지 않는다 — 토큰은 쇼핑몰 상태 계약 하나에서만 온다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = { api: { post: apiPost }, state: { get: globalState(), setLocal: vi.fn() } };
        window.sessionStorage.setItem('g7_guest_order_token', '1999999999|stale-token');
        markStandardPaymentCloseReportContext(CONTEXT);
        installPaymentCloseMessageListener();

        await dispatchClose();

        expect(apiPost).toHaveBeenCalledTimes(1);
        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });

    // @scenario case=member
    // @effects member_bearer_left_to_core_api
    it('회원이면 비회원 토큰을 싣지 않는다 — 로그인하면 쇼핑몰이 토큰을 비우고, Bearer 는 G7Core.api 몫이다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
            state: { get: globalState({ guestOrderToken: null, currentUser: { uuid: 'member-1' } }), setLocal: vi.fn() },
        };
        markStandardPaymentCloseReportContext(CONTEXT);
        installPaymentCloseMessageListener();

        await dispatchClose();

        expect(apiPost).toHaveBeenCalledTimes(1);
        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });

    // @scenario case=no_token
    // @effects no_token_sends_without_header
    it('토큰이 없으면 헤더 없이 보내고 거부돼도 화면 흐름을 깨지 않는다', async () => {
        const apiPost = vi.fn().mockRejectedValue(new Error('403'));
        windowRecord().G7Core = { api: { post: apiPost }, state: { get: globalState(), setLocal: vi.fn() } };
        markStandardPaymentCloseReportContext(CONTEXT);
        installPaymentCloseMessageListener();

        await dispatchClose();

        expect(apiPost.mock.calls[0]).toHaveLength(2);
    });
});

/**
 * 서버가 주는 닫힘 보고 주소는 사이트 주소 기준 절대 URL 이다(`SiteUrl::absolute`).
 *
 * G7Core.api 는 `/api` 를 앞에 붙이는 경로를 받으므로 절대 URL 을 그대로 넘기면 `/plugins/...`(접두 없음)로
 * 나가 404 가 났고, 결제창을 닫아도 주문이 결제대기로 남았다.
 */
describe('사이트 주소 절대 URL 닫힘 보고', () => {
    beforeEach(() => {
        window.history.pushState({}, '', '/shop/checkout');
        window.sessionStorage.clear();
        window.localStorage.clear();
        clearStandardPaymentCloseReportContext();
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        delete windowRecord().__sirsoftKginicisPaymentCloseListenerInstalled;
        clearStandardPaymentCloseReportContext();
        vi.restoreAllMocks();
    });

    it('같은 출처 절대 URL 은 G7Core.api 에 /plugins 경로로 넘긴다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        // 닫힘 메시지는 체크아웃 화면에서만 처리한다 — 체크아웃 판정은 상태 계약(_global.shopBase)이 기준이다.
        windowRecord().G7Core = { api: { post: apiPost }, state: { get: globalState(), setLocal: vi.fn() } };
        markStandardPaymentCloseReportContext({
            closeReportUrl: `${window.location.origin}/plugins/sirsoft-pay_kginicis/payment/close-report`,
            oid: 'ORD-KGI-ABS-001',
            price: 15000,
            payment_method: 'card',
        });
        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { source: 'sirsoft-pay_kginicis', type: 'payment-window-closed', reason: 'inicis-close-url' },
        }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(apiPost).toHaveBeenCalledWith(
            '/plugins/sirsoft-pay_kginicis/payment/close-report',
            expect.objectContaining({ oid: 'ORD-KGI-ABS-001' }),
        );
    });
});
