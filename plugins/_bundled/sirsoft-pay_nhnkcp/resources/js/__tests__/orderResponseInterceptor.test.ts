/**
 * 주문 생성 인터셉터 테스트 — 이슈 #475
 *
 * 확장 결제수단이 1급 시민이 되면서 인터셉터의 우회(요청 위장 · 응답 변조 · pgPaymentData
 * 재구성)가 모두 제거됐다. 남은 책임은 서버가 알 수 없는 클라이언트 디바이스 조건 하나뿐:
 * Apple Pay 는 iOS 모바일에서만 동작한다.
 *
 * 회귀 방지 대상:
 *   - payment_method 를 'card' 로 위장하면 서버가 간편결제 주문을 "PG 결제가 아닌 주문"
 *     으로 오인해 관리자 알림 오발송 + 임시주문 삭제(재결제 불가)가 재발한다.
 *   - 응답의 requires_pg_payment / redirect_url 을 변조하면 템플릿의
 *     pg_payment_handler dispatch 분기가 발화하지 않는다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installOrderResponseInterceptor } from '../orderResponseInterceptor';

const ORDER_CREATE_URL = '/api/modules/sirsoft-ecommerce/user/orders';

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

/** 서버 주문 생성 응답(정상 PG 결제 주문) */
function orderCreateResponse(): Response {
    return new Response(JSON.stringify({
        success: true,
        data: {
            order: { order_number: 'ORD-1' },
            redirect_url: '/shop/orders/ORD-1/complete',
            requires_pg_payment: true,
            pg_provider: 'sirsoft-nhnkcp',
            pg_payment_handler: 'sirsoft-pay_nhnkcp.requestPayment',
            pg_payment_data: { order_number: 'ORD-1', amount: 1000, payment_method: 'nhnkcp_naverpay' },
        },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
}

describe('installOrderResponseInterceptor', () => {
    beforeEach(() => {
        document.documentElement.lang = 'ko';
        window.history.pushState({}, '', '/shop/checkout');
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        const w = windowRecord();
        delete w['__sirsoftNhnkcpInterceptorInstalled'];
        delete w['G7Core'];
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('간편결제 payment_method 를 card 로 위장하지 않고 원본 그대로 전송한다', async () => {
        let sentBody = '';
        window.fetch = vi.fn().mockImplementation(async (_input, init?: RequestInit) => {
            sentBody = String(init?.body ?? '');
            return orderCreateResponse();
        });

        installOrderResponseInterceptor();

        await window.fetch(ORDER_CREATE_URL, {
            method: 'POST',
            body: JSON.stringify({ payment_method: 'nhnkcp_naverpay' }),
        });

        // 위장이 부활하면 서버가 PG 결제 주문으로 인식하지 못해 #475 가 재발한다.
        expect(JSON.parse(sentBody).payment_method).toBe('nhnkcp_naverpay');
        expect(sentBody).not.toContain('"payment_method":"card"');
    });

    it('서버 응답을 변조하지 않는다 (requires_pg_payment / redirect_url 보존)', async () => {
        window.fetch = vi.fn().mockResolvedValue(orderCreateResponse());

        installOrderResponseInterceptor();

        const response = await window.fetch(ORDER_CREATE_URL, {
            method: 'POST',
            body: JSON.stringify({ payment_method: 'nhnkcp_naverpay' }),
        });
        const body = await response.json();

        // 응답을 변조하면 템플릿의 pg_payment_handler dispatch 분기가 발화하지 않는다.
        expect(body.data.requires_pg_payment).toBe(true);
        expect(body.data.redirect_url).toBe('/shop/orders/ORD-1/complete');
        expect(body.data.pg_payment_handler).toBe('sirsoft-pay_nhnkcp.requestPayment');
    });

    it('iOS 모바일 기기가 아닌 Apple Pay 주문은 서버 전송 전에 차단한다', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(orderCreateResponse());
        window.fetch = fetchSpy;

        // 데스크톱 UA (iOS 아님)
        vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        );

        installOrderResponseInterceptor();

        const response = await window.fetch(ORDER_CREATE_URL, {
            method: 'POST',
            body: JSON.stringify({ payment_method: 'nhnkcp_applepay' }),
        });

        expect(response.status).toBe(422);
        expect(fetchSpy).not.toHaveBeenCalled();

        const body = await response.json();
        expect(body.errors.payment_method).toBeDefined();
    });

    it('주문 생성 외의 요청은 그대로 통과시킨다', async () => {
        const originalResponse = new Response('{}', { status: 200 });
        const fetchSpy = vi.fn().mockResolvedValue(originalResponse);
        window.fetch = fetchSpy;

        installOrderResponseInterceptor();

        const response = await window.fetch('/api/modules/sirsoft-ecommerce/checkout', { method: 'GET' });

        expect(response).toBe(originalResponse);
        expect(fetchSpy).toHaveBeenCalledTimes(1);
    });
});
