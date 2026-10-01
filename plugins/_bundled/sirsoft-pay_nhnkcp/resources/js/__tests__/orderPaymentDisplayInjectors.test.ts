import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    installAdminOrderPaymentDisplayInjector,
    patchAdminPaymentMethodDisplay,
} from '../adminOrderPaymentDisplayInjector';
import { resetOrderScreenPollingForTests } from '../orderScreenPolling';
import { installMypageOrderShowInjector, patchMypagePaymentMethodDisplay } from '../mypageOrderShowInjector';

describe('NHN KCP order payment display injectors', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('마이페이지 주문 상세의 결제 방법 행을 간편결제 표시로 바꾼다', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div id="order_payment_method_row" class="flex items-center justify-between">
                <span class="text-gray-500">결제 방법</span>
                <span class="text-gray-900">신용카드</span>
            </div>
        `;

        expect(patchMypagePaymentMethodDisplay(container, '네이버페이 (신용카드)')).toBe(true);
        expect(container.textContent?.replace(/\s+/g, '')).toContain('결제방법네이버페이(신용카드)');
        expect(container.querySelector('[data-nhnkcp-payment-method-patched="true"]')).not.toBeNull();
    });

    it('관리자 주문 상세의 결제수단 행과 상단 배지를 간편결제 표시로 바꾼다', () => {
        const root = document.createElement('div');
        root.innerHTML = `
            <span class="inline-flex rounded-full font-medium">신용카드</span>
            <div id="payment_method_block_0">
                <span class="text-xs block">결제수단</span>
                <span class="text-sm font-semibold block">신용카드</span>
                <span class="text-xs text-gray-500">(일시불)</span>
            </div>
        `;

        expect(patchAdminPaymentMethodDisplay(root, {
            _pay_method_label: '카카오페이 (신용카드)',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: '카카오페이',
        })).toBe(true);

        const text = root.textContent?.replace(/\s+/g, '');
        expect(text).toContain('카카오페이결제수단카카오페이(신용카드,일시불)');
    });
});

/**
 * 결제 조회 폴링 회귀 잠금 — 응답을 기다리지 않는 반복 호출.
 *
 * 인젝터 폴러가 setInterval 틱마다 앞선 조회를 기다리지 않고 새 조회를 보내, 느린 PG 에서는
 * 첫 응답이 올 때까지 400ms 마다 같은 주소를 다시 불렀다(실측: 관리자 주문 상세 새로고침 한 번에
 * transaction-status 11~14회, 비회원 주문 상세 vbank-mock-deposit-info 5회). 마이페이지는 블록을
 * 하나 붙일 때마다 영수증을 다시 조회했고, 라우트가 바뀌어도 이전 폴러가 멈추지 않았다.
 * 같은 주문·같은 주소의 조회는 한 화면 진입당 1회여야 한다.
 */
describe('결제 조회 폴링 — 한 화면 진입당 1회', () => {
    const ADMIN_STATUS_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_nhnkcp/admin/orders/${orderNumber}/transaction-status`;
    const RECEIPT_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_nhnkcp/user/orders/${orderNumber}/receipt`;
    const MOCK_DEPOSIT_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_nhnkcp/user/orders/${orderNumber}/vbank-mock-deposit-info`;

    // 응답이 오지 않는 PG 조회 (느린 외부 PG)
    const pendingForever = () => new Promise<Response>(() => {});
    // 3초 뒤 502 로 끝나는 PG 조회
    const failsAfter3s = () =>
        new Promise<Response>(resolve => {
            setTimeout(() => resolve({ ok: false, status: 502, json: async () => ({}) } as Response), 3000);
        });
    const callsTo = (fetchMock: ReturnType<typeof vi.fn>, url: string) =>
        fetchMock.mock.calls.filter(([calledUrl]) => String(calledUrl) === url).length;

    const setAdminRoot = () => {
        document.body.innerHTML = `
            <div id="section_payment_info">
                <div id="payment_method_block_0"><span>결제수단</span><span>신용카드</span></div>
            </div>
        `;
    };
    const setMypagePanel = () => {
        document.body.innerHTML = `
            <section id="order_payment_info_panel">
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span>결제 방법</span>
                        <span>신용카드</span>
                    </div>
                </div>
            </section>
        `;
    };
    const setOrderState = (data: Record<string, unknown> | null) => {
        (window as any).G7Core = {
            getState: () => ({ currentDataContext: data ? { order: { data } } : {} }),
        };
    };
    const paidCardOrder = (orderNumber: string) => ({
        order_number: orderNumber,
        payment: {
            pg_provider: 'nhnkcp',
            payment_method: 'card',
            transaction_id: 'KCP-TX',
            paid_at: '2026-09-29 10:00:00',
        },
    });
    const paidVbankOrder = (orderNumber: string) => ({
        order_number: orderNumber,
        total_amount_formatted: '10,000원',
        payment: {
            pg_provider: 'nhnkcp',
            payment_method: 'vbank',
            transaction_id: 'KCP-VB-TX',
            paid_at: '2026-09-29 10:00:00',
            vbank_name: '국민은행',
            vbank_number: '12345678901234',
            vbank_holder: '홍길동',
        },
    });

    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        localStorage.clear();
        sessionStorage.clear();
        localStorage.setItem('auth_token', 'test-token');
        vi.useFakeTimers();
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.innerHTML = '';
        const w = window as unknown as Record<string, unknown>;
        delete w.__nhnkcpAdminPaymentDisplayInjectorInstalled;
        delete w.__kcpMpShowInjectorInstalled;
        delete (window as any).G7Core;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('관리자 주문 상세: PG 응답이 오지 않는 동안 transaction-status 를 다시 부르지 않는다', async () => {
        history.replaceState(null, '', '/admin/ecommerce/orders/ORD-ADM-SLOW');
        setAdminRoot();
        const fetchMock = vi.fn().mockImplementation(pendingForever);
        globalThis.fetch = fetchMock;

        installAdminOrderPaymentDisplayInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 10);

        expect(callsTo(fetchMock, ADMIN_STATUS_API('ORD-ADM-SLOW'))).toBe(1);
    });

    it('관리자 주문 상세: 조회가 502 로 실패하면 재시도하지 않고 표시도 그대로 둔다', async () => {
        history.replaceState(null, '', '/admin/ecommerce/orders/ORD-ADM-502');
        setAdminRoot();
        const fetchMock = vi.fn().mockImplementation(failsAfter3s);
        globalThis.fetch = fetchMock;

        installAdminOrderPaymentDisplayInjector();
        await vi.advanceTimersByTimeAsync(20000);

        expect(callsTo(fetchMock, ADMIN_STATUS_API('ORD-ADM-502'))).toBe(1);
        expect(document.body.textContent?.replace(/\s+/g, '')).toBe('결제수단신용카드');
    });

    it('관리자 주문 상세: 다른 주문으로 이동하면 이전 주문의 폴러가 멈춘다', async () => {
        history.replaceState(null, '', '/admin/ecommerce/orders/ORD-ADM-A');
        // 결제 정보 카드가 아직 그려지지 않은 상태 — 이전 폴러는 DOM 을 기다리는 중
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ data: { _pay_method_label: '신용카드', _embedded_pg_provider_label: null } }),
        } as Response);
        globalThis.fetch = fetchMock;

        installAdminOrderPaymentDisplayInjector();
        await vi.advanceTimersByTimeAsync(2000);
        history.pushState(null, '', '/admin/ecommerce/orders/ORD-ADM-B');
        await vi.advanceTimersByTimeAsync(700);
        setAdminRoot();
        await vi.advanceTimersByTimeAsync(15000);

        expect(callsTo(fetchMock, ADMIN_STATUS_API('ORD-ADM-A')), '떠난 주문을 조회했습니다').toBe(0);
        expect(callsTo(fetchMock, ADMIN_STATUS_API('ORD-ADM-B'))).toBe(1);
    });

    it('마이페이지 주문 상세: PG 응답이 오지 않는 동안 영수증 조회를 다시 부르지 않는다', async () => {
        history.replaceState(null, '', '/mypage/orders/ORD-MP-SLOW');
        setOrderState(paidCardOrder('ORD-MP-SLOW'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(pendingForever);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 10);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-SLOW'))).toBe(1);
    });

    it('마이페이지 주문 상세: 입금 안내·영수증 행을 차례로 붙여도 영수증 조회는 한 번이다', async () => {
        history.replaceState(null, '', '/mypage/orders/ORD-MP-VB');
        setOrderState(paidVbankOrder('ORD-MP-VB'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(async (url: string) => {
            if (String(url).endsWith('/vbank-mock-deposit-info')) {
                return { ok: true, status: 200, json: async () => ({ available: false }) } as Response;
            }
            return {
                ok: true,
                status: 200,
                json: async () => ({ receipt_url: 'https://admin8.kcp.co.kr/receipt', payment_method_display_label: null }),
            } as Response;
        });
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 10);

        expect(document.getElementById('kcp-mp-vbank-row')).not.toBeNull();
        expect(document.getElementById('kcp-mp-receipt-row')).not.toBeNull();
        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-VB'))).toBe(1);
        expect(callsTo(fetchMock, MOCK_DEPOSIT_API('ORD-MP-VB'))).toBe(1);
    });

    it('마이페이지 주문 상세: 조회가 502 로 실패해도 주소마다 한 번만 부르고 입금 안내는 그대로 붙인다', async () => {
        history.replaceState(null, '', '/mypage/orders/ORD-MP-502');
        setOrderState(paidVbankOrder('ORD-MP-502'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(failsAfter3s);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(20000);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-502'))).toBe(1);
        expect(callsTo(fetchMock, MOCK_DEPOSIT_API('ORD-MP-502'))).toBe(1);
        expect(document.querySelectorAll('#kcp-mp-vbank-row')).toHaveLength(1);
        expect(document.querySelectorAll('#kcp-mp-receipt-row')).toHaveLength(1);
        expect(document.getElementById('kcp-mp-mock-deposit')).toBeNull();
    });

    it('비회원 주문 상세: 주문 상태가 없을 때 모의입금 조회를 응답 전에 다시 부르지 않는다', async () => {
        history.replaceState(null, '', '/shop/guest/orders/ORD-GUEST-SLOW');
        setOrderState(null);
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(pendingForever);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 10);

        expect(callsTo(fetchMock, MOCK_DEPOSIT_API('ORD-GUEST-SLOW'))).toBe(1);
    });

    it('마이페이지 주문 상세: 다른 주문으로 이동하면 이전 주문의 폴러가 멈춘다', async () => {
        history.replaceState(null, '', '/mypage/orders/ORD-MP-A');
        setOrderState(paidCardOrder('ORD-MP-A'));
        // 결제 정보 패널이 아직 없음 — 이전 폴러는 DOM 을 기다리는 중
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ receipt_url: 'https://admin8.kcp.co.kr/receipt', available: false }),
        } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2000);
        setOrderState(paidCardOrder('ORD-MP-B'));
        history.pushState(null, '', '/mypage/orders/ORD-MP-B');
        await vi.advanceTimersByTimeAsync(700);
        setMypagePanel();
        await vi.advanceTimersByTimeAsync(15000);

        const callsForA = fetchMock.mock.calls.filter(([url]) => String(url).includes('/orders/ORD-MP-A/'));
        expect(callsForA, '떠난 주문을 조회했습니다').toHaveLength(0);
        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-B'))).toBe(1);
        expect(document.getElementById('kcp-mp-receipt-row')).not.toBeNull();
    });

    /*
     * 비회원 주문 상세 회귀 잠금 — 불러오는 중인 주문을 「주문 정보 없음」으로 본 조회.
     * 실측: 비회원 주문 상세(다른 결제대행사 주문)에서 첫 틱이 주문을 불러오는 동안 돌아 「주문 정보 없음」
     * 분기로 빠졌고, 로그인 전용 모의입금 주소를 비회원 조회 토큰으로 불러 401 이 났다.
     */
    const setLoadingThenOrder = () => {
        const ctx: Record<string, unknown> = { order: undefined };
        (window as any).G7Core = { getState: () => ({ currentDataContext: ctx }) };
        return (data: Record<string, unknown>) => {
            ctx.order = { data };
        };
    };

    it('비회원 주문 상세: 로그인하지 않은 손님에게는 로그인 전용 모의입금 조회를 보내지 않는다', async () => {
        history.replaceState(null, '', '/shop/guest/orders/ORD-GUEST-NOAUTH');
        localStorage.removeItem('auth_token');
        sessionStorage.setItem('g7_guest_order_token', 'guest-token');
        setOrderState(null);
        setMypagePanel();
        const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 401, json: async () => ({}) } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 10);

        expect(callsTo(fetchMock, MOCK_DEPOSIT_API('ORD-GUEST-NOAUTH'))).toBe(0);
    });

    it('주문을 불러오는 동안에는 기다리고, 다른 결제대행사 주문이면 아무것도 조회하지 않는다', async () => {
        history.replaceState(null, '', '/mypage/orders/ORD-MP-LOADING');
        const resolveOrder = setLoadingThenOrder();
        setMypagePanel();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ available: false }),
        } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 3);
        resolveOrder({ order_number: 'ORD-MP-LOADING', payment: { pg_provider: 'kginicis', payment_method: 'vbank', vbank_number: '1234' } });
        await vi.advanceTimersByTimeAsync(400 * 5);

        expect(fetchMock.mock.calls.map(([url]) => String(url)), '불러오는 중에 조회를 보냈습니다').toEqual([]);
    });
});

