/**
 * dual-store-mirror-contract.test.tsx
 *
 * 이중 저장소(A = React `localDynamicState`, B = `globalState._local`)의 **양방향** 계약을
 * 경로별로 잠근다 (engine-v1.63.5).
 *
 * ## 왜 양방향이어야 하는가
 *
 * 엔진이 스스로 선언한 불변조건(DynamicRenderer `performStateUpdate` 상단 주석)은 두 문장이다:
 *
 *   - 자동바인딩 쓰기는 **A + B 양쪽에 동시에 쓴다**
 *   - **B 가 정본**이고 A 는 "쓰는 시점에 강제로 일치시키는 미러" 다
 *
 * 한 방향만 시험하면 반대 방향의 회귀가 초록으로 통과한다. 실제로 그렇게 두 번 새어 나갔다:
 *
 *   - 사례 41 (engine-v1.63.4) — B→A 축. 자동바인딩 키입력이 B 의 편집분을 서버 원본으로 되돌렸다
 *   - 사례 42 (engine-v1.63.5) — A→B 축. 커스텀 핸들러 쓰기가 B 에 닿지 않아 요청 body 가 비었다
 *
 * ## 규약
 *
 * 경로마다 `[이중저장소 A→B] <경로키>` 와 `[이중저장소 B→A] <경로키>` 를 **짝으로** 둔다.
 * 짝이 빠지면 정적 검사가 위반으로 잡는다 — 자기검증 `it` 방식은 그 `it` 을 지우면 무력해서
 * "규정만 있는 상태" 와 실질이 같아지기 때문이다.
 *
 * 저장소 B 는 **손으로 채우지 않는다.** 실제 writer(`G7Core.state.setLocal` ·
 * `setParentLocal` · `ActionDispatcher` · 자동바인딩)를 거친다. 손으로 채운 하네스는
 * 발산 상황을 위조할 뿐이라 "A 에만 쓰는 경로" 자체가 시험에 등장하지 않는다.
 *
 * @see 트러블슈팅 사례 41 · 42
 * @see docs/frontend/state-management.md "이중 저장소 구조"
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';

vi.mock('../ResponsiveManager', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../ResponsiveManager')>();
  return {
    ...actual,
    responsiveManager: {
      getWidth: vi.fn(() => 1440),
      subscribe: vi.fn(() => () => {}),
      getMatchingKey: vi.fn(() => null),
      parseRange: vi.fn(() => null),
    },
  };
});

/** 자동바인딩 대상 — name/value/onChange 를 그대로 DOM 에 넘긴다 */
const TestInput: React.FC<any> = ({ name, value, onChange }) => (
  <input data-testid={`input-${name}`} name={name} value={value ?? ''} onChange={onChange} />
);

let globalState: Record<string, any>;
let storeA: Record<string, any>;
let dispatcher: ActionDispatcher;
let registry: ComponentRegistry;
let bindingEngine: DataBindingEngine;
let translationEngine: TranslationEngine;
let translationContext: TranslationContext;
let originalG7Core: any;

/** 저장소 B 의 현재 `_local` */
const liveB = (): Record<string, any> => globalState._local;

/** 저장소 A writer — 함수형 업데이터도 받는다 */
const setStateA = (updates: any): void => {
  const next = typeof updates === 'function' ? updates(storeA) : updates;
  const { __mergeMode: _m, __setStateId: _s, ...clean } = next || {};
  storeA = { ...storeA, ...clean };
};

/**
 * `init_actions` 가 심은 초기 상태를 **실제 쓰기 경로**로 만든다.
 *
 * @param seed 심을 값
 * @return 없음
 */
const seed = (seedValue: Record<string, any>): void => {
  (window as any).G7Core.state.setLocal(seedValue, { render: false });
  storeA = { ...storeA, ...JSON.parse(JSON.stringify(seedValue)) };
  (window as any).__g7PendingLocalState = null;
  (window as any).__g7SequenceLocalSync = undefined;
  (window as any).__g7ForcedLocalFields = undefined;
  (window as any).__g7SetLocalOverrideKeys = undefined;
};

/**
 * `createHandler` 로 액션 하나를 실행한다.
 *
 * @param action 액션 정의
 * @return 없음
 */
const run = async (action: any): Promise<void> => {
  const handler = dispatcher.createHandler(
    action,
    { _local: liveB(), _global: globalState },
    { state: storeA, setState: setStateA }
  );
  await handler(new Event('click'));
  await Promise.resolve();
};

