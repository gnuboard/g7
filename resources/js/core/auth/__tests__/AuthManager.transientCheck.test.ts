/**
 * @file AuthManager.transientCheck.test.ts
 * @description 인증 확인이 서버 일시 오류(5xx)나 네트워크 오류를 받았을 때 「로그인 안 됨」으로 보지 않는지
 *
 * 개발 서버에서 라우트 캐시를 다시 만드는 순간(`bootstrap/cache/routes-v7.php` 가 잠깐 없음) 사용자 조회가
 * 500 을 받았고, 종전 `checkAuth` 는 모든 오류를 미인증으로 처리해 유효한 토큰을 가진 관리자를 로그인 화면으로
 * 보냈다. 이제 일시 오류는 짧게 다시 묻고, 그래도 안 되면 「확인 불가」로 남긴다. 401 은 종전처럼 미인증이다.
 *
 * @scenario-manifest tests/scenarios/auth-transient-check.yaml
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthManager, AuthUser } from '../AuthManager';
import * as ApiClientModule from '../../api/ApiClient';

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(),
}));

const user: AuthUser = { uuid: 'u-1', name: '관리자', email: 'admin@example.test' };

/**
 * axios 형태의 HTTP 오류
 *
 * @param status 응답 상태
 * @returns 오류 객체
 */
const httpError = (status: number): any => Object.assign(new Error(`HTTP ${status}`), { response: { status, data: {} } });

/**
 * 응답이 없는 네트워크 오류(axios `ERR_NETWORK`)
 *
 * @returns 오류 객체
 */
const networkError = (): any => Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK', isAxiosError: true });

