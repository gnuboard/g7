/**
 * TemplateApp 안전 평가기 경로 테스트 (KVE-2026-1915 B-2)
 *
 * computed(`evaluateComputedExpression`) 와 scripts[].if(`evaluateScriptCondition`)
 * 두 싱크가 실제 TemplateApp 경로에서 화이트리스트 AST 평가기(evaluateSafeExpression)를
 * 사용함을 검증한다. 종전의 `new Function('ctx','with(ctx){…}')` 로 회귀하면
 * `''.constructor.constructor('return …')()` 가 값으로 평가되어 아래 단언이 깨진다.
 *
 * DefinesComputed.test.ts 는 평가기 자체(evaluateSafeExpression)를 직접 호출하지만,
 * 이 파일은 실제 TemplateApp private 메서드를 거쳐 "엔진이 이 싱크에서 안전 평가기를
 * 실제로 쓰는지" 를 고정한다.
 *
 * 효과 요약(마커 아님 — 평문): sandbox_escape_blocked, same_expression_same_value_across_paths.
 * 실제 마커는 그 효과를 단언하는 개별 테스트에만 둔다 — 파일 레벨에 몰아 적으면 테스트를
 * 전부 지워도 커버리지가 green 으로 남는다.
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

describe('TemplateApp 안전 평가기 경로 (computed / scripts[].if)', () => {
  let app: TemplateApp;

  const build = (): TemplateApp => {
    (window as any).G7Config = { trustedScriptHosts: [] };
    const config: TemplateAppConfig = {
      templateId: 'sirsoft-admin_basic',
      templateType: 'admin',
      locale: 'ko',
      debug: false,
    };
    return new TemplateApp(config);
  };

  // computed 싱크: 이미 {{}} 가 제거된 내부 표현식을 받는다
  const computed = (a: TemplateApp, expr: string, ctx: Record<string, any> = {}): any =>
    (a as any).evaluateComputedExpression(expr, ctx);

  // scripts[].if 싱크: {{...}} 형태의 조건을 받아 boolean 을 돌려준다
  const condition = (a: TemplateApp, cond: string, ctx: Record<string, any> = {}): boolean =>
    (a as any).evaluateScriptCondition(cond, ctx);

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    Object.defineProperty(window, 'location', {
      value: {
        href: '',
        origin: 'https://g7.test',
        protocol: 'https:',
        pathname: '/',
        search: '',
      },
      writable: true,
      configurable: true,
    });
    (AuthManager as any).instance = undefined;
    (window as any).G7Core = { devTools: { trackAuthEvent: vi.fn() } };
    vi.clearAllMocks();
    app = build();
  });

  afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).G7Config;
  });

  describe('computed 정상 평가', () => {
    /** @effects same_expression_same_value_across_paths */
    it('속성 접근/산술/삼항/옵셔널 체이닝을 평가한다', () => {
      expect(computed(app, 'user.name', { user: { name: '홍길동' } })).toBe('홍길동');
      expect(computed(app, 'price * qty', { price: 1000, qty: 3 })).toBe(3000);
      expect(computed(app, "role === 'admin' ? 'Y' : 'N'", { role: 'admin' })).toBe('Y');
      expect(computed(app, "user?.profile?.name ?? '없음'", { user: { profile: null } })).toBe('없음');
    });
  });

  describe('computed 보안 회귀 — 샌드박스 탈출 차단', () => {
    /** @effects sandbox_escape_blocked */
    it("''.constructor.constructor 함수 생성은 undefined 로 막힌다", () => {
      expect(computed(app, "''.constructor.constructor('return 1')()")).toBeUndefined();
    });

    /** @effects sandbox_escape_blocked */
    it('전역 Function/eval 참조는 undefined 로 막힌다', () => {
      expect(computed(app, "Function('return 1')()")).toBeUndefined();
      expect(computed(app, "eval('1')")).toBeUndefined();
    });

    /** @effects sandbox_escape_blocked */
    it('__proto__/prototype 접근은 undefined 로 막힌다', () => {
      expect(computed(app, '({}).__proto__')).toBeUndefined();
      expect(computed(app, '[].constructor.prototype')).toBeUndefined();
    });
  });

  describe('scripts[].if 정상 평가', () => {
    /** @effects same_expression_same_value_across_paths */
    it('{{...}} 조건을 boolean 으로 평가한다', () => {
      expect(condition(app, '{{_global.on}}', { _global: { on: true } })).toBe(true);
      expect(condition(app, '{{_global.on}}', { _global: { on: false } })).toBe(false);
      expect(condition(app, "{{status === 'active'}}", { status: 'active' })).toBe(true);
    });
  });

  describe('scripts[].if 보안 회귀 — 샌드박스 탈출 차단', () => {
    /** @effects sandbox_escape_blocked */
    it("''.constructor.constructor 조건은 false 로 막힌다", () => {
      expect(condition(app, "{{''.constructor.constructor('return true')()}}")).toBe(false);
    });

    /** @effects sandbox_escape_blocked */
    it('전역 Function 참조 조건은 false 로 막힌다', () => {
      expect(condition(app, "{{Function('return true')()}}")).toBe(false);
    });
  });
});