beforeEach(() => {
  globalState = { _local: {} };
  storeA = {};

  originalG7Core = (window as any).G7Core;
  delete (window as any).G7Core;

  registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
  };
  bindingEngine = new DataBindingEngine();
  translationEngine = new TranslationEngine();
  translationContext = { templateId: 'test-template', locale: 'ko' };

  (window as any).__templateApp = {
    getGlobalState: () => globalState,
    setGlobalState: (updates: any) => {
      globalState = { ...globalState, ...updates };
    },
  };
  (window as any).__g7PendingLocalState = null;
  (window as any).__g7ForcedLocalFields = undefined;
  (window as any).__g7SetLocalOverrideKeys = undefined;
  (window as any).__g7SequenceLocalSync = undefined;
  (window as any).__g7SetLocalReplacedKeys = undefined;
  (window as any).__g7LayoutContextStack = [];
  delete (window as any).__g7ActionContext;
  delete (window as any).__g7AutoBindingPaths;

  initializeG7CoreGlobals({
    getState: vi.fn(() => ({
      translationEngine,
      translationContext,
      bindingEngine,
      templateMetadata: { locales: ['ko'] },
    })) as any,
    transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
    responsiveManager: {},
    webSocketManager: {
      subscribe: vi.fn(() => 's'),
      unsubscribe: vi.fn(),
      leaveChannel: vi.fn(),
      disconnect: vi.fn(),
      isInitialized: vi.fn(() => true),
      getSubscriptionCount: vi.fn(() => 0),
    },
  } as any);

  dispatcher = new ActionDispatcher({ navigate: vi.fn() });
  dispatcher.setGlobalStateUpdater((updates: any) => {
    (window as any).__templateApp.setGlobalState(updates);
  });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  delete (window as any).__templateApp;
  delete (window as any).__g7PendingLocalState;
  delete (window as any).__g7ForcedLocalFields;
  delete (window as any).__g7SetLocalOverrideKeys;
  delete (window as any).__g7SequenceLocalSync;
  (window as any).__g7LayoutContextStack = [];
  delete (window as any).__g7ActionContext;
  if (originalG7Core === undefined) delete (window as any).G7Core;
  else (window as any).G7Core = originalG7Core;
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: setState target:"_local"
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] setState target:"_local"', () => {
  // @scenario path=setstate_local, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('레이아웃 setState 가 저장소 B 에 도달한다', async () => {
    seed({ filter: 'all' });

    await run({ handler: 'setState', params: { target: '_local', filter: 'mine' } });

    expect(liveB().filter).toBe('mine');
  });
});

describe('[이중저장소 B→A] setState target:"_local"', () => {
  // @scenario path=setstate_local, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('B 에만 있던 키를 저장소 A 스냅샷이 되돌리지 않는다', async () => {
    seed({ filter: 'all' });
    // 저장소 B 에만 존재하는 값 (selfManaged 플러그인이 쓴 편집기 본문을 모사)
    (window as any).G7Core.state.setLocal(
      { editorBody: '<p>편집분</p>' },
      { render: false, selfManaged: true }
    );
    (window as any).__g7PendingLocalState = null;

    await run({ handler: 'setState', params: { target: '_local', filter: 'mine' } });

    expect(liveB().editorBody, 'A 가 아직 모르는 키가 사라지면 안 된다').toBe('<p>편집분</p>');
    expect(liveB().filter).toBe('mine');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: setState target:"_local.dot"
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] setState target:"_local.dot"', () => {
  // @scenario path=setstate_local_dot, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('dot 경로 setState 가 저장소 B 의 중첩 leaf 에 도달한다', async () => {
    seed({ form: { general: { asset_url_mode: 'extension', site_name: 'G7' } } });

    await run({
      handler: 'setState',
      params: { target: '_local.form.general.asset_url_mode', value: 'extensionless' },
    });

    expect(liveB().form.general.asset_url_mode).toBe('extensionless');
    expect(liveB().form.general.site_name, '형제 키를 잃으면 안 된다').toBe('G7');
  });
});

describe('[이중저장소 B→A] setState target:"_local.dot"', () => {
  // @scenario path=setstate_local_dot, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('dot 경로 쓰기가 B 의 다른 최상위 키를 통째로 교체하지 않는다', async () => {
    seed({ form: { general: { asset_url_mode: 'extension' } } });
    (window as any).G7Core.state.setLocal(
      { editorBody: '<p>편집분</p>' },
      { render: false, selfManaged: true }
    );
    (window as any).__g7PendingLocalState = null;

    await run({
      handler: 'setState',
      params: { target: '_local.form.general.asset_url_mode', value: 'extensionless' },
    });

    expect(liveB().editorBody).toBe('<p>편집분</p>');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: setState 기본 분기 (target 미지정)
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] setState 기본 분기', () => {
  // @scenario path=setstate_default, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('target 을 지정하지 않은 setState 도 저장소 B 에 도달한다', async () => {
    seed({ tab: 'a' });

    // target 미지정은 기본값 'component' 로 해석되어 COMPONENT 분기를 탄다
    await run({ handler: 'setState', params: { tab: 'b' } });

    expect(liveB().tab).toBe('b');
  });
});

