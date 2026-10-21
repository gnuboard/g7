/**
 * @file ActionDispatcher.leaveGuardDispatchFlush.test.tsx
 * @description `G7Core.dispatch(navigate)` 도 이탈 가드 판정 전에 대기 디바운스 입력을 반영한다 (engine-v1.82.1)
 *
 * 이벤트 액션 경로는 비디바운스 액션 앞에서 대기 디바운스를 flush 하지만, 관리자 사이드바·링크 `navigateTo` 가 쓰는
 * `G7Core.dispatch(navigate)` 는 그대로 가드 판정에 들어가 폼 변경이 없는 `leave_guard` 화면에서도 검색창 디바운스 대기만으로
 * 확인 창이 떠 이동이 막혔다(engine-v1.82.0). 하네스는 ActionDispatcher.leaveGuard.test.tsx 와 같다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { Router } from '../../routing/Router';
import { resetLeaveGuardForTests } from '../../routing/LeaveGuard';
import {
  installUnloadGuard,
  isDocumentUnloading,
  markDocumentLeaving,
  resetUnloadGuardForTesting,
} from '../networkResilience';

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

vi.mock('../../auth/AuthManager', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../auth/AuthManager')>();
  return {
    ...actual,
    AuthManager: {
      ...(actual as any).AuthManager,
      getInstance: vi.fn(() => ({
        isAuthenticated: () => true,
        getAuthType: () => 'user',
        checkAuth: vi.fn(async () => true),
        getConfig: vi.fn(() => ({})),
      })),
    },
  };
});

let globalState: Record<string, any>;
let storeA: Record<string, any>;
let dispatcher: ActionDispatcher;
let bindingEngine: DataBindingEngine;
let translationEngine: TranslationEngine;
let translationContext: TranslationContext;
let layoutJson: any;
let router: Router;
let routeChange: ReturnType<typeof vi.fn>;
let confirmSpy: ReturnType<typeof vi.spyOn>;
let originalG7Core: any;

const flush = async (): Promise<void> => {
  for (let i = 0; i < 6; i++) {
    await Promise.resolve();
  }
};

const setStateA = (updates: any): void => {
  const next = typeof updates === 'function' ? updates(storeA) : updates;
  const { __mergeMode: _m, __setStateId: _s, ...clean } = next || {};
  storeA = { ...storeA, ...clean };
};

/** 액션 하나를 실제 createHandler 로 실행한다 */
const run = async (action: any): Promise<void> => {
  const handler = dispatcher.createHandler(
    action,
    { _local: globalState._local, _global: globalState },
    { state: storeA, setState: setStateA }
  );
  await handler(new Event('click'));
  await flush();
};

/** 상태 경로 전부의 사본 — 판정 전후 비교용 */
const snapshotStateStores = (): string => JSON.stringify({
  globalState,
  storeA,
  pending: (window as any).__g7PendingLocalState ?? null,
  forced: (window as any).__g7ForcedLocalFields ?? null,
  override: (() => {
    const keys = (window as any).__g7SetLocalOverrideKeys;

    return keys instanceof Set ? [...keys] : keys ?? null;
  })(),
  lastSnapshot: (window as any).__g7LastSetLocalSnapshot ?? null,
  sequenceSync: (window as any).__g7SequenceLocalSync ?? null,
});

const fireUnload = (): Event => {
  const event = new Event('beforeunload', { cancelable: true });
  window.dispatchEvent(event);

  return event;
};

