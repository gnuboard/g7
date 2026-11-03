/**
 * 뒤로/앞으로 가기·새로고침 스크롤 위치 복원 (engine-v1.85.0)
 *
 * 브라우저 기본 복원은 popstate 직후 한 번만 시도해, 화면이 데이터를 다시 받느라 아직 짧으면 맨 위에 머문다
 * (실측: 모바일 홈 2,742px → 상품 → 뒤로 = 0, 홈 높이는 약 1초에 걸쳐 3,416 → 5,574px).
 * 라우터가 이동 종류를 표시하고 항목별 위치를 기억하면, 화면 처리(TemplateApp 자리 — 여기서는 routeChange 구독자)가
 * 복원 정보를 받아 설정대로 되돌리는지 잠근다. 문서 높이·스크롤은 jsdom 이 계산하지 않으므로 직접 흉내 낸다.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Router } from '../Router';
import { configureLeaveGuard, resetLeaveGuardForTests } from '../LeaveGuard';
import { DataBindingEngine } from '../../template-engine/DataBindingEngine';
import { ActionDispatcher } from '../../template-engine/ActionDispatcher';
import {
  getScrollRecord,
  mergeScrollRestorationConfig,
  resetScrollMemoryForTesting,
  runScrollRestore,
  runWithReturnScroll,
  setActiveScrollContainers,
  setScrollMemoryDisabled,
  takeScrollRestoreTicket,
  type PartialScrollRestorationConfig,
} from '../ScrollMemory';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: { getInstance: vi.fn() },
}));

/** 흉내 낸 문서 상태 */
const page = { height: 900, viewport: 844, y: 0 };

/** 사용자가 스크롤한 것처럼 위치를 바꾸고 scroll 이벤트를 낸다 */
function userScrollTo(y: number): void {
  page.y = y;
  window.dispatchEvent(new Event('scroll'));
}

/** 뒤로/앞으로 가기 — 주소를 그 항목으로 바꾸고 popstate (Navigation API 없음 → 주소가 항목 키) */
function traverseTo(path: string): void {
  window.history.pushState({}, '', path);
  window.dispatchEvent(new PopStateEvent('popstate'));
}

/** 화면 처리 흉내 — 복원 정보를 받아 첫 렌더 직후 설정대로 복원을 시작한다 */
function subscribeScreen(router: Router, onRender: (path: string) => void, layer?: PartialScrollRestorationConfig): void {
  router.on('routeChange', (route: { path: string }) => {
    const ticket = takeScrollRestoreTicket();
    const config = mergeScrollRestorationConfig(layer);
    setActiveScrollContainers(config.containers);
    onRender(route.path);
    if (ticket) {
      void runScrollRestore(ticket, config);
    }
  });
}

