/**
 * ApiClient 교차 출처 응답·출처 판정 테스트 (KVE-2026-2350)
 *
 * 레이아웃은 데이터 소스·apiCall 대상 주소를 표현식으로 조립할 수 있어 저장측 검사가 외부 주소를 다
 * 잡지 못한다. 요청 자체에는 토큰을 싣지 않더라도, 외부 서버의 응답이 우리 인증 흐름을 조종하면
 * 토큰이 후속 요청으로 나간다.
 *
 * - 401: 토큰을 갱신한 뒤 새 토큰을 헤더에 직접 붙여 같은 외부 주소로 재시도했다.
 * - 428: 응답의 `challenge_start_url` 을 본인인증 launcher 가 그대로 믿고 토큰을 실어 보냈다.
 *
 * 교차 출처 응답의 401·428 은 따르지 않는다(토큰 첨부와 같은 출처 판정). 확장이 직접 fetch 할 때 쓰는
 * `isSameOriginUrl` 은 apiCall 과 같은 판정을 노출한다.
 *
 * @since engine-v1.73.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import ApiClient from '../ApiClient';
import axios from 'axios';
import { IdentityGuardInterceptor } from '../../identity/IdentityGuardInterceptor';
import { AuthManager } from '../../auth/AuthManager';

vi.mock('axios');
const mockedAxios = axios as any;

vi.mock('../../identity/IdentityGuardInterceptor', () => ({
  IdentityGuardInterceptor: {
    isIdentityRequired: vi.fn(),
    handle: vi.fn(),
  },
}));
const mockedGuard = IdentityGuardInterceptor as unknown as {
  isIdentityRequired: ReturnType<typeof vi.fn>;
  handle: ReturnType<typeof vi.fn>;
};

/**
 * response 인터셉터의 onRejected 콜백을 캡처합니다.
 *
 * @returns 캡처된 onRejected
 */
function buildClientCapturingRejected(): (e: any) => Promise<any> {
  let captured: ((e: any) => Promise<any>) | null = null;
  mockedAxios.create.mockReturnValue({
    interceptors: {
      request: { use: vi.fn(() => 0) },
      response: {
        use: vi.fn((_onFulfilled: any, onRejected: any) => {
          captured = onRejected;
          return 0;
        }),
      },
    },
    get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(),
  });
  new ApiClient();
  return captured!;
}

const identity428Body = {
  error_code: 'identity_verification_required',
  verification: {
    challenge_start_url: 'https://evil.example/steal',
    return_request: { method: 'POST', url: 'https://evil.example/x' },
  },
};

describe('ApiClient — 교차 출처 응답은 인증 흐름을 조종하지 못한다 (engine-v1.73.0)', () => {
  beforeEach(() => {
    localStorage.setItem('auth_token', 'ADMIN-TOKEN');
  });

  afterEach(() => {
    vi.clearAllMocks();
    vi.restoreAllMocks();
    localStorage.clear();
  });

  /**
   * @effects cross_origin_identity_response_not_trusted, sandbox_escape_blocked
   */
  it('교차 출처 요청의 428 응답은 본인인증 흐름으로 넘기지 않고 원 에러로 전파한다', async () => {
    mockedGuard.isIdentityRequired.mockReturnValue(true);
    const onRejected = buildClientCapturingRejected();

    for (const url of ['https://evil.example/x', '//evil.example/x']) {
      const offsite428 = { config: { url, headers: {} }, response: { status: 428, data: identity428Body } };
      await expect(onRejected(offsite428)).rejects.toBe(offsite428);
    }
    expect(mockedGuard.handle).not.toHaveBeenCalled();
  });

  /**
   * @effects cross_origin_identity_response_not_trusted
   */
  it('동일 출처 요청의 428 응답은 종전대로 본인인증 흐름으로 넘긴다 (정상 회귀)', async () => {
    mockedGuard.isIdentityRequired.mockReturnValue(true);
    mockedGuard.handle.mockResolvedValue(null);
    const onRejected = buildClientCapturingRejected();

    const onsite428 = { config: { url: '/api/admin/action', headers: {} }, response: { status: 428, data: identity428Body } };
    await expect(onRejected(onsite428)).rejects.toBe(onsite428);
    expect(mockedGuard.handle).toHaveBeenCalledOnce();
  });

  /**
   * @effects cross_origin_identity_response_not_trusted, sandbox_escape_blocked
   */
  it('교차 출처 요청의 401 응답은 토큰을 갱신해 외부로 재시도하지 않는다', async () => {
    mockedGuard.isIdentityRequired.mockReturnValue(false);
    const refreshToken = vi.fn(async () => true);
    vi.spyOn(AuthManager, 'getInstance').mockReturnValue({ refreshToken } as unknown as AuthManager);
    const onRejected = buildClientCapturingRejected();

    const offsite401 = {
      config: { url: 'https://evil.example/data', headers: {} as Record<string, string> },
      response: { status: 401, data: {} },
    };
    await expect(onRejected(offsite401)).rejects.toBe(offsite401);
    expect(refreshToken).not.toHaveBeenCalled();
    expect(offsite401.config.headers.Authorization).toBeUndefined();
  });

  /**
   * @effects cross_origin_identity_response_not_trusted
   */
  it('동일 출처 요청의 401 응답은 종전대로 토큰을 갱신한다 (정상 회귀)', async () => {
    mockedGuard.isIdentityRequired.mockReturnValue(false);
    const refreshToken = vi.fn(async () => false);
    vi.spyOn(AuthManager, 'getInstance').mockReturnValue({ refreshToken } as unknown as AuthManager);
    const onRejected = buildClientCapturingRejected();

    const onsite401 = { config: { url: '/api/admin/users', headers: {} }, response: { status: 401, data: {} } };
    await expect(onRejected(onsite401)).rejects.toBe(onsite401);
    expect(refreshToken).toHaveBeenCalledOnce();
  });

  /**
   * @effects extension_token_same_origin_only
   */
  it('isSameOriginUrl 은 apiCall 과 같은 출처 판정을 확장에 노출한다', () => {
    const apiClient = new ApiClient();

    expect(apiClient.isSameOriginUrl('/api/admin/users/me/language')).toBe(true);
    expect(apiClient.isSameOriginUrl(`${window.location.origin}/api/me/avatar`)).toBe(true);
    expect(apiClient.isSameOriginUrl('https://evil.example/steal')).toBe(false);
    expect(apiClient.isSameOriginUrl('//evil.example/steal')).toBe(false);
    expect(apiClient.isSameOriginUrl('/\\evil.example/steal')).toBe(false);
    expect(apiClient.isSameOriginUrl('javascript:alert(1)')).toBe(false);
    expect(apiClient.isSameOriginUrl(undefined)).toBe(false);
  });
});
