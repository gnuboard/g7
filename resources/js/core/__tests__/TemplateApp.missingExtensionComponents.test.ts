/**
 * TemplateApp.reportMissingExtensionComponents() 테스트
 *
 * 확장 컴포넌트가 등록되지 않으면 DynamicRenderer 가 노드마다 null 을 반환해 화면에서 조용히
 * 사라진다. S2.5 는 계획서의 "대기 게이트" 를 부팅 순서(init 7 → 8)로 대체하면서 그 대가로
 * **관측 장치**를 내세웠는데, 그 장치가 실제로 배선돼 있지 않으면 대체가 성립하지 않는다.
 * 이 테스트가 그 배선을 잠근다.
 *
 * 함께 잠그는 것: 오검출 방지. 폼 필드 props(`{ name: "mail.host", type: "text" }`)는
 * 컴포넌트 노드와 모양이 같아서, 타입 집합으로 좁히지 않으면 실제 레이아웃 596건에서 157종이
 * 확장 컴포넌트로 오검출된다(실측).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { Logger } from '../utils/Logger';

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

/**
 * 등록된 컴포넌트 이름만 아는 최소 레지스트리.
 *
 * `getMissingExtensionComponents` 는 실제 구현을 그대로 쓴다 — 좌표 판정(이름에 `.` 이 있고
 * 레지스트리에 없는 것)이 이 테스트의 대상이기 때문이다.
 */
const registered = new Set<string>();

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getMissingExtensionComponents: vi.fn((usedNames: string[]) =>
      usedNames.filter(name => name.includes('.') && !registered.has(name))
    ),
  };

  return {
    ComponentRegistry: {
      getInstance: vi.fn(() => mockInstance),
    },
  };
});

describe('TemplateApp.reportMissingExtensionComponents', () => {
  let app: TemplateApp;
  let report: (layout: any) => void;
  let errorSpy: ReturnType<typeof vi.spyOn>;

  const config: TemplateAppConfig = {
    templateId: 'test-template',
    templateType: 'user',
    locale: 'ko',
    debug: false,
  };

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

    (window as any).G7Config = {};

    const appDiv = document.createElement('div');
    appDiv.id = 'app';
    document.body.appendChild(appDiv);

    registered.clear();
    errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

    // logger.error 는 debug 일 때만 console 로 나간다 — 켜지 않으면 긍정 케이스가
    // "아무것도 호출되지 않아서" 통과하는 거짓 green 이 된다.
    Logger.getInstance().setDebug(true);

    app = new TemplateApp(config);
    report = (app as any).reportMissingExtensionComponents.bind(app);
  });

  afterEach(() => {
    const appDiv = document.getElementById('app');
    if (appDiv) document.body.removeChild(appDiv);
    Logger.getInstance().setDebug(false);
    errorSpy.mockRestore();
    vi.clearAllMocks();
    delete (window as any).G7Config;
  });

  /** @effects missing_extension_components_are_observable */
  it('등록되지 않은 확장 컴포넌트를 보고한다', () => {
    report({
      components: [
        { id: 'a', type: 'composite', name: 'sirsoft-ecommerce.ProductCard' },
      ],
    });

    expect(errorSpy).toHaveBeenCalled();
    expect(JSON.stringify(errorSpy.mock.calls)).toContain('sirsoft-ecommerce.ProductCard');
  });

  /** @effects missing_extension_components_are_observable */
  it('등록된 확장 컴포넌트는 보고하지 않는다', () => {
    registered.add('sirsoft-ecommerce.ProductCard');

    report({
      components: [
        { id: 'a', type: 'composite', name: 'sirsoft-ecommerce.ProductCard' },
      ],
    });

    expect(errorSpy).not.toHaveBeenCalled();
  });

  /** @effects missing_extension_components_are_observable */
  it('템플릿 소유 컴포넌트는 대상이 아니다 (이름에 확장 식별자가 없다)', () => {
    report({ components: [{ id: 'a', type: 'basic', name: 'Div' }] });

    expect(errorSpy).not.toHaveBeenCalled();
  });

  /** @effects missing_extension_components_are_observable */
  it('폼 필드 props 를 컴포넌트로 오검출하지 않는다', () => {
    // Input 의 props 는 `{ name, type }` 이라 컴포넌트 노드와 모양이 같다.
    report({
      components: [
        {
          id: 'field',
          type: 'basic',
          name: 'Input',
          props: { name: 'mail.host', type: 'text' },
        },
      ],
    });

    expect(errorSpy).not.toHaveBeenCalled();
  });

  /**
   * 배선 가드 — 메서드가 아니라 **호출부**를 잠근다.
   *
   * 관측 장치는 존재하는 것만으로는 아무 일도 하지 않는다. 실제로 S2.5 1차 구현은 이 API 를
   * 정의만 하고 프로덕션 호출자가 0건이어서, "대기 게이트 대신 관측으로 대체한다" 는 결정이
   * 실질적으로 이행되지 않은 채였다. 레이아웃 로드 경로를 직접 태워 그 재발을 막는다.
   *
   * @effects missing_extension_components_are_observable
   */
  it('레이아웃 로드 시 자동으로 보고된다 (호출부 배선)', async () => {
    (app as any).layoutLoader = {
      loadLayout: vi.fn().mockResolvedValue({
        components: [
          { id: 'a', type: 'composite', name: 'sirsoft-ecommerce.ProductCard' },
        ],
      }),
    };

    // handleRouteChange 는 레이아웃 로드 후 렌더까지 진행하다 mock 경계에서 멈출 수 있다.
    // 관측은 그보다 앞(1.4)에서 일어나므로 이후 실패는 이 테스트의 관심사가 아니다.
    await (app as any).handleRouteChange({ path: '/', layout: 'index', query: {} }).catch(() => {});

    expect(JSON.stringify(errorSpy.mock.calls)).toContain('sirsoft-ecommerce.ProductCard');
  });

  /** @effects missing_extension_components_are_observable */
  it('자식 키를 열거하지 않고 전 구조를 훑는다 (중첩·슬롯·모달)', () => {
    report({
      components: [
        {
          id: 'wrap',
          type: 'layout',
          name: 'Div',
          children: [
            {
              id: 'grid',
              type: 'composite',
              name: 'DataGrid',
              cellChildren: {
                actions: [{ id: 'c', type: 'composite', name: 'sirsoft-board.PostBadge' }],
              },
            },
          ],
        },
      ],
      modals: [
        { id: 'm', type: 'composite', name: 'sirsoft-ecommerce.CartModal' },
      ],
    });

    const reported = JSON.stringify(errorSpy.mock.calls);
    expect(reported).toContain('sirsoft-board.PostBadge');
    expect(reported).toContain('sirsoft-ecommerce.CartModal');
  });
});
