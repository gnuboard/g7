// e2e:allow 실제 엔진 구조(TemplateApp.handleRouteChange·renderTemplate·DynamicRenderer·DataSourceManager·ActionDispatcher)에 하네스 레이아웃을 올려 SPA 전환과 첫 진입의 결과를 견준다 — 브라우저 축은 총괄의 번들 재빌드 뒤 매트릭스(마이페이지 → 주문 상세 → 클레임 신청, 신청 내역 → 신청 상세)가 덮는다
/**
 * @file TemplateApp.blockingOnSuccessScreenChange.test.tsx
 * @description 다른 화면으로 옮길 때 새 화면 blocking 데이터소스의 onSuccess 결과(`_local` 쓰기, 그 안의 다시 받기)가 살아남는다 (engine-v1.95.0)
 *
 * 종전 엔진은 blocking 데이터소스를 받는 자리에서 곧바로 onSuccess 를 돌렸다. 그 시점은 아직 앞 화면 상태 위라,
 * 다른 화면으로 옮기는 SPA 전환에서는 그 뒤 `_local` 초기화(레이아웃 전환)와 렌더 문맥 재구성이 결과를 모두 버렸다.
 * 첫 진입은 비울 앞 화면이 없어 결과가 남으므로, 같은 주소를 바로 열면 되고 SPA 로 들어오면 빈 화면이 됐다.
 *  - 클레임 신청 꼴: 주문(blocking) onSuccess 가 주문 ID 를 `_local` 에 세우고 신청 가능 목록을 다시 받아 상품 줄을 세운다
 *    → SPA 진입이면 「신청할 수 있는 상품이 없습니다」, 주문 ID 빈 값(#869 λ 4부 25 관찰).
 *  - 신청 상세 꼴: 신청서(blocking) onSuccess 가 주문번호를 세우고 원 주문(auto_fetch false)을 다시 받는다
 *    → SPA 진입이면 원 주문 카드가 받는 중(undefined)으로 남는다.
 * 고친 뒤에는 다른 화면으로 옮기는 전환에서 blocking onSuccess 를 새 화면 `_local` 을 세운 뒤(레이아웃 initLocal 다음,
 * init_actions 앞)로 미루고, 그 안의 다시 받기 결과를 첫 렌더 문맥에 싣는다.
 *
 * 하네스: 실물 `TemplateApp.handleRouteChange`(레이아웃 로더만 대역) · 실물 `renderTemplate`·`DynamicRenderer`·
 * `DataSourceManager`·`ActionDispatcher`. 번들 레이아웃 id 에 기대지 않는다.
 *
 * @scenario-manifest tests/scenarios/blocking-onsuccess-screen-change.yaml
 *
 * 잰 효과는 각 시험의 주석에 적었다(매니페스트 effects 6종).
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import { waitFor } from '@testing-library/react';

/** API 대역 — 엔드포인트(앞 `/api` 를 뗀 값)로 응답을 정한다 */
const net = vi.hoisted(() => ({
    calls: [] as string[],
}));

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({
        get: (url: string) => {
            net.calls.push(url);
            const body = respond(url);
            // 실패 응답 — axios 오류 꼴({ response: { status, data } })로 거절한다
            if (body && body.__reject) return Promise.reject(Object.assign(new Error('Request failed'), { response: body.__reject }));
            // 끝나지 않는 요청(응답 없는 서버)
            if (body && body.__hang) return new Promise(() => {});

            return Promise.resolve(body);
        },
        post: vi.fn().mockResolvedValue({}),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue('t'),
        setOnUnauthorized: vi.fn(),
    }),
}));

import { TemplateApp } from '../../TemplateApp';
import * as engine from '../../template-engine';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { ComponentRegistry } from '../ComponentRegistry';
import { AuthManager } from '../../auth/AuthManager';