describe('[이중저장소 B→A] setState 기본 분기', () => {
  // @scenario path=setstate_default, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('B 전용 키가 기본 분기 쓰기로 사라지지 않는다', async () => {
    seed({ tab: 'a' });
    (window as any).G7Core.state.setLocal(
      { editorBody: '<p>편집분</p>' },
      { render: false, selfManaged: true }
    );
    (window as any).__g7PendingLocalState = null;

    await run({ handler: 'setState', params: { tab: 'b' } });

    expect(liveB().editorBody).toBe('<p>편집분</p>');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: setState 빈 객체 교체 (engine-v1.81.1, 7.1.1 — 선행 비우기는 기존 writer 를 그대로 거친다)
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] setState 빈 객체 교체', () => {
  // @scenario path=setstate_empty_object, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('`{}` 로 비운 값이 저장소 B 에도 빈 값으로 도달하고, 저장소 A writer 는 null 다음 {} 를 받는다', async () => {
    seed({ filters: { 색상: '블랙' }, keep: 1 });
    const writes: any[] = [];
    const recordingSetStateA = (updates: any): void => {
      writes.push(JSON.parse(JSON.stringify(typeof updates === 'function' ? updates(storeA) : updates)));
      setStateA(updates);
    };
    const handler = dispatcher.createHandler(
      { handler: 'setState', params: { target: '_local', filters: {} } } as any,
      { _local: liveB(), _global: globalState },
      { state: storeA, setState: recordingSetStateA }
    );
    await handler(new Event('click'));
    await Promise.resolve();

    expect(liveB().filters).toEqual({});
    expect(liveB().keep).toBe(1);
    // 저장소 A(실제 React 병합은 deep)가 앞 값을 지우려면 null 이 먼저 와야 한다
    expect(writes.map((w) => w.filters)).toEqual([null, {}]);
  });
});

describe('[이중저장소 B→A] setState 빈 객체 교체', () => {
  // @scenario path=setstate_empty_object, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('선행 비우기가 B 에만 있던 다른 키를 지우지 않는다', async () => {
    seed({ filters: { 색상: '블랙' } });
    (window as any).G7Core.state.setLocal(
      { editorBody: '<p>편집분</p>' },
      { render: false, selfManaged: true }
    );
    (window as any).__g7PendingLocalState = null;

    await run({ handler: 'setState', params: { target: '_local', filters: {} } });

    expect(liveB().editorBody, 'A 가 아직 모르는 키가 사라지면 안 된다').toBe('<p>편집분</p>');
    expect(liveB().filters).toEqual({});
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: 커스텀 핸들러 context.setState
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] 커스텀 핸들러 context.setState', () => {
  // @scenario path=custom_handler_setstate, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('핸들러가 저장소 A writer 로 쓴 값이 저장소 B 에 도달한다', async () => {
    seed({ selectedOptionItems: [] });
    dispatcher.registerHandler('addItem', (_a: any, ctx: any) => {
      ctx.setState({ selectedOptionItems: [{ id: '화이트' }], __mergeMode: 'shallow' });
    });

    await run({ handler: 'addItem' });

    expect(liveB().selectedOptionItems).toEqual([{ id: '화이트' }]);
  });
});

