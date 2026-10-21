/**
 * ActionDispatcher loginHandoff 액션 테스트
 *
 * 로그인 화면이 `/login?login_handoff={code}` 로 들어오면 `loginHandoff` 액션으로 코드를 교환한다. 반환 모양이
 * `login` 액션과 **키 단위로 같아야** 로그인 화면이 같은 `onSuccess` 분기(2단계 인증 블록·비밀번호 변경 블록·휴면
 * 해제 안내)를 그대로 쓴다.
 *
 * @since engine-v1.83.0
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
});
