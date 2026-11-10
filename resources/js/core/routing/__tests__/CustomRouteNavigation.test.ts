/**
 * custom 주소(routes/custom.php) 전체 페이지 이동 테스트 (#792 W6 · engine-v1.91.0)
 *
 * 엔진 화면 안에서 서버의 개발자 화면 주소로 이동하면 엔진이 `pushState` 로 직접 그리지 않고 `location.assign` 으로
 * 서버에 넘기는지, 뒤로·앞으로 가기(`popstate`)로 그 주소에 오면 `location.reload` 로 서버가 다시 그리는지,
 * 패턴이 없거나 맞지 않으면 종전과 완전히 같은지(pushState + 엔진 렌더) 잠근다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Router } from '../Router';
import { isCustomRoutePath, normalizeCustomRoutePath } from '../CustomRouteMatcher';
import { configureLeaveGuard, resetLeaveGuardForTests, shouldConfirmLeave } from '../LeaveGuard';
import { DataBindingEngine } from '../../template-engine/DataBindingEngine';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: { getInstance: vi.fn() },
}));

describe('CustomRouteMatcher', () => {
  const patterns = { user: ['/board/*', '/board/*/*', '/custom-jquery-example', '/게시판/*'], admin: ['/admin/custom-example'] };

  /**
   * @scenario case=matcher
   * @effects star_matches_one_segment_scoped
   */
  it('`*` 는 한 구간(1글자 이상)과 맞고 범위는 /admin 접두로 고른다', () => {
    expect(isCustomRoutePath('/board/free', patterns)).toBe(true);
    expect(isCustomRoutePath('/board/free/12', patterns)).toBe(true);
    expect(isCustomRoutePath('/board/free/12/edit', patterns)).toBe(false);
    expect(isCustomRoutePath('/board/', patterns)).toBe(false);
    expect(isCustomRoutePath('/boards', patterns)).toBe(false);
    expect(isCustomRoutePath('/admin/custom-example', patterns)).toBe(true);
    expect(isCustomRoutePath('/custom-example', patterns)).toBe(false);
    expect(isCustomRoutePath('/admin/board/free', patterns)).toBe(false);
  });

  /**
   * @scenario case=matcher
   * @effects path_normalized
   */
  it('쿼리·해시·끝 슬래시·퍼센트 인코딩(한글)을 정규화한다', () => {
    expect(isCustomRoutePath('/board/free?page=2#top', patterns)).toBe(true);
    expect(isCustomRoutePath('/board/free/', patterns)).toBe(true);
    expect(isCustomRoutePath('/%EA%B2%8C%EC%8B%9C%ED%8C%90/%EC%9E%90%EC%9C%A0', patterns)).toBe(true);
    expect(normalizeCustomRoutePath('/a/b/?x=1')).toBe('/a/b');
  });

  /**
   * @scenario case=matcher
   * @effects route_constraints_respected
   */
  it('라우트 제약 `{정규식}` 구간은 그 구간 전체가 맞아야 하고 다음 구간으로 넘지 않는다', () => {
    const constrained = { user: ['/board/*', '/board/*/{[0-9]+}', '/y/{[0-9]{4}}', '/any/{.+}'] };
    expect(isCustomRoutePath('/board/free/12', constrained)).toBe(true);
    expect(isCustomRoutePath('/board/free/write', constrained)).toBe(false);
    expect(isCustomRoutePath('/board/free/12/edit', constrained)).toBe(false);
    expect(isCustomRoutePath('/y/2026', constrained)).toBe(true);
    expect(isCustomRoutePath('/y/26', constrained)).toBe(false);
    expect(isCustomRoutePath('/any/a', constrained)).toBe(true);
    expect(isCustomRoutePath('/any/a/b', constrained)).toBe(false);
  });

  /**
   * @scenario case=matcher
   * @effects foreign_origin_and_no_patterns_false
   */
  it('다른 출처 절대 URL 과 패턴 없음은 false', () => {
    expect(isCustomRoutePath('https://evil.example.test/board/free', patterns)).toBe(false);
    expect(isCustomRoutePath('/board/free', null)).toBe(false);
    expect(isCustomRoutePath('/board/free', { user: [], admin: [] })).toBe(false);
  });
});

