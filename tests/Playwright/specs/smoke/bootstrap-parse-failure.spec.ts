/**
 * Smoke: 부팅 실패 사유별 안내 분기 (공개 #121)
 *
 * 번들을 **받았는데 실행되지 않는** 경우와 **끝내 받지 못한** 경우는 사용자가 취해야 할
 * 행동이 정반대다. 전자는 새로고침해도 영원히 낫지 않으므로 "네트워크가 불안정하다 /
 * 새로고침하라" 는 거짓 진단이고, 사용자는 자기 회선을 탓하게 된다.
 *
 * 기존 `network-resilience.spec.ts` 는 `route.abort()` 로 **네트워크 실패만** 모사한다.
 * "다운로드는 되지만 파싱에 실패" 경로를 덮는 spec 이 없었던 것이 이번 결함(iOS 15 전
 * 기기 부팅 불능)이 통과한 이유다.
 *
 * 파싱 실패 모사: 응답을 가로채 **파싱 불가한 정규식 리터럴**을 본문으로 돌려준다.
 * 구형 Safari 가 lookbehind 를 거부하던 것과 같은 종류의 실패다 — 스크립트 태그는
 * `error` 가 아니라 `load` 를 발생시키므로 재시도조차 일어나지 않고 곧장 tryInit 으로 간다.
 *
 * 진입점 두 곳(사용자·관리자)을 모두 잰다. 한쪽만 보면 blade 계층이 갈린 지점의
 * 회귀를 놓친다.
 */
import { test, expect, type Page } from '@playwright/test';

/** 코어 엔진 번들 URL 패턴 */
const CORE_BUNDLE = '**/build/core/template-engine.min.js*';

/**
 * 파싱 불가한 스크립트 본문.
 *
 * `(?<@x)` 는 유효하지 않은 캡처 그룹 이름이라 정규식 **리터럴** 검증 단계에서 거부된다
 * (구형 WebKit 이 lookbehind 를 거부하던 지점과 동일). 파일 전체가 실행되지 않으므로
 * 앞줄의 대입조차 수행되지 않고 `window.G7Core` 는 정의되지 않는다.
 */
const UNPARSEABLE_JS = 'window.__g7ParseProbe = 1;\nvar broken = /(?<@x)y/;\n';

/**
 * 폴백 안내가 그려질 때까지 기다린 뒤 그 상태를 읽는다.
 *
 * 부재 단언은 **존재를 확정한 뒤**에만 의미가 있다 — 폴백 컨테이너가 뜨기 전에
 * 버튼 수를 세면 렌더 전이라 0 이 나와 그냥 통과한다.
 *
 * @param page Playwright page
 * @return 폴백 마커·문구·버튼 수
 */
async function readFallback(page: Page): Promise<{ reason: string | null; title: string; buttons: number }> {
  const container = page.locator('#app [data-g7-bootstrap-fallback]');
  await expect(container).toBeVisible({ timeout: 20_000 });

  return page.evaluate(() => {
    const el = document.querySelector('#app [data-g7-bootstrap-fallback]');
    return {
      reason: el ? el.getAttribute('data-g7-bootstrap-fallback') : null,
      title: el?.querySelector('h1')?.textContent?.trim() ?? '',
      buttons: el ? el.querySelectorAll('button').length : 0,
    };
  });
}

/**
 * 파싱 실패 케이스 본문 — 진입점만 다르다.
 *
 * @param page Playwright page
 * @param url 진입 경로
 * @return {Promise<void>}
 */
async function assertParseFailure(page: Page, url: string): Promise<void> {
  const pageErrors: string[] = [];
  page.on('pageerror', (e) => pageErrors.push(String(e)));

  await page.route(CORE_BUNDLE, (route) =>
    route.fulfill({ status: 200, contentType: 'application/javascript', body: UNPARSEABLE_JS })
  );

  await page.goto(url);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const fallback = await readFallback(page);

  expect(fallback.reason).toBe('incompatible');
  expect(fallback.title.length).toBeGreaterThan(0);
  // 존재를 확정한 뒤의 부재 단언 — 새로고침 버튼이 없어야 한다
  expect(fallback.buttons).toBe(0);

  const state = await page.evaluate(() => ({
    hasCore: typeof (window as any).G7Core !== 'undefined',
    syntaxError: (window as any).__g7Bootstrap?.syntaxError,
  }));
  expect(state.hasCore).toBe(false);
  expect(state.syntaxError).toBe(true);

  expect(pageErrors.join(' | ')).toMatch(/SyntaxError/);
}

/**
 * 네트워크 실패 케이스 본문 — 기존 문구·버튼·재시도 상한이 그대로여야 한다.
 *
 * @param page Playwright page
 * @param url 진입 경로
 * @return {Promise<void>}
 */
