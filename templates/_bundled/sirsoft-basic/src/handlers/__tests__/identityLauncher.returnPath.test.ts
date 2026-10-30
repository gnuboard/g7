/**
 * sirsoft-basic IDV launcher — challenge 요청에 지금 화면 경로(return_path)를 싣는다
 *
 * 428 로 걸린 작업(비밀번호 설정 등)에서 launcher 가 연 challenge 를 서버가 외부 화면을 거치는 수단(회사 계정 다시 로그인,
 * 소셜 다시 로그인)으로 보내면, 그 수단은 확인을 마친 뒤 요청 맥락의 `return_path` 로 돌려보낸다. launcher 가 이 값을
 * 싣지 않아 회사 계정은 마이페이지, 소셜은 첫 화면으로 떨어져 하던 일을 이어 갈 수 없었다.
 *
 * 돌아갈 경로는 같은 출처 경로+쿼리만(해시 없음), 지난 왕복의 결과 쿼리(`verification_token`·`social_reauth`·`social_error`)는 뺀다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import ApiClient from '@core/api/ApiClient';
import { sirsoftBasicIdentityLauncher } from '../identityLauncher';

let bodies: Array<Record<string, unknown>> = [];
let originalLocation: Location;

/**
 * 실제 코어 ApiClient 를 만들고 전송 직전 요청을 가로챈다 (네트워크 없음).
 *
 * @param data 시작 응답의 data
 * @return 코어 ApiClient
 */
function createCapturingCoreApi(data: Record<string, unknown>): ApiClient {
  const client = new ApiClient();
  client.getInstance().defaults.adapter = async (config: any) => {
    bodies.push(JSON.parse(String(config.data ?? '{}')));
    return { data: { success: true, data }, status: 201, statusText: '201', headers: {}, config, request: {} };
  };
  return client;
}

/**
 * 지금 화면 주소를 바꿔 끼웁니다.
 *
 * @param pathname 경로
 * @param search 쿼리 (`?` 포함)
 * @param hash 해시
 */
function setLocation(pathname: string, search = '', hash = ''): void {
  delete (window as any).location;
  (window as any).location = {
    origin: 'http://localhost', protocol: 'http:', host: 'localhost', hostname: 'localhost', port: '',
    pathname, search, hash, href: `http://localhost${pathname}${search}${hash}`,
    assign: vi.fn(), replace: vi.fn(), reload: vi.fn(),
  };
}

const redirectExternally = vi.fn(() => Promise.resolve({ status: 'cancelled' }));

/**
 * 코어 대역을 세웁니다.
 *
 * @param data 시작 응답의 data
 */
function setupCore(data: Record<string, unknown>): void {
  (window as any).G7Core = {
    dispatch: vi.fn(async () => undefined),
    state: { get: vi.fn(() => ({})), getGlobal: vi.fn(() => ({ currentUser: { email: 'member@social.invalid' } })), getLocal: vi.fn(() => ({})), set: vi.fn() },
    api: createCapturingCoreApi(data),
    identity: {
      createDeferred: vi.fn(() => Promise.resolve({ status: 'cancelled' })),
      redirectExternally,
    },
  };
}

const payload = {
  policy_key: 'core.profile.password_change',
  purpose: 'sensitive_action',
  provider_id: 'g7:core.mail',
  render_hint: 'text_code',
  return_request: { method: 'PUT', url: '/api/me/password' },
  challenge_start_url: '/api/identity/challenges',
};

const externalResponse = {
  id: 'ch_ext',
  expires_at: new Date(Date.now() + 600000).toISOString(),
  render_hint: 'external_redirect',
  redirect_url: '/plugins/sirsoft-sso/login/start?ticket=abc',
};

beforeEach(() => {
  bodies = [];
  redirectExternally.mockClear();
  originalLocation = window.location;
  vi.stubGlobal('fetch', vi.fn(async () => {
    throw new Error('direct fetch');
  }));
});

afterEach(() => {
  vi.unstubAllGlobals();
  (window as any).location = originalLocation;
  delete (window as any).G7Core;
});

describe('sirsoftBasicIdentityLauncher — 외부 재확인 뒤 돌아올 화면', () => {
  /**
   * @effects launcher_sends_current_return_path
   */
  it('challenge 요청 본문에 지금 화면 경로를 return_path 로 싣고, 외부 수단의 이동 주소로 보낸다', async () => {
    // @scenario surface=launcher_return_path, page=change_password
    setLocation('/mypage/change-password');
    setupCore(externalResponse);

    await sirsoftBasicIdentityLauncher(payload as any);

    expect(bodies[0]?.return_path).toBe('/mypage/change-password');
    expect(redirectExternally).toHaveBeenCalledWith(expect.objectContaining({ redirect_url: externalResponse.redirect_url }));
  });

  /**
   * @effects launcher_return_path_drops_result_query
   */
  it('화면 쿼리는 남기고 지난 왕복의 결과 쿼리와 해시는 뺀다', async () => {
    // @scenario surface=launcher_return_path, page=query_with_previous_result
    setLocation('/mypage/orders', '?page=2&verification_token=old&social_reauth=code&social_error=reauth_failed', '#top');
    setupCore(externalResponse);

    await sirsoftBasicIdentityLauncher(payload as any);

    expect(bodies[0]?.return_path).toBe('/mypage/orders?page=2');
  });

  /**
   * @effects launcher_sends_current_return_path
   */
  it('인증번호 방식 수단에도 같은 값을 싣는다(서버는 외부 이동 수단만 쓴다)', async () => {
    // @scenario surface=launcher_return_path, page=text_code
    setLocation('/board/free/write');
    setupCore({ id: 'ch_code', expires_at: new Date(Date.now() + 300000).toISOString(), render_hint: 'text_code' });

    await sirsoftBasicIdentityLauncher(payload as any);

    expect(bodies[0]?.return_path).toBe('/board/free/write');
    expect(redirectExternally).not.toHaveBeenCalled();
  });
});
