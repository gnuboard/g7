// e2e:allow 실제 엔진 구조(Router 대기열·TemplateApp.handleRouteChange·updateQueryParams·refetchDataSource·renderTemplate·DynamicRenderer·DataSourceManager·G7Core.dispatch)에 하네스 레이아웃을 올려 경로 이동 중 응답 도착·받는 중 표시·다시 받기 실패를 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(글 수정 → 새 글 빠른 이동·응답 지연, 장바구니 선택 해제 500, 같은 목록 쪽 넘김, 상품 수정 → 목록 덮개, 정가 입력 직후 replace)이 덮는다
/**
 * @file TemplateApp.routeSupersedeAndRefresh.test.tsx
 * @description 경로 이동 중 늦은 응답·받는 동안 앞 화면 입력·다시 받기 실패·같은 화면 다시 받는 중 표시·덮개 해제·같은 경로 replace 의 폼 다시 심기 (engine-v1.82.0)
 *
 * 하네스: 실물 `Router`(대기열 — TemplateApp 초기화와 같은 두 구독을 건다) · 실물 `TemplateApp.handleRouteChange`·`updateQueryParams`·
 * `refetchDataSource`(레이아웃 로더만 대역) · 실물 `renderTemplate`·`DynamicRenderer`·`DataSourceManager`·`G7Core.dispatch`·`G7Core.state`.
 * API 응답은 주소별로 잡아 두었다가 푼다. `_local` 은 손으로 채우지 않는다(데이터소스 initLocal·액션·setLocal 만).
 *
 * @scenario-manifest tests/scenarios/route-supersede-and-refresh.yaml
 *
 * @effects queued_navigation_supersedes_in_flight, late_previous_route_response_dropped, previous_screen_input_locked_while_form_replaced, refetch_failure_context_flag, refetch_kept_value_does_not_reseed, same_screen_refresh_blur, render_cycles_not_increased, overlay_released_after_commit, replace_refetch_reseeds_form
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import { waitFor } from '@testing-library/react';

/** API 대역 — 주소별로 응답을 정하고, 잡아 둘 주소는 풀 때까지 기다린다 */
const net = vi.hoisted(() => ({
    held: new Set<string>(),
    pending: new Map<string, Array<() => void>>(),
    calls: [] as string[],
    fail: new Set<string>(),
    respond: (_url: string, _params: any): any => ({ success: true, data: {} }),
}));

vi.mock('../../api/ApiClient', () => {
    const call = (url: string, params: any) => {
        net.calls.push(url);
        if (net.fail.has(url)) return Promise.reject(Object.assign(new Error('Request failed'), { response: { status: 500, data: { message: 'boom' } } }));
        const body = net.respond(url, params ?? {});
        if (!net.held.has(url)) return Promise.resolve(body);

        return new Promise((resolve) => {
            const list = net.pending.get(url) ?? [];
            list.push(() => resolve(body));
            net.pending.set(url, list);
        });
    };

    return {
        getApiClient: () => ({
            get: (url: string, cfg?: { params?: any }) => call(url, cfg?.params),
            post: (url: string, body?: any) => call(url, body),
            removeToken: vi.fn(),
            setToken: vi.fn(),
            getToken: vi.fn().mockReturnValue('t'),
            setOnUnauthorized: vi.fn(),
        }),
    };
});

import { TemplateApp } from '../../TemplateApp';
import { Router } from '../../routing/Router';
import * as engine from '../../template-engine';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { ComponentRegistry } from '../ComponentRegistry';
import { AuthManager } from '../../auth/AuthManager';
import { transitionManager } from '../TransitionManager';
import { DataBindingEngine } from '../DataBindingEngine';

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
const OVERLAY = { enabled: true, style: 'spinner', target: 'content_area' };

/** 베이스 레이아웃 꼴 — 모든 화면이 같은 정의로 받는 소스(메뉴·현재 사용자·알림 수 등). 다른 화면으로 옮겨도 같은 요청이라 이어 쓴다 */
const BASE = ['b_menu', 'b_user', 'b_noti', 'b_cart', 'b_ext', 'b_gdpr'].map((id) => src(id, `/api/test/base/${id}`));
const baseScreen = (name: string, own: string): Node => ({
    layout_name: name,
    data_sources: [...BASE, src(`${name}_own`, `/api/test/own/${own}`)],
    components: [{ id: `root_${name}`, type: 'basic', name: 'Div', props: { 'data-testid': `root-${name}` }, children: [
        { id: `${name}_grid`, type: 'basic', name: 'Div', blur_until_loaded: { enabled: true, data_sources: `${name}_own` }, children: [span(`${name}-own`, `{{${name}_own === undefined ? 'pending' : 'own'}}`)] },
        span(`${name}-menu`, "{{b_menu === undefined ? 'pending' : 'menu'}}"),
    ] }],
});

