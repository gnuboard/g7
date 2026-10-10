/**
 * DynamicRenderer.localInitPruneHasChanges.test.tsx
 *
 * troubleshooting-state-global.md initLocal 사례 8 (사례 6 engine-v1.54.7 의 미적용 구간) — 탭 왕복 뒤 입력칸은 서버값으로 돌아왔는데
 * 「저장하지 않은 변경」(`_local.hasChanges`)만 true 로 남는 결함.
 *
 * 브라우저 실측(2026-10-05, 관리자 환경설정 › 보안 탭, #736 전수 실측 O-1):
 *   만료 기간 60 → 45 입력 → 개인정보 탭 → 보안 탭 복귀
 *   → 칸은 60(서버값), `G7Core.state.getLocal().hasChanges === false`(저장소 B)
 *   → 그런데 저장 버튼 활성·「저장하지 않은 변경이 있습니다」 표시(화면은 true 를 읽음)
 *   → 저장 POST 본문은 60 이라 데이터 오류는 없으나 「지금 실행」 이 막힌다.
 *
 * 원인: `_localInit` 재적용(refetchOnMount)에서 적용(apply) 인스턴스는 저장소 A·B 의
 *       `hasChanges` 를 false 로 리셋하지만, 건너뛴(prune) 인스턴스는 payload 키 공간만
 *       제거하고 `hasChanges` 는 저장소 A 에 남긴다. 병합은 A 우선이므로 B 의 false 를 덮는다.
 *
 * 이 파일은 형제 루트 렌더러 2개(X 가 먼저 적용, 폼을 소유한 Y 가 prune)와 실물 자동바인딩
 * 입력으로 그 순서를 그대로 밟는다.
 *
 * 함께 잠그는 반대 방향(engine-v1.77.2 설계 검토에서 드러난 회귀 — 게시판·이커머스 설정의
 * 알림 탭): 폼과 무관한 데이터(알림 목록 페이지 정보)만 싣는 initLocal 이 다시 들어와도
 * 편집 중인 폼의 「변경 있음」은 유지돼야 한다. 그래서 초기화는 payload 가 변경 추적 폼의
 * dataKey 첫 키를 담을 때만 한다(`shouldResetHasChanges`).
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, screen, act } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import {
  registerTrackChangesRoot,
  resetLocalInitTracking,
  resetTrackChangesRoots,
  shouldResetHasChanges,
} from '../localInitSlot';

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

const TestDiv: React.FC<Record<string, unknown>> = (props) => {
  const { children, ...rest } = props as { children?: React.ReactNode };
  return <div {...(rest as Record<string, never>)}>{children}</div>;
};

/** 자동바인딩 대상 — name/value/onChange 를 그대로 DOM 에 넘긴다 */
const TestInput: React.FC<any> = ({ name, value, onChange }) => (
  <input data-testid={`input-${name}`} name={name} value={value ?? ''} onChange={onChange} />
);

