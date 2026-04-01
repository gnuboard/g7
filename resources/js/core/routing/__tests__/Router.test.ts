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

    it('API 호출 실패 시 에러를 던져야 합니다', async () => {
      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        statusText: 'Not Found',
      });

      await expect(router.loadRoutes()).rejects.toThrow('Failed to load routes: Not Found');
    });

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

    it('정확한 경로 매칭을 수행해야 합니다', () => {
      const result = router.match('/admin');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('dashboard');
      expect(result?.params).toEqual({});
    });

    it('동적 파라미터를 추출해야 합니다', () => {
      const result = router.match('/admin/users/123');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('user-detail');
      expect(result?.params).toEqual({ id: '123' });
    });

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
    it.skip('와일드카드 패턴을 매칭해야 합니다', () => {
      const result = router.match('/admin/files/documents/2024/report.pdf');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('file-browser');
      expect(result?.params).toEqual({});
    });

    it('매칭되지 않는 경로는 null을 반환해야 합니다', () => {
      const result = router.match('/unknown/path');

      expect(result).toBeNull();
    });

    it('부분 매칭은 수행하지 않아야 합니다', () => {
      const result = router.match('/admin/users/123/extra');

      expect(result).toBeNull();
    });

    it('첫 번째로 매칭되는 라우트를 반환해야 합니다', () => {
      // /admin/users는 /admin/users와 /admin/users/:id 둘 다 매칭 가능
      // 하지만 정확히 매칭되는 /admin/users를 먼저 반환해야 함
      const result = router.match('/admin/users');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('user-list');
      expect(result?.params).toEqual({});
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

    it('영숫자와 하이픈, 언더스코어를 포함한 파라미터를 추출해야 합니다', () => {
      const result = router.match('/test/user-id_123');

      expect(result).not.toBeNull();
      expect(result?.params.param).toBe('user-id_123');
    });

    it('슬래시가 포함된 파라미터는 매칭하지 않아야 합니다', () => {
      const result = router.match('/test/invalid/path');

      expect(result).toBeNull();
    });
  });

  describe('getRoutes', () => {
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

    it('리다이렉트 라우트를 매칭해야 합니다', () => {
      const result = router.match('/admin');

      expect(result).not.toBeNull();
      expect(result?.route.redirect).toBe('/admin/dashboard');
      expect(result?.route.auth_required).toBe(true);
    });

    it('일반 라우트는 layout을 가져야 합니다', () => {
      const result = router.match('/admin/dashboard');

      expect(result).not.toBeNull();
      expect(result?.route.layout).toBe('admin_dashboard');
      expect(result?.route.redirect).toBeUndefined();
    });

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