describe('[이중저장소 B→A] 커스텀 핸들러 context.setState', () => {
  // @scenario path=custom_handler_setstate, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('핸들러 쓰기가 B 전용 키를 지우지 않는다', async () => {
    seed({ selectedOptionItems: [] });
    (window as any).G7Core.state.setLocal(
      { editorBody: '<p>편집분</p>' },
      { render: false, selfManaged: true }
    );
    (window as any).__g7PendingLocalState = null;

    dispatcher.registerHandler('addItem', (_a: any, ctx: any) => {
      ctx.setState({ selectedOptionItems: [{ id: '화이트' }], __mergeMode: 'shallow' });
    });

    await run({ handler: 'addItem' });

    expect(liveB().editorBody).toBe('<p>편집분</p>');
    expect(liveB().selectedOptionItems).toEqual([{ id: '화이트' }]);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: 커스텀 핸들러 shallow 비우기 · 늦은 렌더 사본 (engine-v1.81.2, 7.1.1)
//
// 루트 렌더러의 dataContext._local 은 마지막 루트 다시 그리기 때의 B 사본이다(이 하네스에서는 마운트 때 넘긴 사본 — render:false 쓰기는
// 그것을 바꾸지 않는다). 그 사본에 앞 선택이 있는 상태에서 핸들러가 shallow `{}` 로 비우는 경로를 실물 루트 DynamicRenderer 로 잰다.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 실물 루트 DynamicRenderer(부모 문맥 없음) — 「비우기」·「+」 단추와 선택 표시. dataContext._local 은 지금 B 의 사본이다.
 *
 * @return 없음
 */
const mountShallowClearRoot = (): void => {
  (registry as any).registry = {
    ...(registry as any).registry,
    Div: { component: ({ children }: any) => <div>{children}</div>, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: ({ text, children, ...rest }: any) => <span data-testid={rest['data-testid']}>{children ?? text}</span>, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: ({ text, children, onClick }: any) => <button type="button" onClick={onClick}>{children ?? text}</button>, metadata: { name: 'Button', type: 'basic' } },
  };
  dispatcher.registerHandler('clearSelection', (_a: any, ctx: any) => {
    ctx.setState({ items: [{ id: '네이비_L', qty: 1 }], currentSelection: {}, __mergeMode: 'shallow' });
  });
  dispatcher.registerHandler('bump', (action: any, ctx: any) => {
    ctx.setState({ items: (action.params?.items ?? []).map((i: any) => ({ ...i, qty: i.qty + 1 })) });
  });
  render(
    <DynamicRenderer
      componentDef={{
        id: 'root', type: 'basic', name: 'Div',
        children: [
          { id: 'b1', type: 'basic', name: 'Button', text: 'clear', actions: [{ type: 'click', handler: 'clearSelection' }] },
          { id: 'b2', type: 'basic', name: 'Button', text: 'plus', actions: [{ type: 'click', handler: 'bump', params: { items: '{{_local.items ?? []}}' } }] },
          { id: 's1', type: 'basic', name: 'Span', props: { 'data-testid': 'cs' }, text: '{{JSON.stringify(_local.currentSelection ?? {})}}' },
        ],
      } as ComponentDefinition}
      dataContext={{ _local: { ...liveB() }, _global: globalState }}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={dispatcher}
    />
  );
};

describe('[이중저장소 A→B] 커스텀 핸들러 shallow 비우기', () => {
  // @scenario path=custom_handler_shallow_clear, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('핸들러의 shallow `{}` 가 저장소 B 에 빈 값으로 도달하고, 화면 문맥을 입력으로 쓰는 다음 줄 동작이 B 에 앞 선택을 되쓰지 않는다', async () => {
    seed({ items: [], currentSelection: { 색상: '네이비', 사이즈: 'L' } });
    mountShallowClearRoot();

    fireEvent.click(screen.getByText('clear'));
    await new Promise((r) => setTimeout(r, 20));
    expect(liveB().currentSelection).toEqual({});

    fireEvent.click(screen.getByText('plus'));
    await new Promise((r) => setTimeout(r, 20));
    expect(liveB().items).toEqual([{ id: '네이비_L', qty: 2 }]);
    expect(liveB().currentSelection, '늦은 사본의 앞 선택이 잎 키 보충으로 B 에 되써지면 안 된다').toEqual({});
  });
});

describe('[이중저장소 B→A] 커스텀 핸들러 shallow 비우기', () => {
  // @scenario path=custom_handler_shallow_clear, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('B 를 비운 쓰기가 화면(저장소 A 쪽 렌더 병합)에 도달한다 — 늦은 렌더 사본이 앞 선택을 다시 보이지 않는다', async () => {
    seed({ items: [], currentSelection: { 색상: '네이비', 사이즈: 'L' } });
    mountShallowClearRoot();
    expect(JSON.parse(screen.getByTestId('cs').textContent ?? '')).toEqual({ 색상: '네이비', 사이즈: 'L' });

    fireEvent.click(screen.getByText('clear'));
    await new Promise((r) => setTimeout(r, 20));

    expect(screen.getByTestId('cs').textContent).toBe('{}');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: 액션 밖 setLocal shallow 교체 (engine-v1.81.2, 7.1.1)
//
// 액션 밖(`__g7ActionContext` 없음)의 shallow setLocal 은 저장소 B 만 바꾼다. 페이지 루트(layoutKey 있음)는 교체한 최상위 키를 정본 B 값으로
// 그리고(표지 `__g7SetLocalReplacedKeys`), B 사본이 따라온 뒤 저장소 A 에서 그 키를 지운다. 실물 루트 DynamicRenderer 로 잰다.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 실물 페이지 루트 DynamicRenderer(부모 문맥 없음·layoutKey 있음) — 「고르기」·「다른 동작」 단추와 필터 표시.
 *
 * @return 다시 그리기(지금 B 의 사본으로 루트 다시 그리기)
 */
const mountOutsideReplaceRoot = (): (() => void) => {
  (registry as any).registry = {
    ...(registry as any).registry,
    Div: { component: ({ children }: any) => <div>{children}</div>, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: ({ text, children, ...rest }: any) => <span data-testid={rest['data-testid']}>{children ?? text}</span>, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: ({ text, children, onClick }: any) => <button type="button" onClick={onClick}>{children ?? text}</button>, metadata: { name: 'Button', type: 'basic' } },
  };
  dispatcher.registerHandler('deepOther', () => {
    (window as any).G7Core.state.setLocal({ page: 2 });
  });
  const tree = (): React.ReactElement => (
    <DynamicRenderer
      componentDef={{
        id: 'root', type: 'basic', name: 'Div',
        children: [
          { id: 'b1', type: 'basic', name: 'Button', text: 'pick', actions: [{ type: 'click', handler: 'setState', params: { target: 'local', filters: { status: 'paid' } } }] },
          { id: 'b2', type: 'basic', name: 'Button', text: 'other', actions: [{ type: 'click', handler: 'deepOther' }] },
          { id: 's1', type: 'basic', name: 'Span', props: { 'data-testid': 'filters' }, text: '{{JSON.stringify(_local.filters ?? null)}}' },
        ],
      } as ComponentDefinition}
      dataContext={{ _local: { ...liveB() }, _global: globalState }}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={dispatcher}
      layoutKey="contract"
    />
  );
  const { rerender } = render(tree());

  return () => rerender(tree());
};

describe('[이중저장소 A→B] 액션 밖 setLocal shallow 교체', () => {
  // @scenario path=setlocal_outside_action_replace, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('액션 밖 shallow `{}` 가 저장소 B 에 빈 값으로 도달하고, 화면 문맥을 입력으로 쓰는 다음 동작이 B 에 하위 키를 되쓰지 않는다', async () => {
    seed({ filters: {} });
    const redraw = mountOutsideReplaceRoot();
    fireEvent.click(screen.getByText('pick'));
    await new Promise((r) => setTimeout(r, 20));
    expect(liveB().filters).toEqual({ status: 'paid' });

    (window as any).G7Core.state.setLocal({ filters: {} }, { merge: 'shallow' });
    redraw();
    await new Promise((r) => setTimeout(r, 20));
    expect(liveB().filters).toEqual({});

    fireEvent.click(screen.getByText('other'));
    await new Promise((r) => setTimeout(r, 20));
    expect(liveB().page).toBe(2);
    expect(liveB().filters, '저장소 A 에 남은 하위 키가 잎 키 보충으로 B 에 되써지면 안 된다').toEqual({});
  });
});

