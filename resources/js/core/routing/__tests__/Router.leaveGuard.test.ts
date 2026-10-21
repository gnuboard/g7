/**
 * Router 레이아웃 이탈 가드 잠금 (engine-v1.82.0)
 *
 * 레이아웃 `meta.leave_guard` 가 참인 화면을 떠날 때 확인을 묻는 경로를 실물 Router·DataBindingEngine 으로 밟는다.
 * - navigate: pushState **전** 판정 — 머무르면 pushState 0·routeChange 0
 * - popstate: 이미 바뀐 주소를 확정 주소로 동기 되돌림 — routeChange 0
 * - beforeunload: 가드 참이면 취소
 * - 우회: navigate 옵션·동기 범위·1회용 무장(뒤로 가기·전체 이동) — 소비·만료
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Router } from '../Router';
import {
  configureLeaveGuard,
  resetLeaveGuardForTests,
  runWithLeaveGuardBypass,
  armLeaveGuardBypass,
  isLeaveGuarded,
  LEAVE_GUARD_ARMED_BYPASS_TTL_MS,
  LEAVE_GUARD_DEFAULT_MESSAGE_KEY,
} from '../LeaveGuard';
import { DataBindingEngine } from '../../template-engine/DataBindingEngine';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      isAuthenticated: () => true,
      getAuthType: () => 'user',
      checkAuth: vi.fn(async () => true),
    })),
  },
}));

let layoutJson: any;
let localState: Record<string, any>;
let router: Router;
let routeChange: ReturnType<typeof vi.fn>;
let confirmSpy: ReturnType<typeof vi.spyOn>;
let pushSpy: ReturnType<typeof vi.spyOn>;
/** 이 파일에서 만든 Router — 시험마다 popstate 리스너를 걷는다(앞 시험의 Router 가 남아 같이 받지 않게) */
const createdRouters: Router[] = [];

const makeRouter = (): Router => {
  const created = new Router('sirsoft-basic');
  createdRouters.push(created);

  return created;
};

const flush = async (): Promise<void> => {
  for (let i = 0; i < 5; i++) {
    await Promise.resolve();
  }
};

/** 폼 화면 레이아웃 — 저장하지 않은 변경이 있으면 묻는다 */
const formLayout = (extraMeta: Record<string, any> = {}): any => ({
  layout_name: 'board/form',
  meta: { leave_guard: '{{_local.hasChanges === true}}', ...extraMeta },
  components: [],
});

beforeEach(() => {
  resetLeaveGuardForTests();
  window.history.replaceState({}, '', '/board/free/write');
  layoutJson = formLayout();
  localState = { hasChanges: true };

  (window as any).G7Core = {
    state: {
      get: () => ({ _local: localState }),
      getLocal: () => localState,
    },
  };

  const engine = new DataBindingEngine();
  configureLeaveGuard({
    getLayoutJson: () => layoutJson,
    getDataContext: () => ({ route: { slug: 'free' }, query: {} }),
    getBindingEngine: () => engine,
    translate: (key: string) => `T(${key})`,
  });

  router = makeRouter();
  router.setRoutes([
    { path: '/board/:slug/write', layout: 'board/form' },
    { path: '/board/:slug', layout: 'board/index' },
    { path: '/old', redirect: '/board/free' },
  ]);
  routeChange = vi.fn();
  router.on('routeChange', routeChange as any);

  confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
  pushSpy = vi.spyOn(window.history, 'pushState');
});

afterEach(() => {
  for (const created of createdRouters.splice(0)) {
    window.removeEventListener('popstate', (created as any).handlePopState);
  }
  vi.restoreAllMocks();
  vi.useRealTimers();
  resetLeaveGuardForTests();
  delete (window as any).G7Core;
});

