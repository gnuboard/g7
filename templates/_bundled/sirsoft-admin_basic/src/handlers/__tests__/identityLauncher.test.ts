/**
 * sirsoft-admin_basic IDV Launcher 단위 테스트.
 *
 * challenge 시작 요청은 코어 `G7Core.api` 로 나간다. 그래서 ApiClient 가 모든 요청에 부착하는
 * `Accept-Language: g7_locale` 이 launcher 요청에도 실려 IDV 메일이 사용자 화면 언어로 렌더된다
 * (누락 시 브라우저 기본 언어로 발송되는 회귀). 이 테스트는 실제 코어 ApiClient 를 `G7Core.api` 로
 * 세우고 전송 직전 요청을 어댑터에서 가로채 판정한다.
 *
 * launcher 는 `G7Core.state.subscribe` 로 재전송 만료시각을 따라가며, 결과가 어떻게 나든 그 구독을
 * 해제해야 한다(구독이 남으면 모달을 닫은 뒤에도 전역 상태가 바뀔 때마다 클로저가 불린다).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import ApiClient from '@core/api/ApiClient';
import { sirsoftAdminBasicIdentityLauncher } from '../identityLauncher';

interface SentRequest {
  url: string;
  headers: Record<string, unknown>;
  data: unknown;
}

type Responder = () => { status: number; data: unknown };

const okResponder: Responder = () => ({
  status: 200,
  data: {
    success: true,
    data: {
      id: 'ch_1',
      expires_at: new Date(Date.now() + 300000).toISOString(),
      render_hint: 'text_code',
      max_attempts: 5,
    },
  },
});

/** 가로챈 challenge 시작 요청. */
let sent: SentRequest[] = [];
let responder: Responder = okResponder;

/**
 * 실제 코어 ApiClient 를 만들고 전송 직전 요청을 가로챈다 (네트워크 없음).
 *
 * @return 코어 ApiClient
 */
function createCapturingCoreApi(): ApiClient {
  const client = new ApiClient();
  const instance = client.getInstance();
  instance.defaults.adapter = async (config: any) => {
    const headers = typeof config.headers?.toJSON === 'function' ? config.headers.toJSON() : { ...config.headers };
    sent.push({ url: config.url, headers, data: config.data });
    const { status, data } = responder();
    const response = { data, status, statusText: String(status), headers: {}, config, request: {} };
    if (status >= 400) {
      throw Object.assign(new Error(`Request failed with status code ${status}`), { config, response });
    }
    return response;
  };
  return client;
}

/** 구독자 수를 셀 수 있는 G7Core.state.subscribe 대역. */
const makeStateWithListeners = () => {
  const listeners = new Set<(state: unknown) => void>();
  return {
    listeners,
    state: {
      get: vi.fn(() => ({})),
      getGlobal: vi.fn(() => ({})),
      getLocal: vi.fn(() => ({})),
      set: vi.fn(),
      subscribe: vi.fn((listener: (state: unknown) => void) => {
        listeners.add(listener);
        return () => {
          listeners.delete(listener);
        };
      }),
    },
  };
};

/** launcher 가 openModal 후 deferred 를 await 하므로, 기본은 즉시 cancelled 로 resolve 해 hang 방지. */
const makeG7Core = (overrides: Record<string, unknown> = {}) => ({
  dispatch: vi.fn(async () => undefined),
  state: makeStateWithListeners().state,
  api: createCapturingCoreApi(),
  identity: {
    createDeferred: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
    redirectExternally: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
  },
  createLogger: vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() })),
  ...overrides,
});

const basePayload = {
  policy_key: 'core.user.change_password',
  purpose: 'sensitive_action',
  provider_id: 'g7:core.mail',
  render_hint: 'text_code',
  return_request: { method: 'POST', url: '/api/admin/action' },
};

beforeEach(() => {
  sent = [];
  responder = okResponder;
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('raw fetch must not be used')));
  window.localStorage.clear();
});

afterEach(() => {
  vi.unstubAllGlobals();
  delete (window as any).G7Core;
  window.localStorage.clear();
});

