/**
 * sirsoft-admin_basic IDV Launcher 단위 테스트.
 *
 * challenge 시작 fetch 가 ApiClient 를 우회하는 raw fetch 이므로, ApiClient 가 모든
 * 요청에 부착하는 `Accept-Language: g7_locale` 을 launcher 도 동일하게 부착해야
 * IDV 메일이 사용자 화면 언어로 렌더된다(누락 시 브라우저 기본 언어로 발송되는 회귀).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { sirsoftAdminBasicIdentityLauncher } from '../identityLauncher';

/** challenge 시작 fetch 에 실린 headers 를 캡처. */
let lastChallengeHeaders: Record<string, string> | null = null;

const mockFetch = vi.fn(async (_url: string, init?: RequestInit) => {
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
const makeG7Core = () => ({
  dispatch: vi.fn(async () => undefined),
  state: {
    get: vi.fn(() => ({})),
    getGlobal: vi.fn(() => ({})),
    getLocal: vi.fn(() => ({})),
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
  policy_key: 'core.user.change_password',
  purpose: 'sensitive_action',
  provider_id: 'g7:core.mail',
  render_hint: 'text_code',
  return_request: { method: 'POST', url: '/api/admin/action' },
};

beforeEach(() => {
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

describe('sirsoftAdminBasicIdentityLauncher — challenge 요청 로케일 헤더', () => {
  it('g7_locale 을 Accept-Language 헤더로 부착한다 (IDV 메일이 화면 언어를 따르도록)', async () => {
    window.localStorage.setItem('g7_locale', 'ko');
    (window as any).G7Core = makeG7Core();

    await sirsoftAdminBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'admin@example.com' },
    } as any);

    expect(lastChallengeHeaders?.['Accept-Language']).toBe('ko');
  });

  it('g7_locale 미설정 시 Accept-Language 를 부착하지 않는다 (브라우저 기본/서버 폴백)', async () => {
    (window as any).G7Core = makeG7Core();

    await sirsoftAdminBasicIdentityLauncher({
      ...basePayload,
      target: { email: 'admin@example.com' },
    } as any);

    expect(lastChallengeHeaders?.['Accept-Language']).toBeUndefined();
  });
});
