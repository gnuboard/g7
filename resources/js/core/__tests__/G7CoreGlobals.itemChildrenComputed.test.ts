// e2e:allow 단위·레이아웃 시험 파일 — 실화면 측정은 같은 엔진 변경의 브라우저 매트릭스
/**
 * 회귀: 반복 셀·확장 행 안의 `_computed` 가 늘 빈 객체였다 (engine-v1.85.0)
 *
 * DataGrid 셀(`cellChildren`·`subRowChildren`)은 `G7Core.renderItemChildren` 으로, 확장 행(`expandChildren`)은
 * `G7Core.renderExpandContent` 로 그려진다. 두 래퍼가 셀 문맥에 `_computed: {}`(또는 `globalState._computed || {}`)를
 * 먼저 넣어, 셀 렌더 함수의 `getEffectiveContext()` 가 부모 데이터 문맥(`componentContext.parentDataContext`)을
 * 「결과에 없는 키만」 병합할 때 `_computed` 자리가 이미 차 있어 부모 값이 들어오지 못했다 → 셀 안
 * `{{_computed.x}}` 는 늘 undefined(SSO 회원 연결 표 「연결 해제」 상시 비활성, 통계 리포트 표 `_computed.reportName`,
 * 이커머스 상품 표 `_computed.currencyDisplayColumns`).
 *
 * 고침: 셀 문맥의 `_computed` 기본값을 부모 컴포넌트의 최신 계산값(`componentContext.computedRef.current`,
 * 없으면 `parentDataContext._computed`)으로 둔다. 셀 문맥이 `_computed` 를 직접 주면 그 값이 이긴다(종전과 같음).
 *
 * 셀 렌더 함수는 모킹하고, 래퍼가 넘긴 셀 문맥에 실제 `getEffectiveContext` 를 적용해 셀이 보는 값을 단언한다.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initializeG7CoreGlobals, G7CoreDependencies } from '../template-engine/G7CoreGlobals';
import { renderItemChildren } from '../template-engine/helpers';

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
    getState: vi.fn(() => ({ translationContext: { templateId: 'host', locale: 'ko' } })),
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

/** 마지막 셀 렌더 호출에서 셀이 실제로 보는 문맥(실제 getEffectiveContext 적용) */
const effectiveCellContext = async () => {
  const { getEffectiveContext } = await vi.importActual<typeof import('../template-engine/helpers/RenderHelpers')>(
    '../template-engine/helpers/RenderHelpers'
  );
  const calls = (renderItemChildren as any).mock.calls;
  const [, context, , , options] = calls[calls.length - 1];
  return getEffectiveContext(context, options?.componentContext);
};

/** DataGrid 가 받는 `__componentContext` 와 같은 모양 — 부모 DynamicRenderer 의 데이터 문맥과 최신 계산값 ref */
const gridComponentContext = (computed: Record<string, any> | undefined, refComputed?: Record<string, any>) => ({
  state: {},
  setState: vi.fn(),
  stateRef: { current: {} },
  ...(refComputed !== undefined ? { computedRef: { current: refComputed } } : {}),
  parentDataContext: {
    ssoAccounts: { data: { abilities: { can_unlink: true } } },
    ...(computed !== undefined ? { _computed: computed } : {}),
  },
});

describe('[case:components-datagrid-cell-computed-empty] G7Core 셀 렌더 래퍼 — 셀 안 _computed 가 부모 계산값을 본다', () => {
  let G7Core: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    (window as any).__templateApp = { getGlobalState: () => ({}) };
    initializeG7CoreGlobals(createMockDependencies());
    G7Core = (window as any).G7Core;
    (renderItemChildren as any).mockClear();
  });

  afterEach(() => {
    delete (window as any).__templateApp;
  });

  /**
   * @scenario case=cell_computed_ref
   * @effects cell_computed_reads_parent_latest
   */
  it('renderItemChildren: 셀 안 _computed 가 부모의 최신 계산값(computedRef.current)이다', async () => {
    G7Core.renderItemChildren([{}], { row: { id: 1 } }, {}, 'k', {
      componentContext: gridComponentContext({ canUnlink: false }, { canUnlink: true }),
    });

    const ctx = await effectiveCellContext();
    expect(ctx._computed).toEqual({ canUnlink: true });
    // 데이터소스 병합(engine-v1.19.1)은 종전대로
    expect(ctx.ssoAccounts.data.abilities.can_unlink).toBe(true);
  });

  /**
   * @scenario case=cell_parent_data_context
   * @effects cell_computed_reads_parent_latest
   */
  it('renderItemChildren: computedRef 가 없으면 부모 데이터 문맥의 _computed 를 본다', async () => {
    G7Core.renderItemChildren([{}], { row: { id: 1 } }, {}, 'k', {
      componentContext: gridComponentContext({ reportName: 'site_search' }),
    });

    const ctx = await effectiveCellContext();
    expect(ctx._computed).toEqual({ reportName: 'site_search' });
  });

  /**
   * @scenario case=cell_explicit_item_context
   * @effects cell_computed_explicit_wins
   */
  it('대조군: 셀 문맥이 _computed 를 직접 주면 그 값이 이긴다', async () => {
    G7Core.renderItemChildren([{}], { row: { id: 1 }, _computed: { own: 1 } }, {}, 'k', {
      componentContext: gridComponentContext({ canUnlink: true }, { canUnlink: true }),
    });

    const ctx = await effectiveCellContext();
    expect(ctx._computed).toEqual({ own: 1 });
  });

  /**
   * @scenario case=cell_no_parent
   * @effects cell_computed_empty_without_parent
   */
  it('대조군: 부모 문맥이 없으면(CardGrid 등) 종전처럼 빈 객체', async () => {
    G7Core.renderItemChildren([{}], { row: { id: 1 } }, {}, 'k');

    const ctx = await effectiveCellContext();
    expect(ctx._computed).toEqual({});
  });

  /**
   * @scenario case=expand_computed_ref
   * @effects cell_computed_reads_parent_latest
   */
  it('renderExpandContent: 확장 행 children 의 _computed 가 부모의 최신 계산값이다', async () => {
    G7Core.renderExpandContent({
      children: [{}],
      row: { id: 1 },
      componentContext: gridComponentContext({ columns: ['old'] }, { columns: ['KRW', 'USD'] }),
      componentMap: {},
      keyPrefix: 'expand-1',
    });

    const ctx = await effectiveCellContext();
    expect(ctx._computed).toEqual({ columns: ['KRW', 'USD'] });
  });
});
