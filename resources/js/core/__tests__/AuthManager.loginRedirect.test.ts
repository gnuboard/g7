/**
 * AuthManager 로그인 리다이렉트 테스트
 *
 * Issue #57: 로그아웃 후 redirect= 에 queryString이 미포함되는 문제 수정 검증
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { AuthManager, type AuthType } from '../auth/AuthManager';

// Mock ApiClient
const mockApiClient = {
  post: vi.fn().mockResolvedValue({}),
  get: vi.fn().mockResolvedValue({}),
  removeToken: vi.fn(),
  setToken: vi.fn(),
  getToken: vi.fn().mockReturnValue(null),
  setOnUnauthorized: vi.fn(),
};

vi.mock('../api/ApiClient', () => ({
  getApiClient: () => mockApiClient,
}));

vi.mock('../utils/Logger', () => ({
  createLogger: () => ({
    log: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
  }),
}));

describe('AuthManager - 로그인 리다이렉트 (Issue #57)', () => {
  let authManager: AuthManager;

  beforeEach(() => {
    // 싱글톤 인스턴스 리셋
    (AuthManager as any).instance = undefined;
    authManager = AuthManager.getInstance();

    // window.location mock
    Object.defineProperty(window, 'location', {
      value: {
        href: '',
        pathname: '/admin/ecommerce/products',
        search: '?page=1&end_date=2026-02-13&date_type=created_at',
      },
      writable: true,
      configurable: true,
    });

    // G7Core DevTools mock
    (window as any).G7Core = {
      devTools: {
        trackAuthEvent: vi.fn(),
      },
    };

    vi.clearAllMocks();
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  describe('getLoginRedirectUrl', () => {
    it('queryString이 포함된 URL을 올바르게 인코딩하여 redirect 파라미터에 포함해야 한다', () => {
      const returnUrl = '/admin/ecommerce/products?page=1&end_date=2026-02-13&date_type=created_at';
      const result = authManager.getLoginRedirectUrl('admin', returnUrl);

      expect(result).toBe(
        `/admin/login?redirect=${encodeURIComponent(returnUrl)}`
      );
      // queryString이 인코딩되어 포함되었는지 확인
      expect(result).toContain('redirect=');
      expect(result).toContain(encodeURIComponent('?page=1'));
      expect(result).toContain(encodeURIComponent('&end_date=2026-02-13'));
    });

    it('queryString이 없는 URL도 정상 처리해야 한다', () => {
      const returnUrl = '/admin/ecommerce/products';
      const result = authManager.getLoginRedirectUrl('admin', returnUrl);

      expect(result).toBe(
        `/admin/login?redirect=${encodeURIComponent(returnUrl)}`
      );
    });

    it('user 타입에 대해 올바른 loginPath를 사용해야 한다', () => {
      const returnUrl = '/mypage?tab=orders&sort=desc';
      const result = authManager.getLoginRedirectUrl('user', returnUrl);

      expect(result).toBe(
        `/login?redirect=${encodeURIComponent(returnUrl)}`
      );
    });

    it('설정이 없는 타입은 /login 을 반환해야 한다', () => {
      const result = authManager.getLoginRedirectUrl('unknown' as AuthType, '/some/path?q=1');

      expect(result).toBe('/login');
    });
  });

  describe('getLoginRedirectUrl - reason 파라미터 (Issue #301)', () => {
    it('reason 인자가 있으면 쿼리에 reason 파라미터가 결합되어야 한다', () => {
      const returnUrl = '/admin/dashboard';
      const result = authManager.getLoginRedirectUrl('admin', returnUrl, 'session_expired');

      expect(result).toBe(
        `/admin/login?redirect=${encodeURIComponent(returnUrl)}&reason=session_expired`
      );
    });

    it('reason 미지정 시 쿼리에 reason 이 포함되지 않아야 한다 (회귀)', () => {
      const returnUrl = '/admin/dashboard';
      const result = authManager.getLoginRedirectUrl('admin', returnUrl);

      expect(result).toBe(`/admin/login?redirect=${encodeURIComponent(returnUrl)}`);
      expect(result).not.toContain('reason=');
    });

    it('user 타입 + reason 조합도 정상 처리되어야 한다', () => {
      const returnUrl = '/mypage';
      const result = authManager.getLoginRedirectUrl('user', returnUrl, 'session_expired');

      expect(result).toBe(
        `/login?redirect=${encodeURIComponent(returnUrl)}&reason=session_expired`
      );
    });
  });

  describe('updateConfig - 인증 설정 부분 갱신 (Issue #301 Tier 1)', () => {
    it('updateConfig 로 loginPath 변경 후 getLoginRedirectUrl 이 새 경로를 반환해야 한다', () => {
      authManager.updateConfig('user', { loginPath: '/account/signin' });
      const result = authManager.getLoginRedirectUrl('user', '/me', 'session_expired');

      expect(result).toBe(
        `/account/signin?redirect=${encodeURIComponent('/me')}&reason=session_expired`
      );
    });

    it('admin 타입도 동일하게 커스터마이즈 가능해야 한다', () => {
      authManager.updateConfig('admin', { loginPath: '/admin/auth/signin' });
      const result = authManager.getLoginRedirectUrl('admin', '/admin/dashboard');

      expect(result).toBe(
        `/admin/auth/signin?redirect=${encodeURIComponent('/admin/dashboard')}`
      );
    });

    it('외부 origin URL 은 throw 해야 한다 (open redirect 차단)', () => {
      expect(() =>
        authManager.updateConfig('user', { loginPath: 'https://evil.com/login' })
      ).toThrow(/loginPath/);
    });

    it('protocol-relative URL 은 throw 해야 한다 (open redirect 차단)', () => {
      expect(() =>
        authManager.updateConfig('user', { loginPath: '//evil.com/login' })
      ).toThrow(/loginPath/);
    });

    it('상대 경로(./, ../)도 throw 해야 한다 — / 시작만 허용', () => {
      expect(() =>
        authManager.updateConfig('user', { loginPath: './login' })
      ).toThrow(/loginPath/);
    });

    it('미등록 type 호출은 no-op (throw 하지 않음)', () => {
      expect(() =>
        authManager.updateConfig('unknown' as AuthType, { loginPath: '/foo' })
      ).not.toThrow();
    });

    it('loginPath 외 다른 필드(defaultPath 등)도 갱신 가능해야 한다', () => {
      authManager.updateConfig('user', { defaultPath: '/welcome' });
      const config = authManager.getConfig('user');

      expect(config?.defaultPath).toBe('/welcome');
      // loginPath 는 기본값 유지
      expect(config?.loginPath).toBe('/login');
    });
  });

  describe('logout() - redirect에 queryString 포함', () => {
    it('로그아웃 시 현재 URL의 pathname + search가 redirect에 포함되어야 한다', async () => {
      // 인증 상태 설정 (admin)
      (authManager as any).state = {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', email: 'admin@test.com' },
        type: 'admin',
      };

      await authManager.logout();

      // window.location.href에 redirect 파라미터가 포함되어야 함
      const expectedReturnUrl = '/admin/ecommerce/products?page=1&end_date=2026-02-13&date_type=created_at';
      const expectedHref = `/admin/login?redirect=${encodeURIComponent(expectedReturnUrl)}`;
      expect(window.location.href).toBe(expectedHref);
    });

    it('queryString이 없는 경우에도 정상 동작해야 한다', async () => {
      Object.defineProperty(window, 'location', {
        value: {
          href: '',
          pathname: '/admin/dashboard',
          search: '',
        },
        writable: true,
        configurable: true,
      });

      (authManager as any).state = {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', email: 'admin@test.com' },
        type: 'admin',
      };

      await authManager.logout();

      const expectedHref = `/admin/login?redirect=${encodeURIComponent('/admin/dashboard')}`;
      expect(window.location.href).toBe(expectedHref);
    });

    it('user 타입 로그아웃 시 올바른 loginPath로 리다이렉트해야 한다', async () => {
      Object.defineProperty(window, 'location', {
        value: {
          href: '',
          pathname: '/mypage',
          search: '?tab=orders',
        },
        writable: true,
        configurable: true,
      });

      (authManager as any).state = {
        isAuthenticated: true,
        user: { id: 1, name: 'User', email: 'user@test.com' },
        type: 'user',
      };

      await authManager.logout();

      const expectedReturnUrl = '/mypage?tab=orders';
      const expectedHref = `/login?redirect=${encodeURIComponent(expectedReturnUrl)}`;
      expect(window.location.href).toBe(expectedHref);
    });
  });
});