describe('[case:network-auth-check-transient-5xx-logout] AuthManager — 인증 확인의 일시 오류', () => {
  let authManager: AuthManager;
  let api: any;

  beforeEach(() => {
    vi.useFakeTimers();
    AuthManager.resetInstance();
    authManager = AuthManager.getInstance();
    api = {
      getToken: vi.fn().mockReturnValue('valid-token'),
      setToken: vi.fn(),
      removeToken: vi.fn(),
      get: vi.fn(),
      post: vi.fn(),
    };
    (ApiClientModule.getApiClient as any).mockReturnValue(api);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
  });

  /**
   * 확인을 시작하고 재시도 대기를 모두 흘려보낸다.
   *
   * @param run 확인 호출
   * @returns 확인 결과
   */
  const settle = async <T>(run: () => Promise<T>): Promise<T> => {
    const pending = run();
    await vi.runAllTimersAsync();
    return pending;
  };

  // @scenario failure=server_5xx_then_ok
  // @effects transient_auth_failure_retried
  it('500 뒤에 성공하면 다시 물어 인증 상태가 된다', async () => {
    api.get.mockRejectedValueOnce(httpError(500)).mockResolvedValueOnce({ success: true, data: user });

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(true);
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(authManager.isAuthenticated()).toBe(true);
    expect(authManager.getLastCheckStatus('admin')).toBe('authenticated');
  });

  // @scenario failure=network_then_ok
  // @effects transient_auth_failure_retried
  it('네트워크 오류 뒤에 성공하면 다시 물어 인증 상태가 된다', async () => {
    api.get.mockRejectedValueOnce(networkError()).mockResolvedValueOnce({ success: true, data: user });

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(true);
    expect(api.get).toHaveBeenCalledTimes(2);
  });

  // @scenario failure=server_5xx_persistent
  // @effects transient_auth_failure_reported_unavailable
  it('5xx 가 계속되면 몇 번만 다시 묻고 「확인 불가」로 남기며 토큰은 지우지 않는다', async () => {
    api.get.mockRejectedValue(httpError(503));

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(false);
    expect(api.get).toHaveBeenCalledTimes(4);
    expect(authManager.getLastCheckStatus('admin')).toBe('unavailable');
    expect(api.removeToken).not.toHaveBeenCalled();
  });

  // @scenario failure=server_5xx_persistent_with_session
  // @effects transient_auth_failure_keeps_session
  it('이미 인증된 세션이면 5xx 가 계속돼도 세션을 유지한다', async () => {
    api.get.mockResolvedValueOnce({ success: true, data: user });
    await settle(() => authManager.checkAuth('admin'));
    const changes: any[] = [];
    authManager.on('authStateChange', (state: any) => changes.push(state));
    api.get.mockRejectedValue(httpError(500));

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(true);
    expect(authManager.isAuthenticated()).toBe(true);
    expect(authManager.getUser()).toEqual(user);
    expect(changes).toEqual([]);
  });

  // #869 λ 4부 16 관찰 4: 서버 요청 제한(429)을 인증 확인이 받으면 4xx 라 미인증으로 보고 로그인 화면으로 보냈다
  // @scenario failure=rate_limited_429
  // @effects transient_auth_failure_retried
  it('429 뒤에 성공하면 다시 물어 인증 상태가 되고 토큰을 지우지 않는다', async () => {
    api.get.mockRejectedValueOnce(httpError(429)).mockResolvedValueOnce({ success: true, data: user });

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(true);
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(authManager.getLastCheckStatus('admin')).toBe('authenticated');
    expect(api.removeToken).not.toHaveBeenCalled();
  });

  // @scenario failure=rate_limited_429
  // @effects transient_auth_failure_keeps_session
  it('이미 인증된 세션이면 429 가 계속돼도 세션을 유지한다', async () => {
    api.get.mockResolvedValueOnce({ success: true, data: user });
    await settle(() => authManager.checkAuth('admin'));
    api.get.mockRejectedValue(httpError(429));

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(true);
    expect(authManager.isAuthenticated()).toBe(true);
    expect(api.removeToken).not.toHaveBeenCalled();
  });

  // @scenario failure=rate_limited_429
  // @effects transient_auth_failure_retried
  it('429 의 Retry-After(초)를 다시 묻는 간격으로 따르되 5초를 넘기지 않는다', async () => {
    const limited = (retryAfter: string): any => Object.assign(new Error('HTTP 429'), { response: { status: 429, data: {}, headers: { 'retry-after': retryAfter } } });
    api.get.mockRejectedValueOnce(limited('2')).mockRejectedValueOnce(limited('120')).mockResolvedValueOnce({ success: true, data: user });

    const pending = authManager.checkAuth('admin');
    await vi.advanceTimersByTimeAsync(1999);
    expect(api.get).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(api.get).toHaveBeenCalledTimes(2);
    // 120초는 5초로 줄인다 — 부팅 인증 확인이 화면을 오래 막지 않게
    await vi.advanceTimersByTimeAsync(4999);
    expect(api.get).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(api.get).toHaveBeenCalledTimes(3);

    expect(await pending).toBe(true);
  });

  // @scenario failure=unauthorized_401
  // @effects real_auth_failure_still_unauthenticated
  it('401 은 다시 묻지 않고 종전처럼 미인증이다(갱신 실패 시)', async () => {
    api.get.mockRejectedValue(httpError(401));
    vi.spyOn(authManager, 'refreshToken').mockResolvedValue(false);

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(false);
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(authManager.getLastCheckStatus('admin')).toBe('unauthenticated');
  });

  // @scenario failure=csrf_419
  // @effects real_auth_failure_still_unauthenticated
  it('419 는 다시 묻지 않고 미인증이며, 인증돼 있던 세션도 비운다', async () => {
    api.get.mockResolvedValueOnce({ success: true, data: user });
    await settle(() => authManager.checkAuth('admin'));
    api.get.mockRejectedValue(httpError(419));

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(false);
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(authManager.isAuthenticated()).toBe(false);
    expect(authManager.getLastCheckStatus('admin')).toBe('unauthenticated');
  });

  // @scenario failure=non_http_exception
  // @effects real_auth_failure_still_unauthenticated
  it('HTTP 오류가 아닌 예외는 다시 묻지 않고 종전처럼 미인증이다', async () => {
    api.get.mockRejectedValue(new TypeError('boom'));

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(false);
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(authManager.getLastCheckStatus('admin')).toBe('unauthenticated');
  });

  // @scenario failure=listener_throws
  // @effects transient_auth_failure_retried
  it('authStateChange 구독자가 던져도 다시 묻지 않고 인증 상태가 된다', async () => {
    api.get.mockResolvedValue({ success: true, data: user });
    authManager.on('authStateChange', () => {
      throw new Error('subscriber');
    });

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(true);
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(authManager.isAuthenticated()).toBe(true);
  });

  // @scenario failure=server_5xx_then_ok
  // @effects auth_check_progress_announced
  it('다시 묻기 시작할 때와 끝날 때 한 번씩 알린다(첫 시도 성공이면 알리지 않음)', async () => {
    const events: string[] = [];
    authManager.on('authCheckRetry', ({ type }: any) => events.push(`retry:${type}`));
    authManager.on('authCheckRetryEnd', ({ type, status }: any) => events.push(`end:${type}:${status}`));

    api.get.mockResolvedValueOnce({ success: true, data: user });
    await settle(() => authManager.checkAuth('admin'));
    expect(events).toEqual([]);

    api.get.mockRejectedValueOnce(httpError(500)).mockRejectedValueOnce(httpError(502)).mockResolvedValueOnce({ success: true, data: user });
    await settle(() => authManager.checkAuth('admin'));
    expect(events).toEqual(['retry:admin', 'end:admin:authenticated']);

    events.length = 0;
    AuthManager.resetInstance();
    const fresh = AuthManager.getInstance();
    fresh.on('authCheckRetry', ({ type }: any) => events.push(`retry:${type}`));
    fresh.on('authCheckRetryEnd', ({ type, status }: any) => events.push(`end:${type}:${status}`));
    api.get.mockRejectedValue(httpError(503));
    await settle(() => fresh.checkAuth('admin'));
    expect(events).toEqual(['retry:admin', 'end:admin:unavailable']);
  });

  // @scenario failure=server_5xx_persistent
  // @effects recent_unavailable_reused
  it('「확인 불가」 결론은 정해진 시간 동안만 최근 결론으로 본다', async () => {
    api.get.mockRejectedValue(httpError(503));
    await settle(() => authManager.checkAuth('admin'));

    expect(authManager.isRecentlyUnavailable('admin')).toBe(true);
    expect(authManager.isRecentlyUnavailable('user')).toBe(false);

    vi.advanceTimersByTime(AuthManager.UNAVAILABLE_REUSE_MS + 1);
    expect(authManager.isRecentlyUnavailable('admin')).toBe(false);
  });

  // @scenario failure=server_5xx_persistent
  // @effects auth_check_progress_announced
  it('모든 확인은 시작과 끝을 한 번씩 알린다(첫 시도 성공, 재시도 뒤 확인 불가)', async () => {
    const events: string[] = [];
    authManager.on('authCheckStart', ({ type }: any) => events.push(`start:${type}`));
    authManager.on('authCheckEnd', ({ type, status }: any) => events.push(`end:${type}:${status}`));

    api.get.mockResolvedValueOnce({ success: true, data: user });
    await settle(() => authManager.checkAuth('admin'));
    expect(events).toEqual(['start:admin', 'end:admin:authenticated']);

    events.length = 0;
    api.get.mockRejectedValue(httpError(503));
    AuthManager.resetInstance();
    const fresh = AuthManager.getInstance();
    fresh.on('authCheckStart', ({ type }: any) => events.push(`start:${type}`));
    fresh.on('authCheckEnd', ({ type, status }: any) => events.push(`end:${type}:${status}`));
    await settle(() => fresh.checkAuth('admin'));
    expect(events).toEqual(['start:admin', 'end:admin:unavailable']);
  });

  // @scenario failure=no_token
  // @effects real_auth_failure_still_unauthenticated
  it('토큰이 없으면 묻지 않고 미인증이다', async () => {
    api.getToken.mockReturnValue(null);

    const result = await settle(() => authManager.checkAuth('admin'));

    expect(result).toBe(false);
    expect(api.get).not.toHaveBeenCalled();
    expect(authManager.getLastCheckStatus('admin')).toBe('unauthenticated');
  });
});