describe('[이중저장소 B→A] 액션 밖 setLocal shallow 교체', () => {
  // @scenario path=setlocal_outside_action_replace, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('B 만 비운 액션 밖 쓰기가 화면(저장소 A 쪽 렌더 병합)에 도달한다 — 저장소 A 의 하위 키가 다시 보이지 않는다', async () => {
    seed({ filters: {} });
    const redraw = mountOutsideReplaceRoot();
    fireEvent.click(screen.getByText('pick'));
    await new Promise((r) => setTimeout(r, 20));
    expect(screen.getByTestId('filters').textContent).toBe('{"status":"paid"}');

    (window as any).G7Core.state.setLocal({ filters: {} }, { merge: 'shallow' });
    redraw();
    await new Promise((r) => setTimeout(r, 20));

    expect(screen.getByTestId('filters').textContent).toBe('{}');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: 모달의 `$parent._local`·`$root._local` setState·`setLocal({scope:'parent'})` (engine-v1.81.2, 7.1.1)
//
// 대상이 페이지(레이아웃 컨텍스트 스택의 맨 아래 — 첫 모달을 연 화면)일 때 그 쓰기는 페이지 `_local` 쓰기다. 종전에는 페이지의
// 저장소 A(스택 setState)에만 써서, 저장소 B 를 읽는 getLocal·핸들러·이탈 가드가 옛 값을 봤다. 쓴 키만 live B 에 얹는다 — 모달 자신의
// 상태는 B 에 들어가지 않는다(사례 29). 중첩 모달의 `$parent`(바로 아래 모달)는 페이지가 아니라 미러하지 않는다.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 페이지 → 모달 스택을 만든다(실물 handleOpenModal 이 쌓는 모양 — state 는 `_local` 내용물, setState 는 저장소 A writer).
 *
 * @param depth 모달 깊이(1 = 페이지가 연 모달, 2 = 그 모달이 연 모달)
 * @return 페이지 저장소 A 사본
 */
const stackModals = (depth: number): Record<string, any> => {
  const pageA: Record<string, any> = JSON.parse(JSON.stringify(liveB()));
  const entries: any[] = [{ state: pageA, setState: (u: any) => { const { __mergeMode: _m, ...rest } = u || {}; Object.assign(pageA, rest); } }];
  for (let i = 1; i < depth; i++) {
    const modalA: Record<string, any> = { modalOnly: `m${i}` };
    entries.push({ state: modalA, setState: (u: any) => { const { __mergeMode: _m, ...rest } = u || {}; Object.assign(modalA, rest); } });
  }
  (window as any).__g7LayoutContextStack = entries;

  return pageA;
};

describe('[이중저장소 A→B] 모달 $parent._local 쓰기', () => {
  // @scenario path=modal_parent_local, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('페이지가 연 모달의 `$parent._local` 쓰기(점 경로·병합)와 `setLocal({scope:\'parent\'})` 가 저장소 B 에 도달한다', async () => {
    seed({ form: { rules: ['a'], title: 'T' }, hasChanges: false });
    const pageA = stackModals(1);
    // 모달 버튼의 문맥 — 모달 자신의 상태(B 에 들어가면 안 된다)
    storeA = { modalDraft: 'draft' };

    await run({ handler: 'setState', params: { target: '$parent._local.form.rules', value: ['a', 'b'] } });
    await run({ handler: 'setState', params: { target: '$parent._local', hasChanges: true } });
    (window as any).G7Core.state.setLocal({ 'form.title': 'T2' }, { scope: 'parent' });

    expect(pageA.form.rules).toEqual(['a', 'b']);
    expect(liveB().form.rules).toEqual(['a', 'b']);
    expect(liveB().form.title).toBe('T2');
    expect(liveB().hasChanges).toBe(true);
    expect(liveB().modalDraft, '모달 자신의 상태는 페이지 B 에 들어가지 않는다(사례 29)').toBeUndefined();
    expect((window as any).G7Core.state.getLocal().form.rules).toEqual(['a', 'b']);
  });
});

