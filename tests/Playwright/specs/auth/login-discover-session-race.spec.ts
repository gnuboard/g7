/**
 * @file login-discover-session-race.spec.ts
 * @description 로그인 직후 개발자 화면이 그 회원을 안다 — 로그인 수단 묻기 응답이 로그인 응답보다 늦게 와도 (7.2.0)
 *
 * 로그인 화면은 이메일 칸을 벗어날 때 `POST /api/auth/login/discover`(관리자 `…/admin/login/discover`)로 로그인 수단을 묻는다.
 * 비밀번호 관리자 자동 입력처럼 곧바로 제출하면 이 요청과 로그인 요청이 로그인 전 세션 쿠키를 싣고 함께 나간다. 로그인 응답은
 * 서버 세션에 로그인과 그 토큰의 짝을 남기고 세션 쿠키를 새로 심는데, 묻기 응답이 세션 쿠키를 내리면서 늦게 도착하면 브라우저
 * 쿠키가 로그인 전 세션으로 되돌아가 개발자 화면(`routes/custom.php`)이 비회원으로 보인다.
 *
 * 두 갈래로 잰다(사용자·관리자 로그인 화면 각각):
 *  1. 결정적 재현 — 화면에서 로그인한 뒤, 로그인 전 세션 쿠키를 실은 묻기 요청을 따로 보내 그 응답을 브라우저가 받은 것처럼
 *     적용한다(응답에 세션 쿠키가 있으면 브라우저 쿠키를 그것으로 바꾼다). 응답에 세션 쿠키가 없어야 하고, 개발자 화면은 회원이다.
 *  2. 실제 화면 즉시 제출 5회 — 이메일·비밀번호를 곧바로 넣고 Enter. 매번 개발자 화면이 회원이다.
 *
 * 개발자 화면은 견본(`php artisan custom:example board`)으로 만들고 끝나면 지운다 — 예약 자리에 개발자 파일이 이미 있으면
 * 시작하지 않는다. 시험 계정은 `playwright:seed-password-expiry --stage=fresh` 로 만들고 끝나면 지운다.
 * 로그인 계열 요청 제한(같은 IP 분당 30회)을 넘지 않도록 관리자 묶음 앞에서 1분 쉰다.
 *
 * 실행: PLAYWRIGHT_BASE_URL=https://<개발 사이트 주소> npx playwright test tests/Playwright/specs/auth/login-discover-session-race.spec.ts
 *
 * 시나리오 매니페스트: tests/scenarios/custom-screen-auth.yaml
 */
import { existsSync, readdirSync, rmdirSync, unlinkSync } from 'node:fs';
import { resolve } from 'node:path';
import { test, expect } from '../../fixtures/auth';
import { G7_ROOT, runArtisan } from '../../fixtures/analytics';
import { runSeedPasswordExpiry, seedAccount, type SeededAccount } from '../../fixtures/password-expiry';
import type { Browser, BrowserContext, Page, PlaywrightWorkerArgs } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

type Guard = 'user' | 'admin';

const ROUTES: Record<Guard, { loginPage: string; loginApi: string; discoverApi: string }> = {
  user: { loginPage: '/login', loginApi: '/api/auth/login', discoverApi: '/api/auth/login/discover' },
  admin: { loginPage: '/admin/login', loginApi: '/api/auth/admin/login', discoverApi: '/api/auth/admin/login/discover' },
};

/** 즉시 제출 반복 횟수 */
const IMMEDIATE_SUBMIT_ROUNDS = 5;

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

/** 견본이 만드는 예약 자리 폴더 (깊은 것부터) — 비었을 때만 지운다 */
const SAMPLE_DIRS = [
  'app/Custom/Http/Controllers', 'app/Custom/Http', 'app/Custom',
  'lang/custom/en', 'lang/custom/ko', 'lang/custom',
  'public/custom',
  'resources/views/custom/admin', 'resources/views/custom/board', 'resources/views/custom/jquery', 'resources/views/custom',
];

const ROUTE_CACHE = resolve(G7_ROOT, 'bootstrap/cache/routes-v7.php');

let routeCacheWasOn = false;
const accounts: Partial<Record<Guard, SeededAccount>> = {};

