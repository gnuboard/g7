/**
 * sirsoft-admin_basic IDV launcher — challenge 요청에 428 의 정책 키를 싣는다 (#699)
 *
 * 라우트·훅 정책의 428 응답은 정책 키를 알려 주지만 launcher 가 challenge 요청 본문에 목적·프로바이더·대상만 실어,
 * 서버가 어떤 정책 때문에 인증하는지 몰랐다. 그래서 운영자가 알림 설정에서 달아 둔 정책별 문구가 쓰이지 않고
 * 늘 목적 공통 문구가 나갔다. 서버는 받은 정책 키를 그 정책이 켜져 있고 목적이 같을 때만 쓴다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { sirsoftAdminBasicIdentityLauncher } from '../identityLauncher';

let bodies: Array<Record<string, unknown>> = [];

const mockFetch = vi.fn(async (_url: string, init?: RequestInit) => {
  bodies.push(JSON.parse(String(init?.body ?? '{}')));
  return {
    ok: true,
    status: 200,
    json: async () => ({
      success: true,
      data: { id: 'ch_1', expires_at: new Date(Date.now() + 300000).toISOString(), render_hint: 'text_code' },
    }),
  } as any;
});

const payload = (policy_key?: string) => ({
  policy_key,
  purpose: 'sensitive_action',
  provider_id: 'g7:core.mail',
  render_hint: 'text_code',
  return_request: { method: 'POST', url: '/api/modules/sirsoft-board/boards/free/posts' },
  challenge_start_url: '/api/identity/challenges',
});

beforeEach(() => {
  bodies = [];
  vi.stubGlobal('fetch', mockFetch);
  (window as any).G7Core = {
    dispatch: vi.fn(async () => undefined),
    state: { get: vi.fn(() => ({})), getGlobal: vi.fn(() => ({ currentUser: { email: 'member@example.test' } })), getLocal: vi.fn(() => ({})), set: vi.fn() },
    api: { getToken: vi.fn(() => 'MEMBER-TOKEN') },
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

describe('sirsoftAdminBasicIdentityLauncher — challenge 요청의 정책 키', () => {
  /**
   * @effects launcher_sends_policy_key_from_428
   */
  it('428 이 알려 준 정책 키를 challenge 요청 본문에 싣는다', async () => {
    // @scenario surface=launcher_policy_key
    await sirsoftAdminBasicIdentityLauncher(payload('admin.idv.board_write') as any);

    expect(bodies[0]?.policy_key).toBe('admin.idv.board_write');
    expect(bodies[0]?.purpose).toBe('sensitive_action');
  });

  /**
   * @effects launcher_sends_policy_key_from_428
   */
  it('정책 키가 없으면 본문에 싣지 않는다 (종전 요청 모양 유지)', async () => {
    // @scenario surface=launcher_policy_key
    await sirsoftAdminBasicIdentityLauncher(payload(undefined) as any);

    expect(bodies[0]).toBeDefined();
    expect('policy_key' in (bodies[0] ?? {})).toBe(false);
  });
});
