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
  it('레이아웃 setState 가 저장소 B 에 도달한다', async () => {
    seed({ filter: 'all' });

    await run({ handler: 'setState', params: { target: '_local', filter: 'mine' } });

    expect(liveB().filter).toBe('mine');
  });
});

describe('[이중저장소 B→A] setState target:"_local"', () => {
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
  it('target 을 지정하지 않은 setState 도 저장소 B 에 도달한다', async () => {
    seed({ tab: 'a' });

    // target 미지정은 기본값 'component' 로 해석되어 COMPONENT 분기를 탄다
    await run({ handler: 'setState', params: { tab: 'b' } });

    expect(liveB().tab).toBe('b');
  });
});

describe('[이중저장소 B→A] setState 기본 분기', () => {
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
// 경로키: 커스텀 핸들러 context.setState
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] 커스텀 핸들러 context.setState', () => {
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
// 경로키: resultTo _local
// ─────────────────────────────────────────────────────────────────────────────

describe('[이중저장소 A→B] resultTo _local', () => {
  it('핸들러 결과를 _local 에 저장하면 저장소 B 에도 도달한다', async () => {
    seed({ probe: null });
    dispatcher.registerHandler('produce', () => 'RESULT');

    await run({ handler: 'produce', resultTo: { target: '_local', key: 'probe' } });

    expect(liveB().probe).toBe('RESULT');
  });
});

describe('[이중저장소 B→A] resultTo _local', () => {
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
