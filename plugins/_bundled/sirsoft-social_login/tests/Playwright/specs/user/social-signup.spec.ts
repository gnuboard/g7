/**
 * 소셜 가입 종단 검증 — 이메일을 주지 않는 모의 카카오로 로그인하면 가입 모달이 열리고, 이메일 인증번호·동의를 거쳐
 * 비밀번호 없이 가입한 뒤 로그인 인계로 로그인되며, 마이페이지 카드에 연결이 1건 보인다.
 */
import { test, expect } from '@playwright/test';
import { issueToken } from '../../../../../../../tests/Playwright/fixtures/auth';
import { enableTwoFactorFixture, plantTwoFactorCode, restoreTwoFactorFixture } from '../../../../../../../tests/Playwright/fixtures/two-factor';
import { approveMock, dismissCookieBanner, members, mockOff, mockOn, runId } from '../../fixtures/mock-provider';

test.describe.configure({ mode: 'serial' });

let adminToken: string;
let request: import('@playwright/test').APIRequestContext | undefined;
let mailState: Awaited<ReturnType<typeof enableTwoFactorFixture>> | undefined;

test.beforeAll(async ({ playwright }, testInfo) => {
  members('purge');
  mockOn();
  // 인증번호 메일이 실제로 나가야 가입 모달이 다음 단계로 간다(발송 실패는 503). 코어 fixture 로 메일을 SMTP 싱크로 돌리되
  // 2단계 인증은 켜지 않는다 — 추가 인자가 기본값(two_factor_auth: true)을 덮는다.
  adminToken = issueToken('core.settings.read', 'core.settings.update');
  request = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL, ignoreHTTPSErrors: true });
  mailState = await enableTwoFactorFixture(request, adminToken, { two_factor_auth: false });
});

test.afterAll(async () => {
  if (mailState && request) {
    await restoreTwoFactorFixture(request, adminToken, mailState);
  }
  await request?.dispose();
  members('purge');
  mockOff();
});

/**
 * @scenario provider=mock_kakao, variant=no_email, email=input
 * @effects e2e_signup_modal_code_consent_handoff, e2e_signup_card_shows_link, e2e_signup_code_countdown_ticks
 */
test('이메일 없는 카카오 계정 — 가입 모달에서 이메일 인증과 동의를 마치면 로그인되고 마이페이지에 연결이 보인다', async ({ page }) => {
  const id = runId();
  const email = `e2e40.signup.${id}@example.test`;

  await page.goto('/login');
  await dismissCookieBanner(page);
  await page.locator('[data-social-provider="mock_kakao"]').click();
  await approveMock(page, { variant: 'no_email', subject: `e2e-signup-${id}` });

  await expect(page).toHaveURL(/\/register\?social_pending=[A-Za-z0-9]{48}&social_step=complete/);
  const dialog = page.getByRole('dialog').filter({ hasText: '계정으로 가입' });
  await expect(dialog).toBeVisible({ timeout: 15_000 });
  const submit = dialog.getByRole('button', { name: '가입하고 시작하기' });
  await expect(submit).toBeDisabled();

  await dialog.locator('input[name="social_email"]').fill(email);
  const [codeResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/signup/email/request') && r.request().method() === 'POST'),
    dialog.getByRole('button', { name: '인증번호 받기' }).click(),
  ]);
  expect(codeResponse.status()).toBe(200);
  // 보낸 인증번호의 남은 시간이 1초마다 줄어든다.
  const timer = dialog.locator('#social_signup_code_timer');
  await expect(timer).toContainText(/남은 시간 \d+:\d{2}/);
  const first = await timer.innerText();
  await expect.poll(async () => timer.innerText(), { timeout: 5_000 }).not.toBe(first);
  const challengeId = String((await codeResponse.json()).data.challenge_id);

  await dialog.locator('input[name="social_email_code"]').fill(plantTwoFactorCode(challengeId));
  await dialog.getByRole('button', { name: '확인', exact: true }).click();
  await expect(dialog).toContainText('이메일을 확인했습니다');
  await expect(submit).toBeDisabled();

  // 동의 칸은 상태가 값을 소유한다(전역 상태 반영 뒤 다시 그린다) — 클릭하고 체크 상태를 기다린다.
  for (const name of ['social_agree_terms', 'social_agree_privacy']) {
    await dialog.locator(`input[name="${name}"]`).click();
    await expect(dialog.locator(`input[name="${name}"]`)).toBeChecked();
  }
  const [complete, handoff] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/signup/complete')),
    page.waitForResponse((r) => r.url().includes('/api/auth/login/handoff')),
    submit.click(),
  ]);
  expect(complete.status()).toBe(200);
  expect(handoff.status()).toBe(200);
  await page.waitForFunction(() => !location.pathname.startsWith('/login') && !location.pathname.startsWith('/register'));
  expect(await page.evaluate(() => !!localStorage.getItem('auth_token'))).toBe(true);

  await page.goto('/mypage/profile');
  const card = page.locator('#social_accounts_card');
  await expect(card).toBeVisible({ timeout: 15_000 });
  await expect(card.locator('li')).toHaveCount(1);
  await expect(card).toContainText('비밀번호가 없는 계정입니다');
  await expect(page.locator('body')).not.toContainText('$t:');
});
