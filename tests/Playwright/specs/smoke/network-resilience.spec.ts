/**
 * Smoke: 페이지 로드 요청 1건의 일시 실패가 앱 전체를 죽이지 않는다 (#463)
 *
 * 한 페이지 로드는 same-origin 요청 20여 개를 발사하는데, 그중 6개는 1건만 취소돼도
 * 앱 전체가 죽었다(전면 "초기화 실패" / 백지 / raw 영문 토스트). 모바일 회선에서
 * 새로고침을 연타하면 요청이 떠 있는 창(수백 ms)에 정확히 꽂혀 간헐 재현된다.
 *
 * 방어는 **로드 계층**(fetch 래퍼 / script 로더)에 건다. 재시도할 URL 목록을 열거하는
 * 방식은 쓰지 않는다 — 레이아웃 API 는 라우트마다 경로가 다른 무한 집합이라, 새 페이지·
 * 새 모듈이 생길 때마다 구멍이 다시 뚫린다.
 *
 * 검증 축:
 *   (A) 1건 취소(일시 실패) → 재시도로 복구, 사용자는 인지조차 못 한다
 *   (B) 상시 취소(진짜 부재) → 백지·raw 영문이 아니라 명시적 안내로 끝난다
 *
 * @scenario network-resilience
 * @effects retry-on-network-failure, no-blank-screen, no-raw-error-leak
 */
import { test, expect, type Page } from '@playwright/test';

/** 앱이 죽었다고 판정하는 화면 문구 (전면 에러 / 내부 식별자 raw 노출) */
const DEAD_SCREEN = /초기화 실패|페이지 로딩 실패|Unknown action handler/;

/** 코어/컴포넌트 번들이 끝내 부재할 때 사용자에게 보여야 할 폴백 안내 */
const FALLBACK_UI = /화면을 불러오지 못했습니다|Failed to load the page/;

/**
 * 지정 패턴의 **첫 요청 1건만** 취소한다 (재시도 요청은 통과).
 *
 * 일시적 커넥션 유실을 모사한다 — 재시도가 실제로 복구하는지 보기 위함.
 *
 * @param page Playwright page
 * @param pattern 취소할 요청 URL glob
 * @return 취소·통과 카운터 (재시도 발동 여부 확인용)
 */
async function abortFirstRequestOnly(page: Page, pattern: string): Promise<{ hits: () => number }> {
  let hits = 0;
  await page.route(pattern, (route) => {
    hits += 1;
    if (hits === 1) return route.abort('aborted');
    return route.continue();
  });
  return { hits: () => hits };
}

/** 페이지 본문 텍스트 (공백 정규화) */
async function bodyText(page: Page): Promise<string> {
  return page.evaluate(() => document.body.innerText.trim().replace(/\n+/g, ' | '));
}

