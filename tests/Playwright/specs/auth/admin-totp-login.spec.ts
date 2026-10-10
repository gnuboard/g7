/**
 * 인증 앱(TOTP) 로그인 종단 검증 (이슈 #636 트랙1).
 *
 * 메일 2FA 축은 `two-factor-login.spec.ts` 가 덮는다. 여기서는 수단이 하나 늘었을 때
 * 달라지는 지점만 본다 — 화면이 provider 를 보고 분기하지 않으면, 서버가 아무것도 보내지
 * 않는데 「인증번호를 보냈습니다」라고 안내하고 「다시 받기」 버튼까지 띄워, 사용자는 오지
 * 않는 메일을 기다리다 로그인을 포기한다.
 *
 * 이 spec 은 사이트 설정을 **가역적으로** 바꾼다 — `test.afterAll` 이 원복을 담당한다.
 * 되돌리지 못하면 이후 모든 로그인이 막히므로 반드시 `mode: 'serial'` 로 실행한다.
 *
 * // @scenario provider=totp, enrolled=true|false, code=valid|invalid|backup, mail_fallback=on, locked=true
 * // @effects admin_totp_challenge_reports_app_provider, unenrolled_admin_falls_back_to_mail, a_used_code_cannot_be_reused_on_a_new_challenge, admin_totp_locked_account_cannot_complete
 */
import { test, expect, issueToken } from '../../fixtures/auth';
import {
  enableTwoFactorFixture,
  ensureTwoFactorUser,
  enrollTotpFor,
  purgeTwoFactorUsers,
  restoreTwoFactorFixture,
  totpCodeFor,
  TOTP_BACKUP_CODES,
} from '../../fixtures/two-factor';

test.describe.configure({ mode: 'serial' });

const PASSWORD = 'Passw0rd!2fa';

/** 이 spec 이 만드는 계정의 접미사 — 정리 범위를 자기 것으로 한정하는 데 쓴다. */
const ACCOUNT_PREFIX = 'totp';

/** 계정 잠금 임계값 — 잠금 축 테스트가 이 횟수만큼 오답을 넣는다. */
const LOCK_THRESHOLD = 3;

let adminToken: string;
let fixtureRequest: import('@playwright/test').APIRequestContext | undefined;
let fixtureState: Awaited<ReturnType<typeof enableTwoFactorFixture>>;
let enrolledEmail: string;
let unenrolledEmail: string;
let lockoutEmail: string;
let secret: string;
let lockoutSecret: string;

test.beforeAll(async ({ playwright }, testInfo) => {
  adminToken = issueToken('core.settings.read', 'core.settings.update');

  enrolledEmail = ensureTwoFactorUser('totpenrolled', { admin: true, password: PASSWORD });
  unenrolledEmail = ensureTwoFactorUser('totpunenrolled', { admin: true, password: PASSWORD });
  // 잠금 축은 계정을 잠그므로 전용 계정을 쓴다 — 공용 계정을 잠그면 형제 테스트가 깨진다.
  lockoutEmail = ensureTwoFactorUser('totplockout', { admin: true, password: PASSWORD });

  // 휴대폰을 들 수 없으므로 «앱이 이미 등록된 상태» 를 서버에서 만든다.
  secret = enrollTotpFor(enrolledEmail);
  lockoutSecret = enrollTotpFor(lockoutEmail);

  fixtureRequest = await playwright.request.newContext({
    baseURL: testInfo.project.use.baseURL,
    ignoreHTTPSErrors: true,
  });

  // 잠금 축이 재려면 계정 잠금이 켜져 있어야 한다. 보안 탭 전체가 스냅샷되므로 함께 되돌아간다.
  fixtureState = await enableTwoFactorFixture(fixtureRequest, adminToken, {
    login_attempt_enabled: true,
    max_login_attempts: LOCK_THRESHOLD,
  });
});

test.afterAll(async () => {
  // 되돌리지 못한 채 끝나면 사이트의 모든 로그인이 2단계 인증을 요구하게 된다.
  if (fixtureState && fixtureRequest) {
    await restoreTwoFactorFixture(fixtureRequest, adminToken, fixtureState);
  }

  await fixtureRequest?.dispose();

  // 알려진 비밀번호와 알려진 TOTP 시크릿을 가진 관리자 계정을 남기지 않는다.
  // 이 spec 이 만든 계정만 지운다 — 전체 정리는 같은 fixture 를 쓰는 형제 spec 의 계정까지
  // 지워 그쪽을 엉뚱한 실패로 떨어뜨린다.
  purgeTwoFactorUsers(ACCOUNT_PREFIX);
});