type Node = Record<string, any>;

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('data-') || ['id', 'role'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

const src = (id: string, endpoint: string, extra: Node = {}): Node => ({ id, type: 'api', endpoint, auth_mode: 'required', loading_strategy: 'progressive', ...extra });
const span = (testid: string, text: string): Node => ({ id: `s_${testid}`, type: 'basic', name: 'Span', props: { 'data-testid': testid }, text });
const root = (testid: string, children: Node[]): Node[] => [{ id: `root_${testid}`, type: 'basic', name: 'Div', props: { 'data-testid': testid }, children }];

const LAYOUTS: Record<string, Node> = {
    // 앞 화면(마이페이지 꼴) — init_actions 로 `_local` 에 값을 남긴다(새 화면으로 새지 않아야 한다)
    start: {
        layout_name: 'start',
        data_sources: [],
        init_actions: [{ handler: 'setState', params: { target: 'local', leftover: 'start-only' } }],
        components: root('root-s', [span('s-left', "{{'left:' + (_local.leftover ?? 'none')}}")]),
    },
    // 클레임 신청 꼴 — 주문(blocking) onSuccess 가 주문 ID 를 세우고 신청 가능 목록(auto_fetch false)을 다시 받으며,
    // 그 onSuccess 가 상품 줄을 `_local` 에 세운다. 레이아웃 initLocal 과 init_actions 도 함께 둔다(순서 확인용)
    apply_like: {
        layout_name: 'apply_like',
        data_sources: [
            src('order', '/api/test/orders/{{route.order_number}}', {
                loading_strategy: 'blocking',
                cache: false,
                refetchOnMount: true,
                // 번들 신청 셸과 같은 initLocal — 응답 본문이 아니라 주문 객체(data)를 기준으로 읽는다
                initLocal: { claimOrderId: 'id', claimApiBase: "{{'/api/test/orders/' + data.id}}" },
                onSuccess: [{
                    handler: 'sequence',
                    actions: [
                        { handler: 'setState', params: { target: '_local', claimOrderId: '{{response.data.data.id}}', note: 'from-success' } },
                        { handler: 'refetchDataSource', params: { dataSourceId: 'claimable' } },
                    ],
                }],
            }),
            src('claimable', '/api/test/orders/{{_local.claimOrderId ?? 0}}/claimable', {
                auto_fetch: false,
                onSuccess: [{ handler: 'setState', params: { target: '_local', claimItems: '{{response.data.data.options}}' } }],
            }),
        ],
        initLocal: { note: 'layout-default', shell: 'member' },
        init_actions: [{ handler: 'setState', params: { target: 'local', initRan: true } }],
        components: root('root-ap', [
            span('ap-id', "{{'id:' + (_local.claimOrderId ?? 'none')}}"),
            span('ap-items', "{{'items:' + (_local.claimItems ?? []).length}}"),
            span('ap-note', "{{'note:' + (_local.note ?? 'none') + ' shell:' + (_local.shell ?? 'none')}}"),
            span('ap-seen', "{{'init:' + (_local.initRan === true) + ' base:' + (_local.claimApiBase ?? 'none')}}"),
            span('ap-left', "{{'left:' + (_local.leftover ?? 'none')}}"),
            span('ap-claimable', "{{claimable === undefined ? 'claimable-pending' : 'claimable:' + (claimable?.data?.options ?? []).length}}"),
        ]),
    },
    // 신청 상세 꼴 — 신청서(blocking) onSuccess 가 주문번호를 세우고 원 주문(auto_fetch false)을 다시 받는다. init_actions 없음
    show_like: {
        layout_name: 'show_like',
        data_sources: [
            src('claim', '/api/test/claims/{{route.claim_number}}', {
                loading_strategy: 'blocking',
                onSuccess: [{
                    handler: 'sequence',
                    actions: [
                        { handler: 'setState', params: { target: '_local', claimOrderNumber: '{{response.data.data.claim.order_number}}' } },
                        { handler: 'refetchDataSource', params: { dataSourceId: 'order' } },
                    ],
                }],
            }),
            src('order', '/api/test/orders/{{_local.claimOrderNumber ?? 0}}', { auto_fetch: false }),
        ],
        components: root('root-sh', [
            span('sh-order', "{{order === undefined ? 'order-pending' : 'order:' + (order?.data?.id ?? 'none')}}"),
            span('sh-number', "{{'number:' + (_local.claimOrderNumber ?? 'none')}}"),
        ]),
    },
    // blocking onSuccess 안 다시 받기가 끝나지 않는 화면 — 그동안 다른 이동이 들어오면 물러나야 한다
    hang_like: {
        layout_name: 'hang_like',
        data_sources: [
            src('order', '/api/test/orders/{{route.order_number}}', {
                loading_strategy: 'blocking',
                onSuccess: [{ handler: 'refetchDataSource', params: { dataSourceId: 'slow' } }],
            }),
            src('slow', '/api/test/slow', { auto_fetch: false }),
        ],
        components: root('root-hg', [span('hg', 'hang-screen')]),
    },
};

/** 서버 응답 — 모양은 실제 주문·신청 API 꼴(`{ success, data }`) */
function respond(url: string): any {
    if (url === '/test/orders/O-481') return { success: true, data: { id: 481, order_number: 'O-481' } };
    if (url === '/test/orders/481') return { success: true, data: { id: 481 } };
    if (url === '/test/orders/481/claimable') return { success: true, data: { options: [11, 12] } };
    if (url === '/test/claims/RT-1') return { success: true, data: { claim: { order_number: '481' } } };
    if (url.startsWith('/test/orders/0')) return { __reject: { status: 404, data: { message: 'none' } } };
    if (url === '/test/slow') return { __hang: true };

    return { success: true, data: {} };
}

interface RouteSpec {
    path: string;
    layout: string;
    params?: Record<string, string>;
}

const ROUTES = {
    start: { path: '/mypage', layout: 'start' },
    apply: { path: '/mypage/orders/O-481/claims/apply/return', layout: 'apply_like', params: { order_number: 'O-481', type: 'return' } },
    show: { path: '/mypage/claims/RT-1', layout: 'show_like', params: { claim_number: 'RT-1' } },
    hang: { path: '/mypage/orders/O-481/hang', layout: 'hang_like', params: { order_number: 'O-481' } },
} satisfies Record<string, RouteSpec>;

const settle = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));
const waitLong = <T,>(cb: () => T): Promise<T> => waitFor(cb, { timeout: 10_000, interval: 15 });
vi.setConfig({ testTimeout: 60_000 });
const text = (testid: string): string | null => document.querySelector(`[data-testid="${testid}"]`)?.textContent ?? null;