beforeEach(() => {
  resetLeaveGuardForTests();
  window.history.replaceState({}, '', '/board/free/write');
  globalState = { _local: {} };
  storeA = {};
  layoutJson = {
    layout_name: 'board/form',
    meta: { leave_guard: '{{_local.hasChanges === true}}' },
    components: [],
  };

  originalG7Core = (window as any).G7Core;
  delete (window as any).G7Core;

  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {};
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
  (window as any).__g7LastSetLocalSnapshot = undefined;
  (window as any).__g7LayoutContextStack = [];

  initializeG7CoreGlobals({
    getState: vi.fn(() => ({
      translationEngine,
      translationContext,
      bindingEngine,
      templateMetadata: { locales: ['ko'] },
      actionDispatcher: dispatcher,
      currentLayoutJson: layoutJson,
      currentDataContext: { route: { slug: 'free' }, query: {}, _global: globalState, _local: globalState._local },
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

  router = new Router('sirsoft-basic');
  router.setRoutes([
    { path: '/board/:slug/write', layout: 'board/form' },
    { path: '/board/:slug', layout: 'board/index' },
  ]);
  routeChange = vi.fn();
  router.on('routeChange', routeChange as any);

  // 실제 TemplateApp 처럼 옵션을 버리는 래퍼를 문맥에 넣는다
  dispatcher = new ActionDispatcher({ navigate: (path: string) => router.navigate(path) } as any);
  dispatcher.setGlobalStateUpdater((updates: any) => {
    (window as any).__templateApp.setGlobalState(updates);
  });

  confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
});

afterEach(() => {
  window.removeEventListener('popstate', (router as any).handlePopState);
  cleanup();
  vi.restoreAllMocks();
  vi.useRealTimers();
  resetLeaveGuardForTests();
  resetUnloadGuardForTesting();
  delete (window as any).__templateApp;
  delete (window as any).__g7PendingLocalState;
  delete (window as any).__g7ForcedLocalFields;
  delete (window as any).__g7SetLocalOverrideKeys;
  delete (window as any).__g7SequenceLocalSync;
  delete (window as any).__g7LastSetLocalSnapshot;
  (window as any).__g7LayoutContextStack = [];
  if (originalG7Core === undefined) delete (window as any).G7Core;
  else (window as any).G7Core = originalG7Core;
});

describe('가드 식 거짓 + 디바운스 검색 대기만 있을 때 다른 화면 이동 (engine-v1.82.1)', () => {
  const ctx = () => ({ _local: globalState._local, _global: globalState });
  const comp = () => ({ state: storeA, setState: setStateA });
  /** 검색창 꼴 — 레이아웃 actions 의 300ms 디바운스 change(검색어 setState). 실물 bindActionsToProps 경로. 폼 변경(hasChanges)은 없다 */
  const typeSearchDebounced = async (): Promise<void> => {
    const bound = dispatcher.bindActionsToProps(
      { id: 'search_kw', actions: [{ type: 'change', handler: 'setState', params: { target: '_local', keyword: '{{$event.target.value}}' }, debounce: 300 }] },
      ctx(),
      comp(),
    );
    const input = document.createElement('input');
    input.value = '홍길동';
    bound.onChange({ target: input, currentTarget: input, preventDefault: () => {}, stopPropagation: () => {}, type: 'change', nativeEvent: new Event('change') });
    await flush();
  };
  /** 레이아웃 링크 꼴 — click navigate (실물 bindActionsToProps 경로) */
  const clickLink = async (): Promise<void> => {
    const bound = dispatcher.bindActionsToProps(
      { id: 'link_list', actions: [{ type: 'click', handler: 'navigate', params: { path: '/board/free' } }] },
      ctx(),
      comp(),
    );
    const a = document.createElement('a');
    await bound.onClick({ target: a, currentTarget: a, preventDefault: () => {}, stopPropagation: () => {}, type: 'click', button: 0, nativeEvent: new MouseEvent('click') });
    await flush();
  };

  beforeEach(() => {
    (window as any).__templateApp.getActionDispatcher = () => dispatcher;
    (window as any).__templateApp.getRouter = () => router;
  });

  /**
   * @scenario exit_path=pending_debounce
   * @effects clean_leave_does_not_ask
   */
  it('4-0 전제: 대기 디바운스가 있고 식은 거짓', async () => {
    await typeSearchDebounced();
    expect(dispatcher.hasPendingDebounce()).toBe(true);
    expect(globalState._local.hasChanges).toBeUndefined();
  });

  /**
   * @scenario exit_path=pending_debounce
   * @effects clean_leave_does_not_ask
   */
  it('4-a 레이아웃 링크(click navigate 액션) — 비디바운스 액션이 먼저 flush 하므로 묻지 않는다', async () => {
    await typeSearchDebounced();
    await clickLink();
    expect(dispatcher.hasPendingDebounce()).toBe(false);
    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario exit_path=pending_debounce
   * @effects dispatch_navigate_flushes_pending_input
   */
  it('4-b G7Core.dispatch navigate(AdminSidebar·navigationLink.navigateTo 경로) — 대기 입력을 먼저 반영해 묻지 않고 이동한다', async () => {
    await typeSearchDebounced();
    await (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: '/board/free' } });
    await flush();
    // 수정 전: 확인 창 1회 · 이동 0 · 주소 /board/free/write 그대로
    expect(dispatcher.hasPendingDebounce()).toBe(false);
    expect(globalState._local.keyword).toBe('홍길동');
    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario exit_path=pending_debounce
   * @effects pending_debounce_counts_as_dirty
   */
  it('4-c 대기 중 브라우저 뒤로(popstate) — 확인창 (CHANGELOG 가 밝힌 의도)', async () => {
    await typeSearchDebounced();
    window.history.replaceState({}, '', '/board/free');
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();
    expect(confirmSpy).toHaveBeenCalledTimes(1);
  });
});
