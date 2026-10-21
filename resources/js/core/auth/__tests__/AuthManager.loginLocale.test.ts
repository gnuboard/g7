/**
 * AuthManager — 로그인 직후 회원 언어가 꺼진 언어일 때 (engine-v1.81.0, #148 D8)
 *
 * 회원 언어(users.language)가 언어팩을 끈 언어(`fil`)면 종전에는 그 값을 그대로 `g7_locale` 에 쓰고
 * `changeLocale('fil')` 을 불러 화면이 영어로 떨어졌다. 지원 목록에 없으면 사이트 기본 언어로 열고,
 * 회원 정보의 언어 설정은 바꾸지 않는다(`persist: false`).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { AuthManager, AuthUser } from '../AuthManager';
import * as ApiClientModule from '../../api/ApiClient';

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(),
}));

describe('로그인 직후 회원 언어 처리', () => {
  let authManager: AuthManager;
  let changeLocale: ReturnType<typeof vi.fn>;

  /**
   * 로그인 응답을 회원 언어와 함께 준비합니다.
   *
   * @param language 회원 언어
   */
  function mockLogin(language: string): void {
    const user: AuthUser = { id: 1, name: 'QA', email: 'qa@example.com', language } as AuthUser;
    (ApiClientModule.getApiClient as any).mockReturnValue({
      getToken: vi.fn(),
      setToken: vi.fn(),
      removeToken: vi.fn(),
      get: vi.fn(),
      post: vi.fn().mockResolvedValue({ success: true, data: { token: 't', user } }),
    });
  }

  beforeEach(() => {
    AuthManager.resetInstance();
    authManager = AuthManager.getInstance();
    localStorage.clear();
    changeLocale = vi.fn().mockResolvedValue(undefined);
    (window as any).__templateApp = {
      changeLocale,
      globalState: { appConfig: { supportedLocales: ['ko', 'en'], siteLocale: 'en' } },
    };
  });

  afterEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    delete (window as any).__templateApp;
  });

  // @scenario case=login_member_unsupported
  // @effects login_with_unsupported_member_language_uses_site_default
  it('꺼진 회원 언어(fil)면 사이트 기본 언어(en)로 바꾸고 회원 언어는 저장하지 않는다', async () => {
    localStorage.setItem('g7_locale', 'ko');
    mockLogin('fil');

    await authManager.login('user', { email: 'qa@example.com', password: 'pw' });

    expect(localStorage.getItem('g7_locale')).toBe('en');
    expect(changeLocale).toHaveBeenCalledTimes(1);
    expect(changeLocale).toHaveBeenCalledWith('en', { persist: false });
  });

  // @scenario case=login_member_unsupported
  // @effects login_with_unsupported_member_language_uses_site_default
  it('지원되는 회원 언어는 종전대로 그 언어로 바꾼다', async () => {
    localStorage.setItem('g7_locale', 'ko');
    mockLogin('en');

    await authManager.login('user', { email: 'qa@example.com', password: 'pw' });

    expect(localStorage.getItem('g7_locale')).toBe('en');
    expect(changeLocale).toHaveBeenCalledWith('en');
  });

  // @scenario case=login_member_unsupported
  // @effects login_with_unsupported_member_language_uses_site_default
  it('꺼진 회원 언어를 대신한 사이트 기본 언어가 이미 화면 언어면 다시 바꾸지 않는다', async () => {
    localStorage.setItem('g7_locale', 'en');
    mockLogin('fil');

    await authManager.login('user', { email: 'qa@example.com', password: 'pw' });

    expect(localStorage.getItem('g7_locale')).toBe('en');
    expect(changeLocale).not.toHaveBeenCalled();
  });
});