/**
 * 관리자 로그인을 제출하고 challenge 응답을 읽는다.
 *
 * @param page Playwright 페이지
 * @param email 로그인할 계정
 * @returns challenge 응답 본문의 data
 */
async function submitAdminLogin(
  page: import('@playwright/test').Page,
  email: string
): Promise<{ challenge_id: string; provider_id: string; token?: string }> {
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('/api/auth/admin/login') && r.request().method() === 'POST'
    ),
    (async () => {
      await page.fill('input[name="email"]', email);
      await page.fill('input[name="password"]', PASSWORD);
      await page.click('form button[type="submit"]');
    })(),
  ]);

  const body = await response.json();

  expect(response.status(), '2단계 인증이 켜져 있으면 로그인은 200 챌린지를 돌려준다').toBe(200);
  expect(body?.data?.two_factor_required).toBe(true);
  // 코드 확인 전에 토큰이 실리면 2단계 인증이 없는 것과 같다.
  expect(body?.data?.token, '코드 확인 전에 토큰이 발급되었습니다').toBeFalsy();

  return body.data;
}

/**
 * 등록한 관리자의 로그인이 메일이 아닌 앱 수단으로 분기하고, 화면이 그에 맞게 바뀌는지 본다.
 *
 * @scenario provider=totp, enrolled=true
 * @effects admin_totp_challenge_reports_app_provider
 */
test('등록한 관리자 — 앱 코드 안내로 바뀌고 「다시 받기」가 사라진다', async ({ page }) => {
  await page.goto('/admin/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const data = await submitAdminLogin(page, enrolledEmail);

  expect(data.provider_id, '등록한 관리자인데 인증 앱으로 분기하지 않았습니다').toBe('totp');

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });

  // 서버가 보내는 것이 없으므로 「인증번호 다시 받기」는 뜨면 안 된다 —
  // 누르면 아무 일도 일어나지 않은 것처럼 보인다.
  await expect(page.getByRole('button', { name: /다시 받기|Resend|再送/ })).toHaveCount(0);

  // 안내 문구도 메일이 아니라 앱을 가리켜야 한다.
  await expect(page.locator('body')).not.toContainText('인증번호를 보냈습니다');
  await expect(page.locator('body')).not.toContainText('Cannot read properties of undefined');
});

/**
 * 앱이 만든 코드로 관리자 로그인이 끝까지 완료되는지 본다.
 *
 * @scenario provider=totp, enrolled=true, code=valid|invalid
 * @effects admin_totp_challenge_reports_app_provider
 */
test('등록한 관리자 — 앱 코드로 로그인이 완료된다', async ({ page }) => {
  await page.goto('/admin/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  await submitAdminLogin(page, enrolledEmail);

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });

  // 오답 → 로그인 미완료
  await codeInput.fill('000000');
  await page.click('form button[type="submit"]');
  await expect(page.locator('[role="alert"]')).toBeVisible({ timeout: 10_000 });
  expect(page.url()).toContain('/admin/login');

  // 정답 → 관리자 화면 진입. 코드는 30초마다 바뀌므로 제출 직전에 계산한다.
  await codeInput.fill(totpCodeFor(secret));
  await page.click('form button[type="submit"]');

  await page.waitForFunction(() => !window.location.pathname.endsWith('/admin/login'), {
    timeout: 15_000,
  });

  const token = await page.evaluate(() => localStorage.getItem('auth_token'));
  expect(token, '로그인 완료 후 토큰이 저장되어야 합니다').toBeTruthy();
  expect(token).not.toBe('undefined');
});

/**
 * 단말을 잃은 관리자가 백업 코드로 로그인할 수 있는지 본다 — 막히면 계정이 영구히 잠긴다.
 *
 * @scenario provider=totp, enrolled=true, code=backup
 * @effects a_used_code_cannot_be_reused_on_a_new_challenge
 */