const LAYOUTS: Record<string, Node> = {
    screen_a: baseScreen('screen_a', 'a'),
    screen_b: baseScreen('screen_b', 'b'),
    home: {
        layout_name: 'home',
        transition_overlay: OVERLAY,
        data_sources: [],
        components: [{ id: 'root_home', type: 'basic', name: 'Div', props: { id: 'content_area' }, children: [span('home', 'home')] }],
    },
    // 게시판 글 폼 꼴 — 수정·새 글이 한 레이아웃, 폼은 blocking 응답 initLocal(form) + 성공 처리 setState(form=응답)
    post_form: {
        layout_name: 'post_form',
        transition_overlay: OVERLAY,
        data_sources: [src('form_data', "/api/test/posts/{{route.id ?? 'new'}}", {
            loading_strategy: 'blocking',
            initLocal: 'form',
            onSuccess: [{ handler: 'setState', params: { target: 'local', form: '{{response.data.data}}' } }],
        })],
        components: [{
            id: 'root_form', type: 'basic', name: 'Div', props: { id: 'content_area', 'data-testid': 'root-form' },
            children: [{
                id: 'form_box', type: 'basic', name: 'Div', blur_until_loaded: true, props: { 'data-testid': 'form-box' },
                children: [
                    span('e-id', "{{'id:' + (_local.form?.id ?? 'none')}}"),
                    span('e-content', "{{'content:' + (_local.form?.content ?? 'none')}}"),
                ],
            }],
        }],
    },
    // 장바구니 꼴 — 선택(initLocal)을 응답에서 받아 두고, 선택을 바꾸면 같은 주소를 다시 받는다. 오류 처리기는 다시 받기/처음 받기를 가른다
    cart: {
        layout_name: 'cart',
        data_sources: [
            src('cartLike', '/api/test/cart', {
                method: 'POST',
                params: { selected: '{{_local.selectedItems}}' },
                initLocal: { selectedItems: 'item_ids' },
                // refetchOnMount — 다시 받을 때마다 initLocal 을 강제로 다시 적용하는 소스(상품·글 폼과 같은 꼴). 해시가 같아도 건너뛰지 않는다
                refetchOnMount: true,
                fallback: { data: { items: [], item_ids: [] } },
                errorHandling: {
                    default: {
                        handler: 'conditions',
                        conditions: [
                            { if: '{{error.refetch}}', then: { handler: 'setState', params: { target: 'global', refetchFailed: '{{error.status}}' } } },
                            { then: { handler: 'setState', params: { target: 'global', firstFailed: '{{error.status}}' } } },
                        ],
                    },
                },
            }),
            // 다시 받기로만 받는 소스(auto_fetch:false — 편집기 확장 본문 꼴). 첫 받기 실패는 다시 받기 실패가 아니다
            src('manualOnly', '/api/test/manual', {
                auto_fetch: false,
                errorHandling: { default: { handler: 'setState', params: { target: 'global', manualFlag: "{{error.refetch === true ? 'refetch' : 'first'}}" } } },
            }),
            src('boomFirst', '/api/test/boom-first', {
                errorHandling: { default: { handler: 'setState', params: { target: 'global', boomRefetchFlag: "{{error.refetch === true ? 'refetch' : 'first'}}" } } },
            }),
        ],
        components: [{ id: 'root_cart', type: 'basic', name: 'Div', props: { 'data-testid': 'root-cart' }, children: [
            span('k-items', "{{cartLike === undefined ? 'pending' : 'items:' + (cartLike?.data?.items ?? []).join(',')}}"),
            span('k-sel', "{{'sel:' + (_local.selectedItems ?? []).join(',')}}"),
        ] }],
    },
    // 같은 목록 쪽 넘김 꼴 — 쪽은 쿼리, 흐림은 이 소스만 본다
    list: {
        layout_name: 'list',
        data_sources: [src('items', '/api/test/items', { params: { page: '{{query.page ?? 1}}' } })],
        components: [{ id: 'root_list', type: 'basic', name: 'Div', props: { 'data-testid': 'root-list' }, children: [{
            id: 'grid', type: 'basic', name: 'Div', blur_until_loaded: { enabled: true, data_sources: 'items' }, props: { 'data-testid': 'grid' },
            children: [span('l-rows', "{{items === undefined ? 'pending' : 'rows:' + items?.data?.page}}")],
        }] }],
    },
    // 상품 목록 꼴 — 덮개(spinner), 목록(빠름)과 보조 소스(느림)
    overlay_list: {
        layout_name: 'overlay_list',
        transition_overlay: OVERLAY,
        data_sources: [src('main', '/api/test/main'), src('aux', '/api/test/aux', { loading_strategy: 'background' }), src('aux2', '/api/test/aux2')],
        components: [{ id: 'root_ol', type: 'basic', name: 'Div', props: { id: 'content_area', 'data-testid': 'root-ol' }, children: [span('ol-main', "{{main === undefined ? 'pending' : 'main:' + main?.data?.n}}")] }],
    },
    // 같은 꼴 + 덮개가 보조 소스를 기다리라고 선언(wait_for) — 그 계약은 그대로
    overlay_wait: {
        layout_name: 'overlay_wait',
        transition_overlay: { ...OVERLAY, wait_for: ['aux2'] },
        data_sources: [src('main', '/api/test/main'), src('aux2', '/api/test/aux2')],
        components: [{ id: 'root_ow', type: 'basic', name: 'Div', props: { id: 'content_area', 'data-testid': 'root-ow' }, children: [span('ow-main', "{{main === undefined ? 'pending' : 'main:' + main?.data?.n}}")] }],
    },
    // 상품 수정 꼴 — 폼은 blocking initLocal(form). 탭은 쿼리(replace), 폼 소스 주소는 탭을 읽지 않는다
    product: {
        layout_name: 'product',
        // refetchOnMount — 실물 상품 폼과 같이 다시 받은 값이 같아도 initLocal 을 강제로 다시 적용하는 소스
        data_sources: [src('product', '/api/test/products/{{route.id}}', { loading_strategy: 'blocking', initLocal: 'form', refetchOnMount: true })],
        components: [{ id: 'root_p', type: 'basic', name: 'Div', props: { 'data-testid': 'root-p' }, children: [span('p-price', "{{'price:' + (_local.form?.list_price ?? 'none')}}")] }],
    },
    // init 이 있고 blocking 이 없는 화면 — 다른 화면과 같은 id(`shared`)를 progressive 로 받는다
    init_list: {
        layout_name: 'init_list',
        data_sources: [src('shared', '/api/test/shared-a')],
        init_actions: [{ handler: 'setState', params: { target: 'local', ready: true } }],
        components: [{ id: 'root_il', type: 'basic', name: 'Div', props: { 'data-testid': 'root-il' }, children: [span('il-shared', "{{shared === undefined ? 'pending' : 'who:' + shared?.data?.who}}")] }],
    },
    // 같은 id 를 blocking 으로 받고 init 이 느린 화면(init 안 다시 받기)
    slow_init: {
        layout_name: 'slow_init',
        data_sources: [src('shared', '/api/test/shared-b', { loading_strategy: 'blocking' }), src('slow', '/api/test/slow', { auto_fetch: false })],
        init_actions: [{ handler: 'refetchDataSource', params: { dataSourceId: 'slow' } }],
        components: [{ id: 'root_si', type: 'basic', name: 'Div', props: { 'data-testid': 'root-si' }, children: [span('si-shared', "{{'who:' + shared?.data?.who}}")] }],
    },
    // 탭마다 다른 설정 묶음을 받는 폼 — 폼 소스 주소가 탭을 읽는다(요청이 바뀌면 종전대로 다시 심는다)
    settings: {
        layout_name: 'settings',
        data_sources: [src('settings', '/api/test/settings/{{query.tab ?? "a"}}', { loading_strategy: 'blocking', initLocal: 'form', refetchOnMount: true })],
        components: [{ id: 'root_s', type: 'basic', name: 'Div', props: { 'data-testid': 'root-s' }, children: [span('s-v', "{{'v:' + (_local.form?.v ?? 'none')}}")] }],
    },
};