/**
 * 실물 엔진을 올리고 첫 화면으로 들어간다.
 *
 * @param first 첫 화면
 * @returns 앱과 이동 함수
 */
async function mount(first: RouteSpec): Promise<{ app: any; go: (r: RouteSpec) => Promise<void> }> {
    document.body.innerHTML = '<div id="app"></div>';
    net.calls = [];
    vi.spyOn(AuthManager.getInstance(), 'isAuthenticated').mockReturnValue(true);
    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
    (ComponentRegistry.getInstance() as any).registry = {
        Div: { component: passthrough('div'), metadata: {} },
        Span: { component: passthrough('span'), metadata: {} },
    };
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));
    (app as any).layoutLoader = {
        loadLayout: async (_templateId: string, layoutPath: string) => {
            const key = layoutPath.replace(/^[^.]+\./, '');

            return JSON.parse(JSON.stringify({ version: '1.0.0', ...LAYOUTS[key] }));
        },
    };
    // Router.executeNavigation 과 같은 순서: pushState → 화면 전환
    const go = async (r: RouteSpec): Promise<void> => {
        window.history.pushState({}, '', r.path);
        await (app as any).handleRouteChange({ path: r.path, layout: r.layout, params: r.params ?? {}, query: {} });
    };
    await go(first);
    await settle(30);

    return { app, go };
}

