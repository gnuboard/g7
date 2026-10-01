import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    installAdminOrderPaymentDisplayInjector,
    patchAdminPaymentMethodDisplay,
} from '../adminOrderPaymentDisplayInjector';
import { resetOrderScreenPollingForTests } from '../orderScreenPolling';
import { installMypageOrderShowInjector, patchMypagePaymentMethodDisplay } from '../mypageOrderShowInjector';
import { installOrderCompleteReceiptInjector } from '../orderCompleteReceiptInjector';
import { installVbankInfoInjector } from '../vbankInfoInjector';

describe('order payment display injectors', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        localStorage.clear();
        sessionStorage.clear();
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.innerHTML = '';
        delete (window as Record<string, unknown>).__kginicisOrderShowInjectorInstalled;
        delete (window as Record<string, unknown>).__kginicisOcReceiptInjectorInstalled;
        delete (window as any).G7Core;
        vi.useRealTimers();
        vi.restoreAllMocks();
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
        expect(container.querySelector('[data-kginicis-payment-method-patched="true"]')).not.toBeNull();
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
            _pay_method_label: '네이버페이 (신용카드)',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: '네이버페이',
        })).toBe(true);

        const text = root.textContent?.replace(/\s+/g, '');
        expect(text).toContain('네이버페이결제수단네이버페이(신용카드,일시불)');
    });

    it('관리자 주문 상세는 간편결제 정보가 없으면 기존 표시를 건드리지 않는다', () => {
        const root = document.createElement('div');
        root.innerHTML = `
            <span class="inline-flex rounded-full font-medium">신용카드</span>
            <div><span>결제수단</span><span>신용카드</span></div>
        `;

        expect(patchAdminPaymentMethodDisplay(root, {
            _pay_method_label: '신용카드',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: null,
        })).toBe(false);
        expect(root.textContent?.replace(/\s+/g, '')).toBe('신용카드결제수단신용카드');
    });

    it('결제완료 페이지에서 비회원 토큰 없이 receipt cookie fallback 응답만으로 영수증 버튼을 붙인다', async () => {
        vi.useFakeTimers();
        history.pushState(null, '', '/shop/orders/ORD-COOKIE-500/complete');
        document.body.innerHTML = `
            <div id="actions">
                <button type="button" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white">주문상세</button>
                <button type="button" class="px-4 py-2 bg-gray-100">계속 쇼핑</button>
            </div>
        `;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ success: true, data: {
                receipt_type: 'inicis_receipt',
                receipt_url: 'https://iniweb.inicis.com/receipt',
                receipt_view_label: '영수증 조회',
                payment_method_display_label: '네이버페이 (신용카드)',
            } }),
        } as Response);
        globalThis.fetch = fetchMock;

        installOrderCompleteReceiptInjector();
        await vi.advanceTimersByTimeAsync(1300);

        const receiptButton = document.getElementById('kginicis-oc-receipt-btn');
        expect(receiptButton?.textContent).toBe('영수증 조회');
        expect(document.getElementById('actions')?.textContent?.replace(/\s+/g, '')).toContain(
            '영수증조회계속쇼핑',
        );
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/api/plugins/sirsoft-pay_kginicis/user/orders/ORD-COOKIE-500/receipt',
        );
        expect(fetchMock.mock.calls[0][1].headers.Authorization).toBeUndefined();
        expect(fetchMock.mock.calls[0][1].headers['X-Guest-Order-Token']).toBeUndefined();
    });

    it('KG이니시스 결제가 아닌 주문에서는 영수증 API 를 아예 호출하지 않는다', async () => {
        // 결함: 영수증 조회를 먼저 호출하고 그 다음에 결제수단을 확인해, 무통장입금처럼
        //   PG 를 거치지 않는 주문에서도 매번 404 요청이 나갔다. 화면에는 영향이 없지만
        //   불필요한 왕복이고 콘솔에 오류로 남는다.
        vi.useFakeTimers();
        localStorage.setItem('auth_token', 'member-token');
        history.pushState(null, '', '/shop/orders/ORD-DBANK-1/complete');
        document.body.innerHTML = `
            <div id="actions">
                <button type="button" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white">주문상세</button>
            </div>
        `;

        const fetchMock = vi.fn().mockImplementation(async (url: string) => {
            if (String(url).includes('/user/orders/ORD-DBANK-1')) {
                return {
                    ok: true,
                    status: 200,
                    // 무통장입금 — PG 미경유이므로 pg_provider 가 없다
                    json: async () => ({ data: { payment: { pg_provider: null, payment_status: 'pending', transaction_id: null } } }),
                } as unknown as Response;
            }

            return { ok: false, status: 404, json: async () => ({}) } as unknown as Response;
        });
        globalThis.fetch = fetchMock;

        installOrderCompleteReceiptInjector();
        await vi.advanceTimersByTimeAsync(1300);

        const receiptCalls = fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/receipt'));

        expect(receiptCalls, 'PG 결제가 아닌데 영수증 API 를 호출했습니다').toHaveLength(0);
        expect(document.getElementById('kginicis-oc-receipt-btn')).toBeNull();
    });

    it('비회원 주문 상세에서 order state 를 못 잡아도 receipt API 응답만으로 영수증 행을 붙인다', async () => {
        vi.useFakeTimers();
        history.pushState(null, '', '/shop/guest/orders/ORD-GUEST-RECEIPT');
        (window as any).G7Core = {
            getState: () => ({ currentDataContext: {} }),
        };
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

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ success: true, data: {
                receipt_type: 'inicis_receipt',
                receipt_url: 'https://iniweb.inicis.com/receipt',
                receipt_label: '영수증',
                receipt_view_label: '영수증 조회',
                payment_method_display_label: '신용카드',
            } }),
        } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2100);

        const receiptRow = document.getElementById('kginicis-mp-receipt-row');
        expect(document.getElementById('order_payment_info_panel')!.contains(receiptRow!)).toBe(true);
        expect(receiptRow?.textContent?.replace(/\s+/g, '')).toContain('영수증영수증조회');
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-pay_kginicis/user/orders/ORD-GUEST-RECEIPT/receipt',
            expect.objectContaining({ credentials: 'same-origin' }),
        );

        delete (window as any).G7Core;
    });

    // 결제 정보 카드는 제목 글자가 아니라 템플릿이 약속한 id 로 찾는다 — 영어 화면도 같은 id 를 가진 카드에 붙는다.
    it('영어 비회원 주문 상세에서도 결제 정보 카드(id)에 영수증 행을 붙인다', async () => {
        vi.useFakeTimers();
        history.pushState(null, '', '/shop/guest/orders');
        (window as any).G7Core = {
            getState: () => ({
                currentDataContext: {
                    order: {
                        data: {
                            order_number: 'ORD-GUEST-EN',
                            payment: {
                                pg_provider: 'kginicis',
                                payment_status: 'paid',
                                transaction_id: 'StdpayCARDINIpayTest',
                            },
                        },
                    },
                },
            }),
        };
        document.body.innerHTML = `
            <section id="order_payment_info_panel">
                <div>
                    <h3>Payment Information</h3>
                </div>
                <div class="space-y-2">
                    <div id="order_payment_method_row" class="flex items-center justify-between">
                        <span>Payment Method</span>
                        <span>Credit Card</span>
                    </div>
                </div>
            </section>
        `;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ success: true, data: {
                receipt_type: 'inicis_receipt',
                receipt_url: 'https://iniweb.inicis.com/receipt',
                receipt_label: 'Receipt',
                receipt_view_label: 'View Receipt',
                payment_method_display_label: 'Naver Pay (Credit Card)',
            } }),
        } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        history.replaceState(null, '', '/shop/guest/orders/ORD-GUEST-EN');
        await vi.advanceTimersByTimeAsync(1100);

        const receiptRow = document.getElementById('kginicis-mp-receipt-row');
        expect(receiptRow?.textContent?.replace(/\s+/g, '')).toContain('ReceiptViewReceipt');
        expect(document.body.textContent?.replace(/\s+/g, '')).toContain('PaymentMethodNaverPay(CreditCard)');
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/plugins/sirsoft-pay_kginicis/user/orders/ORD-GUEST-EN/receipt',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
    });
});

