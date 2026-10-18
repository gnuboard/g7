/**
 * @file TemplateApp.localeSwitch.test.ts
 * @description 언어 전환이 저장 전 입력을 지우고 이미 받은 목록을 이전 언어로 남기던 결함 회귀 (engine-v1.79.0)
 *
 * 버그:
 *   1) 언어 전환은 엔진을 다시 띄우고 같은 경로로 재진입한다. 재진입이 레이아웃 init·데이터소스 initLocal 을
 *      다시 돌려, 관리자 페이지 작성 화면에서 입력한 제목·고른 첨부가 사라졌다(7.1.0 실측 O-G3-3).
 *   2) 탭을 열 때 받은 목록(auto_fetch=false — 마이페이지 「내 반응」)은 재진입이 다시 받지 않아,
 *      화면 글자는 새 언어인데 서버 문구(반응 유형 이름 등)는 이전 언어로 남았다(O-K1-3).
 *
 * 수정:
 *   언어 전환 재진입은 전환 직전 `_local` 로 그리고 initLocal 을 적용하지 않는다(데이터소스는 새 언어로 다시 받는다).
 *   전환 직전 데이터가 있던 수동 데이터소스는 재진입 뒤 initLocal 없이 다시 받는다.
 *   언어 전환이 아닌 화면 이동은 종전대로 init·initLocal 을 적용한다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher, renderTemplateMock, updateTemplateDataMock } = vi.hoisted(() => ({
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
}));

vi.mock('../template-engine', async () => {
  const actual = await vi.importActual<any>('../template-engine/DataSourceManager');
  return {
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: renderTemplateMock,
    destroyTemplate: vi.fn(),
    updateTemplateData: updateTemplateDataMock,
    DataSourceManager: actual.DataSourceManager,
    getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
    getState: vi.fn().mockReturnValue({
      actionDispatcher: sharedActionDispatcher,
      reactRoot: null,
      currentLayoutJson: null,
      currentDataContext: { _global: {} },
      bindingEngine: { invalidateCacheByKeys: vi.fn() },
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
import { isLocaleSwitching, stashForLocaleSwitch, takeLocaleSwitchHandoff, clearLocaleSwitchHandoff } from '../template-engine/localeSwitchHandoff';
import { getLocalInitTracking } from '../template-engine/localInitSlot';

/** 페이지 작성 화면 축약 — 폼 기본값을 주는 blocking 소스(initLocal=form) + 탭을 열 때 받는 수동 목록 */
const LAYOUT = {
  layout_name: 'admin_page_form',
  components: [{ id: 'root', type: 'basic', name: 'Div' }],
  data_sources: [
    { id: 'formDefaults', type: 'api', endpoint: '/api/form-defaults', auto_fetch: true, loading_strategy: 'blocking', initLocal: 'form' },
    { id: 'myReactions', type: 'api', endpoint: '/api/me/reactions', auto_fetch: false, loading_strategy: 'progressive' },
  ],
};

const ROUTE = { path: '/admin/pages/create', layout: 'admin_page_form', params: {}, query: {} };