test.describe('네트워크 복원력 — 요청 1건의 일시 실패 (#463)', () => {
  // 각 경로의 첫 요청 1건을 취소해도 재시도로 복구되어 앱이 정상 렌더돼야 한다.
  const SINGLE_ABORT_PATHS: Array<{ name: string; pattern: string }> = [
    { name: 'routes.json', pattern: '**/api/templates/*/routes.json*' },
    { name: 'components.json', pattern: '**/api/templates/*/components.json*' },
    { name: 'modules/bundle.js', pattern: '**/api/modules/bundle.js*' },
    { name: 'layouts/*.json', pattern: '**/api/layouts/**' },
  ];

  for (const { name, pattern } of SINGLE_ABORT_PATHS) {
    test(`@smoke ${name} 1건 취소 → 재시도로 복구되어 앱이 정상 렌더된다`, async ({ page }) => {
      const counter = await abortFirstRequestOnly(page, pattern);

      await page.goto('/');
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
      await page.waitForFunction(
        () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
        { timeout: 20_000 }
      );

      const text = await bodyText(page);

      // 전면 에러 화면 없이 살아남아야 한다
      expect(text).not.toMatch(DEAD_SCREEN);
      expect(text.length).toBeGreaterThan(20);

      // 취소 1건 + 재시도 1건 = 정확히 2회.
      // 하한(>=2)으로 두면 과잉 재시도(3회+) 회귀가 그대로 통과한다. 재시도는 "필요한
      // 만큼만" 이 계약이므로 정확값으로 잠근다.
      expect(counter.hits()).toBe(2);
    });
  }

  /**
   * `<script src>` 경로 (코어 번들 / 템플릿 컴포넌트 번들).
   *
   * 실측 결과 — 이 둘은 **1건 취소만으로는 죽지 않는다**. Chromium 이 abort 된
   * `<script src>` 를 스스로 재요청하기 때문이다(수정 전 blade 로 측정해도
   * hits=2, #app 렌더 정상). 따라서 "1건 취소 → 복구" 를 단언하는 테스트는
   * **수정 전에도 통과** 해 회귀 가드가 되지 못한다 (거짓 안심).
   *
   * blade 인라인 재시도가 실제로 값을 갖는 지점은 **브라우저가 포기한 뒤**다.
   * 그 경계(끝내 부재 → 백지 대신 폴백 안내)는 아래 '번들이 끝내 부재할 때'
   * describe 가 검증한다. 여기서는 그 사이 계약 — 재시도 훅이 실제로 설치되고
   * 정상 경로를 방해하지 않는다는 것 — 만 잠근다.
   */
  test('@smoke script 번들 경로 — 부트스트랩 재시도 훅이 설치되고 정상 로드를 방해하지 않는다', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 20_000 }
    );

    // 재시도/폴백 훅이 실제로 살아 있어야 한다 (blade partial 이 빠지면 여기서 잡힌다)
    const bootstrap = await page.evaluate(() => {
      const b = (window as any).__g7Bootstrap;
      return b
        ? { present: true, failed: b.failed, hasRetry: typeof b.retry === 'function', hasFallback: typeof b.renderFallback === 'function' }
        : { present: false };
    });

    expect(bootstrap.present).toBe(true);
    expect(bootstrap.hasRetry).toBe(true);
    expect(bootstrap.hasFallback).toBe(true);

    // 정상 경로에서는 실패로 마킹되지 않아야 한다 (재시도가 오발동하면 여기서 잡힌다)
    expect(bootstrap.failed).toBe(false);

    const text = await bodyText(page);
    expect(text).not.toMatch(DEAD_SCREEN);
  });

  // 레이아웃 API 는 페이지마다 경로가 다르다. 방어가 로드 계층에 걸려 있으므로
  // URL 이 달라져도 동일하게 복구돼야 한다 (URL 열거 방식이 아님을 잠근다).
  test('@smoke 레이아웃 json 1건 취소 → 다른 경로의 페이지에서도 복구된다', async ({ page }) => {
    const counter = await abortFirstRequestOnly(page, '**/api/layouts/**');

    await page.goto('/login');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 20_000 }
    );

    const text = await bodyText(page);
    expect(text).not.toMatch(DEAD_SCREEN);
    expect(counter.hits()).toBe(2);
  });
});

