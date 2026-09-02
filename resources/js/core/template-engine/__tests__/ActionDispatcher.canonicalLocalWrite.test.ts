/**
 * ActionDispatcher.canonicalLocalWrite.test.ts
 *
 * 커스텀 핸들러가 `context.setState`(저장소 A) 로만 쓰던 경로를 저장소 B(`_global._local`)에도
 * 함께 쓰도록 만든 미러 계약을 잠근다 (engine-v1.63.5).
 *
 * ## 무엇이 깨져 있었나 (브라우저 실측 2026-09-02, `https://g7_2.dev/shop/products/{code}`)
 *
 *   1) `show.json` 의 `init_actions` 가 B 에 `selectedOptionItems: []` 를 심는다
 *   2) 옵션을 고르면 템플릿 커스텀 핸들러가 `context.setState({ selectedOptionItems: [item] })`
 *      로 **저장소 A 에만** 쓴다 — 화면에는 담긴 항목이 그대로 보인다
 *   3) 이어지는 `apiCall` 의 body 는 sequence 의 `currentState`(= `_local`)를 읽는데,
 *      engine-v1.63.3 이 그 값을 live B 기준으로 바꾼 뒤로 `addMissingLeafKeys` 는
 *      **B 에 키가 없을 때만** 보충하므로 `[]` 가 그대로 남는다
 *   4) `POST …/checkout` body 가 `{"direct_items":[]}` 로 나가 422
 *
 * 예외도 콘솔 에러도 없다. 화면과 요청이 서로 다른 값을 보는 것이 유일한 증상이다.
 *
 * ## 이 파일이 잠그는 것
 *
 * 게이트 1:1 대응 — 미러가 붙는 조건과 **붙지 않아야 하는 조건**을 모두 고정한다.
 * 미러를 무조건 붙이면 사례 17(replace 확대 손실) · 사례 29(모달 → 페이지 오염) ·
 * File 손상이 재발하므로, 제외 게이트도 결함 재현 핀과 같은 급으로 잠근다.
 *
 * 저장소 B 는 **손으로 채우지 않는다** — 실제 `G7Core.state.setLocal` 을 거쳐 심는다.
 * 손으로 채우면 "A 에만 쓰는 경로" 자체가 시험에 등장하지 않아 결함을 통과시킨다
 * (그것이 engine-v1.63.3 이 이 결함을 놓친 이유다).
 *
 * @see 트러블슈팅 사례 42
 * @see docs/frontend/state-management.md "이중 저장소 구조"
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine } from '../TranslationEngine';

describe('[case:state-setstate-42] 커스텀 핸들러 setState 미러 — 저장소 A 전용 쓰기 (engine-v1.63.5)', () => {
  let dispatcher: ActionDispatcher;
  let globalState: Record<string, any>;
  /** 저장소 A — React localDynamicState 를 모사한다 */
  let storeA: Record<string, any>;
  /** 저장소 A writer 호출 횟수 (중복 쓰기 판정용) */
  let aWrites: number;
  /** `globalStateUpdater` 가 받은 payload 이력 */
  let updaterCalls: Array<{ updates: any; options?: any }>;
  let originalG7Core: any;

  /** 저장소 A writer — 함수형 업데이터도 받는다(엔진 `handleLocalSetState` 계약) */
  const setStateA = (updates: any): void => {
    aWrites++;
    const next = typeof updates === 'function' ? updates(storeA) : updates;
    const { __mergeMode: _m, __setStateId: _s, ...clean } = next || {};
    storeA = { ...storeA, ...clean };
  };

  /** 저장소 B 의 현재 `_local` */
  const liveB = (): Record<string, any> => globalState._local;

  /**
   * `createHandler` 로 액션 하나를 실행한다.
   *
   * @param action 액션 정의
   * @param dataContext 데이터 컨텍스트 (미지정 시 live B 를 `_local` 로 싣는다)
   * @return 없음
   */
  const run = async (action: any, dataContext?: any): Promise<void> => {
    const handler = dispatcher.createHandler(
      action,
      dataContext ?? { _local: liveB(), _global: globalState },
      { state: storeA, setState: setStateA }
    );
    await handler(new Event('click'));
    // createHandler 내부의 await 사슬이 풀리도록 한 tick 양보한다
    await Promise.resolve();
  };

  beforeEach(() => {
    globalState = { _local: {} };
    storeA = {};
    aWrites = 0;
    updaterCalls = [];

    originalG7Core = (window as any).G7Core;
    delete (window as any).G7Core;

    (window as any).__templateApp = {
      getGlobalState: () => globalState,
      // TemplateApp 계약: 최상위 키 **얕은** 병합
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
        translationEngine: new TranslationEngine(),
        translationContext: { templateId: 't', locale: 'ko' },
        bindingEngine: new DataBindingEngine(),
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
    dispatcher.setGlobalStateUpdater((updates: any, options?: any) => {
      updaterCalls.push({ updates, options });
      (window as any).__templateApp.setGlobalState(updates);
    });
  });

  afterEach(() => {
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

  /**
   * `init_actions` 가 B 에 기본값을 심은 상태를 **실제 쓰기 경로**로 만든다.
   *
   * @param seed 심을 값
   * @return 없음
   */
  const seedB = (seed: Record<string, any>): void => {
    (window as any).G7Core.state.setLocal(seed, { render: false });
    // 렌더 컨텍스트(저장소 A)도 같은 기본값을 갖는 상태가 실제 화면이다 —
    // 그래야 결함이 "undefined" 가 아니라 브라우저 실측과 같은 "[] 가 그대로 나감" 으로 드러난다.
    storeA = { ...storeA, ...JSON.parse(JSON.stringify(seed)) };
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7SequenceLocalSync = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
  };

  const ITEM = { id: '화이트', optionId: 11, quantity: 1, unitPrice: 19000 };

  it('T1 — 핸들러 쓰기 후 후속 액션이 보는 _local 에 실제 값이 담긴다 (주 회귀 핀)', async () => {
    seedB({ selectedOptionItems: [], currentSelection: {} });

    let seenByNext: any;
    dispatcher.registerHandler('addItem', (_a: any, ctx: any) => {
      ctx.setState({
        selectedOptionItems: [ITEM],
        currentSelection: {},
        __mergeMode: 'shallow',
      });
    });
    dispatcher.registerHandler('recordLocal', (_a: any, ctx: any) => {
      seenByNext = ctx.data?._local;
    });

    await run({
      handler: 'sequence',
      params: { actions: [{ handler: 'addItem' }, { handler: 'recordLocal' }] },
    });

    expect(
      seenByNext?.selectedOptionItems,
      '후속 액션(= 요청 body 가 읽는 자리)이 빈 배열을 보면 안 된다'
    ).toEqual([ITEM]);
  });

  it('T2 — globalStateUpdater 가 받은 마지막 _local 에 값이 도달한다', async () => {
    seedB({ selectedOptionItems: [] });
    dispatcher.registerHandler('addItem', (_a: any, ctx: any) => {
      ctx.setState({ selectedOptionItems: [ITEM], __mergeMode: 'shallow' });
    });

    await run({ handler: 'addItem' });

    expect(liveB().selectedOptionItems).toEqual([ITEM]);
  });

  it('T3 — __g7SequenceLocalSync 가 갱신되어 sequence 전파 장치가 살아난다', async () => {
    seedB({ selectedOptionItems: [] });
    dispatcher.registerHandler('addItem', (_a: any, ctx: any) => {
      ctx.setState({ selectedOptionItems: [ITEM], __mergeMode: 'shallow' });
    });

    await run({ handler: 'addItem' });

    expect((window as any).__g7SequenceLocalSync?.selectedOptionItems).toEqual([ITEM]);
  });

  it('T4 — __g7ActionContext.setState 와 참조가 같으면 저장소 A 를 한 번만 쓴다', async () => {
    seedB({ n: 0 });
    dispatcher.registerHandler('bump', (_a: any, ctx: any) => {
      aWrites = 0;
      ctx.setState({ n: 1 });
    });

    await run({ handler: 'bump' });

    expect(aWrites, '중복 A 쓰기가 있으면 안 된다').toBe(1);
    expect(storeA.n).toBe(1);
    expect(liveB().n).toBe(1);
  });

  it('T5 — __g7ActionContext 가 없으면 원본 writer 와 setLocal 이 각각 한 번씩 동작한다', () => {
    seedB({ n: 0 });
    const ctx: any = { state: storeA, setState: setStateA, data: { _local: liveB() } };
    aWrites = 0;

    (dispatcher as any).writeLocalState(ctx, { n: 7 });

    expect(aWrites).toBe(1);
    expect(liveB().n).toBe(7);
  });

  it('T6 — 미러 writer 로 재진입해도 무한 재귀가 없다', () => {
    seedB({ n: 0 });
    const ctx: any = { state: storeA, setState: setStateA };
    const proxy = (dispatcher as any).makeLocalSetStateProxy(ctx);
    const ctx2: any = { state: storeA, setState: proxy };

    expect(() => (dispatcher as any).writeLocalState(ctx2, { n: 1 })).not.toThrow();
    expect(storeA.n).toBe(1);
  });

  it('T7 — 모달 컨텍스트 스택이 있으면 미러하지 않는다 (사례 29)', () => {
    seedB({ pageKey: 'page' });
    (window as any).__g7LayoutContextStack = [{ state: {}, setState: () => {} }];

    const ctx: any = { state: storeA, setState: setStateA };
    (dispatcher as any).writeLocalState(ctx, { pageKey: 'modal' });

    expect(liveB().pageKey, '모달의 쓰기가 페이지 저장소를 덮으면 안 된다').toBe('page');
    expect(storeA.pageKey).toBe('modal');
  });

  it('T8 — merge:"replace" 는 미러하지 않는다 (사례 17)', () => {
    seedB({ a: 1, b: 2 });
    const ctx: any = { state: storeA, setState: setStateA };

    (dispatcher as any).writeLocalState(ctx, { a: 9, __mergeMode: 'replace' });

    expect(liveB(), '부분 replace 가 B 전체 손실로 확대되면 안 된다').toEqual({ a: 1, b: 2 });
    expect(storeA.a).toBe(9);
  });

  it('T9 — dot-notation 키는 양쪽 모두 중첩으로 기록되고 리터럴 키가 남지 않는다', () => {
    seedB({ form: { general: { asset_url_mode: 'extension' } } });
    const ctx: any = { state: storeA, setState: setStateA };

    (dispatcher as any).writeLocalState(ctx, { 'form.general.asset_url_mode': 'extensionless' });

    expect(liveB().form.general.asset_url_mode).toBe('extensionless');
    expect(Object.keys(liveB())).not.toContain('form.general.asset_url_mode');
  });

  it('T10 — 배열을 비우는 쓰기가 양쪽 모두 빈 배열이 된다', () => {
    seedB({ selectedOptionItems: [ITEM] });
    const ctx: any = { state: storeA, setState: setStateA };

    (dispatcher as any).writeLocalState(ctx, { selectedOptionItems: [], __mergeMode: 'shallow' });

    expect(liveB().selectedOptionItems).toEqual([]);
  });

  it('T11 — __mergeMode 명시 시 forcedFields 가 얕게 재보정된다 (사례 19 2차)', () => {
    seedB({ currentSelection: { 색상: '화이트' } });
    (window as any).__g7ForcedLocalFields = { currentSelection: { 색상: '화이트' } };
    const ctx: any = { state: storeA, setState: setStateA };

    (dispatcher as any).writeLocalState(ctx, { currentSelection: {}, __mergeMode: 'shallow' });

    expect(
      (window as any).__g7ForcedLocalFields.currentSelection,
      '깊은 병합이면 리셋이 무효화된다'
    ).toEqual({});
  });

  it('T12 — payload 에 errors 키가 있으면 미러하지 않는다', () => {
    seedB({ errors: { title: ['필수'] } });
    const ctx: any = { state: storeA, setState: setStateA };

    (dispatcher as any).writeLocalState(ctx, { errors: {} });

    expect(liveB().errors, 'errors 는 병합 의미가 달라 미러 대상이 아니다').toEqual({ title: ['필수'] });
    expect(storeA.errors).toEqual({});
  });

  it('T13 — payload 에 File 등 non-plain 객체가 있으면 미러하지 않는다', () => {
    seedB({ upload: null });
    const ctx: any = { state: storeA, setState: setStateA };
    const file = new File(['x'], 'a.txt', { type: 'text/plain' });

    (dispatcher as any).writeLocalState(ctx, { upload: file });

    expect(liveB().upload, '전개로 손상되는 값은 B 에 싣지 않는다').toBeNull();
    expect(storeA.upload).toBe(file);
  });

  it('T14 — 함수형 업데이터는 원본 writer 로 폴백한다', () => {
    seedB({ n: 1 });
    const ctx: any = { state: storeA, setState: setStateA };

    (dispatcher as any).writeLocalState(ctx, (prev: any) => ({ n: (prev.n ?? 0) + 1 }));

    expect(storeA.n).toBe(2);
    expect(liveB().n, '함수형은 payload 를 꺼낼 수 없어 미러하지 않는다').toBe(1);
  });

  it('T15 — 커스텀 핸들러는 미러 프록시를 받고, 그 프록시가 부모 스코프 스택으로 새지 않는다', async () => {
    seedB({ n: 0 });
    let sawProxy = false;
    dispatcher.registerHandler('inspect', (_a: any, ctx: any) => {
      sawProxy = ctx.setState?.__g7CanonicalWriter === true;
    });

    await run({ handler: 'inspect' });
    expect(sawProxy, '커스텀 핸들러는 A/B 를 함께 쓰는 writer 를 받아야 한다').toBe(true);

    const stack = (window as any).__g7LayoutContextStack || [];
    for (const entry of stack) {
      expect(
        (entry?.setState as any)?.__g7CanonicalWriter,
        '부모 스코프 경로가 미러 프록시를 잡으면 안 된다'
      ).toBeUndefined();
    }
  });

  it('T16 — scope:"parent" 로 넘긴 쓰기는 미러하지 않는다', () => {
    seedB({ pageKey: 'page' });
    const ctx: any = { state: storeA, setState: setStateA };

    (dispatcher as any).writeLocalState(ctx, { pageKey: 'x' }, undefined, { scope: 'parent' });

    expect(liveB().pageKey).toBe('page');
    expect(storeA.pageKey).toBe('x');
  });
});