/**
 * 결제 조회 폴링 회귀 잠금 — 응답을 기다리지 않는 반복 호출.
 *
 * 인젝터 폴러가 setInterval 틱마다 앞선 조회를 기다리지 않고 새 조회를 보내, 느린 PG 에서는
 * 첫 응답이 올 때까지 400ms 마다 같은 주소를 다시 불렀다(실측: 관리자 주문 상세 새로고침 한 번에
 * transaction-status 10~31회 — 서버는 매번 외부 PG API 까지 간다). 라우트가 바뀌어도 이전 폴러가
 * 멈추지 않았다. 같은 주문·같은 주소의 조회는 한 화면 진입당 1회여야 한다.
 */
describe('결제 조회 폴링 — 한 화면 진입당 1회', () => {
    const ADMIN_STATUS_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_kginicis/admin/orders/${orderNumber}/transaction-status`;
    const RECEIPT_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_kginicis/user/orders/${orderNumber}/receipt`;

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
                    <div id="order_payment_method_row" class="flex items-center justify-between">
                        <span>결제 방법</span>
                        <span>신용카드</span>
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
    const paidOrder = (orderNumber: string) => ({
        order_number: orderNumber,
        payment: { pg_provider: 'kginicis', payment_status: 'paid', transaction_id: 'StdpayCARDINIpayTest' },
    });
    const waitingVbankOrder = (orderNumber: string, transactionId: string | null = null) => ({
        order_number: orderNumber,
        payment: {
            pg_provider: 'kginicis',
            payment_status: 'waiting_deposit',
            transaction_id: transactionId,
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
        delete w.__kginicisAdminPaymentDisplayInjectorInstalled;
        delete w.__kginicisOrderShowInjectorInstalled;
        delete w.__kginicisVbankInfoInjectorInstalled;
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
        history.replaceState(null, '', '/shop/guest/orders/ORD-MP-SLOW');
        setOrderState(paidOrder('ORD-MP-SLOW'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(pendingForever);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 10);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-SLOW'))).toBe(1);
    });

    it('마이페이지 주문 상세: 영수증 조회가 502 로 실패해도 같은 화면에서 다시 부르지 않는다', async () => {
        history.replaceState(null, '', '/shop/guest/orders/ORD-MP-502');
        setOrderState(paidOrder('ORD-MP-502'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(failsAfter3s);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(20000);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-502'))).toBe(1);
        expect(document.getElementById('kginicis-mp-receipt-row')).toBeNull();
    });

    it('마이페이지 주문 상세: 다른 주문으로 이동하면 이전 주문의 폴러가 멈춘다', async () => {
        history.replaceState(null, '', '/shop/guest/orders/ORD-MP-A');
        setOrderState(paidOrder('ORD-MP-A'));
        // 결제 정보 패널이 아직 없음 — 이전 폴러는 DOM 을 기다리는 중
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ success: true, data: {
                receipt_type: 'inicis_receipt',
                receipt_url: 'https://iniweb.inicis.com/receipt',
                receipt_label: '영수증',
                receipt_view_label: '영수증 조회',
                payment_method_display_label: '신용카드',
            } }),
        } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2000);
        setOrderState(paidOrder('ORD-MP-B'));
        history.pushState(null, '', '/shop/guest/orders/ORD-MP-B');
        await vi.advanceTimersByTimeAsync(700);
        setMypagePanel();
        await vi.advanceTimersByTimeAsync(15000);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-A')), '떠난 주문을 조회했습니다').toBe(0);
        expect(callsTo(fetchMock, RECEIPT_API('ORD-MP-B'))).toBe(1);
        expect(document.getElementById('kginicis-mp-receipt-row')).not.toBeNull();
    });

    it('가상계좌 안내: PG 응답이 오지 않는 동안 영수증 조회를 다시 부르지 않는다', async () => {
        history.replaceState(null, '', '/mypage/orders/ORD-VB-SLOW');
        setOrderState(waitingVbankOrder('ORD-VB-SLOW'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(pendingForever);
        globalThis.fetch = fetchMock;

        installVbankInfoInjector();
        await vi.advanceTimersByTimeAsync(800 + 400 * 10);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-VB-SLOW'))).toBe(1);
    });

    it('가상계좌 안내: 영수증 조회가 502 로 실패해도 한 번만 부르고 입금 안내는 그대로 붙인다', async () => {
        history.replaceState(null, '', '/mypage/orders/ORD-VB-502');
        setOrderState(waitingVbankOrder('ORD-VB-502'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(failsAfter3s);
        globalThis.fetch = fetchMock;

        installVbankInfoInjector();
        await vi.advanceTimersByTimeAsync(20000);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-VB-502'))).toBe(1);
        const section = document.getElementById('kginicis-mp-vbank-info');
        expect(section?.textContent?.replace(/\s+/g, '')).toContain('가상계좌입금안내');
        expect(document.querySelectorAll('#kginicis-mp-vbank-info')).toHaveLength(1);
    });

    it('같은 화면의 두 인젝터(영수증 행·가상계좌 안내)가 같은 영수증 주소를 합쳐 한 번만 부른다', async () => {
        history.replaceState(null, '', '/mypage/orders/ORD-VB-BOTH');
        setOrderState(waitingVbankOrder('ORD-VB-BOTH', 'INIpayVBNK-TX'));
        setMypagePanel();
        const fetchMock = vi.fn().mockImplementation(pendingForever);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        installVbankInfoInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 10);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-VB-BOTH'))).toBe(1);
    });

    /*
     * 비회원 주문 상세 회귀 잠금 — 불러오는 중인 주문을 「주문 정보 없음」으로 본 조회.
     * 실측: 다른 결제대행사의 비회원 주문 상세에서 주문을 불러오는 동안 경로의 주문번호로 영수증을 조회해
     * 404 가 났다. 불러오는 중에는 기다리고, 불러온 주문이 KG이니시스가 아니면 조회하지 않는다.
     */
    it('비회원 주문 상세: 주문을 불러오는 동안 기다리고, 다른 결제대행사 주문이면 영수증을 조회하지 않는다', async () => {
        history.replaceState(null, '', '/shop/guest/orders/ORD-GUEST-LOADING');
        const ctx: Record<string, unknown> = { order: undefined };
        (window as any).G7Core = { getState: () => ({ currentDataContext: ctx }) };
        setMypagePanel();
        const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 404, json: async () => ({}) } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(1500 + 400 * 3);
        ctx.order = {
            data: {
                order_number: 'ORD-GUEST-LOADING',
                payment: { pg_provider: 'nhnkcp', payment_status: 'paid', transaction_id: 'KCP-TX' },
            },
        };
        await vi.advanceTimersByTimeAsync(400 * 5);

        expect(callsTo(fetchMock, RECEIPT_API('ORD-GUEST-LOADING')), '불러오는 중에 영수증을 조회했습니다').toBe(0);
    });
});

/**
 * 레이아웃 데이터 소스 재사용 회귀 잠금 — 같은 주소의 이중 조회.
 *
 * 관리자 주문 상세는 레이아웃 확장(admin_order_payment_query)이 transaction-status 를 데이터 소스
 * `kginicis_status` 로 받는데, 인젝터가 같은 주소를 한 번 더 불러 서버가 외부 PG API 를 두 번 조회했다
 * (실측: 새로고침 한 번에 PG 마다 transaction-status 2회 — XHR 1 + fetch 1). 인젝터는 같은 화면의
 * 데이터 소스 값을 쓰고, 값이 끝내 오지 않을 때만 직접 조회한다. 라우트 전환 직후 남아 있는 이전
 * 주문의 값은 새 주문 값으로 쓰지 않는다.
 */
describe('관리자 주문 상세 — 레이아웃 데이터 소스 재사용', () => {
    const DATA_SOURCE = 'kginicis_status';
    const STATUS_API = (orderNumber: string) =>
        `/api/plugins/sirsoft-pay_kginicis/admin/orders/${orderNumber}/transaction-status`;
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
        delete (window as unknown as Record<string, unknown>).__kginicisAdminPaymentDisplayInjectorInstalled;
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
