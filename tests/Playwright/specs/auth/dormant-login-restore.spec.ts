/**
 * E2E: 휴면 회원 로그인 — 해제 안내가 로그인 화면에 뜬다 (dormant-account.yaml restore_on_login)
 *
 * 서버는 휴면이 풀린 로그인에만 `dormancy_restored: true` 를 싣고, 로그인 화면은 그 키로 「휴면 상태가
 * 해제되었습니다」 안내를 띄운다. 엔진의 로그인 핸들러가 응답에서 사용자만 골라 돌려주던 동안에는 이 안내가
 * 한 번도 뜨지 않았다 — 서버 테스트도 레이아웃 테스트도 각자 green 이라, 두 층을 실제로 잇는 이 경로만이
 * 그 끊김을 잡는다(engine-v1.76.0).
 *
 * 시험 계정은 `playwright:seed-dormant --stage=dormant` 로 한 명 심고 끝나면 `--purge-users` 로 지운다.
 *
 * @scenario stage=restore, toggle=off
 *
 * @effects dormant_account_is_restored_on_login, dormancy_restored_flag_reaches_login_layouts
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../');

/** 시드 커맨드의 기본 비밀번호 */
const PASSWORD = 'Passw0rd!dorm';

/** 해제 안내 — 로케일과 무관하게 잡는다(ko · en · ja 번들 문구). */
const RESTORED_NOTICE = /휴면 상태가 해제되었습니다|no longer dormant|休眠状態が解除されました/;

/** 로그인 성공 안내 */
const LOGIN_SUCCESS = /로그인되었습니다|Logged in successfully|ログインしました/;

let email = '';

test.describe.configure({ mode: 'serial' });

/**
 * 휴면 시험 계정 시드 커맨드를 실행한다(3중 가드 — CLI · G7_PLAYWRIGHT_BYPASS · 테스트 계정 접두사).
 *
 * @param args 커맨드 인자
 * @returns 표준 출력
 */
function seedDormant(args: string): string {
  return execSync(`php artisan playwright:seed-dormant ${args}`, {
    cwd: CORE_ROOT,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
}

/**
 * 로그인 폼을 채워 제출한다.
 *
 * @param page 페이지
 */
async function submitLogin(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', PASSWORD);
  await page.click('form button[type="submit"]');
}

test.beforeAll(() => {
  const lines = seedDormant('--stage=dormant').split(/\r?\n/).map((line) => line.trim());
  email = lines.find((line) => line.startsWith('playwright_dormant_')) ?? '';
  expect(email, '시드가 계정 이메일을 출력해야 한다').not.toBe('');
});

test.afterAll(() => {
  seedDormant('--purge-users');
});

// @scenario stage=restore, toggle=off
// @effects dormant_account_is_restored_on_login, dormancy_restored_flag_reaches_login_layouts
test('휴면 회원이 로그인하면 성공 안내와 함께 휴면 해제 안내가 뜬다', async ({ page }) => {
  const loginResponse = page.waitForResponse((res) => res.url().endsWith('/api/auth/login'));

  await submitLogin(page);

  const body = await (await loginResponse).json();
  expect(body?.data?.dormancy_restored, '서버가 해제 사실을 실었다').toBe(true);

  await expect(page.getByText(LOGIN_SUCCESS).first()).toBeVisible({ timeout: 10_000 });
  await expect(page.getByText(RESTORED_NOTICE).first()).toBeVisible({ timeout: 10_000 });
});

// @scenario stage=restore, toggle=off
// @effects dormancy_restored_flag_reaches_login_layouts
test('이미 풀린 계정으로 다시 로그인하면 해제 안내는 뜨지 않는다', async ({ page }) => {
  const loginResponse = page.waitForResponse((res) => res.url().endsWith('/api/auth/login'));

  await submitLogin(page);

  const body = await (await loginResponse).json();
  expect(body?.data, '평시 응답에는 해제 키가 없다').not.toHaveProperty('dormancy_restored');

  await expect(page.getByText(LOGIN_SUCCESS).first()).toBeVisible({ timeout: 10_000 });
  await expect(page.getByText(RESTORED_NOTICE)).toHaveCount(0);
});
