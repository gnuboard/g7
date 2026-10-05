/**
 * sirsoft-admin_basic IDV launcher — 링크 방식 인증의 완료를 시작한 화면이 받아 간다 (#699)
 *
 * 비밀번호 재설정 목적은 인증번호 대신 확인 링크를 메일로 보낸다. 링크는 다른 기기에서 열릴 수 있고 확인 결과의
 * 토큰은 링크를 연 쪽에만 가므로, 인증 창을 띄운 원래 화면은 끝났는지 알 수 없어 재설정이 멈춰 있었다.
 * challenge 시작 응답의 일회용 비밀값으로 서버에 결과를 물어, 토큰을 받으면 원래 요청을 이어 간다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { sirsoftAdminBasicIdentityLauncher, rememberIdentityClaimHandler } from '../identityLauncher';

let requests: Array<{ url: string; body: Record<string, unknown> }> = [];
let claimAnswers: Array<{ status: number; json: unknown }> = [];
let dispatched: Array<Record<string, any>> = [];
let resolveDeferred: (v: unknown) => void = () => {};

const mockFetch = vi.fn(async (url: string, init?: RequestInit) => {
  requests.push({ url, body: JSON.parse(String(init?.body ?? '{}')) });
  if (url.endsWith('/claim')) {
    const next = claimAnswers.shift() ?? { status: 202, json: { success: true, data: { status: 'pending' } } };
    return { ok: next.status < 300, status: next.status, json: async () => next.json } as any;
  }
  return {
    ok: true,
    status: 201,
    json: async () => ({
      success: true,
      data: { id: 'ch_link', expires_at: new Date(Date.now() + 300000).toISOString(), render_hint: 'link', claim_secret: 'S'.repeat(48) },
    }),
  } as any;
});

const payload = {
  policy_key: 'core.auth.password_reset',
  purpose: 'password_reset',
  provider_id: 'g7:core.mail',
  render_hint: 'link',
  return_request: { method: 'POST', url: '/api/auth/reset-password' },
  challenge_start_url: '/api/identity/challenges',
  target: { email: 'member@example.test' },
};

beforeEach(() => {
  vi.useFakeTimers();
  requests = [];
  claimAnswers = [];
  dispatched = [];
  vi.stubGlobal('fetch', mockFetch);
  (window as any).G7Core = {
    dispatch: vi.fn(async (action: Record<string, any>) => {
      dispatched.push(action);
      if (action.handler === 'resolveIdentityChallenge') resolveDeferred({ status: action.params?.result, token: action.params?.token });
    }),
    state: { get: vi.fn(() => ({})), getGlobal: vi.fn(() => ({})), getLocal: vi.fn(() => ({})), set: vi.fn(), subscribe: vi.fn(() => () => {}) },
    api: { getToken: vi.fn(() => null) },
    identity: {
      createDeferred: vi.fn(() => new Promise((r) => { resolveDeferred = r; })),
      redirectExternally: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
    },
  };
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  delete (window as any).G7Core;
});

describe('sirsoftAdminBasicIdentityLauncher — 링크 방식 완료 받아 가기', () => {
  /**
   * @effects launcher_claims_link_verification_with_secret
   */
  it('링크가 확인되면 시작 응답의 비밀값으로 토큰을 받아 원래 요청을 이어 간다', async () => {
    // @scenario surface=launcher_link_claim
    claimAnswers = [
      { status: 202, json: { success: true, data: { status: 'pending' } } },
      { status: 200, json: { success: true, data: { status: 'verified', verification_token: 'TOKEN-1' } } },
    ];

    const pending = sirsoftAdminBasicIdentityLauncher(payload as any);
    await vi.advanceTimersByTimeAsync(10000);
    const result = await pending;

    const claims = requests.filter((r) => r.url.endsWith('/claim'));
    expect(claims.length).toBeGreaterThanOrEqual(2);
    expect(claims[0].url).toBe('/api/identity/challenges/ch_link/claim');
    expect(claims[0].body.claim_secret).toBe('S'.repeat(48));
    expect(result).toEqual({ status: 'verified', token: 'TOKEN-1' });
    expect(dispatched.some((a) => a.handler === 'closeModal')).toBe(true);
    // 비밀값은 전역 상태에 쓰지 않는다(개발자 도구로 누구나 볼 수 있다).
    const stateWrites = JSON.stringify(((window as any).G7Core.state.set as any).mock?.calls ?? []);
    expect(stateWrites).not.toContain('S'.repeat(48));
  });

  /**
   * @effects launcher_claims_link_verification_with_secret
   */
  it('[다시 보내기]로 새 건이 생기면 넘겨받은 새 비밀값으로 새 건을 묻는다', async () => {
    // @scenario surface=launcher_link_claim
    let globalState: Record<string, any> = {};
    (window as any).G7Core.state.getGlobal = vi.fn(() => globalState);
    claimAnswers = [
      { status: 202, json: { success: true, data: { status: 'pending' } } },
      { status: 200, json: { success: true, data: { status: 'verified', verification_token: 'TOKEN-2' } } },
    ];

    const pending = sirsoftAdminBasicIdentityLauncher(payload as any);
    await vi.advanceTimersByTimeAsync(3500);
    globalState = { identityChallenge: { challenge_id: 'ch_new' } };
    expect(rememberIdentityClaimHandler({ params: { challenge_id: 'ch_new', claim_secret: 'N'.repeat(48) } })).toBe(true);
    await vi.advanceTimersByTimeAsync(3500);
    const result = await pending;

    const claims = requests.filter((r) => r.url.endsWith('/claim'));
    expect(claims[0].url).toBe('/api/identity/challenges/ch_link/claim');
    expect(claims[1].url).toBe('/api/identity/challenges/ch_new/claim');
    expect(claims[1].body.claim_secret).toBe('N'.repeat(48));
    expect(result).toEqual({ status: 'verified', token: 'TOKEN-2' });
  });

  /**
   * @effects launcher_claims_link_verification_with_secret
   */
  it('인증번호 방식(비밀값 없음)은 결과를 묻지 않는다', async () => {
    // @scenario surface=launcher_link_claim
    mockFetch.mockImplementationOnce(async (url: string, init?: RequestInit) => {
      requests.push({ url, body: JSON.parse(String(init?.body ?? '{}')) });
      return { ok: true, status: 201, json: async () => ({ success: true, data: { id: 'ch_code', expires_at: new Date(Date.now() + 300000).toISOString(), render_hint: 'text_code' } }) } as any;
    });

    const pending = sirsoftAdminBasicIdentityLauncher({ ...payload, purpose: 'sensitive_action', render_hint: 'text_code' } as any);
    await vi.advanceTimersByTimeAsync(10000);
    resolveDeferred({ status: 'cancelled' });
    await pending;

    expect(requests.filter((r) => r.url.endsWith('/claim'))).toHaveLength(0);
  });
});