/**
 * 레이아웃 데이터 소스 재사용 회귀 잠금 — 같은 주소의 이중 조회.
 *
 * 관리자 주문 상세는 레이아웃 확장(admin_order_payment_query)이 transaction-status 를 데이터 소스
 * `kcp_status` 로 받는데, 인젝터가 같은 주소를 한 번 더 불러 서버가 외부 PG API 를 두 번 조회했다
 * (실측: 새로고침 한 번에 PG 마다 transaction-status 2회 — XHR 1 + fetch 1). 인젝터는 같은 화면의
 * 데이터 소스 값을 쓰고, 값이 끝내 오지 않을 때만 직접 조회한다. 라우트 전환 직후 남아 있는 이전
 * 주문의 값은 새 주문 값으로 쓰지 않는다.
 */
describe('관리자 주문 상세 — 레이아웃 데이터 소스 재사용', () => {
    const DATA_SOURCE = 'kcp_status';
    const STATUS_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_nhnkcp/admin/orders/${orderNumber}/transaction-status`;
    const callsTo = (fetchMock: ReturnType<typeof vi.fn>, url: string) =>
        fetchMock.mock.calls.filter(([calledUrl]) => String(calledUrl) === url).length;
    const statusBody = (embedded: string) => ({
        success: true,
        data: {
            _pay_method_label: `${embedded} (신용카드)`,
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: embedded,
        },
    });
    const setAdminRoot = () => {
        document.body.innerHTML = `
            <div id="section_payment_info">
                <div id="payment_method_block_0"><span>결제수단</span><span>신용카드</span></div>
            </div>
        `;
    };
    const paymentText = () => document.body.textContent?.replace(/\s+/g, '') ?? '';
    let dataSources: Record<string, unknown>;
    let originalFetch: typeof globalThis.fetch;
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        localStorage.clear();
        sessionStorage.clear();
        localStorage.setItem('auth_token', 'test-token');
        vi.useFakeTimers();
        resetOrderScreenPollingForTests();
        dataSources = {};
        (window as any).G7Core = { dataSource: { get: (id: string) => dataSources[id] } };
        fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => statusBody('직접조회페이'),
        } as Response);
        globalThis.fetch = fetchMock;
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.innerHTML = '';
        delete (window as unknown as Record<string, unknown>).__nhnkcpAdminPaymentDisplayInjectorInstalled;
        delete (window as any).G7Core;
        resetOrderScreenPollingForTests();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('데이터 소스 값이 이미 있으면 transaction-status 를 부르지 않고 그 값으로 표시한다', async () => {
        history.replaceState(null, '', '/admin/ecommerce/orders/ORD-DS-READY');
        setAdminRoot();
        dataSources[DATA_SOURCE] = statusBody('네이버페이');

        installAdminOrderPaymentDisplayInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 3);

        expect(callsTo(fetchMock, STATUS_API('ORD-DS-READY'))).toBe(0);
        expect(paymentText()).toContain('결제수단네이버페이(신용카드)');
    });

    it('데이터 소스 값이 늦게 와도 기다렸다가 그 값을 쓰고 직접 조회하지 않는다', async () => {
        history.replaceState(null, '', '/admin/ecommerce/orders/ORD-DS-LATE');
        setAdminRoot();

        installAdminOrderPaymentDisplayInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 5);
        expect(paymentText()).toBe('결제수단신용카드');

        dataSources[DATA_SOURCE] = statusBody('카카오페이');
        await vi.advanceTimersByTimeAsync(800);

        expect(callsTo(fetchMock, STATUS_API('ORD-DS-LATE'))).toBe(0);
        expect(paymentText()).toContain('결제수단카카오페이(신용카드)');
    });

    it('데이터 소스 값이 끝내 오지 않으면 한 번만 직접 조회한다', async () => {
        history.replaceState(null, '', '/admin/ecommerce/orders/ORD-DS-NONE');
        setAdminRoot();

        installAdminOrderPaymentDisplayInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 30);

        expect(callsTo(fetchMock, STATUS_API('ORD-DS-NONE'))).toBe(1);
        expect(paymentText()).toContain('결제수단직접조회페이(신용카드)');
    });

    it('다른 주문으로 바로 이동하면 남아 있던 이전 주문의 값으로 표시하지 않는다', async () => {
        history.replaceState(null, '', '/admin/ecommerce/orders/ORD-DS-A');
        setAdminRoot();
        dataSources[DATA_SOURCE] = statusBody('이전주문페이');

        installAdminOrderPaymentDisplayInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 2);
        expect(paymentText()).toContain('결제수단이전주문페이(신용카드)');

        history.pushState(null, '', '/admin/ecommerce/orders/ORD-DS-B');
        setAdminRoot();
        await vi.advanceTimersByTimeAsync(600 + 400 * 5);
        expect(paymentText(), '이전 주문의 결제 표시를 새 주문에 붙였습니다').toBe('결제수단신용카드');

        dataSources[DATA_SOURCE] = statusBody('새주문페이');
        await vi.advanceTimersByTimeAsync(800);

        expect(paymentText()).toContain('결제수단새주문페이(신용카드)');
        expect(callsTo(fetchMock, STATUS_API('ORD-DS-A'))).toBe(0);
        expect(callsTo(fetchMock, STATUS_API('ORD-DS-B'))).toBe(0);
    });
});
