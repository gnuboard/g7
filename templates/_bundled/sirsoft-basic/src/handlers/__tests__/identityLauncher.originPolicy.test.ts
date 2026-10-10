/**
 * sirsoft-basic IDV launcher — challenge 시작 주소의 토큰 첨부 출처 (KVE-2026-2350)
 *
 * challenge 시작 주소는 428 응답 본문(`challenge_start_url`)에서 온다. 교차 출처 서버가 428 을 흉내 내
 * 자기 주소를 넣으면 회원 토큰을 그 주소로 보냈다. 토큰은 동일 출처 주소에만 싣는다.
 *
 * launcher 는 challenge 시작 요청을 코어 `G7Core.api` 로 보내고 토큰 첨부를 코어에 맡긴다. 그래서 이
 * 테스트는 실제 코어 ApiClient 를 `G7Core.api` 로 세우고, 전송 직전 요청(최종 주소·헤더)을 어댑터에서
 * 가로채 판정한다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import ApiClient from '@core/api/ApiClient';
import { sirsoftBasicIdentityLauncher } from '../identityLauncher';

interface SentRequest {
  url: string;
  origin: string;
  headers: Record<string, unknown>;
}

let calls: SentRequest[] = [];

/**
 * 실제 코어 ApiClient 를 만들고 전송 직전 요청을 가로챈다 (네트워크 없음).
 *
 * @param sent 가로챈 요청을 쌓을 배열
 * @return 코어 ApiClient
 */
function createCapturingCoreApi(sent: SentRequest[]): ApiClient {
  const client = new ApiClient();
  const instance = client.getInstance();
  instance.defaults.adapter = async (config: any) => {
    const full = instance.getUri({ baseURL: config.baseURL, url: config.url });
    const headers = typeof config.headers?.toJSON === 'function' ? config.headers.toJSON() : { ...config.headers };
    sent.push({ url: config.url, origin: new URL(full, `${window.location.origin}/`).origin, headers });
    return {
      data: {
        success: true,
        data: { id: 'ch_1', expires_at: new Date(Date.now() + 300000).toISOString(), render_hint: 'text_code' },
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

const payload = (challenge_start_url?: string) => ({
  policy_key: 'sirsoft-ecommerce.payment.confirm',
  purpose: 'sensitive_action',
  provider_id: 'g7:core.mail',
  render_hint: 'text_code',
  return_request: { method: 'POST', url: '/api/orders' },
  challenge_start_url,
});

beforeEach(() => {
  calls = [];
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('raw fetch must not be used')));
  localStorage.setItem('auth_token', 'MEMBER-TOKEN');
  (window as any).G7Core = {
    dispatch: vi.fn(async () => undefined),
    state: { get: vi.fn(() => ({})), getGlobal: vi.fn(() => ({})), getLocal: vi.fn(() => ({})), set: vi.fn() },
    api: createCapturingCoreApi(calls),
    identity: {
      createDeferred: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
      redirectExternally: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
    },
  };
});

afterEach(() => {
  vi.unstubAllGlobals();
  delete (window as any).G7Core;
  localStorage.clear();
});

describe('sirsoftBasicIdentityLauncher — challenge 시작 주소의 토큰 첨부', () => {
  // @scenario sink=identity_launcher, target=cross_origin
  // @effects extension_token_same_origin_only
  it('교차 출처 challenge_start_url 에는 Authorization 을 싣지 않는다', async () => {
    for (const url of ['https://evil.example/steal', '//evil.example/steal']) {
      calls.length = 0;
      await sirsoftBasicIdentityLauncher(payload(url) as any);

      expect(calls).toHaveLength(1);
      expect(new URL(calls[0].origin).hostname).toBe('evil.example');
      expect(calls[0].headers.Authorization).toBeUndefined();
    }

    // 백슬래시 변형(/\host)은 외부로 나가지 않고 자기 서버 /api 아래로 간다
    calls.length = 0;
    await sirsoftBasicIdentityLauncher(payload('/\\evil.example/steal') as any);
    expect(calls[0].origin).toBe(window.location.origin);
  });

  // @scenario sink=identity_launcher, target=same_origin
  // @effects extension_token_same_origin_only
  it('동일 출처 challenge_start_url 에는 종전대로 Authorization 을 싣는다 (정상 회귀)', async () => {
    for (const url of [undefined, '/api/identity/challenges', `${window.location.origin}/api/identity/challenges`]) {
      calls.length = 0;
      await sirsoftBasicIdentityLauncher(payload(url) as any);

      expect(calls[0].origin).toBe(window.location.origin);
      expect(calls[0].headers.Authorization).toBe('Bearer MEMBER-TOKEN');
    }
  });
});
