/**
 * E2E: 언어 코드(BCP 47) — 꺼진 저장 언어로 부팅·형식 위반 언어 파일 요청 (engine-v1.81.0, #148)
 *
 * 1. 이 브라우저에 저장된 화면 언어(g7_locale)가 사이트에서 꺼진 언어면 종전에는 그 언어로 부팅해 화면이 영어로
 *    떨어졌다. 이제 주 언어가 같은 지원 언어(ko-KR → ko) → 사이트 기본 언어 순으로 연다. 저장값은 고쳐 쓰지 않는다
 *    — 언어팩을 잠깐 껐다 켜도 비회원의 선택이 남는다(설계 확정 2026-10-19).
 * 2. 다국어 파일 주소의 언어 코드 형식 위반(fil-ph·FIL·kor1·21자)과 설치되지 않은 언어(es-419)는 서버 오류(500)가
 *    아니라 404 다.
 *
 * 단위 테스트(TemplateApp.memberLocale 「stale_saved_locale_on_boot」·localeTag·PublicTemplateControllerInvalidLocale·
 * TemplateLanguageBcp47Serving)는 엔진·서버 한 경로를 잠그고, 이 spec 은 실제 부팅(서버 appConfig → 생성자 →
 * 첫 렌더)과 실제 라우트·컨트롤러를 거쳐 화면 언어와 응답 코드를 본다. 검수 언어팩이 없어도 재현된다
 * (꺼진 언어로 지원 목록 밖의 코드를 쓴다).
 */
import { test, expect, type Page } from '@playwright/test';

/**
 * 저장 언어를 심고 로그인 화면을 열어 엔진이 화면 언어를 정할 때까지 기다립니다.
 *
 * @param page Playwright 페이지
 * @param savedLocale 심을 g7_locale 값
 * @return 화면 언어·저장값·사이트 설정
 */
async function bootWithSavedLocale(page: Page, savedLocale: string): Promise<{ lang: string; stored: string | null; site: string | undefined; supported: string[] }> {
  await page.addInitScript((value) => {
    try { localStorage.setItem('g7_locale', value); } catch { /* storage 접근 불가 환경은 무시 */ }
  }, savedLocale);
  await page.goto('/login');
  await page.waitForFunction(() => typeof (window as any).__templateApp?.getLocale === 'function', undefined, { timeout: 60_000, polling: 250 });

  return page.evaluate(() => {
    const appConfig = (window as any).__templateApp?.globalState?.appConfig ?? (window as any).G7Config?.appConfig ?? {};
    let stored: string | null = null;
    try { stored = localStorage.getItem('g7_locale'); } catch { /* 무시 */ }

    return {
      lang: document.documentElement.lang,
      stored,
      site: appConfig.siteLocale,
      supported: Array.isArray(appConfig.supportedLocales) ? appConfig.supportedLocales : [],
    };
  });
}

test.describe('언어 코드(BCP 47) — 꺼진 저장 언어 부팅·형식 위반 언어 파일', () => {
  test.setTimeout(120_000);
  let pageErrors: string[];

  test.beforeEach(async ({ page }) => {
    pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
  });

  /**
   * @scenario case=stale_saved_locale_on_boot
   * @effects stale_saved_locale_switches_to_site_default, guest_keeps_browser_language
   */
  test('저장된 화면 언어가 꺼진 언어면 사이트 기본 언어로 열고 저장값은 그대로 둔다', async ({ page }) => {
    const state = await bootWithSavedLocale(page, 'tlh');

    expect(state.supported).not.toContain('tlh');
    expect(state.site && state.supported.includes(state.site)).toBe(true);
    expect(state.lang).toBe(state.site);
    expect(state.stored).toBe('tlh');
    expect(pageErrors).toEqual([]);
  });

  /**
   * @scenario case=stale_saved_locale_on_boot
   * @effects stale_saved_locale_switches_to_site_default
   */
  test('저장된 화면 언어의 지역만 다르면 주 언어가 같은 지원 언어로 연다', async ({ page }) => {
    const state = await bootWithSavedLocale(page, 'ko-KR');
    test.skip(!state.supported.includes('ko') || state.supported.includes('ko-KR'), '이 사이트는 ko 를 지원하지 않거나 ko-KR 을 직접 지원한다');

    expect(state.lang).toBe('ko');
    expect(state.stored).toBe('ko-KR');
    expect(pageErrors).toEqual([]);
  });

  /**
   * @scenario shape=malformed
   * @effects invalid_locale_maps_to_404
   */
  test('다국어 파일 주소의 형식 위반·미설치 언어 코드는 500 이 아니라 404 다', async ({ request }) => {
    const codes = ['fil-ph', 'FIL', 'kor1', 'fil_PH', 'fil-Latn-419-abcdefgh', 'es-419'];
    const statuses: Record<string, number> = {};
    for (const code of codes) {
      const response = await request.get(`/api/templates/sirsoft-basic/lang/${code}.json`, { headers: { Accept: 'application/json' } });
      statuses[code] = response.status();
    }

    expect(statuses).toEqual(Object.fromEntries(codes.map((code) => [code, 404])));
  });
});
