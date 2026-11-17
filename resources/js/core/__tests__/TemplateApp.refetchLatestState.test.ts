/**
 * @file TemplateApp.refetchLatestState.test.ts
 * @description 다시 받기는 정본 최신 상태로 요청을 만든다 (engine-v1.95.0, #869 λ 4부 17)
 *
 * `G7Core.state.set` 은 정본 전역 상태(TemplateApp.globalState)를 바로 바꾸지만, `TemplateApp.refetchDataSource` 는 요청 식과 전역 헤더를
 * 다시 그리기 전 화면 문맥(`currentDataContext._global`·`._local`)으로 풀었다. 그래서 state.set 직후의 다시 받기가 앞 값으로 나갔다
 * (늦게 받은 장바구니 키를 싣고 바로 다시 받으면 키 없이 나가 400). 같은 엔진의 `handleApiCall` 과 레이아웃 `refetchDataSource` 핸들러는
 * 이미 최신 상태(`G7Core.state.get`·`getLocal`)를 쓴다. 넘겨받은 override 는 그대로 우선한다.
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
    // 다시 그리기 전 화면 문맥 — 앞 값이 남아 있다
    getState: vi.fn().mockReturnValue({
        reactRoot: null,
        currentLayoutJson: null,
        currentDataContext: { _global: { cartKey: null, layoutWarnings: [] }, _local: { filter: 'old' } },
        bindingEngine: { invalidateCacheByKeys: vi.fn(), clearCache: vi.fn(), startRenderCycle: vi.fn() },
    }),
}));

vi.mock('../template-engine/TransitionManager', () => ({
    transitionManager: { setPending: vi.fn(), getIsPending: vi.fn(() => false), subscribe: vi.fn(() => vi.fn()), clearSubscribers: vi.fn() },
}));

import { TemplateApp } from '../TemplateApp';
import { AuthManager } from '../auth/AuthManager';
import { DataSourceManager } from '../template-engine/DataSourceManager';

describe('다시 받기 — 정본 최신 상태로 요청을 만든다 (engine-v1.95.0)', () => {
    let app: TemplateApp;

    beforeEach(() => {
        apiGet.mockReset();
        apiGet.mockResolvedValue({ success: true, data: { ok: true } });
        (window as any).G7Core = {};
        vi.spyOn(AuthManager.getInstance(), 'isAuthenticated').mockReturnValue(true);
        app = new TemplateApp({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
        (window as any).__templateApp = app;
        (app as any).currentDataSources = [
            {
                id: 'cartItems', type: 'api', endpoint: '/api/test/cart/{{_global.cartKey}}', auth_mode: 'required', auto_fetch: false,
                params: { f: '{{_local.filter}}' }, headers: { 'X-Cart-Key': '{{_global.cartKey}}' },
            },
        ];
        (app as any).currentRouteParams = {};
        (app as any).currentQueryParams = new URLSearchParams();
        (app as any).currentFetchedData = {};
        (app as any).currentGlobalHeaders = [];
    });

    afterEach(() => {
        vi.restoreAllMocks();
        delete (window as any).G7Core;
        delete (window as any).__templateApp;
    });

    /**
     * @scenario path=latest_state_refetch
     * @effects refetch_uses_latest_canonical_state
     */
    it('state.set 직후의 다시 받기는 새 전역 값(주소 식, 헤더)과 새 _local 값으로 요청한다', async () => {
        app.setGlobalState({ cartKey: 'ck_new', _local: { filter: 'new' } });

        await app.refetchDataSource('cartItems');

        expect(apiGet).toHaveBeenCalledTimes(1);
        expect(apiGet.mock.calls[0][0]).toBe('/test/cart/ck_new'); // 결함: '/test/cart/null'
        expect(apiGet.mock.calls[0][1]?.params).toEqual(expect.objectContaining({ f: 'new' })); // 결함: 'old'
        expect(apiGet.mock.calls[0][1]?.headers).toEqual(expect.objectContaining({ 'X-Cart-Key': 'ck_new' })); // 결함: 헤더 없음
    });

    /**
     * @scenario path=latest_state_refetch
     * @effects refetch_uses_latest_canonical_state
     */
    it('setLocal 이 남긴 아직 반영 대기 중인 _local(__g7PendingLocalState)도 다시 받기 요청에 쓴다', async () => {
        app.setGlobalState({ _local: { filter: 'committed' } });
        (window as any).__g7PendingLocalState = { filter: 'pending' };
        try {
            await app.refetchDataSource('cartItems');
        } finally {
            delete (window as any).__g7PendingLocalState;
        }

        expect(apiGet.mock.calls[0][1]?.params).toEqual(expect.objectContaining({ f: 'pending' }));
    });

    /**
     * @scenario path=latest_state_refetch
     * @effects refetch_uses_latest_canonical_state
     */
    it('마지막 setLocal 사본이 정본 B 보다 낡았으면 그 사본을 쓰지 않는다(ρ 9부 M9)', async () => {
        // setLocal → 사본을 지우지 않는 B 쓰기 → 렌더 전 다시 받기 — getLocal() 은 사본을 B 위에 덮어 낡은 값을 돌려준다
        app.setGlobalState({ _local: { filter: 'b-latest' } });
        (window as any).__g7LastSetLocalSnapshot = { filter: 'stale-snapshot' };
        (window as any).G7Core = {
            state: { getLocal: () => ({ ...(app as any).globalState._local, ...(window as any).__g7LastSetLocalSnapshot }) },
        };
        try {
            await app.refetchDataSource('cartItems');
        } finally {
            delete (window as any).__g7LastSetLocalSnapshot;
        }

        expect(apiGet.mock.calls[0][1]?.params).toEqual(expect.objectContaining({ f: 'b-latest' })); // 결함: 'stale-snapshot'
    });

    /**
     * @scenario path=latest_state_refetch
     * @effects refetch_uses_latest_canonical_state
     */
    it('쿼리 갱신(updateQueryParams) 재조회도 정본 최신 _global 로 조건 재평가와 요청을 만든다', async () => {
        const fetchSpy = vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults').mockResolvedValue([] as any);
        (app as any).currentRawDataSources = (app as any).currentDataSources;
        (app as any).currentDataSources[0].auto_fetch = true;
        app.setGlobalState({ cartKey: 'ck_new' });

        await app.updateQueryParams('/shop/cart?page=2');

        expect(fetchSpy).toHaveBeenCalled();
        expect(fetchSpy.mock.calls[0][3]).toEqual(expect.objectContaining({ cartKey: 'ck_new' })); // 결함: 화면 문맥의 null
    });

    /**
     * 위 시험의 소스에는 `if` 가 없어 조건 재평가 기준이 바뀌어도 통과한다(ρ 20부 Z20).
     * 전역 값에 기대는 `if` 를 붙여, state.set 직후 탭이나 필터를 바꿔도 그 소스를 다시 받는지 잰다.
     *
     * @scenario path=latest_state_refetch
     * @effects refetch_uses_latest_canonical_state
     */
    it('쿼리 갱신의 조건 재평가는 정본 최신 _global 로 하므로 state.set 직후 켜진 조건의 소스를 받는다', async () => {
        const fetchSpy = vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults').mockResolvedValue([] as any);
        (app as any).currentRawDataSources = [
            { id: 'cartItems', type: 'api', endpoint: '/api/test/cart/{{_global.cartKey}}', auto_fetch: true, if: '{{!!_global.cartKey}}' },
        ];
        (app as any).currentDataSources = [];
        app.setGlobalState({ cartKey: 'ck_new' });

        await app.updateQueryParams('/shop/cart?page=2');

        const ids = fetchSpy.mock.calls.flatMap((c: any[]) => (c[0] as any[]).map((s) => s.id));
        expect(ids).toContain('cartItems'); // 결함: 화면 문맥의 cartKey null 로 걸러져 받지 않음
    });

    /**
     * @scenario path=latest_state_refetch
     * @effects refetch_override_still_wins
     */
    it('넘겨받은 globalStateOverride·localStateOverride 는 그대로 우선한다', async () => {
        app.setGlobalState({ cartKey: 'ck_new', _local: { filter: 'new' } });

        await app.refetchDataSource('cartItems', { globalStateOverride: { cartKey: 'ck_override' }, localStateOverride: { filter: 'override' } });

        expect(apiGet.mock.calls[0][0]).toBe('/test/cart/ck_override');
        expect(apiGet.mock.calls[0][1]?.params).toEqual(expect.objectContaining({ f: 'override' }));
    });
});
