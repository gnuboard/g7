/**
 * @file identityLauncher.test.ts
 * @description sirsoft-basic IDV launcher 의 인증 대상(target) 결정 로직 테스트.
 *
 * launcher 는 흐름별 폼 경로 하드코딩을 폐기하고, 다음 우선순위로 target 을 결정한다:
 *   1. payload.target (흐름이 apiCall identity_target 으로 선언 — 범용 진입점)
 *   2. 로그인 사용자 세션 (currentUser / user / auth.user 의 email·phone)
 *   3. (하위호환) signup/password_reset 폼
 *
 * 검증 방식: launcher 가 코어 `G7Core.api` 로 challenge 를 시작할 때 body.target 에 어떤 값이
 * 실리는지 단언한다. 실제 코어 ApiClient 를 `G7Core.api` 로 세우고 전송 직전 요청을 어댑터에서
 * 가로챈다 — 그래서 `Accept-Language: g7_locale` 처럼 코어가 붙이는 헤더도 함께 잰다.
 *
 * launcher 는 `G7Core.state.subscribe` 로 재전송 만료시각을 따라가며, 결과가 어떻게 나든 그 구독을
 * 해제해야 한다(구독이 남으면 모달을 닫은 뒤에도 전역 상태가 바뀔 때마다 클로저가 불린다).
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import ApiClient from '@core/api/ApiClient';
import { sirsoftBasicIdentityLauncher } from '../identityLauncher';

type Target = { email?: string; phone?: string };

/** challenge 시작 요청에 실린 body 를 캡처. */
let lastChallengeBody: any = null;
/** challenge 시작 요청에 실린 headers 를 캡처 (코어 인터셉터 적용 후). */
let lastChallengeHeaders: Record<string, unknown> | null = null;

/**
 * 실제 코어 ApiClient 를 만들고 전송 직전 요청을 가로챈다 (네트워크 없음).
 *
 * @return 코어 ApiClient
 */
function createCapturingCoreApi(): ApiClient {
  const client = new ApiClient();
  const instance = client.getInstance();
  instance.defaults.adapter = async (config: any) => {
    lastChallengeBody = config.data ? JSON.parse(String(config.data)) : null;
    lastChallengeHeaders = typeof config.headers?.toJSON === 'function' ? config.headers.toJSON() : { ...config.headers };
    return {
      data: {
        success: true,
        data: {
          id: 'ch_1',
          expires_at: new Date(Date.now() + 300000).toISOString(),
          render_hint: 'text_code',
          max_attempts: 5,
        },
      },
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
      request: {},
    };
  };
  return client;
}

/** 구독자 수를 셀 수 있는 G7Core.state 대역. */
const makeStateWithListeners = (state: Record<string, any>) => {
  const listeners = new Set<(next: unknown) => void>();
  return {
    listeners,
    state: {
      get: vi.fn(() => state),
      getGlobal: vi.fn(() => state),
      getLocal: vi.fn(() => state._local ?? {}),
      set: vi.fn(),
      subscribe: vi.fn((listener: (next: unknown) => void) => {
        listeners.add(listener);
        return () => {
          listeners.delete(listener);
        };
      }),
    },
  };
};

/** launcher 가 openModal 후 deferred 를 await 하므로, 즉시 cancelled 로 resolve 해 hang 방지. */
const makeG7Core = (state: Record<string, any>) => ({
  dispatch: vi.fn(async () => undefined),
  state: makeStateWithListeners(state).state,
  api: createCapturingCoreApi(),
  identity: {
    createDeferred: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
    redirectExternally: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
  },
  createLogger: vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() })),
});

const basePayload = {
  policy_key: 'sirsoft-ecommerce.payment.confirm',
  purpose: 'sensitive_action',
  provider_id: 'g7:core.mail',
  render_hint: 'text_code',
  return_request: { method: 'POST', url: '/api/orders' },
};

/** challenge body.target 추출 헬퍼. */
const sentTarget = (): Target | undefined => lastChallengeBody?.target;

beforeEach(() => {
  lastChallengeBody = null;
  lastChallengeHeaders = null;
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('raw fetch must not be used')));
  window.localStorage.clear();
});

afterEach(() => {
  vi.unstubAllGlobals();
  delete (window as any).G7Core;
  window.localStorage.clear();
});

describe('sirsoftBasicIdentityLauncher — challenge 요청 로케일 헤더', () => {
  // @scenario surface=identity_launcher_challenge
  // @effects identity_challenge_via_core_api
  it('g7_locale 을 Accept-Language 헤더로 부착한다 (IDV 메일이 화면 언어를 따르도록)', async () => {
    window.localStorage.setItem('g7_locale', 'ko');
    (window as any).G7Core = makeG7Core({ _local: {} });

    await sirsoftBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'guest@example.com' },
    } as any);

    expect(lastChallengeHeaders?.['Accept-Language']).toBe('ko');
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  // @scenario surface=identity_launcher_challenge
  // @effects identity_challenge_via_core_api
  it('g7_locale 미설정 시 Accept-Language 를 부착하지 않는다 (브라우저 기본/서버 폴백)', async () => {
    (window as any).G7Core = makeG7Core({ _local: {} });

    await sirsoftBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'guest@example.com' },
    } as any);

    expect(lastChallengeHeaders?.['Accept-Language']).toBeUndefined();
  });
});