async function assertNetworkFailure(page: Page, url: string): Promise<void> {
  let attempts = 0;
  await page.route(CORE_BUNDLE, (route) => {
    attempts += 1;
    return route.abort('failed');
  });

  await page.goto(url);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const fallback = await readFallback(page);

  expect(fallback.reason).toBe('network');
  expect(fallback.buttons).toBe(1);

  // 재시도 상한(초기 1 + 재시도 2) 불변
  expect(attempts).toBe(3);
}

/**
 * 정상 부팅 케이스 본문 — 폴백이 오발동하지 않아야 한다.
 *
 * @param page Playwright page
 * @param url 진입 경로
 * @return {Promise<void>}
 */
async function assertNormalBoot(page: Page, url: string): Promise<void> {
  await page.goto(url);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
    { timeout: 20_000 }
  );

  const state = await page.evaluate(() => ({
    failed: (window as any).__g7Bootstrap?.failed,
    syntaxError: (window as any).__g7Bootstrap?.syntaxError,
    hasCore: typeof (window as any).G7Core !== 'undefined',
    fallbacks: document.querySelectorAll('#app [data-g7-bootstrap-fallback]').length,
  }));

  expect(state.hasCore).toBe(true);
  expect(state.failed).toBe(false);
  expect(state.syntaxError).toBe(false);
  expect(state.fallbacks).toBe(0);
}

test.describe('부팅 실패 사유별 안내 분기 (공개 #121)', () => {
  /**
   * @scenario entrypoint=user, failure_mode=parse_error
   * @effects syntax_error_renders_incompatible_notice, incompatible_notice_omits_reload_button
   */
  test('@smoke [user] 번들 파싱 실패 → 브라우저 비호환 안내 + 새로고침 버튼 부재', async ({ page }) => {
    await assertParseFailure(page, '/');
  });

  /**
   * @scenario entrypoint=admin, failure_mode=parse_error
   * @effects syntax_error_renders_incompatible_notice, incompatible_notice_omits_reload_button
   */
  test('@smoke [admin] 번들 파싱 실패 → 브라우저 비호환 안내 + 새로고침 버튼 부재', async ({ page }) => {
    await assertParseFailure(page, '/admin');
  });

  /**
   * @scenario entrypoint=user, failure_mode=network_exhausted
   * @effects network_failure_renders_network_notice, network_notice_keeps_reload_button
   */
  test('@smoke [user] 번들 상시 부재 → 기존 네트워크 안내 + 새로고침 버튼 유지', async ({ page }) => {
    await assertNetworkFailure(page, '/');
  });

  /**
   * @scenario entrypoint=admin, failure_mode=network_exhausted
   * @effects network_failure_renders_network_notice, network_notice_keeps_reload_button
   */
  test('@smoke [admin] 번들 상시 부재 → 기존 네트워크 안내 + 새로고침 버튼 유지', async ({ page }) => {
    await assertNetworkFailure(page, '/admin');
  });

  /**
   * @scenario entrypoint=user, failure_mode=none
   * @effects normal_boot_renders_no_fallback
   */
  test('@smoke [user] 정상 부팅 → 폴백 미렌더', async ({ page }) => {
    await assertNormalBoot(page, '/');
  });

  /**
   * @scenario entrypoint=admin, failure_mode=none
   * @effects normal_boot_renders_no_fallback
   */
  test('@smoke [admin] 정상 부팅 → 폴백 미렌더', async ({ page }) => {
    await assertNormalBoot(page, '/admin');
  });

  /**
   * 서빙되는 코어 엔진 번들에 lookbehind 가 없다.
   *
   * 저장소 산출물은 정적 검사가 덮지만, **실제로 서빙되는 것**이 그 산출물인지는
   * 별개 축이다(배포 경로·캐시·리버스 프록시). 브라우저가 받는 바이트를 직접 센다.
   *
   * @effects shipped_core_bundle_has_no_lookbehind
   */
  test('@smoke 서빙 중인 코어 엔진 번들에 정규식 lookbehind 가 없다', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const probe = await page.evaluate(async () => {
      const el = Array.from(document.querySelectorAll('script[src]')).find((s) =>
        /template-engine\.min\.js/.test((s as HTMLScriptElement).src)
      ) as HTMLScriptElement | undefined;
      if (!el) return { found: false, bytes: 0, lookbehind: -1 };

      const body = await fetch(el.src).then((r) => r.text());
      return { found: true, bytes: body.length, lookbehind: (body.match(/\(\?<[!=]/g) || []).length };
    });

    expect(probe.found).toBe(true);
    expect(probe.bytes).toBeGreaterThan(1000);
    expect(probe.lookbehind).toBe(0);
  });
});
