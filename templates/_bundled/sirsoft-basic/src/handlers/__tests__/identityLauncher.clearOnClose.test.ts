/**
 * sirsoft-basic IDV launcher — 인증 창이 닫히면 창 이름 공간(인증번호·건 번호)을 비운다 (7.1.1)
 *
 * 결과(확인·취소)가 나면 창은 닫히지만 `_global.identityChallenge` 는 다음 띄움까지 남아, 친 인증번호가 개발자 도구로
 * 보였다. launcher 가 결과 뒤 창이 닫히기를 기다려 비우고, 그 사이 다른 띄움이 이름 공간을 새로 썼으면 건드리지 않는다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import ApiClient from '@core/api/ApiClient';
import { sirsoftBasicIdentityLauncher } from '../identityLauncher';

let resolveDeferred: (v: unknown) => void = () => {};
let globalState: Record<string, any> = {};
let modalOpen = false;

/**
 * 코어 상태 저장을 흉내 낸다 — 객체 값은 한 단계 병합, null 은 교체(코어 deep 병합과 같은 결과).
 *
 * @param updates 갱신
 * @return 반환값 없음
 */
function setState(updates: Record<string, any>): void {
  const next = { ...globalState };
  for (const [key, value] of Object.entries(updates)) {
    next[key] = value !== null && typeof value === 'object' && !Array.isArray(value) && next[key] && typeof next[key] === 'object'
      ? { ...next[key], ...value }
      : value;
  }
  globalState = next;
}

/**
 * 인증번호 방식 시작 응답을 돌려주는 코어 ApiClient (네트워크 없음).
 *
 * @return 코어 ApiClient
 */
function createCoreApi(): ApiClient {
  const client = new ApiClient();
  client.getInstance().defaults.adapter = async (config: any) => ({
    data: { success: true, data: { id: 'ch_code', expires_at: new Date(Date.now() + 300000).toISOString(), render_hint: 'text_code' } },
    status: 201,
    statusText: '201',
    headers: {},
    config,
    request: {},
  });
  return client;
}

const payload = {
  policy_key: 'core.profile.sensitive',
  purpose: 'sensitive_action',
  provider_id: 'g7:core.mail',
  render_hint: 'text_code',
  return_request: { method: 'PUT', url: '/api/me' },
  challenge_start_url: '/api/identity/challenges',
};

beforeEach(() => {
  vi.useFakeTimers();
  globalState = {};
  modalOpen = false;
  (window as any).G7Core = {
    dispatch: vi.fn(async (action: Record<string, any>) => {
      if (action.handler === 'openModal') modalOpen = true;
      if (action.handler === 'resolveIdentityChallenge') resolveDeferred({ status: action.params?.result, token: action.params?.token });
    }),
    state: { get: vi.fn(() => globalState), getGlobal: vi.fn(() => globalState), getLocal: vi.fn(() => ({})), set: vi.fn(setState), subscribe: vi.fn(() => () => {}) },
    modal: { isOpen: vi.fn((id: string) => id === 'identity-challenge-modal' && modalOpen) },
    api: createCoreApi(),
    identity: {
      createDeferred: vi.fn(() => new Promise((r) => { resolveDeferred = r; })),
      redirectExternally: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
    },
  };
});

afterEach(() => {
  vi.useRealTimers();
  delete (window as any).G7Core;
});

/**
 * launcher 를 띄우고 창 이름 공간이 심길 때까지 진행한 뒤, 사용자가 인증번호를 친 상태를 만든다.
 *
 * @return launcher 결과 Promise 를 담은 객체(Promise 를 그대로 돌려주면 await 가 launcher 끝까지 기다린다)
 */
async function launchAndType(): Promise<{ pending: Promise<unknown> }> {
  const pending = sirsoftBasicIdentityLauncher(payload as any);
  await vi.advanceTimersByTimeAsync(50);
  setState({ identityChallenge: { code: '123456' } });

  return { pending };
}

describe('sirsoftBasicIdentityLauncher — 창이 닫히면 인증번호를 비운다', () => {
  /**
   * @effects launcher_clears_challenge_namespace_after_close
   */
  it('확인 결과 뒤 창이 닫히면 identityChallenge 가 null 이 되고, 창이 열려 있는 동안에는 비우지 않는다', async () => {
    // @scenario surface=launcher_close_cleanup
    const { pending } = await launchAndType();
    expect(globalState.identityChallenge?.code).toBe('123456');

    resolveDeferred({ status: 'verified', token: 'T' });
    await pending;
    await vi.advanceTimersByTimeAsync(250);
    expect(globalState.identityChallenge?.code, '창이 열려 있는 동안 비우면 빈 칸이 한 번 보인다').toBe('123456');

    modalOpen = false;
    await vi.advanceTimersByTimeAsync(150);
    expect(globalState.identityChallenge).toBeNull();
  });

  /**
   * @effects launcher_clears_challenge_namespace_after_close
   */
  it('취소로 닫혀도 비운다', async () => {
    // @scenario surface=launcher_close_cleanup
    const { pending } = await launchAndType();
    modalOpen = false;
    resolveDeferred({ status: 'cancelled' });
    await pending;
    await vi.advanceTimersByTimeAsync(10);

    expect(globalState.identityChallenge).toBeNull();
  });

  /**
   * @effects launcher_clears_challenge_namespace_after_close
   */
  it('그 사이 다른 띄움이 이름 공간을 새로 썼으면 건드리지 않는다', async () => {
    // @scenario surface=launcher_close_cleanup
    const { pending } = await launchAndType();
    resolveDeferred({ status: 'verified', token: 'T' });
    await pending;
    // 이어진 요청이 새 428 을 받아 다른 띄움이 이름 공간을 새로 심었다
    globalState = { identityChallenge: { launch_key: 'other', challenge_id: 'ch_next', code: '' } };
    modalOpen = false;
    await vi.advanceTimersByTimeAsync(300);

    expect(globalState.identityChallenge).toEqual({ launch_key: 'other', challenge_id: 'ch_next', code: '' });
  });
});
