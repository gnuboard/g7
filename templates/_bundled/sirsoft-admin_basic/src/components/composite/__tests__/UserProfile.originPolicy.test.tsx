/**
 * UserProfile 로그아웃 요청·이동 대상 출처 테스트 (KVE-2026-2350)
 *
 * `logoutEndpoint`·`redirectPath` 는 레이아웃 prop 이라 편집 권한만 있는 계정이 표현식으로 외부 주소를
 * 넣을 수 있다(저장 검사는 조립된 주소를 다 잡지 못한다). 로그아웃을 누르면 관리자 토큰이 그 주소로
 * 가거나(`logoutEndpoint`), 실행 스킴 주소로 이동해 관리자 화면에서 스크립트가 돌았다(`redirectPath`).
 * 토큰은 동일 출처 대상에만 싣고, 이동은 동일 출처 주소만 따른다(아니면 기본 로그인 경로).
 *
 * 컴포넌트는 로그아웃 요청을 코어 `G7Core.api` 로 보내고 토큰 첨부를 코어에 맡긴다. 그래서 이 테스트는
 * 실제 코어 ApiClient 를 `G7Core.api` 로 세우고, 전송 직전 요청(최종 주소·헤더)을 어댑터에서 가로채
 * 판정한다. XSRF 헤더는 axios 가 동일 출처 요청에만 붙이며(브라우저 어댑터 단계), 여기서는 Bearer 만 잰다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ApiClient from '@core/api/ApiClient';
import { UserProfile, User } from '../UserProfile';

interface SentRequest {
  method: string;
  url: string;
  origin: string;
  headers: Record<string, unknown>;
}

/**
 * 실제 코어 ApiClient 를 만들고 전송 직전 요청을 가로챈다 (네트워크 없음).
 *
 * @param sent 가로챈 요청을 쌓을 배열
 * @return 코어 ApiClient
 */
function createCapturingCoreApi(sent: SentRequest[]): ApiClient {
  const client = new ApiClient();
  const instance = client.getInstance();
  instance.defaults.adapter = async (config: any) => {
    const full = instance.getUri({ baseURL: config.baseURL, url: config.url });
    const headers = typeof config.headers?.toJSON === 'function' ? config.headers.toJSON() : { ...config.headers };
    sent.push({
      method: String(config.method).toUpperCase(),
      url: config.url,
      origin: new URL(full, `${window.location.origin}/`).origin,
      headers,
    });
    return { data: { success: true }, status: 200, statusText: 'OK', headers: {}, config, request: {} };
  };
  return client;
}

const ORIGIN = 'http://localhost:3000';

const mockUser: User = { name: 'John Doe', email: 'john@example.com', role: 'Administrator' };

describe('UserProfile — 로그아웃 요청·이동 대상 출처', () => {
  const originalLocation = window.location;
  const originalApi = (window as any).G7Core?.api;
  let requests: SentRequest[];

  beforeEach(() => {
    Object.defineProperty(window, 'location', {
      configurable: true,
      writable: true,
      value: { href: `${ORIGIN}/admin`, origin: ORIGIN } as unknown as Location,
    });
    requests = [];
    (window as any).G7Core.api = createCapturingCoreApi(requests);
    localStorage.setItem('auth_token', 'ADMIN-TOKEN');
    global.fetch = vi.fn().mockRejectedValue(new Error('raw fetch must not be used')) as unknown as typeof fetch;
  });

  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, writable: true, value: originalLocation });
    (window as any).G7Core.api = originalApi;
    localStorage.clear();
  });

  const logout = async (props: Record<string, string>) => {
    const user = userEvent.setup();
    const { unmount } = render(<UserProfile user={mockUser} {...props} />);
    await user.click(screen.getByText('John Doe').closest('button')!);
    await user.click(screen.getByText('Logout'));
    unmount();
  };

  // @scenario sink=user_profile_logout, target=cross_origin
  // @effects extension_token_same_origin_only
  it('교차 출처 logoutEndpoint 에는 Bearer 를 싣지 않는다', async () => {
    for (const logoutEndpoint of ['https://evil.example/logout', '//evil.example/logout']) {
      requests.length = 0;
      // 앞선 로그아웃이 이동시킨 주소를 되돌린다 (이 테스트의 location 은 평범한 객체)
      window.location.href = `${ORIGIN}/admin`;
      await logout({ logoutEndpoint });

      expect(requests).toHaveLength(1);
      expect(new URL(requests[0].origin).hostname).toBe('evil.example');
      expect(requests[0].headers.Authorization).toBeUndefined();
    }
    // 백슬래시 변형(/\host)은 외부로 나가지 않고 자기 서버 /api 아래로 간다
    requests.length = 0;
    window.location.href = `${ORIGIN}/admin`;
    await logout({ logoutEndpoint: '/\\evil.example/logout' });
    expect(requests).toHaveLength(1);
    expect(requests[0].origin).toBe(ORIGIN);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  // @scenario sink=user_profile_redirect, target=cross_origin
  // @effects cross_origin_redirect_falls_back_to_default
  it('동일 출처가 아닌 redirectPath 는 따르지 않고 기본 로그인 경로로 이동한다', async () => {
    for (const redirectPath of ['javascript:alert(1)', ' JaVaScRiPt:alert(1)', 'https://evil.example/', '//evil.example/']) {
      window.location.href = `${ORIGIN}/admin`;
      await logout({ redirectPath });

      expect(window.location.href).toBe('/admin/login');
    }
  });

  // @scenario sink=user_profile_logout, target=same_origin
  // @effects extension_token_same_origin_only, cross_origin_redirect_falls_back_to_default
  // @scenario sink=user_profile_redirect, target=same_origin
  // @effects extension_token_same_origin_only, cross_origin_redirect_falls_back_to_default
  it('동일 출처 대상은 종전대로 토큰을 싣고 지정 경로로 이동한다 (정상 회귀)', async () => {
    await logout({ redirectPath: '/admin/login?reason=logout' });

    expect(requests[0].method).toBe('POST');
    expect(requests[0].url).toBe('/api/admin/auth/logout');
    expect(requests[0].origin).toBe(ORIGIN);
    expect(requests[0].headers.Authorization).toBe('Bearer ADMIN-TOKEN');
    expect(window.location.href).toBe('/admin/login?reason=logout');
    // 로그아웃 뒤 토큰은 코어 공개 API(removeToken)로 지워진다
    expect(localStorage.getItem('auth_token')).toBeNull();
  });
});
