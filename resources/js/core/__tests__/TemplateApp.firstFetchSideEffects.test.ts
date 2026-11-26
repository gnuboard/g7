/**
 * @file TemplateApp.firstFetchSideEffects.test.ts
 * @description 화면 처리의 첫 받기와 미룬 blocking onSuccess 의 출시본 동작 잠금
 *
 * 화면 처리(handleRouteChange)의 첫 받기는 화면 세대로만 걸러 그대로 실린다. 그 사이 쪽 넘김(쿼리 갱신)이나 수동 소스 다시 받기가
 * 나가도 첫 받기 값과 그에 딸린 일(initGlobal, 실시간 알림 구독 문맥)이 빠지지 않는다. 다른 레이아웃으로 옮기는 전환의 미룬 blocking
 * onSuccess 는 앞 화면이 남긴 반영 대기 값을 비우고 화면을 잠근 채 돈다.
 *
 * 이 시험들은 언어 전환 회차의 시험 파일에 있다가, 설계 확정(2026-11-10)으로 언어 전환 연쇄를 걷으면서 그 파일을 지우고
 * 이 파일로 옮겼다. 언어 전환과 무관하게 성립하는 동작이다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher, renderTemplateMock, updateTemplateDataMock, initTemplateEngineMock } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    setNamedActions: vi.fn(),
    setGlobalHeaders: vi.fn(),
    isPreviewMode: vi.fn(() => false),
    setPreviewMode: vi.fn(),
    customHandlers: new Map(),
  },
  renderTemplateMock: vi.fn().mockResolvedValue(undefined),
  updateTemplateDataMock: vi.fn(),
  initTemplateEngineMock: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('../template-engine', async () => {
  const actual = await vi.importActual<any>('../template-engine/DataSourceManager');
  return {
    initTemplateEngine: initTemplateEngineMock,
    renderTemplate: renderTemplateMock,
    destroyTemplate: vi.fn(),
    releaseTemplateRoot: vi.fn(),
    updateTemplateData: updateTemplateDataMock,
    DataSourceManager: actual.DataSourceManager,
    getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
    getState: vi.fn().mockReturnValue({
      actionDispatcher: sharedActionDispatcher,
      reactRoot: null,
      currentLayoutJson: null,
      currentDataContext: { _global: {} },
      bindingEngine: { invalidateCacheByKeys: vi.fn(), clearCache: vi.fn(), startCacheEpoch: vi.fn(() => 1), startRenderCycle: vi.fn() },
    }),
  };
});

vi.mock('../template-engine/TransitionManager', () => ({
  transitionManager: {
    setPending: vi.fn(),
    getIsPending: vi.fn(() => false),
    subscribe: vi.fn(() => vi.fn()),
    clearSubscribers: vi.fn(),
  },
}));

vi.mock('../routing/Router', () => ({
  Router: vi.fn(function (this: any) {
    this.loadRoutes = vi.fn().mockResolvedValue(undefined);
    this.on = vi.fn();
    this.navigateToCurrentPath = vi.fn();
    this.getRoutes = vi.fn().mockReturnValue([]);
  }),
}));

vi.mock('../template-engine/LayoutLoader', () => ({
  LayoutLoader: vi.fn(function (this: any) {
    this.loadLayout = vi.fn().mockResolvedValue({ components: [] });
    this.clear = vi.fn();
  }),
  LayoutLoaderError: class LayoutLoaderError extends Error {},
}));

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance: any = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getMissingExtensionComponents: vi.fn().mockReturnValue([]),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';
import { DataSourceManager } from '../template-engine/DataSourceManager';

/** 관리자 환경설정(언어팩 탭) 축약 — 상세 창을 열 때만 받는 수동 소스가 `_local.detailId` 로 대상을 고른다 */
const LAYOUT = {
  layout_name: 'admin_settings',
  components: [{ id: 'root', type: 'basic', name: 'Div' }],
  data_sources: [
    { id: 'settings', type: 'api', endpoint: '/api/admin/settings', auto_fetch: true },
    { id: 'language_pack_detail', type: 'api', endpoint: '/api/admin/language-packs/{{_local.detailId}}', method: 'GET', auto_fetch: false, auth_required: true },
  ],
};

const ROUTE = { path: '/admin/settings', layout: 'admin_settings', params: {}, query: { tab: 'language_packs' } };

