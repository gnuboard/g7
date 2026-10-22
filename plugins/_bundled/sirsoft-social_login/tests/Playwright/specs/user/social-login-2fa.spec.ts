/**
 * 소셜 로그인 + 2단계 인증 종단 검증 — 사이트 2단계 인증을 켜면 연결된 회원의 소셜 로그인도 로그인 화면에서 인증번호 단계를
 * 거친다(코어 관문). 인증번호를 넣으면 돌아갈 주소로 간다. 사이트 설정·메일 설정은 코어 fixture 가 바꾸고 되돌린다.
 */
import { test, expect } from '@playwright/test';
import { issueToken } from '../../../../../../../tests/Playwright/fixtures/auth';
import { enableTwoFactorFixture, plantTwoFactorCode, restoreTwoFactorFixture } from '../../../../../../../tests/Playwright/fixtures/two-factor';
import { approveMock, members, mockOff, mockOn, runId } from '../../fixtures/mock-provider';

test.describe.configure({ mode: 'serial' });

const id = runId();
const email = `e2e40.2fa.${id}@example.test`;
const subject = `e2e-2fa-${id}`;
let adminToken: string;
let request: import('@playwright/test').APIRequestContext | undefined;
let state: Awaited<ReturnType<typeof enableTwoFactorFixture>> | undefined;

test.beforeAll(async ({ playwright }, testInfo) => {
  members('purge');
  members('member', email);
  members('link', email, 'mock_google', subject);
  mockOn();
  adminToken = issueToken('core.settings.read', 'core.settings.update');
  request = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL, ignoreHTTPSErrors: true });
  state = await enableTwoFactorFixture(request, adminToken, { two_factor_scope: 'all' });
});

test.afterAll(async () => {
  if (state && request) {
    await restoreTwoFactorFixture(request, adminToken, state);
  }
  await request?.dispose();
  members('purge');
  mockOff();
});

/**
 * @scenario provider=mock_google, two_factor=on, link=existing
 * @effects e2e_social_login_goes_through_two_factor
 */
test('2단계 인증이 켜진 사이트 — 구글 연결 회원은 인계 뒤 인증번호 단계를 거쳐 돌아갈 주소로 간다', async ({ page }) => {
  await page.goto('/plugins/sirsoft-social_login/oauth/mock_google/start?redirect=%2Fmypage%2Fprofile');

  const [handoff] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/auth/login/handoff') && r.request().method() === 'POST'),
    approveMock(page, { subject }),
  ]);
  const body = await handoff.json();
  expect(handoff.status()).toBe(200);
  expect(body.data.two_factor_required).toBe(true);
  expect(body.data.token ?? null, '인증번호 확인 전에는 토큰이 없다').toBeNull();

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });
  await codeInput.fill(plantTwoFactorCode(String(body.data.challenge_id)));
  await page.click('form button[type="submit"]');

  await page.waitForURL(/\/mypage\/profile/, { timeout: 15_000 });
  expect(await page.evaluate(() => !!localStorage.getItem('auth_token'))).toBe(true);
});
