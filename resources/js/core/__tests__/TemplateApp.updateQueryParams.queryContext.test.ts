/**
 * @file TemplateApp.updateQueryParams.queryContext.test.ts
 * @description navigate replace(updateQueryParams) 뒤 화면 컨텍스트 `query` 가 새 쿼리로 바뀌는지의 회귀 잠금 (탐침에서 확정, 7.1.1).
 *
 * 결함: 주소는 replaceState 로 바뀌지만, 다시 받을 auto_fetch 소스가 없거나(일찍 return) 다시 받은 결과가
 * 모두 실패하면(updateData 가 비어 updateTemplateData 생략) 컨텍스트 `query` 가 옛 값으로 남는다 —
 * `{{query.*}}`·`_computed`(query 파생)·mergeQuery 링크가 옛 쿼리로 그려진다.
 * 대조군(C): 재조회 성공 시에는 `query` 가 갱신된다. 수정 전 엔진으로 A·B red (engine-v1.82.1 에서 고침).
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher, updateTemplateDataMock, invalidateMock } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    customHandlers: new Map(),
  },
  updateTemplateDataMock: vi.fn(),
  invalidateMock: vi.fn(),
}));

vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  releaseTemplateRoot: vi.fn(),
  updateTemplateData: updateTemplateDataMock,
  getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
  getState: vi.fn().mockReturnValue({
    actionDispatcher: sharedActionDispatcher,
    reactRoot: null,
    currentLayoutJson: null,
    currentDataContext: { _global: {}, query: { tab: 'a' } },
    bindingEngine: { invalidateCacheByKeys: invalidateMock, clearCache: vi.fn(), startRenderCycle: vi.fn() },
  }),
}));

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
  }),
}));

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance: any = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';
import { DataSourceManager } from '../template-engine/DataSourceManager';

describe('updateQueryParams — 재조회가 없거나 실패해도 컨텍스트 query 를 새 쿼리로 바꾼다 (engine-v1.82.1)', () => {
  let app: TemplateApp;
  let fetchSpy: ReturnType<typeof vi.spyOn>;

  const setup = (sources: any[]) => {
    (app as any).currentRawDataSources = sources;
    (app as any).currentDataSources = sources;
    (app as any).currentRouteParams = {};
    (app as any).currentQueryParams = new URLSearchParams('tab=a');
    (app as any).currentFetchedData = {};
    (app as any).globalState = {};
    (app as any).currentGlobalHeaders = [];
  };
  const queryUpdates = () => updateTemplateDataMock.mock.calls.map((c) => c[0]).filter((d) => d && 'query' in d);

  beforeEach(() => {
    updateTemplateDataMock.mockClear();
    invalidateMock.mockClear();
    document.body.innerHTML = '<div id="app"></div>';
    window.history.replaceState(null, '', '/admin/x?tab=a');
    (window as any).G7Core = {};
    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
    fetchSpy = vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults');
  });
  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  /**
   * 주소를 읽어 href 를 만드는 `:mergeQuery` 링크가 다시 그려지도록 주소 교체를 알린다.
   *
   * @scenario case=single_call
   * @effects merge_query_href_follows_query_replace
   */
  it('주소를 바꾸면 g7:location-replaced 를 알린다 — 바뀐 뒤의 주소로', async () => {
    setup([{ id: 'opts', type: 'api', endpoint: '/api/x', auto_fetch: false }]);
    const seen: string[] = [];
    const onReplaced = () => seen.push(window.location.search);
    window.addEventListener('g7:location-replaced', onReplaced);
    try {
      await app.updateQueryParams('/admin/x?tab=c');
    } finally {
      window.removeEventListener('g7:location-replaced', onReplaced);
    }
    expect(seen).toEqual(['?tab=c']);
  });

  /**
   * @scenario case=single_call
   * @effects query_context_follows_url_without_refetch
   */
  it('A. auto_fetch 소스가 없는 화면 — 주소와 컨텍스트 query 가 함께 바뀐다', async () => {
    setup([{ id: 'opts', type: 'api', endpoint: '/api/x', auto_fetch: false }]);
    await app.updateQueryParams('/admin/x?tab=b');

    expect(window.location.search).toBe('?tab=b');
    expect(queryUpdates()).toEqual([expect.objectContaining({ query: { tab: 'b' } })]); // 결함: [] (5110 부근 일찍 return)
  });

  /**
   * @scenario case=single_call
   * @effects query_context_follows_url_without_refetch
   */
  it('B. 재조회가 모두 실패한 화면 — 컨텍스트 query 는 새 쿼리다', async () => {
    setup([{ id: 'list', type: 'api', endpoint: '/api/x', auto_fetch: true }]);
    fetchSpy.mockResolvedValue([{ id: 'list', state: 'error', error: new Error('500') }] as any);
    await app.updateQueryParams('/admin/x?tab=b');

    expect(queryUpdates()).toEqual([expect.objectContaining({ query: { tab: 'b' } })]); // 결함: [] (updateData 비어 갱신 생략)
  });

  /**
   * @scenario case=single_call
   * @effects query_context_follows_url_without_refetch
   */
  it('C. 재조회 성공(대조군) — 컨텍스트 query 가 새 쿼리다', async () => {
    setup([{ id: 'list', type: 'api', endpoint: '/api/x', auto_fetch: true }]);
    fetchSpy.mockResolvedValue([{ id: 'list', state: 'success', data: { data: [] } }] as any);
    await app.updateQueryParams('/admin/x?tab=b');

    expect(queryUpdates()).toEqual([expect.objectContaining({ query: { tab: 'b' } })]);
  });
});