describe('[case:state-global-blocking-onsuccess-lost-on-screen-change] 다른 화면으로 옮길 때 blocking onSuccess 의 `_local` 쓰기와 다시 받기 결과가 사라지지 않는다 (engine-v1.95.0)', () => {
    afterEach(async () => {
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * 기준 — 클레임 신청 꼴 주소를 바로 연다. 종전에도 통과(비울 앞 화면이 없다).
     *
     * @scenario case=apply_like_direct_entry
     * @effects direct_entry_unchanged, layout_initlocal_and_init_actions_order_kept
     */
    it('바로 열면 주문 ID 와 상품 줄이 선다(종전과 같음)', async () => {
        await mount(ROUTES.apply);
        await waitLong(() => expect(text('ap-id')).toBe('id:481'));
        await waitLong(() => expect(text('ap-items')).toBe('items:2'));
        // onSuccess 가 쓴 값이 레이아웃 initLocal 기본값을 이기고(빈 키만 채움), init_actions 와 데이터소스 initLocal 도 선다
        expect(text('ap-note')).toBe('note:from-success shell:member');
        expect(text('ap-seen')).toBe('init:true base:/api/test/orders/481');
    });

    /**
     * 마이페이지 → 클레임 신청 꼴(SPA). 종전: 「id:none」「items:0」(λ 4부 25 관찰과 같은 꼴).
     *
     * @scenario case=apply_like_spa_entry
     * @effects blocking_onsuccess_local_survives_spa, blocking_onsuccess_refetch_data_survives_spa, previous_screen_local_not_carried, layout_initlocal_and_init_actions_order_kept
     */
    it('SPA 로 들어와도 바로 연 것과 같이 주문 ID 와 상품 줄이 서고, 다시 받은 목록도 첫 렌더에 있다', async () => {
        const { go } = await mount(ROUTES.start);
        await waitLong(() => expect(text('s-left')).toBe('left:start-only'));

        await go(ROUTES.apply);
        await waitLong(() => expect(document.querySelector('[data-testid="root-ap"]')).not.toBeNull());
        await waitLong(() => expect(text('ap-id')).toBe('id:481'));
        await waitLong(() => expect(text('ap-items')).toBe('items:2'));
        await waitLong(() => expect(text('ap-claimable')).toBe('claimable:2'));
        // 바로 연 것과 같은 결과 — onSuccess 값이 레이아웃 기본값을 이기고, init_actions 와 데이터소스 initLocal 도 선다
        expect(text('ap-note')).toBe('note:from-success shell:member');
        expect(text('ap-seen')).toBe('init:true base:/api/test/orders/481');
        // 앞 화면 `_local` 은 새 화면으로 넘어오지 않는다(레이아웃 전환 초기화는 그대로)
        expect(text('ap-left')).toBe('left:none');
        // 신청 가능 목록은 주문 ID 를 실은 주소로 한 번만 나간다(0 주소로 새지 않음)
        expect(net.calls.filter((u) => u.includes('/claimable'))).toEqual(['/test/orders/481/claimable']);
    });

    /**
     * 기준 — 신청 상세 꼴 주소를 바로 연다.
     *
     * @scenario case=show_like_direct_entry
     * @effects direct_entry_unchanged
     */
    it('신청 상세 꼴을 바로 열면 원 주문이 선다(종전과 같음)', async () => {
        await mount(ROUTES.show);
        await waitLong(() => expect(text('sh-order')).toBe('order:481'));
        expect(text('sh-number')).toBe('number:481');
    });

    /**
     * 신청 내역(앞 화면) → 신청 상세 꼴(SPA). 종전: 「order-pending」「number:none」. init_actions 없는 화면이다.
     *
     * @scenario case=show_like_spa_entry
     * @effects blocking_onsuccess_local_survives_spa, blocking_onsuccess_refetch_data_survives_spa
     */
    it('init_actions 없는 화면도 SPA 로 들어오면 onSuccess 가 다시 받은 원 주문과 주문번호가 첫 렌더에 있다', async () => {
        const { go } = await mount(ROUTES.start);
        await waitLong(() => expect(text('s-left')).toBe('left:start-only'));

        await go(ROUTES.show);
        await waitLong(() => expect(document.querySelector('[data-testid="root-sh"]')).not.toBeNull());
        await waitLong(() => expect(text('sh-order')).toBe('order:481'));
        await waitLong(() => expect(text('sh-number')).toBe('number:481'));
    });

    /**
     * 신청 상세 → 클레임 신청(서로 다른 blocking onSuccess 화면 사이) → 다시 신청 상세. 앞 화면 onSuccess 값이 섞이지 않는다.
     *
     * @scenario case=onsuccess_screen_to_onsuccess_screen
     * @effects blocking_onsuccess_local_survives_spa, previous_screen_local_not_carried
     */
    it('onSuccess 화면끼리 오가도 각 화면 값만 선다', async () => {
        const { go } = await mount(ROUTES.show);
        await waitLong(() => expect(text('sh-order')).toBe('order:481'));

        await go(ROUTES.apply);
        await waitLong(() => expect(document.querySelector('[data-testid="root-ap"]')).not.toBeNull());
        await waitLong(() => expect(text('ap-items')).toBe('items:2'));
        expect((window as any).__templateApp.getGlobalState()._local.claimOrderNumber).toBeUndefined();

        await go(ROUTES.show);
        await waitLong(() => expect(document.querySelector('[data-testid="root-sh"]')).not.toBeNull());
        await waitLong(() => expect(text('sh-order')).toBe('order:481'));
        expect((window as any).__templateApp.getGlobalState()._local.claimItems).toBeUndefined();
    });

    /**
     * onSuccess 안 다시 받기가 끝나지 않는 화면으로 옮기는 중 다음 이동이 들어온다. 받기 안에서 돌던 종전처럼 물러나야 한다.
     *
     * @scenario case=deferred_onsuccess_superseded
     * @effects deferred_onsuccess_yields_to_next_navigation
     */
    it('미룬 onSuccess 가 끝나지 않아도 다음 이동이 들어오면 물러나고 다음 화면이 선다', async () => {
        const { app, go } = await mount(ROUTES.start);
        await waitLong(() => expect(text('s-left')).toBe('left:start-only'));

        let settled = false;
        const hanging = go(ROUTES.hang).then(() => { settled = true; });
        await waitLong(() => expect(net.calls).toContain('/test/slow'));
        // Router 가 진행 중 이동 뒤에 새 이동을 대기열에 넣을 때 부르는 신호
        (app as any).supersedeRouteChange();
        await waitLong(() => expect(settled).toBe(true));
        await hanging;
        expect(text('hg')).toBeNull();

        await go(ROUTES.apply);
        await waitLong(() => expect(text('ap-items')).toBe('items:2'));
    });
});
