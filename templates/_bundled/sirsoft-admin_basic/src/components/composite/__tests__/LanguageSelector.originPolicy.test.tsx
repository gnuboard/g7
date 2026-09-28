/**
 * LanguageSelector 언어 저장 요청 대상 출처 테스트 (KVE-2026-2350)
 *
 * `apiEndpoint` 는 레이아웃 prop 이라 편집 권한만 있는 계정이 표현식으로 외부 주소를 넣을 수 있다.
 * 관리자가 상단바에서 언어를 바꾸면 관리자 토큰이 그 주소로 갔다. 토큰은 동일 출처 대상에만 싣는다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LanguageSelector } from '../LanguageSelector';

describe('LanguageSelector — 언어 저장 요청 대상 출처', () => {
  const originalApi = (window as any).G7Core?.api;
  let requests: Array<{ url: string; headers: Record<string, string> }>;

  beforeEach(() => {
    (window as any).__templateApp = { changeLocale: vi.fn().mockResolvedValue(undefined) };
    // 코어 판정이 없는 환경(폴백 경로)으로 고정한다
    (window as any).G7Core.api = {};
    localStorage.setItem('auth_token', 'ADMIN-TOKEN');
    document.cookie = 'XSRF-TOKEN=xsrf-value; path=/';
    requests = [];
    global.fetch = vi.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
      requests.push({ url: String(url), headers: (init?.headers ?? {}) as Record<string, string> });
      return { ok: true, json: async () => ({ success: true }) } as Response;
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    delete (window as any).__templateApp;
    (window as any).G7Core.api = originalApi;
    localStorage.clear();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
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

  /**
   * @effects extension_token_same_origin_only
   */
  it('교차 출처 apiEndpoint 에는 Bearer·XSRF 를 싣지 않는다', async () => {
    // @scenario sink=language_selector, target=cross_origin
    for (const apiEndpoint of ['https://evil.example/lang', '//evil.example/lang', '/\\evil.example/lang']) {
      requests = [];
      await chooseEnglish(apiEndpoint);

      expect(requests).toHaveLength(1);
      expect(requests[0].url).toBe(apiEndpoint);
      expect(requests[0].headers.Authorization).toBeUndefined();
      expect(requests[0].headers['X-XSRF-TOKEN']).toBeUndefined();
    }
  });

  /**
   * @effects extension_token_same_origin_only
   */
  it('동일 출처 apiEndpoint 는 종전대로 Bearer·XSRF 를 싣는다 (정상 회귀)', async () => {
    // @scenario sink=language_selector, target=same_origin
    await chooseEnglish();

    expect(requests[0].url).toBe('/api/admin/users/me/language');
    expect(requests[0].headers.Authorization).toBe('Bearer ADMIN-TOKEN');
    expect(requests[0].headers['X-XSRF-TOKEN']).toBe('xsrf-value');
  });
});
