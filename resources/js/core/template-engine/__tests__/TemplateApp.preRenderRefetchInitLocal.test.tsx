// e2e:allow 실제 엔진 구조(TemplateApp.handleRouteChange·renderTemplate·DynamicRenderer·DataSourceManager·ActionDispatcher)에 하네스 레이아웃을 올려 SPA 전환과 첫 진입의 첫 렌더 상태를 견준다 — 브라우저 축은 총괄의 번들 재빌드 뒤 매트릭스가 덮는다
/**
 * @file TemplateApp.preRenderRefetchInitLocal.test.tsx
 * @description 첫 렌더 전에 다시 받은 소스의 initLocal·initGlobal 이 SPA 진입에서도 첫 렌더 상태에 실린다 (engine-v1.89.0)
 *
 * 첫 렌더 전 다시 받기는 두 자리에서 난다 — 레이아웃 init_actions 안의 `refetchDataSource`, 그리고 다른 화면으로 옮길 때
 * 새 화면 상태를 세운 뒤로 미룬 blocking onSuccess 안의 다시 받기. 다시 받기는 그 소스의 initLocal 을 `_localInit` 으로
 * `updateTemplateData` 에 넘긴다. 첫 진입은 React 루트가 아직 없어 그 갱신이 대기열에 들어갔다가 첫 렌더 뒤 적용되지만,
 * SPA 진입은 떠나는 화면 문맥에 적용됐다가 새 화면 렌더가 문맥을 새로 세우며 버려졌다. 그래서 같은 주소를 바로 열면
 * 폼이 채워지고 SPA 로 들어오면 빈 폼이었다.
 *
 * 하네스: 실물 `TemplateApp.handleRouteChange`(레이아웃 로더만 대역) · 실물 `renderTemplate`·`DynamicRenderer`·
 * `DataSourceManager`·`ActionDispatcher`. 번들 레이아웃 id 에 기대지 않는다.
 *
 * @scenario-manifest tests/scenarios/pre-render-refetch-initlocal.yaml
 *
 * 잰 효과는 각 시험의 주석에 적었다.
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
    // 앞 화면 — init_actions 로 `_local` 에 값을 남긴다(새 화면으로 새지 않아야 한다)
    start: {
        layout_name: 'start',
        data_sources: [],
        init_actions: [{ handler: 'setState', params: { target: 'local', leftover: 'start-only' } }],
        components: root('root-s', [span('s-left', "{{'left:' + (_local.leftover ?? 'none')}}")]),
    },
    // init_actions 안에서 다시 받는 화면 — 받은 프로필이 initLocal(form)·initGlobal(profileG)을 세운다
    init_refetch: {
        layout_name: 'init_refetch',
        data_sources: [
            src('profile', '/api/test/profile', { auto_fetch: false, initLocal: 'form', initGlobal: 'profileG' }),
        ],
        init_actions: [{ handler: 'refetchDataSource', params: { dataSourceId: 'profile' } }],
        components: root('root-ir', [
            span('ir-local', "{{'local:' + (_local.form?.name ?? 'none')}}"),
            span('ir-global', "{{'global:' + (_global.profileG?.name ?? 'none')}}"),
            span('ir-left', "{{'left:' + (_local.leftover ?? 'none')}}"),
        ]),
    },
    // init_actions 가 다시 받은 뒤 같은 키(form)의 하위 값을 setState 로 고치는 화면 — 첫 진입과 SPA 결과가 같아야 한다
    init_refetch_then_set: {
        layout_name: 'init_refetch_then_set',
        data_sources: [
            src('profile', '/api/test/profile', { auto_fetch: false, initLocal: 'form' }),
        ],
        init_actions: [
            { handler: 'refetchDataSource', params: { dataSourceId: 'profile' } },
            { handler: 'setState', params: { target: 'local', 'form.extra': 'after', other: 'kept' } },
        ],
        components: root('root-rs', [
            span('rs-state', "{{'name:' + (_local.form?.name ?? 'none') + ' extra:' + (_local.form?.extra ?? 'none') + ' other:' + (_local.other ?? 'none')}}"),
        ]),
    },
    // blocking initLocal 과 init 안 다시 받기 initLocal 이 같은 키(form)를 채우는 화면 — 두 응답의 하위 키가 모두 남아야 한다
    shared_key: {
        layout_name: 'shared_key',
        data_sources: [
            src('base', '/api/test/base', { loading_strategy: 'blocking', initLocal: 'form' }),
            src('extra', '/api/test/extra', { auto_fetch: false, initLocal: 'form' }),
        ],
        init_actions: [{ handler: 'refetchDataSource', params: { dataSourceId: 'extra' } }],
        components: root('root-sk', [
            span('sk-state', "{{'a:' + (_local.form?.a ?? 'none') + ' b:' + (_local.form?.b ?? 'none')}}"),
        ]),
    },
    // blocking onSuccess 안에서 다시 받는 화면(다른 화면 전환이면 새 화면 상태를 세운 뒤로 미뤄진다)
    onsuccess_refetch: {
        layout_name: 'onsuccess_refetch',
        data_sources: [
            src('claim', '/api/test/claims/{{route.claim_number}}', {
                loading_strategy: 'blocking',
                onSuccess: [{ handler: 'refetchDataSource', params: { dataSourceId: 'detail' } }],
            }),
            src('detail', '/api/test/detail', { auto_fetch: false, initLocal: { detailName: 'name' }, initGlobal: 'detailG' }),
        ],
        components: root('root-or', [
            span('or-local', "{{'local:' + (_local.detailName ?? 'none')}}"),
            span('or-global', "{{'global:' + (_global.detailG?.name ?? 'none')}}"),
            span('or-left', "{{'left:' + (_local.leftover ?? 'none')}}"),
        ]),
    },
};

/** 서버 응답 — 모양은 실제 API 꼴(`{ success, data }`) */
function respond(url: string): any {
    if (url === '/test/profile') return { success: true, data: { name: '프로필' } };
    if (url === '/test/claims/RT-1') return { success: true, data: { claim: { order_number: '481' } } };
    if (url === '/test/detail') return { success: true, data: { name: '상세' } };
    if (url === '/test/base') return { success: true, data: { a: 'A' } };
    if (url === '/test/extra') return { success: true, data: { b: 'B' } };

    return { success: true, data: {} };
}

