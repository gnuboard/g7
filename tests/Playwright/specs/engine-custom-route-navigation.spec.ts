/**
 * E2E: 엔진 화면 안 이동이 개발자 화면(custom) 주소면 전체 페이지 이동 (#792 W6, engine-v1.91.0)
 *
 * 서버 셸이 `window.G7Config.customRoutes` 로 `routes/custom.php` 의 GET 주소 패턴을 내린다. 엔진 Router 는 그
 * 패턴과 맞는 주소로 갈 때 pushState 로 직접 그리지 않고 `location.assign` 으로 서버에 넘기고, 패턴이 없거나 맞지
 * 않으면 종전과 같이 SPA 로 그린다. 패턴의 `{정규식}` 구간은 서버 라우트의 경로 제약이다 — 잃으면
 * `/board/{slug}/{id}`(숫자)가 `/board/free/write`(그누보드7 글쓰기)까지 가로챈다(2026-10-17 대장 실측).
 *
 * 개발자 라우트 유무와 무관하게 돌도록 패턴은 페이지에서 직접 넣고, 이동은 엔진 navigate 핸들러로 낸다.
 * 「같은 문서인가」는 이동 전 window 에 둔 표식이 남는지로 판정한다(전체 이동이면 새 문서라 사라진다).
 *
 * 시나리오 매니페스트: tests/scenarios/engine-custom-route-navigation.yaml
 */
import { existsSync, readdirSync, rmdirSync, unlinkSync } from 'node:fs';
import { resolve } from 'node:path';
import { test, expect, type Page } from '../fixtures/auth';
import { G7_ROOT, runArtisan } from '../fixtures/analytics';

const PATTERNS = { user: ['/board/*', '/board/*/{[0-9]+}'], admin: [] as string[] };

/** 사용자 첫 화면을 열고 엔진이 준비될 때까지 기다린다. */
async function openHome(page: Page): Promise<void> {
  await page.goto('/');
  await page.waitForFunction(
    () => !!(window as any).G7Core?.dispatch && typeof (window as any).G7Config !== 'undefined'
      && (document.querySelector('#app')?.children.length ?? 0) > 0,
    null,
    { timeout: 30_000 },
  );
}

/** 엔진 navigate 핸들러로 이동하고, 이동 뒤 같은 문서인지 돌려준다. */
async function navigateAndCheckSameDocument(page: Page, path: string, patterns: unknown): Promise<boolean> {
  await page.evaluate((p) => {
    (window as any).__e2eDocMark = 'before';
    (window as any).G7Config.customRoutes = p;
  }, patterns);
  await page.evaluate((target) => {
    setTimeout(() => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: target } }), 0);
  }, path);
  // SPA 이동은 load 이벤트가 없다 — 주소만 바뀌기를 기다린다
  await page.waitForFunction((target) => location.pathname === target, path, { timeout: 30_000 });
  await page.waitForLoadState('domcontentloaded');

  return page.evaluate(() => (window as any).__e2eDocMark === 'before');
}

test.describe('엔진 Router — custom 주소 전체 페이지 이동', () => {
  // @scenario case=navigate
  // @effects custom_navigate_full_page
  test('패턴과 맞는 주소로 이동하면 새 문서를 연다', async ({ page }) => {
    await openHome(page);

    expect(await navigateAndCheckSameDocument(page, '/board/free', PATTERNS)).toBe(false);
  });

  // @scenario case=navigate
  // @effects route_constraints_respected, non_custom_navigate_unchanged
  test('제약 구간이 맞지 않는 주소(/board/free/write)는 SPA 로 남는다', async ({ page }) => {
    await openHome(page);

    expect(await navigateAndCheckSameDocument(page, '/board/free/write', PATTERNS)).toBe(true);
  });

  // @scenario case=navigate
  // @effects no_patterns_unchanged
  test('패턴이 없으면 종전과 같이 SPA 로 그린다', async ({ page }) => {
    await openHome(page);

    expect(await navigateAndCheckSameDocument(page, '/board/free', { user: [], admin: [] })).toBe(true);
  });
});