describe('sirsoftAdminBasicIdentityLauncher — challenge 요청 로케일 헤더', () => {
  // @scenario surface=identity_launcher_challenge
  // @effects identity_challenge_via_core_api
  it('g7_locale 을 Accept-Language 헤더로 부착한다 (IDV 메일이 화면 언어를 따르도록)', async () => {
    window.localStorage.setItem('g7_locale', 'ko');
    (window as any).G7Core = makeG7Core();

    await sirsoftAdminBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'admin@example.com' },
    } as any);

    expect(sent).toHaveLength(1);
    expect(sent[0].headers['Accept-Language']).toBe('ko');
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  // @scenario surface=identity_launcher_challenge
  // @effects identity_challenge_via_core_api
  it('g7_locale 미설정 시 Accept-Language 를 부착하지 않는다 (브라우저 기본/서버 폴백)', async () => {
    (window as any).G7Core = makeG7Core();

    await sirsoftAdminBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'admin@example.com' },
    } as any);

    expect(sent[0].headers['Accept-Language']).toBeUndefined();
  });
});

describe('sirsoftAdminBasicIdentityLauncher — challenge 시작 요청', () => {
  // @scenario surface=identity_launcher_challenge
  // @effects identity_challenge_via_core_api
  it('G7Core.api 로 /api/identity/challenges 에 policy_key·purpose·provider_id·target 을 보낸다', async () => {
    (window as any).G7Core = makeG7Core();

    await sirsoftAdminBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'admin@example.com' },
    } as any);

    expect(sent[0].url).toBe('/api/identity/challenges');
    expect(JSON.parse(String(sent[0].data))).toEqual({
      policy_key: 'core.user.change_password',
      purpose: 'sensitive_action',
      provider_id: 'g7:core.mail',
      target: { email: 'admin@example.com' },
    });
  });

  // @scenario surface=identity_launcher_challenge
  // @effects identity_challenge_failure_reported
  it('서버가 4xx 로 거절하면 응답 message 로 실패를 돌려주고 토스트를 띄운다', async () => {
    responder = () => ({ status: 422, data: { success: false, message: '인증 대상이 올바르지 않습니다.' } });
    const g7 = makeG7Core();
    (window as any).G7Core = g7;

    const result = await sirsoftAdminBasicIdentityLauncher({ ...basePayload } as any);

    expect(result).toEqual({
      status: 'failed',
      failureCode: 'CHALLENGE_START_FAILED',
      reason: '인증 대상이 올바르지 않습니다.',
    });
    expect(g7.dispatch).toHaveBeenCalledWith({
      handler: 'toast',
      params: { type: 'error', message: '인증 대상이 올바르지 않습니다.' },
    });
  });
});

describe('sirsoftAdminBasicIdentityLauncher — 전역 상태 구독 해제', () => {
  // @scenario surface=identity_launcher_subscription
  // @effects state_subscription_released
  it('모달 결과가 나오면 구독을 해제해 구독자 수가 0 으로 돌아간다', async () => {
    const { listeners, state } = makeStateWithListeners();
    let resolveDeferred: (value: unknown) => void = () => undefined;
    const deferred = new Promise((resolve) => {
      resolveDeferred = resolve;
    });
    (window as any).G7Core = makeG7Core({
      state,
      identity: {
        createDeferred: vi.fn(() => deferred),
        redirectExternally: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
      },
    });

    const running = sirsoftAdminBasicIdentityLauncher({ ...basePayload } as any);
    await vi.waitFor(() => expect(listeners.size).toBe(1));

    resolveDeferred({ status: 'verified', token: 'tok' });
    await expect(running).resolves.toEqual({ status: 'verified', token: 'tok' });

    expect(state.subscribe).toHaveBeenCalledTimes(1);
    expect(listeners.size).toBe(0);
  });

  // @scenario surface=identity_launcher_subscription
  // @effects state_subscription_released
  it('모달을 열지 못해 풀페이지로 폴백할 때도 구독을 해제한다', async () => {
    const { listeners, state } = makeStateWithListeners();
    const g7 = makeG7Core({
      state,
      dispatch: vi.fn(async (action: { handler: string }) => {
        if (action.handler === 'openModal') throw new Error('modal missing');
      }),
    });
    (window as any).G7Core = g7;

    await sirsoftAdminBasicIdentityLauncher({ ...basePayload } as any);

    expect(state.subscribe).toHaveBeenCalledTimes(1);
    expect(listeners.size).toBe(0);
    expect((g7.identity as any).redirectExternally).toHaveBeenCalled();
  });

  // @scenario surface=identity_launcher_subscription
  // @effects state_subscription_released
  it('subscribe 가 해제 함수를 돌려주지 않는 구 코어에서도 오류 없이 끝난다', async () => {
    const g7 = makeG7Core();
    (g7.state as any).subscribe = vi.fn(() => undefined);
    (window as any).G7Core = g7;

    await expect(sirsoftAdminBasicIdentityLauncher({ ...basePayload } as any)).resolves.toEqual({ status: 'cancelled' });
  });
});
