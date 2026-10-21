// e2e:allow 실제 엔진 구조(TemplateApp.handleRouteChange·renderTemplate·DynamicRenderer·DataSourceManager)에 하네스 레이아웃을 올려 응답 전 첫 렌더를 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(상품 목록 → 쿠폰 목록 → 배송정책 목록 사이드바 이동·같은 목록 검색·플러그인 설정 A → B)이 덮는다
/**
 * @file TemplateApp.routeDataCarryOver.test.tsx
 * @description 화면을 옮길 때 앞 화면의 같은 id 데이터소스 값이 새 화면 첫 렌더에 심기지 않는다 (engine-v1.81.2)
 *
 * 종전 엔진은 두 통로로 앞 화면 값을 새 화면에 넣었다.
 * ① progressive·background 초기값 — 앞 컨텍스트에 같은 id 값이 있으면 레이아웃 비교 없이 이어 썼다.
 * ② `currentFetchedData` — blocking 이 없는 화면을 거치는 동안 앞 화면들이 받은 값이 쌓였고, init_actions 가 있는 화면은
 *    렌더 직전 그 값을 모두 컨텍스트에 덮어썼다(앞앞 화면 값까지).
 * 그래서 새 화면이 받기 전부터 앞 화면 값을 「받음」으로 보고 빈 문구 조건이 풀리거나(모양이 다르면 0행·「없음」)
 * 앞 화면 행이 먼저 그려졌다. 고친 뒤에는 같은 화면(쿼리만 다름)과 같은 요청(같은 정의·같은 의존 값 — 베이스
 * 레이아웃의 메뉴 등)만 이어 쓴다.
 *
 * 하네스: 실물 `TemplateApp.handleRouteChange`(레이아웃 로더만 대역 — 하네스 레이아웃을 돌려줌) · 실물 `renderTemplate`·
 * `DynamicRenderer`·`DataSourceManager`. API 응답은 대역이 잡아 두었다가 단언 뒤에 푼다(응답 전 첫 렌더를 본다).
 * 번들 레이아웃 id 에 기대지 않는다.
 *
 * @scenario-manifest tests/scenarios/route-data-carry-over.yaml
 *
 * @effects refetch_failure_null_and_error_recorded, refetch_other_target_failure_no_previous_value, refetch_same_request_failure_keeps_last, refetch_success_clears_error, other_screen_same_id_not_carried, base_source_carried, same_screen_query_change_carried, plugin_settings_a_to_b_not_carried, accumulated_fetched_data_not_injected, same_layout_other_path_route_source_not_carried
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import { waitFor } from '@testing-library/react';

/** API 대역 — 엔드포인트(앞 `/api` 를 뗀 값)·파라미터로 응답을 정하고, 잡아 두기를 켜면 풀 때까지 기다린다 */
const net = vi.hoisted(() => {
    const state = {
        hold: false,
        pending: [] as Array<() => void>,
        calls: [] as string[],
        /** 대상별 실패 상태 코드(쿠폰 발급 내역 꼴) */
        failCoupons: {} as Record<string, number>,
        /** 레이아웃 JSON 응답 지연(ms) — 서버가 느린 화면 전환 */
        layoutDelay: 0,
        respond: (_url: string, _params: any): any => ({ success: true, data: {} }),
    };

    return state;
});

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({
        get: (url: string, cfg?: { params?: any }) => {
            net.calls.push(url);
            const body = net.respond(url, cfg?.params ?? {});
            // 실패 응답 — axios 오류 꼴({ response: { status, data } })로 거절한다
            if (body && body.__reject) return Promise.reject(Object.assign(new Error('Request failed'), { response: body.__reject }));
            if (!net.hold) return Promise.resolve(body);

            return new Promise((resolve) => { net.pending.push(() => resolve(body)); });
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

/** 편집기 대역 마운트 횟수 */
const editorMounts = { count: 0 };

/**
 * 편집기 대역 — 처음 받은 비어 있지 않은 값을 붙들고, 바깥 값이 비어도 지우지 않는다(본문 편집기가 마운트 뒤 바깥의
 * 빈 값으로 본문을 비우지 않는 것과 같은 꼴). 다시 마운트돼야 새 화면 값으로 시작한다.
 *
 * @param props value·data-testid
 * @returns 편집기 화면
 */
function EditorStandIn({ value, ...rest }: any) {
    const held = React.useRef<string | undefined>(undefined);
    if (held.current === undefined && typeof value === 'string' && value !== '') held.current = value;
    React.useEffect(() => { editorMounts.count++; }, []);

    return React.createElement('div', { 'data-testid': rest['data-testid'] }, held.current ?? '');
}

const src = (id: string, endpoint: string, extra: Node = {}): Node => ({ id, type: 'api', endpoint, auth_mode: 'required', loading_strategy: 'progressive', ...extra });

/** 베이스 레이아웃이 모든 화면에 주는 소스와 같은 꼴 — 정의가 글자까지 같다 */
const MENU = src('menu', '/api/test/menus');
const span = (testid: string, text: string, cond?: string): Node => ({
    id: `s_${testid}`, type: 'basic', name: 'Span', props: { 'data-testid': testid }, text, ...(cond ? { if: cond } : {}),
});
const menuSpan = (p: string) => span(`${p}-menu`, "{{menu === undefined ? 'menu-pending' : 'menu:' + (menu?.data ?? []).length}}");
const root = (testid: string, children: Node[]): Node[] => [{ id: `root_${testid}`, type: 'basic', name: 'Div', props: { 'data-testid': testid }, children }];
const INIT = [{ handler: 'setState', params: { target: 'local', ready: true } }];

const LAYOUTS: Record<string, Node> = {
    // 상품·주문 목록 꼴 — 같은 id `items` 를 다른 모양(`{data:[{value,label}]}`)으로 받는 background 소스
    list_a: {
        layout_name: 'list_a',
        data_sources: [MENU, src('items', '/api/test/a/active', { loading_strategy: 'background' })],
        components: root('root-a', [menuSpan('a'), span('a-items', "{{items === undefined ? 'items-pending' : 'items:' + (items?.data ?? []).length}}")]),
    },
    // 배송정책 목록 꼴 — `items.data.data` 를 읽고 받기 전/빈 목록/행을 가른다. init_actions 있음, blocking 없음
    list_b: {
        layout_name: 'list_b',
        data_sources: [MENU, src('items', '/api/test/b/items', { params: { page: '{{query.page ?? 1}}' } })],
        init_actions: INIT,
        components: root('root-b', [
            menuSpan('b'),
            span('b-loading', 'loading', '{{items === undefined}}'),
            span('b-empty', 'empty', '{{items !== undefined && (items?.data?.data ?? []).length === 0}}'),
            span('b-rows', "{{'rows:' + (items?.data?.data ?? []).length + ':' + (items?.data?.data?.[0]?.name ?? '')}}", '{{(items?.data?.data ?? []).length > 0}}'),
        ]),
    },
    // 쿠폰 목록 꼴 — `items` 를 선언하지 않고 init_actions 가 있다(blocking 없음)
    list_c: {
        layout_name: 'list_c',
        data_sources: [MENU, src('coupons', '/api/test/c/coupons')],
        init_actions: INIT,
        components: root('root-c', [menuSpan('c')]),
    },
    // 플러그인 설정 꼴 — 레이아웃 이름이 같고 `settings` 정의가 글자까지 같다(경로 파라미터만 다름)
    plugin_settings: {
        layout_name: 'plugin_settings',
        data_sources: [MENU, src('settings', '/api/test/plugins/{{route.identifier}}/settings', { initLocal: 'form' })],
        components: root('root-p', [span('p-local', "{{'local:' + (_local.form?.vendor ?? 'none')}}"), menuSpan('p'), span('p-settings', "{{settings === undefined ? 'settings-pending' : 'settings:' + settings?.data?.vendor}}")]),
    },
    // 쿠폰 발급 내역 창 꼴 — 다시 받기로만 받는 소스(대상은 전역 상태), 실패 표시는 errorHandling 기본값이 전역 표시로 세운다
    refetch_list: {
        layout_name: 'refetch_list',
        data_sources: [
            src('issues', '/api/test/coupons/{{_global.coupon?.id}}/issues', {
                auto_fetch: false,
                errorHandling: { default: { handler: 'setState', params: { target: 'global', issuesLoadFailed: true } } },
                onSuccess: [{ handler: 'setState', params: { target: 'global', issuesLoadFailed: false } }],
            }),
            src('issuesFb', '/api/test/coupons/{{_global.coupon?.id}}/issues', { auto_fetch: false, fallback: { data: { data: [] } } }),
        ],
        components: root('root-r', [
            span('r-state', "{{issues === undefined ? 'pending' : issues === null ? 'null' : 'rows:' + (issues?.data?.data?.[0]?.name ?? '')}}"),
            span('r-error', "{{'err:' + (_dataSourceErrors?.issues?.status ?? 'none')}}"),
            span('r-flag', "{{'flag:' + (_global.issuesLoadFailed === true)}}"),
            span('r-fb', "{{issuesFb === undefined ? 'fb-pending' : 'fb:' + (issuesFb?.data?.data?.[0]?.name ?? '')}}"),
        ]),
    },
    // 장바구니 꼴 — 같은 주소에 선택(파라미터)만 바꿔 다시 받는 소스 + fallback, 그리고 처음 받기가 실패하는 progressive 소스
    refetch_cart: {
        layout_name: 'refetch_cart',
        data_sources: [
            src('cartLike', '/api/test/cart', { auto_fetch: false, params: { selected: '{{_global.sel}}' }, fallback: { data: { items: [] } } }),
            src('boom', '/api/test/boom'),
            src('issues', '/api/test/coupons/{{_global.coupon?.id}}/issues', { auto_fetch: false }),
        ],
        components: root('root-k', [
            span('k-cart', "{{cartLike === undefined ? 'pending' : 'items:' + (cartLike?.data?.items ?? []).join(',')}}"),
            span('k-errors', "{{'errs:' + Object.keys(_dataSourceErrors ?? {}).sort().join(',')}}"),
        ]),
    },
    // init_actions 안에서 다시 받기로 받는 화면(blocking 없음) — 렌더 전 다시 받기 실패가 첫 렌더에 닿는가
    init_refetch: {
        layout_name: 'init_refetch',
        data_sources: [src('issues', '/api/test/coupons/{{_global.coupon?.id}}/issues', { auto_fetch: false })],
        init_actions: [
            { handler: 'setState', params: { target: 'global', coupon: { id: 'INIT' } } },
            { handler: 'refetchDataSource', params: { dataSourceId: 'issues' } },
        ],
        components: root('root-i', [
            span('i-state', "{{issues === undefined ? 'pending' : issues === null ? 'null' : 'rows'}}"),
            span('i-error', "{{'err:' + (_dataSourceErrors?.issues?.status ?? 'none')}}"),
        ]),
    },
    // 글 폼 꼴 — 수정·새 글이 한 레이아웃(경로만 다름). 폼은 blocking 응답의 initLocal(form), 본문은 편집기
    post_form: {
        layout_name: 'post_form',
        data_sources: [src('form_data', "/api/test/posts/{{route.id ?? 'new'}}", { loading_strategy: 'blocking', initLocal: 'form' })],
        components: root('root-e', [
            { id: 'editor', type: 'basic', name: 'Editor', props: { value: "{{_local.form?.content ?? ''}}", 'data-testid': 'editor' } },
            span('e-id', "{{'id:' + (_local.form?.id ?? 'none')}}"),
            span('e-content', "{{'content:' + (_local.form?.content ?? 'none')}}"),
            span('e-att', "{{'att:' + ((_local.form?.attachments ?? []).length)}}"),
        ]),
    },
    // 다른 레이아웃 두 개가 같은 _local 키(form)를 blocking initLocal 로 채운다(회원 수정 → 역할 수정 꼴)
    leak_a: {
        layout_name: 'leak_a',
        data_sources: [src('subject', '/api/test/leak/a', { loading_strategy: 'blocking', initLocal: 'form' })],
        components: root('root-la', [span('la-form', "{{'a:' + (_local.form?.a ?? 'none') + ' b:' + (_local.form?.b ?? 'none')}}")]),
    },
    leak_b: {
        layout_name: 'leak_b',
        data_sources: [src('subject', '/api/test/leak/b', { loading_strategy: 'blocking', initLocal: 'form' })],
        components: root('root-lb', [span('lb-form', "{{'a:' + (_local.form?.a ?? 'none') + ' b:' + (_local.form?.b ?? 'none')}}")]),
    },
    // 전환 덮개 — 베이스 레이아웃의 spinner 설정(대상 content_area)을 가진 화면들
    ov_a: {
        layout_name: 'ov_a',
        transition_overlay: { enabled: true, style: 'spinner', target: 'content_area' },
        data_sources: [],
        components: [{ id: 'root_ov_a', type: 'basic', name: 'Div', props: { id: 'content_area', 'data-testid': 'root-ova' }, children: [span('ova', 'screen-a')] }],
    },
    ov_b: {
        layout_name: 'ov_b',
        transition_overlay: { enabled: true, style: 'spinner', target: 'content_area', wait_for: ['items'] },
        data_sources: [src('items', '/api/test/b/items')],
        components: [{ id: 'root_ov_b', type: 'basic', name: 'Div', props: { id: 'content_area', 'data-testid': 'root-ovb' }, children: [span('ovb', 'screen-b')] }],
    },
    ov_c: {
        layout_name: 'ov_c',
        data_sources: [],
        components: [{ id: 'root_ov_c', type: 'basic', name: 'Div', props: { id: 'content_area', 'data-testid': 'root-ovc' }, children: [span('ovc', 'screen-c')] }],
    },
    // 상품 수정 꼴 — 같은 레이아웃의 다른 경로. 경로를 읽는 소스와 읽지 않는 소스
    product_form: {
        layout_name: 'product_form',
        data_sources: [MENU, src('logs', '/api/test/products/{{route.id}}/logs'), src('cats', '/api/test/cats')],
        components: root('root-f', [
            span('f-logs', "{{logs === undefined ? 'logs-pending' : 'logs:' + logs?.data?.product}}"),
            span('f-cats', "{{cats === undefined ? 'cats-pending' : 'cats:' + (cats?.data ?? []).length}}"),
        ]),
    },
};

/** 서버 응답 — 모양은 실제 목록 API 꼴 */
function respond(url: string, params: any): any {
    const m = url.match(/^\/test\/(?:plugins\/([^/]+)\/settings|products\/([^/]+)\/logs)$/);
    if (url === '/test/menus') return { success: true, data: ['m1', 'm2', 'm3'] };
    if (url === '/test/a/active') return { success: true, data: [{ value: 1, label: 'A-active' }] };
    if (url === '/test/b/items') return { success: true, data: { data: [{ name: `B-p${params.page ?? 1}` }, { name: 'B-x' }] } };
    if (url === '/test/c/coupons') return { success: true, data: { data: [] } };
    if (url === '/test/cats') return { success: true, data: [1, 2] };
    if (url === '/test/leak/a') return { success: true, data: { a: 'A 의 값' } };
    if (url === '/test/leak/b') return { success: true, data: { b: 'B 의 값' } };
    if (url === '/test/posts/96') return { success: true, data: { id: 96, content: '앞 글 본문', attachments: [1], verification_token: 'v' } };
    if (url === '/test/posts/new') return { success: true, data: { content: '' } };
    if (url === '/test/boom') return { __reject: { status: 500, data: { message: 'boom' } } };
    if (url === '/test/cart') {
        const sel = String(params.selected ?? '');
        if (net.failCoupons[`cart-${sel}`]) return { __reject: { status: 500, data: { message: 'cart' } } };

        return { success: true, data: { items: [`sel-${sel}`] } };
    }
    const coupon = url.match(/^\/test\/coupons\/([^/]+)\/issues$/)?.[1];
    if (coupon) {
        const status = net.failCoupons[coupon];
        if (status) return { __reject: { status, data: { message: `fail-${coupon}` } } };

        return { success: true, data: { data: [{ name: `${coupon}-1` }] } };
    }
    if (m?.[1]) return { success: true, data: { vendor: m[1] } };
    if (m?.[2]) return { success: true, data: { product: m[2] } };

    return { success: true, data: {} };
}

interface RouteSpec {
    path: string;
    layout: string;
    params?: Record<string, string>;
    query?: Record<string, string>;
}

const ROUTES = {
    a: { path: '/admin/a', layout: 'list_a' },
    b: { path: '/admin/b', layout: 'list_b' },
    b2: { path: '/admin/b', layout: 'list_b', query: { page: '2' } },
    c: { path: '/admin/c', layout: 'list_c' },
    pa: { path: '/admin/plugins/vendor-a/settings', layout: 'vendor-a.plugin_settings', params: { identifier: 'vendor-a' } },
    pb: { path: '/admin/plugins/vendor-b/settings', layout: 'vendor-b.plugin_settings', params: { identifier: 'vendor-b' } },
    f1: { path: '/admin/products/1/edit', layout: 'product_form', params: { id: '1' } },
    f2: { path: '/admin/products/2/edit', layout: 'product_form', params: { id: '2' } },
    r: { path: '/admin/refetch', layout: 'refetch_list' },
    k: { path: '/admin/refetch-cart', layout: 'refetch_cart' },
    la: { path: '/admin/leak-a', layout: 'leak_a' },
    ova: { path: '/admin/ov-a', layout: 'ov_a' },
    ovb: { path: '/admin/ov-b', layout: 'ov_b' },
    ovc: { path: '/admin/ov-c', layout: 'ov_c' },
    lb: { path: '/admin/leak-b', layout: 'leak_b' },
    e96: { path: '/board/b/96/edit', layout: 'post_form', params: { id: '96' } },
    ew: { path: '/board/b/write', layout: 'post_form' },
    ewq1: { path: '/board/b/write', layout: 'post_form', query: { tab: '1' } },
    ewq2: { path: '/board/b/write', layout: 'post_form', query: { tab: '2' } },
    i: { path: '/admin/init-refetch', layout: 'init_refetch' },
} satisfies Record<string, RouteSpec>;

const settle = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));
/**
 * 화면 반영을 기다린다 — 다른 시험과 병렬로 돌아 CPU 가 바쁠 때도 React 커밋·응답 반영을 넉넉히 기다린다.
 * 고정 시간(settle) 뒤 곧바로 단언하면 부하에서 커밋 전 DOM 을 읽어 간헐 red 가 난다.
 *
 * @param cb 단언
 * @returns 단언이 통과하면 풀리는 약속
 */
const waitLong = <T,>(cb: () => T): Promise<T> => waitFor(cb, { timeout: 10_000, interval: 15 });

// 병렬 실행 부하에서 기본 5초 상한에 걸리지 않게 이 파일의 시험 상한을 넉넉히 둔다
vi.setConfig({ testTimeout: 60_000 });
const text = (testid: string): string | null => document.querySelector(`[data-testid="${testid}"]`)?.textContent ?? null;
const ctx = (): Record<string, any> => engine.getState().currentDataContext;

/**
 * 실물 엔진을 올리고 첫 화면으로 들어간다(응답은 바로).
 *
 * @param first 첫 화면
 * @returns 앱과 이동 함수
 */
async function mount(first: RouteSpec): Promise<{ app: any; go: (r: RouteSpec) => Promise<void>; goHeld: (r: RouteSpec, rootTestId: string) => Promise<() => Promise<void>> }> {
    document.body.innerHTML = '<div id="app"></div>';
    net.hold = false;
    net.pending = [];
    net.calls = [];
    net.respond = respond;
    vi.spyOn(AuthManager.getInstance(), 'isAuthenticated').mockReturnValue(true);
    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
    (ComponentRegistry.getInstance() as any).registry = {
        Div: { component: passthrough('div'), metadata: {} },
        Span: { component: passthrough('span'), metadata: {} },
        Editor: { component: EditorStandIn, metadata: {} },
    };
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));
    (app as any).layoutLoader = {
        loadLayout: async (_templateId: string, layoutPath: string) => {
            const key = layoutPath.replace(/^[^.]+\./, '');
            if (net.layoutDelay) await settle(net.layoutDelay);

            return JSON.parse(JSON.stringify({ version: '1.0.0', ...LAYOUTS[key] }));
        },
    };
    // Router.executeNavigation 과 같은 순서: pushState → 화면 전환
    const go = async (r: RouteSpec): Promise<void> => {
        const qs = new URLSearchParams(r.query ?? {}).toString();
        window.history.pushState({}, '', r.path + (qs ? `?${qs}` : ''));
        await (app as any).handleRouteChange({ path: r.path, layout: r.layout, params: r.params ?? {}, query: r.query ?? {} });
    };
    /** 응답을 잡아 둔 채 옮기고 새 화면 첫 렌더까지 기다린다 — 돌려준 함수가 응답을 풀고 전환 끝까지 기다린다 */
    const goHeld = async (r: RouteSpec, rootTestId: string): Promise<() => Promise<void>> => {
        net.hold = true;
        net.calls = [];
        const run = go(r);
        // 새 화면 첫 렌더가 끝나야 progressive 요청이 나간다 — 같은 레이아웃이면 DOM 이 남아 있어 그 신호로 본다
        await waitLong(() => expect(net.pending.length).toBeGreaterThan(0));
        await settle(30);
        await waitLong(() => expect(ctx().route?.path).toBe(r.path));
        await waitLong(() => expect(document.querySelector(`[data-testid="${rootTestId}"]`)).not.toBeNull());

        return async () => {
            net.hold = false;
            const p = net.pending;
            net.pending = [];
            p.forEach((release) => release());
            await run;
        };
    };
    await go(first);
    await settle(30);

    return { app, go, goHeld };
}

