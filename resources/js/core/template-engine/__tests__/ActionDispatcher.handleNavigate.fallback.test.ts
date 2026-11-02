/**
 * ActionDispatcher handleNavigate fallback 테스트
 *
 * 미등록 라우트로 navigate 시 fallback 핸들러(기본 openWindow)로 분기 검증.
 *
 * @since engine-v1.40.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';
import { Router } from '../../routing/Router';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn(),
      logout: vi.fn(),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('ActionDispatcher - handleNavigate fallback (engine-v1.40.0)', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn<(path: string, options?: unknown) => void>>;
  let mockMatch: ReturnType<typeof vi.fn>;
  let mockWindowOpen: ReturnType<typeof vi.fn>;
  let mockLocationAssign: ReturnType<typeof vi.fn>;
  let originalWindowOpen: typeof window.open;
  let originalLocationAssign: typeof window.location.assign;

  beforeEach(() => {
    mockNavigate = vi.fn<(path: string, options?: unknown) => void>();
    dispatcher = new ActionDispatcher({ navigate: mockNavigate });
    Logger.getInstance().setDebug(false);

    mockMatch = vi.fn();
    (window as any).__templateApp = {
      getRouter: () => ({
        match: mockMatch,
      }),
    };

    originalWindowOpen = window.open;
    mockWindowOpen = vi.fn();
    window.open = mockWindowOpen as any;

    // window.location.assign 모킹 — fallback 기본값(_self)이 `location.assign` 을 호출.
    // jsdom 의 location 객체는 spyOn/defineProperty 가 모두 잠겨 있으므로
    // delete + 객체 교체 패턴으로 우회.
    originalLocationAssign = window.location.assign;
    mockLocationAssign = vi.fn();
    delete (window as any).location;
    (window as any).location = {
      assign: mockLocationAssign,
      href: '',
      pathname: '',
      search: '',
      hash: '',
      origin: 'http://localhost',
    };
  });

  afterEach(() => {
    window.open = originalWindowOpen;
    void originalLocationAssign;
    delete (window as any).__templateApp;
    vi.clearAllMocks();
  });

  // @scenario route=matched, fallback=default
  // @effects matched_route_navigates_in_app
  it('라우트 매칭 성공 시 정상 navigate — fallback 미호출', async () => {
    mockMatch.mockReturnValue({ route: { path: '/admin/users' }, params: {} });

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/admin/users' },
    });

    expect(mockMatch).toHaveBeenCalledWith('/admin/users');
    expect(mockNavigate).toHaveBeenCalledWith('/admin/users', { replace: false });
    expect(mockWindowOpen).not.toHaveBeenCalled();
  });

  // @scenario route=unmatched, fallback=default
  // @effects unmatched_route_defaults_to_same_tab_window
  it('라우트 매칭 실패 + fallback 미지정 → 기본 openWindow target=_self → window.location.assign 호출 (admin↔user 교차 이동 흐름 보존)', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/shop/orders/123' },
    });

    expect(mockNavigate).not.toHaveBeenCalled();
    // 기본값 _self 로 같은 탭 이동 (ActionDispatcher.handleOpenWindow + resolveNavigateFallback 본문 주석 참조).
    // _self 는 window.open 대신 window.location.assign 을 호출한다.
    expect(mockLocationAssign).toHaveBeenCalledWith('/shop/orders/123');
    expect(mockWindowOpen).not.toHaveBeenCalled();
  });

  // @scenario route=unmatched, fallback=disabled
  // @effects fallback_false_forces_in_app_navigate
  it('라우트 매칭 실패 + fallback: false → 기존 동작(navigate 강행)', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/shop/orders/123', fallback: false },
    });

    expect(mockNavigate).toHaveBeenCalledWith('/shop/orders/123', { replace: false });
    expect(mockWindowOpen).not.toHaveBeenCalled();
  });

  // @scenario route=unmatched, fallback=handler_name
  // @effects fallback_handler_receives_path
  it('라우트 매칭 실패 + fallback: string → 해당 핸들러로 분기', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/shop/orders/123', fallback: 'openWindow' },
    });

    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockWindowOpen).toHaveBeenCalledWith('/shop/orders/123', '_blank', 'noopener');
  });

  // @scenario route=unmatched, fallback=handler_object
  // @effects fallback_handler_receives_path
  it('라우트 매칭 실패 + fallback: { handler, params } → 상세 지정 동작', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: {
        path: '/shop/orders/123',
        fallback: { handler: 'openWindow', params: { target: '_blank' } },
      },
    });

    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockWindowOpen).toHaveBeenCalledWith('/shop/orders/123', '_blank', 'noopener');
  });

  // @scenario route=unmatched, fallback=default
  // @effects route_match_uses_pathname_only
  it('쿼리 파라미터가 있는 경로도 fallback pathname만 매칭 → _self 기본값으로 location.assign 호출', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: {
        path: '/shop/orders',
        query: { id: '123', tab: 'detail' },
      },
    });

    expect(mockMatch).toHaveBeenCalledWith('/shop/orders');
    expect(mockNavigate).not.toHaveBeenCalled();
    // _self 기본값 — query 병합된 finalPath 가 location.assign 으로 전달됨
    expect(mockLocationAssign).toHaveBeenCalled();
    const openedUrl = mockLocationAssign.mock.calls[0][0];
    expect(openedUrl).toContain('/shop/orders?');
    expect(openedUrl).toContain('id=123');
    expect(openedUrl).toContain('tab=detail');
  });

  // @scenario route=replace_only, fallback=default
  // @effects replace_navigation_skips_fallback
  it('replace: true 경로는 fallback 미적용 (쿼리 갱신 전용)', async () => {
    mockMatch.mockReturnValue(null);
    (window as any).G7Core = {
      updateQueryParams: vi.fn().mockResolvedValue(undefined),
    };

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/admin/users', query: { page: 2 }, replace: true },
    });

    // replace 경로는 updateQueryParams 사용 — fallback 미적용
    expect((window as any).G7Core.updateQueryParams).toHaveBeenCalled();
    expect(mockWindowOpen).not.toHaveBeenCalled();

    delete (window as any).G7Core;
  });

  // @scenario route=router_missing, fallback=default
  // @effects missing_router_navigates_in_app
  it('router를 가져올 수 없으면 fallback 미적용 (정상 navigate)', async () => {
    (window as any).__templateApp = undefined;

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/shop/orders/123' },
    });

    expect(mockNavigate).toHaveBeenCalledWith('/shop/orders/123', { replace: false });
    expect(mockWindowOpen).not.toHaveBeenCalled();
  });

  // ── 미등록 라우트 fallback 의 same-origin 게이트 ─────────────────────────────
  //
  // 폴백은 「매치 실패 = 이 템플릿이 모르는 주소 = 바깥으로 내보낸다」로 동작한다.
  // 그런데 라우트 패턴은 전부 `^/…$` 앵커라 **외부 URL 은 구조적으로 매치될 수 없다** —
  // 즉 매치 실패가 방어가 아니라 방아쇠다. 로그인 복귀 주소(`?redirect=`)는 검증 없이
  // 이 자리에 도착하므로(번들 템플릿 3곳), 게이트가 없으면 무인증 오픈 리다이렉트다.
  //
  // 판정은 브라우저 URL 파서와 같은 정규화를 거친다 — 문자열 접두 검사만으로는
  // 백슬래시·탭·개행 우회를 막지 못한다. 과차단도 재는 이유는 경로 중간의 연속
  // 슬래시·백슬래시가 authority 를 만들지 않기 때문이다.
  describe('same-origin 게이트 — 외부 오리진은 fallback 으로 내보내지 않는다', () => {
    const external: ReadonlyArray<readonly [string, string]> = [
      ['scheme 절대 URL', 'https://evil.com/checkout'],
      ['protocol-relative', '//evil.com/checkout'],
      ['백슬래시 authority', '/\\evil.com/checkout'],
      ['백슬래시 2개 authority', '\\\\evil.com/checkout'],
      ['탭 삽입', '/\t/evil.com/checkout'],
      ['개행 삽입', '/\n/evil.com/checkout'],
      ['CR 삽입', '/\r/evil.com/checkout'],
      ['슬래시 3개', '///evil.com/checkout'],
      ['scheme + 슬래시 3개', 'https:///evil.com/checkout'],
      ['앞뒤 공백을 두른 절대 URL', '  https://evil.com/x  '],
    ];

    // @scenario route=unmatched, fallback=default
    // @effects unmatched_fallback_refuses_external_origins
    it.each(external)('%s 은 바깥으로 나가지 않는다', async (_label, path) => {
      mockMatch.mockReturnValue(null);

      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'navigate',
        params: { path },
      });

      expect(mockLocationAssign).not.toHaveBeenCalled();
      expect(mockWindowOpen).not.toHaveBeenCalled();
    });

    // @scenario route=unmatched, fallback=default
    // @effects unmatched_fallback_still_opens_in_site_paths
    it('미등록이지만 사이트 안인 경로는 종전대로 fallback 이 동작한다 (과차단 없음)', async () => {
      mockMatch.mockReturnValue(null);

      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '/admin/users' },
      });

      expect(mockLocationAssign).toHaveBeenCalledWith('/admin/users');
    });

    // @scenario route=unmatched, fallback=default
    // @effects unmatched_fallback_still_opens_in_site_paths
    it('경로 중간의 연속 슬래시는 authority 가 아니므로 통과한다 (과차단 없음)', async () => {
      mockMatch.mockReturnValue(null);

      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '/files/a//b.png' },
      });

      expect(mockLocationAssign).toHaveBeenCalledWith('/files/a//b.png');
    });

    // @scenario route=unmatched, fallback=handler_name
    // @effects open_window_sink_refuses_external_origins
    it('openWindow 를 직접 부르는 경로도 같은 게이트를 지난다', async () => {
      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'openWindow',
        params: { path: 'https://evil.com/x', target: '_self' },
      });

      expect(mockLocationAssign).not.toHaveBeenCalled();
      expect(mockWindowOpen).not.toHaveBeenCalled();
    });

    // @scenario route=unmatched, fallback=handler_name
    // @effects open_window_sink_refuses_external_origins
    it('openWindow 의 새 탭 경로도 같은 게이트를 지난다', async () => {
      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'openWindow',
        params: { path: '//evil.com/x', target: '_blank' },
      });

      expect(mockWindowOpen).not.toHaveBeenCalled();
      expect(mockLocationAssign).not.toHaveBeenCalled();
    });
  });

  // ── 사이트 밖 목적지는 라우트 대조 전에 미등록으로 본다 (실제 Router) ─────────────
  //
  // 라우터 대조는 연속 슬래시를 하나로 접는다(engine-v1.84.0). 그래서 `//evil.example/board/free` 가
  // `/evil.example/board/free` 로 접혀 `*/board/:slug`(언어 접두 자리에 `/evil.example`)에 **맞아 버리고**,
  // 미등록 폴백(같은 출처 게이트)을 건너뛰어 SPA 이동으로 갔다 — 브라우저는 `pushState('//evil.example/…')`
  // 를 교차 출처로 막아 SecurityError 가 처리되지 않은 채 남았다. 위 블록은 `match` 를 null 로 모의해
  // 이 갈래를 밟지 못하므로 여기서는 실제 Router 로 대조한다.
  describe('사이트 밖 목적지 — 실제 Router 대조에서도 같은 출처 게이트로 간다', () => {
    let router: Router;

    beforeEach(() => {
      router = new Router('test-template');
      router.setRoutes([
        { path: '*/board/:slug', layout: 'board_index' },
        { path: '*/admin/users', layout: 'admin_users' },
      ]);
      (window as any).__templateApp = { getRouter: () => router };
    });

    afterEach(() => {
      delete (window as any).G7Config;
    });

    const offsite: ReadonlyArray<readonly [string, string]> = [
      ['protocol-relative', '//evil.example/board/free'],
      ['슬래시 3개', '///evil.example/board/free'],
      ['백슬래시 authority', '/\\evil.example/board/free'],
      ['탭 삽입', '/\t/board/free'],
      ['쿼리를 단 protocol-relative', '//evil.example/board/free?x=1'],
    ];

    // @scenario route=matched, fallback=default
    // @effects offsite_target_skips_route_match
    it.each(offsite)('%s 은 라우트에 접혀 맞아도 SPA 이동하지 않고 폴백 게이트에서 거부된다', async (_label, path) => {
      // 전제: 실제 라우터는 이 주소를 라우트에 맞춘다(연속 슬래시 접기) — 이 시험이 밟는 갈래
      if (path.startsWith('//')) {
        expect(router.match(path.split('?')[0])).not.toBeNull();
      }

      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'navigate',
        params: { path },
      });

      expect(mockNavigate).not.toHaveBeenCalled();
      expect(mockLocationAssign).not.toHaveBeenCalled();
      expect(mockWindowOpen).not.toHaveBeenCalled();
    });

    // @scenario route=unmatched, fallback=disabled
    // @effects offsite_target_refused_without_fallback
    it('fallback: false 여도 사이트 밖 목적지로 SPA 이동하지 않는다', async () => {
      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '//evil.example/board/free', fallback: false },
      });

      expect(mockNavigate).not.toHaveBeenCalled();
      expect(mockLocationAssign).not.toHaveBeenCalled();
    });

    // @scenario route=router_missing, fallback=default
    // @effects offsite_target_refused_without_fallback
    it('라우터가 없는 단계에서도 사이트 밖 목적지로 SPA 이동하지 않는다', async () => {
      (window as any).__templateApp = undefined;

      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '//evil.example/board/free' },
      });

      expect(mockNavigate).not.toHaveBeenCalled();
      expect(mockLocationAssign).not.toHaveBeenCalled();
    });

    const inSite: ReadonlyArray<readonly [string, string]> = [
      ['정규 경로', '/board/free'],
      ['언어 접두 경로', '/ko/board/free'],
      ['경로 중간 연속 슬래시', '/board//free'],
      ['끝 슬래시', '/board/free/'],
    ];

    // @scenario route=matched, fallback=default
    // @effects matched_route_navigates_in_app
    it.each(inSite)('%s 은 종전대로 SPA 이동한다 (과차단 없음)', async (_label, path) => {
      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'navigate',
        params: { path },
      });

      expect(mockNavigate).toHaveBeenCalledWith(path, { replace: false });
      expect(mockLocationAssign).not.toHaveBeenCalled();
    });

    // @scenario route=matched, fallback=default
    // @effects offsite_target_skips_route_match
    it('하위 폴더 배치에서도 사이트 밖 목적지는 거부되고, 같은 출처 전체 주소는 접두사를 벗겨 SPA 이동한다', async () => {
      (window as any).G7Config = { basePath: '/g7' };

      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '//evil.example/board/free' },
      });
      expect(mockNavigate).not.toHaveBeenCalled();
      expect(mockLocationAssign).not.toHaveBeenCalled();

      await dispatcher.dispatchAction({
        type: 'click',
        handler: 'navigate',
        params: { path: 'http://localhost/g7/board/free' },
      });
      expect(mockNavigate).toHaveBeenCalledWith('/board/free', { replace: false });
    });

    // @scenario route=matched, fallback=default
    // @effects subpath_prefixed_path_strips_mount_prefix
    it('하위 폴더 배치에서 접두사를 품은 루트 상대 경로(`/g7/board/free`)는 벗겨 대조하고 SPA 이동한다 (D1)', async () => {
      (window as any).G7Config = { basePath: '/g7' };

      for (const [path, expected] of [
        ['/g7/board/free', '/board/free'],
        ['/g7/board/free?page=2', '/board/free?page=2'],
        ['/g7/board/free#list', '/board/free#list'],
      ] as const) {
        mockNavigate.mockClear();
        await dispatcher.dispatchAction({ type: 'click', handler: 'navigate', params: { path } });
        expect(mockNavigate).toHaveBeenCalledWith(expected, { replace: false });
      }
      expect(mockLocationAssign).not.toHaveBeenCalled();
      expect(mockWindowOpen).not.toHaveBeenCalled();
    });

    // @scenario route=matched, fallback=default
    // @effects subpath_prefixed_path_strips_mount_prefix
    it('접두사 경계가 아닌 경로(`/g7abc/…`)·이미 벗겨진 경로·루트 설치는 벗기지 않는다 (D1)', async () => {
      (window as any).G7Config = { basePath: '/g7' };
      await dispatcher.dispatchAction({ type: 'click', handler: 'navigate', params: { path: '/g7abc/board/free' } });
      expect(mockNavigate).toHaveBeenLastCalledWith('/g7abc/board/free', { replace: false });
      await dispatcher.dispatchAction({ type: 'click', handler: 'navigate', params: { path: '/board/free' } });
      expect(mockNavigate).toHaveBeenLastCalledWith('/board/free', { replace: false });

      delete (window as any).G7Config;
      await dispatcher.dispatchAction({ type: 'click', handler: 'navigate', params: { path: '/g7/board/free' } });
      expect(mockNavigate).toHaveBeenLastCalledWith('/g7/board/free', { replace: false });
    });

    // @scenario route=matched, fallback=default
    // @effects offsite_target_skips_route_match
    it('접두사를 벗긴 결과가 `//host` 이면 사이트 밖 판정이 이동을 막는다(같은 출처 전체 주소와 같은 판정) (D1)', async () => {
      (window as any).G7Config = { basePath: '/g7' };
      await dispatcher.dispatchAction({ type: 'click', handler: 'navigate', params: { path: '/g7//evil.example/board/free' } });
      expect(mockNavigate).not.toHaveBeenCalled();
      expect(mockLocationAssign).not.toHaveBeenCalled();
      expect(mockWindowOpen).not.toHaveBeenCalled();
    });
  });
});