test.beforeAll(() => {
  // 개발자 파일이 이미 있으면 견본이 덮지 않고 멈춘다 — 이 시험이 개발자 파일을 지우지 않도록 시작하지 않는다
  expect(existsSync(resolve(G7_ROOT, 'routes/custom.php')), '예약 자리에 개발자 라우트가 이미 있다').toBe(false);
  routeCacheWasOn = existsSync(ROUTE_CACHE);
  runArtisan('custom:example board');
  if (routeCacheWasOn) runArtisan('route:cache');

  accounts.user = seedAccount('fresh');
  accounts.admin = seedAccount('fresh', { admin: true });
});

test.afterAll(() => {
  for (const rel of SAMPLE_FILES) {
    const file = resolve(G7_ROOT, rel);
    if (existsSync(file)) unlinkSync(file);
  }
  for (const rel of SAMPLE_DIRS) {
    const dir = resolve(G7_ROOT, rel);
    if (existsSync(dir) && readdirSync(dir).length === 0) rmdirSync(dir);
  }
  runArtisan(routeCacheWasOn ? 'route:cache' : 'route:clear');
  runSeedPasswordExpiry(['--purge-users']);
});

/**
 * 브라우저 맥락의 서버 세션 쿠키를 찾는다 (이름은 사이트 설정에 따라 다르다 — 기본 `{앱 이름}-session`).
 *
 * @param context 브라우저 맥락
 * @returns 세션 쿠키 이름과 값
 */
async function sessionCookie(context: BrowserContext): Promise<{ name: string; value: string } | null> {
  const wanted = process.env.G7_SESSION_COOKIE;
  const cookie = (await context.cookies()).find((c) => (wanted ? c.name === wanted : c.name.endsWith('-session')));

  return cookie ? { name: cookie.name, value: cookie.value } : null;
}

/**
 * 로그인 화면을 열고 이메일 칸이 보일 때까지 기다린다.
 *
 * @param page 페이지
 * @param guard 로그인 경로
 */
async function openLogin(page: Page, guard: Guard): Promise<void> {
  await page.goto(ROUTES[guard].loginPage);
  await expect(page.locator('input[name="email"]')).toBeVisible({ timeout: 30_000 });
}

/**
 * 개발자 화면이 로그인 회원으로 보이는지 돌려준다.
 *
 * 사용자: 견본 게시판(Blade) 머리의 회원 인사. 관리자: 견본 관리자 화면이 관리자 로그인으로 보내지 않고 열린다.
 *
 * @param page 페이지
 * @param guard 로그인 경로
 * @returns 회원으로 보이면 true
 */
async function screenSeesMember(page: Page, guard: Guard): Promise<boolean> {
  if (guard === 'user') {
    await page.goto('/board/free');
    await page.waitForLoadState('domcontentloaded');

    return (await page.getByTestId('cx-member').count()) > 0;
  }

  const response = await page.goto('/admin/custom-example');
  await page.waitForLoadState('domcontentloaded');

  return response?.status() === 200 && !new URL(page.url()).pathname.startsWith('/admin/login');
}

/**
 * 로그인 전 세션 쿠키를 실은 묻기 요청을 따로 보내고, 그 응답이 뒤늦게 도착한 것처럼 브라우저 맥락에 적용한다.
 *
 * @param playwright Playwright 진입점
 * @param context 브라우저 맥락
 * @param baseURL 사이트 주소
 * @param guard 로그인 경로
 * @param preLogin 로그인 전 세션 쿠키
 * @param identifier 묻는 식별자
 * @returns 응답이 내린 세션 쿠키 값 (없으면 null)
 */
async function deliverLateDiscover(
  playwright: PlaywrightWorkerArgs['playwright'],
  context: BrowserContext,
  baseURL: string,
  guard: Guard,
  preLogin: { name: string; value: string },
  identifier: string,
): Promise<string | null> {
  const stale = await playwright.request.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { Accept: 'application/json', Cookie: `${preLogin.name}=${preLogin.value}` },
  });
  try {
    const response = await stale.post(ROUTES[guard].discoverApi, { data: { identifier } });
    expect(response.status()).toBe(200);
    const header = response
      .headersArray()
      .filter((h) => h.name.toLowerCase() === 'set-cookie')
      .map((h) => h.value)
      .find((v) => v.startsWith(`${preLogin.name}=`));
    if (!header) return null;

    // 브라우저가 늦게 도착한 응답의 Set-Cookie 를 받아들이는 것과 같다
    const value = header.slice(preLogin.name.length + 1).split(';')[0];
    await context.addCookies([{ name: preLogin.name, value, url: baseURL }]);

    return value;
  } finally {
    await stale.dispose();
  }
}

