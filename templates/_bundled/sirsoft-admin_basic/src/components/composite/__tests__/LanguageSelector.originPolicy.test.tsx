/**
 * LanguageSelector 언어 저장 요청 대상 출처 테스트 (KVE-2026-2350)
 *
 * `apiEndpoint` 는 레이아웃 prop 이라 편집 권한만 있는 계정이 표현식으로 외부 주소를 넣을 수 있다.
 * 관리자가 상단바에서 언어를 바꾸면 관리자 토큰이 그 주소로 갔다. 토큰은 동일 출처 대상에만 싣는다.
 *
 * 컴포넌트는 요청을 코어 `G7Core.api` 로 보내고 토큰 첨부를 코어에 맡긴다. 그래서 이 테스트는 실제 코어
 * ApiClient 를 `G7Core.api` 로 세우고, 전송 직전 요청(최종 주소·헤더)을 어댑터에서 가로채 판정한다.
 * XSRF 헤더는 axios 가 동일 출처 요청에만 붙이며(브라우저 어댑터 단계), 여기서는 Bearer 만 잰다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ApiClient from '@core/api/ApiClient';
import { LanguageSelector } from '../LanguageSelector';

interface SentRequest {
  method: string;
  url: string;
  origin: string;
  headers: Record<string, unknown>;
}

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
    sent.push({
      method: String(config.method).toUpperCase(),
      url: config.url,
      origin: new URL(full, window.location.href).origin,
      headers,
    });
    return { data: { success: true }, status: 200, statusText: 'OK', headers: {}, config, request: {} };
  };
  return client;
}

describe('LanguageSelector — 언어 저장 요청 대상 출처', () => {
  const g7 = (window as any).G7Core;
  const originalApi = g7.api;
  const originalLocale = g7.locale;
  let requests: SentRequest[];

  beforeEach(() => {
    requests = [];
    g7.api = createCapturingCoreApi(requests);
    g7.locale = { change: vi.fn().mockResolvedValue(undefined) };
    localStorage.setItem('auth_token', 'ADMIN-TOKEN');
    global.fetch = vi.fn().mockRejectedValue(new Error('raw fetch must not be used')) as unknown as typeof fetch;
  });

  afterEach(() => {
    g7.api = originalApi;
    g7.locale = originalLocale;
    localStorage.clear();
  });

  const chooseEnglish = async (apiEndpoint?: string) => {
    const user = userEvent.setup();
    const { unmount } = render(
      <LanguageSelector availableLocales={['ko', 'en']} {...(apiEndpoint ? { apiEndpoint } : {})} />
    );
    await user.click(screen.getByRole('button'));
    await user.click(screen.getByText('English'));
    unmount();
  };

  // @scenario sink=language_selector, target=cross_origin
  // @effects extension_token_same_origin_only
  it('교차 출처 apiEndpoint 에는 Bearer 를 싣지 않는다', async () => {
    for (const apiEndpoint of ['https://evil.example/lang', '//evil.example/lang']) {
      requests.length = 0;
      await chooseEnglish(apiEndpoint);

      expect(requests).toHaveLength(1);
      expect(new URL(requests[0].origin).hostname).toBe('evil.example');
      expect(requests[0].headers.Authorization).toBeUndefined();
    }
    expect(global.fetch).not.toHaveBeenCalled();
  });

  // @scenario sink=language_selector, target=cross_origin
  // @effects extension_token_same_origin_only
  it('백슬래시 변형(/\\host)은 외부로 나가지 않고 자기 서버 /api 아래로 간다', async () => {
    await chooseEnglish('/\\evil.example/lang');

    expect(requests).toHaveLength(1);
    expect(requests[0].origin).toBe(window.location.origin);
  });

  // @scenario sink=language_selector, target=same_origin
  // @effects extension_token_same_origin_only
  it('동일 출처 apiEndpoint 는 종전대로 Bearer 를 싣는다 (정상 회귀)', async () => {
    await chooseEnglish();

    expect(requests).toHaveLength(1);
    expect(requests[0].method).toBe('PATCH');
    expect(requests[0].url).toBe('/api/admin/users/me/language');
    expect(requests[0].origin).toBe(window.location.origin);
    expect(requests[0].headers.Authorization).toBe('Bearer ADMIN-TOKEN');
  });
});
