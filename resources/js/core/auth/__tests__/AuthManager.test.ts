import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { AuthManager, AuthType, AuthUser } from '../AuthManager';
import * as ApiClientModule from '../../api/ApiClient';

// Mock ApiClient
vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(),
}));

describe('AuthManager', () => {
  let authManager: AuthManager;
  let mockApiClient: any;

  beforeEach(() => {
    // AuthManager 인스턴스 초기화
    AuthManager.resetInstance();
    authManager = AuthManager.getInstance();

    // Mock ApiClient 설정
    mockApiClient = {
      getToken: vi.fn(),
      setToken: vi.fn(),
      removeToken: vi.fn(),
      get: vi.fn(),
      post: vi.fn(),
    };

    (ApiClientModule.getApiClient as any).mockReturnValue(mockApiClient);
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('싱글톤 패턴', () => {
    it('항상 동일한 인스턴스를 반환해야 합니다', () => {
      const instance1 = AuthManager.getInstance();
      const instance2 = AuthManager.getInstance();
      expect(instance1).toBe(instance2);
    });
  });

  describe('checkAuth', () => {
    it('토큰이 없으면 false를 반환해야 합니다', async () => {
      mockApiClient.getToken.mockReturnValue(null);

      const result = await authManager.checkAuth('admin');

      expect(result).toBe(false);
      expect(authManager.isAuthenticated()).toBe(false);
    });

    it('유효한 토큰이면 true를 반환하고 사용자 정보를 저장해야 합니다', async () => {
      const mockUser: AuthUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
      };

      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValue({
        success: true,
        data: mockUser,
      });

      const result = await authManager.checkAuth('admin');

      expect(result).toBe(true);
      expect(authManager.isAuthenticated()).toBe(true);
      expect(authManager.getUser()).toEqual(mockUser);
      expect(authManager.getAuthType()).toBe('admin');
    });

    it('관리자 인증과 일반 사용자 인증을 구분해야 합니다', async () => {
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValue({
        success: true,
        data: { id: 1, name: 'User', email: 'user@example.com' },
      });

      // 관리자 인증
      await authManager.checkAuth('admin');
      expect(mockApiClient.get).toHaveBeenCalledWith('/admin/auth/user');

      // 인스턴스 리셋
      AuthManager.resetInstance();
      authManager = AuthManager.getInstance();

      // 일반 사용자 인증 (defaultConfigs.user.userEndpoint = '/auth/user')
      await authManager.checkAuth('user');
      expect(mockApiClient.get).toHaveBeenCalledWith('/auth/user');
    });

    it('API 호출 실패 시 false를 반환해야 합니다', async () => {
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockRejectedValue(new Error('Network error'));

      const result = await authManager.checkAuth('admin');

      expect(result).toBe(false);
      expect(authManager.isAuthenticated()).toBe(false);
    });
  });

  describe('refreshToken', () => {
    beforeEach(async () => {
      // 먼저 인증 상태 설정
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValue({
        success: true,
        data: { id: 1, name: 'User', email: 'user@example.com' },
      });
      await authManager.checkAuth('admin');
    });

    it('토큰 갱신이 성공하면 true를 반환해야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { token: 'new-token' },
      });

      const result = await authManager.refreshToken();

      expect(result).toBe(true);
      expect(mockApiClient.setToken).toHaveBeenCalledWith('new-token');
    });

    it('토큰 갱신이 실패하면 false를 반환해야 합니다', async () => {
      mockApiClient.post.mockRejectedValue(new Error('Refresh failed'));

      const result = await authManager.refreshToken();

      expect(result).toBe(false);
    });

    it('동시 갱신 요청은 하나의 요청으로 처리해야 합니다', async () => {
      mockApiClient.post.mockImplementation(() =>
        new Promise(resolve =>
          setTimeout(() => resolve({
            success: true,
            data: { token: 'new-token' },
          }), 100)
        )
      );

      // 동시에 여러 갱신 요청
      const promises = [
        authManager.refreshToken(),
        authManager.refreshToken(),
        authManager.refreshToken(),
      ];

      await Promise.all(promises);

      // API는 한 번만 호출되어야 함
      expect(mockApiClient.post).toHaveBeenCalledTimes(1);
    });

    it('인증 타입이 없으면 false를 반환해야 합니다', async () => {
      // 인스턴스 리셋하여 인증 타입 없음
      AuthManager.resetInstance();
      authManager = AuthManager.getInstance();

      const result = await authManager.refreshToken();

      expect(result).toBe(false);
    });
  });

  describe('getLoginRedirectUrl', () => {
    it('관리자 로그인 URL에 redirect 파라미터를 포함해야 합니다', () => {
      const url = authManager.getLoginRedirectUrl('admin', '/admin/dashboard');

      expect(url).toBe('/admin/login?redirect=%2Fadmin%2Fdashboard');
    });

    it('일반 사용자 로그인 URL에 redirect 파라미터를 포함해야 합니다', () => {
      const url = authManager.getLoginRedirectUrl('user', '/profile');

      expect(url).toBe('/login?redirect=%2Fprofile');
    });
  });

  describe('getRedirectUrl', () => {
    it('URL 파라미터에서 redirect 값을 읽어야 합니다', () => {
      // URL 파라미터 설정
      Object.defineProperty(window, 'location', {
        value: {
          search: '?redirect=%2Fadmin%2Fdashboard',
        },
        writable: true,
      });

      const url = authManager.getRedirectUrl('admin');

      expect(url).toBe('/admin/dashboard');
    });

    it('redirect 파라미터가 없으면 기본 경로를 반환해야 합니다', () => {
      Object.defineProperty(window, 'location', {
        value: {
          search: '',
        },
        writable: true,
      });

      const adminUrl = authManager.getRedirectUrl('admin');
      const userUrl = authManager.getRedirectUrl('user');

      expect(adminUrl).toBe('/admin');
      expect(userUrl).toBe('/');
    });

    it('외부 URL은 무시하고 기본 경로를 반환해야 합니다', () => {
      Object.defineProperty(window, 'location', {
        value: {
          search: '?redirect=https%3A%2F%2Fevil.com',
        },
        writable: true,
      });

      const url = authManager.getRedirectUrl('admin');

      expect(url).toBe('/admin');
    });
  });

  describe('logout', () => {
    beforeEach(async () => {
      // 먼저 인증 상태 설정
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValue({
        success: true,
        data: { id: 1, name: 'User', email: 'user@example.com' },
      });
      await authManager.checkAuth('admin');
    });

    it('로그아웃 시 토큰과 사용자 정보를 삭제해야 합니다', async () => {
      // window.location.href mock
      const locationMock = { href: '' };
      Object.defineProperty(window, 'location', {
        value: locationMock,
        writable: true,
      });

      mockApiClient.post.mockResolvedValue({ success: true });

      await authManager.logout();

      expect(mockApiClient.removeToken).toHaveBeenCalled();
      expect(authManager.isAuthenticated()).toBe(false);
      expect(authManager.getUser()).toBeNull();
    });

    it('로그아웃 이벤트를 발생시켜야 합니다', async () => {
      const locationMock = { href: '' };
      Object.defineProperty(window, 'location', {
        value: locationMock,
        writable: true,
      });

      mockApiClient.post.mockResolvedValue({ success: true });

      const logoutHandler = vi.fn();
      authManager.on('logout', logoutHandler);

      await authManager.logout();

      expect(logoutHandler).toHaveBeenCalled();
    });

    it('로그아웃 후 로그인 페이지로 리다이렉트해야 합니다', async () => {
      const locationMock = { href: '' };
      Object.defineProperty(window, 'location', {
        value: locationMock,
        writable: true,
      });

      mockApiClient.post.mockResolvedValue({ success: true });

      await authManager.logout();

      expect(locationMock.href).toBe('/admin/login');
    });
  });

  describe('login', () => {
    it('로그인 성공 시 토큰을 저장하고 사용자 정보를 반환해야 합니다', async () => {
      const mockUser: AuthUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
      };

      mockApiClient.post.mockResolvedValue({
        success: true,
        data: {
          token: 'new-token',
          user: mockUser,
        },
      });

      const result = await authManager.login('admin', {
        email: 'test@example.com',
        password: 'password',
      });

      expect(result).toEqual(mockUser);
      expect(mockApiClient.setToken).toHaveBeenCalledWith('new-token');
      expect(authManager.isAuthenticated()).toBe(true);
      expect(authManager.getUser()).toEqual(mockUser);
    });

    it('로그인 실패 시 에러를 throw해야 합니다', async () => {
      mockApiClient.post.mockRejectedValue(new Error('Invalid credentials'));

      await expect(
        authManager.login('admin', {
          email: 'test@example.com',
          password: 'wrong',
        })
      ).rejects.toThrow('Invalid credentials');

      expect(authManager.isAuthenticated()).toBe(false);
    });

    it('options.headers가 있으면 ApiClient.post에 전달해야 합니다', async () => {
      const mockUser: AuthUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
      };

      mockApiClient.post.mockResolvedValue({
        success: true,
        data: {
          token: 'new-token',
          user: mockUser,
        },
      });

      await authManager.login(
        'user',
        { email: 'test@example.com', password: 'password' },
        { headers: { 'X-Cart-Key': 'ck_abc123' } }
      );

      expect(mockApiClient.post).toHaveBeenCalledWith(
        '/auth/login',
        { email: 'test@example.com', password: 'password' },
        { headers: { 'X-Cart-Key': 'ck_abc123' } }
      );
    });

    it('options가 없으면 ApiClient.post에 config를 전달하지 않아야 합니다', async () => {
      const mockUser: AuthUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
      };

      mockApiClient.post.mockResolvedValue({
        success: true,
        data: {
          token: 'new-token',
          user: mockUser,
        },
      });

      await authManager.login('user', {
        email: 'test@example.com',
        password: 'password',
      });

      expect(mockApiClient.post).toHaveBeenCalledWith(
        '/auth/login',
        { email: 'test@example.com', password: 'password' },
        undefined
      );
    });
  });
});