/**
 * 로그인 화면에서 이메일·비밀번호를 곧바로 넣고 Enter 로 제출한 뒤, 로그인 응답과 묻기 응답이 모두 끝나기를 기다린다.
 *
 * @param page 페이지
 * @param guard 로그인 경로
 * @param account 시험 계정
 */
async function submitImmediately(page: Page, guard: Guard, account: SeededAccount): Promise<void> {
  const discovered = page.waitForResponse((r) => r.url().endsWith(ROUTES[guard].discoverApi), { timeout: 30_000 });
  const loggedIn = page.waitForResponse(
    (r) => r.url().endsWith(ROUTES[guard].loginApi) && r.request().method() === 'POST',
    { timeout: 30_000 },
  );
  await page.fill('input[name="email"]', account.email);
  // 비밀번호 칸으로 옮기는 순간 이메일 칸을 벗어나 묻기 요청이 나간다
  await page.fill('input[name="password"]', account.password);
  await page.press('input[name="password"]', 'Enter');

  const [login] = await Promise.all([loggedIn, discovered]);
  expect(login.status()).toBe(200);
}

for (const guard of ['user', 'admin'] as Guard[]) {
  test.describe(`${guard === 'user' ? '사용자' : '관리자'} 로그인 화면`, () => {
    if (guard === 'admin') {
      // 사용자 묶음과 같은 IP 의 로그인 계열 요청 제한(분당 30회)을 나눠 쓰지 않게 한 번 쉰다
      test.beforeAll(async () => {
        await new Promise((r) => setTimeout(r, 61_000));
      });
    }

    /**
     * @scenario case=discover_race
     * @effects discover_issues_no_session_cookie, discover_keeps_paired_session
     */
    test('로그인 전 세션을 실은 묻기 응답이 로그인 뒤에 도착해도 개발자 화면은 회원이다', async ({ browser, baseURL, playwright }) => {
      test.setTimeout(120_000);
      const context = await (browser as Browser).newContext({ baseURL, ignoreHTTPSErrors: true });
      const page = await context.newPage();
      try {
        await openLogin(page, guard);
        const preLogin = await sessionCookie(context);
        expect(preLogin, '로그인 화면이 세션 쿠키를 심지 않았다').not.toBeNull();

        await submitImmediately(page, guard, accounts[guard]!);
        const paired = await sessionCookie(context);
        expect(paired?.value, '로그인은 세션 쿠키를 새로 심는다').not.toBe(preLogin!.value);

        const late = await deliverLateDiscover(playwright, context, baseURL!, guard, preLogin!, accounts[guard]!.email);
        expect(late, '묻기 응답이 세션 쿠키를 내렸다 — 늦게 도착하면 로그인 세션을 덮는다').toBeNull();

        expect(await screenSeesMember(page, guard)).toBe(true);
      } finally {
        await context.close();
      }
    });

    /**
     * @scenario case=discover_race
     * @effects discover_keeps_paired_session
     */
    test(`이메일·비밀번호를 곧바로 넣고 Enter 로 ${IMMEDIATE_SUBMIT_ROUNDS}번 로그인해도 매번 개발자 화면은 회원이다`, async ({ browser, baseURL }) => {
      test.setTimeout(240_000);
      const results: boolean[] = [];
      for (let round = 0; round < IMMEDIATE_SUBMIT_ROUNDS; round++) {
        const context = await (browser as Browser).newContext({ baseURL, ignoreHTTPSErrors: true });
        const page = await context.newPage();
        try {
          await openLogin(page, guard);
          await submitImmediately(page, guard, accounts[guard]!);
          results.push(await screenSeesMember(page, guard));
        } finally {
          await context.close();
        }
      }

      expect(results).toEqual(Array(IMMEDIATE_SUBMIT_ROUNDS).fill(true));
    });
  });
}