test('등록한 관리자 — 백업 코드로도 로그인된다', async ({ page }) => {
  await page.goto('/admin/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  await submitAdminLogin(page, enrolledEmail);

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });

  // 단말을 잃은 관리자의 유일한 탈출구 — 막히면 계정이 영구히 잠긴다.
  await codeInput.fill(TOTP_BACKUP_CODES[0]);
  await page.click('form button[type="submit"]');

  await page.waitForFunction(() => !window.location.pathname.endsWith('/admin/login'), {
    timeout: 15_000,
  });

  expect(await page.evaluate(() => localStorage.getItem('auth_token'))).toBeTruthy();
});

/**
 * 인증 앱을 등록하지 않은 관리자가 메일 폴백으로 로그인할 수 있는지 본다(콜드스타트 락아웃 방지).
 *
 * @scenario provider=mail_fallback, enrolled=false, mail_fallback=on
 * @effects unenrolled_admin_falls_back_to_mail
 */
test('미등록 관리자 — 메일 폴백으로 넘어가 잠기지 않는다', async ({ page }) => {
  await page.goto('/admin/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const data = await submitAdminLogin(page, unenrolledEmail);

  // 인증 앱을 등록하지 않았다는 이유로 관리자가 로그인하지 못하면
  // 설정을 되돌릴 수단까지 사라진다(콜드스타트 락아웃).
  expect(data.provider_id, '미등록 관리자가 메일 폴백으로 넘어가지 않았습니다').toBe('g7:core.mail');

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });

  // 이 경로는 실제로 메일을 보내므로 「다시 받기」가 남아 있어야 한다.
  await expect(page.getByRole('button', { name: /다시 받기|Resend|再送/ })).toHaveCount(1);
});

/**
 * 앱 challenge 를 받아 둔 뒤 계정이 잠기면, 그 challenge 의 정답 코드로도 못 들어가는지 본다.
 *
 * 관리자 판정이 코드 확인 **뒤**에 일어나는 구조라, 잠금 재검사가 admin 경로에서 빠지면
 * 잠긴 계정이 앱 코드 하나로 그대로 들어온다 — 무제한 비밀번호 추측을 막는 장치가
 * 2단계 인증 화면에서만 통째로 우회되는 셈이다.
 *
 * 잠금을 만드는 것은 **비밀번호** 실패다(`core.auth.login_failed` 훅이 카운터를 올린다).
 * 코드 오답은 challenge 자체의 시도 한도(401)라 별개 축이므로 여기서 섞지 않는다.
 *
 * 잠금은 계정 상태를 바꾸므로 **이 테스트 전용 계정**을 쓴다 — 형제 테스트가 쓰는
 * 계정을 잠그면 그쪽이 엉뚱한 이유로 실패한다.
 *
 * @scenario provider=totp, enrolled=true, code=valid, locked=true
 * @effects admin_totp_locked_account_cannot_complete
 */
test('등록한 관리자 — 잠긴 뒤에는 정답 코드로도 못 들어간다', async ({ page }) => {
  await page.goto('/admin/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 1. 아직 잠기지 않은 상태에서 앱 challenge 를 받아 둔다.
  await submitAdminLogin(page, lockoutEmail);

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });

  // 2. 다른 탭에서 비밀번호를 틀려 계정을 잠근다 — 잠금 카운터를 올리는 것은 비밀번호 실패다.
  for (let attempt = 0; attempt < LOCK_THRESHOLD; attempt++) {
    await page.request.post('/api/auth/admin/login', {
      data: { email: lockoutEmail, password: 'wrong-password' },
      headers: { Accept: 'application/json' },
      failOnStatusCode: false,
    });
  }

  // 3. 잠긴 뒤에는 잠금 전에 받은 challenge 의 정답 코드도 거절되어야 한다.
  const [verifyResponse] = await Promise.all([
    page.waitForResponse(
      (r) =>
        r.url().includes('/api/auth/admin/login/two-factor') && r.request().method() === 'POST'
    ),
    (async () => {
      await codeInput.fill(totpCodeFor(lockoutSecret));
      await page.click('form button[type="submit"]');
    })(),
  ]);

  expect(
    verifyResponse.status(),
    '잠긴 관리자가 정답 코드로 로그인에 성공하면 계정 잠금이 통째로 우회됩니다'
  ).toBe(423);

  expect(page.url()).toContain('/admin/login');
  expect(
    await page.evaluate(() => localStorage.getItem('auth_token')),
    '잠긴 계정에 토큰이 저장되었습니다'
  ).toBeFalsy();
});
