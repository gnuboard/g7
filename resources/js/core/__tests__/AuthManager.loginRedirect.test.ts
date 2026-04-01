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
