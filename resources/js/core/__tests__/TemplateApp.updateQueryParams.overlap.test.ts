/**
 * @file TemplateApp.updateQueryParams.overlap.test.ts
 * @description 탭 전환 쿼리 갱신(updateQueryParams)이 겹칠 때 덮개·결과 적용 소유권 회귀
 *
 * 버그 (게시판 환경설정, 7.1.0 실측):
 *   탭 A 로 옮긴 직후(A 의 재조회가 아직 진행 중) 원래 탭 B 를 누르면 updateQueryParams 가 두 번 겹친다.
 *   먼저 시작한 A 호출이 늦게 끝나면서 finally 의 hideTransitionOverlay 가 B 호출이 띄운 덮개를 걷는다.
 *   B 의 설정(blocking, initLocal, refetchOnMount) 응답은 그 뒤에 도착하므로, 그 사이 칸에 친 값이
 *   응답의 initLocal 에 말없이 덮인다(실측: 덮개 3450ms 해제, 설정 응답 4012ms).
 *   A 호출의 결과(이전 쿼리 기준)도 B 가 시작된 뒤에 화면에 적용되어 같은 방식으로 칸을 되돌린다.
 *
 * 수정:
 *   호출마다 번호를 매겨, 덮개와 대기 표시는 가장 마지막에 재조회를 시작한 호출만 걷고,
 *   뒤에 새 호출이 시작된 옛 호출은 결과를 화면에 적용하지 않는다.
 *
 * [case:state-global-tab-refetch-overlay-overlap]
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher, mockState, updateTemplateDataMock, setPendingMock } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    customHandlers: new Map(),
  },
  mockState: {} as Record<string, any>,
  updateTemplateDataMock: vi.fn(),
  setPendingMock: vi.fn(),
}));

vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  releaseTemplateRoot: vi.fn(),
  getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
  getState: vi.fn(() => mockState),
  updateTemplateData: updateTemplateDataMock,
}));

vi.mock('../template-engine/TransitionManager', () => ({
  transitionManager: {
    setPending: setPendingMock,
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
  }),
}));

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance: any = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';
import { DataSourceManager } from '../template-engine/DataSourceManager';

/** 게시판 환경설정 구조 축약: 설정 본체(blocking, initLocal, refetchOnMount) + 탭별 progressive 목록 */
const RAW_SOURCES = [
  { id: 'settings', type: 'api', endpoint: '/api/settings', auto_fetch: true, loading_strategy: 'blocking', initLocal: 'form', refetchOnMount: true },
  { id: 'roles', type: 'api', endpoint: '/api/roles', auto_fetch: true, if: "{{['basic_defaults','report_policy'].includes(query.tab)}}" },
  { id: 'definitions', type: 'api', endpoint: '/api/definitions', auto_fetch: true, if: "{{query.tab === 'report_policy'}}" },
  { id: 'manual_only', type: 'api', endpoint: '/api/manual', auto_fetch: false, if: "{{query.tab === 'manual'}}" },
];

/** 바깥에서 결과를 정할 수 있는 지연 fetch */
function deferred() {
  let resolve!: (v: any) => void;
  const promise = new Promise<any>((r) => { resolve = r; });
  return { promise, resolve };
}

const settingsResult = (siteValue: number) => ({ id: 'settings', state: 'success', data: { data: { attachment: { days: siteValue } } } });

const overlayPresent = () => !!document.getElementById('g7-skeleton-overlay');

