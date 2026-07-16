/**
 * 주문 생성 인터셉터 테스트 — 이슈 #475
 *
 * 확장 결제수단이 1급 시민이 되면서 인터셉터의 우회가 전부 제거됐다:
 *   - payment_method 가 'kginicis_*' 이면 'card' 로 위장 (서버 검증이 확장 ID 를 422 로 막음)
 *   - 응답의 requires_pg_payment / redirect_url 변조 (navigate-to-self 강제)
 *   - pg_payment_data 재구성
 *   - navigate-to-self 로 인한 체크아웃 재렌더(입력값 소실)를 막는 navigate suppressor
 *
 * 위장이 서버로 하여금 간편결제 주문을 "PG 결제가 아닌 주문" 으로 오인하게 만들어
 * (a) 결제 실패 시 관리자 알림 오발송 (b) 임시주문 삭제 → 재결제 불가 를 일으켰다.
 *
 * 이제 결제창 진입은 서버 응답의 pg_payment_handler 를 템플릿이 dispatch 하는 경로로
 * 처리된다. 템플릿의 PG 분기는 navigate 를 하지 않으므로 suppressor 도 불필요하다.
 * 원본 결제수단(예: 'kginicis_lpay')은 서버가 pg_payment_data.payment_method 로 내려준다.
 *
 * 본 테스트는 인터셉터가 fetch / 라우터를 일절 건드리지 않음(no-op)을 회귀 방지로 고정한다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installOrderResponseInterceptor } from '../orderResponseInterceptor';

const ORDER_CREATE_URL = '/api/modules/sirsoft-ecommerce/user/orders';

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

describe('installOrderResponseInterceptor', () => {
    beforeEach(() => {
        window.history.replaceState({}, '', '/shop/checkout');
        vi.spyOn(console, 'info').mockImplementation(() => {});
    });

    afterEach(() => {
        const w = windowRecord();
        delete w['__templateApp'];
        vi.restoreAllMocks();
    });

    it('window.fetch 를 래핑하지 않는다 (no-op)', () => {
        const originalFetch = vi.fn();
        window.fetch = originalFetch as unknown as typeof fetch;

        installOrderResponseInterceptor();

        expect(window.fetch).toBe(originalFetch);
    });

    it('간편결제 payment_method 를 card 로 위장하지 않고 원본 그대로 전송한다', async () => {
        let sentBody = '';
        window.fetch = vi.fn().mockImplementation(async (_input: unknown, init?: RequestInit) => {
            sentBody = String(init?.body ?? '');
            return new Response('{}', { status: 200 });
        }) as unknown as typeof fetch;

        installOrderResponseInterceptor();

        await window.fetch(ORDER_CREATE_URL, {
            method: 'POST',
            body: JSON.stringify({ payment_method: 'kginicis_lpay' }),
        });

        // 위장이 부활하면 서버가 PG 결제 주문으로 인식하지 못해 #475 가 재발한다.
        expect(JSON.parse(sentBody).payment_method).toBe('kginicis_lpay');
    });

    it('주문 생성 응답을 변조하지 않는다', async () => {
        const serverBody = {
            success: true,
            data: {
                order: { order_number: 'ORD-1' },
                redirect_url: '/shop/orders/ORD-1/complete',
                requires_pg_payment: true,
                pg_provider: 'sirsoft-kginicis',
                pg_payment_handler: 'sirsoft-pay_kginicis.requestPayment',
            },
        };
        window.fetch = vi.fn().mockResolvedValue(
            new Response(JSON.stringify(serverBody), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            })
        ) as unknown as typeof fetch;

        installOrderResponseInterceptor();

        const response = await window.fetch(ORDER_CREATE_URL, { method: 'POST', body: '{}' });
        const body = await response.json();

        expect(body.data.requires_pg_payment).toBe(true);
        expect(body.data.redirect_url).toBe('/shop/orders/ORD-1/complete');
        expect(body.data.pg_payment_handler).toBe('sirsoft-pay_kginicis.requestPayment');
    });

    it('라우터의 navigate 를 가로채지 않는다 (suppressor 제거)', () => {
        const navigate = vi.fn();
        windowRecord()['__templateApp'] = {
            getRouter: () => ({ navigate }),
        };

        installOrderResponseInterceptor();

        const router = (windowRecord()['__templateApp'] as { getRouter: () => { navigate: unknown } }).getRouter();

        // suppressor 가 부활하면 navigate 가 패치된 함수로 교체된다.
        expect(router.navigate).toBe(navigate);
    });
});
