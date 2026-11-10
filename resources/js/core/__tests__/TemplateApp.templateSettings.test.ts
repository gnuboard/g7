/**
 * TemplateApp.loadG7Config() — `_global.templateSettings` 노출 (engine-v1.93.0, #434)
 *
 * 서버가 G7Config.templateSettings 로 싣는 「이 화면 종류의 활성 템플릿 공개 설정」(평면 객체)을 같은 이름의 전역 상태로 싣는다.
 * 키가 없는 옛 서버에서는 전역 상태를 만들지 않는다 — 레이아웃은 `{{_global.templateSettings?.키 ?? 기본}}` 로 읽는다.
 * SEO(봇) 렌더러의 같은 키는 tests/Feature/View/FaviconTagsRenderingTest.php 가 잠근다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { DataBindingEngine } from '../template-engine/DataBindingEngine';

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
    this.setRoutes = vi.fn();
  }),
}));

vi.mock('../template-engine/LayoutLoader', () => ({
  LayoutLoader: vi.fn(function (this: any) {
    this.loadLayout = vi.fn().mockResolvedValue({ components: [] });
  }),
}));

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

const config: TemplateAppConfig = {
  templateId: 'sirsoft-basic',
  templateType: 'user',
  locale: 'ko',
  debug: false,
};

function bootWith(g7Config: Record<string, unknown>): TemplateApp {
  (window as any).G7Config = g7Config;
  return new TemplateApp(config);
}

function evaluate(app: TemplateApp, expr: string): unknown {
  return new DataBindingEngine().evaluateExpression(expr, { _global: app.getGlobalState() } as any, { skipCache: true });
}

describe('TemplateApp.loadG7Config — 활성 템플릿 공개 설정 (engine-v1.93.0)', () => {
  beforeEach(() => {
    const store: Record<string, string> = {};
    Object.defineProperty(global, 'localStorage', {
      value: {
        getItem: vi.fn((key: string) => store[key] ?? null),
        setItem: vi.fn((key: string, val: string) => {
          store[key] = val;
        }),
        removeItem: vi.fn((key: string) => {
          delete store[key];
        }),
      },
      writable: true,
      configurable: true,
    });

    const appDiv = document.createElement('div');
    appDiv.id = 'app';
    document.body.appendChild(appDiv);
  });

  afterEach(() => {
    document.getElementById('app')?.remove();
    vi.clearAllMocks();
    delete (window as any).G7Config;
  });

  // @scenario case=browser_global_template_settings
  // @effects template_settings_exposed_to_global
  it('G7Config.templateSettings 를 같은 이름의 전역 상태로 싣고 바인딩으로 읽힌다', () => {
    const app = bootWith({ templateSettings: { favicon_url: '/api/favicon/AbCdEf123456' } });

    expect(app.getGlobalState().templateSettings).toEqual({ favicon_url: '/api/favicon/AbCdEf123456' });
    expect(evaluate(app, "_global.templateSettings?.favicon_url ?? ''")).toBe('/api/favicon/AbCdEf123456');
  });

  // @scenario case=browser_global_template_settings
  // @effects template_settings_exposed_to_global
  it('키가 없으면(옛 서버) 전역 상태를 만들지 않고 기본값 식으로 읽힌다', () => {
    const app = bootWith({ settings: {} });

    expect('templateSettings' in app.getGlobalState()).toBe(false);
    expect(evaluate(app, "_global.templateSettings?.favicon_url ?? 'none'")).toBe('none');
  });

  // @scenario case=browser_global_template_settings
  // @effects template_settings_exposed_to_global
  it('객체가 아닌 값(null)은 키 없음과 같이 다룬다', () => {
    const app = bootWith({ templateSettings: null });

    expect('templateSettings' in app.getGlobalState()).toBe(false);
  });

  // @scenario case=browser_global_template_settings
  // @effects template_settings_exposed_to_global
  it('빈 객체(설정 없는 템플릿)는 빈 객체로 싣는다 — 다른 전역 키는 그대로', () => {
    const app = bootWith({ templateSettings: {}, plugins: { 'sirsoft-x': { a: 1 } } });

    expect(app.getGlobalState().templateSettings).toEqual({});
    expect(app.getGlobalState().plugins).toEqual({ 'sirsoft-x': { a: 1 } });
  });
});
