/**
 * ApiClient 무토큰 401 테스트 (#883, engine-v1.88.2)
 *
 * 비회원이 권한 없는 기능(편집기 이미지 업로드·모바일 결제 승인키·클레임 신청)을 누르면 서버는 401 을 준다.
 * 종전 응답 인터셉터는 그 401 도 세션 만료로 보고 토큰 갱신 → 실패 → `onUnauthorized`(로그인 화면 이동,
 * `reason=session_expired`)를 불러 쓰던 글이 사라졌다. 「세션이 만료됐다」고 볼 수 있는 것은 토큰을 실어
 * 보낸 요청뿐이므로, 토큰 실음 여부를 **요청 시점**에 기록하고 무토큰 401 은 호출자에게 돌려준다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import ApiClient from '../ApiClient';
import axios from 'axios';
import { AuthManager } from '../../auth/AuthManager';

vi.mock('axios');
const mockedAxios = axios as any;

interface CapturedInterceptors {
  onRequest: (config: any) => any;
  onRejected: (error: any) => Promise<any>;
  client: ApiClient;
}

/**
 * 요청·응답 인터셉터를 캡처합니다.
 *
 * @param onUnauthorized 401 갱신 실패 콜백
 * @returns 캡처된 인터셉터와 클라이언트
 */
function buildClient(onUnauthorized: () => void): CapturedInterceptors {
  let onRequest: ((config: any) => any) | null = null;
  let onRejected: ((e: any) => Promise<any>) | null = null;
  mockedAxios.create.mockReturnValue({
    interceptors: {
      request: {
        use: vi.fn((fulfilled: any) => {
          onRequest = fulfilled;
          return 0;
        }),
      },
      response: {
        use: vi.fn((_fulfilled: any, rejected: any) => {
          onRejected = rejected;
          return 0;
        }),
      },
    },
    get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(),
  });
  const client = new ApiClient({ onUnauthorized });
  return { onRequest: onRequest!, onRejected: onRejected!, client };
}

/**
 * 요청 인터셉터를 거친 401 오류를 만듭니다.
 *
 * @param onRequest 요청 인터셉터
 * @param url 요청 주소
 * @param headers 호출자가 직접 넣은 헤더
 * @returns axios 오류 모양 객체
 */
function rejectedThroughRequestInterceptor(onRequest: (c: any) => any, url: string, headers: Record<string, string> = {}): any {
  const config = onRequest({ url, baseURL: '/api', headers: { ...headers } });
  return { config, response: { status: 401, data: { message: 'Unauthenticated.' } } };
}

