/**
 * TemplateApp.calculateComputed 실호출 테스트 — 공개 이슈 140 (WP2)
 *
 * 라우트 진입 시점 computed 계산(`calculateComputed`)은 렌더 경로와 다른 규칙을 썼다:
 * 파이프 미처리(비트 OR 로 거부), 전처리 없음, `$switch` 판정에 `$cases` 필수, computed 간 참조 불가.
 * 공용 평가기로 같은 규칙을 쓰되 실패 시 undefined 정책은 유지한다. 이 메서드를 실제로 부르는
 * 테스트가 없어 보안 회귀선이 죽은 경로(evaluateComputedExpression)만 지키던 빈틈도 함께 닫는다.
 *
 * @since engine-v1.74.0
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


describe('TemplateApp.calculateComputed — 공용 평가기 경유', () => {
  let app: TemplateApp;
  const calc = (defs: Record<string, any>, ctx: Record<string, any> = {}): Record<string, any> =>
    (app as any).calculateComputed(defs, ctx);

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    Object.defineProperty(window, 'location', {
      value: { href: '', origin: 'https://g7.test', protocol: 'https:', pathname: '/', search: '' },
      writable: true,
      configurable: true,
    });
    (AuthManager as any).instance = undefined;
    // 계산 경로가 evaluateExpression 을 거치므로 devTools 스텁에 isEnabled 가 있어야 한다(실제 인터페이스와 같게)
    (window as any).G7Core = { devTools: { trackAuthEvent: vi.fn(), isEnabled: () => false } };
    (window as any).G7Config = { trustedScriptHosts: [] };
    vi.clearAllMocks();
    const config: TemplateAppConfig = { templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false };
    app = new TemplateApp(config);
  });

  afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).G7Config;
  });

  /**

   * @scenario layer=route_entry

   * @effects route_entry_computed_uses_shared_evaluator

   */
  it('파이프 computed 가 진입 시점에 올바른 값이 된다', () => {
    const out = calc({ up: "{{name | default('none') | uppercase}}" }, { name: 'abc' });
    expect(out.up).toBe('ABC'); // 수정 전: undefined(비트 OR 거부)
  });

  /**

   * @scenario layer=route_entry

   * @effects route_entry_computed_uses_shared_evaluator

   */
  it('$switch — $cases 가 없는 형태도 $default 로 평가한다', () => {
    const out = calc({
      a: { $switch: '{{status}}', $cases: { on: 'ON' }, $default: 'OFF' },
      b: { $switch: '{{status}}', $default: 'D' },
    }, { status: 'on' });
    expect(out).toEqual({ a: 'ON', b: 'D' });
  });

  /**

   * @scenario layer=route_entry

   * @effects route_entry_computed_uses_shared_evaluator

   */
  it('정의 순서 참조: 앞에 정의된 computed 를 뒤 computed 가 읽는다', () => {
    const out = calc({ base: '{{qty * 1000}}', total: '{{$computed.base + 3000}}' }, { qty: 2 });
    expect(out).toEqual({ base: 2000, total: 5000 });
  });

  /**

   * @scenario layer=route_entry

   * @effects route_entry_computed_uses_shared_evaluator

   */
  it('앞뒤 공백 정의도 식으로 평가하고, 리터럴은 원문, 실패는 undefined', () => {
    const out = calc({ ws: ' {{1 + 1}} ', lit: 'profile', bad: '{{[].reduce((a, b) => a)}}' }, {});
    expect(out.ws).toBe(2);
    expect(out.lit).toBe('profile');
    expect('bad' in out).toBe(true);
    expect(out.bad).toBeUndefined();
  });

  /**

   * @scenario layer=route_entry

   * @effects route_entry_computed_uses_shared_evaluator

   */
  it('진입 시점에도 옵셔널 체이닝 전처리가 적용된다(nullish 객체 접근이 예외가 아님)', () => {
    const out = calc({ name: "{{user.profile.name ?? 'none'}}" }, { user: null });
    expect(out.name).toBe('none');
  });

  describe('보안 회귀 — 실제 계산 경로에서도 막힌다', () => {
    /**
     * @scenario layer=route_entry
     * @effects route_entry_computed_security_blocked
     */
    it("''.constructor.constructor 함수 생성은 값이 undefined", () => {
      expect(calc({ x: "{{''.constructor.constructor('return 1')()}}" }).x).toBeUndefined();
    });
    /**
     * @scenario layer=route_entry
     * @effects route_entry_computed_security_blocked
     */
    it('eval 호출은 값이 undefined', () => {
      expect(calc({ x: "{{eval('1')}}" }).x).toBeUndefined();
    });
    /**
     * @scenario layer=route_entry
     * @effects route_entry_computed_security_blocked
     */
    it('__proto__ 접근은 값이 undefined', () => {
      expect(calc({ x: '{{({}).__proto__}}' }).x).toBeUndefined();
    });
  });
});
