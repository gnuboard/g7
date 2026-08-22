/**
 * @file TemplateApp.updateQueryParams.condition.test.ts
 * @description replace:true navigate(탭 전환/필터 변경) 경로의 데이터소스 if 재평가 회귀 (#415)
 *
 * 버그:
 *   updateQueryParams(replace:true navigate 진입점)가 직전 진입 시점에 if 로 필터링된
 *   currentDataSources 스냅샷을 그대로 refetch 했다. 탭 전환으로 query 컨텍스트가 바뀌어도
 *   if 를 재평가하지 않아, 다른 탭의 데이터소스가 잘못 선택되었다.
 *   (예: 본인인증 탭→언어팩 탭 클릭 전환 시, 언어팩 데이터소스가 fetch 되지 않고
 *    정책 데이터소스가 계속 fetch 됨)
 *
 * 수정:
 *   currentRawDataSources(if 필터링 전 원본)를 보존하고, updateQueryParams 에서 변경된 query +
 *   최신 _global 로 filterByCondition 을 재평가하여 fetch 대상을 다시 산정한다.
 *
 * 검증:
 *   fetchDataSourcesWithResults 를 spy 로 가로채, updateQueryParams 호출 시 전달된 데이터소스
 *   목록이 새 query.tab 기준으로 재평가되었는지 확인.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    customHandlers: new Map(),
  },
}));

vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
  getState: vi.fn().mockReturnValue({
    actionDispatcher: sharedActionDispatcher,
    reactRoot: null,
    currentLayoutJson: null,
    currentDataContext: { _global: { activeSettingsTab: 'language_packs' } },
    bindingEngine: { invalidateCacheByKeys: vi.fn() },
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

/** 정책/언어팩/공통 데이터소스 원본 (admin_settings.json 구조 축약) */
const RAW_SOURCES = [
  { id: 'settings', type: 'api', endpoint: '/api/admin/settings', auto_fetch: true },
  {
    id: 'policies',
    type: 'api',
    endpoint: '/api/admin/identity/policies',
    auto_fetch: true,
    if: "{{(query.tab || _global.activeSettingsTab) === 'identity'}}",
  },
  {
    id: 'language_packs',
    type: 'api',
    endpoint: '/api/admin/language-packs',
    auto_fetch: true,
    if: "{{(query.tab || _global.activeSettingsTab) === 'language_packs'}}",
  },
];

describe('updateQueryParams 데이터소스 if 재평가 (#415)', () => {
  let app: TemplateApp;
  let fetchSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    window.history.replaceState(null, '', '/admin/settings?tab=identity');
    (window as any).G7Core = {};

    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);

    // 직전 진입(본인인증 탭) 시점 스냅샷 모사: policies 만 선택된 상태
    const dm = new DataSourceManager();
    const initialSelected = dm.filterByCondition(RAW_SOURCES as any, {
      query: { tab: 'identity' },
      route: {},
      _global: { activeSettingsTab: 'identity' },
    } as any);
    (app as any).currentRawDataSources = RAW_SOURCES;
    (app as any).currentDataSources = initialSelected;
    (app as any).currentRouteParams = {};
    (app as any).currentQueryParams = new URLSearchParams('tab=identity');
    (app as any).currentFetchedData = {};
    (app as any).globalState = { activeSettingsTab: 'language_packs' };
    (app as any).currentGlobalHeaders = [];

    // fetch 를 가로채 빈 결과 반환 — 실제 네트워크 없이 "어떤 소스로 호출됐는지"만 검사
    fetchSpy = vi
      .spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults')
      .mockResolvedValue([] as any);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  it('본인인증→언어팩 탭 전환 시 language_packs 가 재선택되어 fetch 되고 policies 는 제외된다', async () => {
    await app.updateQueryParams('/admin/settings?tab=language_packs');

    expect(fetchSpy).toHaveBeenCalled();
    const fetchedSources = (fetchSpy.mock.calls[0][0] as any[]).map((s) => s.id);

    expect(fetchedSources).toContain('language_packs');
    expect(fetchedSources).not.toContain('policies');
    // if 없는 공통 소스는 항상 포함
    expect(fetchedSources).toContain('settings');
  });

  it('재평가 결과가 currentDataSources 에도 반영된다 (이후 refetchDataSource/wait_for 참조용)', async () => {
    await app.updateQueryParams('/admin/settings?tab=language_packs');

    const currentIds = (app as any).currentDataSources.map((s: any) => s.id);
    expect(currentIds).toContain('language_packs');
    expect(currentIds).not.toContain('policies');
  });

  it('언어팩→본인인증 탭 전환 시 policies 가 재선택되어 fetch 되고 language_packs 는 제외된다', async () => {
    // 직전 진입(언어팩 탭) 스냅샷으로 재설정
    const dm = new DataSourceManager();
    (app as any).currentDataSources = dm.filterByCondition(RAW_SOURCES as any, {
      query: { tab: 'language_packs' },
      route: {},
      _global: { activeSettingsTab: 'language_packs' },
    } as any);
    (app as any).currentQueryParams = new URLSearchParams('tab=language_packs');

    await app.updateQueryParams('/admin/settings?tab=identity');

    const fetchedSources = (fetchSpy.mock.calls.at(-1)![0] as any[]).map((s) => s.id);
    expect(fetchedSources).toContain('policies');
    expect(fetchedSources).not.toContain('language_packs');
  });
});