const ROUTES = [
    { path: '/', layout: 'home' },
    { path: '/board/b/write', layout: 'post_form' },
    { path: '/board/b/:id/edit', layout: 'post_form' },
    { path: '/shop/cart', layout: 'cart' },
    { path: '/admin/list', layout: 'list' },
    { path: '/admin/overlay-list', layout: 'overlay_list' },
    { path: '/admin/overlay-wait', layout: 'overlay_wait' },
    { path: '/admin/products/:id/edit', layout: 'product' },
    { path: '/admin/settings', layout: 'settings' },
    { path: '/admin/screen-a', layout: 'screen_a' },
    { path: '/admin/screen-b', layout: 'screen_b' },
    { path: '/admin/init-list', layout: 'init_list' },
    { path: '/admin/slow-init', layout: 'slow_init' },
];

/** 서버 응답 */
function respond(url: string, params: any): any {
    if (url === '/test/posts/96') return { success: true, data: { id: 96, content: '앞 글 본문', attachments: [1] } };
    if (url === '/test/posts/new') return { success: true, data: { content: '' } };
    if (url === '/test/cart') {
        const sel = Array.isArray(params.selected) ? params.selected : [];

        return { success: true, data: { items: ['i1', 'i2'], item_ids: sel.length > 0 ? sel : [1, 2] } };
    }
    if (url === '/test/boom-first') return Promise.reject(Object.assign(new Error('x'), { response: { status: 503, data: {} } }));
    if (url === '/test/manual') return { success: true, data: { body: 'x' } };
    if (url === '/test/items') return { success: true, data: { page: Number(params.page ?? 1) } };
    if (url === '/test/main') return { success: true, data: { n: 20 } };
    if (url === '/test/aux' || url === '/test/aux2') return { success: true, data: [] };
    if (url.startsWith('/test/products/')) return { success: true, data: { id: 5, list_price: 27000 } };
    if (url.startsWith('/test/base/') || url.startsWith('/test/own/')) return { success: true, data: { ok: true } };
    if (url.startsWith('/test/settings/')) return { success: true, data: { v: url.split('/').pop() } };
    if (url === '/test/shared-a') return { success: true, data: { who: 'A' } };
    if (url === '/test/shared-b') return { success: true, data: { who: 'B' } };

    return { success: true, data: {} };
}

