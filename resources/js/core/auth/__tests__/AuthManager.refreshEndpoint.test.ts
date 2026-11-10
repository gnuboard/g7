/**
 * 회귀: 사용자 화면 토큰 갱신 주소가 실제 라우트와 같다 (#883 후속)
 *
 * 결함: 사용자 설정의 갱신 주소가 `/auth/refresh` 였는데 서버 라우트는 `POST /api/user/auth/refresh` 뿐이라(같은 묶음의
 * `/auth/user`·`/auth/logout` 은 별칭이 있음) 사용자 화면의 갱신 요청이 늘 404 였다 — 만료가 가까운 정상 토큰도 갱신되지 않았다.
 * 관리자 주소(`/admin/auth/refresh`)는 실제 라우트와 같다.
 *
 * @scenario-manifest tests/scenarios/auth-refresh-endpoint.yaml
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthManager } from '../AuthManager';
import * as ApiClientModule from '../../api/ApiClient';

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(),
}));

describe('AuthManager 토큰 갱신 주소', () => {
  let api: any;

  beforeEach(() => {
    AuthManager.resetInstance();
    api = {
      getToken: vi.fn(() => 'valid-token'),
      setToken: vi.fn(),
      removeToken: vi.fn(),
      get: vi.fn(async () => ({ success: true, data: { id: 1, name: 'U' } })),
      post: vi.fn(async () => ({ success: true, data: { token: 'new-token' } })),
    };
    (ApiClientModule.getApiClient as any).mockReturnValue(api);
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  /**
   * @scenario auth_type=user
   * @effects user_refresh_hits_existing_route
   */
  it('사용자 갱신은 /user/auth/refresh 로 보낸다', async () => {
    const auth = AuthManager.getInstance();
    await auth.checkAuth('user');

    await expect(auth.refreshToken()).resolves.toBe(true);
    expect(api.post).toHaveBeenCalledWith('/user/auth/refresh');
  });

  /**
   * @scenario auth_type=admin
   * @effects admin_refresh_unchanged
   */
  it('관리자 갱신은 /admin/auth/refresh 그대로다', async () => {
    const auth = AuthManager.getInstance();
    await auth.checkAuth('admin');

    await expect(auth.refreshToken()).resolves.toBe(true);
    expect(api.post).toHaveBeenCalledWith('/admin/auth/refresh');
  });
});