/** `php artisan custom:example board` 가 만드는 견본 파일 (코어 원본 stubs/board 와 같은 목록) */
const SAMPLE_FILES = [
  'app/Custom/Http/Controllers/AdminExampleController.php',
  'app/Custom/Http/Controllers/BoardController.php',
  'lang/custom/en/board.php',
  'lang/custom/ko/board.php',
  'public/custom/example.css',
  'public/custom/example.js',
  'resources/views/custom/admin/example.blade.php',
  'resources/views/custom/board/error.blade.php',
  'resources/views/custom/board/list.blade.php',
  'resources/views/custom/board/show.blade.php',
  'resources/views/custom/jquery/index.html',
  'resources/views/custom/layout.blade.php',
  'routes/custom.php',
];

const ROUTE_CACHE = resolve(G7_ROOT, 'bootstrap/cache/routes-v7.php');

/** 견본이 만드는 예약 자리 폴더 (깊은 것부터) — 비었을 때만 지운다 */
const SAMPLE_DIRS = [
  'app/Custom/Http/Controllers', 'app/Custom/Http', 'app/Custom',
  'lang/custom/en', 'lang/custom/ko', 'lang/custom',
  'public/custom',
  'resources/views/custom/admin', 'resources/views/custom/board', 'resources/views/custom/jquery', 'resources/views/custom',
];

/** 견본이 만든 파일과 그 때문에 생긴 빈 폴더를 지운다(예약 자리는 원래 비어 있어야 한다). */
function removeSample(): void {
  for (const rel of SAMPLE_FILES) {
    const file = resolve(G7_ROOT, rel);
    if (existsSync(file)) unlinkSync(file);
  }
  for (const rel of SAMPLE_DIRS) {
    const dir = resolve(G7_ROOT, rel);
    if (existsSync(dir) && readdirSync(dir).length === 0) rmdirSync(dir);
  }
}

// 계획 WP-E 의 E2E — 견본 생성 상태에서 홈 → 게시판 메뉴 클릭이 custom 화면으로 전체 이동한다.
// 견본이 예약 자리에 파일을 쓰고 라우트 캐시를 다시 만들므로 이 묶음은 혼자(직렬) 돈다.
test.describe.serial('견본 생성 상태 — 홈 메뉴에서 custom 화면으로', () => {
  let routeCacheWasOn = false;

  test.beforeAll(() => {
    // 개발자 파일이 이미 있으면 견본이 덮지 않고 멈춘다 — 이 시험이 개발자 파일을 지우지 않도록 시작하지 않는다
    expect(existsSync(resolve(G7_ROOT, 'routes/custom.php')), '예약 자리에 개발자 라우트가 이미 있다').toBe(false);
    routeCacheWasOn = existsSync(ROUTE_CACHE);
    runArtisan('custom:example board');
    if (routeCacheWasOn) runArtisan('route:cache');
  });

  test.afterAll(() => {
    removeSample();
    runArtisan(routeCacheWasOn ? 'route:cache' : 'route:clear');
  });

  // @scenario case=sample_menu
  // @effects menu_click_opens_custom_screen_full_page
  test('홈의 게시판 메뉴를 누르면 새 문서로 custom 게시판 화면이 열리고, 뒤로 가면 엔진 화면이다', async ({ page }) => {
    await openHome(page);
    const customRoutes = await page.evaluate(() => (window as any).G7Config.customRoutes);
    // 견본 라우트는 slug 제약이 있어 `/board/{[A-Za-z0-9_-]+}` 로 내려온다
    expect(customRoutes.user.some((p: string) => p.startsWith('/board/'))).toBe(true);

    // 사용자 템플릿 상단 메뉴의 게시판 항목은 엔진 navigate 액션을 가진 단추다(실제 사용자 조작)
    const menu = page.locator('#app').getByRole('button', { name: '자유게시판', exact: true }).first();
    await expect(menu).toBeVisible();
    await page.evaluate(() => { (window as any).__e2eDocMark = 'before'; });

    await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/board/')), menu.click()]);
    await page.waitForLoadState('domcontentloaded');

    expect(await page.evaluate(() => (window as any).__e2eDocMark)).toBeUndefined();
    expect(await page.evaluate(() => performance.getEntriesByType('navigation').length)).toBe(1);
    await expect(page.locator('#app')).toHaveCount(0);
    await expect(page.locator('.cx-wrap').first()).toBeVisible();

    await page.goBack();
    await page.waitForFunction(() => (document.querySelector('#app')?.children.length ?? 0) > 0, null, { timeout: 30_000 });
  });
});