const settle = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));
const waitLong = <T,>(cb: () => T, timeout = 10_000): Promise<T> => waitFor(cb, { timeout, interval: 15 });
vi.setConfig({ testTimeout: 60_000 });
const text = (testid: string): string | null => document.querySelector(`[data-testid="${testid}"]`)?.textContent ?? null;
const local = (): Record<string, any> => (window as any).G7Core.state.getLocal();
const release = (url: string): void => {
    net.held.delete(url);
    const list = net.pending.get(url) ?? [];
    net.pending.delete(url);
    list.forEach((r) => r());
};
const blurred = (testid: string): boolean => {
    const el = document.querySelector(`[data-testid="${testid}"]`);
    for (let e: Element | null = el; e; e = e.parentElement) {
        if (e.classList?.contains('blur-sm')) return true;
    }

    return false;
};

/**
 * 실물 엔진과 실물 Router 를 올린다 — TemplateApp 초기화(`init`)가 거는 Router 구독 두 개(routeChange·navigationSuperseded)를 같게 건다.
 *
 * @param first 첫 경로
 * @returns 앱·라우터·이동 함수
 */
async function mount(first: string): Promise<{ app: any; router: Router; go: (path: string) => Promise<void> }> {
    document.body.innerHTML = '<div id="app"></div>';
    net.held.clear();
    net.pending.clear();
    net.calls = [];
    net.fail.clear();
    net.respond = respond;
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
        loadLayout: async (_templateId: string, layoutPath: string) => JSON.parse(JSON.stringify({ version: '1.0.0', ...LAYOUTS[layoutPath] })),
    };
    const router = new Router('sirsoft-basic');
    router.setRoutes(ROUTES as any);
    (app as any).router = router;
    // TemplateApp.init 과 같은 구독
    router.on('routeChange', (route: any) => (app as any).handleRouteChange(route));
    router.on('navigationSuperseded', () => (app as any).supersedeRouteChange());
    router.on('routeNotFound', (path: string) => (app as any).handleRouteNotFound(path));
    const go = async (path: string): Promise<void> => {
        window.history.pushState({}, '', path);
        await router.navigateToCurrentPath();
    };
    await go(first);
    await settle(30);

    return { app, router, go };
}