describe('[case:state-global-localinit-has-changes-scope] _localInit 재적용은 변경 추적 폼을 덮을 때만 hasChanges 를 초기화한다', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;
  let globalState: Record<string, any>;
  let originalG7Core: any;

  /** 저장소 B (TemplateApp 계약: 객체는 얕은 펼침, 함수형은 prev 로 교체) */
  const setGlobalState = (updates: any) => {
    globalState = typeof updates === 'function' ? updates(globalState) : { ...globalState, ...updates };
  };

  /** 다른 루트(토스트·전환 오버레이 등) — 폼을 갖지 않고 payload 를 먼저 적용한다 */
  const otherRoot: ComponentDefinition = {
    id: 'other_root',
    type: 'basic',
    name: 'Div',
    props: { 'data-testid': 'other' },
    children: [],
  } as unknown as ComponentDefinition;

  /**
   * 폼을 소유한 레이아웃 루트 — 실측 구조 그대로 루트(`admin_layout_root`) 아래에
   * `data-key="form" data-track-changes` 컨테이너(`settings_content`)가 있다.
   * 자동바인딩 쓰기는 컨테이너의 FormContext 를 거쳐 **루트 렌더러의 저장소 A** 에 남는다.
   */
  const formRoot: ComponentDefinition = {
    id: 'admin_layout_root',
    type: 'basic',
    name: 'Div',
    props: { 'data-testid': 'layout-root' },
    children: [
      {
        id: 'settings_content',
        type: 'basic',
        name: 'Div',
        dataKey: 'form',
        trackChanges: true,
        props: { 'data-testid': 'form-root' },
        children: [
          { id: 'days', type: 'basic', name: 'Input', props: { name: 'days' } },
        ],
      },
      {
        id: 'flag',
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': 'flag', 'data-dirty': "{{_local.hasChanges ? 'dirty' : 'clean'}}" },
        children: [],
      },
    ],
  } as unknown as ComponentDefinition;


  const contextWith = (localInit: Record<string, any>) => ({
    _local: globalState._local,
    _global: globalState,
    _globalSetState: setGlobalState,
    _localInit: localInit,
  });

  /**
   * 두 루트를 렌더합니다. 컨텍스트 객체를 따로 받아, 한쪽만 새 컨텍스트로 다시 그리는
   * 순서(다른 루트만 리렌더 — 폼 루트는 props 동일)를 재현할 수 있게 한다.
   */
  const tree = (ctxOther: Record<string, any>, ctxForm: Record<string, any>) => {
    const common = {
      translationContext,
      registry,
      bindingEngine,
      translationEngine,
      actionDispatcher,
    };
    return (
      <>
        <DynamicRenderer componentDef={otherRoot} dataContext={ctxOther} {...common} />
        <DynamicRenderer componentDef={formRoot} dataContext={ctxForm} {...common} />
      </>
    );
  };

  beforeEach(() => {
    resetLocalInitTracking();
    resetTrackChangesRoots();
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
      Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };

    originalG7Core = (window as any).G7Core;
    delete (window as any).G7Core;

    globalState = { _local: {} };
    (window as any).__templateApp = {
      getGlobalState: () => globalState,
      setGlobalState: (u: any) => setGlobalState(u),
    };
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
    (window as any).__g7LayoutContextStack = [];

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

  /**
   * @scenario edit_order=last_edited
   *
   * @effects refetch_resets_has_changes_in_every_renderer
   */
  it('입력 → 재조회(refetchOnMount) 뒤 폼은 서버값이고 변경 표시도 해제된다', async () => {
    const first = { form: { days: 60 }, _forceLocalInit: 1 };
    let ctxOther = contextWith(first);
    const ctxForm = contextWith(first);
    const { rerender } = render(tree(ctxOther, ctxForm));
    await act(async () => {});

    expect(screen.getByTestId('flag').getAttribute('data-dirty')).toBe('clean');

    // 사용자가 60 → 45 입력 (실물 자동바인딩 경로)
    // 자동바인딩은 저장소 B 를 render:false 로 쓴다 — 폼 루트는 다시 렌더되지 않는다.
    fireEvent.change(screen.getByTestId('input-days'), { target: { value: '45' } });
    await act(async () => {});

    expect((screen.getByTestId('input-days') as HTMLInputElement).value).toBe('45');
    expect(screen.getByTestId('flag').getAttribute('data-dirty')).toBe('dirty');

    // 그 사이 다른 루트만 새 전역 상태로 다시 그려진다(실측: 헤더 확장 렌더러) —
    // 이 루트의 정리 효과가 입력 직후 남아 있던 setLocal 키 목록을 비운다.
    ctxOther = contextWith(first);
    rerender(tree(ctxOther, ctxForm));
    await act(async () => {});

    // 탭 왕복 → 같은 서버값을 다시 받음 (refetchOnMount 타임스탬프만 다름).
    // 다른 루트가 먼저 적용(apply)하고, 폼 루트는 정리(prune)한다.
    const second = { form: { days: 60 }, _forceLocalInit: 2 };
    rerender(tree(contextWith(second), contextWith(second)));
    await act(async () => {});
    rerender(tree(contextWith(second), contextWith(second)));
    await act(async () => {});

    // 저장소 B 는 적용 인스턴스가 리셋했다
    expect(globalState._local.hasChanges).toBe(false);
    expect(globalState._local.form.days).toBe(60);
    // 화면: 칸은 서버값, 변경 표시도 해제 (수정 전에는 prune 인스턴스 A 의 true 가 남는다)
    expect((screen.getByTestId('input-days') as HTMLInputElement).value).toBe('60');
    expect(screen.getByTestId('flag').getAttribute('data-dirty')).toBe('clean');
  });

  /**
   * @scenario edit_order=last_edited
   *
   * @effects input_without_refetch_keeps_has_changes
   */
  it('재조회 없이 입력만 하면 변경 표시는 유지된다 (대조군)', async () => {
    const first = { form: { days: 60 }, _forceLocalInit: 1 };
    const ctxForm = contextWith(first);
    const { rerender } = render(tree(contextWith(first), ctxForm));
    await act(async () => {});

    fireEvent.change(screen.getByTestId('input-days'), { target: { value: '45' } });
    await act(async () => {});
    rerender(tree(contextWith(first), ctxForm));
    await act(async () => {});

    expect(screen.getByTestId('flag').getAttribute('data-dirty')).toBe('dirty');
    expect(globalState._local.hasChanges).toBe(true);
  });

  /**
   * @scenario edit_order=last_edited
   *
   * @effects unrelated_init_local_keeps_has_changes
   */
  it('폼과 무관한 데이터만 다시 들어오면 편집 중인 폼의 변경 표시는 유지된다', async () => {
    const first = { form: { days: 60 }, _forceLocalInit: 1 };
    let ctxOther = contextWith(first);
    const ctxForm = contextWith(first);
    const { rerender } = render(tree(ctxOther, ctxForm));
    await act(async () => {});

    fireEvent.change(screen.getByTestId('input-days'), { target: { value: '45' } });
    await act(async () => {});
    ctxOther = contextWith(first);
    rerender(tree(ctxOther, ctxForm));
    await act(async () => {});
    expect(screen.getByTestId('flag').getAttribute('data-dirty')).toBe('dirty');

    // 알림 목록 재조회 — 폼과 무관한 페이지 정보만 싣는다 (refetchOnMount 없음)
    const unrelated = { notificationDefinitionCurrentPage: 1, notificationDefinitionTotalPages: 1 };
    rerender(tree(contextWith(unrelated), contextWith(unrelated)));
    await act(async () => {});
    rerender(tree(contextWith(unrelated), contextWith(unrelated)));
    await act(async () => {});

    expect(globalState._local.notificationDefinitionCurrentPage).toBe(1);
    expect(globalState._local.hasChanges).toBe(true);
    expect((screen.getByTestId('input-days') as HTMLInputElement).value).toBe('45');
    expect(screen.getByTestId('flag').getAttribute('data-dirty')).toBe('dirty');
  });
});

