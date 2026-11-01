/**
 * Smoke: strict CSP(`script-src 'self'`, unsafe-inline·unsafe-eval 없음)에서 셸이 동작한다 (#158)
 *
 * 운영자가 웹서버에 `Content-Security-Policy: script-src 'self'` 를 걸면 인라인 `<script>` 와
 * on* 속성이 실행되지 않는다. 종전에는 G7Config 대입·부트스트랩·번들 `<script onload>` 가
 * 인라인이라 관리자·사용자 화면 전체가 백지였고 폴백 안내조차 뜨지 않았다.
 *
 * 서버 응답을 바꾸지 않고 **문서 응답에만** CSP 헤더를 덧붙여 "운영자 웹서버 헤더 한 줄"을
 * 재현한다. 위반은 `securitypolicyviolation` 이벤트로 센다.
 *
 * 검증 축:
 *   (A) 관리자·사용자 진입 화면 렌더 + 위반 0
 *   (B) 컴포넌트 번들 상시 실패 → 폴백 안내 + 새로고침 버튼(리스너)으로 새 문서 로드
 *   (C) 셸 JS 자체 미도착 → 20초 뒤 CSS 지연 안내문(#g7-shell-stall) 표시
 *
 * 소유 매니페스트: tests/scenarios/strict-csp-compat.yaml (조합은 테스트별 마킹)
 * @effects shell_html_has_no_inline_executable, shell_init_on_captured_load, shell_stall_notice_rendered
 */
import { test, expect, type Page } from '@playwright/test';

/** 운영자 웹서버 상당 strict 정책 (S9 — Monaco·이미지 압축 blob 워커 허용) */
const STRICT_POLICY = "script-src 'self' 'report-sample'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'";

/** 코어/컴포넌트 번들이 끝내 부재할 때 사용자에게 보여야 할 폴백 안내 */
const FALLBACK_UI = /화면을 불러오지 못했습니다|Failed to load the page/;

/**
 * 문서 응답에 strict CSP 헤더를 덧붙이고 위반 수집기를 심는다.
 *
 * @param page Playwright page
 * @return {Promise<void>}
 */
async function injectStrictCsp(page: Page): Promise<void> {
  await page.route('**/*', async (route) => {
    if (route.request().resourceType() !== 'document') {
      return route.fallback();
    }
    const response = await route.fetch();
    await route.fulfill({
      response,
      headers: { ...response.headers(), 'content-security-policy': STRICT_POLICY },
    });
  });

  await page.addInitScript(() => {
    (window as any).__cspv = [];
    document.addEventListener('securitypolicyviolation', (e) => {
      (window as any).__cspv.push({ d: e.effectiveDirective, b: e.blockedURI, s: (e.sample || '').slice(0, 60) });
    });
  });
}

/**
 * 위반 목록을 읽는다.
 *
 * @param page Playwright page
 * @return 위반 목록
 */
async function violations(page: Page): Promise<Array<{ d: string; b: string; s: string }>> {
  return page.evaluate(() => (window as any).__cspv ?? []);
}

test.describe('strict CSP 셸 호환 (#158)', () => {
  for (const url of ['/admin/login', '/', '/login']) {
    /**
     * @scenario surface=admin_shell, csp=webserver_strict, failure=none
     * @effects shell_html_has_no_inline_executable, shell_init_on_captured_load
     */
    test(`(A) ${url} 가 strict CSP 에서 렌더되고 위반이 없다`, async ({ page }) => {
      await injectStrictCsp(page);

      const response = await page.goto(url);
      expect(response?.headers()['content-security-policy']).toBe(STRICT_POLICY);

      await page.waitForFunction(() => (window as any).__g7Bootstrap?.initialized === true, null, { timeout: 30_000 });
      await expect(page.locator('#app input').nth(1)).toBeVisible({ timeout: 30_000 });

      const state = await page.evaluate(() => ({
        config: typeof (window as any).G7Config,
        shellOk: document.documentElement.classList.contains('g7-shell-ok'),
        inputs: document.querySelectorAll('#app input').length,
      }));

      expect(state.config).toBe('object');
      expect(state.shellOk).toBe(true);
      expect(state.inputs).toBeGreaterThanOrEqual(2);
      expect(await violations(page)).toEqual([]);
    });
  }

  /**
   * @scenario surface=bootstrap_fallback, csp=webserver_strict, failure=components_bundle_fail
   * @effects shell_retry_backoff_then_fallback
   */
  test('(B) 컴포넌트 번들 상시 실패 → 폴백 안내, 새로고침 버튼이 새 문서를 연다', async ({ page }) => {
    await injectStrictCsp(page);

    let aborted = 0;
    await page.route(/components\.iife(\.js)?(\?|$)|file=js%2Fcomponents\.iife\.js/, (route) => {
      aborted += 1;
      return route.abort('connectionreset');
    });

    await page.goto('/login');
    await expect(page.locator('#app [data-g7-bootstrap-fallback]')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('#app')).toContainText(FALLBACK_UI);
    expect(aborted).toBeGreaterThanOrEqual(3);

    const reload = page.locator('#app [data-g7-bootstrap-reload]');
    await expect(reload).toBeVisible();
    expect(await reload.getAttribute('onclick')).toBeNull();

    const navigation = page.waitForEvent('framenavigated');
    await reload.click();
    await navigation;

    expect(await violations(page)).toEqual([]);
  });

  /**
   * @scenario surface=user_shell, csp=webserver_strict, failure=shell_js_fail
   * @effects shell_stall_notice_rendered
   */
  test('(C) 셸 JS 를 못 받으면 20초 뒤 지연 안내문이 보인다', async ({ page }) => {
    test.setTimeout(60_000);
    await injectStrictCsp(page);
    await page.route(/g7-shell-boot\.js/, (route) => route.abort('connectionreset'));

    await page.goto('/login');

    const stall = page.locator('#g7-shell-stall');
    await expect(stall).toBeHidden();
    await expect(stall).toBeVisible({ timeout: 30_000 });
    await expect(stall).toContainText(FALLBACK_UI);
  });
});