interface RouteSpec {
    path: string;
    layout: string;
    params?: Record<string, string>;
}

const ROUTES = {
    start: { path: '/mypage', layout: 'start' },
    initRefetch: { path: '/mypage/profile', layout: 'init_refetch' },
    sharedKey: { path: '/mypage/shared', layout: 'shared_key' },
    refetchThenSet: { path: '/mypage/profile-set', layout: 'init_refetch_then_set' },
    onsuccessRefetch: { path: '/mypage/claims/RT-1', layout: 'onsuccess_refetch', params: { claim_number: 'RT-1' } },
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

describe('[case:state-global-pre-render-refetch-initlocal-lost-on-spa] 첫 렌더 전에 다시 받은 소스의 initLocal·initGlobal 이 SPA 진입에서도 첫 렌더 상태에 실린다 (engine-v1.89.0)', () => {
    afterEach(async () => {
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * 기준 — init_actions 다시 받기 화면을 바로 연다(종전에도 통과).
     *
     * @scenario case=init_refetch_direct_entry
     * @effects pre_render_refetch_direct_entry_unchanged
     */
    it('init_actions 다시 받기 화면을 바로 열면 initLocal·initGlobal 이 선다', async () => {
        await mount(ROUTES.initRefetch);
        await waitLong(() => expect(text('ir-local')).toBe('local:프로필'));
        expect(text('ir-global')).toBe('global:프로필');
    });

    /**
     * 앞 화면 → init_actions 다시 받기 화면(SPA). 종전: 「local:none」.
     *
     * @scenario case=init_refetch_spa_entry
     * @effects init_actions_refetch_initlocal_on_first_render, init_actions_refetch_initglobal_on_first_render, pre_render_refetch_previous_local_not_carried
     */
    it('SPA 로 들어와도 init_actions 안 다시 받기의 initLocal·initGlobal 이 첫 렌더 상태에 있다', async () => {
        const { app, go } = await mount(ROUTES.start);
        await waitLong(() => expect(text('s-left')).toBe('left:start-only'));

        await go(ROUTES.initRefetch);
        await waitLong(() => expect(document.querySelector('[data-testid="root-ir"]')).not.toBeNull());
        await waitLong(() => expect(text('ir-local')).toBe('local:프로필'));
        expect(text('ir-global')).toBe('global:프로필');
        expect(text('ir-left')).toBe('left:none');
        // 정본 B 에도 실린다(핸들러의 getLocal·다음 setState 기준)
        expect(app.getGlobalState()._local.form?.name).toBe('프로필');
    });

    /**
     * 기준 — blocking onSuccess 다시 받기 화면을 바로 연다.
     *
     * @scenario case=onsuccess_refetch_direct_entry
     * @effects pre_render_refetch_direct_entry_unchanged
     */
    it('blocking onSuccess 다시 받기 화면을 바로 열면 initLocal·initGlobal 이 선다', async () => {
        await mount(ROUTES.onsuccessRefetch);
        await waitLong(() => expect(text('or-local')).toBe('local:상세'));
        expect(text('or-global')).toBe('global:상세');
    });

    /**
     * 앞 화면 → blocking onSuccess 다시 받기 화면(SPA, 미룬 onSuccess). 종전: 「local:none」.
     *
     * @scenario case=onsuccess_refetch_spa_entry
     * @effects deferred_onsuccess_refetch_initlocal_on_first_render, pre_render_refetch_previous_local_not_carried
     */
    it('SPA 로 들어와도 미룬 onSuccess 안 다시 받기의 initLocal·initGlobal 이 첫 렌더 상태에 있다', async () => {
        const { app, go } = await mount(ROUTES.start);
        await waitLong(() => expect(text('s-left')).toBe('left:start-only'));

        await go(ROUTES.onsuccessRefetch);
        await waitLong(() => expect(document.querySelector('[data-testid="root-or"]')).not.toBeNull());
        await waitLong(() => expect(text('or-local')).toBe('local:상세'));
        expect(text('or-global')).toBe('global:상세');
        expect(text('or-left')).toBe('left:none');
        expect(app.getGlobalState()._local.detailName).toBe('상세');
    });

    /**
     * 렌더 뒤의 다시 받기는 종전 경로(updateTemplateData 의 `_localInit`)로 반영된다 — 수집하지 않는다.
     *
     * @scenario case=post_render_refetch
     * @effects post_render_refetch_unchanged
     */
    it('렌더 뒤 다시 받기는 종전처럼 바로 반영되고 렌더 전 수집 상자는 닫혀 있다', async () => {
        const { app, go } = await mount(ROUTES.start);
        await go(ROUTES.initRefetch);
        await waitLong(() => expect(text('ir-local')).toBe('local:프로필'));
        expect((app as any).preRenderInitCollector ?? null).toBeNull();

        await app.refetchDataSource('profile');
        await waitLong(() => expect(text('ir-local')).toBe('local:프로필'));
        expect((app as any).preRenderInitCollector ?? null).toBeNull();
    });

    /**
     * init_actions 가 다시 받은 뒤 같은 키의 하위 값을 setState 로 고친다 — 받은 순서대로 쌓이고 SPA 진입의 결과가 바로 연 결과와 같다.
     * 종전: 바로 열기 「name:none extra:after」(첫 렌더 뒤 대기열 적용이 사이 값을 덮음), 이 회차 1차 수정 SPA 「extra:none」.
     *
     * @scenario case=init_refetch_then_setstate_parity
     * @effects pre_render_refetch_matches_direct_entry_order, pre_render_refetch_then_setstate_accumulates
     */
    it('다시 받은 뒤 같은 키를 setState 한 결과가 바로 열기와 SPA 진입에서 같다', async () => {
        await mount(ROUTES.refetchThenSet);
        await waitLong(() => expect(text('rs-state')).toContain('name:프로필'));
        await settle(50);
        const direct = text('rs-state');
        // 받은 순서대로 쌓인다 — 다시 받은 form 위에 init 의 setState(form.extra)가 얹힌다
        expect(direct).toBe('name:프로필 extra:after other:kept');
        engine.destroyTemplate();
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
        vi.restoreAllMocks();

        const { go } = await mount(ROUTES.start);
        await waitLong(() => expect(text('s-left')).toBe('left:start-only'));
        await go(ROUTES.refetchThenSet);
        await waitLong(() => expect(document.querySelector('[data-testid="root-rs"]')).not.toBeNull());
        await waitLong(() => expect(text('rs-state')).toContain('name:프로필'));
        await settle(50);
        expect(text('rs-state')).toBe(direct);
    });

    /**
     * blocking initLocal 과 init 안 다시 받기 initLocal 이 같은 키를 채운다 — 바로 열기와 SPA 모두 두 응답의 하위 키가 남는다.
     *
     * @scenario case=blocking_and_refetch_same_key
     * @effects pre_render_refetch_and_blocking_initlocal_both_kept
     */
    it('blocking initLocal 과 다시 받기 initLocal 이 같은 키를 채워도 두 하위 키가 모두 남는다', async () => {
        await mount(ROUTES.sharedKey);
        await waitLong(() => expect(text('sk-state')).toBe('a:A b:B'));
        engine.destroyTemplate();
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
        vi.restoreAllMocks();

        const { go } = await mount(ROUTES.start);
        await go(ROUTES.sharedKey);
        await waitLong(() => expect(document.querySelector('[data-testid="root-sk"]')).not.toBeNull());
        await waitLong(() => expect(text('sk-state')).toBe('a:A b:B'));
        await settle(50);
        expect(text('sk-state')).toBe('a:A b:B');
    });
});
