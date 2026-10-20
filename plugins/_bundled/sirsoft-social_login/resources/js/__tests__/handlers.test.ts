/**
 * @file handlers.test.ts
 * @description 로그인 회원 왕복 시작 핸들러 — 코어 API 클라이언트로 시작하고, 같은 출처·https 주소로만 이동한다.
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { safeProviderUrl, startLinkHandler, startReauthHandler } from '../handlers/providerRedirect';

describe('providerRedirect 핸들러', () => {
  let post: ReturnType<typeof vi.fn>;
  let dispatch: ReturnType<typeof vi.fn>;
  let assign: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    post = vi.fn();
    dispatch = vi.fn();
    assign = vi.fn();
    (window as any).G7Core = { api: { post }, dispatch, t: (key: string) => key };
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, origin: 'https://site.test', pathname: '/mypage/profile', assign },
    });
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario url=same_origin|https|http_external|javascript
   * @effects only_same_origin_or_https_followed
   */
  it('같은 출처와 https 주소만 따라가고 실행 스킴·http 바깥 주소는 막는다', () => {
    expect(safeProviderUrl('/plugins/sirsoft-social_login/mock/authorize?x=1')).toBe('https://site.test/plugins/sirsoft-social_login/mock/authorize?x=1');
    expect(safeProviderUrl('https://kauth.kakao.com/oauth/authorize?state=s')).toBe('https://kauth.kakao.com/oauth/authorize?state=s');
    expect(safeProviderUrl('http://evil.example/x')).toBeNull();
    expect(safeProviderUrl('javascript:alert(1)')).toBeNull();
    expect(safeProviderUrl('')).toBeNull();
  });

  /**
   * @scenario handler=startLink, response=ok
   * @effects start_link_posts_and_redirects
   */
  it('연결 시작은 코어 API 로 시작 주소를 받아 그리로 이동한다', async () => {
    post.mockResolvedValue({ data: { authorize_url: 'https://nid.naver.com/oauth2.0/authorize?state=abc' } });

    await expect(startLinkHandler({ params: { provider: 'naver' } })).resolves.toBe(true);

    expect(post).toHaveBeenCalledWith('/plugins/sirsoft-social_login/me/accounts/naver/link-start', undefined);
    expect(assign).toHaveBeenCalledWith('https://nid.naver.com/oauth2.0/authorize?state=abc');
  });

  /**
   * @scenario handler=startLink, client=class_instance
   * @effects api_client_called_with_its_receiver
   */
  it('코어 API 클라이언트의 post 를 수신자(this)와 함께 부른다 — 떼어 부르면 클라이언트 내부가 깨진다', async () => {
    class Client {
      private base = '/api';
      async post(url: string) {
        return { data: { authorize_url: `https://kauth.kakao.com${this.base}${url}` } };
      }
    }
    (window as any).G7Core.api = new Client();

    await expect(startLinkHandler({ params: { provider: 'kakao' } })).resolves.toBe(true);
    expect(assign).toHaveBeenCalledWith('https://kauth.kakao.com/api/plugins/sirsoft-social_login/me/accounts/kakao/link-start');
  });

  /**
   * @scenario handler=startReauth, response=ok
   * @effects start_reauth_sends_return_path
   */
  it('재확인 시작은 돌아올 경로를 함께 보낸다', async () => {
    post.mockResolvedValue({ data: { authorize_url: '/plugins/sirsoft-social_login/mock/authorize?state=x' } });

    await startReauthHandler({ params: { provider: 'mock_kakao', return: '/mypage/profile-edit' } });

    expect(post).toHaveBeenCalledWith('/plugins/sirsoft-social_login/me/reauth/mock_kakao/start', { return: '/mypage/profile-edit' });
    expect(assign).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario handler=startLink, provider=invalid, response=409
   * @effects invalid_provider_ignored, failure_shows_server_message
   */
  it('형식이 틀린 제공자는 요청하지 않고, 실패하면 서버 문구로 토스트를 띄운다', async () => {
    await expect(startLinkHandler({ params: { provider: '../admin' } })).resolves.toBe(false);
    expect(post).not.toHaveBeenCalled();

    post.mockRejectedValue({ response: { data: { message: '이미 연결된 제공자입니다.' } } });
    await expect(startLinkHandler({ params: { provider: 'kakao' } })).resolves.toBe(false);

    expect(dispatch).toHaveBeenCalledWith({ handler: 'toast', params: { type: 'error', message: '이미 연결된 제공자입니다.' } });
    expect(assign).not.toHaveBeenCalled();
  });
});
