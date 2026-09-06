/**
 * Smoke: 정적 게시(bake) fast path + API 폴백 (#122 S2·S3)
 *
 * 정적 게시가 켜진 프로덕션 사이트에서 ① 부트 리소스가 `/build/ext/{v}/…` 로
 * 수신되고 ② 정적 응답을 브라우저단에서 404 로 강제해도 legacy API 폴백으로
 * 화면이 정상 렌더됨을 잠근다. 서버 파일 조작이 없으므로 원복이 불필요하다.
 *
 * env 가드 — 대상 사이트가 정적 게시 미적용(비프로덕션/kill-switch/미게시)이면
 * staticBase 미주입으로 skip 된다 (첫 방문이 자가 치유를 예약하므로 워밍 1회 수행).
 *
 * @scenario publish_state=partial, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=self_heal, process_user=web
 * @effects static_first_fetch_falls_back_to_api_on_miss, fallback_is_observable_via_console_warn
 */
import { test, expect, type Page } from '@playwright/test';

/** 대상 사이트의 staticBase 주입 여부 (자가 치유 워밍 포함) */
async function probeStaticBase(page: Page): Promise<string | null> {
  await page.goto('/');
  await page.waitForLoadState('networkidle', { timeout: 30_000 });

  let base = await page.evaluate(() => (window as any).G7Config?.staticBase ?? null);

  if (!base) {
    // 미게시 상태였다면 방금 렌더가 terminating 게시를 예약했다 — 1회 재방문
    await page.waitForTimeout(3_000);
    await page.reload();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    base = await page.evaluate(() => (window as any).G7Config?.staticBase ?? null);
  }

  return typeof base === 'string' ? base : null;
}