describe('[이중저장소 B→A] 모달 $parent._local 쓰기', () => {
  // @scenario path=modal_parent_local, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('모달의 `$parent._local` 쓰기가 페이지 B 에만 있던 키를 지우지 않고, 중첩 모달의 `$parent`(모달)는 페이지 B 를 건드리지 않는다', async () => {
    seed({ form: { rules: ['a'] } });
    // B 에만 있는 값(selfManaged 편집기 본문)
    (window as any).G7Core.state.setLocal({ editorBody: '<p>본문</p>' }, { render: false, selfManaged: true });
    (window as any).__g7PendingLocalState = null;
    stackModals(1);

    await run({ handler: 'setState', params: { target: '$parent._local.form.rules', value: ['a', 'c'] } });
    expect(liveB().editorBody).toBe('<p>본문</p>');
    expect(liveB().form.rules).toEqual(['a', 'c']);

    // 중첩: 두 번째 모달의 `$parent` 는 첫 모달이다 — 페이지 B 는 그대로
    stackModals(2);
    await run({ handler: 'setState', params: { target: '$parent._local', modalOnly: 'changed' } });
    expect(liveB().modalOnly).toBeUndefined();
    expect(liveB().form.rules).toEqual(['a', 'c']);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: resultTo _local
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] resultTo _local', () => {
  // @scenario path=result_to_local, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('핸들러 결과를 _local 에 저장하면 저장소 B 에도 도달한다', async () => {
    seed({ probe: null });
    dispatcher.registerHandler('produce', () => 'RESULT');

    await run({ handler: 'produce', resultTo: { target: '_local', key: 'probe' } });

    expect(liveB().probe).toBe('RESULT');
  });
});

describe('[이중저장소 B→A] resultTo _local', () => {
  // @scenario path=result_to_local, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('resultTo 쓰기가 B 전용 키를 지우지 않는다', async () => {
    seed({ probe: null });
    (window as any).G7Core.state.setLocal(
      { editorBody: '<p>편집분</p>' },
      { render: false, selfManaged: true }
    );
    (window as any).__g7PendingLocalState = null;
    dispatcher.registerHandler('produce', () => 'RESULT');

    await run({ handler: 'produce', resultTo: { target: '_local', key: 'probe' } });

    expect(liveB().editorBody).toBe('<p>편집분</p>');
    expect(liveB().probe).toBe('RESULT');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: setLocal render:false selfManaged
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] setLocal render:false selfManaged', () => {
  // @scenario path=setlocal_self_managed, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('selfManaged 쓰기는 저장소 B 에 즉시 반영된다', () => {
    seed({ form: { content: '' } });

    (window as any).G7Core.state.setLocal(
      { form: { content: '<p>편집분</p>' } },
      { render: false, selfManaged: true }
    );

    expect(liveB().form.content).toBe('<p>편집분</p>');
  });
});

