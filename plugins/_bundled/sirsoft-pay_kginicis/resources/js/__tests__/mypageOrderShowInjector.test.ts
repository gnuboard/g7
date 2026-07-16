import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';

const FLAG = '__kginicisOrderShowInjectorInstalled';
const RECEIPT_API = (orderNumber: string) =>
    `/api/plugins/sirsoft-pay_kginicis/user/orders/${orderNumber}/receipt`;

function setPaymentPanel(): void {
    document.body.innerHTML = `
        <section id="order_payment_info_panel">
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <span>결제 방법</span>
                    <span>무통장입금</span>
                </div>
            </div>
        </section>
    `;
}

function setOrderState(data: Record<string, unknown>): void {
    (window as any).G7Core = {
        getState: () => ({ currentDataContext: { order: { data } } }),
    };
}

// 회원 마이페이지 주문 상세 URL 은 /mypage/orders/{주문 ID} (주문번호 아님).
// injector 가 ID 세그먼트를 주문번호로 오인해 영수증 API 를 404 로 반복 폴링하던
// 회귀(2026-06-11 검수 발견)를 잠그는 테스트.
describe('mypageOrderShowInjector — 회원 주문 상세 (URL 세그먼트 = 주문 ID)', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        localStorage.clear();
        sessionStorage.clear();
        vi.useFakeTimers();
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.innerHTML = '';
        delete (window as Record<string, unknown>)[FLAG];
        delete (window as any).G7Core;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('비-kginicis 주문은 ID 세그먼트를 state.id 로 매칭해 영수증 API 호출 없이 종료한다', async () => {
        history.pushState(null, '', '/mypage/orders/495');
        setOrderState({
            id: 495,
            order_number: '20260610-0915410377',
            payment: { pg_provider: null, payment_status: 'pending', transaction_id: null },
        });
        setPaymentPanel();

        const fetchMock = vi.fn();
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        // 초기 schedule(1500ms) + 폴링 상한 30회(400ms 간격)를 모두 지나도록 진행
        await vi.advanceTimersByTimeAsync(16000);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.getElementById('kginicis-mp-receipt-row')).toBeNull();
    });

    it('kginicis 결제 주문은 state 의 실제 주문번호로 영수증을 조회해 행을 붙인다', async () => {
        history.pushState(null, '', '/mypage/orders/498');
        setOrderState({
            id: 498,
            order_number: '20260611-0529322361',
            payment: {
                pg_provider: 'kginicis',
                payment_status: 'paid',
                transaction_id: 'StdpayCARDINIpayTest',
            },
        });
        setPaymentPanel();

        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url === RECEIPT_API('20260611-0529322361')) {
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: async () => ({
                        receipt_type: 'inicis_receipt',
                        receipt_url: 'https://iniweb.inicis.com/receipt',
                        receipt_label: '영수증',
                        receipt_view_label: '영수증 조회',
                        payment_method_display_label: '신용카드',
                    }),
                } as Response);
            }
            // 주문 ID(498) 등 잘못된 식별자로 호출하면 실서버처럼 404
            return Promise.resolve({ ok: false, status: 404, json: async () => ({}) } as Response);
        });
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2500);

        const row = document.getElementById('kginicis-mp-receipt-row');
        expect(row?.textContent?.replace(/\s+/g, '')).toContain('영수증영수증조회');
        expect(fetchMock).toHaveBeenCalled();
        expect(
            fetchMock.mock.calls.every(call => String(call[0]) === RECEIPT_API('20260611-0529322361')),
        ).toBe(true);
    });

    it('order state 를 확보하기 전에는 회원 경로에서 영수증 API 를 호출하지 않는다', async () => {
        history.pushState(null, '', '/mypage/orders/777');
        // G7Core 미존재 = 엔진 state 미준비 상황
        setPaymentPanel();

        const fetchMock = vi.fn();
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(16000);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('회원 경로에서 영수증 API 404 는 확정 응답으로 보고 폴링을 중단한다', async () => {
        history.pushState(null, '', '/mypage/orders/600');
        setOrderState({
            id: 600,
            order_number: 'ORD-600',
            payment: { pg_provider: 'kginicis', payment_status: 'paid', transaction_id: 'TX-600' },
        });
        setPaymentPanel();

        const fetchMock = vi
            .fn()
            .mockResolvedValue({ ok: false, status: 404, json: async () => ({}) } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(16000);

        // 종전 회귀: 400ms 간격 30회 재시도 → 30+ 호출.
        // 같은 파일 내 선행 install 의 history wrapper 가 폴링을 1회 더 기동할 수 있어
        // 정확히 1회 대신 "소수 호출 후 정지" 를 검증한다.
        const callsAfterFirstWindow = fetchMock.mock.calls.length;
        expect(callsAfterFirstWindow).toBeLessThan(5);

        // 추가 시간이 지나도 호출 수가 늘지 않아야 폴링 중단이 입증된다.
        await vi.advanceTimersByTimeAsync(8000);
        expect(fetchMock.mock.calls.length).toBe(callsAfterFirstWindow);
        expect(document.getElementById('kginicis-mp-receipt-row')).toBeNull();
    });
});