describe('updateQueryParams 겹침 — 덮개와 결과 적용은 마지막 호출이 가진다', () => {
  let app: TemplateApp;
  let fetchSpy: ReturnType<typeof vi.spyOn>;
  let pending: Array<ReturnType<typeof deferred>>;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"><div id="right_content_area"><div id="tab_content"><input name="attachment.days" /></div></div></div>';
    document.head.querySelectorAll('#g7-skeleton-overlay-style').forEach((n) => n.remove());
    window.history.replaceState(null, '', '/admin/boards/settings?tab=basic_defaults');
    (window as any).G7Core = { t: () => '' };

    Object.keys(mockState).forEach((k) => delete mockState[k]);
    Object.assign(mockState, {
      actionDispatcher: sharedActionDispatcher,
      reactRoot: null,
      currentLayoutJson: {
        transition_overlay: { enabled: true, style: 'spinner', target: 'tab_content', fallback_target: 'right_content_area', wait_for: ['settings', 'roles', 'definitions'] },
      },
      currentDataContext: { _global: {} },
      bindingEngine: { invalidateCacheByKeys: vi.fn() },
    });
    updateTemplateDataMock.mockClear();
    setPendingMock.mockClear();

    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
    (app as any).currentRawDataSources = RAW_SOURCES;
    (app as any).currentDataSources = RAW_SOURCES.slice(0, 2);
    (app as any).currentRouteParams = {};
    (app as any).currentQueryParams = new URLSearchParams('tab=basic_defaults');
    (app as any).currentFetchedData = {};
    (app as any).globalState = {};
    (app as any).currentGlobalHeaders = [];

    pending = [];
    fetchSpy = vi
      .spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults')
      .mockImplementation(() => {
        const d = deferred();
        pending.push(d);
        return d.promise;
      });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  // @scenario case=older_finishes_first
  // @effects overlay_held_until_latest_refetch
  it('앞 호출이 먼저 끝나도 뒤 호출의 재조회(설정 포함)가 끝날 때까지 덮개와 대기 표시가 남는다', async () => {
    const first = app.updateQueryParams('/admin/boards/settings?tab=report_policy');
    const second = app.updateQueryParams('/admin/boards/settings?tab=basic_defaults');
    expect(fetchSpy).toHaveBeenCalledTimes(2);
    expect((fetchSpy.mock.calls[1][0] as any[]).map((s) => s.id)).toContain('settings');
    expect(overlayPresent()).toBe(true);

    // 앞 호출(이전 탭) 응답이 먼저 도착 — 뒤 호출의 설정 응답은 아직
    pending[0].resolve([settingsResult(30)]);
    await first;
    expect(overlayPresent()).toBe(true);
    expect(setPendingMock).not.toHaveBeenCalledWith(false);

    // 뒤 호출의 설정 응답 도착 → 그제서야 걷힌다
    pending[1].resolve([settingsResult(30)]);
    await second;
    expect(overlayPresent()).toBe(false);
    expect(setPendingMock).toHaveBeenLastCalledWith(false);
  });

  // @scenario case=stale_same_source
  // @effects stale_response_discarded
  it('뒤 호출이 같은 소스를 다시 받기 시작한 뒤 끝난 앞 호출의 그 결과는 화면에 적용하지 않는다', async () => {
    const first = app.updateQueryParams('/admin/boards/settings?tab=report_policy');
    const second = app.updateQueryParams('/admin/boards/settings?tab=basic_defaults');

    pending[0].resolve([settingsResult(11)]);
    await first;
    expect(updateTemplateDataMock).not.toHaveBeenCalled();
    expect((app as any).currentFetchedData.settings).toBeUndefined();

    pending[1].resolve([settingsResult(22)]);
    await second;
    expect(updateTemplateDataMock).toHaveBeenCalledTimes(1);
    expect(updateTemplateDataMock.mock.calls[0][0].settings).toEqual({ data: { attachment: { days: 22 } } });
  });

  // @scenario case=newer_finishes_first
  // @effects overlay_released_when_current_sources_loaded, stale_response_discarded
  it('뒤 호출이 앞 호출보다 먼저 끝나면 덮개를 걷고, 늦게 끝난 앞 호출의 설정 응답은 폼에 적용하지 않는다', async () => {
    const first = app.updateQueryParams('/admin/boards/settings?tab=report_policy');
    const second = app.updateQueryParams('/admin/boards/settings?tab=basic_defaults');

    pending[1].resolve([settingsResult(22), { id: 'roles', state: 'success', data: { data: [] } }]);
    await second;
    // 앞 호출은 아직 진행 중이지만 현재 화면 소스(settings, roles)는 모두 뒤 호출이 받았다 → 걷는다
    expect(overlayPresent()).toBe(false);
    expect(updateTemplateDataMock).toHaveBeenCalledTimes(1);

    // 사용자가 칸을 고치는 사이 앞 호출(이전 탭) 응답이 늦게 도착
    pending[0].resolve([settingsResult(11), { id: 'roles', state: 'success', data: { data: [1] } }, { id: 'definitions', state: 'success', data: { data: [] } }]);
    await first;
    // settings·roles 는 뒤 호출 것이 최신이라 버리고(initLocal 로 폼을 되돌리지 않음), 이전 탭 전용 소스(definitions)는
    // 지금 쿼리에서 고르지 않은 소스라 버린다 → 화면 갱신 없음
    expect(updateTemplateDataMock).toHaveBeenCalledTimes(1);
    expect((app as any).currentFetchedData.settings).toEqual({ data: { attachment: { days: 22 } } });
    expect((app as any).currentFetchedData.definitions).toBeUndefined();
  });

  // @scenario case=stale_previous_tab_source
  // @effects non_current_source_response_discarded
  it('앞 탭 전용 소스(initLocal 포함)는 뒤 호출이 다시 받지 않아도, 지금 쿼리에서 고르지 않았으면 적용하지 않는다', async () => {
    (app as any).currentRawDataSources = [
      ...RAW_SOURCES,
      { id: 'report_form', type: 'api', endpoint: '/api/report', auto_fetch: true, initLocal: 'form', if: "{{query.tab === 'report_policy'}}" },
    ];
    const first = app.updateQueryParams('/admin/boards/settings?tab=report_policy');
    const second = app.updateQueryParams('/admin/boards/settings?tab=basic_defaults');
    pending[1].resolve([settingsResult(22)]);
    await second;
    pending[0].resolve([{ id: 'report_form', state: 'success', data: { data: { attachment: { days: 99 } } } }]);
    await first;
    expect(updateTemplateDataMock).toHaveBeenCalledTimes(1);
    expect(updateTemplateDataMock.mock.calls[0][0]._localInit).toBeDefined();
    // 늦게 끝난 앞 호출의 report_form(days 99)은 어디에도 실리지 않는다(타임스탬프에 섞인 숫자와 구분하려고 키까지 본다)
    expect(JSON.stringify(updateTemplateDataMock.mock.calls[0][0])).not.toContain('"days":99');
    expect((app as any).currentFetchedData.report_form).toBeUndefined();
  });

  // @scenario case=route_change_during_update
  // @effects stale_response_discarded
  it('화면 이동(handleRouteChange)이 시작되면 진행 중 쿼리 갱신의 늦은 응답은 적용하지 않는다', async () => {
    const run = app.updateQueryParams('/admin/boards/settings?tab=basic_defaults');
    // 화면 이동 시작 — 레이아웃 로드 등은 하네스 목이 처리한다(결과는 보지 않는다)
    await (app as any).handleRouteChange({ path: '/admin/other', layout: 'admin_other', params: {} }).catch(() => {});
    pending[0].resolve([settingsResult(55)]);
    await run;
    const applied = updateTemplateDataMock.mock.calls.map((c) => c[0]).filter((d) => d && d.settings);
    expect(applied).toHaveLength(0);
    expect((app as any).queryUpdateInFlight.size).toBe(0);
  });

  // @scenario case=scoped_list_refetch
  // @effects scoped_refetch_keeps_settings_form
  it('[목록만 다시 받기] refetchDataSourceIds 를 주면 그 소스만 다시 받고 설정 폼(initLocal)은 건드리지 않는다', async () => {
    (app as any).currentRawDataSources = [
      ...RAW_SOURCES,
      { id: 'policies', type: 'api', endpoint: '/api/policies', auto_fetch: true, if: "{{query.tab === 'identity'}}" },
    ];
    const run = app.updateQueryParams('/admin/settings?tab=identity&page=2', { refetchDataSourceIds: ['policies'] });
    expect((fetchSpy.mock.calls[0][0] as any[]).map((s) => s.id)).toEqual(['policies']);
    pending[0].resolve([{ id: 'policies', state: 'success', data: { data: [], meta: { current_page: 2 } } }]);
    await run;
    const applied = updateTemplateDataMock.mock.calls[0][0];
    expect(applied.settings).toBeUndefined();
    expect(applied._localInit).toBeUndefined();
    expect(applied.policies).toEqual({ data: [], meta: { current_page: 2 } });
    expect(applied.query).toEqual({ tab: 'identity', page: '2' });
  });

  // @scenario case=scoped_during_full_refetch
  // @effects overlay_held_until_latest_refetch, scoped_refetch_keeps_settings_form
  it('[목록만 다시 받기] 탭 전체 재조회가 진행 중이면 목록 재조회가 덮개를 좁은 대상으로 바꾸거나 먼저 걷지 않는다', async () => {
    (app as any).currentRawDataSources = [
      ...RAW_SOURCES,
      { id: 'policies', type: 'api', endpoint: '/api/policies', auto_fetch: true, if: "{{query.tab === 'identity'}}" },
    ];
    document.getElementById('tab_content')!.insertAdjacentHTML('beforeend', '<div id="policy_table_card"></div>');
    mockState.currentLayoutJson.transition_overlay.wait_for.push('policies');
    const full = app.updateQueryParams('/admin/settings?tab=identity');
    const tabOverlayParent = document.getElementById('g7-skeleton-overlay')!.parentElement!.id;
    expect(tabOverlayParent).toBe('tab_content');

    const scoped = app.updateQueryParams('/admin/settings?tab=identity&page=2', { transitionOverlayTarget: 'policy_table_card', refetchDataSourceIds: ['policies'] });
    expect(document.getElementById('g7-skeleton-overlay')!.parentElement!.id).toBe('tab_content');

    pending[1].resolve([{ id: 'policies', state: 'success', data: { data: [] } }]);
    await scoped;
    // 설정(blocking)은 아직 탭 전체 재조회가 받는 중 → 덮개 유지
    expect(overlayPresent()).toBe(true);

    pending[0].resolve([settingsResult(30), { id: 'policies', state: 'success', data: { data: ['old'] } }]);
    await full;
    expect(overlayPresent()).toBe(false);
    // 목록은 뒤(쪽 넘김) 호출 것이 최신 — 탭 전체 재조회의 옛 목록 응답은 버리고 설정은 적용
    const lastApply = updateTemplateDataMock.mock.calls.at(-1)![0];
    expect(lastApply.policies).toBeUndefined();
    expect(lastApply.settings).toEqual({ data: { attachment: { days: 30 } } });
  });

  // @scenario case=newer_without_sources
  // @effects overlay_released_when_current_sources_loaded
  it('뒤 호출이 재조회할 소스가 없어 바로 끝나면, 앞 호출이 끝날 때 덮개와 대기 표시를 정리한다', async () => {
    const first = app.updateQueryParams('/admin/boards/settings?tab=report_policy');
    (app as any).currentRawDataSources = RAW_SOURCES.filter((s) => s.id === 'manual_only');
    await app.updateQueryParams('/admin/boards/settings?tab=manual');
    expect(fetchSpy).toHaveBeenCalledTimes(1);
    expect(overlayPresent()).toBe(true);

    pending[0].resolve([settingsResult(11)]);
    await first;
    expect(overlayPresent()).toBe(false);
    expect(setPendingMock).toHaveBeenLastCalledWith(false);
  });

  // @scenario case=single_call
  // @effects single_call_unchanged
  it('겹치지 않는 단일 호출은 종전대로 결과를 적용하고 덮개를 걷는다', async () => {
    const only = app.updateQueryParams('/admin/boards/settings?tab=basic_defaults');
    expect(overlayPresent()).toBe(true);
    pending[0].resolve([settingsResult(30)]);
    await only;
    expect(overlayPresent()).toBe(false);
    expect(updateTemplateDataMock).toHaveBeenCalledTimes(1);
    expect(setPendingMock).toHaveBeenLastCalledWith(false);
  });
});