describe('[case:components-form-leave-without-confirm] Router.navigate — 이탈 가드', () => {
  // @scenario exit_path=navigate
  // @effects clean_leave_does_not_ask
  it('가드 거짓이면 묻지 않고 종전대로 이동한다', async () => {
    localState = { hasChanges: false };

    expect(router.navigate('/board/free')).toBe(true);
    await flush();

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(pushSpy).toHaveBeenCalledTimes(1);
    expect(routeChange).toHaveBeenCalledTimes(1);
    expect(window.location.pathname).toBe('/board/free');
  });

  // @scenario exit_path=navigate
  // @effects undeclared_guard_keeps_previous_behavior
  it('레이아웃에 leave_guard 가 없으면 hasChanges 가 참이어도 묻지 않는다 (종전 동작)', async () => {
    layoutJson = { layout_name: 'board/form', meta: {}, components: [] };

    router.navigate('/board/free');
    await flush();

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=navigate
  // @effects dirty_leave_asks_and_cancel_keeps_screen
  it('가드 참 + 취소면 pushState 0 · routeChange 0 · false 를 돌려준다', async () => {
    const started = router.navigate('/board/free');
    await flush();

    expect(started).toBe(false);
    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(confirmSpy).toHaveBeenCalledWith(`T(${LEAVE_GUARD_DEFAULT_MESSAGE_KEY})`);
    expect(pushSpy).not.toHaveBeenCalled();
    expect(routeChange).not.toHaveBeenCalled();
    expect(window.location.pathname).toBe('/board/free/write');
  });

  // @scenario exit_path=navigate
  // @effects approved_leave_moves_and_rearms
  it('가드 참 + 승인이면 이동하고, 같은 이동 안의 리다이렉트 라우트는 다시 묻지 않는다', async () => {
    confirmSpy.mockReturnValue(true);

    router.navigate('/old');
    await flush();
    await flush();

    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(window.location.pathname).toBe('/board/free');
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=navigate
  // @effects approved_leave_moves_and_rearms
  it('새 화면이 커밋되면 승인이 풀려 다음 이탈에 다시 묻는다', async () => {
    confirmSpy.mockReturnValue(true);
    router.navigate('/board/free');
    await flush();
    expect(confirmSpy).toHaveBeenCalledTimes(1);

    // 새 화면도 같은 가드를 선언했고 변경이 있다
    confirmSpy.mockReturnValue(false);
    router.navigate('/board/notice');
    await flush();

    expect(confirmSpy).toHaveBeenCalledTimes(2);
    expect(window.location.pathname).toBe('/board/free');
  });

  // @scenario exit_path=navigate
  // @effects guard_failure_does_not_trap_user
  it('confirm 이 boolean 을 돌려주지 않는 환경(대화상자 미구현)에서는 막지 않는다', async () => {
    confirmSpy.mockReturnValue(undefined as any);

    expect(router.navigate('/board/free')).toBe(true);
    await flush();

    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=navigate
  // @effects bypass_skips_ask_once
  it('bypassLeaveGuard 옵션이면 묻지 않고 이동한다', async () => {
    router.navigate('/board/free', { bypassLeaveGuard: true });
    await flush();

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=navigate
  // @effects bypass_skips_ask_once
  it('동기 우회 범위 안의 navigate 는 옵션을 버리는 래퍼를 거쳐도 묻지 않는다', async () => {
    const dropOptions = (path: string) => router.navigate(path);
    runWithLeaveGuardBypass(() => dropOptions('/board/free'));
    await flush();

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=navigate
  // @effects same_path_move_does_not_ask
  it('같은 경로(쿼리·해시만 다름)는 묻지 않는다', async () => {
    router.navigate('/board/free/write?draft=1#body');
    await flush();

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=navigate
  // @effects dirty_leave_asks_and_cancel_keeps_screen
  it('다른 이동이 진행 중이어도(대기열) 넣기 전에 판정한다', async () => {
    localState = { hasChanges: false };
    // 진행 중 이동을 붙잡아 둔다
    let release: () => void = () => {};
    routeChange.mockImplementationOnce(() => new Promise<void>((r) => { release = r; }));
    router.navigate('/board/free');
    await flush();

    localState = { hasChanges: true };
    const queued = router.navigate('/board/notice');

    expect(queued).toBe(false);
    expect(confirmSpy).toHaveBeenCalledTimes(1);
    release();
    await flush();
    expect(window.location.pathname).toBe('/board/free');
  });

  // @scenario exit_path=navigate
  // @effects guard_failure_does_not_trap_user
  it('식 평가가 실패하거나 {{식}} 이 아니면 묻지 않는다 (사용자를 가두지 않는다)', async () => {
    layoutJson = formLayout({ leave_guard: '{{_local.a.b.c.d()}}' });
    router.navigate('/board/free');
    await flush();
    expect(confirmSpy).not.toHaveBeenCalled();

    layoutJson = formLayout({ leave_guard: 'true' });
    router.navigate('/board/notice');
    await flush();
    expect(confirmSpy).not.toHaveBeenCalled();
  });

  // @scenario exit_path=navigate
  // @effects leave_message_translated
  it('leave_message 의 $t: 문구를 번역해 묻는다', async () => {
    layoutJson = formLayout({ leave_message: '$t:board.leave' });

    router.navigate('/board/free');

    expect(confirmSpy).toHaveBeenCalledWith('T(board.leave)');
  });
});

describe('Router popstate — 이탈 가드', () => {
  // @scenario exit_path=popstate
  // @effects dirty_leave_asks_and_cancel_keeps_screen
  it('가드 참 + 취소면 확정 주소를 곧바로 다시 쌓고 routeChange 0', async () => {
    // 사용자가 뒤로 가기를 눌러 주소가 이미 바뀐 상태를 만든다
    window.history.replaceState({}, '', '/board/free');
    pushSpy.mockClear();

    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();

    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(pushSpy).toHaveBeenCalledTimes(1);
    expect(window.location.pathname).toBe('/board/free/write');
    expect(routeChange).not.toHaveBeenCalled();
  });

  // @scenario exit_path=popstate
  // @effects approved_leave_moves_and_rearms
  it('가드 참 + 승인이면 그 주소로 화면을 바꾼다', async () => {
    confirmSpy.mockReturnValue(true);
    window.history.replaceState({}, '', '/board/free');

    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();

    expect(routeChange).toHaveBeenCalledTimes(1);
    expect(routeChange.mock.calls[0][0].path).toBe('/board/free');
  });

  // @scenario exit_path=popstate
  // @effects clean_leave_does_not_ask
  it('가드 거짓이면 종전대로 routeChange', async () => {
    localState = { hasChanges: false };
    window.history.replaceState({}, '', '/board/free');

    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=popstate
  // @effects same_path_move_does_not_ask
  it('해시만 바뀐 popstate 는 묻지 않는다', async () => {
    window.history.replaceState({}, '', '/board/free/write#comments');

    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();

    expect(confirmSpy).not.toHaveBeenCalled();
  });

  // @scenario exit_path=popstate
  // @effects bypass_skips_ask_once
  it('무장된 우회(navigateBack bypassLeaveGuard)는 다음 popstate 1회만 묻지 않고 소멸한다', async () => {
    armLeaveGuardBypass();
    window.history.replaceState({}, '', '/board/free');
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(routeChange).toHaveBeenCalledTimes(1);

    // 소비됐다 — 다음 이탈(새 화면도 같은 가드)은 다시 묻는다
    window.history.replaceState({}, '', '/board/notice');
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
    await flush();
    expect(confirmSpy).toHaveBeenCalledTimes(1);
  });

  // @scenario exit_path=popstate
  // @effects bypass_skips_ask_once
  it('무장된 우회는 popstate 가 오지 않으면(히스토리 첫 항목) 제한 시간 뒤 사라진다', async () => {
    vi.useFakeTimers();
    armLeaveGuardBypass();
    vi.advanceTimersByTime(LEAVE_GUARD_ARMED_BYPASS_TTL_MS + 1);

    window.history.replaceState({}, '', '/board/free');
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));

    expect(confirmSpy).toHaveBeenCalledTimes(1);
  });
});

describe('beforeunload — 이탈 가드', () => {
  const fireUnload = (): Event => {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);

    return event;
  };

  // @scenario exit_path=beforeunload
  // @effects dirty_leave_asks_and_cancel_keeps_screen
  it('가드 참이면 취소한다(브라우저 확인 창)', () => {
    expect(fireUnload().defaultPrevented).toBe(true);
    expect(confirmSpy).not.toHaveBeenCalled();
  });

  // @scenario exit_path=beforeunload
  // @effects clean_leave_does_not_ask
  it('가드 거짓이면 취소하지 않는다', () => {
    localState = { hasChanges: false };

    expect(fireUnload().defaultPrevented).toBe(false);
  });

  // @scenario exit_path=beforeunload
  // @effects bypass_skips_ask_once
  it('무장된 우회(openWindow _self bypassLeaveGuard)는 다음 1회만 묻지 않는다', () => {
    armLeaveGuardBypass();

    expect(fireUnload().defaultPrevented).toBe(false);
    expect(fireUnload().defaultPrevented).toBe(true);
  });

  // @scenario exit_path=beforeunload
  // @effects single_unload_listener
  it('Router 를 여러 번 만들어도 리스너는 하나다', () => {
    makeRouter();
    makeRouter();
    armLeaveGuardBypass();

    // 리스너가 둘이면 두 번째가 무장을 못 보고 취소한다
    expect(fireUnload().defaultPrevented).toBe(false);
  });
});

describe('G7Core.navigation.isLeaveGuarded 의 값', () => {
  // @scenario exit_path=guard_value
  // @effects guard_reads_state_without_writing
  it('가드 식을 그대로 돌려준다', () => {
    expect(isLeaveGuarded()).toBe(true);
    localState = { hasChanges: false };
    expect(isLeaveGuarded()).toBe(false);
  });
});