describe('[case:state-global-locale-switch-reentry] TemplateApp 언어 전환 — 화면 상태 유지와 수동 데이터소스 재조회 (engine-v1.79.0)', () => {
  let app: TemplateApp;
  let fetchSpy: ReturnType<typeof vi.spyOn>;

  const lastRenderContext = (): Record<string, any> => {
    const calls = renderTemplateMock.mock.calls;
    return calls.length ? calls[calls.length - 1][0].dataContext : {};
  };

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    window.history.replaceState(null, '', ROUTE.path);
    (window as any).G7Core = {};
    renderTemplateMock.mockClear();
    updateTemplateDataMock.mockClear();
    clearLocaleSwitchHandoff();
    localStorage.removeItem("g7_locale");

    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko' } as any);
    const a = app as any;
    a.layoutLoader = { loadLayout: vi.fn().mockResolvedValue(LAYOUT), clear: vi.fn() };
    a.router = { navigateToCurrentPath: vi.fn(async () => { await a.handleRouteChange(ROUTE); }) };
    a.currentLayoutName = 'admin_page_form';
    a.currentRoutePath = ROUTE.path; // 직전 handleRouteChange 가 채운 값
    a.globalState = { _local: { form: { title: { ko: '저장 전 제목' } }, hasChanges: true } };
    a.currentDataSources = LAYOUT.data_sources;
    a.currentRawDataSources = LAYOUT.data_sources;
    a.currentFetchedData = { myReactions: { data: { type_name: '추천' } } };

    fetchSpy = vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults').mockImplementation(async (list: any[], _r?: any, _q?: any, _g?: any, _l?: any, opts?: any) =>
      // 실제 계약: auto_fetch=false 소스는 ignoreAutoFetch 일 때만 받는다
      list.filter((s: any) => s.auto_fetch !== false || opts?.ignoreAutoFetch === true).map((s: any) => ({
        id: s.id,
        state: 'success',
        data: s.id === 'formDefaults' ? { data: { title: { ko: '' } } } : { data: { type_name: 'Recommend' } },
      })) as any,
    );
  });

  afterEach(() => {
    vi.restoreAllMocks();
    clearLocaleSwitchHandoff();
    delete (window as any).G7Core;
  });

  /**
   * @scenario case=unsaved_title_kept
   * @effects unsaved_local_kept, init_local_not_applied
   */
  it('언어 전환 재진입은 전환 직전 _local 로 그리고 initLocal 을 적용하지 않는다', async () => {
    await app.changeLocale('en');

    const ctx = lastRenderContext();
    expect(ctx._local?.form?.title).toEqual({ ko: '저장 전 제목' });
    expect(ctx._local?.hasChanges).toBe(true);
    expect(ctx._localInit).toBeUndefined();
  });

  /**
   * @scenario case=manual_source_refetched
   * @effects manual_source_refetched_in_new_locale, manual_refetch_does_not_touch_local
   */
  it('전환 직전 데이터가 있던 수동 데이터소스(auto_fetch=false)를 다시 받고 initLocal 없이 반영한다', async () => {
    await app.changeLocale('en');

    const fetchedIds = fetchSpy.mock.calls.flatMap((call: any[]) => (call[0] as any[]).map((s) => s.id));
    expect(fetchedIds).toContain('myReactions');
    const reactionUpdate = updateTemplateDataMock.mock.calls.map((c) => c[0]).find((d) => d.myReactions);
    expect(reactionUpdate?.myReactions).toEqual({ data: { type_name: 'Recommend' } });
    expect(reactionUpdate?._localInit).toBeUndefined();
  });

  /**
   * 언어 전환이 아닌 같은 경로 재진입(새로고침과 같은 재진입)은 종전대로 initLocal 을 적용한다.
   *
   * @scenario case=normal_reentry_applies_init_local
   * @effects normal_reentry_applies_init_local
   */
  it('언어 전환이 아닌 재진입은 종전대로 initLocal 을 적용한다', async () => {
    await (app as any).handleRouteChange(ROUTE);

    const ctx = lastRenderContext();
    expect(ctx._localInit?.form).toEqual({ title: { ko: '' } });
  });

  /**
   * @scenario case=handoff_store
   * @effects handoff_accepts_stash_only_while_switching, handoff_take_once, handoff_cleared_on_next_navigation
   */
  it('인계 저장소는 언어 전환 중에만 맡고, 한 번 꺼내면 지우며, 다음 화면 이동에서 버린다', async () => {
    expect(stashForLocaleSwitch('k', 1)).toBe(false);

    const a = app as any;
    let stashedDuringUnmount = false;
    const { destroyTemplate } = await import('../template-engine');
    vi.mocked(destroyTemplate).mockImplementation(() => {
      stashedDuringUnmount = isLocaleSwitching() && stashForLocaleSwitch('/admin/pages/create::page_attachments', ['file-a']);
    });

    await app.changeLocale('en');
    expect(stashedDuringUnmount).toBe(true);
    expect(isLocaleSwitching()).toBe(false);
    expect(takeLocaleSwitchHandoff('/admin/pages/create::page_attachments')).toEqual(['file-a']);
    expect(takeLocaleSwitchHandoff('/admin/pages/create::page_attachments')).toBeUndefined();

    a.currentDataSources = LAYOUT.data_sources;
    await app.changeLocale('ja');
    await a.handleRouteChange({ ...ROUTE, path: '/admin/pages' });
    expect(takeLocaleSwitchHandoff('/admin/pages/create::page_attachments')).toBeUndefined();
    vi.mocked(destroyTemplate).mockReset();
  });

  /**
   * 레이아웃이 `_local` 을 선언하지 않는 화면(글 상세처럼 파라미터만 다른 경로)에서 같은 레이아웃의 다른 경로로
   * 가면, 렌더 컨텍스트에 저장소 B 를 싣는다 — 경로 전환이 상태 소유 루트의 저장소 A 를 비워도 B 의 값이 보인다.
   *
   * @scenario case=no_local_declaration_store_b
   * @effects render_context_carries_store_b
   */
  it('_local 을 선언하지 않는 레이아웃의 같은 레이아웃 재진입은 렌더 컨텍스트에 저장소 B 를 싣는다', async () => {
    const a = app as any;
    const plain = { layout_name: 'board_show', components: [{ id: 'root', type: 'basic', name: 'Div' }], data_sources: [] };
    a.layoutLoader.loadLayout = vi.fn().mockResolvedValue(plain);
    a.currentLayoutName = 'board_show';
    a.globalState = { _local: { viewMode: 'card' } };

    await a.handleRouteChange({ path: '/board/free/2', layout: 'board_show', params: { slug: 'free', id: '2' }, query: {} });

    expect(lastRenderContext()._local).toEqual({ viewMode: 'card' });
  });

  /**
   * 없는 주소의 오류 화면처럼 데이터 소스 정의를 채운 경로가 아닌 화면에서 언어를 바꾸면, 직전 화면의 수동 데이터
   * 소스를 다시 받지 않는다.
   *
   * @scenario case=error_page_no_refetch
   * @effects stale_manual_source_not_refetched
   */
  it('데이터 소스를 채운 경로가 아닌 화면(오류 화면)에서는 직전 화면의 수동 데이터 소스를 다시 받지 않는다', async () => {
    const a = app as any;
    a.currentRoutePath = '/mypage/board';
    window.history.replaceState(null, '', '/no-such-page');
    a.router = { navigateToCurrentPath: vi.fn(async () => {}) };

    await app.changeLocale('en');

    const fetchedIds = fetchSpy.mock.calls.flatMap((call: any[]) => (call[0] as any[]).map((src) => src.id));
    expect(fetchedIds).not.toContain('myReactions');
  });

  /**
   * 같은 레이아웃의 다른 경로로 가면 `_localInit` 적용 추적을 초기화한다 — 「수정 → 등록 → 뒤로 가기」로 같은
   * payload 가 돌아와도 건너뛰지 않고 다시 적용해, 경로 전환으로 비운 상태 소유 루트의 저장소 A 를 채운다.
   *
   * @scenario case=local_init_tracking_reset
   * @effects local_init_tracking_reset_on_path_change
   */
  it('같은 레이아웃의 다른 경로로 가면 _localInit 적용 추적을 초기화한다', async () => {
    const a = app as any;
    a.currentRoutePath = '/admin/memos/5/edit';
    getLocalInitTracking().hash = 'stale-hash:no-force';

    await a.handleRouteChange({ ...ROUTE, path: '/admin/memos/create' });

    expect(getLocalInitTracking().hash).not.toBe('stale-hash:no-force');
  });

  /**
   * 언어 전환 재진입이 레이아웃을 끝내 받지 못해 오류 화면으로 끝나면, 옛 화면 정의로 수동 데이터 소스를
   * 다시 받지 않는다.
   *
   * @scenario case=reentry_failed_no_refetch
   * @effects manual_refetch_skipped_when_reentry_failed
   */
  it('언어 전환 재진입이 실패하면 수동 데이터 소스를 다시 받지 않는다', async () => {
    const a = app as any;
    a.layoutLoader.loadLayout = vi.fn().mockRejectedValue(new Error('network'));
    vi.spyOn(a, 'showRouteError').mockImplementation(() => {});

    await app.changeLocale('en');

    const fetchedIds = fetchSpy.mock.calls.flatMap((call: any[]) => (call[0] as any[]).map((src) => src.id));
    expect(fetchedIds).not.toContain('myReactions');
  });
});
