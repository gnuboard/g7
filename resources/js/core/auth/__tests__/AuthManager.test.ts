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
    /**
     * @scenario surface=singleton
     * @effects auth_manager_session_lifecycle, single_instance_is_shared
     */
    it('항상 동일한 인스턴스를 반환해야 합니다', () => {
      const instance1 = AuthManager.getInstance();
      const instance2 = AuthManager.getInstance();
      expect(instance1).toBe(instance2);
    });
  });

  describe('checkAuth', () => {
    /**
     * @scenario surface=check_auth
     * @effects auth_manager_session_lifecycle, check_auth_reflects_server_session
     */
    it('토큰이 없으면 false를 반환해야 합니다', async () => {
      mockApiClient.getToken.mockReturnValue(null);

      const result = await authManager.checkAuth('admin');

      expect(result).toBe(false);
      expect(authManager.isAuthenticated()).toBe(false);
    });

    /**
     * @scenario surface=check_auth
     * @effects auth_manager_session_lifecycle, check_auth_reflects_server_session
     */
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

    /**
     * @scenario surface=check_auth
     * @effects auth_manager_session_lifecycle, check_auth_reflects_server_session
     */
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

    /**
     * @scenario surface=check_auth
     * @effects auth_manager_session_lifecycle, check_auth_reflects_server_session
     */
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

    /**
     * @scenario surface=token_refresh
     * @effects auth_manager_token_refresh, token_refresh_is_shared_and_reported
     */
    it('토큰 갱신이 성공하면 true를 반환해야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { token: 'new-token' },
      });

      const result = await authManager.refreshToken();

      expect(result).toBe(true);
      expect(mockApiClient.setToken).toHaveBeenCalledWith('new-token');
    });

    /**
     * @scenario surface=token_refresh
     * @effects auth_manager_token_refresh, token_refresh_is_shared_and_reported
     */
    it('토큰 갱신이 실패하면 false를 반환해야 합니다', async () => {
      mockApiClient.post.mockRejectedValue(new Error('Refresh failed'));

      const result = await authManager.refreshToken();

      expect(result).toBe(false);
    });

    /**
     * @scenario surface=token_refresh
     * @effects auth_manager_token_refresh, token_refresh_is_shared_and_reported
     */
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

    /**
     * @scenario surface=token_refresh
     * @effects auth_manager_token_refresh, token_refresh_is_shared_and_reported
     */
    it('인증 타입이 없으면 false를 반환해야 합니다', async () => {
      // 인스턴스 리셋하여 인증 타입 없음
      AuthManager.resetInstance();
      authManager = AuthManager.getInstance();

      const result = await authManager.refreshToken();

      expect(result).toBe(false);
    });
  });

  describe('getLoginRedirectUrl', () => {
    /**
     * @scenario surface=redirect
     * @effects auth_manager_redirect_urls, login_redirect_url_is_built_safely
     */
    it('관리자 로그인 URL에 redirect 파라미터를 포함해야 합니다', () => {
      const url = authManager.getLoginRedirectUrl('admin', '/admin/dashboard');

      expect(url).toBe('/admin/login?redirect=%2Fadmin%2Fdashboard');
    });

    /**
     * @scenario surface=redirect
     * @effects auth_manager_redirect_urls, login_redirect_url_is_built_safely
     */
    it('일반 사용자 로그인 URL에 redirect 파라미터를 포함해야 합니다', () => {
      const url = authManager.getLoginRedirectUrl('user', '/profile');

      expect(url).toBe('/login?redirect=%2Fprofile');
    });

    /**
     * 세션이 끊긴 채 로그인 화면 자신을 열면 데이터소스 401 → 로그인 이동이 일어난다.
     * 이때 로그인 화면을 복귀 경로로 실으면 로그인을 마친 뒤 다시 로그인 폼에 떨어진다.
     *
     * @effects auth_manager_redirect_skips_guest_only_pages
     */
    it.each([
      ['user', '/login', '/login?reason=session_expired'],
      ['user', '/login/', '/login?reason=session_expired'],
      ['user', '/register', '/login?reason=session_expired'],
      ['user', '/forgot-password', '/login?reason=session_expired'],
      ['user', '/reset-password?token=abc&email=a%40b.c', '/login?reason=session_expired'],
      ['admin', '/admin/login', '/admin/login?reason=session_expired'],
      ['admin', '/admin/forgot-password', '/admin/login?reason=session_expired'],
      ['admin', '/admin/reset-password?token=abc', '/admin/login?reason=session_expired'],
    ] as Array<[AuthType, string, string]>)(
      '%s 로그인 전용 화면(%s)은 복귀 경로로 싣지 않아야 합니다',
      (type, returnUrl, expected) => {
        expect(authManager.getLoginRedirectUrl(type, returnUrl, 'session_expired')).toBe(expected);
      }
    );

    /** @effects auth_manager_redirect_skips_guest_only_pages */
    it('로그인 전용 화면이고 사유도 없으면 쿼리 없이 로그인 경로만 돌려야 합니다', () => {
      expect(authManager.getLoginRedirectUrl('user', '/login')).toBe('/login');
      expect(authManager.getLoginRedirectUrl('admin', '/admin/login')).toBe('/admin/login');
    });

    /** @effects auth_manager_redirect_skips_guest_only_pages */
    it('로그인 화면이 이미 복귀 경로를 갖고 있으면 그 복귀 경로를 이어서 실어야 합니다', () => {
      expect(
        authManager.getLoginRedirectUrl('user', '/login?redirect=%2Fmypage%3Ftab%3D1', 'session_expired')
      ).toBe('/login?redirect=%2Fmypage%3Ftab%3D1&reason=session_expired');
      expect(
        authManager.getLoginRedirectUrl('admin', '/admin/login?redirect=%2Fadmin%2Fusers&reason=session_expired', 'session_expired')
      ).toBe('/admin/login?redirect=%2Fadmin%2Fusers&reason=session_expired');
    });

    /** @effects auth_manager_redirect_skips_guest_only_pages */
    it('이어 싣는 복귀 경로도 로그인 전용 화면이면 버려야 합니다', () => {
      expect(
        authManager.getLoginRedirectUrl('user', '/login?redirect=%2Flogin', 'session_expired')
      ).toBe('/login?reason=session_expired');
    });

    /** @effects auth_manager_redirect_skips_guest_only_pages */
    it('이름만 비슷한 일반 화면은 그대로 복귀 경로로 실어야 합니다', () => {
      expect(authManager.getLoginRedirectUrl('user', '/login-help')).toBe('/login?redirect=%2Flogin-help');
      expect(authManager.getLoginRedirectUrl('user', '/board/login')).toBe('/login?redirect=%2Fboard%2Flogin');
      expect(authManager.getLoginRedirectUrl('admin', '/admin/users')).toBe('/admin/login?redirect=%2Fadmin%2Fusers');
    });

    /** @effects auth_manager_redirect_skips_guest_only_pages */
    it('updateConfig 로 바꾼 로그인 경로도 로그인 전용 화면으로 취급해야 합니다', () => {
      authManager.updateConfig('user', { loginPath: '/account/signin' });

      expect(authManager.getLoginRedirectUrl('user', '/account/signin', 'session_expired')).toBe(
        '/account/signin?reason=session_expired'
      );
      expect(authManager.getLoginRedirectUrl('user', '/mypage')).toBe('/account/signin?redirect=%2Fmypage');
    });
  });

  describe('getRedirectUrl', () => {
    /**
     * @scenario surface=redirect
     * @effects auth_manager_redirect_urls, login_redirect_url_is_built_safely
     */
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

    /**
     * @scenario surface=redirect
     * @effects auth_manager_redirect_urls, login_redirect_url_is_built_safely
     */
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

    /**
     * @scenario surface=redirect
     * @effects auth_manager_redirect_urls, login_redirect_url_is_built_safely
     */
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

    /**
     * @scenario surface=logout
     * @effects auth_manager_session_lifecycle, logout_clears_session_and_redirects
     */
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

    /**
     * @scenario surface=logout
     * @effects auth_manager_session_lifecycle, logout_clears_session_and_redirects
     */
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

    /**
     * @scenario surface=logout
     * @effects auth_manager_session_lifecycle, logout_clears_session_and_redirects
     */
    it('로그아웃 후 로그인 페이지로 리다이렉트해야 합니다', async () => {
      // logout 구현이 returnUrl(`${pathname}${search}`) 을 redirect 쿼리로 포함하므로
      // mock 에 pathname/search 를 제공해야 URL 조립이 정상 동작
      const locationMock = { href: '', pathname: '/admin/dashboard', search: '' };
      Object.defineProperty(window, 'location', {
        value: locationMock,
        writable: true,
      });

      mockApiClient.post.mockResolvedValue({ success: true });

      await authManager.logout();

      expect(locationMock.href).toMatch(/^\/admin\/login(\?redirect=.*)?$/);
    });
  });

  describe('login', () => {
    /**
     * @scenario surface=login
     * @effects login_result_discriminates_response_forms, login_establishes_session_or_returns_challenge
     */
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

      expect(result).toEqual({ status: 'authenticated', user: mockUser });
      expect(mockApiClient.setToken).toHaveBeenCalledWith('new-token');
      expect(authManager.isAuthenticated()).toBe(true);
      expect(authManager.getUser()).toEqual(mockUser);
    });

    /**
     * @scenario surface=login
     * @effects login_result_discriminates_response_forms, login_establishes_session_or_returns_challenge
     */
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

    /**
     * @scenario surface=login
     * @effects login_result_discriminates_response_forms, login_establishes_session_or_returns_challenge
     */
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

    /**
     * @scenario surface=login
     * @effects login_result_discriminates_response_forms, login_establishes_session_or_returns_challenge
     */
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

    /**
     * @scenario surface=login
     * @effects login_result_discriminates_response_forms, login_establishes_session_or_returns_challenge
     */
    it('2단계 인증 응답에서는 토큰을 저장하지 않고 challenge 를 돌려줘야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: {
          two_factor_required: true,
          challenge_id: 'challenge-uuid',
          provider_id: 'g7:core.mail',
          expires_at: '2026-09-07T14:03:00+09:00',
        },
      });

      const result = await authManager.login('user', {
        email: 'test@example.com',
        password: 'password',
      });

      expect(result).toEqual({
        status: 'two_factor_required',
        challenge: {
          challengeId: 'challenge-uuid',
          providerId: 'g7:core.mail',
          expiresAt: '2026-09-07T14:03:00+09:00',
        },
      });
      // 토큰도 사용자도 없는 응답이다 — 저장하면 이후 모든 요청이 Bearer undefined 로 나간다.
      expect(mockApiClient.setToken).not.toHaveBeenCalled();
      expect(authManager.isAuthenticated()).toBe(false);
    });

    /**
     * @scenario surface=login
     * @effects login_result_discriminates_response_forms, login_establishes_session_or_returns_challenge
     */
    it('updateConfig 로 바꾼 loginEndpoint 를 사용해야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { token: 'new-token', user: { id: 1, name: 'T', email: 't@e.com' } },
      });

      authManager.updateConfig('user', { loginEndpoint: '/auth/custom-login' });

      await authManager.login('user', { email: 'test@example.com', password: 'password' });

      expect(mockApiClient.post).toHaveBeenCalledWith(
        '/auth/custom-login',
        { email: 'test@example.com', password: 'password' },
        undefined
      );
    });

    /**
     * @scenario surface=login
     * @effects login_result_discriminates_response_forms, login_establishes_session_or_returns_challenge
     */
    it('네트워크 오류의 code 를 보존해야 합니다', async () => {
      const networkError: any = new Error('Network Error');
      networkError.code = 'ERR_NETWORK';
      mockApiClient.post.mockRejectedValue(networkError);

      // TypeError 가 아니므로 code 가 사라지면 네트워크 실패 판정이 불가능해진다.
      await expect(
        authManager.login('user', { email: 'test@example.com', password: 'password' })
      ).rejects.toMatchObject({ code: 'ERR_NETWORK' });
    });
  });

  describe('completeTwoFactor', () => {
    /**
     * @scenario surface=two_factor
     * @effects two_factor_completion_returns_login_result, two_factor_completes_or_resends_challenge
     */
    it('코드 확인에 성공하면 토큰을 저장하고 로그인 상태가 되어야 합니다', async () => {
      const mockUser: AuthUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
      };

      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { token: 'two-factor-token', user: mockUser },
      });

      const loginHandler = vi.fn();
      authManager.on('login', loginHandler);

      const result = await authManager.completeTwoFactor('user', {
        challengeId: 'challenge-uuid',
        code: '135790',
      });

      // 공개 계약(engine-v1.65.0~): 사용자만 돌려준다 — 안내 필드·변경 단계는 completeTwoFactorSession
      expect(result).toEqual(mockUser);
      expect(mockApiClient.post).toHaveBeenCalledWith(
        '/auth/login/two-factor',
        { challenge_id: 'challenge-uuid', code: '135790' },
        undefined
      );
      expect(mockApiClient.setToken).toHaveBeenCalledWith('two-factor-token');
      expect(authManager.isAuthenticated()).toBe(true);
      expect(loginHandler).toHaveBeenCalled();
    });

    /**
     * @scenario surface=two_factor
     * @effects two_factor_completion_returns_login_result, two_factor_completes_or_resends_challenge
     */
    it('관리자 타입은 관리자 전용 엔드포인트를 사용해야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { token: 't', user: { id: 1, name: 'T', email: 't@e.com' } },
      });

      await authManager.completeTwoFactor('admin', {
        challengeId: 'challenge-uuid',
        code: '135790',
      });

      expect(mockApiClient.post).toHaveBeenCalledWith(
        '/auth/admin/login/two-factor',
        { challenge_id: 'challenge-uuid', code: '135790' },
        undefined
      );
    });

    /**
     * @scenario surface=two_factor
     * @effects two_factor_completion_returns_login_result, two_factor_completes_or_resends_challenge
     */
    it('실패 시 서버 메시지를 실은 에러를 throw 해야 합니다', async () => {
      const error: any = new Error('Request failed');
      error.response = { status: 401, data: { message: '인증번호가 올바르지 않습니다.' } };
      mockApiClient.post.mockRejectedValue(error);

      await expect(
        authManager.completeTwoFactor('user', { challengeId: 'c', code: '000000' })
      ).rejects.toThrow('인증번호가 올바르지 않습니다.');

      expect(authManager.isAuthenticated()).toBe(false);
    });
  });

  describe('completeTwoFactorSession', () => {
    /** @effects two_factor_completion_returns_login_result */
    it('코드 확인에 성공하면 세션을 열고 사용자와 서버 안내 필드를 LoginResult 로 돌려줘야 합니다', async () => {
      const mockUser: AuthUser = { id: 1, uuid: 'u-1', name: 'T', email: 't@e.com' };
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { token: 'two-factor-token', user: mockUser, dormancy_restored: true },
      });

      const result = await authManager.completeTwoFactorSession('user', { challengeId: 'c', code: '135790' });

      expect(result).toEqual({ status: 'authenticated', user: mockUser, extras: { dormancy_restored: true } });
      expect(mockApiClient.setToken).toHaveBeenCalledWith('two-factor-token');
      expect(authManager.isAuthenticated()).toBe(true);
    });
  });

  describe('비밀번호 변경 단계 (engine-v1.77.0)', () => {
    const changeStep = {
      password_change_required: true,
      challenge_id: 'pw-challenge',
      reason: 'expired',
      can_postpone: true,
      postpone_days: 30,
      expired_at: '2026-09-01T00:00:00+09:00',
      expires_at: '2026-10-03T10:10:00+09:00',
    };
    const mockUser: AuthUser = { id: 1, uuid: 'u-1', name: 'T', email: 't@e.com' };

    /** @effects password_change_step_returned_without_token */
    it('login 이 비밀번호 변경 단계를 받으면 토큰을 저장하지 않고 challenge 를 돌려줘야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({ success: true, data: changeStep });

      const result = await authManager.login('admin', { email: 'a@e.com', password: 'p' });

      expect(result).toEqual({
        status: 'password_change_required',
        challenge: {
          challengeId: 'pw-challenge',
          reason: 'expired',
          canPostpone: true,
          postponeDays: 30,
          expiredAt: '2026-09-01T00:00:00+09:00',
          expiresAt: '2026-10-03T10:10:00+09:00',
        },
      });
      expect(mockApiClient.setToken).not.toHaveBeenCalled();
      expect(authManager.isAuthenticated()).toBe(false);
    });

    /** @effects password_change_step_returned_without_token */
    it('관리자 지정 사유는 연기 불가·만료일 없음으로 해석해야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { ...changeStep, reason: 'admin_assigned', can_postpone: false, postpone_days: null, expired_at: null },
      });

      const result = await authManager.login('user', { email: 'a@e.com', password: 'p' });

      expect(result.status).toBe('password_change_required');
      if (result.status === 'password_change_required') {
        expect(result.challenge.reason).toBe('admin_assigned');
        expect(result.challenge.canPostpone).toBe(false);
        expect(result.challenge.postponeDays).toBeNull();
        expect(result.challenge.expiredAt).toBeNull();
      }
    });

    /** @effects password_change_step_returned_without_token */
    it('completeTwoFactorSession 이 비밀번호 변경 단계를 받으면 토큰 없이 그 형태를 돌려줘야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({ success: true, data: changeStep });

      const result = await authManager.completeTwoFactorSession('user', { challengeId: 'c', code: '123456' });

      expect(result.status).toBe('password_change_required');
      expect(mockApiClient.setToken).not.toHaveBeenCalled();
    });

    /** @effects password_change_step_returned_without_token */
    it('공개 API completeTwoFactor 는 비밀번호 변경 단계를 받으면 세션 없이 오류를 던져야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({ success: true, data: changeStep });

      await expect(
        authManager.completeTwoFactor('user', { challengeId: 'c', code: '123456' })
      ).rejects.toThrow('Password change required');
      expect(mockApiClient.setToken).not.toHaveBeenCalled();
      expect(authManager.isAuthenticated()).toBe(false);
    });

    /** @effects password_change_step_returned_without_token */
    it('completeTwoFactorSession 응답의 2단계 인증 challenge 형태는 받지 않아야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { two_factor_required: true, challenge_id: 'x', provider_id: 'p', expires_at: null },
      });

      await expect(
        authManager.completeTwoFactorSession('user', { challengeId: 'c', code: '123456' })
      ).rejects.toThrow('Login failed');
    });

    /** @effects password_change_step_returned_without_token */
    it('login 성공 응답의 dormancy_restored 를 extras 로 보존해야 합니다 (D20)', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { token: 't', user: mockUser, dormancy_restored: true },
      });

      const result = await authManager.login('user', { email: 'a@e.com', password: 'p' });

      expect(result).toEqual({ status: 'authenticated', user: mockUser, extras: { dormancy_restored: true } });
    });

    /** @effects password_change_step_returned_without_token */
    it('dormancy_restored 가 없으면 결과에도 키가 없어야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({ success: true, data: { token: 't', user: mockUser } });

      const result = await authManager.login('user', { email: 'a@e.com', password: 'p' });

      expect(result).toEqual({ status: 'authenticated', user: mockUser });
      expect('extras' in result).toBe(false);
    });

    /** @effects password_change_step_returned_without_token */
    it('completePasswordChange 는 사용자 엔드포인트에 새 비밀번호를 보내고 세션을 열어야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { token: 'new-token', user: mockUser, dormancy_restored: true },
      });
      const loginHandler = vi.fn();
      authManager.on('login', loginHandler);

      const result = await authManager.completePasswordChange('user', {
        challengeId: 'pw-challenge',
        password: 'NewPassw0rd!',
        passwordConfirmation: 'NewPassw0rd!',
      });

      expect(mockApiClient.post).toHaveBeenCalledWith(
        '/auth/login/password-change',
        { challenge_id: 'pw-challenge', password: 'NewPassw0rd!', password_confirmation: 'NewPassw0rd!' },
        undefined
      );
      expect(mockApiClient.setToken).toHaveBeenCalledWith('new-token');
      expect(result).toEqual({ status: 'authenticated', user: mockUser, extras: { dormancy_restored: true } });
      expect(authManager.isAuthenticated()).toBe(true);
      expect(loginHandler).toHaveBeenCalled();
    });

    /** @effects password_change_step_returned_without_token */
    it('관리자 completePasswordChange·postponePasswordChange 는 관리자 엔드포인트를 써야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({ success: true, data: { token: 't', user: mockUser } });

      await authManager.completePasswordChange(
        'admin',
        { challengeId: 'c', password: 'x', passwordConfirmation: 'x' },
        { headers: { 'X-Test': '1' } }
      );
      await authManager.postponePasswordChange('admin', { challengeId: 'c' });

      expect(mockApiClient.post).toHaveBeenNthCalledWith(
        1,
        '/auth/admin/login/password-change',
        { challenge_id: 'c', password: 'x', password_confirmation: 'x' },
        { headers: { 'X-Test': '1' } }
      );
      expect(mockApiClient.post).toHaveBeenNthCalledWith(
        2,
        '/auth/admin/login/password-change/postpone',
        { challenge_id: 'c' },
        undefined
      );
    });

    /** @effects password_change_step_returned_without_token */
    it('postponePasswordChange 는 연기 종료일을 extras.postponed_until 로 보존해야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: { token: 't', user: mockUser, postponed_until: '2026-11-02T00:00:00+09:00' },
      });

      const result = await authManager.postponePasswordChange('user', { challengeId: 'c' });

      expect(mockApiClient.post).toHaveBeenCalledWith(
        '/auth/login/password-change/postpone',
        { challenge_id: 'c' },
        undefined
      );
      expect(result).toEqual({
        status: 'authenticated',
        user: mockUser,
        extras: { postponed_until: '2026-11-02T00:00:00+09:00' },
      });
    });

    /** @effects password_change_step_returned_without_token */
    it('변경 실패(422) 는 서버 메시지·상태를 실은 오류를 던지고 세션을 열지 않아야 합니다', async () => {
      const error: any = new Error('Request failed');
      error.response = {
        status: 422,
        data: { message: '최근에 사용한 비밀번호입니다.', errors: { password: ['최근에 사용한 비밀번호입니다.'] } },
      };
      mockApiClient.post.mockRejectedValue(error);

      await expect(
        authManager.completePasswordChange('user', { challengeId: 'c', password: 'x', passwordConfirmation: 'x' })
      ).rejects.toMatchObject({ message: '최근에 사용한 비밀번호입니다.', status: 422 });

      expect(mockApiClient.setToken).not.toHaveBeenCalled();
      expect(authManager.isAuthenticated()).toBe(false);
    });
  });

  describe('resendTwoFactor', () => {
    /**
     * @scenario surface=two_factor
     * @effects two_factor_resend_replaces_challenge, two_factor_completes_or_resends_challenge
     */
    it('새 challenge 를 돌려주고 토큰은 건드리지 않아야 합니다', async () => {
      mockApiClient.post.mockResolvedValue({
        success: true,
        data: {
          two_factor_required: true,
          challenge_id: 'new-challenge',
          provider_id: 'g7:core.mail',
          expires_at: null,
        },
      });

      const result = await authManager.resendTwoFactor('user', {
        challengeId: 'old-challenge',
      });

      expect(result).toEqual({
        challengeId: 'new-challenge',
        providerId: 'g7:core.mail',
        expiresAt: null,
      });
      expect(mockApiClient.post).toHaveBeenCalledWith(
        '/auth/login/two-factor/resend',
        { challenge_id: 'old-challenge' },
        undefined
      );
      expect(mockApiClient.setToken).not.toHaveBeenCalled();
    });
  });

  /**
   * #639 — 구독 해제와 인증 경계 이벤트.
   *
   * 확장은 엔진보다 늦게 실리므로 `on()` 이 해제 함수를 돌려주지 않으면 화면을 떠나도 리스너가
   * 남는다. 토큰 만료로 인증 확인이 실패해 비인증으로 바뀔 때 이벤트가 없으면, 인증 상태에 묶인
   * 캐시(동의 스냅샷 등)를 비울 기회가 없다.
   */
  describe('구독 해제와 인증 경계 이벤트 (#639)', () => {
    // @scenario surface=subscription
    // @effects auth_listeners_are_releasable_and_boundary_is_emitted
    it('on() 은 해제 함수를 돌려주고, 해제 뒤에는 호출되지 않는다', async () => {
      const handler = vi.fn();
      const off = authManager.on('authStateChange', handler);

      expect(typeof off).toBe('function');

      off();
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValue({ success: true, data: { id: 1, name: 'U', email: 'u@example.com' } });
      await authManager.checkAuth('user');

      expect(handler).not.toHaveBeenCalled();
    });

    // @scenario surface=subscription
    // @effects auth_listeners_are_releasable_and_boundary_is_emitted
    it('off(event, handler) 로도 해제할 수 있다', async () => {
      const handler = vi.fn();
      authManager.on('authStateChange', handler);
      authManager.off('authStateChange', handler);

      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValue({ success: true, data: { id: 1, name: 'U', email: 'u@example.com' } });
      await authManager.checkAuth('user');

      expect(handler).not.toHaveBeenCalled();
    });

    // @scenario surface=subscription
    // @effects auth_listeners_are_releasable_and_boundary_is_emitted
    it('getState() 는 현재 상태의 사본을 돌려준다', async () => {
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValue({ success: true, data: { id: 7, uuid: 'u-7', name: 'U', email: 'u@example.com' } });
      await authManager.checkAuth('user');

      const snapshot = authManager.getState();
      expect(snapshot).toEqual({ isAuthenticated: true, user: expect.objectContaining({ id: 7 }), type: 'user' });

      (snapshot as { isAuthenticated: boolean }).isAuthenticated = false;
      expect(authManager.isAuthenticated()).toBe(true);
    });

    // @scenario surface=subscription
    // @effects auth_listeners_are_releasable_and_boundary_is_emitted
    it('인증 상태에서 인증 확인이 실패해 비인증이 되면 authStateChange 를 발행한다', async () => {
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValueOnce({ success: true, data: { id: 1, name: 'U', email: 'u@example.com' } });
      await authManager.checkAuth('user');

      const handler = vi.fn();
      authManager.on('authStateChange', handler);

      mockApiClient.getToken.mockReturnValue(null);
      await authManager.checkAuth('user');

      expect(handler).toHaveBeenCalledTimes(1);
      expect(handler).toHaveBeenCalledWith(expect.objectContaining({ isAuthenticated: false, user: null }));
    });

    // @scenario surface=subscription
    // @effects auth_listeners_are_releasable_and_boundary_is_emitted
    it('비인증 전환을 받은 구독자가 예외를 던져도 인증 확인은 false 로 끝난다', async () => {
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValueOnce({ success: true, data: { id: 1, name: 'U', email: 'u@example.com' } });
      await authManager.checkAuth('user');

      authManager.on('authStateChange', () => {
        throw new Error('listener boom');
      });

      mockApiClient.getToken.mockReturnValue(null);
      await expect(authManager.checkAuth('user')).resolves.toBe(false);
      expect(authManager.isAuthenticated()).toBe(false);
    });

    // @scenario surface=subscription
    // @effects auth_listeners_are_releasable_and_boundary_is_emitted
    it('getState() 의 user 도 사본이라 바꿔도 내부 상태가 그대로다', async () => {
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValue({ success: true, data: { id: 7, uuid: 'u-7', name: 'U', email: 'u@example.com' } });
      await authManager.checkAuth('user');

      const snapshot = authManager.getState();
      (snapshot.user as { name: string }).name = 'changed';

      expect(authManager.getUser()?.name).toBe('U');
    });

    // @scenario surface=subscription
    // @effects auth_listeners_are_releasable_and_boundary_is_emitted
    it('비인증 상태에서 다시 비인증이 되면 발행하지 않는다', async () => {
      const handler = vi.fn();
      authManager.on('authStateChange', handler);

      mockApiClient.getToken.mockReturnValue(null);
      await authManager.checkAuth('user');
      await authManager.checkAuth('user');

      expect(handler).not.toHaveBeenCalled();
    });

    // @scenario surface=subscription
    // @effects auth_listeners_are_releasable_and_boundary_is_emitted
    it('logout() 은 authStateChange 를 한 번만 발행한다', async () => {
      Object.defineProperty(window, 'location', {
        value: { href: '', pathname: '/', search: '' },
        writable: true,
      });
      mockApiClient.getToken.mockReturnValue('valid-token');
      mockApiClient.get.mockResolvedValue({ success: true, data: { id: 1, name: 'U', email: 'u@example.com' } });
      mockApiClient.post.mockResolvedValue({ success: true });
      await authManager.checkAuth('user');

      const handler = vi.fn();
      authManager.on('authStateChange', handler);
      await authManager.logout();

      expect(handler).toHaveBeenCalledTimes(1);
    });
  });
});