describe('Router 뒤로/앞으로 가기·새로고침 스크롤 복원', () => {
  let router: Router;
  let scrollToSpy: ReturnType<typeof vi.fn>;
  let timers: ReturnType<typeof setTimeout>[];
  const createdRouters: Router[] = [];

  const createRouter = (): Router => {
    const r = new Router('sirsoft-basic');
    createdRouters.push(r);
    (r as any).routes = [
      { path: '/', layout: 'home' },
      { path: '/shop/products/:id', layout: 'shop/show' },
      { path: '/checkout', layout: 'checkout' },
      { path: '/done', layout: 'done' },
    ];
    return r;
  };

  beforeEach(() => {
    resetScrollMemoryForTesting();
    window.sessionStorage.clear();
    window.history.replaceState({}, '', '/');
    page.height = 900;
    page.viewport = 844;
    page.y = 0;
    timers = [];
    Object.defineProperty(document.documentElement, 'scrollHeight', { configurable: true, get: () => page.height });
    Object.defineProperty(window, 'innerHeight', { configurable: true, get: () => page.viewport });
    Object.defineProperty(window, 'scrollY', { configurable: true, get: () => page.y });
    scrollToSpy = vi.fn((arg: ScrollToOptions | number) => {
      const top = typeof arg === 'number' ? arg : (arg.top ?? 0);
      page.y = Math.max(0, Math.min(top, page.height - page.viewport));
      window.dispatchEvent(new Event('scroll'));
    });
    window.scrollTo = scrollToSpy as unknown as typeof window.scrollTo;
    window.requestAnimationFrame = ((cb: FrameRequestCallback) => { cb(0); return 0; }) as typeof window.requestAnimationFrame;
    router = createRouter();
  });

  afterEach(() => {
    timers.forEach(clearTimeout);
    // 앞 시험의 라우터가 popstate 를 계속 받지 않게 뗀다
    for (const created of createdRouters.splice(0)) {
      window.removeEventListener('popstate', (created as any).handlePopState);
    }
    resetScrollMemoryForTesting();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  /** 홈 → 상품 → 뒤로. 홈은 처음엔 짧다가 늦게 자란다 */
  const homeToProductAndBack = async (homeY: number, finalHomeHeight: number, layer?: PartialScrollRestorationConfig): Promise<void> => {
    subscribeScreen(router, (path) => {
      if (path === '/') {
        page.height = 3416;
        timers.push(
          setTimeout(() => { page.height = 3978; }, 100),
          setTimeout(() => { page.height = 4670; }, 200),
          setTimeout(() => { page.height = finalHomeHeight; }, 300),
        );
      } else {
        page.height = 2000;
        page.y = 0;
      }
    }, layer);
    page.height = finalHomeHeight;
    userScrollTo(homeY);

    router.navigate('/shop/products/P0000DTB');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));

    traverseTo('/');
    // 이전 화면(상세)이 남은 채 생긴 스크롤 — 홈의 기억을 덮으면 안 된다
    userScrollTo(0);
  };

  // @scenario entry=popstate, content_timing=grows_late, config_layer=engine_default
  // @effects scroll_position_restored_after_late_content
  it('홈 2,742px 에서 상품으로 갔다가 돌아오면, 홈이 늦게 자라도 2,742px 로 되돌린다', async () => {
    await homeToProductAndBack(2742, 5574);

    await vi.waitFor(() => expect(page.y).toBe(2742), { timeout: 3000 });
  });

  // @scenario entry=popstate, content_timing=already_tall, config_layer=engine_default
  // @effects stale_scroll_during_popstate_not_saved
  it('popstate 직후 이전 화면에서 생긴 스크롤은 돌아갈 항목의 기억을 덮지 않는다', async () => {
    await homeToProductAndBack(1500, 5000);

    await vi.waitFor(() => expect(page.y).toBe(1500), { timeout: 3000 });
    expect(getScrollRecord('url:/')?.y).toBe(1500);
  });

  // @scenario entry=navigate, content_timing=already_tall, config_layer=engine_default
  // @effects new_navigation_does_not_restore
  it('navigate 로 새 항목에 가면 복원하지 않는다', async () => {
    subscribeScreen(router, () => { page.height = 5000; });
    page.height = 5000;
    userScrollTo(1200);

    router.navigate('/shop/products/P0000DTB');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));
    await new Promise((r) => setTimeout(r, 150));

    expect(scrollToSpy).not.toHaveBeenCalled();
    expect(getScrollRecord('url:/')?.y).toBe(1200);
  });

  // @scenario entry=popstate, content_timing=grows_late, user_input=during_restore
  // @effects user_input_stops_restore
  it('복원을 기다리는 중 사용자가 화면을 만지면 복원을 멈춘다', async () => {
    subscribeScreen(router, (path) => {
      page.height = 1500;
      if (path === '/') {
        timers.push(setTimeout(() => { page.height = 5000; }, 300));
      }
    });
    page.height = 5000;
    userScrollTo(3000);
    router.navigate('/shop/products/P0000DTB');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));
    page.y = 0;

    traverseTo('/');
    await new Promise((r) => setTimeout(r, 100));
    window.dispatchEvent(new Event('touchstart'));
    await new Promise((r) => setTimeout(r, 400));

    expect(page.y).toBeLessThan(3000);
  });

  // @scenario entry=popstate, content_timing=never_tall_enough, config_layer=engine_default
  // @effects unreachable_target_clamps_to_max
  it('최대 대기 안에 높이가 모자라면 닿을 수 있는 가장 아래에 둔다', async () => {
    subscribeScreen(router, () => { page.height = 2000; }, { timeout_ms: 400 });
    page.height = 5000;
    userScrollTo(3000);
    router.navigate('/shop/products/P0000DTB');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));

    traverseTo('/');

    await vi.waitFor(() => expect(page.y).toBe(2000 - 844), { timeout: 2000 });
  });

  // @scenario entry=reload, content_timing=grows_late, config_layer=engine_default
  // @effects reload_restores_from_session_storage
  it('새로고침 전에 기억한 위치를 새로고침 뒤 첫 화면에서 되돌린다', async () => {
    page.height = 5000;
    userScrollTo(2200);
    window.dispatchEvent(new Event('pagehide'));

    // 새로고침: 메모리 기억은 사라지고 sessionStorage 만 남는다, 첫 진입 종류 reload
    const stored = window.sessionStorage.getItem('g7_scroll_positions');
    resetScrollMemoryForTesting();
    window.sessionStorage.setItem('g7_scroll_positions', stored ?? '');
    page.y = 0;
    page.height = 900;
    vi.spyOn(performance, 'getEntriesByType').mockReturnValue([{ type: 'reload' } as any]);
    router = createRouter();
    subscribeScreen(router, () => {
      timers.push(setTimeout(() => { page.height = 5000; }, 100));
    });

    await router.navigateToCurrentPath();

    await vi.waitFor(() => expect(page.y).toBe(2200), { timeout: 3000 });
  });

  // @scenario entry=reload, content_timing=already_tall, config_layer=layout_meta
  // @effects on_reload_false_starts_at_top
  it('on_reload 가 꺼진 화면은 새로고침 뒤 맨 위에서 시작한다', async () => {
    page.height = 5000;
    userScrollTo(2200);
    window.dispatchEvent(new Event('pagehide'));
    const stored = window.sessionStorage.getItem('g7_scroll_positions');
    resetScrollMemoryForTesting();
    window.sessionStorage.setItem('g7_scroll_positions', stored ?? '');
    page.y = 700;
    vi.spyOn(performance, 'getEntriesByType').mockReturnValue([{ type: 'reload' } as any]);
    router = createRouter();
    subscribeScreen(router, () => { page.height = 5000; }, { on_reload: false });

    await router.navigateToCurrentPath();

    await vi.waitFor(() => expect(page.y).toBe(0), { timeout: 2000 });
  });

  // @scenario entry=popstate, content_timing=already_tall, config_layer=navigate_return_scroll
  // @effects return_scroll_overrides_screen_setting
  it('이동 액션의 returnScroll:"top" 으로 떠난 화면에 돌아오면 맨 위에서 시작한다', async () => {
    subscribeScreen(router, () => { page.height = 5000; });
    window.history.replaceState({}, '', '/checkout');
    page.height = 5000;
    userScrollTo(1800);

    runWithReturnScroll('top', () => router.navigate('/done'));
    await vi.waitFor(() => expect(window.location.pathname).toBe('/done'));
    page.y = 400;

    traverseTo('/checkout');

    await vi.waitFor(() => expect(page.y).toBe(0), { timeout: 2000 });
    expect(getScrollRecord('url:/checkout')?.r).toBe('top');
  });

  // @scenario entry=popstate, content_timing=already_tall, config_layer=navigate_return_scroll
  // @effects return_scroll_overrides_screen_setting
  it('returnScroll 은 그 이동 한 번만의 것이다 — 같은 화면을 다음에 일반 이동으로 떠났다 돌아오면 기억한 위치로 복원한다', async () => {
    subscribeScreen(router, () => { page.height = 5000; });
    window.history.replaceState({}, '', '/checkout');
    page.height = 5000;
    userScrollTo(1800);
    runWithReturnScroll('top', () => router.navigate('/done'));
    await vi.waitFor(() => expect(window.location.pathname).toBe('/done'));
    traverseTo('/checkout');
    await vi.waitFor(() => expect(page.y).toBe(0), { timeout: 2000 });

    // 같은 화면에서 이번에는 returnScroll 없이 떠난다
    await new Promise((resolve) => setTimeout(resolve, 700));
    userScrollTo(1500);
    router.navigate('/shop/products/7');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/7'));
    page.y = 300;

    traverseTo('/checkout');

    await vi.waitFor(() => expect(page.y).toBe(1500), { timeout: 2000 });
    expect(getScrollRecord('url:/checkout')?.r).toBeUndefined();
  });

  // @scenario entry=navigate, content_timing=already_tall, config_layer=navigate_return_scroll
  // @effects return_scroll_not_attached_when_leave_guard_blocks
  it('이탈 가드에서 머무르기를 고르면 returnScroll 을 붙이지 않는다', async () => {
    subscribeScreen(router, () => { page.height = 5000; });
    const engine = new DataBindingEngine();
    configureLeaveGuard({
      getLayoutJson: () => ({ meta: { leave_guard: '{{true}}' }, components: [] }),
      getDataContext: () => ({ route: {}, query: {} }),
      getBindingEngine: () => engine,
      translate: (key: string) => key,
    });
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    try {
      const started = runWithReturnScroll('top', () => router.navigate('/done'));

      expect(started).toBe(false);
      expect(window.location.pathname).toBe('/');
      expect(getScrollRecord('url:/')?.r).toBeUndefined();
    } finally {
      configureLeaveGuard(null);
      resetLeaveGuardForTests();
    }
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
  // @effects selector_mode_scrolls_to_element
  it('mode 가 선택자면 그 요소가 생길 때까지 기다려 그 요소로 간다', async () => {
    const intoView = vi.fn();
    subscribeScreen(router, (path) => {
      page.height = 5000;
      if (path === '/') {
        timers.push(setTimeout(() => {
          const el = document.createElement('section');
          el.id = 'list';
          el.scrollIntoView = intoView;
          document.body.appendChild(el);
        }, 150));
      }
    }, { mode: '#list' });
    page.height = 5000;
    userScrollTo(900);
    router.navigate('/shop/products/P0000DTB');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));

    traverseTo('/');

    await vi.waitFor(() => expect(intoView).toHaveBeenCalledTimes(1), { timeout: 2000 });
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
  // @effects container_scroll_restored
  it('containers 로 지정한 내부 스크롤 영역도 떠날 때 위치로 되돌린다', async () => {
    const panel = document.createElement('div');
    panel.id = 'admin-main';
    Object.defineProperty(panel, 'scrollHeight', { configurable: true, value: 3000 });
    Object.defineProperty(panel, 'clientHeight', { configurable: true, value: 600 });
    document.body.appendChild(panel);
    subscribeScreen(router, () => { page.height = 900; }, { containers: ['#admin-main'] });
    // 첫 화면의 내부 영역 등록 (TemplateApp 은 레이아웃을 받은 뒤 등록한다)
    setActiveScrollContainers(['#admin-main']);
    panel.scrollTop = 1234;

    router.navigate('/shop/products/P0000DTB');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));
    panel.scrollTop = 0;

    traverseTo('/');

    await vi.waitFor(() => expect(panel.scrollTop).toBe(1234), { timeout: 2000 });
  });

  // @scenario entry=popstate, content_timing=already_tall, config_layer=editor_mode
  // @effects editor_mode_disables_restore
  it('레이아웃 편집기 편집 모드에서는 복원하지 않는다', async () => {
    subscribeScreen(router, () => { page.height = 5000; });
    page.height = 5000;
    userScrollTo(2000);
    router.navigate('/shop/products/P0000DTB');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));
    page.y = 0;
    setScrollMemoryDisabled(true);
    scrollToSpy.mockClear();

    traverseTo('/');
    await new Promise((r) => setTimeout(r, 300));

    expect(scrollToSpy).not.toHaveBeenCalled();
    expect(page.y).toBe(0);
    // 끈 동안은 브라우저 기본 복원으로 되돌린다
    expect(window.history.scrollRestoration).toBe('auto');
    setScrollMemoryDisabled(false);
    expect(window.history.scrollRestoration).toBe('manual');
  });

  // @scenario entry=popstate, content_timing=already_tall, config_layer=template_config
  // @effects config_layers_merge_narrower_wins
  it('설정은 엔진 기본값 → 템플릿 → 레이아웃 순으로 덮어쓰고, 적지 않은 키는 앞 값을 유지한다', () => {
    const merged = mergeScrollRestorationConfig(
      { mode: 'top', timeout_ms: 8000, snapshot: { ttl_seconds: 60 } },
      { mode: 'restore', containers: ['#main'], snapshot: { revalidate: false } },
      null,
      'invalid',
    );

    expect(merged).toEqual({
      mode: 'restore',
      on_reload: true,
      wait: 'height',
      timeout_ms: 8000,
      containers: ['#main'],
      snapshot: { enabled: true, ttl_seconds: 60, revalidate: false, item_key: 'id', state: [] },
    });
  });

  // @scenario entry=popstate, content_timing=already_tall, config_layer=template_config
  // @effects invalid_mode_ignored_with_warning
  it('mode 가 정해진 값(restore·top·none)도 선택자(#·.·[ 로 시작)도 아니면 버리고 앞 층의 값을 쓴다', () => {
    expect(mergeScrollRestorationConfig({ mode: 'Restore' }).mode).toBe('restore');
    expect(mergeScrollRestorationConfig({ mode: 'top' }, { mode: 'auto' }).mode).toBe('top');
    expect(mergeScrollRestorationConfig({ mode: '#product-list' }).mode).toBe('#product-list');
    expect(mergeScrollRestorationConfig({ mode: '.card' }).mode).toBe('.card');
    expect(mergeScrollRestorationConfig({ mode: '[data-anchor="a"]' }).mode).toBe('[data-anchor="a"]');
  });

  /** 화면 처리 흉내 — 첫 렌더 뒤 `delayMs` 가 지나서야 복원을 시작한다(데이터 기준 대기 화면) */
  const subscribeDelayed = (delayMs: number): void => {
    router.on('routeChange', () => {
      const ticket = takeScrollRestoreTicket();
      const config = mergeScrollRestorationConfig();
      page.height = 5000;
      if (ticket) {
        timers.push(setTimeout(() => { void runScrollRestore(ticket, config); }, delayMs));
      }
    });
  };

  /** /checkout 을 1,800 까지 내렸다가 /done 으로 떠났다가 뒤로 */
  const leaveCheckoutAndTraverseBack = async (): Promise<void> => {
    window.history.replaceState({}, '', '/checkout');
    page.height = 5000;
    userScrollTo(1800);
    router.navigate('/done');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/done'));
    page.y = 0;
    traverseTo('/checkout');
  };

  // @scenario entry=popstate, content_timing=grows_late, config_layer=engine_default
  // @effects user_input_before_restore_skips_restore
  it('뒤로 간 뒤 복원이 시작되기 전에 사용자가 손으로 스크롤하면, 복원하지 않고 사용자가 둔 위치를 기억한다', async () => {
    subscribeDelayed(300);
    await leaveCheckoutAndTraverseBack();

    await new Promise((r) => setTimeout(r, 80));
    window.dispatchEvent(new Event('wheel'));
    userScrollTo(600);
    await new Promise((r) => setTimeout(r, 700));

    expect(page.y).toBe(600);
    expect(getScrollRecord('url:/checkout')?.y).toBe(600);
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=engine_default
  // @effects screen_scroll_action_before_restore_skips_restore
  it('복원이 시작되기 전에 화면이 스크롤 액션(scrollIntoView)을 실행하면 복원하지 않는다', async () => {
    subscribeDelayed(300);
    const target = document.createElement('div');
    target.id = 'review-section';
    (target as any).scrollIntoView = vi.fn(() => { page.y = 700; });
    document.body.appendChild(target);
    const dispatcher = new ActionDispatcher({ navigate: vi.fn() } as any);
    await leaveCheckoutAndTraverseBack();

    await new Promise((r) => setTimeout(r, 80));
    await dispatcher.executeAction({ type: 'click', handler: 'scrollIntoView', params: { selector: '#review-section', behavior: 'instant' } } as any, {} as any);
    await new Promise((r) => setTimeout(r, 700));

    expect(page.y).toBe(700);
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=engine_default
  // @effects screen_scroll_action_during_restore_stops_restore
  it('복원 중에 화면이 스크롤 액션을 실행하면 복원을 멈추고 화면이 옮긴 위치를 둔다', async () => {
    subscribeScreen(router, () => {
      page.height = 900;
      timers.push(setTimeout(() => { page.height = 5000; }, 400));
    });
    const target = document.createElement('div');
    target.id = 'review-section';
    (target as any).scrollIntoView = vi.fn(() => { page.y = 700; });
    document.body.appendChild(target);
    const dispatcher = new ActionDispatcher({ navigate: vi.fn() } as any);
    window.history.replaceState({}, '', '/checkout');
    page.height = 5000;
    userScrollTo(1800);
    router.navigate('/done');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/done'));
    page.y = 0;
    traverseTo('/checkout');

    // 복원 중(문서가 아직 짧아 1,800 에 닿지 못함)에 화면이 리뷰 영역으로 옮긴다
    await new Promise((r) => setTimeout(r, 100));
    await dispatcher.executeAction({ type: 'click', handler: 'scrollIntoView', params: { selector: '#review-section', behavior: 'instant' } } as any, {} as any);
    await new Promise((r) => setTimeout(r, 900));

    expect(page.y).toBe(700);
  });
});
