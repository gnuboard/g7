/**
 * sirsoft-admin_basic IDV launcher — challenge 시작 주소의 토큰 첨부 출처 (KVE-2026-2350)
 *
 * challenge 시작 주소는 428 응답 본문(`challenge_start_url`)에서 온다. 교차 출처 서버가 428 을 흉내 내
 * 자기 주소를 넣으면 관리자 토큰을 그 주소로 보냈다. 토큰은 동일 출처 주소에만 싣는다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { sirsoftAdminBasicIdentityLauncher } from '../identityLauncher';

let calls: Array<{ url: string; headers: Record<string, string> }> = [];

const mockFetch = vi.fn(async (url: string, init?: RequestInit) => {
  calls.push({ url, headers: (init?.headers as Record<string, string>) ?? {} });
  return {
    ok: true,
    status: 200,
    json: async () => ({
      success: true,
      data: { id: 'ch_1', expires_at: new Date(Date.now() + 300000).toISOString(), render_hint: 'text_code' },
    }),
  } as any;
});

const payload = (challenge_start_url?: string) => ({
  policy_key: 'core.user.change_password',
  purpose: 'sensitive_action',
  provider_id: 'g7:core.mail',
  render_hint: 'text_code',
  return_request: { method: 'POST', url: '/api/admin/action' },
  challenge_start_url,
});

beforeEach(() => {
  calls = [];
  vi.stubGlobal('fetch', mockFetch);
  (window as any).G7Core = {
    dispatch: vi.fn(async () => undefined),
    state: { get: vi.fn(() => ({})), getGlobal: vi.fn(() => ({})), getLocal: vi.fn(() => ({})), set: vi.fn() },
    api: { getToken: vi.fn(() => 'ADMIN-TOKEN') },
    identity: {
      createDeferred: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
      redirectExternally: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
    },
  };
});

afterEach(() => {
  vi.unstubAllGlobals();
  delete (window as any).G7Core;
});

describe('sirsoftAdminBasicIdentityLauncher — challenge 시작 주소의 토큰 첨부', () => {
  /**
   * @effects extension_token_same_origin_only
   */
  it('교차 출처 challenge_start_url 에는 Authorization 을 싣지 않는다', async () => {
    // @scenario sink=identity_launcher, target=cross_origin
    for (const url of ['https://evil.example/steal', '//evil.example/steal', '/\\evil.example/steal']) {
      calls = [];
      await sirsoftAdminBasicIdentityLauncher(payload(url) as any);

      expect(calls[0]?.url).toBe(url);
      expect(calls[0]?.headers.Authorization).toBeUndefined();
    }
  });

  /**
   * @effects extension_token_same_origin_only
   */
  it('동일 출처 challenge_start_url 에는 종전대로 Authorization 을 싣는다 (정상 회귀)', async () => {
    // @scenario sink=identity_launcher, target=same_origin
    for (const url of [undefined, '/api/identity/challenges', `${window.location.origin}/api/identity/challenges`]) {
      calls = [];
      await sirsoftAdminBasicIdentityLauncher(payload(url) as any);

      expect(calls[0]?.headers.Authorization).toBe('Bearer ADMIN-TOKEN');
    }
  });
});
