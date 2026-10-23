/**
 * @file TemplateApp.handlerTreeIsolatedRefetch.test.ts
 * @description 확장 핸들러 대기 목록의 중첩 키 · 다시 받기의 격리 상태 전달 (7.1.3)
 *
 * - E12 `collectNamespacedHandlers()` 가 엔진이 실행하는 중첩 키 중 `onLoad`(loadScript)·`params.callbackAction`(callExternal)을
 *   보지 않아, `loadScript` onLoad 안의 확장 핸들러가 init 대기 목록에서 빠졌다 — 첫 진입에 `Unknown handler` 가 날 수 있었다.
 * - E14 `refetchDataSource` 핸들러가 `isolatedStateOverride` 를 넘기지만 `TemplateApp.refetchDataSource` 가 받지 않아 버려졌다 —
 *   격리 상태 안에서 다시 받는 소스의 주소·파라미터 식 `{{_isolated.*}}` 가 풀리지 않았다.
 *
 * @scenario-manifest tests/scenarios/engine-binding-cache-error-state.yaml
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }));

vi.mock('../api/ApiClient', () => ({
    getApiClient: () => ({
        get: apiGet,
        post: vi.fn(),
        getToken: vi.fn().mockReturnValue('t'),
        setOnUnauthorized: vi.fn(),
        removeToken: vi.fn(),
    }),
}));

vi.mock('../template-engine', () => ({
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: vi.fn().mockResolvedValue(undefined),
    destroyTemplate: vi.fn(),
    releaseTemplateRoot: vi.fn(),
    updateTemplateData: vi.fn(),
    getActionDispatcher: vi.fn(),
    getState: vi.fn().mockReturnValue({
        reactRoot: null,
        currentLayoutJson: null,
        currentDataContext: { _global: {} },
        bindingEngine: { invalidateCacheByKeys: vi.fn(), clearCache: vi.fn(), startRenderCycle: vi.fn() },
    }),
}));

vi.mock('../template-engine/TransitionManager', () => ({
    transitionManager: { setPending: vi.fn(), getIsPending: vi.fn(() => false), subscribe: vi.fn(() => vi.fn()), clearSubscribers: vi.fn() },
}));

import { TemplateApp } from '../TemplateApp';
import { DataSourceManager } from '../template-engine/DataSourceManager';
import { AuthManager } from '../auth/AuthManager';

describe('init 대기 목록 — 엔진이 실행하는 중첩 키 (7.1.3)', () => {
    /**
     * @scenario path=handler_tree
     * @effects namespaced_handlers_collected_from_onload_and_callback
     */
    it('E12. loadScript onLoad·callExternal params.callbackAction 안 확장 핸들러를 모은다', () => {
        const names: string[] = (TemplateApp as any).collectNamespacedHandlers([
            {
                handler: 'loadScript',
                params: { src: 'https://t1.daumcdn.net/x.js' },
                onLoad: { handler: 'sequence', actions: [{ handler: 'sirsoft-daum_postcode.init' }] },
            },
            {
                handler: 'callExternal',
                params: { constructor: 'daum.Postcode', callbackAction: [{ handler: 'sirsoft-ecommerce.fillAddress' }] },
            },
        ], {});

        expect(names).toEqual(expect.arrayContaining(['sirsoft-daum_postcode.init', 'sirsoft-ecommerce.fillAddress'])); // 결함: []
    });

    /**
     * @scenario path=handler_tree
     * @effects namespaced_handlers_collected_from_navigate_fallback
     */
    it('E12. navigate params.fallback 객체(미등록 경로 대체 동작) 안 확장 핸들러를 모은다', () => {
        const names: string[] = (TemplateApp as any).collectNamespacedHandlers([
            {
                handler: 'navigate',
                params: {
                    path: '/shop/legacy',
                    fallback: { handler: 'sequence', actions: [{ handler: 'sirsoft-ecommerce.openLegacyCart' }] },
                },
            },
            // 문자열 fallback 은 그 이름의 핸들러로 실행된다(resolveNavigateFallback) — 확장 핸들러 이름이면 모으고, 내장은 모으지 않는다
            { handler: 'navigate', params: { path: '/x', fallback: 'sirsoft-board.openLegacy' } },
            { handler: 'navigate', params: { path: '/y', fallback: 'openWindow' } },
        ], {});

        expect(names).toEqual(['sirsoft-ecommerce.openLegacyCart', 'sirsoft-board.openLegacy']); // 결함: []
    });
});

describe('다시 받기 — 격리 상태 전달 (7.1.3)', () => {
    let app: TemplateApp;

    beforeEach(() => {
        apiGet.mockReset();
        apiGet.mockResolvedValue({ success: true, data: { ok: true } });
        (window as any).G7Core = {};
        vi.spyOn(AuthManager.getInstance(), 'isAuthenticated').mockReturnValue(true);
        app = new TemplateApp({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
        (app as any).currentDataSources = [
            { id: 'cats', type: 'api', endpoint: '/api/test/cats/{{_isolated.parentId}}', auth_mode: 'required', auto_fetch: false, params: { depth: '{{_isolated.depth}}' } },
        ];
        (app as any).currentRouteParams = {};
        (app as any).currentQueryParams = new URLSearchParams();
        (app as any).currentFetchedData = {};
        (app as any).currentGlobalHeaders = [];
    });

    afterEach(() => {
        vi.restoreAllMocks();
        delete (window as any).G7Core;
    });

    /**
     * @scenario path=isolated_refetch
     * @effects isolated_state_reaches_refetch_request
     */
    it('E14. isolatedStateOverride 가 다시 받기 요청의 {{_isolated.*}} 식에 닿는다', async () => {
        const spy = vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults');

        await app.refetchDataSource('cats', { isolatedStateOverride: { parentId: 7, depth: 2 } } as any);

        expect(spy.mock.calls[0][5]).toEqual(expect.objectContaining({ isolatedState: { parentId: 7, depth: 2 } })); // 결함: 옵션 없음
        expect(apiGet).toHaveBeenCalledTimes(1);
        expect(apiGet.mock.calls[0][0]).toBe('/test/cats/7');
        expect(apiGet.mock.calls[0][1]?.params).toEqual(expect.objectContaining({ depth: 2 }));
    });
});
