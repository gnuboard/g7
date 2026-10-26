/**
 * AuthManager.loginWithHandoff() 테스트
 *
 * 외부 인증(소셜 로그인 등)이 발급한 일회용 인계 코드를 교환하면 서버는 비밀번호 로그인과 같은 세 형태의 200
 * (인증 완료 · 2단계 인증 · 비밀번호 변경)을 돌려준다. 결과 해석도 `login()` 과 같아야 로그인 화면이 같은 분기를 쓴다.
 *
 * @since engine-v1.84.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { AuthManager, AuthUser } from '../AuthManager';
import * as ApiClientModule from '../../api/ApiClient';

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(),
}));

describe('AuthManager.loginWithHandoff', () => {
  let authManager: AuthManager;
  let mockApiClient: any;

  beforeEach(() => {
    AuthManager.resetInstance();
    authManager = AuthManager.getInstance();

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

  /**
   * @scenario response=authenticated
   * @effects handoff_establishes_session, handoff_result_matches_login_result
   */
  it('인증 완료 응답이면 세션을 열고 서버 안내 필드를 extras 로 넘긴다', async () => {
    const user: AuthUser = { uuid: 'u-1', name: '회원', email: 'm@example.test' };
    mockApiClient.post.mockResolvedValue({
      success: true,
      data: { token: 'tok', token_type: 'Bearer', user, dormancy_restored: true },
    });

    const result = await authManager.loginWithHandoff('user', 'CODE123');

    expect(mockApiClient.post).toHaveBeenCalledWith('/auth/login/handoff', { code: 'CODE123' }, undefined);
    expect(result).toEqual({ status: 'authenticated', user, extras: { dormancy_restored: true } });
    expect(mockApiClient.setToken).toHaveBeenCalledWith('tok');
    expect(authManager.isAuthenticated()).toBe(true);
  });

  /**
   * @scenario response=two_factor_required
   * @effects handoff_two_factor_challenge_without_session
   */
  it('2단계 인증 응답이면 세션 없이 challenge 를 돌려준다', async () => {
    mockApiClient.post.mockResolvedValue({
      success: true,
      data: { two_factor_required: true, challenge_id: 'c-1', provider_id: 'g7:core.mail', expires_at: null },
    });

    const result = await authManager.loginWithHandoff('user', 'CODE');

    expect(result).toEqual({
      status: 'two_factor_required',
      challenge: { challengeId: 'c-1', providerId: 'g7:core.mail', expiresAt: null },
    });
    expect(mockApiClient.setToken).not.toHaveBeenCalled();
    expect(authManager.isAuthenticated()).toBe(false);
  });

  /**
   * @scenario response=password_change_required
   * @effects handoff_password_change_challenge_without_session
   */
  it('비밀번호 변경 응답이면 세션 없이 변경 단계를 돌려준다', async () => {
    mockApiClient.post.mockResolvedValue({
      success: true,
      data: { password_change_required: true, challenge_id: 'p-1', reason: 'expired', can_postpone: true, postpone_days: 30, expired_at: '2026-10-01T00:00:00+09:00', expires_at: null },
    });

    const result = await authManager.loginWithHandoff('user', 'CODE');

    expect(result.status).toBe('password_change_required');
    expect(mockApiClient.setToken).not.toHaveBeenCalled();
  });

  /**
   * @scenario response=invalid_code
   * @effects handoff_failure_keeps_state
   */
  it('코드 무효(422)면 서버 메시지로 던지고 열린 상태를 지우지 않는다', async () => {
    const error: any = new Error('Request failed');
    error.response = { status: 422, data: { message: '로그인 정보가 만료되었거나 올바르지 않습니다.', errors: { code: ['…'] } } };
    mockApiClient.post.mockRejectedValue(error);
    const clearSpy = vi.spyOn(authManager as any, 'clearState');

    await expect(authManager.loginWithHandoff('user', 'BAD')).rejects.toMatchObject({
      message: '로그인 정보가 만료되었거나 올바르지 않습니다.',
      status: 422,
    });
    expect(clearSpy).not.toHaveBeenCalled();
  });

  /**
   * 관리자 인계는 관리자 경로로 교환하고 관리자 세션을 연다 (engine-v1.85.0 — 종전에는 인계 엔드포인트가 없어 던졌다).
   *
   * @scenario auth_type=admin, response=authenticated
   * @effects handoff_admin_exchanges_on_admin_endpoint
   */
  it('관리자 타입은 관리자 인계 경로로 교환하고 세션을 연다', async () => {
    const user = { uuid: 'a-1', name: '관리자', email: 'a@example.test' };
    mockApiClient.post.mockResolvedValue({ success: true, data: { token: 'tok-admin', token_type: 'Bearer', user } });

    const result = await authManager.loginWithHandoff('admin', 'CODE');

    expect(mockApiClient.post).toHaveBeenCalledWith('/auth/admin/login/handoff', { code: 'CODE' }, undefined);
    expect(result.status).toBe('authenticated');
    expect(mockApiClient.setToken).toHaveBeenCalledWith('tok-admin');
    expect(authManager.isAuthenticated()).toBe(true);
  });

  /**
   * @scenario auth_type=admin, response=two_factor_required
   * @effects handoff_admin_two_factor_without_session
   */
  it('관리자 인계의 2단계 인증 응답은 세션 없이 challenge 를 돌려준다', async () => {
    mockApiClient.post.mockResolvedValue({
      success: true,
      data: { two_factor_required: true, challenge_id: 'c-a', provider_id: 'g7:core.mail', expires_at: null },
    });

    const result = await authManager.loginWithHandoff('admin', 'CODE');

    expect(result.status).toBe('two_factor_required');
    expect(mockApiClient.setToken).not.toHaveBeenCalled();
  });

  /**
   * @scenario auth_type=admin, response=invalid_code
   * @effects handoff_admin_invalid_code_rejects
   */
  it('관리자 인계 코드 무효(422)면 서버 메시지로 던진다', async () => {
    const error: any = new Error('Request failed');
    error.response = { status: 422, data: { message: '로그인 정보가 만료되었거나 올바르지 않습니다.', errors: { code: ['…'] } } };
    mockApiClient.post.mockRejectedValue(error);

    await expect(authManager.loginWithHandoff('admin', 'BAD')).rejects.toMatchObject({
      message: '로그인 정보가 만료되었거나 올바르지 않습니다.',
      status: 422,
    });
  });

  /**
   * @scenario header=global_headers
   * @effects handoff_forwards_headers
   */
  it('추가 헤더(장바구니 키 등)를 그대로 싣는다', async () => {
    mockApiClient.post.mockResolvedValue({ success: true, data: { token: 't', user: { uuid: 'u' } } });

    await authManager.loginWithHandoff('user', 'C', { headers: { 'X-Cart-Key': 'ck_1' } });

    expect(mockApiClient.post).toHaveBeenCalledWith('/auth/login/handoff', { code: 'C' }, { headers: { 'X-Cart-Key': 'ck_1' } });
  });
});
