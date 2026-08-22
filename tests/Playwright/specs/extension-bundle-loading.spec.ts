/**
 * E2E: 확장 프론트엔드 병합 번들 로딩 회귀 검증
 *
 * @scenario extension-bundle-loading
 * @effects individual_iife_requests_absent_in_browser, bundle_url_same_origin_only,
 *          gdpr_interceptor_still_active_after_merge, extension_handlers_registered_no_console_error
 *
 * 배경: 활성 모듈/플러그인 IIFE 를 서버측에서 종류별 1개 번들로 병합 서빙한다.
 * 실제 페이지 로드 시 (1) 개별 `*.iife.js` 요청이 사라지고 `bundle.js` 로 대체되는지,
 * (2) gdpr preblocker 가 여전히 유효한지(병합 후 race 회귀 없음), (3) 확장 자가등록
 * (핸들러/레지스트리)이 정상 동작하는지 실측한다.
 */
import { test, expect } from '@playwright/test';

test.describe('확장 병합 번들 로딩', () => {
  test('@smoke 홈페이지 로드 시 개별 iife 대신 병합 번들이 요청된다', async ({ page }) => {
    const requestedUrls: string[] = [];
    page.on('request', (req) => {
      requestedUrls.push(req.url());
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const moduleBundle = requestedUrls.filter((u) => /\/api\/modules\/bundle[./]js/.test(u));
    const pluginBundle = requestedUrls.filter((u) => /\/api\/plugins\/bundle[./]js/.test(u));

    // 활성 확장이 있으면 번들이 요청되고, 종류별로 1건씩만 나가야 한다
    const individualIife = requestedUrls.filter((u) => /\/api\/(modules|plugins)\/assets\/.*\.iife\.js/.test(u));

    // 개별 iife 직접 요청은 0건 (번들로 대체)
    expect(individualIife, `개별 iife 요청이 남아있음: ${individualIife.join(', ')}`).toHaveLength(0);

    // 번들이 요청됐다면 종류별 최대 1건 (중복 가드)
    expect(moduleBundle.length, '모듈 번들 중복 요청').toBeLessThanOrEqual(1);
    expect(pluginBundle.length, '플러그인 번들 중복 요청').toBeLessThanOrEqual(1);
  });

  test('@smoke 병합 번들 응답이 정상(200/304)이고 same-origin 이다', async ({ page }) => {
    const bundleResponses: { url: string; status: number }[] = [];
    page.on('response', (res) => {
      if (/\/api\/(modules|plugins)\/bundle[./](js|css)/.test(res.url())) {
        bundleResponses.push({ url: res.url(), status: res.status() });
      }
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    for (const r of bundleResponses) {
      expect([200, 304], `번들 응답 비정상: ${r.url} → ${r.status}`).toContain(r.status);
      // same-origin (/api/...) — CDN/외부 origin 금지 (gdpr preblocker 자기차단 방지)
      const origin = new URL(page.url()).origin;
      expect(r.url.startsWith(origin), `번들 URL 이 same-origin 아님: ${r.url}`).toBe(true);
    }
  });

  test('@smoke 확장 병합 로드 후 페이지가 정상 렌더된다 (자가등록 계약)', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 홈 네비게이션이 렌더되면 템플릿 엔진 + 확장 자가등록이 정상 작동한 것
    await expect(page.getByTestId('nav-home')).toBeVisible({ timeout: 15_000 });

    // 번들 파싱 에러(ASI 경계 붕괴)가 있으면 "Unexpected token" 등 콘솔 에러가 뜬다
    const parseErrors = consoleErrors.filter((e) =>
      /Unexpected token|SyntaxError|is not defined|Unknown action handler/i.test(e),
    );
    expect(parseErrors, `번들 실행 관련 콘솔 에러: ${parseErrors.join(' | ')}`).toHaveLength(0);
  });

  /**
   * 정상 구성에서는 자산 실패 안내가 뜨지 않는다.
   *
   * 스타일이 비어 있는 확장(0바이트 CSS)만 설치된 기본 구성에서 번들 CSS 가 503 이 되어
   * 사용자 화면마다 안내 배너가 떴다. 서버가 그 상태를 정상 빈 200 으로 판정한 뒤로는
   * 배너가 없어야 한다.
   *
   * // @scenario ext_type=module, asset_kind=css, active_combo=one, file_state=present_empty
   *
   * @effects empty_result_with_present_empty_artifacts_returns_200
   */
  test('@smoke 정상 로드 시 자산 실패 안내가 없다', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await expect(page.locator('#g7-asset-failure-notice')).toHaveCount(0);

    const failures = await page.evaluate(
      () => (window as any).G7Core?.assets?.getFailures?.() ?? [],
    );
    expect(failures, `자산 실패가 남아있음: ${JSON.stringify(failures)}`).toHaveLength(0);
  });

  /**
   * 진짜 소실(503)일 때의 안내 항목명은 사용자 어휘여야 한다.
   *
   * 종전에는 번들 구분 키가 그대로 나와 "module을(를) 불러오지 못했습니다" 로 보였다.
   *
   * // @scenario ext_type=module, asset_kind=css, active_combo=one, file_state=one_missing
   *
   * @effects bundle_css_failure_banner_uses_user_vocabulary
   */
  test('번들 CSS 가 503 이면 안내 항목명이 사용자 어휘다', async ({ page }) => {
    await page.route(/\/api\/(modules|plugins)\/bundle[./]css/, (route) =>
      route.fulfill({ status: 503, contentType: 'text/css', body: '' }),
    );

    await page.goto('/');

    const notice = page.locator('#g7-asset-failure-notice');
    await expect(notice).toBeVisible({ timeout: 30_000 });

    const text = (await notice.innerText()).replace(/\s+/g, ' ');
    expect(text, `배너 문구: ${text}`).toContain('모듈 스타일');
    expect(text, `내부 구분 키가 노출됨: ${text}`).not.toContain('module을(를)');
  });
});
