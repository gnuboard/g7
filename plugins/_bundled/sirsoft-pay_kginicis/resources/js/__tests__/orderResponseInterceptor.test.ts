/**
 * 주문 응답 인터셉터 회귀 테스트
 *
 * KG 결제창을 띄운 뒤 체크아웃 템플릿의 fallback navigate 가 현재 경로를 다시
 * 렌더링하면 배송지/주문자 입력 상태가 초기화된다. 인터셉터가 그 자기 경로
 * navigate 1회만 차단하는지 검증한다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { requestPaymentHandler } from '../handlers/requestPayment';
import { installOrderResponseInterceptor } from '../orderResponseInterceptor';

vi.mock('../handlers/requestPayment', () => ({
    requestPaymentHandler: vi.fn().mockResolvedValue(undefined),
}));

interface MutatedOrderResponse {
    data?: {
        redirect_url?: string;
        requires_pg_payment?: boolean;
    };
}

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function installTemplateRouter(navigate: ReturnType<typeof vi.fn>): { navigate: (path: string, options?: unknown) => unknown } {
    const router = { navigate };
    windowRecord()['__templateApp'] = { getRouter: () => router };
    return router;
}

function mockOrderCreateFetch(): ReturnType<typeof vi.fn> {
    const responseBody = {
        success: true,
        data: {
            order: { order_number: 'ORD-001' },
            redirect_url: '/shop/orders/ORD-001/complete',
            requires_pg_payment: true,
            pg_provider: 'sirsoft-kginicis',
            pg_payment_data: {
                order_number: 'ORD-001',
                order_name: '테스트 주문',
                amount: 10000,
            },
        },
    };

    const fetchSpy = vi.fn().mockResolvedValue(
        new Response(JSON.stringify(responseBody), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }),
    );

    window.fetch = fetchSpy as unknown as typeof fetch;
    return fetchSpy;
}

async function createOrder(): Promise<MutatedOrderResponse> {
    const response = await window.fetch('/api/modules/sirsoft-ecommerce/user/orders', {
        method: 'POST',
        body: JSON.stringify({ payment_method: 'card' }),
    });

    return (await response.json()) as MutatedOrderResponse;
}

describe('installOrderResponseInterceptor', () => {
    beforeEach(() => {
        window.history.pushState({}, '', '/shop/checkout');
        vi.mocked(requestPaymentHandler).mockClear();
        vi.spyOn(console, 'info').mockImplementation(() => {});
    });

    afterEach(() => {
        const w = windowRecord();
        const suppressor = w['__sirsoftKginicisNavigateSuppressor'] as { restore?: () => void } | undefined;
        suppressor?.restore?.();

        delete w['__sirsoftKginicisInterceptorInstalled'];
        delete w['__sirsoftPgOriginalFetch'];
        delete w['__sirsoftKginicisNavigateSuppressor'];
        delete w['__templateApp'];
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('KG 주문 생성 응답 후 체크아웃 자기 경로 navigate 1회를 차단한다', async () => {
        const originalNavigate = vi.fn();
        const router = installTemplateRouter(originalNavigate);
        mockOrderCreateFetch();
        installOrderResponseInterceptor();

        const body = await createOrder();

        expect(requestPaymentHandler).toHaveBeenCalledWith({
            params: {
                pgPaymentData: expect.objectContaining({ order_number: 'ORD-001' }),
                paymentMethod: 'card',
            },
        });
        expect(body.data?.requires_pg_payment).toBe(false);
        expect(body.data?.redirect_url).toBe('/shop/checkout');

        router.navigate(body.data?.redirect_url ?? '');

        expect(originalNavigate).not.toHaveBeenCalled();

        router.navigate('/shop/orders/ORD-001/complete');

        expect(originalNavigate).toHaveBeenCalledWith('/shop/orders/ORD-001/complete');
    });

    it('현재 체크아웃 경로가 아닌 navigate 는 차단하지 않는다', async () => {
        const originalNavigate = vi.fn();
        const router = installTemplateRouter(originalNavigate);
        mockOrderCreateFetch();
        installOrderResponseInterceptor();

        await createOrder();

        router.navigate('/shop/cart', { replace: true });

        expect(originalNavigate).toHaveBeenCalledWith('/shop/cart', { replace: true });
    });
});
