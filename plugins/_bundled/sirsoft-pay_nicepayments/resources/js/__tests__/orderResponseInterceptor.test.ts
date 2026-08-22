/**
 * 주문 생성 인터셉터 테스트 — 이슈 #475
 *
 * 확장 결제수단이 1급 시민이 되면서 인터셉터의 우회가 전부 제거됐다:
 *   - payment_method 를 'card' 로 위장 (서버 검증이 확장 ID 를 422 로 막았기 때문)
 *   - 응답의 requires_pg_payment / redirect_url 변조 (navigate-to-self 강제)
 *   - pg_payment_data 재구성
 *   - navigate-to-self 로 인한 "주문서 없음" 다이얼로그를 막기 위한 GET checkout 캐싱
 *
 * 위장이 서버로 하여금 간편결제 주문을 "PG 결제가 아닌 주문" 으로 오인하게 만들어
 * (a) 결제 실패 시 관리자 알림 오발송 (b) 임시주문 삭제 → 재결제 불가 를 일으켰다.
 *
 * 이제 결제창 진입은 서버 응답의 pg_payment_handler 를 템플릿이 dispatch 하는 경로로
 * 처리되고, 템플릿의 PG 분기는 navigate 를 하지 않으므로 캐싱도 불필요하다.
 *
 * 본 테스트는 인터셉터가 fetch 를 일절 건드리지 않음(no-op)을 회귀 방지로 고정한다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installOrderResponseInterceptor } from '../orderResponseInterceptor';

const ORDER_CREATE_URL = '/api/modules/sirsoft-ecommerce/user/orders';
const CHECKOUT_URL = '/api/modules/sirsoft-ecommerce/checkout';

describe('installOrderResponseInterceptor', () => {
    beforeEach(() => {
        window.history.replaceState({}, '', '/shop/checkout');
        vi.spyOn(console, 'info').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('window.fetch 를 래핑하지 않는다 (no-op)', () => {
        const originalFetch = vi.fn();
        window.fetch = originalFetch as unknown as typeof fetch;

        installOrderResponseInterceptor();

        // 래핑하면 다른 함수 참조가 된다 — 동일 참조여야 한다.
        expect(window.fetch).toBe(originalFetch);
    });

    it('주문 생성 요청의 payment_method 를 위장하지 않는다', async () => {
        let sentBody = '';
        window.fetch = vi.fn().mockImplementation(async (_input: unknown, init?: RequestInit) => {
            sentBody = String(init?.body ?? '');
            return new Response('{}', { status: 200 });
        }) as unknown as typeof fetch;

        installOrderResponseInterceptor();

        await window.fetch(ORDER_CREATE_URL, {
            method: 'POST',
            body: JSON.stringify({ payment_method: 'nicepay_naverpay' }),
        });

        expect(JSON.parse(sentBody).payment_method).toBe('nicepay_naverpay');
    });

    it('주문 생성 응답을 변조하지 않는다', async () => {
        const serverBody = {
            success: true,
            data: {
                order: { order_number: 'ORD-1' },
                redirect_url: '/shop/orders/ORD-1/complete',
                requires_pg_payment: true,
                pg_provider: 'sirsoft-nicepayments',
                pg_payment_handler: 'sirsoft-pay_nicepayments.requestPayment',
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
        expect(body.data.pg_payment_handler).toBe('sirsoft-pay_nicepayments.requestPayment');
    });

    it('GET checkout 응답을 캐싱하거나 가로채지 않는다', async () => {
        const checkoutResponse = new Response('{"data":{}}', { status: 200 });
        const fetchSpy = vi.fn().mockResolvedValue(checkoutResponse);
        window.fetch = fetchSpy as unknown as typeof fetch;

        installOrderResponseInterceptor();

        const first = await window.fetch(CHECKOUT_URL, { method: 'GET' });
        const second = await window.fetch(CHECKOUT_URL, { method: 'GET' });

        // 캐시 서빙이 부활하면 두 번째 호출이 네트워크로 나가지 않는다.
        expect(fetchSpy).toHaveBeenCalledTimes(2);
        expect(first).toBe(checkoutResponse);
        expect(second).toBe(checkoutResponse);
    });
});
