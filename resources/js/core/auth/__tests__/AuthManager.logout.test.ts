/**
 * AuthManager.logout() 이동 경로 테스트
 *
 * 로그아웃 응답이 `data.redirect_url` 을 실으면(외부 인증의 IdP 로그아웃 등) 같은 출처 경로일 때만 그 경로로 가고,
 * 없거나 외부 주소면 지금처럼 로그인 화면으로 간다. 토큰과 상태는 어느 경우든 지운다.
 *
 * @since engine-v1.93.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { AuthManager } from '../AuthManager';
import * as ApiClientModule from '../../api/ApiClient';

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(),
}));

describe('AuthManager.logout — 로그아웃 뒤 이동', () => {
  let authManager: AuthManager;
  let mockApiClient: any;
  let locationMock: { href: string; pathname: string; search: string };

  beforeEach(async () => {
    AuthManager.resetInstance();
    authManager = AuthManager.getInstance();

    mockApiClient = {
      getToken: vi.fn().mockReturnValue('valid-token'),
      setToken: vi.fn(),
      removeToken: vi.fn(),
      get: vi.fn().mockResolvedValue({ success: true, data: { uuid: 'u-1', name: 'User', email: 'user@example.test' } }),
      post: vi.fn(),
    };
    (ApiClientModule.getApiClient as any).mockReturnValue(mockApiClient);

    locationMock = { href: '', pathname: '/mypage', search: '' };
    Object.defineProperty(window, 'location', { value: locationMock, writable: true });

    await authManager.checkAuth('user');
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  /**
   * @scenario redirect=none
   * @effects logout_redirect_falls_back_to_login
   */
  it('이동 경로가 없으면 로그인 화면으로 간다', async () => {
    mockApiClient.post.mockResolvedValue({ success: true, data: { redirect_url: null } });

    await authManager.logout();

    expect(locationMock.href).toContain('/login');
    expect(mockApiClient.removeToken).toHaveBeenCalled();
    expect(authManager.isAuthenticated()).toBe(false);
  });

  /**
   * @scenario redirect=same_origin_path
   * @effects logout_redirect_follows_server_path
   */
  it('같은 출처 경로면 그 경로로 간다', async () => {
    mockApiClient.post.mockResolvedValue({ success: true, data: { redirect_url: '/plugins/sirsoft-sso/logout?t=abc' } });

    await authManager.logout();

    expect(locationMock.href).toBe('/plugins/sirsoft-sso/logout?t=abc');
    expect(mockApiClient.removeToken).toHaveBeenCalled();
  });

  /**
   * @scenario redirect=external|protocol_relative
   * @effects logout_external_redirect_ignored
   */
  it('외부 주소는 무시하고 로그인 화면으로 간다', async () => {
    for (const bad of ['https://idp.example/logout', '//idp.example/logout', 'javascript:alert(1)']) {
      await authManager.checkAuth('user');
      locationMock.href = '';
      mockApiClient.post.mockResolvedValue({ success: true, data: { redirect_url: bad } });

      await authManager.logout();

      expect(locationMock.href).toContain('/login');
    }
  });

  /**
   * @scenario response=api_failure
   * @effects logout_redirect_falls_back_to_login
   */
  it('로그아웃 API 가 실패해도 로그인 화면으로 간다', async () => {
    mockApiClient.post.mockRejectedValue(new Error('network'));

    await authManager.logout();

    expect(locationMock.href).toContain('/login');
    expect(mockApiClient.removeToken).toHaveBeenCalled();
  });
});
