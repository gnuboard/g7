import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Router } from '../Router';
import { AuthManager } from '../../auth/AuthManager';

// fetch 모킹
global.fetch = vi.fn();

// AuthManager 모킹
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(),
  },
}));

describe('Router', () => {
  let router: Router;

  beforeEach(() => {
    router = new Router('sirsoft-admin_basic');
    vi.clearAllMocks();
  });

  describe('loadRoutes', () => {
    // @scenario entry=initial
    // @effects routes_loaded_from_api_with_cache_version
    it('API에서 라우트 목록을 성공적으로 로드해야 합니다', async () => {
      const mockRoutes = [
        { path: '/admin', layout: 'dashboard' },
        { path: '/admin/users/:id', layout: 'user-detail' },
      ];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes();

      expect(global.fetch).toHaveBeenCalledWith('/api/templates/sirsoft-admin_basic/routes.json');
      expect(router.getRoutes()).toEqual(mockRoutes);
    });

    // @since engine-v1.19.0
    // @scenario entry=initial
    // @effects routes_loaded_from_api_with_cache_version
    it('cacheVersion 전달 시 ?v= 쿼리 파라미터를 URL에 부착해야 합니다', async () => {
      const mockRoutes = [{ path: '/admin', layout: 'dashboard' }];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes(1712345678);

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/templates/sirsoft-admin_basic/routes.json?v=1712345678'
      );
      expect(router.getRoutes()).toEqual(mockRoutes);
    });

    // @since engine-v1.19.0
    // @scenario entry=initial
    // @effects routes_loaded_from_api_with_cache_version
    it('cacheVersion 이 0 또는 undefined 이면 쿼리 파라미터 없이 호출해야 합니다', async () => {
      const mockRoutes = [{ path: '/admin', layout: 'dashboard' }];

      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes(0);
      expect(global.fetch).toHaveBeenLastCalledWith('/api/templates/sirsoft-admin_basic/routes.json');

      await router.loadRoutes(undefined);
      expect(global.fetch).toHaveBeenLastCalledWith('/api/templates/sirsoft-admin_basic/routes.json');
    });

    // @scenario entry=initial
    // @effects routes_loaded_from_api_with_cache_version
    it('API 호출 실패 시 에러를 던져야 합니다', async () => {
      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        statusText: 'Not Found',
      });

      await expect(router.loadRoutes()).rejects.toThrow('Failed to load routes: Not Found');
    });

    // @scenario entry=initial
    // @effects routes_loaded_from_api_with_cache_version
    it('success가 false일 때 에러를 던져야 합니다', async () => {
      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: false,
          data: null,
        }),
      });

      await expect(router.loadRoutes()).rejects.toThrow('Failed to load routes from API');
    });

    // @scenario entry=initial
    // @effects routes_loaded_from_api_with_cache_version
    it('잘못된 데이터 형식 시 에러를 던져야 합니다', async () => {
      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0' }, // routes 배열이 없음
        }),
      });

      await expect(router.loadRoutes()).rejects.toThrow('Invalid routes data format');
    });
  });

  describe('match', () => {
    beforeEach(async () => {
      const mockRoutes = [
        { path: '/admin', layout: 'dashboard' },
        { path: '/admin/users', layout: 'user-list' },
        { path: '/admin/users/:id', layout: 'user-detail' },
        { path: '/admin/files/*', layout: 'file-browser' },
        { path: '/admin/posts/:postId/comments/:commentId', layout: 'comment-detail' },
      ];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes();
    });

    // @scenario path_shape=plain
    // @effects route_pattern_matches_and_extracts_params
    it('정확한 경로 매칭을 수행해야 합니다', () => {
      const result = router.match('/admin');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('dashboard');
      expect(result?.params).toEqual({});
    });

    // @scenario path_shape=plain
    // @effects route_pattern_matches_and_extracts_params
    it('동적 파라미터를 추출해야 합니다', () => {
      const result = router.match('/admin/users/123');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('user-detail');
      expect(result?.params).toEqual({ id: '123' });
    });

    // @scenario path_shape=plain
    // @effects route_pattern_matches_and_extracts_params
    it('다중 동적 파라미터를 추출해야 합니다', () => {
      const result = router.match('/admin/posts/456/comments/789');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('comment-detail');
      expect(result?.params).toEqual({
        postId: '456',
        commentId: '789',
      });
    });

    // TODO: 와일드카드 패턴(*)은 아직 미구현 - 현재는 */만 언어 prefix용으로 지원
    // @scenario path_shape=plain
    // @effects route_pattern_matches_and_extracts_params
    it.skip('와일드카드 패턴을 매칭해야 합니다', () => {
      const result = router.match('/admin/files/documents/2024/report.pdf');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('file-browser');
      expect(result?.params).toEqual({});
    });

    // @scenario path_shape=plain
    // @effects route_pattern_matches_and_extracts_params
    it('매칭되지 않는 경로는 null을 반환해야 합니다', () => {
      const result = router.match('/unknown/path');

      expect(result).toBeNull();
    });

    // @scenario path_shape=plain
    // @effects route_pattern_matches_and_extracts_params
    it('부분 매칭은 수행하지 않아야 합니다', () => {
      const result = router.match('/admin/users/123/extra');

      expect(result).toBeNull();
    });

    // @scenario path_shape=plain
    // @effects route_pattern_matches_and_extracts_params
    it('첫 번째로 매칭되는 라우트를 반환해야 합니다', () => {
      // /admin/users는 /admin/users와 /admin/users/:id 둘 다 매칭 가능
      // 하지만 정확히 매칭되는 /admin/users를 먼저 반환해야 함
      const result = router.match('/admin/users');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('user-list');
      expect(result?.params).toEqual({});
    });

    // 브라우저가 끝 슬래시가 붙은 경로(`/admin/`)로 진입해도, 끝 슬래시 없는
    // 라우트 패턴(`/admin`)에 매칭되어야 한다. 미정규화 시 어떤 라우트에도
    // 매칭되지 않아 404 로 떨어지고, 미인증 진입 시 로그인 리다이렉트 대신
    // 404 가 노출되는 회귀가 발생한다.
    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('끝 슬래시가 붙은 경로도 끝 슬래시 없는 라우트에 매칭해야 합니다', () => {
      const result = router.match('/admin/');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('dashboard');
      expect(result?.params).toEqual({});
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('끝 슬래시가 붙은 하위 경로도 매칭해야 합니다', () => {
      const result = router.match('/admin/users/');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('user-list');
      expect(result?.params).toEqual({});
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('끝 슬래시가 붙은 동적 파라미터 경로도 매칭하고 파라미터를 추출해야 합니다', () => {
      const result = router.match('/admin/users/123/');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('user-detail');
      expect(result?.params).toEqual({ id: '123' });
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('연속된 끝 슬래시도 정규화해야 합니다', () => {
      const result = router.match('/admin//');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('dashboard');
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('루트 경로(/)는 끝 슬래시를 제거하지 않아야 합니다', () => {
      // 루트만 있는 라우트를 임시로 주입해 루트 정규화 안전성을 검증한다.
      router.setRoutes([{ path: '/', layout: 'home' }]);
      const result = router.match('/');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('home');
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('중간의 연속 슬래시도 하나로 줄여 매칭해야 합니다 (공개#156)', () => {
      const result = router.match('/admin//users');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('user-list');
    });
  });

  // 끝·연속 슬래시 주소로 들어오면 매칭만 정규형으로 하던 종전 동작은 routeChange.path·로그인 redirect·
  // 이후 화면 동일성 판정에 원본 `/x/` 를 남겼다. 진입 즉시 주소창을 정규형으로 바꾸고 이후 전부 정규형을 쓴다.
  describe('주소 정규화 — 끝·연속 슬래시 주소를 정규형으로 replaceState (공개#156)', () => {
    const originalLocation = window.location;
    let fakeLocation: { pathname: string; search: string; hash: string; href: string };
    let historyEntries: number;
    let replaceSpy: ReturnType<typeof vi.spyOn>;
    let pushSpy: ReturnType<typeof vi.spyOn>;

    const setUrl = (url: string) => {
      const parsed = new URL(url, 'https://site.test');
      fakeLocation.pathname = parsed.pathname;
      fakeLocation.search = parsed.search;
      fakeLocation.hash = parsed.hash;
    };

    beforeEach(() => {
      fakeLocation = { pathname: '/', search: '', hash: '', href: '' };
      Object.defineProperty(window, 'location', { value: fakeLocation, writable: true, configurable: true });
      historyEntries = 1;
      replaceSpy = vi.spyOn(window.history, 'replaceState').mockImplementation((_state, _title, url) => {
        if (url) setUrl(String(url));
      });
      pushSpy = vi.spyOn(window.history, 'pushState').mockImplementation((_state, _title, url) => {
        historyEntries++;
        if (url) setUrl(String(url));
      });
      router.setRoutes([
        { path: '/', layout: 'home' },
        { path: '/admin/settings', layout: 'admin_settings' },
        { path: '/admin/users', layout: 'admin_users' },
        { path: '/board/:slug', layout: 'board_index' },
        { path: '/admin/secure', layout: 'admin_secure', auth_required: true, auth_type: 'admin' as const },
      ]);
    });

    afterEach(() => {
      replaceSpy.mockRestore();
      pushSpy.mockRestore();
      Object.defineProperty(window, 'location', { value: originalLocation, writable: true, configurable: true });
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('끝 슬래시 주소는 쿼리·해시를 보존해 정규형으로 1회 replaceState 하고 routeChange.path 도 정규형이다', async () => {
      setUrl('/admin/settings/?tab=a#h');
      const routeChangeHandler = vi.fn();
      router.on('routeChange', routeChangeHandler);

      await router.navigateToCurrentPath();

      expect(replaceSpy).toHaveBeenCalledTimes(1);
      expect(replaceSpy.mock.calls[0][2]).toBe('/admin/settings?tab=a#h');
      expect(routeChangeHandler).toHaveBeenCalledWith(expect.objectContaining({ path: '/admin/settings', query: { tab: 'a' } }));
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('연속 슬래시 주소도 정규형으로 바꾼다', async () => {
      setUrl('/board//free');
      const routeChangeHandler = vi.fn();
      router.on('routeChange', routeChangeHandler);

      await router.navigateToCurrentPath();

      expect(fakeLocation.pathname).toBe('/board/free');
      expect(routeChangeHandler).toHaveBeenCalledWith(expect.objectContaining({ path: '/board/free', params: { slug: 'free' } }));
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('정규형·루트 주소는 replaceState 하지 않는다', async () => {
      setUrl('/');
      await router.navigateToCurrentPath();
      setUrl('/admin/users?page=2');
      await router.navigateToCurrentPath();

      expect(replaceSpy).not.toHaveBeenCalled();
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('슬래시 주소로 navigate 해도 히스토리는 1개만 늘고 최종 주소는 정규형이다', async () => {
      const routeChangeHandler = vi.fn();
      router.on('routeChange', routeChangeHandler);

      router.navigate('/admin/users/');
      await vi.waitFor(() => expect(routeChangeHandler).toHaveBeenCalled());

      expect(historyEntries).toBe(2);
      expect(fakeLocation.pathname).toBe('/admin/users');
      expect(routeChangeHandler).toHaveBeenCalledWith(expect.objectContaining({ path: '/admin/users' }));
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('미인증 진입의 로그인 복귀 주소에 끝 슬래시가 남지 않는다', async () => {
      const authManager = {
        isAuthenticated: vi.fn().mockReturnValue(false),
        getAuthType: vi.fn().mockReturnValue(null),
        checkAuth: vi.fn().mockResolvedValue(false),
        getLoginRedirectUrl: vi.fn((_type: string, path: string) => `/admin/login?redirect=${encodeURIComponent(path)}`),
      };
      (AuthManager.getInstance as any).mockReturnValue(authManager);
      setUrl('/admin/secure/');

      await router.navigateToCurrentPath();

      expect(authManager.getLoginRedirectUrl).toHaveBeenCalledWith('admin', '/admin/secure');
      expect(fakeLocation.href).toBe('/admin/login?redirect=%2Fadmin%2Fsecure');
    });

    // @scenario path_shape=trailing_slash
    // @effects client_router_normalizes_address_with_replace_state
    it('뒤로 가기로 끝 슬래시 항목에 돌아와도 정규형으로 바꾼다', async () => {
      const routeChangeHandler = vi.fn();
      router.on('routeChange', routeChangeHandler);
      setUrl('/admin/users/');

      window.dispatchEvent(new PopStateEvent('popstate'));
      await vi.waitFor(() => expect(routeChangeHandler).toHaveBeenCalled());

      expect(fakeLocation.pathname).toBe('/admin/users');
      expect(routeChangeHandler).toHaveBeenCalledWith(expect.objectContaining({ path: '/admin/users' }));
    });
  });

  describe('matchPattern (간접 테스트)', () => {
    beforeEach(async () => {
      const mockRoutes = [
        { path: '/test/:param', layout: 'test' },
      ];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes();
    });

    // @scenario path_shape=plain
    // @effects route_pattern_matches_and_extracts_params
    it('영숫자와 하이픈, 언더스코어를 포함한 파라미터를 추출해야 합니다', () => {
      const result = router.match('/test/user-id_123');

      expect(result).not.toBeNull();
      expect(result?.params.param).toBe('user-id_123');
    });

    // @scenario path_shape=plain
    // @effects route_pattern_matches_and_extracts_params
    it('슬래시가 포함된 파라미터는 매칭하지 않아야 합니다', () => {
      const result = router.match('/test/invalid/path');

      expect(result).toBeNull();
    });
  });

  describe('getRoutes', () => {
    // @scenario entry=initial
    // @effects routes_loaded_from_api_with_cache_version
    it('로드된 라우트 목록의 복사본을 반환해야 합니다', async () => {
      const mockRoutes = [
        { path: '/admin', layout: 'dashboard' },
      ];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes();

      const routes = router.getRoutes();
      routes.push({ path: '/test', layout: 'test' });

      // 원본은 변경되지 않아야 함
      expect(router.getRoutes().length).toBe(1);
    });
  });

  describe('redirect', () => {
    beforeEach(async () => {
      const mockRoutes = [
        { path: '/admin', redirect: '/admin/dashboard', auth_required: true },
        { path: '/admin/login', layout: 'admin_login', auth_required: false },
        { path: '/admin/dashboard', layout: 'admin_dashboard', auth_required: true },
      ];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes();
    });

    // @scenario entry=initial
    // @effects redirect_route_navigates_to_target
    it('리다이렉트 라우트를 매칭해야 합니다', () => {
      const result = router.match('/admin');

      expect(result).not.toBeNull();
      expect(result?.route.redirect).toBe('/admin/dashboard');
      expect(result?.route.auth_required).toBe(true);
    });

    // @scenario entry=initial
    // @effects redirect_route_navigates_to_target
    it('일반 라우트는 layout을 가져야 합니다', () => {
      const result = router.match('/admin/dashboard');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('admin_dashboard');
      expect(result?.route.redirect).toBeUndefined();
    });

    // @scenario entry=initial
    // @effects redirect_route_navigates_to_target
    it('navigateToCurrentPath에서 리다이렉트 라우트를 처리해야 합니다', async () => {
      // 리다이렉트 후 pathname이 변경되도록 시뮬레이션
      let currentPathname = '/admin';

      Object.defineProperty(window, 'location', {
        get: () => ({ pathname: currentPathname, search: '', href: '' }),
        configurable: true,
      });

      // window.history.pushState 모킹 - pathname도 함께 변경
      const pushStateSpy = vi.spyOn(window.history, 'pushState').mockImplementation((_, __, url) => {
        currentPathname = url as string;
      });

      // Mock AuthManager for auth_required routes
      const mockAuthManager = {
        isAuthenticated: vi.fn().mockReturnValue(false),
        getAuthType: vi.fn().mockReturnValue(null),
        checkAuth: vi.fn().mockResolvedValue(true),
        getLoginRedirectUrl: vi.fn(),
      };
      (AuthManager.getInstance as any).mockReturnValue(mockAuthManager);

      // routeChange 이벤트 리스너
      const routeChangeHandler = vi.fn();
      router.on('routeChange', routeChangeHandler);

      // navigateToCurrentPath 호출 (async)
      await router.navigateToCurrentPath();

      // pushState가 리다이렉트 경로로 호출되어야 함
      expect(pushStateSpy).toHaveBeenCalledWith({}, '', '/admin/dashboard');

      // 최종적으로 routeChange가 dashboard 레이아웃으로 호출되어야 함
      expect(routeChangeHandler).toHaveBeenCalledWith(
        expect.objectContaining({
          layout: 'admin_dashboard',
        })
      );

      pushStateSpy.mockRestore();
    });
  });

  describe('인증 가드', () => {
    let mockAuthManager: any;

    beforeEach(async () => {
      const mockRoutes = [
        { path: '/admin/login', layout: 'admin_login', auth_required: false },
        { path: '/admin/dashboard', layout: 'admin_dashboard', auth_required: true },
        { path: '/admin/users', layout: 'admin_users', auth_required: true, auth_type: 'admin' as const },
        { path: '/profile', layout: 'user_profile', auth_required: true },
        { path: '/login', layout: 'user_login', auth_required: false },
      ];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes();

      // Mock AuthManager
      mockAuthManager = {
        isAuthenticated: vi.fn().mockReturnValue(false),
        getAuthType: vi.fn().mockReturnValue(null),
        checkAuth: vi.fn(),
        getLoginRedirectUrl: vi.fn(),
      };

      (AuthManager.getInstance as any).mockReturnValue(mockAuthManager);
    });

    afterEach(() => {
      vi.clearAllMocks();
    });

    // @scenario auth=auth_required_guest
    // @effects auth_required_route_redirects_guest_to_login
    it('auth_required가 true이고 미인증 시 로그인 페이지로 리다이렉트해야 합니다', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/admin/dashboard', search: '', href: '' },
        writable: true,
        configurable: true,
      });

      mockAuthManager.checkAuth.mockResolvedValue(false);
      mockAuthManager.getLoginRedirectUrl.mockReturnValue('/admin/login?redirect=%2Fadmin%2Fdashboard');

      const routeChangeHandler = vi.fn();
      router.on('routeChange', routeChangeHandler);

      await router.navigateToCurrentPath();

      expect(mockAuthManager.checkAuth).toHaveBeenCalledWith('admin');
      expect(window.location.href).toBe('/admin/login?redirect=%2Fadmin%2Fdashboard');
      expect(routeChangeHandler).not.toHaveBeenCalled();
    });

    // @scenario auth=auth_required_guest
    // @effects auth_required_route_redirects_guest_to_login
    it('auth_required가 true이고 인증됨 시 정상 렌더링해야 합니다', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/admin/dashboard', search: '', href: '' },
        writable: true,
        configurable: true,
      });

      mockAuthManager.checkAuth.mockResolvedValue(true);

      const routeChangeHandler = vi.fn();
      router.on('routeChange', routeChangeHandler);

      await router.navigateToCurrentPath();

      expect(mockAuthManager.checkAuth).toHaveBeenCalledWith('admin');
      expect(routeChangeHandler).toHaveBeenCalledWith(
        expect.objectContaining({
          layout: 'admin_dashboard',
        })
      );
    });

    // @scenario auth=auth_required_guest
    // @effects auth_required_route_redirects_guest_to_login
    it('/admin 경로는 관리자 로그인 페이지로 리다이렉트해야 합니다', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/admin/users', search: '', href: '' },
        writable: true,
        configurable: true,
      });

      mockAuthManager.checkAuth.mockResolvedValue(false);
      mockAuthManager.getLoginRedirectUrl.mockReturnValue('/admin/login?redirect=%2Fadmin%2Fusers');

      await router.navigateToCurrentPath();

      expect(mockAuthManager.checkAuth).toHaveBeenCalledWith('admin');
      expect(mockAuthManager.getLoginRedirectUrl).toHaveBeenCalledWith('admin', '/admin/users');
    });

    // @scenario auth=auth_required_guest
    // @effects auth_required_route_redirects_guest_to_login
    it('일반 경로는 사용자 로그인 페이지로 리다이렉트해야 합니다', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/profile', search: '', href: '' },
        writable: true,
        configurable: true,
      });

      mockAuthManager.checkAuth.mockResolvedValue(false);
      mockAuthManager.getLoginRedirectUrl.mockReturnValue('/login?redirect=%2Fprofile');

      await router.navigateToCurrentPath();

      expect(mockAuthManager.checkAuth).toHaveBeenCalledWith('user');
      expect(mockAuthManager.getLoginRedirectUrl).toHaveBeenCalledWith('user', '/profile');
    });

    // @scenario auth=auth_required_guest
    // @effects auth_required_route_redirects_guest_to_login
    it('auth_required가 false인 경우 인증 검증을 수행하지 않아야 합니다', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/admin/login', search: '', href: '' },
        writable: true,
        configurable: true,
      });

      const routeChangeHandler = vi.fn();
      router.on('routeChange', routeChangeHandler);

      await router.navigateToCurrentPath();

      expect(mockAuthManager.checkAuth).not.toHaveBeenCalled();
      expect(routeChangeHandler).toHaveBeenCalledWith(
        expect.objectContaining({
          layout: 'admin_login',
        })
      );
    });

    // @scenario auth=auth_required_guest
    // @effects auth_required_route_redirects_guest_to_login
    it('라우트에 auth_type이 명시된 경우 해당 타입을 사용해야 합니다', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/admin/users', search: '', href: '' },
        writable: true,
        configurable: true,
      });

      mockAuthManager.checkAuth.mockResolvedValue(true);

      await router.navigateToCurrentPath();

      // auth_type이 'admin'으로 명시되어 있으므로 'admin'으로 호출
      expect(mockAuthManager.checkAuth).toHaveBeenCalledWith('admin');
    });
  });

  describe('배열 쿼리 파라미터 파싱', () => {
    // @scenario path_shape=plain
    // @effects array_query_params_parsed_into_route_query
    it('같은 키의 여러 값을 배열로 파싱해야 합니다', async () => {
      const mockRoutes = [
        { path: '/admin/products', layout: 'products' },
      ];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes();

      let capturedRoute: any = null;
      router.on('routeChange', (route: any) => {
        capturedRoute = route;
      });

      // 배열 쿼리 파라미터가 있는 URL 설정
      Object.defineProperty(window, 'location', {
        value: {
          pathname: '/admin/products',
          search: '?sales_status[]=on_sale&sales_status[]=sold_out&page=1',
          href: '',
        },
        writable: true,
        configurable: true,
      });

      await router.navigateToCurrentPath();

      // query에서 배열이 올바르게 파싱되었는지 확인
      expect(capturedRoute).not.toBeNull();
      expect(capturedRoute.query['sales_status[]']).toEqual(['on_sale', 'sold_out']);
      expect(capturedRoute.query['page']).toBe('1');
    });

    // @scenario path_shape=plain
    // @effects array_query_params_parsed_into_route_query
    it('단일 배열 값도 배열로 파싱해야 합니다 (key[] 형태)', async () => {
      const mockRoutes = [
        { path: '/admin/products', layout: 'products' },
      ];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes();

      let capturedRoute: any = null;
      router.on('routeChange', (route: any) => {
        capturedRoute = route;
      });

      // 단일 배열 값이 있는 URL 설정
      Object.defineProperty(window, 'location', {
        value: {
          pathname: '/admin/products',
          search: '?sales_status[]=on_sale',
          href: '',
        },
        writable: true,
        configurable: true,
      });

      await router.navigateToCurrentPath();

      // key[]로 끝나는 단일 값도 배열로 파싱되어야 함
      expect(capturedRoute).not.toBeNull();
      expect(capturedRoute.query['sales_status[]']).toEqual(['on_sale']);
    });

    // @scenario path_shape=plain
    // @effects array_query_params_parsed_into_route_query
    it('일반 파라미터는 문자열로 유지해야 합니다', async () => {
      const mockRoutes = [
        { path: '/admin/products', layout: 'products' },
      ];

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          success: true,
          data: { version: '1.0.0', routes: mockRoutes },
        }),
      });

      await router.loadRoutes();

      let capturedRoute: any = null;
      router.on('routeChange', (route: any) => {
        capturedRoute = route;
      });

      Object.defineProperty(window, 'location', {
        value: {
          pathname: '/admin/products',
          search: '?page=1&search=test',
          href: '',
        },
        writable: true,
        configurable: true,
      });

      await router.navigateToCurrentPath();

      // 일반 파라미터는 문자열
      expect(capturedRoute).not.toBeNull();
      expect(capturedRoute.query['page']).toBe('1');
      expect(capturedRoute.query['search']).toBe('test');
      expect(typeof capturedRoute.query['page']).toBe('string');
    });
  });
});