describe('ApiClient — 토큰 없이 보낸 요청의 401 은 세션 만료가 아니다 (#883)', () => {
  let refreshToken: ReturnType<typeof vi.fn>;
  let onUnauthorized: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    localStorage.clear();
    refreshToken = vi.fn(async () => false);
    onUnauthorized = vi.fn();
    vi.spyOn(AuthManager, 'getInstance').mockReturnValue({ refreshToken } as unknown as AuthManager);
  });

  afterEach(() => {
    vi.clearAllMocks();
    vi.restoreAllMocks();
    localStorage.clear();
  });

  /**
   * @scenario request=anonymous
   * @effects anonymous_unauthorized_not_redirected
   */
  it('무토큰 401 은 토큰 갱신·onUnauthorized 를 부르지 않고 g7Auth.tokenSent=false 로 reject 한다', async () => {
    const { onRequest, onRejected, client } = buildClient(onUnauthorized);
    const removeSpy = vi.spyOn(client, 'removeToken');

    const error = rejectedThroughRequestInterceptor(onRequest, '/api/plugins/sirsoft-ckeditor5/upload');
    expect(error.config._g7AuthTokenSent).toBe(false);

    await expect(onRejected(error)).rejects.toBe(error);
    expect(refreshToken).not.toHaveBeenCalled();
    expect(onUnauthorized).not.toHaveBeenCalled();
    expect(removeSpy).not.toHaveBeenCalled();
    expect(error.g7Auth).toEqual({ tokenSent: false });
    expect(Object.isFrozen(error.g7Auth)).toBe(true);
    expect(client.isAnonymousUnauthorized(error)).toBe(true);
  });

  /**
   * @scenario request=authenticated
   * @effects authenticated_unauthorized_still_redirects
   */
  it('토큰을 실은 401 은 종전대로 갱신을 시도하고 실패하면 onUnauthorized 를 부른다', async () => {
    localStorage.setItem('auth_token', 'EXPIRED-TOKEN');
    const { onRequest, onRejected, client } = buildClient(onUnauthorized);

    const error = rejectedThroughRequestInterceptor(onRequest, '/api/me');
    expect(error.config.headers.Authorization).toBe('Bearer EXPIRED-TOKEN');
    expect(error.config._g7AuthTokenSent).toBe(true);

    await expect(onRejected(error)).rejects.toBe(error);
    expect(refreshToken).toHaveBeenCalledOnce();
    expect(onUnauthorized).toHaveBeenCalledOnce();
    expect(localStorage.getItem('auth_token')).toBeNull();
    expect(client.isAnonymousUnauthorized(error)).toBe(false);
  });

  /**
   * @scenario request=token_cleared_after_send
   * @effects authenticated_unauthorized_still_redirects
   */
  it('보낸 뒤 토큰이 지워져도(다른 요청의 갱신 실패) 요청 시점 기록이 판정 근거다', async () => {
    localStorage.setItem('auth_token', 'TOKEN-AT-SEND');
    const { onRequest, onRejected } = buildClient(onUnauthorized);

    const error = rejectedThroughRequestInterceptor(onRequest, '/api/me');
    localStorage.removeItem('auth_token');

    await expect(onRejected(error)).rejects.toBe(error);
    expect(refreshToken).toHaveBeenCalledOnce();
    expect(onUnauthorized).toHaveBeenCalledOnce();
    expect(error.g7Auth).toBeUndefined();
  });

  /**
   * @scenario request=logged_in_after_send
   * @effects authenticated_unauthorized_still_redirects
   */
  it('보낸 뒤 다른 탭에서 로그인해도 무토큰으로 보낸 요청은 이동하지 않는다', async () => {
    const { onRequest, onRejected } = buildClient(onUnauthorized);

    const error = rejectedThroughRequestInterceptor(onRequest, '/api/plugins/sirsoft-ckeditor5/upload');
    localStorage.setItem('auth_token', 'LOGGED-IN-ELSEWHERE');

    await expect(onRejected(error)).rejects.toBe(error);
    expect(refreshToken).not.toHaveBeenCalled();
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  /**
   * @scenario request=caller_authorization_header
   * @effects authenticated_unauthorized_still_redirects
   */
  it('호출자가 직접 넣은 Authorization 헤더도 토큰을 실은 것으로 본다', async () => {
    const { onRequest, onRejected } = buildClient(onUnauthorized);

    const error = rejectedThroughRequestInterceptor(onRequest, '/api/me', { Authorization: 'Bearer CALLER' });
    expect(error.config._g7AuthTokenSent).toBe(true);

    await expect(onRejected(error)).rejects.toBe(error);
    expect(refreshToken).toHaveBeenCalledOnce();
  });

  /**
   * @scenario request=auth_or_cross_origin
   * @effects anonymous_unauthorized_not_redirected
   */
  it('/auth/ 경로·교차 출처 401 갈래는 종전과 같다 (갱신·콜백 없음, g7Auth 표지 없음)', async () => {
    const { onRequest, onRejected } = buildClient(onUnauthorized);

    const authError = rejectedThroughRequestInterceptor(onRequest, '/api/auth/login');
    await expect(onRejected(authError)).rejects.toBe(authError);
    expect(authError.g7Auth).toBeUndefined();

    const offsite = rejectedThroughRequestInterceptor(onRequest, 'https://evil.example/x');
    await expect(onRejected(offsite)).rejects.toBe(offsite);
    expect(offsite.g7Auth).toBeUndefined();

    expect(refreshToken).not.toHaveBeenCalled();
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  /**
   * @scenario request=anonymous
   * @effects anonymous_unauthorized_not_redirected
   */
  it('isAnonymousUnauthorized 는 401 이 아니거나 표지가 없으면 false 다', () => {
    const { client } = buildClient(onUnauthorized);
    expect(client.isAnonymousUnauthorized(null)).toBe(false);
    expect(client.isAnonymousUnauthorized(new Error('x'))).toBe(false);
    expect(client.isAnonymousUnauthorized({ response: { status: 403 }, g7Auth: { tokenSent: false } })).toBe(false);
    expect(client.isAnonymousUnauthorized({ response: { status: 401 } })).toBe(false);
  });
});
