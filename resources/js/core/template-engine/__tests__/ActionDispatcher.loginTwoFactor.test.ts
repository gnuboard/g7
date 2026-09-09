/**
 * ActionDispatcher 2단계 인증 핸들러 테스트
 *
 * 서버는 보안 환경설정에 따라 로그인에 **두 가지 형태의 200** 을 돌려준다. 레이아웃이
 * 그 사실을 알 통로가 없으면 인증번호 입력 단계로 넘어갈 방법이 없고, 화면은 알 수 없는
 * 오류로 멈춘다(공개 #133).
 *
 * @since engine-v1.65.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

const mockLogin = vi.fn();
const mockCompleteTwoFactor = vi.fn();
const mockResendTwoFactor = vi.fn();
const mockLogout = vi.fn().mockResolvedValue(undefined);

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: mockLogin,
      logout: mockLogout,
      completeTwoFactor: mockCompleteTwoFactor,
      resendTwoFactor: mockResendTwoFactor,
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('ActionDispatcher - 2단계 인증', () => {
  let dispatcher: ActionDispatcher;

  beforeEach(() => {
    mockLogin.mockReset();
    mockCompleteTwoFactor.mockReset();
    mockResendTwoFactor.mockReset();
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);
  });

  afterEach(() => {
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

  describe('login', () => {
    it('challenge 응답을 레이아웃이 읽을 수 있는 형태로 돌려준다', async () => {
      mockLogin.mockResolvedValue({
        status: 'two_factor_required',
        challenge: {
          challengeId: 'challenge-uuid',
          providerId: 'g7:core.mail',
          expiresAt: '2026-09-07T14:03:00+09:00',
        },
      });

      const result = await run({
        type: 'click',
        handler: 'login',
        target: 'user',
        params: { body: { email: 'a@b.c', password: 'pw' } },
      });

      expect(result).toMatchObject({
        user: null,
        two_factor_required: true,
        challenge_id: 'challenge-uuid',
        provider_id: 'g7:core.mail',
        expires_at: '2026-09-07T14:03:00+09:00',
      });
    });

    it('정상 로그인은 종전대로 user 를 돌려준다', async () => {
      const user = { id: 1, name: 'Test User' };
      mockLogin.mockResolvedValue({ status: 'authenticated', user });

      const result = await run({
        type: 'click',
        handler: 'login',
        target: 'user',
        params: { body: { email: 'a@b.c', password: 'pw' } },
      });

      // 기존 레이아웃이 `response.user` 를 읽으므로 이 계약은 유지되어야 한다.
      expect(result).toMatchObject({ user, two_factor_required: false });
    });
  });

  describe('loginTwoFactor', () => {
    it('challenge_id 와 code 로 로그인을 완료한다', async () => {
      const user = { id: 1, name: 'Test User' };
      mockCompleteTwoFactor.mockResolvedValue(user);

      const result = await run({
        type: 'click',
        handler: 'loginTwoFactor',
        target: 'user',
        params: { body: { challenge_id: 'challenge-uuid', code: '135790' } },
      });

      expect(mockCompleteTwoFactor).toHaveBeenCalledWith(
        'user',
        { challengeId: 'challenge-uuid', code: '135790' },
        undefined
      );
      expect(result).toEqual({ user });
    });

    it('globalHeaders 패턴이 관리자 확인 요청에도 적용된다', async () => {
      mockCompleteTwoFactor.mockResolvedValue({ id: 1 });
      dispatcher.setGlobalHeaders([
        { pattern: '/api/auth/*', headers: { 'X-Cart-Key': 'ck_admin456' } },
      ]);

      await run({
        type: 'click',
        handler: 'loginTwoFactor',
        target: 'admin',
        params: { body: { challenge_id: 'c', code: '135790' } },
      });

      expect(mockCompleteTwoFactor).toHaveBeenCalledWith(
        'admin',
        { challengeId: 'c', code: '135790' },
        { headers: { 'X-Cart-Key': 'ck_admin456' } }
      );
    });

    it('code 가 없으면 요청을 보내지 않는다', async () => {
      await expect(
        run({
          type: 'click',
          handler: 'loginTwoFactor',
          target: 'user',
          params: { body: { challenge_id: 'c' } },
        })
      ).rejects.toThrow();

      expect(mockCompleteTwoFactor).not.toHaveBeenCalled();
    });

    it('서버 오류 메시지를 그대로 실어 재포장한다', async () => {
      const error: any = new Error('인증번호가 올바르지 않거나 유효시간이 지났습니다.');
      error.response = { status: 401, data: { message: '인증번호가 올바르지 않거나 유효시간이 지났습니다.' } };
      error.status = 401;
      mockCompleteTwoFactor.mockRejectedValue(error);

      await expect(
        run({
          type: 'click',
          handler: 'loginTwoFactor',
          target: 'user',
          params: { body: { challenge_id: 'c', code: '000000' } },
        })
      ).rejects.toThrow('인증번호가 올바르지 않거나 유효시간이 지났습니다.');
    });
  });

  describe('loginTwoFactorResend', () => {
    it('새 challenge 를 돌려준다', async () => {
      mockResendTwoFactor.mockResolvedValue({
        challengeId: 'new-challenge',
        providerId: 'g7:core.mail',
        expiresAt: null,
      });

      const result = await run({
        type: 'click',
        handler: 'loginTwoFactorResend',
        target: 'user',
        params: { body: { challenge_id: 'old-challenge' } },
      });

      expect(mockResendTwoFactor).toHaveBeenCalledWith(
        'user',
        { challengeId: 'old-challenge' },
        undefined
      );
      expect(result).toEqual({
        two_factor_required: true,
        challenge_id: 'new-challenge',
        provider_id: 'g7:core.mail',
        expires_at: null,
      });
    });

    it('challenge_id 가 없으면 요청을 보내지 않는다', async () => {
      await expect(
        run({
          type: 'click',
          handler: 'loginTwoFactorResend',
          target: 'user',
          params: { body: {} },
        })
      ).rejects.toThrow();

      expect(mockResendTwoFactor).not.toHaveBeenCalled();
    });
  });
});
