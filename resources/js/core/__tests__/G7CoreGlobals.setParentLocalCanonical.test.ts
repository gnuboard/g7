/**
 * 회귀: `setParentLocal` 의 저장소 B 쓰기가 live B 를 base 로 한다 (engine-v1.63.3 / 공개 이슈 #130 계열)
 *
 * 결함: `setParentLocal` 은 부모 컨텍스트의 **저장소 A**(`parentEntry.state._local`)를 base 로
 * `merged` 를 만든 뒤 그것을 그대로 `setGlobalState({ _local: merged })` 로 보냈다.
 * `setGlobalState` 는 `_local` 을 얕게 병합하므로 이 쓰기는 patch 가 아니라 **통째 교체**이며,
 * A 가 아직 받지 못한 값(예: `selfManaged` 플러그인이 B 에만 쓴 편집기 본문)이 조용히 사라진다.
 * 부모가 페이지 루트일 때 그 A 스냅샷은 B 보다 뒤처져 있을 수 있다(사례 21).
 *
 * 계약:
 *  1. B 쓰기는 live B + 변경 키 (A 에만 있던 키는 `addMissingLeafKeys` 로 보충)
 *  2. 저장소 A 경로(`parentEntry.setState`)와 `__g7PendingLocalState` 는 종전 그대로 —
 *     그쪽 base 를 바꾸면 React 전용 배열이 B 초기값으로 덮이는 사례 22 위험이 생긴다
 *  3. `merge: 'replace'` 는 의도적 리셋이므로 live B 를 base 로 쓰지 않는다 (사례 17)
 *
 * 이 파일은 **실물 엔진**을 세운다 — `template-engine/__tests__/G7CoreGlobals.test.ts` 의
 * `setParentLocal` 테스트는 G7Core 를 테스트 파일 안에 재구현해 검증하므로 엔진을 지워도 초록이다.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initializeG7CoreGlobals, G7CoreDependencies } from '../template-engine/G7CoreGlobals';

vi.mock('../template-engine/ComponentRegistry', () => ({
  ComponentRegistry: { getInstance: vi.fn(() => ({ getComponentMap: vi.fn(() => ({})) })) },
}));
vi.mock('../template-engine/TranslationEngine', () => ({ TranslationEngine: vi.fn(), TranslationContext: {} }));
vi.mock('../template-engine/ActionDispatcher', () => ({ ActionDispatcher: vi.fn() }));
vi.mock('../template-engine/DataBindingEngine', () => ({ DataBindingEngine: vi.fn(), dataBindingEngine: {} }));
vi.mock('../template-engine/DataSourceManager', () => ({ DataSourceManager: vi.fn(), dataSourceManager: {} }));
vi.mock('../template-engine/DynamicRenderer', () => ({ default: () => null }));
vi.mock('../template-engine/ResponsiveManager', () => ({ responsiveManager: {}, BREAKPOINT_PRESETS: {} }));
vi.mock('../template-engine/TransitionContext', () => ({ useTransitionState: vi.fn(() => ({ isPending: false })) }));
vi.mock('../template-engine/TranslationContext', () => ({
  useTranslation: vi.fn(() => ({ t: (k: string) => k })),
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
  mergeClasses: vi.fn((...a: string[]) => a.join(' ')),
  conditionalClass: vi.fn(() => ''),
  joinClasses: vi.fn((...a: (string | false | undefined)[]) => a.filter(Boolean).join(' ')),
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

describe('setParentLocal — 저장소 B 쓰기는 live B 를 base 로 한다', () => {
  let globalState: Record<string, any>;
  let setGlobalState: ReturnType<typeof vi.fn>;
  let parentSetState: ReturnType<typeof vi.fn>;
  let originalG7Core: any;

  /** setGlobalState 로 B 에 실제로 쓰인 _local */
  const writtenLocal = () => {
    const calls = setGlobalState.mock.calls;
    return calls.length ? calls[calls.length - 1][0]._local : undefined;
  };

  /**
   * 부모 컨텍스트를 스택에 세웁니다.
   *
   * @param parentLocalA 부모 컨텍스트의 저장소 A 스냅샷
   */
  const pushParent = (parentLocalA: Record<string, any>): void => {
    (window as any).__g7LayoutContextStack = [{ state: { _local: parentLocalA }, setState: parentSetState }];
  };

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    delete (window as any).G7Core;

    globalState = { _local: {} };
    setGlobalState = vi.fn((updates: any) => {
      globalState = { ...globalState, ...updates };
    });
    parentSetState = vi.fn();

    (window as any).__templateApp = { getGlobalState: () => globalState, setGlobalState };
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7LayoutContextStack = [];

    initializeG7CoreGlobals(createMockDependencies());
  });

  afterEach(() => {
    vi.clearAllMocks();
    delete (window as any).__templateApp;
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7LayoutContextStack = [];
    if (originalG7Core === undefined) delete (window as any).G7Core;
    else (window as any).G7Core = originalG7Core;
  });

  it('결함 재현 핀: B 에만 있던 편집기 본문이 stale 부모 A 스냅샷으로 덮이지 않는다', () => {
    // CKEditor 가 setLocal({render:false, selfManaged:true}) 로 B 에만 기록한 본문
    globalState._local = { form: { title: '제목', content: '<p>본문</p>' } };
    // 부모 컨텍스트의 저장소 A 는 그 본문을 아직 못 받았다
    pushParent({ form: { title: '제목', content: '' } });

    (window as any).G7Core.state.setParentLocal({ 'form.title': '새 제목' });

    expect(writtenLocal().form.content, 'B 의 본문이 살아 있어야 한다').toBe('<p>본문</p>');
    expect(writtenLocal().form.title).toBe('새 제목');
  });

  it('B 에만 있던 최상위 키도 잃지 않는다', () => {
    globalState._local = { couponId: 7, orderer: { name: '관리자' } };
    pushParent({ selectedItems: [1, 2] });

    (window as any).G7Core.state.setParentLocal({ memo: 'x' });

    expect(writtenLocal().couponId).toBe(7);
    expect(writtenLocal().orderer).toEqual({ name: '관리자' });
    expect(writtenLocal().memo).toBe('x');
  });

  it('부모 A 에만 있던 키는 addMissingLeafKeys 로 보충된다', () => {
    globalState._local = { form: { content: '<p>본문</p>' } };
    pushParent({ form: { content: '' }, loadingActions: { save: true } });

    (window as any).G7Core.state.setParentLocal({ memo: 'y' });

    expect(writtenLocal().loadingActions, 'A 전용 키가 B 에 보충되어야 한다').toEqual({ save: true });
    expect(writtenLocal().form.content).toBe('<p>본문</p>');
  });

  it('사례 22 핀: 저장소 A 경로는 종전대로 부모 A 기반이다', () => {
    globalState._local = { expandedRows: [] };
    pushParent({ expandedRows: [301] });

    (window as any).G7Core.state.setParentLocal({ memo: 'z' });

    // parentEntry.setState 는 부모 A 기반 merged 를 받아야 한다 (B 기반으로 바꾸면 배열이 초기값으로 덮인다)
    expect(parentSetState).toHaveBeenCalledTimes(1);
    expect(parentSetState.mock.calls[0][0].expandedRows).toEqual([301]);
  });

  it('사례 17 핀: merge replace 는 live B 를 base 로 쓰지 않는다', () => {
    globalState._local = { form: { content: '<p>본문</p>' }, keep: 1 };
    pushParent({ form: { content: '' } });

    (window as any).G7Core.state.setParentLocal({ only: 'this' }, { merge: 'replace' });

    expect(writtenLocal().keep, 'replace 는 의도적 리셋이다').toBeUndefined();
    expect(writtenLocal().only).toBe('this');
  });

  it('merge shallow 도 live B 를 base 로 한다', () => {
    globalState._local = { a: 1, b: 2, form: { content: '<p>본문</p>' } };
    pushParent({ a: 1 });

    (window as any).G7Core.state.setParentLocal({ b: 99 }, { merge: 'shallow' });

    expect(writtenLocal().b).toBe(99);
    expect(writtenLocal().a).toBe(1);
    expect(writtenLocal().form.content).toBe('<p>본문</p>');
  });

  it('컨텍스트 스택이 비면 아무것도 쓰지 않는다', () => {
    (window as any).__g7LayoutContextStack = [];

    (window as any).G7Core.state.setParentLocal({ x: 1 });

    expect(setGlobalState).not.toHaveBeenCalled();
    expect(parentSetState).not.toHaveBeenCalled();
  });
});
