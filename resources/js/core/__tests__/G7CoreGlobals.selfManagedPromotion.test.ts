/**
 * G7CoreGlobals.selfManagedPromotion.test.ts
 *
 * 사례 40(engine-v1.63.3) L5 — 사례 32 의 자동 승격/`selfManaged` opt-out 을 **실물로** 검증한다.
 *
 * 왜 별도 파일인가:
 *   기존 사례 32 회귀 테스트(`troubleshooting-state-setstate.test.ts`)는 판정 함수
 *   `shouldPromoteRender`/`flattenLeafPaths` 를 테스트 파일 안에 **재구현**해 검증한다.
 *   그래서 엔진의 판정 블록(G7CoreGlobals.ts:1834~1843)을 통째로 지워도 그 테스트는 초록이다.
 *   이 파일은 `initializeG7CoreGlobals()` 로 실제 `G7Core.state.setLocal` 을 세워
 *   **엔진이 실제로 승격하는지**를 `setGlobalState` 의 `render` 인자로 관측한다.
 *
 *   그리고 troubleshooting 파일과 공존할 수 없다 — 그 파일은 실물 `ActionDispatcher` 를 쓰는데
 *   여기서는 `vi.mock('../template-engine/ActionDispatcher')` 가 필요하다.
 *
 * 사례 40 과의 관계:
 *   `selfManaged: true` 는 **의도된 opt-out** 이다(37,000+ 바인딩 재평가 회피).
 *   그 opt-out 이 안전한 전제는 "저장소 B 를 통째로 교체하는 경로가 없을 것" 이며,
 *   그 전제는 사례 40 의 `handleSetState` COMPONENT path 수정이 잠근다.
 *   따라서 이 파일은 opt-out 이 **살아 있음**을 단언한다 — 없애는 것이 사례 40 의 수정 방향이 아니다.
 *
 * @see 트러블슈팅 사례 32(자동 승격 도입) · 사례 40(저장소 B 통째 교체)
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initializeG7CoreGlobals, G7CoreDependencies } from '../template-engine/G7CoreGlobals';

vi.mock('../template-engine/ComponentRegistry', () => ({
  ComponentRegistry: {
    getInstance: vi.fn(() => ({ getComponentMap: vi.fn(() => ({})) })),
  },
}));
vi.mock('../template-engine/TranslationEngine', () => ({
  TranslationEngine: vi.fn(),
  TranslationContext: {},
}));
vi.mock('../template-engine/ActionDispatcher', () => ({ ActionDispatcher: vi.fn() }));
vi.mock('../template-engine/DataBindingEngine', () => ({
  DataBindingEngine: vi.fn(),
  dataBindingEngine: {},
}));
vi.mock('../template-engine/DataSourceManager', () => ({
  DataSourceManager: vi.fn(),
  dataSourceManager: {},
}));
vi.mock('../template-engine/DynamicRenderer', () => ({ default: () => null }));
vi.mock('../template-engine/ResponsiveManager', () => ({
  responsiveManager: {},
  BREAKPOINT_PRESETS: {},
}));
vi.mock('../template-engine/TransitionContext', () => ({
  useTransitionState: vi.fn(() => ({ isPending: false })),
}));
vi.mock('../template-engine/TranslationContext', () => ({
  useTranslation: vi.fn(() => ({ t: (key: string) => key })),
  TranslationProvider: () => null,
  TranslationReactContext: {},
}));
vi.mock('../template-engine/ResponsiveContext', () => ({
  useResponsive: vi.fn(() => ({ width: 1024, isMobile: false })),
  ResponsiveProvider: () => null,
  ResponsiveContext: {},
}));
vi.mock('../auth/AuthManager', () => ({ AuthManager: { getInstance: vi.fn() } }));
vi.mock('../api/ApiClient', () => ({ getApiClient: vi.fn(() => ({ get: vi.fn(), post: vi.fn() })) }));
vi.mock('../websocket/WebSocketManager', () => ({ WebSocketManager: vi.fn() }));
vi.mock('../template-engine/helpers', () => ({
  renderItemChildren: vi.fn(() => []),
  createChangeEvent: vi.fn(),
  createClickEvent: vi.fn(),
  createSubmitEvent: vi.fn(),
  createKeyboardEvent: vi.fn(),
  mergeClasses: vi.fn((...args: string[]) => args.join(' ')),
  conditionalClass: vi.fn(() => ''),
  joinClasses: vi.fn((...args: (string | false | undefined)[]) => args.filter(Boolean).join(' ')),
}));

function createMockDependencies(): G7CoreDependencies {
  return {
    getState: vi.fn(() => ({
      translationEngine: { translate: vi.fn((k: string) => k) } as any,
      translationContext: { templateId: 'test', locale: 'ko' },
      bindingEngine: {} as any,
      actionDispatcher: {} as any,
      templateMetadata: { locales: ['ko'] },
    })),
    transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
    responsiveManager: {},
    webSocketManager: {
      subscribe: vi.fn(() => 'sub'),
      unsubscribe: vi.fn(),
      leaveChannel: vi.fn(),
      disconnect: vi.fn(),
      isInitialized: vi.fn(() => true),
      getSubscriptionCount: vi.fn(() => 0),
    } as any,
  };
}

describe('[사례 32 실물화] setLocal 자동 승격과 selfManaged opt-out (사례 40 L5)', () => {
  let globalState: Record<string, any>;
  let setGlobalState: ReturnType<typeof vi.fn>;
  let originalG7Core: any;

  /** 마지막 setGlobalState 호출의 render 옵션 (undefined = 미지정 = 기본 true) */
  const lastRenderOption = () => {
    const calls = setGlobalState.mock.calls;
    return calls.length ? calls[calls.length - 1][1]?.render : 'NOT_CALLED';
  };

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    delete (window as any).G7Core;

    globalState = { _local: {} };
    setGlobalState = vi.fn((updates: any) => {
      globalState = { ...globalState, ...updates };
    });

    (window as any).__templateApp = {
      getGlobalState: () => globalState,
      setGlobalState,
      // debounce 경로로 새지 않도록 ActionDispatcher 를 노출하지 않는다
      getActionDispatcher: undefined,
    };
    (window as any).__g7AutoBindingPaths = new Map<string, number>();
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
    (window as any).__g7ActionContext = undefined;
    (window as any).__g7LayoutContextStack = [];

    initializeG7CoreGlobals(createMockDependencies());
  });

  afterEach(() => {
    vi.clearAllMocks();
    delete (window as any).__templateApp;
    delete (window as any).__g7AutoBindingPaths;
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
    (window as any).__g7LayoutContextStack = [];
    if (originalG7Core === undefined) delete (window as any).G7Core;
    else (window as any).G7Core = originalG7Core;
  });

  const setLocal = (updates: any, options?: any) =>
    (window as any).G7Core.state.setLocal(updates, options);

  it('레지스트리에 겹치는 경로를 render:false 로 쓰면 엔진이 render:true 로 승격한다', () => {
    (window as any).__g7AutoBindingPaths.set('form.title', 1);

    setLocal({ 'form.title': 'X' }, { render: false });

    expect(lastRenderOption()).toBe(true);
  });

  it('selfManaged:true 는 겹쳐도 승격하지 않는다 (CKEditor 성능 opt-out 보존)', () => {
    // @scenario save_flow=edit, resize_kind=none
    // @effects selfmanaged_setlocal_writes_canonical_without_render
    (window as any).__g7AutoBindingPaths.set('form.content', 1);

    setLocal({ 'form.content': '<p>내용</p>' }, { render: false, selfManaged: true });

    expect(lastRenderOption()).toBe(false);
  });

  it('레지스트리가 비면 승격하지 않는다 (SPA 네비게이션 직후)', () => {
    setLocal({ 'form.title': 'X' }, { render: false });

    expect(lastRenderOption()).toBe(false);
  });

  it('겹치지 않는 경로는 승격하지 않는다', () => {
    (window as any).__g7AutoBindingPaths.set('form.title', 1);

    setLocal({ 'form.content': 'Y' }, { render: false });

    expect(lastRenderOption()).toBe(false);
  });

  it('render 옵션을 생략하면 판정 분기에 들어가지 않는다 (기본 render:true)', () => {
    (window as any).__g7AutoBindingPaths.set('form.title', 1);

    setLocal({ 'form.title': 'X' });

    expect(lastRenderOption()).toBeUndefined();
  });

  it('selfManaged 경로도 저장소 B 에는 값을 남긴다 (사례 40 의 재현 전제)', () => {
    // @scenario save_flow=create, resize_kind=none
    // @effects selfmanaged_setlocal_writes_canonical_without_render
    (window as any).__g7AutoBindingPaths.set('form.content', 1);

    setLocal({ 'form.content': '<p>본문</p>' }, { render: false, selfManaged: true });

    expect(globalState._local.form.content).toBe('<p>본문</p>');
    // 오버레이/pending 도 함께 세워진다 — 리사이즈가 pending 만 지우는 것이 결함의 절반이다
    expect((window as any).__g7PendingLocalState.form.content).toBe('<p>본문</p>');
    expect((window as any).__g7ForcedLocalFields.form.content).toBe('<p>본문</p>');
    expect((window as any).__g7SetLocalOverrideKeys.form.content).toBe('<p>본문</p>');
  });
});
