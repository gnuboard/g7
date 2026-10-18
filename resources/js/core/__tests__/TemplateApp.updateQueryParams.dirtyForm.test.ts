/**
 * @file TemplateApp.updateQueryParams.dirtyForm.test.ts
 * @description 쿼리만 바뀌는 이동(탭 전환)의 재조회는 편집 중이어도 initLocal 을 적용한다 — 계약 잠금
 *
 * 배경:
 *   게시판·코어·이커머스 환경설정은 저장할 때 **지금 탭의 구획만** 보낸다. 탭을 오가도 고친 값을 남기면
 *   다른 탭의 미저장 편집이 「변경 있음」과 함께 남은 채 지금 탭만 저장되어, 사용자는 모두 저장됐다고 믿게 된다.
 *   그래서 탭 전환 재조회는 서버 값으로 되돌리는 것이 계약이다(트러블슈팅 state-global 사례 6·8,
 *   E2E localinit-tab-roundtrip-store-sync). 미저장 변경이 사라진다는 사실은 레이아웃이 탭을 옮기기 전에
 *   확인 창으로 알린다(결정 2026-10-08). 엔진이 편집 중인 폼의 initLocal 을 건너뛰지 않음을 이 파일이 잠근다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher, updateTemplateDataMock } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    customHandlers: new Map(),
  },
  updateTemplateDataMock: vi.fn(),
}));

vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  updateTemplateData: updateTemplateDataMock,
  getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
  getState: vi.fn().mockReturnValue({
    actionDispatcher: sharedActionDispatcher,
    reactRoot: null,
    currentLayoutJson: null,
    currentDataContext: { _global: {} },
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
import { registerTrackChangesRoot, resetTrackChangesRoots } from '../template-engine/localInitSlot';

/** 게시판 환경설정 설정 본체 — 쿼리와 무관한 같은 자원 */
const SETTINGS_SOURCE = {
  id: 'settings',
  type: 'api',
  endpoint: '/api/modules/sirsoft-board/admin/settings',
  method: 'GET',
  auto_fetch: true,
  loading_strategy: 'blocking',
  initLocal: 'form',
  refetchOnMount: true,
};

/** 쿼리로 레코드가 바뀌는 소스 — 채널별 설정 */
const CHANNEL_SOURCE = {
  id: 'channel',
  type: 'api',
  endpoint: '/api/admin/channels/{{query.channel}}',
  method: 'GET',
  auto_fetch: true,
  initLocal: 'form',
};

describe('updateQueryParams — 탭 전환 재조회는 편집 중이어도 서버 값으로 되돌린다 (계약 잠금)', () => {
  let app: TemplateApp;
  let releaseRoot: () => void;

  const setup = (sources: any[], query: string, local: Record<string, any>, fetched: Record<string, any>) => {
    window.history.replaceState(null, '', `/admin/boards/settings?${query}`);
    (app as any).currentRawDataSources = sources;
    (app as any).currentDataSources = sources;
    (app as any).currentRouteParams = {};
    (app as any).currentQueryParams = new URLSearchParams(query);
    (app as any).currentFetchedData = {};
    (app as any).globalState = { _local: local };
    (app as any).currentGlobalHeaders = [];
    vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults').mockImplementation(async (list: any[]) =>
      list.map((s: any) => ({ id: s.id, state: 'success', data: { data: fetched[s.id] } })) as any,
    );
  };

  const lastUpdate = (): Record<string, any> => {
    const calls = updateTemplateDataMock.mock.calls;
    return calls.length ? calls[calls.length - 1][0] : {};
  };

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    (window as any).G7Core = {};
    resetTrackChangesRoots();
    releaseRoot = registerTrackChangesRoot('form');
    updateTemplateDataMock.mockClear();
    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
  });

  afterEach(() => {
    releaseRoot();
    resetTrackChangesRoots();
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  /**
   * @scenario case=dirty_same_resource
   * @effects dirty_form_reverted_to_server, data_source_refreshed
   */
  it('변경 있음 상태로 탭을 바꿔도 같은 자원의 initLocal 이 form 을 서버 값으로 되돌린다', async () => {
    setup([SETTINGS_SOURCE], 'tab=basic_defaults', { form: { basic_defaults: { max_file_size: '13' } }, hasChanges: true }, {
      settings: { basic_defaults: { max_file_size: 10 } },
    });

    await app.updateQueryParams('/admin/boards/settings?tab=report_policy');

    const update = lastUpdate();
    expect(update.settings).toEqual({ data: { basic_defaults: { max_file_size: 10 } } });
    expect(update._localInit?.form).toEqual({ basic_defaults: { max_file_size: 10 } });
  });

  /**
   * @scenario case=clean_same_resource
   * @effects clean_form_refreshed_from_server
   */
  it('변경이 없으면 initLocal 을 적용한다', async () => {
    setup([SETTINGS_SOURCE], 'tab=basic_defaults', { form: { basic_defaults: { max_file_size: 10 } }, hasChanges: false }, {
      settings: { basic_defaults: { max_file_size: 12 } },
    });

    await app.updateQueryParams('/admin/boards/settings?tab=report_policy');

    expect(lastUpdate()._localInit?.form).toEqual({ basic_defaults: { max_file_size: 12 } });
  });

  /**
   * @scenario case=dirty_query_dependent
   * @effects other_record_replaces_form
   */
  it('요청이 쿼리에 따라 바뀌는 소스(다른 레코드)는 변경 있음이어도 initLocal 을 적용한다', async () => {
    setup([CHANNEL_SOURCE], 'channel=email', { form: { sender: 'edited@x' }, hasChanges: true }, {
      channel: { sender: 'sms-sender' },
    });

    await app.updateQueryParams('/admin/boards/settings?channel=sms');

    expect(lastUpdate()._localInit?.form).toEqual({ sender: 'sms-sender' });
  });
});
