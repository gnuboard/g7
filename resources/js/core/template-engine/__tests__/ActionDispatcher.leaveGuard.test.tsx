/**
 * 레이아웃 이탈 가드 — 실물 ActionDispatcher·G7CoreGlobals·Router 잠금 (engine-v1.82.0)
 *
 * - 가드 판정 근거(`_local.hasChanges`)는 손으로 채우지 않고 실제 writer(`setState` 액션·`G7Core.state.setLocal`)로 만든다.
 * - 판정은 상태를 쓰지 않는다: 저장소 A·B, `__g7PendingLocalState`·`__g7ForcedLocalFields`·`__g7SetLocalOverrideKeys`·
 *   `__g7LastSetLocalSnapshot` 이 판정 전후 같다 (상태 병합 경로 무접점).
 * - `bypassLeaveGuard` 는 navigate · navigateBack · openWindow · redirect 에서 같은 이름으로 받는다.
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

describe('판정 근거는 실제 writer 가 쓴 저장소 B 다', () => {
  // @scenario exit_path=navigate
  // @effects guard_reads_state_without_writing, dirty_leave_asks_and_cancel_keeps_screen
  it('setState 액션으로 hasChanges 를 켜면 navigate 가 묻고, 취소하면 이동 0', async () => {
    await run({ handler: 'setState', params: { target: '_local', hasChanges: true } });
    expect(globalState._local.hasChanges).toBe(true);

    await run({ handler: 'navigate', params: { path: '/board/free' } });

    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(routeChange).not.toHaveBeenCalled();
    expect(window.location.pathname).toBe('/board/free/write');
  });

  // @scenario exit_path=guard_value
  // @effects guard_reads_state_without_writing
  it('G7Core.state.setLocal 로 켠 값(아직 커밋 전 pending)도 본다', async () => {
    (window as any).G7Core.state.setLocal({ hasChanges: true });

    expect((window as any).G7Core.navigation.isLeaveGuarded()).toBe(true);
  });

  // @scenario exit_path=navigate
  // @effects clean_leave_does_not_ask
  it('hasChanges 를 끄면(저장 성공) 묻지 않고 이동한다', async () => {
    await run({ handler: 'setState', params: { target: '_local', hasChanges: true } });
    await run({ handler: 'setState', params: { target: '_local', hasChanges: false } });

    await run({ handler: 'navigate', params: { path: '/board/free' } });

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });
});

describe('판정은 상태를 쓰지 않는다', () => {
  // @scenario exit_path=guard_value
  // @effects guard_reads_state_without_writing
  it('isLeaveGuarded·막힌 navigate·막힌 popstate·beforeunload 전후 모든 상태 경로가 같다', async () => {
    await run({ handler: 'setState', params: { target: '_local', hasChanges: true, form: { title: '제목' } } });
    (window as any).G7Core.state.setLocal({ form: { body: '본문' } }, { render: false });
    const before = snapshotStateStores();

    (window as any).G7Core.navigation.isLeaveGuarded();
    router.navigate('/board/free');
    window.history.replaceState({}, '', '/board/free');
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    fireUnload();
    await flush();

    expect(snapshotStateStores()).toBe(before);
    expect(confirmSpy).toHaveBeenCalledTimes(2);
  });
});

describe('bypassLeaveGuard — navigate · navigateBack · openWindow · redirect 같은 이름', () => {
  beforeEach(async () => {
    await run({ handler: 'setState', params: { target: '_local', hasChanges: true } });
  });

  // @scenario exit_path=bypass
  // @effects bypass_skips_ask_once
  it('navigate: 옵션을 버리는 문맥 래퍼를 거쳐도 묻지 않고 이동한다', async () => {
    await run({ handler: 'navigate', params: { path: '/board/free', bypassLeaveGuard: true } });

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=navigate
  // @effects dirty_leave_asks_and_cancel_keeps_screen
  it('navigate: 거부되면 스크롤을 옮기지 않는다', async () => {
    const scrollSpy = vi.spyOn(dispatcher as any, 'applyScrollOption');

    await run({ handler: 'navigate', params: { path: '/board/free' } });

    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(scrollSpy).not.toHaveBeenCalled();
  });

  // @scenario exit_path=bypass
  // @effects bypass_skips_ask_once
  it('navigateBack: 다음 popstate 1회를 묻지 않는다', async () => {
    const backSpy = vi.spyOn(window.history, 'back').mockImplementation(() => {
      window.history.replaceState({}, '', '/board/free');
      window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    });

    await run({ handler: 'navigateBack', params: { bypassLeaveGuard: true } });

    expect(backSpy).toHaveBeenCalledTimes(1);
    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=popstate
  // @effects dirty_leave_asks_and_cancel_keeps_screen
  it('navigateBack: 우회가 없으면 popstate 에서 묻고, 취소하면 확정 주소로 되돌린다', async () => {
    vi.spyOn(window.history, 'back').mockImplementation(() => {
      window.history.replaceState({}, '', '/board/free');
      window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    });

    await run({ handler: 'navigateBack' });

    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(window.location.pathname).toBe('/board/free/write');
    expect(routeChange).not.toHaveBeenCalled();
  });

  // @scenario exit_path=bypass
  // @effects bypass_skips_ask_once
  it('openWindow _self: 다음 beforeunload 1회를 묻지 않는다', async () => {
    const assignSpy = vi.fn();
    const originalLocation = window.location;
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...originalLocation, assign: assignSpy, pathname: originalLocation.pathname, href: originalLocation.href, origin: originalLocation.origin },
    });
    try {
      await run({ handler: 'openWindow', params: { path: '/board/free/edit', target: '_self', bypassLeaveGuard: true } });
      expect(assignSpy).toHaveBeenCalledTimes(1);
      expect(fireUnload().defaultPrevented).toBe(false);
      expect(fireUnload().defaultPrevented, '무장은 1회용').toBe(true);
    } finally {
      Object.defineProperty(window, 'location', { configurable: true, value: originalLocation });
    }
  });

  // @scenario exit_path=bypass
  // @effects bypass_skips_ask_once
  it('navigate 미등록 경로(대체 동작 openWindow _self, 비동기 dispatch)도 우회가 다음 beforeunload 1회에 닿는다', async () => {
    (window as any).__templateApp.getRouter = () => router;
    const assignSpy = vi.fn();
    const originalLocation = window.location;
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...originalLocation, assign: assignSpy, pathname: originalLocation.pathname, href: originalLocation.href, origin: originalLocation.origin },
    });
    try {
      await run({ handler: 'navigate', params: { path: '/shop/cart', bypassLeaveGuard: true } });
      expect(assignSpy).toHaveBeenCalledTimes(1);
      expect(confirmSpy).not.toHaveBeenCalled();
      expect(fireUnload().defaultPrevented).toBe(false);
    } finally {
      Object.defineProperty(window, 'location', { configurable: true, value: originalLocation });
    }
  });

  // @scenario exit_path=beforeunload
  // @effects dirty_leave_asks_and_cancel_keeps_screen
  it('openWindow _self: 우회가 없으면 beforeunload 를 취소한다', () => {
    expect(fireUnload().defaultPrevented).toBe(true);
  });
});

describe('의도한 문서 이탈(세션 만료)과 networkResilience 의 이탈 표지', () => {
  beforeEach(async () => {
    resetUnloadGuardForTesting();
    installUnloadGuard();
    await run({ handler: 'setState', params: { target: '_local', hasChanges: true } });
  });

  // @scenario exit_path=intentional_leave
  // @effects intentional_leave_not_asked
  it('markDocumentLeaving(세션 만료 → 로그인 이동)은 이탈 확인을 묻지 않는다', () => {
    markDocumentLeaving();

    expect(fireUnload().defaultPrevented).toBe(false);
  });

  // @scenario exit_path=intentional_leave
  // @effects intentional_leave_not_asked
  it('가드가 beforeunload 를 취소하고 사용자가 머무르면 이탈 표지를 되돌린다', () => {
    vi.useFakeTimers();

    expect(fireUnload().defaultPrevented).toBe(true);
    expect(isDocumentUnloading()).toBe(true);

    vi.advanceTimersByTime(1000);
    expect(isDocumentUnloading(), '머무른 문서의 재시도·오류 표시가 꺼진 채 남으면 안 된다').toBe(false);
  });

  // @scenario exit_path=intentional_leave
  // @effects intentional_leave_not_asked
  it('취소되지 않은 beforeunload 는 이탈 표지를 유지한다', async () => {
    await run({ handler: 'setState', params: { target: '_local', hasChanges: false } });
    vi.useFakeTimers();

    expect(fireUnload().defaultPrevented).toBe(false);
    vi.advanceTimersByTime(1000);
    expect(isDocumentUnloading()).toBe(true);
  });
});

describe('디바운스 대기 입력 — 읽기 전용으로 「변경 있음」 (상태 쓰기 0)', () => {
  /**
   * 300ms 디바운스로 제목을 쓰는 입력 — 자동바인딩 디바운스(DynamicRenderer)가 예약하는 것과 같은 디스패처 대기열
   * (`debouncedCall`)에, 실행되면 실제 writer(`setState` 액션)로 쓴다
   */
  const typeDebounced = async (): Promise<void> => {
    dispatcher.debouncedCall('__g7AutoBindingDebounce:test', 300, () => {
      void run({ handler: 'setState', params: { target: '_local', title: '초안' } });
    });
    await flush();
  };

  // @scenario exit_path=pending_debounce
  // @effects pending_debounce_counts_as_dirty
  it('대기 중 브라우저 뒤로(popstate) — 식이 거짓이어도 묻고, 대기분은 실행·정리하지 않는다', async () => {
    await typeDebounced();
    expect(dispatcher.hasPendingDebounce()).toBe(true);
    const before = snapshotStateStores();

    window.history.replaceState({}, '', '/board/free');
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();

    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(window.location.pathname).toBe('/board/free/write');
    expect(routeChange).not.toHaveBeenCalled();
    expect(snapshotStateStores(), '판정이 대기분을 flush 하면 안 된다').toBe(before);
    expect(dispatcher.hasPendingDebounce()).toBe(true);
  });

  // @scenario exit_path=pending_debounce
  // @effects pending_debounce_counts_as_dirty
  it('대기 중 새로고침·닫기(beforeunload) — 취소한다', async () => {
    await typeDebounced();
    const before = snapshotStateStores();

    expect(fireUnload().defaultPrevented).toBe(true);
    expect(snapshotStateStores()).toBe(before);
    expect(dispatcher.hasPendingDebounce()).toBe(true);
  });

  // @scenario exit_path=pending_debounce
  // @effects clean_leave_does_not_ask
  it('대기 없음 + 식 거짓 — 묻지 않는다', async () => {
    expect(dispatcher.hasPendingDebounce()).toBe(false);

    window.history.replaceState({}, '', '/board/free');
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
    expect(fireUnload().defaultPrevented).toBe(false);
  });

  // @scenario exit_path=pending_debounce
  // @effects clean_leave_does_not_ask
  it('대기분이 실행된 뒤(디바운스 경과)·식 거짓 — 묻지 않는다', async () => {
    vi.useFakeTimers();
    await typeDebounced();
    vi.advanceTimersByTime(350);
    vi.useRealTimers();
    await flush();

    expect(dispatcher.hasPendingDebounce()).toBe(false);
    expect(globalState._local.title).toBe('초안');
    expect(fireUnload().defaultPrevented).toBe(false);
  });

  // @scenario exit_path=pending_debounce
  // @effects undeclared_guard_keeps_previous_behavior
  it('가드를 선언하지 않은 화면은 대기 중이어도 묻지 않는다 (종전 동작)', async () => {
    layoutJson = { layout_name: 'board/index', meta: {}, components: [] };
    await typeDebounced();

    expect(fireUnload().defaultPrevented).toBe(false);
    window.history.replaceState({}, '', '/board/free');
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();
    expect(confirmSpy).not.toHaveBeenCalled();
  });
});
