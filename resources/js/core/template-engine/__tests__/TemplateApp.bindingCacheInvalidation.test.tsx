// e2e:allow 실제 엔진 구조(TemplateApp·renderTemplate·DynamicRenderer·DataSourceManager·G7CoreGlobals)에 하네스 레이아웃을 올려 단순 경로 바인딩의 영구 캐시를 잰다 — 브라우저 축은 7.1.3 출시 전 B6·B7 실측(글 보기 비밀번호 확인·더보기·관리자 목록 검색 실패·오류 화면 연속)이 덮는다
/**
 * @file TemplateApp.bindingCacheInvalidation.test.tsx
 * @description 화면 전환이 없는 경로에서 데이터가 바뀌어도 단순 경로 바인딩(`{{a.b.c}}`)이 30초 영구 캐시의 옛 값으로 남던 결함 (7.1.3)
 *
 * 바인딩 엔진은 `?.`·연산자가 없는 단순 경로 값을 경로 글자를 키로 30초 동안 영구 캐시에 둔다. 그래서 데이터가 바뀌는 곳마다
 * 그 키의 캐시를 비워야 새 값이 보인다. 7.1.2 는 화면 전환 때 캐시를 비웠지만, 아래 경로는 화면 전환이 없어 그대로 남았다.
 * - E1 `G7Core.dataSource.set` (updateDataSource 핸들러)
 * - E2 `G7Core.dataSource.updateItem`
 * - E3 `G7Core.dataSource.updateData` (appendDataSource 핸들러)
 * - E4 progressive 데이터 소스 받기 실패(같은 화면 쿼리 이동에서 앞 쪽 값이 이어진 상태)
 * - E5 `updateQueryParams` 다시 받기 실패 — 오류 기록도 없었다
 * - E6 `reloadExtensionState` 의 `$locales` — 화면 컨텍스트 병합 순서상 옛 값이 전역 변수 새 값을 이겼다
 * - E7 오류 화면을 같은 화면에서 403 → 500 연속으로 그리면 `{{errorCode}}` 가 403
 *
 * 하네스: 실물 `TemplateApp`(레이아웃 로더만 대역) · 실물 `renderTemplate`·`DynamicRenderer`·`DataSourceManager`·`ActionDispatcher`·
 * `G7CoreGlobals`. API 는 ApiClient 대역.
 *
 * @scenario-manifest tests/scenarios/engine-binding-cache-error-state.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import { waitFor } from '@testing-library/react';

const net = vi.hoisted(() => ({
    respond: (_url: string, _params: any): any => ({ success: true, data: {} }),
}));

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({
        // 응답은 네트워크처럼 조금 늦게 온다 — 첫 렌더(앞 쪽 값을 이어 그린 화면)가 커밋된 뒤에 도착하는 실제 순서
        get: (url: string, cfg?: { params?: any }) => new Promise((resolve, reject) => {
            const body = net.respond(url, cfg?.params ?? {});
            setTimeout(() => {
                if (body && body.__reject) reject(Object.assign(new Error('Request failed'), { response: body.__reject }));
                else resolve(body);
            }, 30);
        }),
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

// 단순 경로 바인딩은 props 에 둔다 — 텍스트(`text`)는 렌더러가 캐시 없이(skipCache) 풀어 영구 캐시를 타지 않는다
const span = (testid: string, expr: string): Node => ({ id: `s_${testid}`, type: 'basic', name: 'Span', props: { 'data-testid': testid, 'data-v': expr } });

const LAYOUTS: Record<string, Node> = {
    // 단순 경로만 그리는 화면 — 영구 캐시를 타는 바인딩
    probe: {
        layout_name: 'probe',
        // 쪽 번호가 주소 경로에 들어가는 소스 — 같은 주소의 파라미터만 바뀐 실패는 마지막 값을 지키는 계약(engine-v1.82.0)이라, 「다른 요청」의 실패를 만든다
        data_sources: [{ id: 'probe', type: 'api', endpoint: '/api/test/probe/{{query.page ?? 1}}', auth_mode: 'required' }],
        components: [{
            id: 'root', type: 'basic', name: 'Div', props: { id: 'main_content', 'data-testid': 'root' }, children: [
                span('total', '{{probe.data.total}}'),
                span('first', '{{probe.data.items.0.name}}'),
                span('count', '{{probe.data.items.length}}'),
                span('err', '{{_dataSourceErrors.probe.status}}'),
                span('locales', '{{$locales.length}}'),
            ],
        }],
    },
    e403: { layout_name: 'e403', components: [span('code', '{{errorCode}}'), span('epath', '{{route.path}}'), span('eflag', '{{_global.flag}}')] },
    e500: { layout_name: 'e500', components: [span('code', '{{errorCode}}'), span('epath', '{{route.path}}'), span('eflag', '{{_global.flag}}')] },
};

function respond(url: string, _params: any): any {
    const page = url.match(/^\/test\/probe\/(\d+)$/)?.[1];
    if (page) {
        if (page === '2') return { __reject: { status: 500, data: { message: 'boom' } } };

        return { success: true, data: { total: 1, items: [{ id: 1, name: 'A' }] } };
    }

    return { success: true, data: {} };
}

const waitLong = <T,>(cb: () => T): Promise<T> => waitFor(cb, { timeout: 10_000, interval: 15 });
vi.setConfig({ testTimeout: 60_000 });
const text = (testid: string): string | null => document.querySelector(`[data-testid="${testid}"]`)?.getAttribute('data-v') ?? null;
const G7 = (): any => (window as any).G7Core;

/**
 * 실물 엔진을 올리고 probe 화면으로 들어간다.
 *
 * @param query 첫 진입 쿼리
 * @returns 앱과 이동 함수
 */