test.describe('네트워크 복원력 — 번들이 끝내 부재할 때 (#463)', () => {
  /**
   * 코어 번들이 없으면 재시도할 JS 자체가 없다(닭-달걀). blade 인라인 JS 가 재시도하고,
   * 끝내 실패하면 **백지가 아니라** 사용자 안내를 심어야 한다.
   *
   * 수정 전: 완전 백지 (콘솔에만 `G7Core.initTemplateApp is not available`).
   */
  test('@smoke 코어 번들 상시 부재 → 백지가 아니라 폴백 안내 + 새로고침 버튼', async ({ page }) => {
    let attempts = 0;
    await page.route('**/build/core/template-engine.min.js*', (route) => {
      attempts += 1;
      return route.abort('failed');
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 20_000 }
    );

    const text = await bodyText(page);

    // 백지가 아니어야 한다 (수정 전 결함)
    expect(text.length).toBeGreaterThan(0);
    expect(text).toMatch(FALLBACK_UI);
    await expect(page.locator('#app button')).toBeVisible();

    // 재시도 3시도 (초기 1 + 재시도 2)
    expect(attempts).toBe(3);
  });

  /**
   * 템플릿 컴포넌트 번들도 코어 번들과 동일한 `<script src>` 사망 경로다.
   * 이쪽이 끝내 부재하면 코어 엔진(`G7Core`)은 살아 있지만 렌더할 컴포넌트가 없어
   * 화면을 만들 수 없다 — 백지로 끝나면 안 되고 폴백 안내로 끝나야 한다.
   *
   * (1건 취소 → 복구 는 검증하지 않는다. 위 'script 번들 경로' 주석대로 브라우저가
   *  abort 된 script 를 스스로 재요청해 수정 전에도 통과하기 때문. 재시도가 실제로
   *  값을 갖는 지점은 브라우저가 포기한 뒤인 여기다.)
   */
  test('@smoke 템플릿 컴포넌트 번들 상시 부재 → 백지가 아니라 폴백 안내 + 새로고침 버튼', async ({ page }) => {
    let attempts = 0;
    await page.route('**/api/templates/assets/**/components.iife.js*', (route) => {
      attempts += 1;
      return route.abort('failed');
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 20_000 }
    );

    const text = await bodyText(page);

    // 백지가 아니어야 한다
    expect(text.length).toBeGreaterThan(0);
    expect(text).toMatch(FALLBACK_UI);
    await expect(page.locator('#app button')).toBeVisible();

    // 재시도 3시도 (초기 1 + 재시도 2)
    expect(attempts).toBe(3);
  });

  /**
   * 재시도를 소진한 **진짜 실패**는 조용히 삼키면 안 된다. 사용자가 상황을 알고
   * 재시도(새로고침)할 수 있어야 한다 — 복원력이 은폐가 되어서는 안 된다.
   */
  test('@smoke routes.json 상시 부재 → 명시적 에러 화면 + 새로고침 버튼', async ({ page }) => {
    let attempts = 0;
    await page.route('**/api/templates/*/routes.json*', (route) => {
      attempts += 1;
      return route.abort('failed');
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 20_000 }
    );

    const text = await bodyText(page);

    // 재시도 상한(초기 1 + 재시도 2)을 지킨다 — 무한루프 부재
    expect(attempts).toBe(3);

    // 진짜 실패는 사용자에게 알린다 (백지가 아니라 명시적 에러 + 재시도 수단)
    expect(text).toMatch(/초기화 실패/);
    expect(text).toMatch(/새로고침/);
  });

  /**
   * 모듈 번들이 부재하면 그 확장 소유 핸들러는 **영원히** 등록되지 않는다.
   *
   * 수정 전 결함 2가지:
   *   (a) waitForHandlers 가 maxWait(5000ms) 통째로 블로킹 → 5초 백지
   *   (b) `Unknown action handler: sirsoft-ecommerce.initPreferredCurrency` raw 노출
   */
  test('@smoke 모듈 번들 상시 부재 → 5초 블로킹 없이 렌더 + 내부 식별자 미노출', async ({ page }) => {
    await page.route('**/api/modules/bundle.js*', (route) => route.abort('failed'));

    // `waitUntil: 'commit'` — 기본값('load')은 취소된 번들의 재시도 체인까지 포함한
    // 모든 서브리소스를 기다리므로, 측정 시작점이 밀려 "렌더까지 걸린 시간" 이 아니라
    // "네비게이션 + 렌더" 를 재게 된다. 우리가 잠그려는 것은 waitForHandlers 의
    // 5초 블로킹이므로, 문서 커밋 시점부터 렌더까지를 잰다.
    await page.goto('/', { waitUntil: 'commit' });
    const committedAt = Date.now();

    await page.waitForFunction(
      () => (document.querySelector('#app')?.childElementCount ?? 0) > 0,
      { timeout: 20_000 }
    );
    const renderedInMs = Date.now() - committedAt;

    // (a) 5초 블로킹이 사라졌다 — 오지 않을 핸들러를 기다리지 않는다.
    //     수정 전에는 waitForHandlers 가 maxWait(5000ms) 를 통째로 소진했다.
    expect(renderedInMs).toBeLessThan(5_000);

    // (b) 내부 식별자가 사용자 화면에 raw 로 노출되지 않는다
    await page.waitForTimeout(2_000); // 토스트가 뜰 시간을 준 뒤 확인
    const text = await bodyText(page);
    expect(text).not.toMatch(/Unknown action handler/);

    // 확장 기능만 조용히 열화되고 페이지 자체는 살아 있다
    expect(text.length).toBeGreaterThan(20);
  });
});
