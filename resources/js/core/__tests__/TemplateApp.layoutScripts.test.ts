/**
 * TemplateApp.loadLayoutScripts — 레이아웃 스크립트 로드의 재시도·모드 보정·실패 표면화
 *
 * 종전에는 `onerror` 에서 `resolve()` 로 삼켜, 그 스크립트가 등록하는 핸들러가 전부
 * 미등록이어도 사용자에게는 "버튼이 안 눌린다" 로만 나타났다. `src` 도 확장자 형태로
 * 굳어 있어, 확장자를 정적 location 이 가로채는 서버에서는 자체 제공 자산이 404 였다.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { AuthManager } from '../auth/AuthManager';

const mockApiClient = {
  post: vi.fn().mockResolvedValue({}),
  get: vi.fn().mockResolvedValue({}),
  removeToken: vi.fn(),
  setToken: vi.fn(),
  getToken: vi.fn().mockReturnValue(null),
  setOnUnauthorized: vi.fn(),
};

vi.mock('../api/ApiClient', () => ({
  getApiClient: () => mockApiClient,
}));

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

vi.mock('../template-engine/LayoutLoader', async () => {
  const actual = await vi.importActual<any>('../template-engine/LayoutLoader');
  return {
    ...actual,
    LayoutLoader: vi.fn(function (this: any) {
      this.loadLayout = vi.fn().mockResolvedValue({ components: [] });
    }),
  };
});

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return {
    ComponentRegistry: {
      getInstance: vi.fn(() => mockInstance),
    },
  };
});


vi.mock('../support/assetUrl', async () => {
  const actual = await vi.importActual<any>('../support/assetUrl');
  return { ...actual, convertToCurrentMode: vi.fn((url: string) => `${url}#converted`) };
});

import { convertToCurrentMode } from '../support/assetUrl';
import { getAssetFailures, clearAllAssetFailures } from '../assets/AssetFailureNotice';

describe('TemplateApp.loadLayoutScripts', () => {
  let app: TemplateApp;
  let created: HTMLScriptElement[];

  const build = (trustedScriptHosts: string[] = []): TemplateApp => {
    (window as any).G7Config = { trustedScriptHosts };
    const config: TemplateAppConfig = {
      templateId: 'sirsoft-admin_basic',
      templateType: 'admin',
      locale: 'ko',
      debug: false,
    };
    return new TemplateApp(config);
  };

  /**
   * head.appendChild 를 가로채 결과를 발화시킵니다.
   *
   * 스크립트는 병렬로 로드되므로 "시도 순서" 인덱스로는 어느 스크립트의 시도인지
   * 특정할 수 없다 — URL 로 판정한다.
   *
   * @param decide src 를 받아 결과를 돌려주는 판정 함수
   */
  const stub = (decide: (src: string, attempt: number) => 'load' | 'error'): void => {
    const attempts = new Map<string, number>();

    vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
      if (node.tagName !== 'SCRIPT') return node;

      created.push(node);

      const src = String(node.getAttribute('src') ?? '');
      const attempt = (attempts.get(src) ?? 0) + 1;
      attempts.set(src, attempt);

      const outcome = decide(src, attempt);

      queueMicrotask(() => {
        if (outcome === 'error') {
          node.onerror?.(new Event('error'));
        } else {
          node.onload?.(new Event('load'));
        }
      });

      return node;
    }) as any);
  };

  /** 언제나 성공하는 판정 */
  const alwaysLoad = () => 'load' as const;

  const load = (app: TemplateApp, scripts: any[]) =>
    (app as any).loadLayoutScripts(scripts, {});

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    created = [];
    clearAllAssetFailures();
    app = build();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    clearAllAssetFailures();
    document.querySelectorAll('script[id^="s-"]').forEach(el => el.remove());
    delete (window as any).G7Config;
  });

  /**
   * @scenario asset_class=vendored, outcome=loaded
   * @effects layout_script_url_converted_to_current_mode
   */
  it('same-origin 경로는 현재 자산 URL 모드로 보정해 로드한다', async () => {
    stub(alwaysLoad);

    await load(app, [{ id: 's-1', src: '/api/plugins/assets/x/dist/a.js' }]);

    expect(convertToCurrentMode).toHaveBeenCalledWith('/api/plugins/assets/x/dist/a.js');
    expect(created[0].src).toContain('#converted');
  });

  it('외부 신뢰 호스트 스크립트는 모드 보정 대상이 아니다', async () => {
    app = build(['t1.daumcdn.net']);
    stub(alwaysLoad);

    await load(app, [{ id: 's-2', src: 'https://t1.daumcdn.net/a.js' }]);

    expect(created[0].src).toBe('https://t1.daumcdn.net/a.js');
  });

  /**
   * @scenario asset_class=vendored, outcome=failed
   * @effects layout_script_retries_before_failing
   */
  it('일시 실패는 재시도해 복구한다', async () => {
    stub((_src, attempt) => (attempt === 1 ? 'error' : 'load'));

    await load(app, [{ id: 's-3', src: '/a.js' }]);

    expect(created).toHaveLength(2);
    expect(app.getFailedLayoutScripts()).toEqual([]);
  });

  /**
   * @effects failed_layout_script_recorded_and_others_continue, failed_asset_shows_retry_notice
   */
  it('끝내 실패하면 기록·안내하되 다른 스크립트는 계속 로드한다', async () => {
    // /fail.js 는 몇 번을 시도해도 실패, /ok.js 는 성공 — 병렬 로드라 URL 로 판정한다
    stub(src => (src.includes('/fail.js') ? 'error' : 'load'));

    await load(app, [
      { id: 's-4', src: '/fail.js' },
      { id: 's-5', src: '/ok.js' },
    ]);

    expect(app.getFailedLayoutScripts()).toEqual(['s-4']);
    expect(getAssetFailures().map(f => f.id)).toContain('layout-script:s-4');
    // 실패해도 reject 하지 않는다 (여기까지 도달한 것 자체가 계약 충족)
    expect(created.filter(el => String(el.getAttribute('src')).includes('/ok.js'))).toHaveLength(1);
  });

  it('이미 로드된 id 는 건너뛴다', async () => {
    const existing = document.createElement('script');
    existing.id = 's-6';
    document.body.appendChild(existing);
    stub(alwaysLoad);

    await load(app, [{ id: 's-6', src: '/a.js' }]);

    expect(created).toHaveLength(0);
  });

  it('미선언 외부 origin 은 로드하지 않는다', async () => {
    stub(alwaysLoad);

    await load(app, [{ id: 's-7', src: 'https://evil.example.com/a.js' }]);

    expect(created).toHaveLength(0);
  });
});