describe('[이중저장소 B→A] setLocal render:false selfManaged', () => {
  // @scenario path=setlocal_self_managed, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('selfManaged 로 B 에만 쓴 값이 자동바인딩 키입력에 되돌려지지 않는다 (사례 41)', () => {
    const BODY = '<p>ZZEDIT본문</p>';
    const SERVER = '<p>본문</p>';
    globalState._local = { form: { title: '제목', content: BODY } };
    (window as any).__g7ForcedLocalFields = { form: { content: BODY } };
    (window as any).__g7SetLocalOverrideKeys = { form: { content: BODY } };
    (window as any).__g7PendingLocalState = null;

    // 저장소 A 는 편집 이전 스냅샷으로 고정돼 있다 (memo 미재계산)
    const staleA = { form: { title: '제목', content: SERVER } };
    render(
      <DynamicRenderer
        componentDef={
          { id: 'title-input', type: 'basic', name: 'Input', props: { name: 'title' } } as ComponentDefinition
        }
        dataContext={{ _local: staleA }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={dispatcher}
        parentFormContextProp={
          { dataKey: 'form', state: staleA, setState: () => {}, trackChanges: true } as any
        }
      />
    );

    fireEvent.change(screen.getByTestId('input-title'), { target: { value: '제목X' } });

    expect(liveB().form.content, '편집분이 서버 원본으로 되돌아가면 안 된다').toBe(BODY);
    expect(liveB().form.title).toBe('제목X');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: 자동바인딩
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] 자동바인딩', () => {
  // @scenario path=autobinding, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('입력값이 저장소 B 에 도달한다', () => {
    globalState._local = { form: { title: '제목' } };
    (window as any).__g7PendingLocalState = null;

    const stateA = { form: { title: '제목' } };
    render(
      <DynamicRenderer
        componentDef={
          { id: 'title-input', type: 'basic', name: 'Input', props: { name: 'title' } } as ComponentDefinition
        }
        dataContext={{ _local: stateA }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={dispatcher}
        parentFormContextProp={
          { dataKey: 'form', state: stateA, setState: () => {}, trackChanges: true } as any
        }
      />
    );

    fireEvent.change(screen.getByTestId('input-title'), { target: { value: '새 제목' } });

    expect(liveB().form.title).toBe('새 제목');
  });
});

describe('[이중저장소 B→A] 자동바인딩', () => {
  // @scenario path=autobinding, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('자동바인딩 쓰기가 저장소 A writer 에도 같은 값을 전달한다', () => {
    globalState._local = { form: { title: '제목' } };
    (window as any).__g7PendingLocalState = null;

    const stateA = { form: { title: '제목' } };
    const aCalls: any[] = [];
    render(
      <DynamicRenderer
        componentDef={
          { id: 'title-input', type: 'basic', name: 'Input', props: { name: 'title' } } as ComponentDefinition
        }
        dataContext={{ _local: stateA }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={dispatcher}
        parentFormContextProp={
          { dataKey: 'form', state: stateA, setState: (u: any) => aCalls.push(u), trackChanges: true } as any
        }
      />
    );

    fireEvent.change(screen.getByTestId('input-title'), { target: { value: '새 제목' } });

    expect(aCalls.length, '저장소 A 도 함께 갱신돼야 한다').toBeGreaterThan(0);
    const last = aCalls[aCalls.length - 1];
    expect(last?.form?.title).toBe('새 제목');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: setParentLocal
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] setParentLocal', () => {
  // @scenario path=set_parent_local, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('부모 스코프 쓰기가 저장소 B 에 도달한다', () => {
    seed({ parentKey: 'before' });
    const parentA = { _local: { parentKey: 'before' } };
    (window as any).__g7LayoutContextStack = [
      { state: parentA, setState: (u: any) => Object.assign(parentA._local, u) },
    ];

    (window as any).G7Core.state.setParentLocal({ parentKey: 'after' });

    expect(liveB().parentKey).toBe('after');
  });
});

