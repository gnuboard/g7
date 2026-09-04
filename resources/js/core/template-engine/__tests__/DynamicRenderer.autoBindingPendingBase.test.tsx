/**
 * DynamicRenderer.autoBindingPendingBase.test.tsx
 *
 * 사례 41(engine-v1.63.4) — 자동바인딩 키입력이 저장소 B 의 편집기 본문을 되돌리는 결함.
 *
 * 브라우저 실측(2026-09-01, 관리자 게시글 **수정** 화면)으로 확정된 조건:
 *
 *   ① CKEditor 가 `setLocal({ render:false, selfManaged:true })` 로 저장소 B 에만 편집분을 기록
 *      → React 렌더 0회 → `extendedDataContext` useMemo 가 재계산되지 않아
 *        `parentFormContext.state`(저장소 A)는 **편집 이전 스냅샷**으로 고정된다
 *   ② memo deps 와 무관한 리렌더(폭 변경)가 `__g7PendingLocalState` 를 null 로 지운다
 *      — 이때 `__g7ForcedLocalFields` 는 **살아남는다**(사례 40 위험 핀이 잠근 사실)
 *   ③ 그 상태에서 제목 등 자동바인딩 입력에 한 글자 → `performStateUpdate` 가
 *      `__g7PendingLocalState = setNestedValue(<stale A>, path, v)` 를 대입
 *   ④ 이어지는 `setLocal(patch, {render:false})` 가
 *      `currentSnapshot = pendingState || baseLocal` 로 그 stale 스냅샷을 채택
 *      → 저장소 B 가 통째 교체되어 **편집분이 사라진다**
 *
 * 실측값(수정 화면, 폭 1051→1032):
 *   setLocal 진입 시  pending=`<p>F6MARKER-BODY-FIRST</p>`(서버 원본)
 *                    forced =`<p>ZZEDITF6MARKER-BODY-FIRST</p>`(편집분 보유)
 *                    liveB  =`<p>ZZEDITF6MARKER-BODY-FIRST</p>`
 *   호출 직후        liveB  =`<p>F6MARKER-BODY-FIRST</p>`  ← ZZEDIT 소실
 *   저장 결과 DB      content 가 서버 원본 그대로, title 만 반영 (조용한 데이터 손실)
 *
 * 화면에는 편집분이 그대로 보이고 콘솔 에러도 없다. 저장은 **성공**하므로
 * 사용자에게는 아무 신호가 없다.
 *
 * 이 파일은 ③④를 **실물 렌더 + 실물 `G7Core.state.setLocal`** 로 잠근다.
 * 저장소 A 가 stale 인 상태는 `parentFormContextProp.state` 로 그대로 재현한다
 * (production 에서 그 값이 곧 재계산되지 않은 useMemo 결과다).
 *
 * @see 트러블슈팅 사례 41
 * @see DynamicRenderer.resizePendingClear.test.tsx (②를 잠그는 사례 40 위험 핀)
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

const BODY = '<p>ZZEDIT서버원본</p>';
const SERVER_ORIGINAL = '<p>서버원본</p>';

describe('[사례 41] 자동바인딩 키입력이 저장소 B 의 편집분을 되돌리지 않는다 (engine-v1.63.4)', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;
  let globalState: Record<string, any>;
  let originalG7Core: any;

  /** 저장소 B (TemplateApp 계약대로 최상위 키 얕은 병합) */
  const makeTemplateApp = () => ({
    getGlobalState: () => globalState,
    setGlobalState: (updates: any) => {
      globalState = { ...globalState, ...updates };
    },
  });

  const liveB = () => globalState._local;

  /**
   * `setLocal` **진입 시점**의 `__g7PendingLocalState` 를 포착합니다.
   *
   * 호출이 끝나면 `debouncedStateUpdate` 의 `onComplete` 가 pending 을 null 로 지우므로
   * (DynamicRenderer.tsx:3648·3657) 사후 관측으로는 판정할 수 없다. 브라우저 실측에서도
   * 같은 이유로 `setLocal` 을 감싸 진입 시점 값을 잡았다.
   *
   * @return 호출별 진입 시점 스냅샷 배열
   */
  const captureSetLocalEntry = (): Array<Record<string, any> | null> => {
    const seen: Array<Record<string, any> | null> = [];
    const G7Core = (window as any).G7Core;
    const orig = G7Core.state.setLocal;
    G7Core.state.setLocal = function (...args: any[]) {
      const p = (window as any).__g7PendingLocalState;
      seen.push(p == null ? null : JSON.parse(JSON.stringify(p)));
      return orig.apply(this, args);
    };
    return seen;
  };

  const componentDef: ComponentDefinition = {
    id: 'title-input',
    type: 'basic',
    name: 'Input',
    props: { name: 'title' },
  };

  /**
   * 저장소 A 가 stale 인 상태로 자동바인딩 Input 을 렌더합니다.
   *
   * @param staleA 재계산되지 않은 `extendedDataContext._local` 을 모사한 값
   * @return `parentFormContext.setState` 가 받은 값을 담는 배열
   */
  const renderInput = (staleA: Record<string, any>) => {
    const setStateCalls: any[] = [];
    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={{ _local: staleA }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
        parentFormContextProp={{
          dataKey: 'form',
          state: staleA,
          setState: (u: any) => setStateCalls.push(u),
          trackChanges: true,
        } as any}
      />
    );
    return setStateCalls;
  };

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    };

    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };

    originalG7Core = (window as any).G7Core;
    delete (window as any).G7Core;

    globalState = { _local: {} };
    (window as any).__templateApp = makeTemplateApp();
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
    (window as any).__g7LayoutContextStack = [];
    delete (window as any).__g7ActionContext;

    initializeG7CoreGlobals({
      getState: vi.fn(() => ({
        translationEngine,
        translationContext,
        bindingEngine,
        actionDispatcher,
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
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    delete (window as any).__templateApp;
    delete (window as any).__g7PendingLocalState;
    delete (window as any).__g7ForcedLocalFields;
    delete (window as any).__g7SetLocalOverrideKeys;
    (window as any).__g7LayoutContextStack = [];
    if (originalG7Core === undefined) delete (window as any).G7Core;
    else (window as any).G7Core = originalG7Core;
  });

  it('결함 재현 핀: 편집분이 저장소 B 에 남는다', () => {
    // @scenario save_flow=edit, resize_kind=same_breakpoint
    // @effects autobinding_keystroke_preserves_editor_content
    // 저장소 B 는 편집분을 들고 있다 (CKEditor 가 render:false 로 기록)
    globalState._local = { form: { title: '제목', content: BODY, content_mode: 'html' } };
    (window as any).__g7ForcedLocalFields = { form: { content: BODY, content_mode: 'html' } };
    (window as any).__g7SetLocalOverrideKeys = { form: { content: BODY, content_mode: 'html' } };
    // 폭 변경 리렌더가 pending 을 지운 뒤다
    (window as any).__g7PendingLocalState = null;

    // 저장소 A 는 편집 이전 스냅샷으로 고정돼 있다 (memo 미재계산)
    renderInput({ form: { title: '제목', content: SERVER_ORIGINAL, content_mode: 'html' } });

    fireEvent.change(screen.getByTestId('input-title'), { target: { value: '제목X' } });

    expect(liveB().form.content, '자동바인딩 키입력이 편집분을 되돌리면 안 된다').toBe(BODY);
    expect(liveB().form.title, '입력한 제목은 반영돼야 한다').toBe('제목X');
  });

  it('pending 스냅샷 자체가 편집분을 담는다 (후속 setLocal 의 base 가 된다)', () => {
    // @scenario save_flow=edit, resize_kind=crossed_breakpoint
    // @effects pending_snapshot_carries_forced_overlay
    globalState._local = { form: { title: '제목', content: BODY } };
    (window as any).__g7ForcedLocalFields = { form: { content: BODY } };
    (window as any).__g7SetLocalOverrideKeys = { form: { content: BODY } };
    (window as any).__g7PendingLocalState = null;

    renderInput({ form: { title: '제목', content: SERVER_ORIGINAL } });
    const entries = captureSetLocalEntry();
    fireEvent.change(screen.getByTestId('input-title'), { target: { value: 'Q' } });

    expect(entries.length, '자동바인딩이 setLocal 로 B 에 써야 한다').toBe(1);
    expect(entries[0]?.form?.content, 'pending 이 stale A 를 그대로 실으면 안 된다').toBe(BODY);
    expect(entries[0]?.form?.title, '방금 입력한 값도 실려야 한다').toBe('Q');
  });

  it('방금 입력한 경로는 forced 의 직전 값에 되돌려지지 않는다', () => {
    // @scenario save_flow=create, resize_kind=same_breakpoint
    // @effects typed_path_wins_over_forced_previous_value
    // setLocal 이 과거에 title 을 쓴 적이 있어 forced 에 옛 값이 남은 상태
    globalState._local = { form: { title: '옛제목', content: BODY } };
    (window as any).__g7ForcedLocalFields = { form: { title: '옛제목', content: BODY } };
    (window as any).__g7SetLocalOverrideKeys = { form: { title: '옛제목', content: BODY } };
    (window as any).__g7PendingLocalState = null;

    renderInput({ form: { title: '옛제목', content: SERVER_ORIGINAL } });
    fireEvent.change(screen.getByTestId('input-title'), { target: { value: '새제목' } });

    expect(liveB().form.title, '방금 입력한 값이 이겨야 한다').toBe('새제목');
    expect(liveB().form.content, '편집분은 그대로 보존').toBe(BODY);
  });

  it('저장소 A 경로는 종전대로 A 기반이다 (2026-04-22 철회 이력 보호)', () => {
    globalState._local = { form: { title: '제목', content: BODY } };
    (window as any).__g7ForcedLocalFields = { form: { content: BODY } };
    (window as any).__g7SetLocalOverrideKeys = { form: { content: BODY } };
    (window as any).__g7PendingLocalState = null;

    const staleA = { form: { title: '제목', content: SERVER_ORIGINAL }, expandedRows: [301] };
    const setStateCalls = renderInput(staleA);
    fireEvent.change(screen.getByTestId('input-title'), { target: { value: 'Z' } });

    // parentFormContext.setState 는 A 기반 update 를 받는다 —
    // 여기 base 를 B 로 바꾸는 것이 2026-04-22 에 철회된 수정(로그인 폼 email 손실)이다.
    expect(setStateCalls.length).toBe(1);
    expect(setStateCalls[0].form.title).toBe('Z');
    expect(setStateCalls[0].form.content, 'A 경로는 A 기반 그대로').toBe(SERVER_ORIGINAL);
    expect(setStateCalls[0].expandedRows, 'React 전용 키가 유지돼야 한다').toEqual([301]);
  });

  it('forced 가 없으면 종전 동작 (A 기반 스냅샷)', () => {
    globalState._local = { form: { title: '제목', content: SERVER_ORIGINAL } };
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7PendingLocalState = null;

    renderInput({ form: { title: '제목', content: SERVER_ORIGINAL } });
    const entries = captureSetLocalEntry();
    fireEvent.change(screen.getByTestId('input-title'), { target: { value: 'W' } });

    expect(entries[0]?.form?.title).toBe('W');
    expect(entries[0]?.form?.content).toBe(SERVER_ORIGINAL);
  });

  it('다국어 중첩 경로에서도 편집분이 보존된다', () => {
    // @scenario save_flow=create, resize_kind=crossed_breakpoint
    // @effects autobinding_keystroke_preserves_editor_content
    const koBody = '<p>다국어편집분</p>';
    globalState._local = { form: { title: '제목', content: { ko: koBody } } };
    (window as any).__g7ForcedLocalFields = { form: { content: { ko: koBody } } };
    (window as any).__g7SetLocalOverrideKeys = { form: { content: { ko: koBody } } };
    (window as any).__g7PendingLocalState = null;

    renderInput({ form: { title: '제목', content: { ko: '<p>원본</p>' } } });
    fireEvent.change(screen.getByTestId('input-title'), { target: { value: 'M' } });

    expect(liveB().form.content.ko, '중첩 경로 편집분 보존').toBe(koBody);
    expect(liveB().form.title).toBe('M');
  });
});
