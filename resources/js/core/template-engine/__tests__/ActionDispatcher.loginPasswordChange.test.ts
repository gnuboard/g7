/**
 * ActionDispatcher 로그인 비밀번호 변경 단계 핸들러 테스트
 *
 * 비밀번호가 만료됐거나 관리자가 정해 준 비밀번호면 서버는 로그인에 토큰 대신 변경 단계
 * challenge 를 돌려준다. 레이아웃은 `loginPasswordChange`(새 비밀번호) 또는
 * `loginPasswordChangePostpone`('다음에 변경') 으로 로그인을 끝낸다.
 *
 * @since engine-v1.77.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

const mockCompletePasswordChange = vi.fn();
const mockPostponePasswordChange = vi.fn();

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn(),
      logout: vi.fn().mockResolvedValue(undefined),
      completeTwoFactor: vi.fn(),
      resendTwoFactor: vi.fn(),
      completePasswordChange: mockCompletePasswordChange,
      postponePasswordChange: mockPostponePasswordChange,
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('ActionDispatcher - 로그인 비밀번호 변경 단계', () => {
  let dispatcher: ActionDispatcher;

  beforeEach(() => {
    mockCompletePasswordChange.mockReset();
    mockPostponePasswordChange.mockReset();
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

  describe('loginPasswordChange', () => {
    /** @effects password_change_handler_completes_login */
    it('challenge_id·새 비밀번호로 로그인을 완료하고 user 를 돌려준다', async () => {
      const user = { id: 1, uuid: 'u-1' };
      mockCompletePasswordChange.mockResolvedValue({ status: 'authenticated', user });

      const result = await run({
        type: 'click',
        handler: 'loginPasswordChange',
        target: 'user',
        params: {
          body: { challenge_id: 'pw-challenge', password: 'NewPassw0rd!', password_confirmation: 'NewPassw0rd!' },
        },
      });

      expect(mockCompletePasswordChange).toHaveBeenCalledWith(
        'user',
        { challengeId: 'pw-challenge', password: 'NewPassw0rd!', passwordConfirmation: 'NewPassw0rd!' },
        undefined
      );
      expect(result).toEqual({ user, password_change_required: false });
    });

    /** @effects password_change_handler_completes_login */
    it('휴면이 풀린 변경이면 dormancy_restored 를 전달한다 (D20)', async () => {
      const user = { id: 1 };
      mockCompletePasswordChange.mockResolvedValue({ status: 'authenticated', user, extras: { dormancy_restored: true } });

      const result = await run({
        type: 'click',
        handler: 'loginPasswordChange',
        target: 'admin',
        params: { body: { challenge_id: 'c', password: 'x', password_confirmation: 'x' } },
      });

      expect(result).toEqual({ user, password_change_required: false, dormancy_restored: true });
    });

    /** @effects password_change_handler_completes_login */
    it('빈 비밀번호도 서버로 보내 규칙 문구를 서버가 정하게 한다', async () => {
      mockCompletePasswordChange.mockResolvedValue({ status: 'authenticated', user: { id: 1 } });

      await run({
        type: 'click',
        handler: 'loginPasswordChange',
        target: 'user',
        params: { body: { challenge_id: 'c' } },
      });

      expect(mockCompletePasswordChange).toHaveBeenCalledWith(
        'user',
        { challengeId: 'c', password: '', passwordConfirmation: '' },
        undefined
      );
    });

    /** @effects password_change_handler_completes_login */
    it('globalHeaders 패턴이 관리자 변경 요청에도 적용된다', async () => {
      mockCompletePasswordChange.mockResolvedValue({ status: 'authenticated', user: { id: 1 } });
      dispatcher.setGlobalHeaders([
        { pattern: '/api/auth/admin/login/password-change', headers: { 'X-Test': 'admin' } },
      ]);

      await run({
        type: 'click',
        handler: 'loginPasswordChange',
        target: 'admin',
        params: { body: { challenge_id: 'c', password: 'x', password_confirmation: 'x' } },
      });

      expect(mockCompletePasswordChange).toHaveBeenCalledWith(
        'admin',
        { challengeId: 'c', password: 'x', passwordConfirmation: 'x' },
        { headers: { 'X-Test': 'admin' } }
      );
    });

    /** @effects password_change_handler_completes_login */
    it('challenge_id 가 없으면 요청을 보내지 않는다', async () => {
      await expect(
        run({
          type: 'click',
          handler: 'loginPasswordChange',
          target: 'user',
          params: { body: { password: 'x' } },
        })
      ).rejects.toThrow();

      expect(mockCompletePasswordChange).not.toHaveBeenCalled();
    });

    /** @effects password_change_handler_completes_login */
    it('422 의 필드 오류(errors.password / errors.challenge_id)를 레이아웃 error 로 실어 보낸다', async () => {
      const error: any = new Error('최근에 사용한 비밀번호입니다.');
      error.status = 422;
      error.response = {
        status: 422,
        data: { message: '최근에 사용한 비밀번호입니다.', errors: { password: ['최근에 사용한 비밀번호입니다.'] } },
      };
      mockCompletePasswordChange.mockRejectedValue(error);

      const result = await dispatcher.dispatchAction(
        {
          type: 'click',
          handler: 'loginPasswordChange',
          target: 'user',
          params: { body: { challenge_id: 'c', password: 'x', password_confirmation: 'x' } },
        },
        context() as any
      );

      expect(result.success).toBe(false);
      expect(result.error?.message).toBe('최근에 사용한 비밀번호입니다.');
      const original: any = (result.error as any)?.originalError;
      expect(original?.status).toBe(422);
      expect(original?.response?.errors?.password).toEqual(['최근에 사용한 비밀번호입니다.']);
    });
  });

  describe('loginPasswordChangePostpone', () => {
    /** @effects password_change_postpone_handler_completes_login */
    it('challenge_id 로 연기하고 user·postponed_until 을 돌려준다', async () => {
      const user = { id: 1 };
      mockPostponePasswordChange.mockResolvedValue({
        status: 'authenticated',
        user,
        extras: { postponed_until: '2026-11-02T00:00:00+09:00' },
      });

      const result = await run({
        type: 'click',
        handler: 'loginPasswordChangePostpone',
        target: 'admin',
        params: { body: { challenge_id: 'pw-challenge' } },
      });

      expect(mockPostponePasswordChange).toHaveBeenCalledWith(
        'admin',
        { challengeId: 'pw-challenge' },
        undefined
      );
      expect(result).toEqual({
        user,
        password_change_required: false,
        postponed_until: '2026-11-02T00:00:00+09:00',
      });
    });

    /** @effects password_change_postpone_handler_completes_login */
    it('challenge_id 가 없으면 요청을 보내지 않는다', async () => {
      await expect(
        run({
          type: 'click',
          handler: 'loginPasswordChangePostpone',
          target: 'user',
          params: { body: {} },
        })
      ).rejects.toThrow();

      expect(mockPostponePasswordChange).not.toHaveBeenCalled();
    });
  });
});