describe('[case:state-global-route-change-carries-other-screen-datasource] 화면을 옮길 때 앞 화면의 같은 id 데이터소스 값이 새 화면 첫 렌더에 심기지 않는다 (engine-v1.81.2)', () => {
    afterEach(async () => {
        net.hold = false;
        net.pending.splice(0).forEach((release) => release());
        // 이 인스턴스가 예약한 덮개 타이머가 다음 시험에서 터지지 않게 끈다(전역 누수 차단)
        (window as any).__templateApp?.cancelEarlySpinnerTimer?.();
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * 상품 목록(`items` = `{data:[{value,label}]}`) → 배송정책 목록(`items.data.data` 를 읽음). 종전: 받기 전 「empty」(0행).
     *
     * @scenario case=other_layout_same_id_different_shape
     * @effects other_screen_same_id_not_carried, base_source_carried
     */
    it('다른 레이아웃의 같은 id 값은 이어 쓰지 않는다 — 받기 전은 「받는 중」, 베이스 소스(메뉴)는 이어 쓴다', async () => {
        const { goHeld } = await mount(ROUTES.a);
        await waitLong(() => expect(text('a-items')).toBe('items:1'));

        const release = await goHeld(ROUTES.b, 'root-b');
        await waitLong(() => expect(ctx().items).toBeUndefined());
        await waitLong(() => expect(text('b-loading')).toBe('loading'));
        await waitLong(() => expect(text('b-empty')).toBeNull());
        // 베이스 레이아웃 꼴 소스(같은 정의)는 종전처럼 이어 써서 메뉴가 비지 않는다
        await waitLong(() => expect(text('b-menu')).toBe('menu:3'));

        await release();
        await waitLong(() => expect(text('b-rows')).toBe('rows:2:B-p1'));
    });

    /**
     * 뒤로(배송정책 목록 → 상품 목록) — 반대 방향도 같다. 종전: 받기 전 `items` 가 배송정책 응답이라 `items:0`.
     *
     * @scenario case=back_navigation_reverse_direction
     * @effects other_screen_same_id_not_carried
     */
    it('반대 방향(뒤로 가기와 같은 전환)도 앞 화면 값을 이어 쓰지 않는다', async () => {
        const { go, goHeld } = await mount(ROUTES.a);
        await go(ROUTES.b);
        await waitLong(() => expect(text('b-rows')).toBe('rows:2:B-p1'));

        const release = await goHeld(ROUTES.a, 'root-a');
        await waitLong(() => expect(ctx().items).toBeUndefined());
        await waitLong(() => expect(text('a-items')).toBe('items-pending'));
        await waitLong(() => expect(text('a-menu')).toBe('menu:3'));
        await release();
        await waitLong(() => expect(text('a-items')).toBe('items:1'));
    });

    /**
     * 같은 목록에서 쪽만 바꾼다(쿼리 변경) — 받는 동안 앞 쪽 행을 보여 깜빡임을 막는 종전 동작 그대로.
     *
     * @scenario case=same_screen_query_change
     * @effects same_screen_query_change_carried
     */
    it('같은 화면의 쿼리 변경(쪽 넘김)은 받는 동안 앞 쪽 행을 그대로 보인다', async () => {
        const { goHeld } = await mount(ROUTES.b);
        await waitLong(() => expect(text('b-rows')).toBe('rows:2:B-p1'));

        const release = await goHeld(ROUTES.b2, 'root-b');
        await waitLong(() => expect(text('b-rows')).toBe('rows:2:B-p1'));
        await waitLong(() => expect(text('b-loading')).toBeNull());
        await waitLong(() => expect(text('b-empty')).toBeNull());
        await release();
        await waitLong(() => expect(text('b-rows')).toBe('rows:2:B-p2'));
    });

    /**
     * 플러그인 A 설정 → B 설정 — 레이아웃 이름과 `settings` 정의가 같고 경로 파라미터만 다르다. 종전: 받기 전 A 의 설정.
     *
     * @scenario case=plugin_settings_a_to_b
     * @effects plugin_settings_a_to_b_not_carried, base_source_carried
     */
    it('플러그인 A 설정 → B 설정은 A 의 설정 값을 이어 쓰지 않는다', async () => {
        const { goHeld } = await mount(ROUTES.pa);
        await waitLong(() => expect(text('p-settings')).toBe('settings:vendor-a'));

        const release = await goHeld(ROUTES.pb, 'root-p');
        // 같은 레이아웃이라 DOM 이 남을 수 있으므로 새 렌더가 끝날 때까지 기다린 뒤 본다
        await waitLong(() => expect(text('p-settings')).toBe('settings-pending'));
        await waitLong(() => expect(ctx().settings).toBeUndefined());
        // 플러그인 설정은 모두 layout_name 이 같다 — 경로가 다르면 다른 화면이라 A 의 폼(_local)도 남지 않는다
        await waitLong(() => expect(text('p-local')).toBe('local:none'));
        await waitLong(() => expect(text('p-menu')).toBe('menu:3'));
        await release();
        await waitLong(() => expect(text('p-settings')).toBe('settings:vendor-b'));
    });

    /**
     * 상품 목록 → 쿠폰 목록(`items` 미선언·init 있음) → 배송정책 목록. 종전: 쿠폰 목록 컨텍스트에 상품 목록의 `items` 가
     * 심기고(init_actions 뒤 병합), 배송정책 목록은 받기 전 「empty」.
     *
     * @scenario case=accumulated_fetched_data_two_screens_back
     * @effects accumulated_fetched_data_not_injected
     */
    it('앞앞 화면이 받은 값이 init_actions 뒤 병합으로 새 화면에 심기지 않는다', async () => {
        const { go, goHeld } = await mount(ROUTES.a);
        await go(ROUTES.c);
        await waitLong(() => expect(ctx().items).toBeUndefined());
        expect((window as any).__templateApp.getDataSource('items')).toBeUndefined();

        const release = await goHeld(ROUTES.b, 'root-b');
        await waitLong(() => expect(ctx().items).toBeUndefined());
        await waitLong(() => expect(text('b-loading')).toBe('loading'));
        await waitLong(() => expect(text('b-empty')).toBeNull());
        await release();
        await waitLong(() => expect(text('b-rows')).toBe('rows:2:B-p1'));
    });

    /**
     * 같은 레이아웃의 다른 경로(상품 1 수정 → 2 수정) — 경로를 읽는 소스(로그)는 이어 쓰지 않고, 읽지 않는 소스(분류)는 이어 쓴다.
     *
     * @scenario case=same_layout_other_path
     * @effects same_layout_other_path_route_source_not_carried, base_source_carried
     */
    it('같은 레이아웃의 다른 경로는 경로를 읽는 소스만 받기 전으로 둔다', async () => {
        const { goHeld } = await mount(ROUTES.f1);
        await waitLong(() => expect(text('f-logs')).toBe('logs:1'));

        const release = await goHeld(ROUTES.f2, 'root-f');
        await waitLong(() => expect(text('f-logs')).toBe('logs-pending'));
        await waitLong(() => expect(text('f-cats')).toBe('cats:2'));
        await release();
        await waitLong(() => expect(text('f-logs')).toBe('logs:2'));
    });
});

/**
 * 창을 여는 동작과 같은 순서 — 대상(전역)을 쓰고 다시 받는다(실제 디스패처 경로).
 *
 * @param id 대상 id
 * @param sourceId 다시 받을 데이터소스
 * @returns 디스패치 결과
 */
const openTarget = (id: string, sourceId = 'issues'): Promise<unknown> => (window as any).G7Core.dispatch({
    handler: 'sequence',
    actions: [
        { handler: 'setState', params: { target: 'global', coupon: { id } } },
        { handler: 'refetchDataSource', params: { dataSourceId: sourceId } },
    ],
});

describe('[case:state-global-refetch-failure-keeps-other-target-value] 다시 받기(refetchDataSource) 실패는 처음 받기와 같은 계약 — 값 null·오류 기록, 다른 대상의 값을 남기지 않는다 (engine-v1.81.2)', () => {
    afterEach(async () => {
        net.failCoupons = {};
        // 이 인스턴스가 예약한 덮개 타이머가 다음 시험에서 터지지 않게 끈다(전역 누수 차단)
        (window as any).__templateApp?.cancelEarlySpinnerTimer?.();
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * 대상 A 내역을 받은 뒤 대상 B 를 열었는데 서버 오류. 종전: B 창에 A 의 내역(마지막 값 키가 대상을 가리지 않음 +
     * 다시 받기 실패가 값을 그대로 둠).
     *
     * @scenario case=refetch_other_target_5xx
     * @effects refetch_other_target_failure_no_previous_value, refetch_failure_null_and_error_recorded
     */
    it('대상 A 를 받은 뒤 대상 B 가 서버 오류면 A 의 값이 남지 않고 null·오류 기록·실패 표시가 선다', async () => {
        await mount(ROUTES.r);
        net.failCoupons = { B: 500 };
        await openTarget('A');
        await waitLong(() => expect(text('r-state')).toBe('rows:A-1'));

        await openTarget('B');
        await waitLong(() => expect(text('r-state')).toBe('null'));
        await waitLong(() => expect(text('r-error')).toBe('err:500'));
        // 레이아웃의 errorHandling 기본값(실패 표시)도 그대로 돈다 — 엔진 null 과 함께 서도 충돌하지 않는다
        await waitLong(() => expect(text('r-flag')).toBe('flag:true'));
        // G7Core.dataSource.get 도 앞 대상 값이 아니라 실패(null)
        expect((window as any).__templateApp.getDataSource('issues')).toBeNull();
    });

    /**
     * 처음 여는 창의 다시 받기가 실패. 종전: 값 undefined 그대로 — 받는 중(흐림)에 머묾.
     *
     * @scenario case=refetch_first_open_failure
     * @effects refetch_failure_null_and_error_recorded
     */
    it('처음 여는 창의 다시 받기가 실패하면 받는 중에 머물지 않고 null·오류 기록이 된다', async () => {
        await mount(ROUTES.r);
        // 마지막 값 보관은 클래스 단위(화면을 넘어 유지)라 앞 사례가 받은 대상과 겹치지 않는 대상을 쓴다
        net.failCoupons = { F: 500 };
        await waitLong(() => expect(text('r-state')).toBe('pending'));

        await openTarget('F');
        await waitLong(() => expect(text('r-state')).toBe('null'));
        await waitLong(() => expect(text('r-error')).toBe('err:500'));
    });

    /**
     * 실패 뒤 다시 성공하면 오류 기록을 지운다. 종전: 다시 받기는 오류 기록을 쓰지도 지우지도 않음.
     *
     * @scenario case=refetch_failure_then_success
     * @effects refetch_success_clears_error
     */
    it('실패 뒤 성공하면 오류 기록이 지워지고 새 값이 보인다', async () => {
        await mount(ROUTES.r);
        net.failCoupons = { B: 500 };
        await openTarget('B');
        await waitLong(() => expect(text('r-error')).toBe('err:500'));

        net.failCoupons = {};
        await openTarget('B');
        await waitLong(() => expect(text('r-state')).toBe('rows:B-1'));
        await waitLong(() => expect(text('r-error')).toBe('err:none'));
        await waitLong(() => expect(text('r-flag')).toBe('flag:false'));
    });

    /**
     * 같은 요청(같은 대상)을 다시 받다 서버 오류 — 마지막 값을 지키는 종전 계약(engine-v1.72.0) 그대로.
     *
     * @scenario case=refetch_same_request_5xx
     * @effects refetch_same_request_failure_keeps_last
     */
    it('같은 대상을 다시 받다 서버 오류면 마지막 값을 지킨다(폴링 계약 유지)', async () => {
        await mount(ROUTES.r);
        await openTarget('A');
        await waitLong(() => expect(text('r-state')).toBe('rows:A-1'));

        net.failCoupons = { A: 500 };
        await openTarget('A');
        await settle(50);
        await waitLong(() => expect(text('r-state')).toBe('rows:A-1'));
        await waitLong(() => expect(text('r-error')).toBe('err:none'));
    });

    /**
     * fallback 을 선언한 소스 — 다른 대상 실패는 fallback 값(첫 그림 계약 그대로). 종전: 키가 같아 A 의 값.
     *
     * @scenario case=refetch_other_target_with_fallback
     * @effects refetch_other_target_failure_no_previous_value
     */
    it('fallback 을 선언한 소스는 다른 대상 실패 때 앞 대상 값이 아니라 fallback 이다', async () => {
        await mount(ROUTES.r);
        net.failCoupons = { B: 500 };
        await openTarget('A', 'issuesFb');
        await waitLong(() => expect(text('r-fb')).toBe('fb:A-1'));

        await openTarget('B', 'issuesFb');
        await waitLong(() => expect(text('r-fb')).toBe('fb:'));
    });

    /**
     * 권한 오류(403)는 같은 대상이어도 마지막 값을 지운다 — 문서 계약(engine-v1.73.0) 「지우고 fallback」, fallback 이 없으면 null.
     * 종전: 다시 받기 실패가 값을 그대로 두어 앞 계정이 받은 값이 남았다.
     *
     * @scenario case=refetch_auth_error_without_fallback
     * @effects refetch_failure_null_and_error_recorded
     */
    it('권한 오류(403)면 fallback 이 없어도 마지막 값을 남기지 않는다', async () => {
        await mount(ROUTES.r);
        await openTarget('A');
        await waitLong(() => expect(text('r-state')).toBe('rows:A-1'));

        net.failCoupons = { A: 403 };
        await openTarget('A');
        await waitLong(() => expect(text('r-state')).toBe('null'));
        await waitLong(() => expect(text('r-error')).toBe('err:403'));
    });

    /**
     * 같은 주소에서 선택(파라미터)만 바꾼 다시 받기가 서버 오류 — 같은 자원의 갱신이라 마지막 값을 지킨다(fallback 빈 목록으로
     * 떨어뜨리지 않는다). 장바구니 선택 변경·폴링 시각 파라미터가 이 꼴이다. 파라미터까지 키에 넣으면 red.
     *
     * @scenario case=refetch_same_path_param_variant_5xx
     * @effects refetch_same_request_failure_keeps_last
     */
    it('같은 주소에서 파라미터만 바꾼 다시 받기가 서버 오류면 fallback 이 아니라 마지막 값을 지킨다', async () => {
        await mount(ROUTES.k);
        const pick = (sel: string): Promise<unknown> => (window as any).G7Core.dispatch({
            handler: 'sequence',
            actions: [
                { handler: 'setState', params: { target: 'global', sel } },
                { handler: 'refetchDataSource', params: { dataSourceId: 'cartLike' } },
            ],
        });
        await pick('1');
        await waitLong(() => expect(text('k-cart')).toBe('items:sel-1'));

        net.failCoupons = { 'cart-2': 500 };
        await pick('2');
        await settle(50);
        await waitLong(() => expect(text('k-cart')).toBe('items:sel-1'));
    });

    /**
     * 처음 받기(progressive) 실패 기록과 다시 받기 실패 기록이 서로를 덮지 않는다. 종전 구현 초안: 각자 컨텍스트 사본으로 통째 교체.
     *
     * @scenario case=refetch_and_progressive_errors_coexist
     * @effects refetch_failure_null_and_error_recorded
     */
    it('처음 받기 실패 기록과 다시 받기 실패 기록이 함께 남는다', async () => {
        await mount(ROUTES.k);
        await waitLong(() => expect(text('k-errors')).toBe('errs:boom'));

        net.failCoupons = { K: 500 };
        await openTarget('K');
        await waitLong(() => expect(text('k-errors')).toBe('errs:boom,issues'));
    });

    /**
     * 다른 화면에서 옮겨 와 init_actions 안 다시 받기가 실패 — 렌더 전 갱신은 렌더가 컨텍스트를 새로 세우며 사라졌다.
     * 이제 받아 둔 값(null)·오류 기록이 init 뒤 병합으로 첫 렌더에 닿는다.
     *
     * @scenario case=init_actions_refetch_failure_after_spa
     * @effects refetch_failure_null_and_error_recorded
     */
    it('SPA 로 옮긴 화면의 init_actions 안 다시 받기가 실패하면 첫 렌더부터 null·오류 기록이다', async () => {
        const { go } = await mount(ROUTES.a);
        net.failCoupons = { INIT: 500 };
        await go(ROUTES.i);
        await waitLong(() => expect(text('i-state')).toBe('null'));
        await waitLong(() => expect(text('i-error')).toBe('err:500'));
    });
});

describe('[case:state-global-route-change-blocking-initlocal-merges-previous-local] 다른 화면의 blocking initLocal 이 앞 화면 폼과 섞이지 않는다 — 같은 레이아웃 다른 경로 포함, 다시 마운트는 하지 않음 (engine-v1.81.2)', () => {
    afterEach(async () => {
        net.hold = false;
        net.pending.splice(0).forEach((release) => release());
        // 이 인스턴스가 예약한 덮개 타이머가 다음 시험에서 터지지 않게 끈다(전역 누수 차단)
        (window as any).__templateApp?.cancelEarlySpinnerTimer?.();
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * 글 96 수정 → 새 글(같은 레이아웃·다른 경로). 종전: 새 글 폼에 앞 글의 id·첨부가 남고(blocking initLocal 이 앞 폼과 깊게
     * 병합), 새 경로의 첫 렌더가 앞 글 폼(본문)으로 그려졌다 — 편집기는 같은 레이아웃이라 다시 마운트되지 않고 경로가 바뀐 뒤
     * 폼 값으로 다시 세우는데(편집기 확장의 경로 기준 재시딩), 첫 렌더 값이 앞 글 본문이면 「아직 새 값 안 옴」과 구분되지 않는다.
     * 컴포넌트는 다시 마운트하지 않는다(같은 레이아웃 경로 이동의 포커스·입력 유지 계약).
     *
     * @scenario case=same_layout_edit_to_write
     * @effects same_layout_other_path_route_source_not_carried
     */
    it('글 수정 → 새 글로 옮기면 앞 글의 폼 키가 남지 않고, 새 경로 첫 렌더부터 앞 글 본문을 보이지 않는다', async () => {
        const { go } = await mount(ROUTES.e96);
        await waitLong(() => expect(text('e-content')).toBe('content:앞 글 본문'));
        await waitLong(() => expect(text('e-id')).toBe('id:96'));
        const mountsBefore = editorMounts.count;

        const seen: string[] = [];
        const observer = new MutationObserver(() => {
            if (window.location.pathname === ROUTES.ew.path) seen.push(text('e-content') ?? '');
        });
        observer.observe(document.body, { childList: true, subtree: true, characterData: true });
        await go(ROUTES.ew);
        // 새 경로의 최종 렌더까지 기다린 뒤 관찰을 끝낸다 — 그 사이 한 번이라도 앞 글 본문이 그려졌으면 잡힌다
        await waitLong(() => expect(text('e-content')).toBe('content:'));
        observer.disconnect();

        expect(seen).not.toContain('content:앞 글 본문');
        await waitLong(() => expect(text('e-id')).toBe('id:none'));
        await waitLong(() => expect(text('e-att')).toBe('att:0'));
        // 다시 마운트하지 않는다
        expect(editorMounts.count).toBe(mountsBefore);
    });

    /**
     * 다른 레이아웃으로 옮겨도 blocking initLocal 이 앞 화면 _local 의 같은 키와 섞이던 문제 — initLocal 계산이 _local 을
     * 비우기 전에 돌아 앞 화면 폼을 깊게 병합했다(회원 수정 → 역할 수정에서 앞 폼 키가 남는 꼴).
     *
     * @scenario case=other_layout_blocking_initlocal_merge
     * @effects other_screen_same_id_not_carried
     */
    it('다른 레이아웃의 blocking initLocal 이 앞 화면 _local 의 같은 키와 섞이지 않는다', async () => {
        const { go } = await mount(ROUTES.la);
        await waitLong(() => expect(text('la-form')).toBe('a:A 의 값 b:none'));

        await go(ROUTES.lb);
        await settle(50);
        await waitLong(() => expect(text('lb-form')).toBe('a:none b:B 의 값'));
        expect((window as any).__templateApp.getGlobalState()._local?.form?.a).toBeUndefined();
    });

    /**
     * 같은 화면에서 쿼리만 바꾼다 — 다시 마운트하지 않고 _local 도 유지한다(검색·쪽·탭의 깜빡임 방지 계약).
     *
     * @scenario case=same_screen_query_change_no_remount
     * @effects same_screen_query_change_carried
     */
    it('같은 화면의 쿼리 변경은 다시 마운트하지 않고 _local 을 유지한다', async () => {
        const { go } = await mount(ROUTES.ewq1);
        await settle(30);
        (window as any).G7Core.dispatch({ handler: 'setState', params: { target: 'local', marker: 'kept' } });
        await settle(30);
        const mountsBefore = editorMounts.count;

        await go(ROUTES.ewq2);
        await settle(50);
        expect(editorMounts.count).toBe(mountsBefore);
        expect((window as any).__templateApp.getGlobalState()._local?.marker).toBe('kept');
    });
});

describe('[case:state-global-route-change-overlay-late] 레이아웃을 받는 동안에도 전환 덮개가 뜬다 (engine-v1.81.2)', () => {
    afterEach(async () => {
        net.hold = false;
        net.layoutDelay = 0;
        net.pending.splice(0).forEach((release) => release());
        // 이 인스턴스가 예약한 덮개 타이머가 다음 시험에서 터지지 않게 끈다(전역 누수 차단)
        (window as any).__templateApp?.cancelEarlySpinnerTimer?.();
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    const spinner = (): Element | null => document.querySelector('#g7-skeleton-overlay');

    /**
     * 서버가 느려 새 레이아웃을 받는 데 오래 걸리면 — 종전: 주소만 바뀐 채 앞 화면이 덮개 없이 남음(상품 수정 → 목록 1~6초).
     * 이제 앞 화면의 덮개 설정으로 잠시 뒤 스피너를 띄우고, 새 화면이 데이터를 받는 동안(wait_for) 이어 보인다.
     *
     * @scenario case=slow_layout_load_spinner
     * @effects early_spinner_during_layout_load
     */
    it('레이아웃을 받는 동안 앞 화면 덮개 설정으로 스피너가 뜨고, 데이터를 받으면 걷힌다', async () => {
        const { go } = await mount(ROUTES.ova);
        await waitLong(() => expect(text('ova')).toBe('screen-a'));
        await waitLong(() => expect(spinner()).toBeNull());

        // 레이아웃 응답을 길게 잡아(부하에서도 스피너 예약 150ms 가 먼저 오도록) 받는 중 구간을 확실히 만든다
        net.layoutDelay = 3000;
        net.hold = true;
        const run = go(ROUTES.ovb);
        // 아직 레이아웃을 받는 중 — 앞 화면 위에 스피너
        await waitLong(() => expect(spinner()).not.toBeNull());
        expect(text('ova')).toBe('screen-a');

        await waitLong(() => expect(text('ovb')).toBe('screen-b'));
        await waitLong(() => expect(net.pending.length).toBeGreaterThan(0));
        // 새 화면이 데이터를 받는 동안(wait_for) 스피너 유지 — 렌더 커밋 뒤 새 대상에 다시 붙는다
        await waitLong(() => expect(spinner()?.isConnected).toBe(true));
        await waitLong(() => expect(document.querySelector("#content_area #g7-skeleton-overlay")).not.toBeNull());
        net.hold = false;
        net.pending.splice(0).forEach((release) => release());
        await run;
        await waitLong(() => expect(spinner()).toBeNull());
    });

    /**
     * 빠른 전환(캐시된 레이아웃)에서는 스피너를 띄우지 않는다 — 깜빡임 방지.
     *
     * @scenario case=fast_layout_load_no_spinner
     * @effects early_spinner_during_layout_load
     */
    it('레이아웃을 바로 받으면 스피너를 띄우지 않는다', async () => {
        // 「바로 받음」을 벽시계(150ms)에 맡기면 병렬 부하에서 판정이 흔들린다 — 이 시험만 예약 시간을 크게 잡아,
        // 레이아웃이 예약보다 먼저 오면 예약이 취소되고 스피너가 그려지지 않는다는 계약만 결정적으로 본다
        const original = (TemplateApp as any).EARLY_SPINNER_DELAY_MS;
        (TemplateApp as any).EARLY_SPINNER_DELAY_MS = 60_000;
        try {
            const { go } = await mount(ROUTES.ova);
            const seen: boolean[] = [];
            const observer = new MutationObserver(() => { seen.push(!!spinner()); });
            observer.observe(document.body, { childList: true, subtree: true });
            await go(ROUTES.ovc);
            await waitLong(() => expect(text('ovc')).toBe('screen-c'));
            observer.disconnect();
            expect(seen.some(Boolean)).toBe(false);
            // 예약은 새 레이아웃을 받는 순간 취소됐다
            expect((window as any).__templateApp.earlySpinnerTimer).toBeNull();
            expect((window as any).__templateApp.earlySpinnerVisible).toBe(false);
        } finally {
            (TemplateApp as any).EARLY_SPINNER_DELAY_MS = original;
        }
    });

    /**
     * 덮개 설정이 없는 화면으로 느리게 옮기면 — 앞당겨 띄운 스피너를 새 화면에서 걷는다.
     *
     * @scenario case=slow_layout_load_to_no_overlay_screen
     * @effects early_spinner_during_layout_load
     */
    it('덮개 설정이 없는 새 화면이면 앞당겨 띄운 스피너를 걷는다', async () => {
        const { go } = await mount(ROUTES.ova);
        net.layoutDelay = 3000;
        const run = go(ROUTES.ovc);
        await waitLong(() => expect(spinner()).not.toBeNull());
        await run;
        await waitLong(() => expect(text('ovc')).toBe('screen-c'));
        await waitLong(() => expect(spinner()).toBeNull());
    });
});

describe('[case:state-global-refetch-other-target-shows-previous-rows] 다시 받기를 다른 대상으로 시작하면 받는 동안 앞 대상 값을 보이지 않는다 (engine-v1.81.2)', () => {
    afterEach(async () => {
        net.hold = false;
        net.failCoupons = {};
        net.pending.splice(0).forEach((release) => release());
        // 이 인스턴스가 예약한 덮개 타이머가 다음 시험에서 터지지 않게 끈다(전역 누수 차단)
        (window as any).__templateApp?.cancelEarlySpinnerTimer?.();
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /** 응답을 잡아 둔 채 대상을 열고, 요청이 나갈 때까지 기다린다 */
    const openHeld = async (id: string, sourceId = 'issues'): Promise<{ run: Promise<unknown> }> => {
        net.hold = true;
        const before = net.pending.length;
        const run = (window as any).G7Core.dispatch({
            handler: 'sequence',
            actions: [
                { handler: 'setState', params: { target: 'global', coupon: { id } } },
                { handler: 'refetchDataSource', params: { dataSourceId: sourceId } },
            ],
        });
        await waitLong(() => expect(net.pending.length).toBeGreaterThan(before));
        await settle(30);

        return { run };
    };
    const releaseAll = async (reverse = false): Promise<void> => {
        net.hold = false;
        const p = net.pending.splice(0);
        (reverse ? p.reverse() : p).forEach((release) => release());
        await settle(30);
    };

    /**
     * 쿠폰 A 발급 내역 → 쿠폰 B(보유기간 대상 작성 IP → 비회원과 같은 꼴). 종전: B 응답 전까지 A 의 행이 「받은 값」으로 보였다.
     *
     * @scenario case=refetch_other_target_in_flight
     * @effects refetch_other_target_starts_unreceived
     */
    it('다른 대상으로 다시 받는 동안 값은 받기 전(undefined)이다 — 앞 대상 행을 보이지 않는다', async () => {
        await mount(ROUTES.r);
        await openTarget('TA');
        await waitLong(() => expect(text('r-state')).toBe('rows:TA-1'));

        const { run } = await openHeld('TB');
        await waitLong(() => expect(text('r-state')).toBe('pending'));
        await releaseAll();
        await run;
        await waitLong(() => expect(text('r-state')).toBe('rows:TB-1'));
    });

    /**
     * 같은 대상을 다시 받는다(같은 주소 재조회) — 받는 동안 앞 값을 그대로 보인다(깜빡임 방지, 종전과 같음).
     *
     * @scenario case=refetch_same_target_in_flight
     * @effects refetch_same_target_keeps_value_in_flight
     */
    it('같은 대상을 다시 받는 동안은 앞 값을 그대로 둔다', async () => {
        await mount(ROUTES.r);
        await openTarget('TS');
        await waitLong(() => expect(text('r-state')).toBe('rows:TS-1'));

        const { run } = await openHeld('TS');
        await waitLong(() => expect(text('r-state')).toBe('rows:TS-1'));
        await releaseAll();
        await run;
    });

    /**
     * 같은 주소에서 파라미터(선택·쪽)만 바꿔 다시 받는다 — 같은 자원의 갱신이라 받는 동안 앞 값을 둔다.
     *
     * @scenario case=refetch_same_path_param_change_in_flight
     * @effects refetch_same_target_keeps_value_in_flight
     */
    it('같은 주소에서 파라미터만 바꿔 다시 받는 동안은 앞 값을 그대로 둔다', async () => {
        await mount(ROUTES.k);
        const pick = (sel: string): Promise<unknown> => (window as any).G7Core.dispatch({
            handler: 'sequence',
            actions: [
                { handler: 'setState', params: { target: 'global', sel } },
                { handler: 'refetchDataSource', params: { dataSourceId: 'cartLike' } },
            ],
        });
        await pick('1');
        await waitLong(() => expect(text('k-cart')).toBe('items:sel-1'));

        net.hold = true;
        const before = net.pending.length;
        const run = pick('2');
        await waitLong(() => expect(net.pending.length).toBeGreaterThan(before));
        await settle(30);
        await waitLong(() => expect(text('k-cart')).toBe('items:sel-1'));
        await releaseAll();
        await run;
        await waitLong(() => expect(text('k-cart')).toBe('items:sel-2'));
    });

    /**
     * A 를 열고 응답 전에 B 를 연다 — A 의 응답이 B 뒤에 와도 B 창에 A 의 행이 그려지지 않는다(겹친 다시 받기는 마지막 것만).
     *
     * @scenario case=refetch_overlap_out_of_order
     * @effects refetch_other_target_starts_unreceived
     */
    it('겹친 다시 받기에서 늦게 온 앞 대상 응답은 버린다', async () => {
        await mount(ROUTES.r);
        const { run: runA } = await openHeld('OA');
        const { run: runB } = await openHeld('OB');
        await releaseAll(true);
        await Promise.all([runA, runB]);
        await settle(50);
        await waitLong(() => expect(text('r-state')).toBe('rows:OB-1'));
    });
});