describe('[판정] shouldResetHasChanges — 변경 추적 폼 등록부', () => {
  beforeEach(() => resetTrackChangesRoots());

  /**
   * @scenario edit_order=untouched
   *
   * @effects has_changes_reset_scoped_to_tracked_form_roots
   */
  it('마운트된 변경 추적 폼이 없으면 종전대로 초기화한다', () => {
    expect(shouldResetHasChanges({ anything: 1 })).toBe(true);
  });

  /**
   * @scenario edit_order=untouched
   *
   * @effects has_changes_reset_scoped_to_tracked_form_roots
   */
  it('payload 가 폼의 dataKey 첫 키를 담으면 초기화한다', () => {
    const release = registerTrackChangesRoot('form.basic');
    expect(shouldResetHasChanges({ form: {}, originalDrivers: {} })).toBe(true);
    release();
  });

  /**
   * @scenario edit_order=untouched
   *
   * @effects has_changes_reset_scoped_to_tracked_form_roots
   */
  it('payload 가 폼과 무관한 키만 담으면 초기화하지 않는다', () => {
    const release = registerTrackChangesRoot('form');
    expect(shouldResetHasChanges({ notificationDefinitionCurrentPage: 1 })).toBe(false);
    release();
  });

  /**
   * @scenario edit_order=untouched
   *
   * @effects has_changes_reset_scoped_to_tracked_form_roots
   */
  it('_global 폼은 등록하지 않고, 해제하면 등록부에서 빠진다', () => {
    registerTrackChangesRoot('_global.editor')();
    const release = registerTrackChangesRoot('form');
    const releaseTwin = registerTrackChangesRoot('form');
    release();
    expect(shouldResetHasChanges({ notificationDefinitionCurrentPage: 1 })).toBe(false);
    releaseTwin();
    expect(shouldResetHasChanges({ notificationDefinitionCurrentPage: 1 })).toBe(true);
  });
});

