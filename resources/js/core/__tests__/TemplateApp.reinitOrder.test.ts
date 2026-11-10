/**
 * @file TemplateApp.reinitOrder.test.ts
 * @description 언어 전환 뒤 핸들러 재등록 순서가 부트와 달라 같은 이름의 승자가 뒤바뀌던 결함 (engine-v1.93.0, #816 S1 F3)
 *
 * 결함:
 *   부트는 「확장 에셋 로드(모듈·플러그인 등록) → 템플릿 핸들러 등록」 순서라 같은 이름이면 템플릿이 마지막에 이긴다.
 *   언어 전환 재초기화는 「모듈 → 템플릿 → 플러그인」 순서로 다시 등록해, 같은 이름이면 플러그인이 이겼다.
 *   전환 전후로 같은 액션이 다른 함수를 부르게 된다.
 *
 * 수정:
 *   언어 전환 재등록을 부트와 같은 「모듈 → 플러그인 → 템플릿」 순서로 맞춘다. 템플릿 등록에는
 *   `{ category: 'custom', source: <활성 템플릿 식별자> }` 를 붙여 등록자를 드러낸다(S1-2).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher, order } = vi.hoisted(() => {
  const order: string[] = [];
  return {
    order,
    sharedActionDispatcher: {
      setNavigate: vi.fn(),
      setGlobalState: vi.fn(),
      setDefaultContext: vi.fn(),
      setGlobalStateUpdater: vi.fn(),
      registerHandler: vi.fn((name: string) => {
        order.push(`register:${name}`);
      }),
      setNamedActions: vi.fn(),
      setGlobalHeaders: vi.fn(),
      isPreviewMode: vi.fn(() => false),
      setPreviewMode: vi.fn(),
      customHandlers: new Map(),
    },
  };
});

vi.mock('../template-engine', async () => {
  const actual = await vi.importActual<any>('../template-engine/DataSourceManager');
  return {
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: vi.fn().mockResolvedValue(undefined),
    destroyTemplate: vi.fn(),
    updateTemplateData: vi.fn(),
    DataSourceManager: actual.DataSourceManager,
    getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
    getState: vi.fn().mockReturnValue({
      actionDispatcher: sharedActionDispatcher,
      reactRoot: null,
      currentLayoutJson: null,
      currentDataContext: { _global: {} },
      bindingEngine: { invalidateCacheByKeys: vi.fn(), clearCache: vi.fn(), startRenderCycle: vi.fn() },
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
import { clearLocaleSwitchHandoff } from '../template-engine/localeSwitchHandoff';

describe('TemplateApp 언어 전환 재등록 순서 (engine-v1.93.0)', () => {
  let app: TemplateApp;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    (window as any).G7Core = {};
    clearLocaleSwitchHandoff();
    localStorage.removeItem('g7_locale');
    order.length = 0;
    sharedActionDispatcher.registerHandler.mockClear();

    // 확장 재등록 진입점 — 이름 고정(window.__X.initModule / initPlugin)
    (window as any).__ProbeModule = { identifier: 'acme-module', initModule: () => order.push('module') };
    (window as any).__ProbePlugin = { identifier: 'acme-plugin', initPlugin: () => order.push('plugin') };
    // 템플릿 핸들러 맵 — 코어가 직접 재등록
    (window as any).G7TemplateHandlers = { setTheme: () => {}, initTheme: () => {} };

    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko' } as any);
    const a = app as any;
    a.layoutLoader = { loadLayout: vi.fn().mockResolvedValue({ components: [] }), clear: vi.fn() };
    a.router = { navigateToCurrentPath: vi.fn(async () => {}) };
  });

  afterEach(() => {
    vi.restoreAllMocks();
    clearLocaleSwitchHandoff();
    delete (window as any).G7Core;
    delete (window as any).__ProbeModule;
    delete (window as any).__ProbePlugin;
    delete (window as any).G7TemplateHandlers;
  });

  // @scenario case=reinit_order_c01
  // @effects locale_switch_reregisters_in_boot_order
  it('언어 전환 재등록은 부트와 같은 모듈 → 플러그인 → 템플릿 순서다 (템플릿이 마지막에 이긴다)', async () => {
    await app.changeLocale('en', { persist: false });

    const firstTemplate = order.findIndex((o) => o.startsWith('register:'));
    expect(order.indexOf('module')).toBeGreaterThanOrEqual(0);
    expect(order.indexOf('plugin')).toBeGreaterThan(order.indexOf('module'));
    expect(firstTemplate).toBeGreaterThan(order.indexOf('plugin'));
  });

  // @scenario case=reinit_order_c02
  // @effects locale_switch_reregisters_in_boot_order
  it('템플릿 재등록은 활성 템플릿 식별자를 등록자로 넘긴다', async () => {
    await app.changeLocale('en', { persist: false });

    const calls = sharedActionDispatcher.registerHandler.mock.calls as any[];
    const setTheme = calls.find((c) => c[0] === 'setTheme');
    expect(setTheme).toBeDefined();
    expect(setTheme[2]).toEqual({ category: 'custom', source: 'sirsoft-admin_basic' });
  });
});
