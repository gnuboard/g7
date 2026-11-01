/**
 * TemplateApp.loadG7Config() — `_global.activeModules` / `_global.activePlugins` 노출 (engine-v1.84.0, #822)
 *
 * 서버가 G7Config.activeModules·activePlugins 로 싣는 메타 객체 배열에서 식별자만 뽑아 같은 이름의 전역 상태로 싣는다.
 * 레이아웃 가드 `{{_global.activeModules?.includes('id') ?? true}}` 가
 *  - 켜진 모듈 → true, 꺼진 모듈 → false, 키가 없는 옛 서버 → true(`?? true`)
 * 로 읽히는지 실제 바인딩 엔진으로 함께 잠근다. SEO(봇) 렌더러의 같은 식은 tests/Unit/Seo/SeoGlobalActiveExtensionsTest.php.
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

/** sirsoft-basic 이 쓰는 가드 식(D2) — 번들 레이아웃과 같은 글자 */
const BOARD_GUARD = "_global.activeModules?.includes('sirsoft-board') ?? true";
const BOARD_GUARD_NEGATED = "!(_global.activeModules?.includes('sirsoft-board') ?? true)";
const POSTCODE_GUARD = "_global.activePlugins?.includes('sirsoft-daum_postcode') ?? true";

const config: TemplateAppConfig = {
  templateId: 'sirsoft-basic',
  templateType: 'user',
  locale: 'ko',
  debug: false,
};

/** 서버(CollectsActiveExtensionMeta)가 싣는 메타 모양 */
function meta(identifier: string) {
  return { identifier, display_name: { ko: identifier }, version: '1.0.0' };
}

function bootWith(g7Config: Record<string, unknown>): TemplateApp {
  (window as any).G7Config = g7Config;
  return new TemplateApp(config);
}

function evaluate(app: TemplateApp, expr: string): unknown {
  return new DataBindingEngine().evaluateExpression(expr, { _global: app.getGlobalState() } as any, { skipCache: true });
}

describe('TemplateApp.loadG7Config — 활성 확장 식별자 (engine-v1.84.0)', () => {
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

  // @scenario case=browser_global_state
  // @effects browser_global_carries_active_identifiers
  it('메타 객체 배열에서 식별자 배열을 같은 이름의 전역 상태로 싣는다', () => {
    const app = bootWith({
      activeModules: [meta('sirsoft-board'), meta('sirsoft-page')],
      activePlugins: [meta('sirsoft-daum_postcode')],
    });

    expect(app.getGlobalState().activeModules).toEqual(['sirsoft-board', 'sirsoft-page']);
    expect(app.getGlobalState().activePlugins).toEqual(['sirsoft-daum_postcode']);
  });

  // @scenario case=browser_global_state
  // @effects browser_global_carries_active_identifiers
  it('원본 G7Config.activeModules(메타 객체 배열)는 바꾸지 않는다 — 편집기·핸들러 대기가 읽는다', () => {
    const modules = [meta('sirsoft-board')];
    bootWith({ activeModules: modules, activePlugins: [] });

    expect((window as any).G7Config.activeModules).toBe(modules);
    expect((window as any).G7Config.activeModules[0]).toEqual(meta('sirsoft-board'));
  });

  // @scenario case=browser_global_state
  // @effects browser_global_carries_active_identifiers
  it('빈 배열은 「켜진 확장 없음」 — 빈 배열로 싣고 가드는 false', () => {
    const app = bootWith({ activeModules: [], activePlugins: [] });

    expect(app.getGlobalState().activeModules).toEqual([]);
    expect(app.getGlobalState().activePlugins).toEqual([]);
    expect(evaluate(app, BOARD_GUARD)).toBe(false);
    expect(evaluate(app, BOARD_GUARD_NEGATED)).toBe(true);
    expect(evaluate(app, POSTCODE_GUARD)).toBe(false);
  });

  // @scenario case=browser_global_state
  // @effects browser_global_carries_active_identifiers
  it('키가 없으면(옛 서버) 전역 상태를 만들지 않고 가드는 true(?? true)', () => {
    const app = bootWith({ settings: {} });

    expect('activeModules' in app.getGlobalState()).toBe(false);
    expect('activePlugins' in app.getGlobalState()).toBe(false);
    expect(evaluate(app, BOARD_GUARD)).toBe(true);
    expect(evaluate(app, BOARD_GUARD_NEGATED)).toBe(false);
    expect(evaluate(app, POSTCODE_GUARD)).toBe(true);
  });

  // @scenario case=browser_global_state
  // @effects browser_global_carries_active_identifiers
  it('배열이 아닌 값은 키 없음과 같이 다룬다', () => {
    const app = bootWith({ activeModules: { 'sirsoft-board': meta('sirsoft-board') }, activePlugins: null });

    expect('activeModules' in app.getGlobalState()).toBe(false);
    expect('activePlugins' in app.getGlobalState()).toBe(false);
  });

  // @scenario case=browser_global_state
  // @effects browser_global_carries_active_identifiers
  it('서버가 목록을 못 구해 null 을 실으면(수집 실패) 키 없음과 같이 「켜짐」 — 봇 화면과 같은 답', () => {
    const app = bootWith({ activeModules: null, activePlugins: null });

    expect('activeModules' in app.getGlobalState()).toBe(false);
    expect('activePlugins' in app.getGlobalState()).toBe(false);
    expect(evaluate(app, BOARD_GUARD)).toBe(true);
    expect(evaluate(app, BOARD_GUARD_NEGATED)).toBe(false);
    expect(evaluate(app, POSTCODE_GUARD)).toBe(true);
  });

  // @scenario case=browser_global_state
  // @effects browser_global_carries_active_identifiers
  it('켜진 모듈은 true, 꺼진 모듈은 false — 부정 식은 반대', () => {
    const boardOn = bootWith({ activeModules: [meta('sirsoft-board')], activePlugins: [] });
    expect(evaluate(boardOn, BOARD_GUARD)).toBe(true);
    expect(evaluate(boardOn, BOARD_GUARD_NEGATED)).toBe(false);

    const boardOff = bootWith({ activeModules: [meta('sirsoft-ecommerce')], activePlugins: [meta('sirsoft-daum_postcode')] });
    expect(evaluate(boardOff, BOARD_GUARD)).toBe(false);
    expect(evaluate(boardOff, BOARD_GUARD_NEGATED)).toBe(true);
    expect(evaluate(boardOff, POSTCODE_GUARD)).toBe(true);
  });

  // @scenario case=browser_global_state
  // @effects browser_global_carries_active_identifiers
  it('식별자가 없거나 문자열이 아닌 항목은 빼고, 문자열 항목은 식별자로 받는다', () => {
    const app = bootWith({
      activeModules: [meta('sirsoft-board'), { display_name: 'x' }, null, 42, 'sirsoft-page', { identifier: '' }],
    });

    expect(app.getGlobalState().activeModules).toEqual(['sirsoft-board', 'sirsoft-page']);
  });
});
