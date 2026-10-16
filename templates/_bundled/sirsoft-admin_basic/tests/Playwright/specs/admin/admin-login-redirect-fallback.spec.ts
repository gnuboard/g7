/**
 * sirsoft-admin_basic — 관리자 로그인 복귀 주소의 대체 경로 (#757 추가 11).
 *
 * 로그인 뒤 갈 곳은 `?redirect=` 로 받는다. 사이트 안 경로가 아니면(실행 스킴·`//host`·다른 사이트·상대 경로·빈 값)
 * 엔진이 navigate `fallbackPath="/admin"` 으로 보낸다 — 관리자 첫 화면. 정상 사이트 안 경로는 그대로 돌아간다.
 * 단위 시험(`admin-login-redirect-fallback.test.ts`)은 레이아웃 식 + 엔진 판정 함수를 재고, 이 spec 은 실제 로그인
 * 제출 뒤 브라우저가 내린 주소를 잰다.
 *
 * 계정: 비밀번호가 있는 관리자 시드 계정(`playwright:seed-password-expiry --stage=fresh --admin`) — 만료 정책은 켜지 않는다.
 * 끝나면 `--purge-users` 로 이 시드 커맨드가 만든 계정을 지운다(같은 커맨드를 쓰는 spec 과 동시에 돌리지 않는다).
 *
 * 시나리오 매니페스트: `tests/scenarios/admin-login-redirect-fallback.yaml`
 * @effects rejected_admin_login_redirect_lands_on_admin_home, same_origin_admin_login_redirect_is_kept
 */
import { test, expect } from '@playwright/test';
import type { Browser } from '@playwright/test';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const CORE_ROOT = process.env.G7_ROOT || resolve(__dirname, '../../../../../../../');

/**
 * 비밀번호 시드 커맨드를 돌리고 출력 줄을 돌려준다.
 *
 * @param args 커맨드 인자
 * @returns 비어 있지 않은 출력 줄
 */
function seed(args: string): string[] {
  const out = execSync(`php artisan playwright:seed-password-expiry ${args}`, {
    cwd: CORE_ROOT,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });

  return out.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
}

let account: { email: string; password: string };

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  const lines = seed('--stage=fresh --admin');
  const email = lines.find((line) => line.endsWith('@playwright.local'));
  if (!email) throw new Error(`시드 계정 이메일을 찾지 못했습니다: ${lines.join(' | ')}`);
  account = { email, password: lines[lines.length - 1] };
});

test.afterAll(() => {
  seed('--purge-users');
});

/**
 * 새 문맥에서 `/admin/login?redirect=…` 로 로그인하고 로그인 뒤 내린 경로를 돌려준다.
 *
 * @param browser 브라우저
 * @param redirect `redirect` 쿼리 값(그대로 인코딩해 붙인다), null 이면 쿼리 없음
 * @returns 로그인 뒤 경로(pathname)
 */
async function loginWith(browser: Browser, redirect: string | null): Promise<string> {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  try {
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    const query = redirect === null ? '' : `?redirect=${encodeURIComponent(redirect)}`;
    await page.goto(`/admin/login${query}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    await page.fill('input[name="email"]', account.email);
    await page.fill('input[name="password"]', account.password);
    await page.click('form button[type="submit"]');
    // 로그인 화면을 떠나 다른 경로로 내릴 때까지 — 거부된 값으로 이동하지 않으면 로그인 화면에 머물러 여기서 실패한다
    await page.waitForURL((url) => !url.pathname.startsWith('/admin/login'), { timeout: 20_000 });
    await page.waitForLoadState('domcontentloaded');

    return new URL(page.url()).pathname.replace(/\/+$/, '') || '/';
  } finally {
    await context.close();
  }
}

test.describe('@sirsoft-admin_basic 관리자 로그인 복귀 주소 (#757 추가 11)', () => {
  for (const [label, value] of [
    ['실행 스킴', 'javascript:alert(1)'],
    ['프로토콜 상대 주소', '//evil.example/x'],
    ['다른 사이트', 'https://example.com/admin'],
    ['상대 경로', 'admin/users'],
    ['빈 값', ''],
  ] as const) {
    // @scenario redirect=rejected
    // @effects rejected_admin_login_redirect_lands_on_admin_home
    test(`거부되는 복귀 주소(${label})는 관리자 첫 화면 /admin 으로 내린다`, async ({ browser }) => {
      expect(await loginWith(browser, value)).toBe('/admin');
    });
  }

  // @scenario redirect=same_origin
  // @effects same_origin_admin_login_redirect_is_kept
  test('사이트 안 경로(/admin/users)는 그대로 돌아간다', async ({ browser }) => {
    expect(await loginWith(browser, '/admin/users')).toBe('/admin/users');
  });
});