describe('[이중저장소 B→A] setParentLocal', () => {
  // @scenario path=set_parent_local, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('부모 스코프 쓰기가 B 전용 키를 통째 교체로 지우지 않는다 (공개 이슈 #130 계열)', () => {
    seed({ parentKey: 'before' });
    (window as any).G7Core.state.setLocal(
      { editorBody: '<p>편집분</p>' },
      { render: false, selfManaged: true }
    );
    (window as any).__g7PendingLocalState = null;

    // 부모의 저장소 A 는 editorBody 를 모른다
    const parentA = { _local: { parentKey: 'before' } };
    (window as any).__g7LayoutContextStack = [
      { state: parentA, setState: (u: any) => Object.assign(parentA._local, u) },
    ];

    (window as any).G7Core.state.setParentLocal({ parentKey: 'after' });

    expect(liveB().editorBody, 'A 스냅샷 통째 교체로 B 전용 키가 사라지면 안 된다').toBe(
      '<p>편집분</p>'
    );
    expect(liveB().parentKey).toBe('after');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 경로키: apiCall 로딩 플래그 해제 (engine-v1.81.2, 7.1.1)
//
// apiCall 의 로딩 플래그(`loadingActions[id]`)는 저장소 A 에만 쓰지만, 요청 중 렌더 문맥을 사본으로 쓰는 setState 의 A 전용 키 보충이
// 진행 중 플래그를 B 에 옮겨 적는다. 해제는 A(함수형 갱신)와 B(끝난 플래그 정리) 양쪽에서 일어나야 한다 — 종전에는 둘 다 남아 호출마다 쌓였다.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 실물 루트 DynamicRenderer(부모 문맥 없음) — 「요청」(apiCall, 응답 보류)·「고르기」(setState) 단추와 플래그 표시.
 *
 * @return 응답 함수(보류 중인 요청에 성공 응답)와 다시 그리기(지금 B 의 사본으로 루트 다시 그리기)
 */
const mountApiCallLoadingRoot = (): { respond: () => Promise<void>; rerenderRoot: () => void } => {
  (registry as any).registry = {
    ...(registry as any).registry,
    Div: { component: ({ children }: any) => <div>{children}</div>, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: ({ text, children, ...rest }: any) => <span data-testid={rest['data-testid']}>{children ?? text}</span>, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: ({ text, children, onClick }: any) => <button type="button" onClick={onClick}>{children ?? text}</button>, metadata: { name: 'Button', type: 'basic' } },
  };
  const waiting: Array<(r: any) => void> = [];
  vi.spyOn(globalThis, 'fetch').mockImplementation(((url: any) => {
    if (!String(url).includes('/api/contract-load')) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({}) } as any);
    }

    return new Promise((resolve) => { waiting.push(resolve); });
  }) as any);
  const tree = (): React.ReactElement => (
    <DynamicRenderer
      componentDef={{
        id: 'root', type: 'basic', name: 'Div',
        children: [
          { id: 'b1', type: 'basic', name: 'Button', text: 'load', actions: [{ type: 'click', handler: 'apiCall', target: '/api/contract-load', params: { method: 'GET' } }] },
          { id: 'b2', type: 'basic', name: 'Button', text: 'pick', actions: [{ type: 'click', handler: 'setState', params: { target: 'local', picked: 'yes' } }] },
          { id: 's1', type: 'basic', name: 'Span', props: { 'data-testid': 'la' }, text: '{{JSON.stringify(_local.loadingActions ?? {})}}' },
        ],
      } as ComponentDefinition}
      dataContext={{ _local: { ...liveB() }, _global: globalState }}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={dispatcher}
    />
  );
  const { rerender } = render(tree());

  return {
    respond: async () => {
      waiting.shift()?.({ ok: true, status: 200, json: async () => ({ data: {} }) });
      await new Promise((r) => setTimeout(r, 40));
    },
    rerenderRoot: () => rerender(tree()),
  };
};

/** 엔진이 만든 apiCall 로딩 id 키 */
const engineLoadingKeys = (flags: Record<string, any> | undefined): string[] => Object.keys(flags ?? {}).filter((k) => k.startsWith('apiCall_'));

describe('[이중저장소 A→B] apiCall 로딩 플래그 해제', () => {
  // @scenario path=apicall_loading_flags, direction=a_to_b
  // @effects dual_store_mirror_holds
  it('요청 중 setState 가 B 에 옮겨 적은 진행 중 플래그가 요청이 끝나면 B 에서도 빠지고, B 의 다른 키는 남는다', async () => {
    seed({ form: { title: 'A' } });
    const root = mountApiCallLoadingRoot();

    fireEvent.click(screen.getByText('load'));
    await new Promise((r) => setTimeout(r, 20));
    fireEvent.click(screen.getByText('pick'));
    await new Promise((r) => setTimeout(r, 20));
    expect(engineLoadingKeys(liveB().loadingActions), '진행 중 플래그는 보충으로 B 에 옮겨진다').toHaveLength(1);

    await root.respond();

    expect(engineLoadingKeys(liveB().loadingActions), '끝난 플래그가 B 에 남으면 안 된다').toEqual([]);
    expect(liveB().picked).toBe('yes');
    expect(liveB().form).toEqual({ title: 'A' });
  });
});

describe('[이중저장소 B→A] apiCall 로딩 플래그 해제', () => {
  // @scenario path=apicall_loading_flags, direction=b_to_a
  // @effects dual_store_mirror_holds
  it('B 에서 뺀 끝난 플래그가 화면(저장소 A 쪽 렌더 병합)에도 다시 보이지 않는다 — 플래그를 실은 늦은 B 사본으로 루트를 다시 그린 뒤에도', async () => {
    seed({ form: { title: 'A' } });
    const root = mountApiCallLoadingRoot();

    fireEvent.click(screen.getByText('load'));
    await new Promise((r) => setTimeout(r, 20));
    fireEvent.click(screen.getByText('pick'));
    await new Promise((r) => setTimeout(r, 20));
    // 요청 중 루트 다시 그리기 — 렌더 사본(dataContext._local)에 진행 중 플래그가 실린다
    root.rerenderRoot();
    await new Promise((r) => setTimeout(r, 20));
    expect(engineLoadingKeys(JSON.parse(screen.getByTestId('la').textContent ?? '{}'))).toHaveLength(1);

    await root.respond();

    expect(screen.getByTestId('la').textContent).toBe('{}');
  });
});
