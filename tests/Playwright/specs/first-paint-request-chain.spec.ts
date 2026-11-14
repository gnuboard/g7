/**
 * E2E: 쇼핑몰 첫 방문 요청 사슬 (#879, engine-v1.95.0)
 *
 * 비로그인 첫 방문이 첫 글자까지 PHP 왕복을 줄줄이 기다리던 것을 실제 브라우저에서 잰다.
 *  - 템플릿 설정은 셸 HTML(`#g7-config` 의 `templateConfig`, template.json `expose` 선언 키만)에서 읽고 config API 를 부르지 않는다.
 *  - 레이아웃 요청은 확장 번들 로드가 끝나기 전에 시작하고, 장바구니 키 발급 요청은 레이아웃 요청과 겹친다(POST 1회).
 *  - 기본 글꼴은 글자 묶음 subset 만 받는다(단일 2MB 파일 없음).
 *  - 셸에 templateConfig 가 없으면(옛 셸) config API 를 1회 불러 그대로 그린다.
 *
 * 시나리오 매니페스트: tests/scenarios/engine-first-paint-request-chain.yaml
 */
import { test, expect, type Page, type Request } from '@playwright/test';

/** 요청 시작·응답 끝 시각을 모은다(페이지 기준 ms) */
function trackRequests(page: Page): Array<{ url: string; method: string; start: number; end: number | null }> {
  const t0 = Date.now();
  const rows: Array<{ url: string; method: string; start: number; end: number | null; req: Request }> = [];
  page.on('request', (req) => rows.push({ url: req.url(), method: req.method(), start: Date.now() - t0, end: null, req }));
  page.on('requestfinished', (req) => {
    const row = rows.find((r) => r.req === req);
    if (row) row.end = Date.now() - t0;
  });

  return rows;
}

test.describe('쇼핑몰 첫 방문 요청 사슬 (#879)', () => {
  // @scenario boot_case=inline_complete
  // @scenario boot_case=prefetch_public_route
  // @effects first_paint_skips_template_config_roundtrip, initial_layout_requested_before_extension_bundles
  test('비로그인 첫 방문 — config 요청 0, 레이아웃 요청이 플러그인 번들 응답 전에 시작, 장바구니 키 POST 1회, 글꼴 subset', async ({ page }) => {
    const rows = trackRequests(page);

    await page.goto('/shop/products');
    await page.waitForLoadState('networkidle', { timeout: 60_000 });

    const config = rows.filter((r) => /\/api\/templates\/[^/]+\/config/.test(r.url));
    expect(config, `config 요청이 남았다: ${config.map((r) => r.url).join(', ')}`).toHaveLength(0);

    const templateConfigKeys = await page.evaluate(() => Object.keys(JSON.parse(document.getElementById('g7-config')!.textContent || '{}').templateConfig || {}));
    expect(templateConfigKeys).toEqual(['scroll_restoration', 'errorHandling', 'error_config']);

    const layout = rows.find((r) => /\/api\/layouts\/[^/]+\/shop\/index/.test(r.url));
    const pluginsBundle = rows.find((r) => /\/plugins(\/bundle)?[./]js|bundles\/plugins\.js/.test(r.url));
    expect(layout, '레이아웃 요청이 없다').toBeTruthy();
    if (pluginsBundle?.end != null) {
      expect(layout!.start, '레이아웃 요청이 플러그인 번들 응답 뒤에 시작했다').toBeLessThan(pluginsBundle.end);
    }

    const cartKey = rows.filter((r) => /cart\/key/.test(r.url) && r.method === 'POST');
    expect(cartKey).toHaveLength(1);
    if (cartKey[0].end != null && layout?.end != null) {
      // 장바구니 키 요청이 레이아웃 응답을 기다리지 않는다
      expect(cartKey[0].start).toBeLessThan(layout.end);
    }

    const fonts = rows.filter((r) => /\.woff2(\?|$)/.test(r.url));
    expect(fonts.some((r) => /PretendardVariable\.woff2/.test(r.url)), '단일 가변 글꼴을 받았다').toBe(false);
    expect(fonts.length).toBeGreaterThan(0);
    expect(await page.evaluate(() => document.fonts.check('16px "Pretendard Variable"'))).toBe(true);
  });

  // @scenario boot_case=inline_absent
  // @effects template_config_fallback_preserves_values
  test('셸에 templateConfig 가 없으면(옛 셸) config API 를 1회 부르고 화면을 그린다', async ({ page }) => {
    await page.addInitScript(() => {
      document.addEventListener('DOMContentLoaded', () => {
        const cfg = (window as unknown as { G7Config?: Record<string, unknown> }).G7Config;
        if (cfg) delete cfg.templateConfig;
      }, { capture: true });
    });
    const rows = trackRequests(page);
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.goto('/shop/products');
    await page.waitForLoadState('networkidle', { timeout: 60_000 });

    expect(rows.filter((r) => /\/api\/templates\/[^/]+\/config/.test(r.url))).toHaveLength(1);
    await expect(page.locator('h1').first()).toBeVisible();
    expect(errors).toEqual([]);
  });
});
