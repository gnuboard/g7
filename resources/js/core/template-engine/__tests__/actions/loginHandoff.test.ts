/**
 * ActionDispatcher loginHandoff 액션 테스트
 *
 * 로그인 화면이 `/login?login_handoff={code}` 로 들어오면 `loginHandoff` 액션으로 코드를 교환한다. 반환 모양이
 * `login` 액션과 **키 단위로 같아야** 로그인 화면이 같은 `onSuccess` 분기(2단계 인증 블록·비밀번호 변경 블록·휴면
 * 해제 안내)를 그대로 쓴다.
 *
 * @since engine-v1.91.0
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../../ActionDispatcher';
import { Logger } from '../../../utils/Logger';

const mockLogin = vi.fn();
const mockLoginWithHandoff = vi.fn();

vi.mock('../../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: mockLogin,
      loginWithHandoff: mockLoginWithHandoff,
      logout: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

vi.mock('../../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('ActionDispatcher - loginHandoff', () => {
  let dispatcher: ActionDispatcher;

  beforeEach(() => {
    mockLogin.mockReset();
    mockLoginWithHandoff.mockReset();
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);
  });

  const context = () => ({ state: { _global: {}, _local: {} } });

  const run = async (action: ActionDefinition) => {
    const result = await dispatcher.dispatchAction(action, context() as any);

    if (!result.success) {
      throw result.error ?? new Error('action failed');
    }

    return result.data;
  };

  const loginAction: ActionDefinition = {
    type: 'click',
    handler: 'login',
    target: 'user',
    params: { body: { email: 'm@example.test', password: 'pw' } },
  };

  const handoffAction: ActionDefinition = {
    type: 'click',
    handler: 'loginHandoff',
    target: 'user',
    params: { body: { code: 'CODE' } },
  };

  const results = {
    authenticated: { status: 'authenticated', user: { uuid: 'u-1' }, extras: { dormancy_restored: true } },
    two_factor_required: {
      status: 'two_factor_required',
      challenge: { challengeId: 'c-1', providerId: 'totp', expiresAt: null },
    },
    password_change_required: {
      status: 'password_change_required',
      challenge: { challengeId: 'p-1', reason: 'expired', canPostpone: false, postponeDays: null, expiredAt: null, expiresAt: null },
    },
  } as const;

  for (const [name, loginResult] of Object.entries(results)) {
    /**
     * @scenario response=authenticated|two_factor_required|password_change_required
     * @effects handoff_action_shape_equals_login
     */
    it(`${name} 응답의 반환이 login 액션과 같다`, async () => {
      mockLogin.mockResolvedValue(loginResult);
      mockLoginWithHandoff.mockResolvedValue(loginResult);

      const viaLogin = await run(loginAction);
      const viaHandoff = await run(handoffAction);

      expect(viaHandoff).toEqual(viaLogin);
      expect(Object.keys(viaHandoff).sort()).toEqual(Object.keys(viaLogin).sort());
    });
  }

  /**
   * @scenario response=two_factor_required
   * @effects handoff_action_shape_equals_login
   */
  it('2단계 인증 분기에 provider_id 가 실린다 (인증 앱 분기용)', async () => {
    mockLoginWithHandoff.mockResolvedValue(results.two_factor_required);

    const data = await run(handoffAction);

    expect(data).toMatchObject({ two_factor_required: true, challenge_id: 'c-1', provider_id: 'totp', user: null });
  });

  /**
   * @scenario code=missing
   * @effects handoff_requires_code
   */
  it('code 가 없으면 요청을 보내지 않고 실패한다', async () => {
    await expect(run({ ...handoffAction, params: { body: {} } })).rejects.toThrow('loginHandoff requires code');
    expect(mockLoginWithHandoff).not.toHaveBeenCalled();
  });

  /**
   * @scenario header=global_headers
   * @effects handoff_forwards_auth_global_headers
   */
  it('/api/auth/* globalHeaders(장바구니 키 등)가 인계 요청에도 실린다', async () => {
    mockLoginWithHandoff.mockResolvedValue(results.authenticated);
    dispatcher.setGlobalHeaders([{ pattern: '/api/auth/*', headers: { 'X-Cart-Key': 'ck_1' } }]);

    await run(handoffAction);

    expect(mockLoginWithHandoff).toHaveBeenCalledWith('user', 'CODE', { headers: { 'X-Cart-Key': 'ck_1' } });
  });

  /**
   * @scenario response=invalid_code
   * @effects handoff_error_reaches_layout
   */
  it('422 오류는 서버 메시지와 errors 를 레이아웃 error 로 넘긴다', async () => {
    const error: any = new Error('로그인 정보가 만료되었거나 올바르지 않습니다.');
    error.status = 422;
    error.response = { status: 422, data: { message: '로그인 정보가 만료되었거나 올바르지 않습니다.', errors: { code: ['x'] } } };
    mockLoginWithHandoff.mockRejectedValue(error);

    const result = await dispatcher.dispatchAction(handoffAction, context() as any);

    expect(result.success).toBe(false);
    expect(result.error?.message).toBe('로그인 정보가 만료되었거나 올바르지 않습니다.');
  });

  /**
   * 관리자 인계는 관리자 경로의 globalHeaders 패턴으로 요청한다 (engine-v1.92.0).
   *
   * @scenario auth_type=admin
   * @effects handoff_admin_dispatches_admin_type
   */
  it('target admin 은 관리자 타입으로 교환하고 관리자 경로 헤더 패턴을 쓴다', async () => {
    mockLoginWithHandoff.mockResolvedValue(results.authenticated);
    dispatcher.setGlobalHeaders([
      { pattern: '/api/auth/admin/*', headers: { 'X-Admin-Probe': '1' } },
      { pattern: '/api/auth/login/*', headers: { 'X-User-Probe': '1' } },
    ]);

    await run({ ...handoffAction, target: 'admin' });

    expect(mockLoginWithHandoff).toHaveBeenCalledWith('admin', 'CODE', { headers: { 'X-Admin-Probe': '1' } });
  });

  /**
   * 로그인·인계 실패의 서버 `errors`(오류 코드 `errors.code`·이동 경로·수단 이름)·`data` 가 레이아웃 `onError` 의 `error` 로
   * 그대로 간다 — 회사 계정 로그인 안내(403)·추가 단계 이동(409) 분기가 이 값을 읽는다. 오류 문맥(`ErrorContext`)이 원본 응답의
   * `errors`·`data` 를 싣는 기존 계약을 잠근다 (engine-v1.92.0 E3).
   *
   * @scenario response=403_password_auth_disabled|409_external_step_required|423_locked, action=login|loginHandoff
   * @effects login_error_carries_server_data
   */
  it('로그인·인계 실패의 errors·data·errorCode 를 onError 의 error 로 넘긴다', async () => {
    const seen: any[] = [];
    dispatcher.registerHandler('probeError', (action: any) => {
      seen.push(action.params);
    });

    const cases = [
      { handler: 'login', status: 403, body: { message: '회사 계정으로 로그인하세요.', errors: { code: 'auth.password_auth_disabled', method_label: { ko: '회사 계정' }, redirect_url: '/plugins/x/login' }, data: { method_label: { ko: '회사 계정' }, redirect_url: '/plugins/x/login' } } },
      { handler: 'login', status: 409, body: { message: '다음 단계가 필요합니다.', errors: { code: 'auth.external_step_required', redirect_url: '/plugins/x/consent' }, data: { redirect_url: '/plugins/x/consent' } } },
      { handler: 'login', status: 423, body: { message: '잠겼습니다.', errors: { locked_until: '2026-10-22T10:00:00+09:00', retry_after_seconds: 60, permanent: false } } },
      { handler: 'loginHandoff', status: 422, body: { message: '만료', errors: { code: ['만료'] } } },
    ];

    for (const { handler, status, body } of cases) {
      const error: any = new Error(body.message);
      error.status = status;
      error.response = { status, data: body };
      (handler === 'login' ? mockLogin : mockLoginWithHandoff).mockRejectedValueOnce(error);

      await dispatcher.dispatchAction({
        ...(handler === 'login' ? loginAction : handoffAction),
        onError: {
          type: 'click',
          handler: 'probeError',
          params: {
            status: '{{error.status}}',
            errorCode: '{{error.errors.code}}',
            redirect: '{{error.errors.redirect_url}}',
            label: '{{error.errors.method_label.ko}}',
            dataRedirect: '{{error.data.redirect_url}}',
            lockedUntil: '{{error.errors.locked_until}}',
          },
        },
      } as any, context() as any);
    }

    // 레이아웃은 error.errors 만 읽는다(규정) — 서버가 안내 데이터를 errors 에도 싣는다. data 경로도 그대로 남는다.
    expect(seen[0]).toMatchObject({ status: 403, errorCode: 'auth.password_auth_disabled', redirect: '/plugins/x/login', label: '회사 계정', dataRedirect: '/plugins/x/login' });
    expect(seen[1]).toMatchObject({ status: 409, errorCode: 'auth.external_step_required', redirect: '/plugins/x/consent' });
    expect(seen[2]).toMatchObject({ status: 423, lockedUntil: '2026-10-22T10:00:00+09:00' });
    // 칸 이름이 code 인 검증 오류는 배열 그대로 — 레이아웃은 문자열 비교로 분기하므로 오인하지 않는다
    expect(seen[3].errorCode).toEqual(['만료']);
  });
});