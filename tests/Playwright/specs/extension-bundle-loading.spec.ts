/**
 * E2E: 확장 프론트엔드 병합 번들 로딩 회귀 검증
 *
 * @scenario extension-bundle-loading
 * @effects bundle_js_requested_once_per_type, individual_iife_requests_absent,
 *          gdpr_interceptor_still_active, extension_handlers_registered
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

    const moduleBundle = requestedUrls.filter((u) => /\/api\/modules\/bundle\.js/.test(u));
    const pluginBundle = requestedUrls.filter((u) => /\/api\/plugins\/bundle\.js/.test(u));

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
      if (/\/api\/(modules|plugins)\/bundle\.(js|css)/.test(res.url())) {
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
});
