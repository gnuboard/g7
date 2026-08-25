/**
 * Smoke: stale 캐시버전 재방문의 부트 이중 로드 제거 (#122 작업 A·B)
 *
 * localStorage `g7_cache_version` 이 stale 이면 첫 burst 가 구버전 `?v` 로 나가고,
 * config 핸드셰이크가 routes 재로드 + lang(ko·en, `_=` 버스터) 재다운로드를 유발해
 * ~500KB 중복 / 부트 ~1.3s 연장이 발생했다. blade 주입 cache_version 시드가
 * 이를 제거했음을 실브라우저에서 잠근다.
 *
 * @scenario publish_state=published, environment=production, trigger=lifecycle, process_user=web
 * @effects no_duplicate_boot_requests, versioned_boot_urls
 */
import { test, expect, type Page } from '@playwright/test';

/**
 * 부트 리소스 URL 판별 — 정적 게시 경로와 API 경로(이중 모드 포함)를 모두 흡수한다.
 *
 * 확장자 형태만 매칭하면 정적 게시가 켜진 사이트에서 카운터가 0 이 되어
 * 테스트가 조용히 무의미해진다 (network-resilience.spec.ts 의 suffixedPath 확장).
 */
function bootResourceMatchers(pathname: string): { routes: boolean; lang: string | null; components: boolean } {
  const routes =
    /^\/build\/ext\/\d+\/templates\/[^/]+\/routes\.json$/.test(pathname) ||
    /^\/api\/templates\/[^/]+\/routes(\.json)?$/.test(pathname);

  const staticLang = pathname.match(/^\/build\/ext\/\d+\/templates\/[^/]+\/lang\/([a-z-]+)\.json$/);
  const apiLang = pathname.match(/^\/api\/templates\/[^/]+\/lang\/([a-z-]+)(\.json)?$/);
  const lang = staticLang?.[1] ?? apiLang?.[1] ?? null;

  const components =
    /^\/build\/ext\/\d+\/templates\/[^/]+\/components\.json$/.test(pathname) ||
    /^\/api\/templates\/[^/]+\/components(\.json)?$/.test(pathname);

  return { routes, lang, components };
}

/** 페이지의 부트 리소스 요청을 수집한다 */
function collectBootRequests(page: Page) {
  const routesUrls: string[] = [];
  const langUrls = new Map<string, string[]>();
  const componentUrls: string[] = [];
  const busterUrls: string[] = [];

  page.on('request', (request) => {
    const url = new URL(request.url());
    const { routes, lang, components } = bootResourceMatchers(url.pathname);

    if (routes) routesUrls.push(request.url());
    if (lang) langUrls.set(lang, [...(langUrls.get(lang) ?? []), request.url()]);
    if (components) componentUrls.push(request.url());
    if (/[?&]_=\d+/.test(url.search)) busterUrls.push(request.url());
  });

  return { routesUrls, langUrls, componentUrls, busterUrls };
}

test.describe('부트 이중 로드 제거 (#122)', () => {
  test('@smoke stale localStorage 재방문 — routes 1회·lang 로케일당 1회·`_=` 0건·버전드 URL', async ({ page }) => {
    // stale 캐시버전 시드 (재방문자 시뮬레이션 — 종전엔 이중 로드 트리거)
    await page.addInitScript(() => {
      try {
        window.localStorage.setItem('g7_cache_version', '1');
      } catch {
        /* localStorage 불가 환경은 시드 없이 진행 */
      }
    });

    const collected = collectBootRequests(page);

    await page.goto('/');
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 30_000 }
    );
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // routes 는 정확히 1회 (핸드셰이크 재로드 부재)
    expect(collected.routesUrls, `routes 요청: ${collected.routesUrls.join(', ')}`).toHaveLength(1);

    // lang 은 로케일당 1회
    for (const [locale, urls] of collected.langUrls) {
      expect(urls, `lang/${locale} 요청: ${urls.join(', ')}`).toHaveLength(1);
    }

    // `_=` 캐시 버스터 재로드 0건
    expect(collected.busterUrls, `버스터 요청: ${collected.busterUrls.join(', ')}`).toHaveLength(0);

    // 부트 리소스 URL 은 버전 기반 (정적 경로 `/build/ext/{v}/` 또는 `?v=`)
    const bootUrls = [...collected.routesUrls, ...collected.componentUrls];
    for (const url of bootUrls) {
      expect(
        /\/build\/ext\/\d+\//.test(url) || /[?&]v=\d+/.test(url),
        `버전 없는 부트 URL: ${url}`
      ).toBe(true);
    }

    // localStorage 는 서버 버전으로 치유된다
    const healed = await page.evaluate(() => ({
      stored: window.localStorage.getItem('g7_cache_version'),
      injected: (window as any).G7Config?.cache_version,
    }));
    expect(healed.stored).toBe(String(healed.injected));
  });

  test('@smoke 치유 후 재로드 — 요청 구성이 동일하고 이중 로드가 없다', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const collected = collectBootRequests(page);

    await page.reload();
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 30_000 }
    );
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    expect(collected.routesUrls.length).toBeLessThanOrEqual(1);
    expect(collected.busterUrls).toHaveLength(0);
  });
});