async function mount(query: Record<string, string> = {}): Promise<{ app: any; go: (q: Record<string, string>) => Promise<void> }> {
    document.body.innerHTML = '<div id="app"></div>';
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
    };
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));
    (app as any).layoutLoader = {
        loadLayout: async (_templateId: string, layoutPath: string) => JSON.parse(JSON.stringify({ version: '1.0.0', ...LAYOUTS[layoutPath.replace(/^[^.]+\./, '')] })),
    };
    const go = async (q: Record<string, string>): Promise<void> => {
        const qs = new URLSearchParams(q).toString();
        window.history.pushState({}, '', '/admin/probe' + (qs ? `?${qs}` : ''));
        await (app as any).handleRouteChange({ path: '/admin/probe', layout: 'probe', params: {}, query: q });
    };
    await go(query);
    await waitLong(() => expect(text('total')).toBe('1'));

    return { app, go };
}

describe('화면 전환 없는 데이터 갱신 뒤 단순 경로 바인딩은 곧바로 새 값이다 (7.1.3)', () => {
    afterEach(() => {
        engine.destroyTemplate?.();
        vi.restoreAllMocks();
        delete (window as any).__templateApp;
    });

    /**
     * @scenario path=dataSource_set
     * @effects simple_path_follows_datasource_set
     */
    it('E1. G7Core.dataSource.set 직후 {{probe.data.total}} 이 새 값이다(30초 대기 없이)', async () => {
        await mount();
        G7().dataSource.set('probe', { data: { total: 2, items: [{ id: 1, name: 'A' }] } });
        await waitLong(() => expect(engine.getState().currentDataContext.probe?.data?.total).toBe(2));
        await waitLong(() => expect(text('total')).toBe('2')); // 결함: '1'
    });

    /**
     * @scenario path=dataSource_updateItem
     * @effects simple_path_follows_datasource_update_item
     */
    it('E2. G7Core.dataSource.updateItem 직후 {{probe.data.items.0.name}} 이 새 값이다', async () => {
        await mount();
        expect(text('first')).toBe('A');
        expect(G7().dataSource.updateItem('probe', 'data.items', 1, { name: 'B' })).toBe(true);
        await waitLong(() => expect(text('first')).toBe('B')); // 결함: 'A'
    });

    /**
     * @scenario path=dataSource_updateData
     * @effects simple_path_follows_datasource_append
     */
    it('E3. G7Core.dataSource.updateData(append) 직후 {{probe.data.items.length}} 가 늘어난다', async () => {
        await mount();
        expect(text('count')).toBe('1');
        expect(G7().dataSource.updateData('probe', 'data.items', [{ id: 2, name: 'B' }], 'append')).toBe(true);
        await waitLong(() => expect(text('count')).toBe('2')); // 결함: '1'
    });

    /**
     * @scenario path=progressive_failure
     * @effects progressive_failure_clears_simple_path
     */
    it('E4. 같은 화면 쿼리 이동에서 progressive 받기가 실패하면 앞 쪽 값이 단순 경로에 남지 않고 오류가 보인다', async () => {
        const { go } = await mount();
        await go({ page: '2' });
        await waitLong(() => expect(text('err')).toBe('500'));
        expect(engine.getState().currentDataContext.probe).toBeNull();
        expect(text('total')).toBeNull(); // 결함: '1' (앞 쪽 값이 이어진 첫 렌더가 영구 캐시에 남김) — 값이 없으면 속성이 빠진다
    });

    /**
     * @scenario path=query_update_failure
     * @effects query_update_failure_recorded
     */
    it('E5. updateQueryParams 다시 받기가 실패하면 오류 기록·null 이 되고 옛 쿼리 결과가 남지 않는다', async () => {
        const { app } = await mount();
        await app.updateQueryParams('/admin/probe?page=2');
        await waitLong(() => expect(text('err')).toBe('500')); // 결함: '' (오류 기록 없음)
        expect(engine.getState().currentDataContext._dataSourceErrors?.probe?.status).toBe(500);
        expect(engine.getState().currentDataContext.probe).toBeNull();
        expect(text('total')).toBeNull(); // 결함: '1'
    });

    /**
     * @scenario path=query_update_failure
     * @effects query_update_success_clears_error
     */
    it('E5. 실패 뒤 같은 소스가 다시 성공하면 오류 기록이 지워진다', async () => {
        const { app } = await mount();
        await app.updateQueryParams('/admin/probe?page=2');
        await waitLong(() => expect(text('err')).toBe('500'));
        await app.updateQueryParams('/admin/probe?page=1');
        await waitLong(() => expect(text('total')).toBe('1'));
        expect(text('err')).toBeNull();
        expect(engine.getState().currentDataContext._dataSourceErrors?.probe).toBeUndefined();
    });

    /**
     * @scenario path=reload_extension_state
     * @effects locales_follow_reload_extension_state
     */
    it('E6. reloadExtensionState 로 활성 언어가 늘면 {{$locales.length}} 가 곧바로 새 값이다', async () => {
        const { app } = await mount();
        expect(text('locales')).toBe('2');
        const fetchSpy = vi.spyOn(globalThis, 'fetch' as any).mockImplementation(async (input: any) => {
            const url = String(input);
            if (url.startsWith('/api/locales/active')) {
                return { ok: true, json: async () => ({ data: { locales: ['ko', 'en', 'ja'] } }) } as any;
            }

            return { ok: false, json: async () => ({}) } as any;
        });
        await app.reloadExtensionState();
        expect(fetchSpy).toHaveBeenCalled();
        await waitLong(() => expect(text('locales')).toBe('3')); // 결함: '2'
    });

    /**
     * @scenario path=error_page
     * @effects error_page_uses_latest_state_and_code
     */
    it('E7. 같은 화면에서 403 → 500 오류 화면을 연속으로 그리면 500 이고, 최신 _global·route 가 실린다', async () => {
        const { app } = await mount();
        (app as any).errorPageHandler = (app as any).createErrorPageHandler();
        vi.spyOn(globalThis, 'fetch' as any).mockImplementation(async (input: any) => {
            if (String(input).includes('/config')) {
                return { ok: true, json: async () => ({ success: true, data: { error_config: { layouts: { 403: 'e403', 500: 'e500' } } } }) } as any;
            }

            return { ok: false, json: async () => ({}) } as any;
        });
        // 오류 화면 핸들러를 만든 뒤 전역 상태를 새 객체로 바꾼다(setGlobalState 는 재대입)
        app.setGlobalState({ flag: 'fresh' });

        const handler = app.getErrorPageHandler();
        expect(await handler.renderError(403, 'app')).toBe(true);
        await waitLong(() => expect(text('code')).toBe('403'));
        expect(text('eflag')).toBe('fresh'); // 결함: '' (생성 때 받은 옛 _global 참조)
        expect(text('epath')).toBe('/admin/probe'); // 결함: '' (route 미전달)

        expect(await handler.renderError(500, 'app')).toBe(true);
        await waitLong(() => expect(text('code')).toBe('500')); // 결함: '403'
    });
});
