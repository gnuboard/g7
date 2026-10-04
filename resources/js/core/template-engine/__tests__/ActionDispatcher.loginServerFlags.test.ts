/**
 * 로그인 응답의 서버 안내 플래그가 레이아웃까지 닿는지 — `login`·`loginTwoFactor` 핸들러
 *
 * 서버는 휴면이 풀린 로그인에만 `dormancy_restored: true` 를 싣고, 로그인 화면(사용자·관리자)은
 * `<toast if="{{response.dormancy_restored}}">` 로 해제 안내를 띄운다. 그런데 AuthManager 가 응답에서
 * `user` 만 골라 돌려주고 핸들러가 `{ user, two_factor_required }` 만 만들어, 플래그가 중간에서 사라져
 * 안내가 한 번도 뜨지 않았다(휴면 회원 로그인 실측 — 토스트는 「로그인 성공」뿐).
 *
 * mock 은 ApiClient 하나뿐이다 — 실제 AuthManager 와 ActionDispatcher 를 그대로 거쳐야 두 층 중
 * 어디서 버려도 잡힌다.
 *
 * @since engine-v1.76.0
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';
import { AuthManager } from '../../auth/AuthManager';
import * as ApiClientModule from '../../api/ApiClient';
import { Logger } from '../../utils/Logger';

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(),
}));

describe('[case:backend-51] ActionDispatcher — 로그인 응답 안내 플래그', () => {
  let dispatcher: ActionDispatcher;
  let api: { getToken: any; setToken: any; removeToken: any; get: any; post: any };

  beforeEach(() => {
    AuthManager.resetInstance();
    api = { getToken: vi.fn(), setToken: vi.fn(), removeToken: vi.fn(), get: vi.fn(), post: vi.fn() };
    (ApiClientModule.getApiClient as any).mockReturnValue(api);
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);
  });

  const run = async (action: ActionDefinition) => {
    const result = await dispatcher.dispatchAction(action, { state: { _global: {}, _local: {} } } as any);

    if (!result.success) {
      throw result.error ?? new Error('action failed');
    }

    return result.data;
  };

  const user = { uuid: 'u-1', name: '휴면 실측 회원', email: 'd@example.test' };

  // @scenario stage=restore, toggle=off
  // @effects dormancy_restored_flag_reaches_login_layouts
  it('비밀번호 로그인: 서버가 실은 dormancy_restored 를 response 로 넘기고 토큰은 넘기지 않는다', async () => {
    api.post.mockResolvedValue({
      success: true,
      data: { user, token: 'plain-token', token_type: 'Bearer', dormancy_restored: true },
    });

    const response = await run({
      type: 'click',
      handler: 'login',
      target: 'user',
      params: { body: { email: user.email, password: 'pw' } },
    });

    expect(response).toMatchObject({ user, two_factor_required: false, dormancy_restored: true });
    // 토큰은 ApiClient 가 보관한다 — 레이아웃 상태(`response`)로 흘려보낼 이유가 없다.
    expect(response).not.toHaveProperty('token');
    expect(response).not.toHaveProperty('token_type');
    expect(api.setToken).toHaveBeenCalledWith('plain-token');
  });

  // @scenario stage=restore, toggle=off
  // @effects dormancy_restored_flag_reaches_login_layouts
  it('평시 로그인: 서버가 플래그를 싣지 않으면 response 에도 없다', async () => {
    api.post.mockResolvedValue({ success: true, data: { user, token: 'plain-token', token_type: 'Bearer' } });

    const response = await run({
      type: 'click',
      handler: 'login',
      target: 'admin',
      params: { body: { email: user.email, password: 'pw' } },
    });

    // engine-v1.77.0 부터 비밀번호 변경 단계 여부(`password_change_required`)를 함께 싣는다.
    expect(response).toEqual({ user, two_factor_required: false, password_change_required: false });
  });

  // @scenario stage=restore, toggle=on
  // @effects dormancy_restored_flag_reaches_login_layouts
  it('2단계 인증 완료: 코드 확인 응답의 dormancy_restored 도 response 로 넘긴다', async () => {
    api.post.mockResolvedValue({
      success: true,
      data: { user, token: 'two-factor-token', token_type: 'Bearer', dormancy_restored: true },
    });

    const response = await run({
      type: 'click',
      handler: 'loginTwoFactor',
      target: 'user',
      params: { body: { challenge_id: 'challenge-uuid', code: '135790' } },
    });

    expect(response).toMatchObject({ user, dormancy_restored: true });
    expect(response).not.toHaveProperty('token');
    expect(api.setToken).toHaveBeenCalledWith('two-factor-token');
  });

  // @scenario stage=restore, toggle=on
  // @effects dormancy_restored_flag_reaches_login_layouts
  it('공개 API completeTwoFactor 는 종전대로 사용자만 돌려준다', async () => {
    api.post.mockResolvedValue({
      success: true,
      data: { user, token: 'two-factor-token', dormancy_restored: true },
    });

    const result = await AuthManager.getInstance().completeTwoFactor('user', { challengeId: 'c', code: '1' });

    expect(result).toEqual(user);
  });
});