describe('Router — custom 주소 전체 페이지 이동', () => {
  let router: Router;
  let assign: ReturnType<typeof vi.fn>;
  let reload: ReturnType<typeof vi.fn>;
  let pushState: ReturnType<typeof vi.spyOn>;
  const originalLocation = window.location;

  beforeEach(() => {
    assign = vi.fn();
    reload = vi.fn();
    delete (window as any).location;
    (window as any).location = {
      assign,
      reload,
      href: 'http://localhost/',
      pathname: '/',
      search: '',
      hash: '',
      origin: 'http://localhost',
    };
    pushState = vi.spyOn(window.history, 'pushState').mockImplementation(() => undefined);
    (window as any).G7Config = { customRoutes: { user: ['/board/*', '/board/*/*'], admin: [] } };
    router = new Router('sirsoft-basic');
  });

  afterEach(() => {
    // 시험마다 만든 라우터의 popstate 구독을 걷는다 — 남으면 다음 시험의 popstate 가 옛 라우터에도 간다
    window.removeEventListener('popstate', (router as any).handlePopState);
    pushState.mockRestore();
    delete (window as any).G7Config;
    (window as any).location = originalLocation;
  });

  /**
   * @scenario case=navigate
   * @effects custom_navigate_full_page
   */
  it('custom 주소로 navigate 하면 location.assign 하고 pushState·엔진 렌더를 하지 않는다', async () => {
    const routeChange = vi.fn();
    router.on('routeChange', routeChange);

    router.navigate('/board/free');
    await Promise.resolve();

    expect(assign).toHaveBeenCalledWith('/board/free');
    expect(pushState).not.toHaveBeenCalled();
    expect(routeChange).not.toHaveBeenCalled();
  });

  /**
   * @scenario case=navigate
   * @effects non_custom_navigate_unchanged
   */
  it('맞지 않는 주소는 종전대로 pushState + 엔진 렌더', async () => {
    const spy = vi.spyOn(router, 'navigateToCurrentPath').mockResolvedValue(undefined);

    router.navigate('/shop/products');
    await Promise.resolve();

    expect(assign).not.toHaveBeenCalled();
    expect(pushState).toHaveBeenCalledWith({}, '', '/shop/products');
    expect(spy).toHaveBeenCalled();
  });

  /**
   * @scenario case=navigate
   * @effects no_patterns_unchanged
   */
  it('패턴이 없으면(G7Config.customRoutes 없음) 종전과 같다', async () => {
    delete (window as any).G7Config;
    const spy = vi.spyOn(router, 'navigateToCurrentPath').mockResolvedValue(undefined);

    router.navigate('/board/free');
    await Promise.resolve();

    expect(assign).not.toHaveBeenCalled();
    expect(pushState).toHaveBeenCalledWith({}, '', '/board/free');
    expect(spy).toHaveBeenCalled();
  });

  /**
   * @scenario case=popstate
   * @effects custom_popstate_reloads
   */
  it('뒤로·앞으로 가기로 custom 주소에 오면 location.reload, 아니면 엔진 렌더', () => {
    const spy = vi.spyOn(router, 'navigateToCurrentPath').mockResolvedValue(undefined);

    (window as any).location.pathname = '/board/free/3';
    window.dispatchEvent(new PopStateEvent('popstate'));
    expect(reload).toHaveBeenCalledTimes(1);
    expect(spy).not.toHaveBeenCalled();

    (window as any).location.pathname = '/shop/products';
    window.dispatchEvent(new PopStateEvent('popstate'));
    expect(reload).toHaveBeenCalledTimes(1);
    expect(spy).toHaveBeenCalledTimes(1);
  });

  /**
   * 이탈 가드(engine-v1.82.0)와 custom 주소 재적재(engine-v1.91.0)가 같은 popstate 에서 만나는 순서 —
   * `navigate` 와 같이 가드를 먼저 묻고, 머무르기면 다시 적재하지 않고 확정 주소를 다시 쌓는다.
   * 떠나기를 고르면 재적재하며, 이미 허락했으므로 beforeunload 가 다시 묻지 않는다(develop-7.2.0 병합 2026-10-21).
   *
   * @scenario case=popstate
   * @effects custom_popstate_respects_leave_guard
   */
  it('이탈 가드가 참이면 custom 주소 popstate 도 먼저 묻고, 머무르면 재적재하지 않는다', () => {
    const engine = new DataBindingEngine();
    configureLeaveGuard({
      getLayoutJson: () => ({ layout_name: 'board/form', meta: { leave_guard: '{{_local.hasChanges === true}}' }, components: [] }),
      getDataContext: () => ({ route: {}, query: {} }),
      getBindingEngine: () => engine,
      translate: (key: string) => key,
    });
    (window as any).G7Core = { state: { get: () => ({ _local: { hasChanges: true } }), getLocal: () => ({ hasChanges: true }) } };
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const spy = vi.spyOn(router, 'navigateToCurrentPath').mockResolvedValue(undefined);

    try {
      (window as any).location.pathname = '/board/free/3';
      window.dispatchEvent(new PopStateEvent('popstate'));
      expect(confirmSpy).toHaveBeenCalledTimes(1);
      expect(reload).not.toHaveBeenCalled();
      expect(pushState).toHaveBeenCalledTimes(1);
      expect(spy).not.toHaveBeenCalled();

      confirmSpy.mockReturnValue(true);
      window.dispatchEvent(new PopStateEvent('popstate'));
      expect(confirmSpy).toHaveBeenCalledTimes(2);
      expect(reload).toHaveBeenCalledTimes(1);
      expect(shouldConfirmLeave()).toBe(false);
    } finally {
      confirmSpy.mockRestore();
      resetLeaveGuardForTests();
      delete (window as any).G7Core;
    }
  });
});