describe('[case:state-global-route-supersede-and-refresh] 경로 이동 중 응답 도착·받는 동안 앞 화면·다시 받기 실패·같은 화면 받는 중·덮개 해제 (engine-v1.82.0)', () => {
    afterEach(async () => {
        for (const url of [...net.pending.keys()]) release(url);
        (window as any).__templateApp?.cancelEarlySpinnerTimer?.();
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        transitionManager.setPending(false);
        // 수정 전 엔진(fail-first)에는 없는 메서드라 있을 때만 부른다
        (transitionManager as any).setRefreshingSources?.([]);
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * 글 수정(응답 지연)으로 가는 중에 새 글로 다시 이동 — 대기열의 새 이동이 앞 이동을 밀어내야 한다.
     * 종전: Router 가 앞 이동(수정 화면 응답·렌더·progressive)을 끝까지 마친 뒤에야 새 글로 갔다 — 응답 전에는 새 글 요청이 나가지 않았다.
     *
     * @scenario case=queued_navigation_during_blocking
     * @effects queued_navigation_supersedes_in_flight, late_previous_route_response_dropped
     */
    it('수정 화면 응답을 기다리는 중 새 글로 옮기면 새 글이 곧바로 시작되고, 늦게 온 수정 응답은 화면·폼에 들어가지 않는다', async () => {
        const { router } = await mount('/');
        net.held.add('/test/posts/96');
        router.navigate('/board/b/96/edit');
        await waitLong(() => expect(net.pending.get('/test/posts/96')?.length ?? 0).toBeGreaterThan(0));

        router.navigate('/board/b/write');
        // 앞 응답을 풀기 전에 새 글 요청이 나가고 새 글 화면이 선다
        await waitLong(() => expect(net.calls).toContain('/test/posts/new'), 3_000);
        await waitLong(() => expect(text('e-id')).toBe('id:none'));
        expect(window.location.pathname).toBe('/board/b/write');

        release('/test/posts/96');
        await settle(300);
        expect(text('e-id')).toBe('id:none');
        expect(text('e-content')).toBe('content:');
        expect(local().form?.id).toBeUndefined();
        expect(window.location.pathname).toBe('/board/b/write');
    });

    /**
     * 밀린 이동 뒤 대기열의 새 이동이 없는 경로(404)로 끝나도 이동 시작 때 켠 전환 상태(흐림)·입력 잠금이 남지 않는다 (가드).
     *
     * @scenario case=superseded_then_not_found
     * @effects queued_navigation_supersedes_in_flight
     */
    it('수정 화면을 받는 중 없는 경로로 옮기면 전환 상태가 풀린다', async () => {
        const { router } = await mount('/board/b/96/edit');
        await waitLong(() => expect(text('e-id')).toBe('id:96'));
        net.held.add('/test/posts/new');
        router.navigate('/board/b/write');
        await waitLong(() => expect(net.pending.get('/test/posts/new')?.length ?? 0).toBeGreaterThan(0));
        expect(transitionManager.getIsPending()).toBe(true);

        router.navigate('/nowhere/at/all');
        await waitLong(() => expect(window.location.pathname).toBe('/nowhere/at/all'));
        await waitLong(() => expect(transitionManager.getIsPending()).toBe(false));
        expect(document.querySelector('[inert]')).toBeNull();
    });

    /**
     * 성능 잠금 — 베이스 소스 6개를 이어 쓰는 다른 화면 이동 1회의 루트급 렌더 주기(`startRenderCycle`)가 수정 전 엔진 이하.
     * 수정 전 엔진(engine-v1.81.2 워킹트리)의 같은 하네스 실측값을 상한으로 둔다. 이어 쓴 베이스 소스를 「다시 받는 중」 으로 알리면
     * 소스마다 해제가 전환 문맥을 바꿔 트리 전체를 다시 그렸다(최종 번들 실측 +60~100%).
     *
     * @scenario case=render_cycle_budget_other_screen
     * @effects render_cycles_not_increased
     */
    it('다른 화면 이동 1회의 렌더 주기가 수정 전 엔진보다 늘지 않는다', async () => {
        const { router } = await mount('/admin/screen-a');
        await waitLong(() => expect(text('screen_a-own')).toBe('own'));
        await settle(200);
        const spy = vi.spyOn(DataBindingEngine.prototype, 'startRenderCycle');
        spy.mockClear();
        // 응답이 소스마다 다른 시각에 온다(실제 서버) — 하나씩 30ms 간격으로 푼다
        const urls = [...BASE.map((b) => `/test/base/${b.id}`), '/test/own/b'];
        urls.forEach((u) => net.held.add(u));
        router.navigate('/admin/screen-b');
        for (const u of urls) {
            await waitLong(() => expect(net.pending.get(u)?.length ?? 0).toBeGreaterThan(0));
        }
        for (const u of urls) {
            release(u);
            await settle(30);
        }
        await waitLong(() => expect(text('screen_b-own')).toBe('own'));
        await settle(400);
        const cycles = spy.mock.calls.length;
        // 수정 전 엔진의 같은 하네스 실측 9(3회 같음) — 렌더 주기 해제 묶기 전 새 엔진은 15
        expect(cycles).toBeLessThanOrEqual(9);
    });

    /**
     * 성능 잠금 — 같은 화면 쿼리 이동(쪽 넘김 링크) 1회의 렌더 주기가 수정 전 엔진 이하.
     *
     * @scenario case=render_cycle_budget_same_screen
     * @effects render_cycles_not_increased
     */
    it('같은 화면 쿼리 이동 1회의 렌더 주기가 수정 전 엔진보다 늘지 않는다', async () => {
        const { router } = await mount('/admin/screen-a');
        await waitLong(() => expect(text('screen_a-own')).toBe('own'));
        await settle(200);
        const spy = vi.spyOn(DataBindingEngine.prototype, 'startRenderCycle');
        spy.mockClear();
        // 응답이 소스마다 다른 시각에 온다(실제 서버) — 하나씩 30ms 간격으로 푼다
        const urls = [...BASE.map((b) => `/test/base/${b.id}`), '/test/own/a'];
        urls.forEach((u) => net.held.add(u));
        router.navigate('/admin/screen-a?page=2');
        for (const u of urls) {
            await waitLong(() => expect(net.pending.get(u)?.length ?? 0).toBeGreaterThan(0));
        }
        for (const u of urls) {
            release(u);
            await settle(30);
        }
        await waitLong(() => expect(text('screen_a-own')).toBe('own'));
        await settle(400);
        const cycles = spy.mock.calls.length;
        // 수정 전 엔진의 같은 하네스 실측 9 — 해제를 데이터 갱신과 같은 전환 갈래로 묶기 전 새 엔진은 15
        expect(cycles).toBeLessThanOrEqual(9);
    });

    /**
     * 같은 레이아웃 경로로 동시에 옮긴 경우(뒤로/앞으로처럼 대기열을 거치지 않음) — 늦게 온 앞 경로 응답의 성공 처리(setState form=응답)가
     * 새 화면 폼을 앞 대상 값으로 덮지 않는다. 종전: 성공 처리는 세대를 보지 않아 새 글 폼에 앞 글 id·본문이 들어갔다.
     *
     * @scenario case=late_previous_route_on_success
     * @effects late_previous_route_response_dropped
     */
    it('늦게 온 앞 경로 응답의 성공 처리는 새 화면 폼을 덮지 않는다', async () => {
        const { go } = await mount('/');
        net.held.add('/test/posts/96');
        const first = go('/board/b/96/edit');
        await waitLong(() => expect(net.pending.get('/test/posts/96')?.length ?? 0).toBeGreaterThan(0));
        await go('/board/b/write');
        await waitLong(() => expect(text('e-id')).toBe('id:none'));

        release('/test/posts/96');
        await first;
        await settle(300);
        expect(local().form?.id).toBeUndefined();
        expect(text('e-id')).toBe('id:none');
        expect(text('e-content')).toBe('content:');
    });

    /**
     * 다 받은 수정 화면 → 새 글(응답 지연). 받는 동안 앞 화면은 「받는 중」(흐림)이고 입력이 잠기며, 그 사이 앞 화면 확장이 setLocal 로
     * 남긴 값은 새 폼 위에 덧씌워지지 않는다. 종전: 응답 전까지 흐림·잠금 없이 앞 글 폼이 조작됐고, 받는 동안 쓴 setLocal 표지가 남아
     * `getLocal().form` 이 앞 글 본문을 돌려줬다.
     *
     * @scenario case=edit_to_write_blocking_window
     * @effects previous_screen_input_locked_while_form_replaced
     */
    it('수정 → 새 글 응답을 받는 동안 앞 화면은 흐림·입력 잠금이고, 받는 동안 쓴 setLocal 은 새 폼에 덧씌워지지 않는다', async () => {
        const { router } = await mount('/board/b/96/edit');
        await waitLong(() => expect(text('e-id')).toBe('id:96'));

        net.held.add('/test/posts/new');
        router.navigate('/board/b/write');
        await waitLong(() => expect(net.pending.get('/test/posts/new')?.length ?? 0).toBeGreaterThan(0));

        // 받는 동안: 전환 상태 켜짐(흐림), 덮개 대상 내용에 inert
        expect(transitionManager.getIsPending()).toBe(true);
        await waitLong(() => expect(blurred('e-id')).toBe(true));
        const lockedChildren = Array.from(document.getElementById('content_area')!.children).filter((c) => c.id !== 'g7-skeleton-overlay');
        expect(lockedChildren.length).toBeGreaterThan(0);
        expect(lockedChildren.every((c) => c.hasAttribute('inert'))).toBe(true);
        // 앞 화면에 남은 편집기 확장이 받는 동안 폼에 앞 글 본문을 심는다(평문 입력칸이 늦게 서는 갈래)
        (window as any).G7Core.state.setLocal({ 'form.content': '앞 글 본문', 'form.content_mode': 'text' }, { render: false, selfManaged: true });

        release('/test/posts/new');
        await waitLong(() => expect(text('e-id')).toBe('id:none'));
        await waitLong(() => expect(text('e-content')).toBe('content:'));
        expect(local().form?.content).toBe('');
        expect(local().form?.id).toBeUndefined();
        await waitLong(() => expect(document.querySelector('[inert]')).toBeNull());
        await waitLong(() => expect(blurred('e-id')).toBe(false));
    });

    /**
     * 장바구니 선택 해제 → 같은 주소 다시 받기 500. 오류 처리기는 `error.refetch` 로 다시 받기 실패를 알고, 마지막 값으로 버틴 결과가
     * 선택(initLocal)을 되돌리지 않는다. 처음 받기 실패는 `error.refetch` 가 거짓이다.
     * 종전: 문맥에 구분이 없어 처음 받기 갈래(오류 화면)가 돌았고, 마지막 값의 initLocal 이 선택을 되돌렸다.
     *
     * @scenario case=cart_selection_refetch_500
     * @effects refetch_failure_context_flag, refetch_kept_value_does_not_reseed
     */
    it('다시 받기 실패는 error.refetch=true 로 오류 처리기에 닿고, 마지막 값으로 버텨도 바꾼 선택은 그대로다', async () => {
        await mount('/shop/cart');
        await waitLong(() => expect(text('k-sel')).toBe('sel:1,2'));
        await waitLong(() => expect((window as any).G7Core.state.get().boomRefetchFlag).toBe('first'));

        await (window as any).G7Core.dispatch({ handler: 'setState', params: { target: 'local', selectedItems: [1] } });
        await waitLong(() => expect(text('k-sel')).toBe('sel:1'));
        net.fail.add('/test/cart');
        await (window as any).G7Core.dispatch({ handler: 'refetchDataSource', params: { dataSourceId: 'cartLike' } });
        await settle(100);

        expect((window as any).G7Core.state.get().refetchFailed).toBe(500);
        expect((window as any).G7Core.state.get().firstFailed).toBeUndefined();
        expect(text('k-sel')).toBe('sel:1');
        expect(local().selectedItems).toEqual([1]);
        expect(text('k-items')).toBe('items:i1,i2');
    });

    /**
     * 다시 받기로만 받는 소스(auto_fetch:false)의 **첫** 받기 실패는 `error.refetch` 가 거짓이다 — 오류 화면으로 빈 폼 저장을 막는
     * 레이아웃 계약(편집기 확장 본문 등)이 토스트로 약해지지 않는다. 한 번 받은 뒤의 실패만 다시 받기 실패다.
     *
     * @scenario case=manual_source_first_fetch_failure
     * @effects refetch_failure_context_flag
     */
    it('다시 받기로만 받는 소스의 첫 실패는 처음 받기 실패, 받은 뒤의 실패만 다시 받기 실패다', async () => {
        await mount('/shop/cart');
        await waitLong(() => expect(text('k-sel')).toBe('sel:1,2'));
        const G7Core = (window as any).G7Core;

        net.fail.add('/test/manual');
        await G7Core.dispatch({ handler: 'refetchDataSource', params: { dataSourceId: 'manualOnly' } });
        await waitLong(() => expect(G7Core.state.get().manualFlag).toBe('first'));

        net.fail.delete('/test/manual');
        await G7Core.dispatch({ handler: 'refetchDataSource', params: { dataSourceId: 'manualOnly' } });
        net.fail.add('/test/manual');
        await G7Core.dispatch({ handler: 'refetchDataSource', params: { dataSourceId: 'manualOnly' } });
        await waitLong(() => expect(G7Core.state.get().manualFlag).toBe('refetch'));
    });

    /**
     * 같은 목록 쪽 넘김(쿼리 replace) — 앞 값을 둔 채 받는 동안 `data_sources` 를 지정한 흐림이 선다.
     * 종전: 값이 있어 흐림이 서지 않았다(받는 중 표시 없음).
     *
     * @scenario case=same_list_page_replace
     * @effects same_screen_refresh_blur
     */
    it('같은 목록 쪽 넘김 동안 앞 행 위에 받는 중 흐림이 서고, 받으면 걷힌다', async () => {
        const { app } = await mount('/admin/list');
        await waitLong(() => expect(text('l-rows')).toBe('rows:1'));
        expect(blurred('l-rows')).toBe(false);

        net.held.add('/test/items');
        const run = app.updateQueryParams('/admin/list?page=2');
        await waitLong(() => expect(net.pending.get('/test/items')?.length ?? 0).toBeGreaterThan(0));
        expect(text('l-rows')).toBe('rows:1');
        await waitLong(() => expect(blurred('l-rows')).toBe(true));

        release('/test/items');
        await run;
        await waitLong(() => expect(text('l-rows')).toBe('rows:2'));
        await waitLong(() => expect(blurred('l-rows')).toBe(false));
    });

    /**
     * 상품 수정 → 목록 꼴. 목록은 빨리 오고 보조 소스(progressive·background)는 늦다 — 덮개는 새 화면 커밋 뒤 걷힌다.
     * 종전: 모든 progressive·background 소스를 받을 때까지 그려진 목록 위에 덮개가 남았다.
     *
     * @scenario case=overlay_slow_aux_sources
     * @effects overlay_released_after_commit
     */
    it('덮개는 보조 소스를 기다리지 않고 새 화면이 그려지면 걷힌다', async () => {
        const { router } = await mount('/');
        net.held.add('/test/aux');
        net.held.add('/test/aux2');
        router.navigate('/admin/overlay-list');
        await waitLong(() => expect(text('ol-main')).toBe('main:20'));
        // 렌더 직후 대상 재부착 사이의 순간 공백을 「걷힘」으로 오인하지 않게 몇 프레임 뒤에 본다
        await settle(300);
        expect(document.getElementById('g7-skeleton-overlay')).toBeNull();
        expect(net.pending.get('/test/aux2')?.length ?? 0).toBeGreaterThan(0);
    });

    /**
     * 레이아웃이 `transition_overlay.wait_for` 로 기다리라고 선언한 소스는 종전대로 그 소스가 올 때까지 덮개를 둔다 (가드).
     *
     * @scenario case=overlay_wait_for_contract
     * @effects overlay_released_after_commit
     */
    it('wait_for 로 선언한 소스는 올 때까지 덮개가 남는다', async () => {
        const { router } = await mount('/');
        net.held.add('/test/aux2');
        router.navigate('/admin/overlay-wait');
        await waitLong(() => expect(text('ow-main')).toBe('main:20'));
        await settle(200);
        expect(document.getElementById('g7-skeleton-overlay')).not.toBeNull();

        release('/test/aux2');
        await waitLong(() => expect(document.getElementById('g7-skeleton-overlay')).toBeNull());
    });

    /**
     * 상품 수정 정가 입력 뒤 같은 경로 replace(탭 쿼리만 바꿈)는 폼 소스를 다시 받아 서버 값으로 되돌린다 — 쿼리 갱신(탭 전환) 재조회는
     * 편집 중이어도 서버 값으로 되돌린다는 계약(저장은 지금 탭 구획만 보냄, `TemplateApp.updateQueryParams.dirtyForm` 잠금)을 이번 판도
     * 지킨다 (가드). 받은 값이 같아도 `refetchOnMount` 소스는 다시 적용된다.
     *
     * @scenario case=same_path_replace_after_typing
     * @effects replace_refetch_reseeds_form
     */
    it('같은 경로 replace 로 폼 소스를 다시 받으면 친 값은 서버 값으로 돌아간다(탭 전환 계약)', async () => {
        const { app } = await mount('/admin/products/5/edit');
        await waitLong(() => expect(text('p-price')).toBe('price:27000'));

        await (window as any).G7Core.dispatch({ handler: 'setState', params: { target: 'local', 'form.list_price': 33333 } });
        await waitLong(() => expect(text('p-price')).toBe('price:33333'));
        await app.updateQueryParams('/admin/products/5/edit?tab=options');

        await waitLong(() => expect(text('p-price')).toBe('price:27000'));
        expect(local().form?.list_price).toBe(27000);
    });

    /**
     * 요청이 바뀌는 replace(탭마다 다른 설정 묶음을 받음)는 종전대로 받은 값으로 폼을 심는다 (가드).
     *
     * @scenario case=replace_changes_request_reseeds
     * @effects replace_refetch_reseeds_form
     */
    it('replace 로 요청이 바뀌면 받은 값으로 폼을 다시 심는다', async () => {
        const { app } = await mount('/admin/settings');
        await waitLong(() => expect(text('s-v')).toBe('v:a'));

        await app.updateQueryParams('/admin/settings?tab=b');
        await waitLong(() => expect(text('s-v')).toBe('v:b'));
    });

    /**
     * init 을 기다리는 동안 취소된 화면(B)이 받아 둔 같은 id 의 blocking 값이, 돌아온 앞 화면(A — blocking 없음·init 있음)의
     * 첫 렌더에 「받은 값」 으로 심기지 않는다. A 는 그린 화면과 같은 화면이라 이어 쓰기 판정을 통과했지만, 받아 둔 데이터는
     * 이미 B 의 것이었다 (engine-v1.82.0 init 뒤 취소 × engine-v1.81.2 이월 차단의 틈).
     *
     * @scenario case=return_during_cancelled_init
     * @effects cancelled_screen_data_not_carried
     */
    it('init 을 기다리는 화면에서 앞 화면으로 돌아오면 그 화면이 받은 같은 id 값이 앞 화면에 보이지 않는다', async () => {
        const { go } = await mount('/admin/init-list');
        await waitLong(() => expect(text('il-shared')).toBe('who:A'));

        net.held.add('/test/slow');
        net.held.add('/test/shared-a');
        void go('/admin/slow-init');
        await waitLong(() => expect(net.calls).toContain('/test/slow'));
        void go('/admin/init-list');

        const seen: Array<string | null> = [];
        for (let i = 0; i < 25; i++) {
            await settle(20);
            seen.push(text('il-shared'));
        }
        release('/test/slow');
        await settle(100);
        seen.push(text('il-shared'));
        expect(seen).not.toContain('who:B');

        release('/test/shared-a');
        await waitLong(() => expect(text('il-shared')).toBe('who:A'));
    });
});