test.describe('정적 게시 fast path + 폴백 (#122)', () => {
  /**
   * @scenario publish_state=published, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=self_heal, process_user=web
   * @effects versioned_boot_urls
   */
  test('@smoke 정적 URL 로 부트 리소스를 수신한다 (static-first)', async ({ page }) => {
    const staticUrls: string[] = [];
    page.on('request', (request) => {
      if (new URL(request.url()).pathname.startsWith('/build/ext/')) {
        staticUrls.push(request.url());
      }
    });

    const base = await probeStaticBase(page);
    test.skip(base === null, '대상 사이트에 정적 게시 미적용 (staticBase 미주입)');

    await page.reload();
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 30_000 }
    );
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 정적 게시 경로에서 부트 리소스를 실제로 받는다
    expect(staticUrls.length, `정적 경로 수신: ${staticUrls.join(', ')}`).toBeGreaterThan(0);
    expect(staticUrls.some((u) => /\/templates\/[^/]+\/routes\.json/.test(u))).toBe(true);
  });

  test('@smoke 정적 응답을 404 로 강제해도 legacy API 폴백으로 화면이 정상 렌더된다', async ({ page }) => {
    const base = await probeStaticBase(page);
    test.skip(base === null, '대상 사이트에 정적 게시 미적용 (staticBase 미주입)');

    const warns: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'warning') warns.push(message.text());
    });

    let aborted = 0;
    const apiFallbacks: string[] = [];

    // 정적 fetch 리소스(routes/lang/components)만 404 강제 — 태그 자산(CSS/JS)은
    // 파일 게이트가 이미 통과시킨 실파일이므로 건드리지 않는다 (렌더 자체를 위한 것)
    await page.route(
      (url) => /\/build\/ext\/\d+\/templates\/[^/]+\/(routes|components|lang\/[a-z-]+)\.json$/.test(url.pathname),
      (route) => {
        aborted += 1;
        return route.fulfill({ status: 404, body: 'forced miss' });
      }
    );

    page.on('request', (request) => {
      const path = new URL(request.url()).pathname;
      if (/^\/api\/templates\/[^/]+\/(routes|components|lang\/[a-z-]+)(\.json)?$/.test(path)) {
        apiFallbacks.push(request.url());
      }
    });

    await page.reload();
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 30_000 }
    );
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 정적 미스가 실제로 발생했고
    expect(aborted).toBeGreaterThan(0);

    // legacy API 폴백이 그 자리를 메웠으며
    expect(apiFallbacks.length, `API 폴백: ${apiFallbacks.join(', ')}`).toBeGreaterThan(0);

    // 화면은 전면 에러 없이 정상이다
    const text = await page.evaluate(() => document.body.innerText.trim());
    expect(text).not.toMatch(/초기화 실패|페이지 로딩 실패/);
    expect(text.length).toBeGreaterThan(20);

    // 폴백은 조용하지 않다 — console warn 으로 관측 가능
    expect(warns.some((w) => w.includes('fetchStaticFirst')), `warns: ${warns.join(' | ')}`).toBe(true);
  });

  /**
   * 정적 응답이 **200 인데 본문이 손상**된 경우에도 폴백해 화면이 정상이어야 한다.
   *
   * 디스크 풀/quota 로 절단된 게시본은 웹서버가 정상 200 으로 서빙한다. `response.ok`
   * 만 보던 종전 구현은 폴백하지 못한 채 `response.json()` 이 던져 부팅 전체가 실패했다 —
   * 3층 폴백이 유일하게 개입하지 못하던 경로다.
   *
   * @scenario publish_state=partial, artifact_integrity=absent, filesystem_writable=writable, environment=production, trigger=self_heal, process_user=web
   * @effects truncated_artifact_rejected_before_manifest, static_first_fetch_falls_back_to_api_on_miss
   */
  test('@smoke 정적 200 + 손상 본문이어도 폴백으로 화면이 정상 렌더된다', async ({ page }) => {
    const base = await probeStaticBase(page);
    test.skip(base === null, '대상 사이트에 정적 게시 미적용 (staticBase 미주입)');

    const warns: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'warning') warns.push(message.text());
    });

    let corrupted = 0;
    const apiFallbacks: string[] = [];

    // 404 가 아니라 **200 + 잘린 JSON** 을 돌려준다 — 종전 구현이 통과시키던 형태.
    await page.route(
      (url) => /\/build\/ext\/\d+\/templates\/[^/]+\/(routes|components|lang\/[a-z-]+)\.json$/.test(url.pathname),
      (route) => {
        corrupted += 1;
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: '{"success":true,"data":{"messa',
        });
      }
    );

    page.on('request', (request) => {
      const path = new URL(request.url()).pathname;
      if (/^\/api\/templates\/[^/]+\/(routes|components|lang\/[a-z-]+)(\.json)?$/.test(path)) {
        apiFallbacks.push(request.url());
      }
    });

    await page.reload();
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 30_000 }
    );
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    expect(corrupted).toBeGreaterThan(0);
    expect(apiFallbacks.length, `API 폴백: ${apiFallbacks.join(', ')}`).toBeGreaterThan(0);

    const text = await page.evaluate(() => document.body.innerText.trim());
    expect(text).not.toMatch(/초기화 실패|페이지 로딩 실패/);
    expect(text.length).toBeGreaterThan(20);

    expect(
      warns.some((w) => w.includes('malformed JSON')),
      `warns: ${warns.join(' | ')}`
    ).toBe(true);
  });

  /**
   * 정적 미스는 SPA 셸 HTML 이 아니라 404 를 받아야 한다 — 확장자 변종 포함.
   *
   * `mjs` · `webp` · `otf` 는 에셋 서빙이 허용하는데 catch-all 제외 목록에는 없었다.
   * 없는 `.mjs` 가 `Content-Type: text/html` 인 200 을 받으면 브라우저는 그것을
   * 스크립트로 파싱하다 죽는데, 응답이 성공이라 `onerror` 도 발화하지 않는다.
   *
   * @effects static_miss_returns_404_for_every_servable_extension
   */
  test('@smoke 없는 정적 자산은 확장자 변종에서도 404 다 (HTML 200 아님)', async ({ page }) => {
    const base = await probeStaticBase(page);
    test.skip(base === null, '대상 사이트에 정적 게시 미적용 (staticBase 미주입)');

    for (const extension of ['mjs', 'webp', 'otf', 'js', 'css', 'json']) {
      const response = await page.request.get(
        `${base}/templates/sirsoft-basic/assets/__missing__.${extension}`
      );

      const contentType = response.headers()['content-type'] ?? '';

      // 계약은 **상태 코드**다. 404 응답의 본문이 HTML 인 것은 정상이며(라라벨 오류 페이지),
      // 문제는 "없는 자산에 200 이 나가는 것" 이었다 — 200 이면 브라우저가 그 HTML 을
      // 스크립트로 파싱하다 죽고, 응답이 성공이라 onerror 도 발화하지 않는다.
      expect(
        response.status(),
        `.${extension} 가 ${response.status()} (content-type: ${contentType}) — SPA 셸 200 이 반환되면 스크립트 파싱이 죽는다`
      ).toBe(404);
    }
  });
});