describe('sirsoftBasicIdentityLauncher — resolveTarget 우선순위', () => {
  // @scenario surface=identity_launcher_target
  // @effects identity_target_resolution
  it('payload.target(흐름 선언값) 을 1순위로 challenge body 에 동봉한다 (email + phone)', async () => {
    (window as any).G7Core = makeG7Core({ _local: {} });

    await sirsoftBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'guest@example.com', phone: '01012345678' },
    } as any);

    expect(sentTarget()).toEqual({ email: 'guest@example.com', phone: '01012345678' });
  });

  // @scenario surface=identity_launcher_target
  // @effects identity_target_resolution
  it('payload.target 에 phone 만 있으면 email 은 하위 단계(세션)에서 보충한다', async () => {
    (window as any).G7Core = makeG7Core({
      currentUser: { email: 'member@example.com' },
      _local: {},
    });

    await sirsoftBasicIdentityLauncher({
      ...basePayload,
      target: { phone: '01099998888' },
    } as any);

    expect(sentTarget()).toEqual({ email: 'member@example.com', phone: '01099998888' });
  });

  // @scenario surface=identity_launcher_target
  // @effects identity_target_resolution
  it('선언값 없으면 로그인 사용자(currentUser) email·phone 으로 도출한다', async () => {
    (window as any).G7Core = makeG7Core({
      currentUser: { email: 'member@example.com', phone: '01055556666' },
      _local: {},
    });

    await sirsoftBasicIdentityLauncher({ ...basePayload } as any);

    expect(sentTarget()).toEqual({ email: 'member@example.com', phone: '01055556666' });
  });

  // @scenario surface=identity_launcher_target
  // @effects identity_target_resolution
  it('선언값·세션 없으면 signup 폼 email 로 하위호환 도출한다', async () => {
    (window as any).G7Core = makeG7Core({
      _local: { registerForm: { email: 'newuser@example.com' } },
    });

    await sirsoftBasicIdentityLauncher({ ...basePayload, purpose: 'signup' } as any);

    expect(sentTarget()).toEqual({ email: 'newuser@example.com' });
  });

  // @scenario surface=identity_launcher_target
  // @effects identity_target_resolution
  it('선언값·세션·폼 모두 없으면 target 을 동봉하지 않는다 (서버 세션 도출)', async () => {
    (window as any).G7Core = makeG7Core({ _local: {} });

    await sirsoftBasicIdentityLauncher({ ...basePayload } as any);

    expect(sentTarget()).toBeUndefined();
  });
});

describe('sirsoftBasicIdentityLauncher — 전역 상태 구독 해제', () => {
  // @scenario surface=identity_launcher_subscription
  // @effects state_subscription_released
  it('모달 결과가 나오면 구독을 해제해 구독자 수가 0 으로 돌아간다', async () => {
    const { listeners, state } = makeStateWithListeners({ _local: {} });
    let resolveDeferred: (value: unknown) => void = () => undefined;
    const deferred = new Promise((resolve) => {
      resolveDeferred = resolve;
    });
    (window as any).G7Core = {
      ...makeG7Core({ _local: {} }),
      state,
      identity: {
        createDeferred: vi.fn(() => deferred),
        redirectExternally: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
      },
    };

    const running = sirsoftBasicIdentityLauncher({ ...basePayload, target: { email: 'guest@example.com' } } as any);
    await vi.waitFor(() => expect(listeners.size).toBe(1));

    resolveDeferred({ status: 'verified', token: 'tok' });
    await expect(running).resolves.toEqual({ status: 'verified', token: 'tok' });

    expect(state.subscribe).toHaveBeenCalledTimes(1);
    expect(listeners.size).toBe(0);
  });

  // @scenario surface=identity_launcher_subscription
  // @effects state_subscription_released
  it('모달을 열지 못해 풀페이지로 폴백할 때도 구독을 해제한다', async () => {
    const { listeners, state } = makeStateWithListeners({ _local: {} });
    const g7 = {
      ...makeG7Core({ _local: {} }),
      state,
      dispatch: vi.fn(async (action: { handler: string }) => {
        if (action.handler === 'openModal') throw new Error('modal missing');
      }),
    };
    (window as any).G7Core = g7;

    await sirsoftBasicIdentityLauncher({ ...basePayload, target: { email: 'guest@example.com' } } as any);

    expect(state.subscribe).toHaveBeenCalledTimes(1);
    expect(listeners.size).toBe(0);
    expect(g7.identity.redirectExternally).toHaveBeenCalled();
  });

  // @scenario surface=identity_launcher_subscription
  // @effects state_subscription_released
  it('subscribe 가 해제 함수를 돌려주지 않는 구 코어에서도 오류 없이 끝난다', async () => {
    const g7 = makeG7Core({ _local: {} });
    (g7.state as any).subscribe = vi.fn(() => undefined);
    (window as any).G7Core = g7;

    await expect(
      sirsoftBasicIdentityLauncher({ ...basePayload, target: { email: 'guest@example.com' } } as any),
    ).resolves.toEqual({ status: 'cancelled' });
  });
});
