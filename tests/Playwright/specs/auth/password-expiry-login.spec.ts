/**
 * 로그인 비밀번호 변경 단계 종단 검증 (#736, engine-v1.77.0).
 *
 * 비밀번호가 만료됐거나 관리자가 정해 준 비밀번호면 로그인·인증번호 확인 응답이 토큰 대신 변경 단계를 돌려준다.
 * 화면이 그 응답을 「2단계 인증이 아니면 성공」으로 오판하면 토큰 없이 이동해 다시 로그인으로 튕긴다.
 * 이 spec 은 관리자·사용자 두 로그인 화면에서 변경 → 진입, 「다음에 변경」 연기, 관리자 지정, 2단계 인증 뒤 변경 순서를
 * 실제 브라우저로 지난다.
 *
 * 사이트 보안 설정을 **가역적으로** 바꾼다 — fixture 가 스냅샷을 떠 두고 afterAll 에서 되돌린다. 한 워커에서만(serial).
 *
 * // @scenario guard=user,admin | reason=expired,admin_assigned | action=force,remind | two_factor=on,off
 * // @effects expired_login_returns_challenge_without_token, completion_issues_token_and_records_change, remind_mode_allows_postpone_until_deadline, admin_assigned_requires_change_even_when_expiry_off, two_factor_precedes_password_change
 */
import { test, expect, issueToken } from '../../fixtures/auth';
import { enablePasswordExpiryFixture, restorePasswordExpiryFixture, seedAccount } from '../../fixtures/password-expiry';
import { enableTwoFactorFixture, plantTwoFactorCode, restoreTwoFactorFixture } from '../../fixtures/two-factor';

test.describe.configure({ mode: 'serial' });

const NEW_PASSWORD = 'N3w-Passw0rd!x';

let adminToken: string;
let request: import('@playwright/test').APIRequestContext | undefined;

test.beforeAll(async ({ playwright }, testInfo) => {
  adminToken = issueToken('core.settings.read', 'core.settings.update');
  enablePasswordExpiryFixture();
  request = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL, ignoreHTTPSErrors: true });
});

test.afterAll(async () => {
  await request?.dispose();
  // 만료가 켜진 채 남으면 사이트 회원이 로그인 때 변경을 요구받는다.
  restorePasswordExpiryFixture();
});

/**
 * 보안 탭 일부 키를 관리자 설정 API 로 저장한다(운영자가 화면에서 저장하는 것과 같은 경로).
 *
 * @param security 저장할 보안 키
 */
async function saveSecurity(security: Record<string, unknown>): Promise<void> {
  // 탭 저장은 그 탭의 필수 키(HTTPS 강제 등)를 함께 요구한다 — 현재 값을 읽어 바꿀 키만 덮는다.
  const read = await request!.get('/api/admin/settings?tab=security', {
    headers: { Authorization: `Bearer ${adminToken}`, Accept: 'application/json' },
  });
  expect(read.ok()).toBe(true);
  const current = ((await read.json())?.data?.security ?? {}) as Record<string, unknown>;
  const previous = Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('_')));

  const response = await request!.post('/api/admin/settings', {
    headers: { Authorization: `Bearer ${adminToken}`, Accept: 'application/json' },
    data: { _tab: 'security', security: { ...previous, ...security } },
  });
  expect(response.status(), await response.text()).toBe(200);
}

/**
 * 로그인을 제출하고 변경 단계 응답을 확인한다.
 *
 * @param page 페이지
 * @param path 로그인 화면 경로
 * @param apiPath 로그인 API 경로 조각
 * @param email 이메일
 * @param password 비밀번호
 * @returns 응답 data
 */
async function loginExpectingChangeStep(page: import('@playwright/test').Page, path: string, apiPath: string, email: string, password: string) {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith(apiPath) && r.request().method() === 'POST'),
    page.click('form button[type="submit"]'),
  ]);
  const body = await response.json();
  expect(response.status()).toBe(200);
  expect(body?.data?.password_change_required).toBe(true);
  // 변경 단계 응답에 토큰이 실리면 만료 강제가 없는 것과 같다.
  expect(body?.data?.token ?? null).toBeNull();

  return body.data;
}

/**
 * @scenario guard=admin, reason=expired, action=force, two_factor=off
 * @effects expired_login_returns_challenge_without_token, login_stage_rejects_current_and_reused, completion_issues_token_and_records_change
 */
test('관리자 로그인 — 만료되면 변경 단계로 바뀌고, 같은 값은 거절, 새 비밀번호로 진입한다', async ({ page }) => {
  const account = seedAccount('expired', { admin: true, withHistory: true, issueToken: true });
  const data = await loginExpectingChangeStep(page, '/admin/login', '/api/auth/admin/login', account.email, account.password);
  expect(data.reason).toBe('expired');

  await expect(page.locator('#login_password_change_step')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('input[name="email"]')).toHaveCount(0);
  expect(await page.evaluate(() => localStorage.getItem('auth_token'))).toBeNull();

  // 현재 비밀번호와 같은 값 → 422, 단계 유지
  await page.fill('#password_change_new', account.password);
  await page.fill('#password_change_confirm', account.password);
  const [same] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/auth/admin/login/password-change')),
    page.click('#login_password_change_submit'),
  ]);
  expect(same.status()).toBe(422);
  await expect(page.locator('#login_password_change_new_error')).toBeVisible();
  await expect(page.locator('#login_password_change_step')).toBeVisible();

  // 새 비밀번호 → 200, 대시보드 진입
  await page.fill('#password_change_new', NEW_PASSWORD);
  await page.fill('#password_change_confirm', NEW_PASSWORD);
  const [ok] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/auth/admin/login/password-change')),
    page.click('#login_password_change_submit'),
  ]);
  expect(ok.status()).toBe(200);
  await page.waitForURL((url) => !url.pathname.startsWith('/admin/login'), { timeout: 20_000 });

  // 다른 기기 토큰은 변경과 함께 폐기된다(D17).
  const other = await request!.get('/api/admin/auth/user', {
    headers: { Authorization: `Bearer ${account.otherDeviceToken}`, Accept: 'application/json' },
  });
  expect(other.status()).toBe(401);
});