describe('TemplateApp 화면 처리 — 첫 받기와 미룬 blocking onSuccess', () => {
  let app: TemplateApp;
  let fetchSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    window.history.replaceState(null, '', ROUTE.path + '?tab=language_packs');
    (window as any).G7Core = {};
    renderTemplateMock.mockClear();
    updateTemplateDataMock.mockClear();
    initTemplateEngineMock.mockClear();
    localStorage.setItem('auth_token', 'test-token');

    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko' } as any);
    const a = app as any;
    a.layoutLoader = { loadLayout: vi.fn().mockResolvedValue(LAYOUT), clear: vi.fn() };
    a.router = { navigateToCurrentPath: vi.fn(async () => { await a.handleRouteChange(ROUTE); }) };
    a.currentLayoutName = 'admin_settings';
    a.currentRoutePath = ROUTE.path;
    a.globalState = { _local: { detailId: 2 } };
    a.currentDataSources = LAYOUT.data_sources;
    a.currentRawDataSources = LAYOUT.data_sources;
    a.currentFetchedData = { language_pack_detail: { data: { id: 1, name: '한국어' } } };

    fetchSpy = vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults').mockImplementation(async (list: any[], _r?: any, _q?: any, _g?: any, _l?: any, opts?: any) =>
      list.filter((s: any) => s.auto_fetch !== false || opts?.ignoreAutoFetch === true).map((s: any) => ({
        id: s.id,
        state: 'success',
        data: { data: { id: s.id } },
      })) as any,
    );
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    localStorage.removeItem('auth_token');
    delete (window as any).G7Core;
  });

  /**
   * 출시본 동작 잠금(설계 확정 2026-11-10 순번 장치 되돌리기): 화면을 처음 받는 사이 쪽 넘김(쿼리 갱신)이 나가도 화면 처리의 첫 받기
   * 응답은 화면 세대로만 거르므로 그대로 실린다 — 실시간 알림 구독 문맥(`current_user`, initGlobal 로 실은 `_global.currentUser`)이 비지 않는다.
   *
   * @scenario case=first_fetch_side_effects
   * @effects first_fetch_applied_for_subscription
   */
  it('첫 받기 중 쪽을 넘겨도 실시간 알림 구독 문맥에 current_user 와 initGlobal 값이 실린다', async () => {
    const a = app as any;
    const WS_LAYOUT = {
      ...LAYOUT,
      data_sources: [
        // 사용자 베이스 레이아웃(`_user_base.json`)과 같은 형식 — 채널이 `_global.currentUser` 를 쓴다
        { id: 'current_user', type: 'api', endpoint: '/api/me', auto_fetch: true, initGlobal: 'currentUser' },
        { id: 'notification_ws', type: 'websocket', channel: 'core.user.notifications.{{current_user.data.uuid}}', event: 'x' },
      ],
    };
    a.layoutLoader = { loadLayout: vi.fn().mockResolvedValue(WS_LAYOUT), clear: vi.fn() };
    let releaseFirst: () => void = () => {};
    const firstHeld = new Promise<void>((resolve) => { releaseFirst = resolve; });
    let releaseQuery: () => void = () => {};
    const queryHeld = new Promise<void>((resolve) => { releaseQuery = resolve; });
    fetchSpy.mockImplementation(async (list: any[], _r?: any, _q?: any, _g?: any, _l?: any, opts?: any) => {
      const isQueryUpdate = opts && opts.refetch !== undefined && opts.ignoreAutoFetch === false;
      await (isQueryUpdate ? queryHeld : firstHeld);
      return list.filter((s: any) => s.type !== 'websocket').map((s: any) => ({ id: s.id, state: 'success', data: { data: { uuid: 'u1' } } })) as any;
    });
    const contexts: any[] = [];
    vi.spyOn(DataSourceManager.prototype, 'subscribeWebSockets').mockImplementation(((_s: any, _cb: any, ctx: any) => { contexts.push(ctx); return []; }) as any);

    const nav = a.handleRouteChange(ROUTE);
    await new Promise((resolve) => setTimeout(resolve, 30));
    const paging = a.updateQueryParams('/admin/settings?tab=language_packs&page=2');
    await new Promise((resolve) => setTimeout(resolve, 10));
    releaseFirst();
    await nav;
    releaseQuery();
    await paging;

    expect(contexts.length).toBe(1);
    expect(contexts[0].current_user).toEqual({ data: { uuid: 'u1' } });
    expect(contexts[0]._global?.currentUser?.uuid ?? contexts[0]._global?.currentUser?.data?.uuid).toBe('u1');
  });

  /**
   * 출시본 동작 잠금(설계 확정 2026-11-10 순번 장치 되돌리기): 화면 처리 도중(앞 화면 기록을 비운 뒤) 기다리지 않고 부른 수동 소스
   * 다시 받기는 화면 처리의 첫 받기와 무관하게 값을 싣는다.
   *
   * @scenario case=first_fetch_side_effects
   * @effects manual_refetch_during_route_applied
   */
  it('화면 처리 도중 시작한 수동 소스 다시 받기는 첫 받기 때문에 버려지지 않는다', async () => {
    const a = app as any;
    let releaseManual: () => void = () => {};
    const manualHeld = new Promise<void>((resolve) => { releaseManual = resolve; });
    fetchSpy.mockImplementation(async (list: any[], _r?: any, _q?: any, _g?: any, _l?: any, opts?: any) => {
      if (opts?.ignoreAutoFetch === true && list.some((s: any) => s.id === 'language_pack_detail')) {
        await manualHeld;
        return [{ id: 'language_pack_detail', state: 'success', data: { data: { id: 2 } } }] as any;
      }
      return list.filter((s: any) => s.auto_fetch !== false || opts?.ignoreAutoFetch === true)
        .map((s: any) => ({ id: s.id, state: 'success', data: { data: { id: s.id } } })) as any;
    });
    let manualRefetch: Promise<any> | null = null;
    a.layoutLoader.loadLayout.mockImplementation(async () => {
      // 화면 처리가 시작된 뒤(앞 화면 기록을 비운 뒤) 기다리지 않고 부른 수동 소스 다시 받기
      manualRefetch = app.refetchDataSource('language_pack_detail');
      return LAYOUT;
    });

    await a.handleRouteChange(ROUTE);
    releaseManual();
    await manualRefetch;

    expect(a.currentFetchedData.language_pack_detail).toEqual({ data: { id: 2 } });
  });

  /**
   * 출시본 동작 잠금(설계 확정 2026-11-10 순번 장치 되돌리기): 다시 받기가 값을 싣지 못하고 끝나도(본문 없는 응답) 늦게 온 화면
   * 처리의 첫 받기 값은 실린다 — 값이 비어 받는 중으로 남지 않는다.
   *
   * @scenario case=first_fetch_side_effects
   * @effects first_fetch_applied_after_empty_refetch
   */
  it('다시 받기가 값을 싣지 못해도 늦게 온 첫 받기 값을 싣는다', async () => {
    const a = app as any;
    let releaseFirst: () => void = () => {};
    const firstHeld = new Promise<void>((resolve) => { releaseFirst = resolve; });
    fetchSpy.mockImplementation(async (list: any[], _r?: any, _q?: any, _g?: any, _l?: any, opts?: any) => {
      if (opts?.ignoreAutoFetch === true) {
        return list.map((s: any) => ({ id: s.id, state: 'success', data: undefined })) as any;
      }
      if (list.some((s: any) => s.id === 'settings')) {
        await firstHeld;
        return [{ id: 'settings', state: 'success', data: { page: 1 } }] as any;
      }
      return [] as any;
    });
    a.currentFetchedData = {};

    const entering = a.handleRouteChange(ROUTE);
    await new Promise((resolve) => setTimeout(resolve, 20));
    await app.refetchDataSource('settings');
    releaseFirst();
    await entering;

    expect(a.currentFetchedData.settings).toEqual({ page: 1 });
  });

  /**
   * ρ 18부 Z16(C2, C3, χ2 몫): 다른 레이아웃으로 옮기는 전환의 미룬 blocking onSuccess 는 앞 화면이 남긴 반영 대기 값을 비운 뒤, 덮개 대상
   * 내용을 잠근 채 돈다 — 그 안 요청을 기다리는 동안 떠나는 화면에 친 입력이 새 화면 `_local` 에 남지 않게.
   *
   * @scenario case=deferred_blocking_on_success
   * @effects deferred_on_success_clears_pending_and_locks_screen
   */
  it('다른 레이아웃으로 옮길 때 미룬 onSuccess 는 반영 대기 값을 비우고 화면을 잠근 채 돈다', async () => {
    const a = app as any;
    const BLOCKING_LAYOUT = {
      layout_name: 'admin_other',
      components: [{ id: 'root', type: 'basic', name: 'Div' }],
      data_sources: [{ id: 'detail', type: 'api', endpoint: '/api/detail', auto_fetch: true, loading_strategy: 'blocking', onSuccess: { handler: 'setState', params: { target: 'local', loaded: true } } }],
    };
    a.layoutLoader.loadLayout.mockResolvedValue(BLOCKING_LAYOUT);
    a.currentLayoutName = 'admin_settings';
    const seen: { pending: unknown; inertCalls: number } = { pending: 'not-run', inertCalls: -1 };
    const inertSpy = vi.spyOn(a, 'applyRouteInert');
    fetchSpy.mockImplementation(async (list: any[]) => {
      // blocking 응답을 기다리는 동안 떠나는 화면에 친 입력이 반영 대기 값으로 남는다(경로 시작 때 비운 뒤에 생긴 값)
      (window as any).__g7PendingLocalState = { typedOnLeavingScreen: 'x' };
      return list.map((s: any) => ({
        id: s.id,
        state: 'success',
        data: { data: {} },
        runOnSuccess: async () => {
          seen.pending = (window as any).__g7PendingLocalState;
          seen.inertCalls = inertSpy.mock.calls.length;
        },
      })) as any;
    });

    await a.handleRouteChange({ path: '/admin/other', layout: 'admin_other', params: {}, query: {} });

    expect(seen.pending).toBeUndefined();
    expect(seen.inertCalls).toBeGreaterThan(0);
    delete (window as any).__g7PendingLocalState;
  });
});
