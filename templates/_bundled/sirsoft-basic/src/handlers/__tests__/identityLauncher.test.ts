/**
 * @file identityLauncher.test.ts
 * @description sirsoft-basic IDV launcher 의 인증 대상(target) 결정 로직 테스트.
 *
 * launcher 는 흐름별 폼 경로 하드코딩을 폐기하고, 다음 우선순위로 target 을 결정한다:
 *   1. payload.target (흐름이 apiCall identity_target 으로 선언 — 범용 진입점)
 *   2. 로그인 사용자 세션 (currentUser / user / auth.user 의 email·phone)
 *   3. (하위호환) signup/password_reset 폼
 *
 * 검증 방식: launcher 가 startChallenge 의 fetch 로 challenge 를 시작할 때
 * body.target 에 어떤 값이 실리는지 단언한다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { sirsoftBasicIdentityLauncher } from '../identityLauncher';

type Target = { email?: string; phone?: string };

/** challenge 시작 fetch 에 실린 body 를 캡처. */
let lastChallengeBody: any = null;
/** challenge 시작 fetch 에 실린 headers 를 캡처. */
let lastChallengeHeaders: Record<string, string> | null = null;

const mockFetch = vi.fn(async (_url: string, init?: RequestInit) => {
  lastChallengeBody = init?.body ? JSON.parse(init.body as string) : null;
  lastChallengeHeaders = (init?.headers as Record<string, string>) ?? null;
  return {
    ok: true,
    status: 200,
    json: async () => ({
      success: true,
      data: {
        id: 'ch_1',
        expires_at: new Date(Date.now() + 300000).toISOString(),
        render_hint: 'text_code',
        max_attempts: 5,
      },
    }),
  } as any;
});

/** launcher 가 openModal 후 deferred 를 await 하므로, 즉시 cancelled 로 resolve 해 hang 방지. */
const makeG7Core = (state: Record<string, any>) => ({
  dispatch: vi.fn(async () => undefined),
  state: {
    get: vi.fn(() => state),
    getGlobal: vi.fn(() => state),
    getLocal: vi.fn(() => state._local ?? {}),
    set: vi.fn(),
    subscribe: vi.fn(() => () => undefined),
  },
  api: { getToken: vi.fn(() => null) },
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
  mockFetch.mockClear();
  vi.stubGlobal('fetch', mockFetch);
  window.localStorage.clear();
});

afterEach(() => {
  vi.unstubAllGlobals();
  delete (window as any).G7Core;
  window.localStorage.clear();
});

describe('sirsoftBasicIdentityLauncher — challenge 요청 로케일 헤더', () => {
  it('g7_locale 을 Accept-Language 헤더로 부착한다 (IDV 메일이 화면 언어를 따르도록)', async () => {
    window.localStorage.setItem('g7_locale', 'ko');
    (window as any).G7Core = makeG7Core({ _local: {} });

    await sirsoftBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'guest@example.com' },
    } as any);

    expect(lastChallengeHeaders?.['Accept-Language']).toBe('ko');
  });

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
  it('payload.target(흐름 선언값) 을 1순위로 challenge body 에 동봉한다 (email + phone)', async () => {
    (window as any).G7Core = makeG7Core({ _local: {} });

    await sirsoftBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'guest@example.com', phone: '01012345678' },
    } as any);

    expect(sentTarget()).toEqual({ email: 'guest@example.com', phone: '01012345678' });
  });

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

  it('선언값 없으면 로그인 사용자(currentUser) email·phone 으로 도출한다', async () => {
    (window as any).G7Core = makeG7Core({
      currentUser: { email: 'member@example.com', phone: '01055556666' },
      _local: {},
    });

    await sirsoftBasicIdentityLauncher({ ...basePayload } as any);

    expect(sentTarget()).toEqual({ email: 'member@example.com', phone: '01055556666' });
  });

  it('선언값·세션 없으면 signup 폼 email 로 하위호환 도출한다', async () => {
    (window as any).G7Core = makeG7Core({
      _local: { registerForm: { email: 'newuser@example.com' } },
    });

    await sirsoftBasicIdentityLauncher({ ...basePayload, purpose: 'signup' } as any);

    expect(sentTarget()).toEqual({ email: 'newuser@example.com' });
  });

  it('선언값·세션·폼 모두 없으면 target 을 동봉하지 않는다 (서버 세션 도출)', async () => {
    (window as any).G7Core = makeG7Core({ _local: {} });

    await sirsoftBasicIdentityLauncher({ ...basePayload } as any);

    expect(sentTarget()).toBeUndefined();
  });
});