/**
 * @scenario guard=user, reason=expired, action=force, two_factor=off
 * @effects expired_login_returns_challenge_without_token, completion_issues_token_and_records_change, new_password_values_never_stored_in_global
 */
test('사용자 로그인 — 만료되면 같은 카드에서 변경 단계로 바뀌고 새 비밀번호로 로그인된다', async ({ page }) => {
  const account = seedAccount('expired');
  await loginExpectingChangeStep(page, '/login', '/api/auth/login', account.email, account.password);

  const newInput = page.locator('input[name="password_change_new"]');
  await expect(newInput).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('input[name="email"]')).toHaveCount(0);

  await newInput.fill(NEW_PASSWORD);
  await page.locator('input[name="password_change_confirm"]').fill(NEW_PASSWORD);
  // 새 비밀번호는 이 화면의 _local 에만 있고 바깥 화면이 읽는 전역 단계 정보(_global.passwordChange)에는 없다
  const stored = await page.evaluate((pw) => {
    const g = (window as any).G7Core.state.get();
    return { inStep: JSON.stringify(g.passwordChange ?? {}).includes(pw), inLocal: g._local?.passwordChangeInput?.password === pw };
  }, NEW_PASSWORD);
  expect(stored).toEqual({ inStep: false, inLocal: true });
  const [ok] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/auth/login/password-change')),
    page.click('form button[type="submit"]'),
  ]);
  expect(ok.status()).toBe(200);
  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20_000 });
  expect(await page.evaluate(() => localStorage.getItem('auth_token'))).toBeTruthy();
});

/**
 * @scenario guard=user, reason=expired, action=remind, two_factor=off
 * @effects remind_mode_allows_postpone_until_deadline
 */
test('「다음에 변경 허용」이면 「다음에 변경」으로 연기하고 로그인된다', async ({ page }) => {
  await saveSecurity({ password_expiry_action: 'remind', password_expiry_remind_days: 14 });
  try {
    const account = seedAccount('expired');
    const data = await loginExpectingChangeStep(page, '/login', '/api/auth/login', account.email, account.password);
    expect(data.can_postpone).toBe(true);
    expect(data.postpone_days).toBe(14);

    const postpone = page.getByRole('button', { name: /다음에 변경|Change later/ });
    await expect(postpone).toBeVisible({ timeout: 10_000 });
    const [ok] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/api/auth/login/password-change/postpone')),
      postpone.click(),
    ]);
    expect(ok.status()).toBe(200);
    expect((await ok.json())?.data?.postponed_until).toBeTruthy();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20_000 });
  } finally {
    await saveSecurity({ password_expiry_action: 'force' });
  }
});

/**
 * @scenario guard=user, reason=admin_assigned, action=force, two_factor=off
 * @effects admin_assigned_requires_change_even_when_expiry_off
 */
test('관리자가 정해 준 비밀번호는 연기 없이 변경 단계를 요구한다', async ({ page }) => {
  const account = seedAccount('admin_assigned');
  const data = await loginExpectingChangeStep(page, '/login', '/api/auth/login', account.email, account.password);
  expect(data.reason).toBe('admin_assigned');
  expect(data.can_postpone).toBe(false);

  await expect(page.locator('input[name="password_change_new"]')).toBeVisible({ timeout: 10_000 });
  await expect(page.getByRole('button', { name: /다음에 변경|Change later/ })).toHaveCount(0);
});

/**
 * @scenario guard=user, reason=expired, action=force, two_factor=on
 * @effects two_factor_precedes_password_change
 */
test('2단계 인증이 켜져 있으면 인증번호를 먼저 확인한 뒤 변경 단계가 온다', async ({ page }) => {
  const twoFactorState = await enableTwoFactorFixture(request!, adminToken);
  try {
    const account = seedAccount('expired');
    await page.goto('/login');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.fill('input[name="email"]', account.email);
    await page.fill('input[name="password"]', account.password);
    const [first] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/api/auth/login') && r.request().method() === 'POST'),
      page.click('form button[type="submit"]'),
    ]);
    const firstBody = await first.json();
    // 순서 불변식 — 2단계 인증이 변경 단계보다 먼저다(비밀번호만 훔친 사람이 변경으로 계정을 가져가지 못하게).
    expect(firstBody?.data?.two_factor_required).toBe(true);
    expect(firstBody?.data?.password_change_required ?? false).toBe(false);

    const code = plantTwoFactorCode(String(firstBody.data.challenge_id));
    await page.locator('input[name="two_factor_code"]').fill(code);
    const [second] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/api/auth/login/two-factor')),
      page.click('form button[type="submit"]'),
    ]);
    expect((await second.json())?.data?.password_change_required).toBe(true);
    await expect(page.locator('input[name="password_change_new"]')).toBeVisible({ timeout: 10_000 });
  } finally {
    await restoreTwoFactorFixture(request!, adminToken, twoFactorState);
  }
});
