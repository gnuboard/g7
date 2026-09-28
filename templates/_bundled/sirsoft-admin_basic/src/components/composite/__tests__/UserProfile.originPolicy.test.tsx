/**
 * UserProfile 로그아웃 요청·이동 대상 출처 테스트 (KVE-2026-2350)
 *
 * `logoutEndpoint`·`redirectPath` 는 레이아웃 prop 이라 편집 권한만 있는 계정이 표현식으로 외부 주소를
 * 넣을 수 있다(저장 검사는 조립된 주소를 다 잡지 못한다). 로그아웃을 누르면 관리자 토큰이 그 주소로
 * 가거나(`logoutEndpoint`), 실행 스킴 주소로 이동해 관리자 화면에서 스크립트가 돌았다(`redirectPath`).
 * 토큰은 동일 출처 대상에만 싣고, 이동은 동일 출처 주소만 따른다(아니면 기본 로그인 경로).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { UserProfile, User } from '../UserProfile';

const ORIGIN = 'http://localhost:3000';

const mockUser: User = { name: 'John Doe', email: 'john@example.com', role: 'Administrator' };

describe('UserProfile — 로그아웃 요청·이동 대상 출처', () => {
  const originalLocation = window.location;
  const originalApi = (window as any).G7Core?.api;
  let requests: Array<{ url: string; headers: Record<string, string> }>;

  beforeEach(() => {
    Object.defineProperty(window, 'location', {
      configurable: true,
      writable: true,
      value: { href: `${ORIGIN}/admin`, origin: ORIGIN } as unknown as Location,
    });
    // 코어 판정이 없는 환경(폴백 경로)으로 고정한다
    (window as any).G7Core.api = {};
    localStorage.setItem('auth_token', 'ADMIN-TOKEN');
    document.cookie = 'XSRF-TOKEN=xsrf-value; path=/';
    requests = [];
    global.fetch = vi.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
      requests.push({ url: String(url), headers: (init?.headers ?? {}) as Record<string, string> });
      return { ok: true, statusText: 'OK' } as Response;
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, writable: true, value: originalLocation });
    (window as any).G7Core.api = originalApi;
    localStorage.clear();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  });

  const logout = async (props: Record<string, string>) => {
    const user = userEvent.setup();
    const { unmount } = render(<UserProfile user={mockUser} {...props} />);
    await user.click(screen.getByText('John Doe').closest('button')!);
    await user.click(screen.getByText('Logout'));
    unmount();
  };

  /**
   * @effects extension_token_same_origin_only
   */
  it('교차 출처 logoutEndpoint 에는 Bearer·XSRF 를 싣지 않는다', async () => {
    // @scenario sink=user_profile_logout, target=cross_origin
    for (const logoutEndpoint of ['https://evil.example/logout', '//evil.example/logout', '/\\evil.example/logout']) {
      requests = [];
      await logout({ logoutEndpoint });

      expect(requests).toHaveLength(1);
      expect(requests[0].url).toBe(logoutEndpoint);
      expect(requests[0].headers.Authorization).toBeUndefined();
      expect(requests[0].headers['X-XSRF-TOKEN']).toBeUndefined();
    }
  });

  /**
   * @effects cross_origin_redirect_falls_back_to_default
   */
  it('동일 출처가 아닌 redirectPath 는 따르지 않고 기본 로그인 경로로 이동한다', async () => {
    // @scenario sink=user_profile_redirect, target=cross_origin
    for (const redirectPath of ['javascript:alert(1)', ' JaVaScRiPt:alert(1)', 'https://evil.example/', '//evil.example/']) {
      window.location.href = `${ORIGIN}/admin`;
      await logout({ redirectPath });

      expect(window.location.href).toBe('/admin/login');
    }
  });

  /**
   * @effects extension_token_same_origin_only, cross_origin_redirect_falls_back_to_default
   */
  it('동일 출처 대상은 종전대로 토큰을 싣고 지정 경로로 이동한다 (정상 회귀)', async () => {
    // @scenario sink=user_profile_logout, target=same_origin
    // @scenario sink=user_profile_redirect, target=same_origin
    await logout({ redirectPath: '/admin/login?reason=logout' });

    expect(requests[0].url).toBe('/api/admin/auth/logout');
    expect(requests[0].headers.Authorization).toBe('Bearer ADMIN-TOKEN');
    expect(requests[0].headers['X-XSRF-TOKEN']).toBe('xsrf-value');
    expect(window.location.href).toBe('/admin/login?reason=logout');
  });
});
