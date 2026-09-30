import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    installAdminOrderPaymentDisplayInjector,
    patchAdminPaymentMethodDisplay,
} from '../adminOrderPaymentDisplayInjector';
import { resetOrderScreenPollingForTests } from '../orderScreenPolling';
import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';

describe('NicePayments order payment display injectors', () => {
    it('관리자 주문상세 결제수단 행을 간편결제 표시명으로 교체한다', () => {
        document.body.innerHTML = `
            <section id="section_payment_info">
                <div id="payment_method_block_0">
                    <div>결제수단</div>
                    <div>신용카드</div>
                    <div>(일시불)</div>
                </div>
            </section>
        `;

        const root = document.getElementById('section_payment_info');

        const patched = patchAdminPaymentMethodDisplay(root!, {
            _pay_method_label: '카카오페이 (신용카드)',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: '카카오페이',
        });

        expect(patched).toBe(true);
        expect(root?.textContent).toContain('카카오페이 (신용카드, 일시불)');
        expect(root?.textContent).not.toContain('(일시불)');
    });

    it('관리자 주문상세 결제수단 배지를 간편결제 라벨로 교체한다', () => {
        document.body.innerHTML = `
            <section id="section_payment_info">
                <span class="rounded-full font-medium">신용카드</span>
            </section>
        `;

        const root = document.getElementById('section_payment_info');

        const patched = patchAdminPaymentMethodDisplay(root!, {
            _pay_method_label: '네이버페이 (신용카드)',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: '네이버페이',
        });

        expect(patched).toBe(true);
        expect(root?.querySelector('span')?.textContent).toBe('네이버페이');
    });
});

/**
 * 결제 조회 폴링 회귀 잠금 — 응답을 기다리지 않는 반복 호출.
 *
 * 인젝터 폴러가 setInterval 틱마다 앞선 조회를 기다리지 않고 새 조회를 보내, 느린 PG 에서는
 * 첫 응답이 올 때까지 400ms 마다 같은 주소를 다시 불렀다(실측: 관리자 주문 상세 새로고침 한 번에
 * transaction-status 4~15회 — 서버는 매번 외부 PG API 까지 간다). 라우트가 바뀌어도 이전 폴러가
 * 멈추지 않았다. 같은 주문·같은 주소의 조회는 한 화면 진입당 1회여야 한다.
 */
describe('결제 조회 폴링 — 한 화면 진입당 1회', () => {
    const ADMIN_STATUS_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_nicepayments/admin/orders/${orderNumber}/transaction-status`;
    const RECEIPT_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_nicepayments/user/orders/${orderNumber}/receipt`;

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
                <div><span>결제수단</span><span>신용카드</span></div>
            </div>
        `;
    };
    const setMypagePanel = () => {
        document.body.innerHTML = `
            <section id="order_payment_info_panel">
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span>결제 방법</span>
                        <span>가상계좌</span>
                    </div>
                </div>
            </section>
        `;
    };
    const setOrderState = (data: Record<string, unknown>) => {
        (window as any).G7Core = {
            getState: () => ({ currentDataContext: { order: { data } } }),
        };
    };
    const vbankOrder = (orderNumber: string) => ({
        order_number: orderNumber,
        total_amount_formatted: '10,000원',
        payment: {
            pg_provider: 'nicepayments',
            payment_method: 'vbank',
            transaction_id: 'NICE-VB-TX',
            paid_at: null,
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
        delete w.__nicepayAdminPaymentDisplayInjectorInstalled;
        delete w.__nicepayOrderShowInjectorInstalled;
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
        setOrderState(vbankOrder('ORD-MP-SLOW'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(pendingForever);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 10);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-SLOW'))).toBe(1);
    });

    it('마이페이지 주문 상세: 영수증 조회가 502 로 실패해도 한 번만 부르고 입금 안내는 그대로 붙인다', async () => {
        history.replaceState(null, '', '/mypage/orders/ORD-MP-502');
        setOrderState(vbankOrder('ORD-MP-502'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(failsAfter3s);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(20000);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-502'))).toBe(1);
        expect(document.querySelectorAll('#nicepay-mp-vbank-info')).toHaveLength(1);
        expect(document.getElementById('nicepay-mp-receipt-row')).toBeNull();
    });
});

/**
 * 레이아웃 데이터 소스 재사용 회귀 잠금 — 같은 주소의 이중 조회.
 *
 * 관리자 주문 상세는 레이아웃 확장(admin_order_payment_query)이 transaction-status 를 데이터 소스
 * `nicepay_status` 로 받는데, 인젝터가 같은 주소를 한 번 더 불러 서버가 외부 PG API 를 두 번 조회했다
 * (실측: 새로고침 한 번에 PG 마다 transaction-status 2회 — XHR 1 + fetch 1). 인젝터는 같은 화면의
 * 데이터 소스 값을 쓰고, 값이 끝내 오지 않을 때만 직접 조회한다. 라우트 전환 직후 남아 있는 이전
 * 주문의 값은 새 주문 값으로 쓰지 않는다.
 */
describe('관리자 주문 상세 — 레이아웃 데이터 소스 재사용', () => {
    const DATA_SOURCE = 'nicepay_status';
    const STATUS_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_nicepayments/admin/orders/${orderNumber}/transaction-status`;
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
                <div><span>결제수단</span><span>신용카드</span></div>
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
        delete (window as unknown as Record<string, unknown>).__nicepayAdminPaymentDisplayInjectorInstalled;
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
