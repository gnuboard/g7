/**
 * 플러그인 엔트리는 코어 함수를 덮어쓰지 않고 핸들러만 등록한다 (NHN KCP)
 *
 * 예전 엔트리는 주문 생성 요청을 가로채려고 window.fetch 를, 화면 이동을 엿보려고 history.pushState·replaceState
 * 를 덮어썼다. 확장끼리 서로를 감싸 순서에 따라 동작이 바뀌고, 엔진 내부가 바뀌면 조용히 깨진다. 화면 표시는
 * 레이아웃 조각이 맡고, 애플페이 기기 제한은 결제수단 카탈로그(requires_ios)가 맡는다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/order-screen-fragments.yaml
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type Registered = Array<{ name: string; options: Record<string, unknown> }>;

let registered: Registered;
let post: ReturnType<typeof vi.fn>;

/**
 * 공개 API 만 가진 코어를 꾸민다.
 */
function installCore(): void {
    registered = [];
    post = vi.fn().mockResolvedValue({ success: true });
    (window as any).G7Core = {
        getActionDispatcher: () => ({
            registerHandler: (name: string, _handler: unknown, options: Record<string, unknown>) => {
                registered.push({ name, options });
            },
        }),
        api: { post },
        state: { get: () => ({}), subscribe: () => () => undefined },
    };
}

beforeEach(() => {
    vi.resetModules();
    installCore();
    sessionStorage.clear();
});

afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__SirsoftNhnkcp;
    delete (window as any).KcpReceiptPopup;
});

describe('NHN KCP 플러그인 엔트리', () => {
    // @scenario case=entry
    // @effects no_core_function_override
    it('불러와도 window.fetch · history.pushState · history.replaceState 를 그대로 둔다', async () => {
        const fetchBefore = window.fetch;
        const pushBefore = history.pushState;
        const replaceBefore = history.replaceState;

        await import('../index');

        expect(window.fetch).toBe(fetchBefore);
        expect(history.pushState).toBe(pushBefore);
        expect(history.replaceState).toBe(replaceBefore);
    });

    // @scenario case=entry
    // @effects handlers_registered_and_reinit_exposed
    it('핸들러를 플러그인 이름공간으로 등록하고, 재등록 진입점은 등록만 다시 한다', async () => {
        await import('../index');

        expect(registered.map((r) => r.name).sort()).toEqual([
            'sirsoft-pay_nhnkcp.copyToClipboard',
            'sirsoft-pay_nhnkcp.requestPayment',
            'sirsoft-pay_nhnkcp.setPaymentMethod',
        ]);
        expect(typeof (window as any).KcpReceiptPopup).toBe('function');

        // 결제 실패 복귀 보고 대상이 있어도 재등록 진입점은 보고를 다시 보내지 않는다.
        sessionStorage.setItem('g7:sirsoft-pay_nhnkcp:pendingClose', JSON.stringify({
            closeReportUrl: 'https://shop.example/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            oid: 'ORD-1',
            price: 1,
        }));
        registered = [];
        (window as any).__SirsoftNhnkcp.initPlugin();

        expect(registered).toHaveLength(3);
        expect(post).not.toHaveBeenCalled();
    });
});
