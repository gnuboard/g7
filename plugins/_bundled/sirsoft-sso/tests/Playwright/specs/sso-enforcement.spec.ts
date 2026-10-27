/**
 * SSO 강제 종단 검증 — 이메일 먼저 입력(소유 도메인이면 회사 계정으로 안내), 도메인·역할·사이트 전체 강제의 비밀번호 로그인 차단,
 * 사이트 전체 강제 중 가입 차단과 비상 계정(2단계 필수), 자동 이동과 거부 뒤 재이동 없음, 관리자 화면 SSO 로그인과 비관리자 거절.
 * (계획서 §10 M5·M6)
 *
 * 정책·IdP 칸은 시작 때 읽은 값으로 시험마다 되돌린다. 정책은 저장소에 바로 써서 개발 사이트 다른 회원의 토큰을 회수하지 않는다.
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { issueToken } from '../../../../../../tests/Playwright/fixtures/auth';
import { enableTwoFactorFixture, plantTwoFactorCode, restoreTwoFactorFixture } from '../../../../../../tests/Playwright/fixtures/two-factor';
import { MOCK_OIDC, OWNED_DOMAIN, decideMockOidc, dismissCookieBanner, mockOff, mockOidcOn, putPolicy, randomPassword, runId, sso } from '../fixtures/sso';

test.describe.configure({ mode: 'serial' });

const id = runId();
const password = randomPassword();
const hong = `e2e798.hong.${id}@${OWNED_DOMAIN}`;
const mgr = `e2e798.mgr.${id}@example.test`;
const emergency = `e2e798.super.${id}@example.test`;
const admin = `e2e798.admin.${id}@example.test`;
const ssoAdmin = `e2e798.ssoadmin.${id}@example.test`;
const ssoAdminSub = `e2e798-ssoadmin-${id}`;
const hongSub = `e2e798-hong-${id}`;

let policyBefore: Record<string, unknown>;
let policyBaseline: Record<string, unknown>;
let idpBefore: Record<string, unknown>;
let adminToken: string;
let request: APIRequestContext | undefined;
let mailState: Awaited<ReturnType<typeof enableTwoFactorFixture>> | undefined;

test.beforeAll(async ({ playwright }, testInfo) => {
  sso('purge');
  mockOidcOn();
  policyBefore = JSON.parse(sso('policy-get'));
  idpBefore = JSON.parse(sso('idp-get', MOCK_OIDC));
  sso('member', hong, password, 'user');
  sso('link', hong, MOCK_OIDC, hongSub);
  sso('member', mgr, password, 'user', 'sirsoft-sso.manager');
  sso('member', emergency, password, 'admin');
  sso('member', admin, password, 'admin');
  sso('member', ssoAdmin, '-', 'admin');
  sso('link', ssoAdmin, MOCK_OIDC, ssoAdminSub);
  putPolicy({ emergency_emails: [emergency] });
  policyBaseline = JSON.parse(sso('policy-get'));
  // 비상 계정 로그인은 사이트 2단계와 무관하게 메일 인증번호를 보낸다(W29) — 메일을 SMTP 싱크로 돌리되 사이트 2단계는 켜지 않는다.
  adminToken = issueToken('core.settings.read', 'core.settings.update');
  request = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL, ignoreHTTPSErrors: true });
  mailState = await enableTwoFactorFixture(request, adminToken, { two_factor_auth: false });
});

test.afterEach(() => {
  sso('policy-put', JSON.stringify(policyBaseline));
  sso('idp-put', MOCK_OIDC, JSON.stringify(idpBefore));
});

test.afterAll(async () => {
  if (mailState && request) {
    await restoreTwoFactorFixture(request, adminToken, mailState);
  }
  await request?.dispose();
  sso('policy-put', JSON.stringify(policyBefore));
  sso('purge');
  mockOff();
});

/**
 * 비밀번호 로그인 API 를 부릅니다.
 *
 * @param page 페이지 (요청 문맥)
 * @param email 이메일
 * @param pass 비밀번호
 * @param guard 사용자 `user`, 관리자 `admin`
 * @returns 상태 코드와 본문
 */
async function passwordLogin(page: Page, email: string, pass: string, guard: 'user' | 'admin' = 'user'): Promise<{ status: number; body: any }> {
  const response = await page.request.post(guard === 'admin' ? '/api/auth/admin/login' : '/api/auth/login', {
    data: { email, password: pass },
    headers: { Accept: 'application/json' },
  });
  return { status: response.status(), body: await response.json().catch(() => ({})) };
}

/**
 * 로그인 화면 이메일 칸에 값을 넣고 칸을 벗어나 판정 응답을 기다립니다.
 *
 * @param page 페이지
 * @param email 이메일
 * @returns 판정 방법
 */
async function discover(page: Page, email: string): Promise<string> {
  const input = page.locator('input[name="email"]');
  await input.fill(email);
  const [response] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/auth/login/discover'),
    input.blur(),
  ]);
  expect(response.status()).toBe(200);
  return String((await response.json()).data?.method);
}

/**
 * @scenario case=discover
 * @effects e2e_owned_domain_switches_to_company_button, e2e_other_domain_keeps_password_form
 */
test('소유 도메인 이메일을 넣으면 비밀번호 칸 대신 「…(으)로 계속」이, 다른 도메인이면 비밀번호 칸이 보이고 여러 번 바꿔도 화면이 따라간다', async ({ page }) => {
  await page.goto('/login');
  await dismissCookieBanner(page);
  const passwordInput = page.locator('input[name="password"]');
  const continueButton = page.getByRole('button', { name: /\(으\)로 계속$/ });
  await expect(passwordInput).toBeVisible({ timeout: 15_000 });

  for (const [email, method] of [[`x.${id}@${OWNED_DOMAIN}`, 'external'], [`x.${id}@gmail.com`, 'password'], [`y.${id}@${OWNED_DOMAIN}`, 'external']] as const) {
    expect(await discover(page, email)).toBe(method);
    if (method === 'external') {
      await expect(continueButton).toBeVisible();
      await expect(passwordInput).toHaveCount(0);
    } else {
      await expect(passwordInput).toBeVisible();
      await expect(continueButton).toHaveCount(0);
    }
  }

  await page.getByRole('button', { name: '다른 이메일로 로그인' }).click();
  await expect(passwordInput).toBeVisible();
  await expect(continueButton).toHaveCount(0);
});

/**
 * @scenario case=domain_enforced
 * @effects e2e_domain_enforcement_blocks_password_login
 */
test('IdP 의 「이 도메인 비밀번호 로그인 막기」를 켜면 그 도메인 회원의 비밀번호 로그인은 맞든 틀리든 403 안내다', async ({ page }) => {
  expect((await passwordLogin(page, hong, password)).status).toBe(200);
  sso('idp-put', MOCK_OIDC, JSON.stringify({ enforce_owned_domains: true }));

  const right = await passwordLogin(page, hong, password);
  expect(right.status).toBe(403);
  expect(right.body.errors?.code).toBe('auth.password_auth_disabled');
  expect(right.body.data?.redirect_url).toContain(`/plugins/sirsoft-sso/login/${MOCK_OIDC}`);
  expect((await passwordLogin(page, hong, `${password}x`)).status).toBe(403);
});

/**
 * @scenario case=role_enforced
 * @effects e2e_role_enforcement_blocks_both_login_routes, e2e_wrong_password_does_not_reveal_role
 */
test('역할 강제 대상 회원은 맞는 비밀번호여도 사용자·관리자 로그인 둘 다 403 이고, 틀린 비밀번호는 일반 401 이다', async ({ page }) => {
  putPolicy({ enforce_roles: ['sirsoft-sso.manager'] });

  const user = await passwordLogin(page, mgr, password);
  expect(user.status).toBe(403);
  expect(user.body.errors?.code).toBe('auth.password_auth_disabled');
  expect((await passwordLogin(page, mgr, password, 'admin')).status).toBe(403);
  expect((await passwordLogin(page, mgr, `${password}x`)).status).toBe(401);
});

/**
 * @scenario case=site_enforced
 * @effects e2e_site_enforcement_closes_registration, e2e_emergency_account_requires_two_factor
 */
test('사이트 전체 강제 중에는 가입이 닫히고, 비상 계정만 2단계 인증을 거쳐 비밀번호로 관리자 로그인한다', async ({ page }) => {
  putPolicy({ enforce_site: true });

  const register = await page.request.post('/api/auth/register', {
    data: { name: 'e2e798', email: `e2e798.reg.${id}@example.test`, password, password_confirmation: password, agree_terms: true, agree_privacy: true },
    headers: { Accept: 'application/json' },
  });
  expect(register.status()).toBe(403);
  await page.goto('/register');
  await dismissCookieBanner(page);
  await expect(page.locator('#registration_closed_card')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('input[type="password"]')).toHaveCount(0);

  expect((await passwordLogin(page, admin, password, 'admin')).status).toBe(403);

  const first = await passwordLogin(page, emergency, password, 'admin');
  expect(first.status).toBe(200);
  const challengeId = String(first.body.data?.challenge_id ?? '');
  expect(challengeId).not.toBe('');
  expect(first.body.data?.token).toBeUndefined();
  const verify = await page.request.post('/api/auth/admin/login/two-factor', {
    data: { challenge_id: challengeId, code: plantTwoFactorCode(challengeId) },
    headers: { Accept: 'application/json' },
  });
  expect(verify.status()).toBe(200);
  const data = (await verify.json()).data ?? {};
  expect(data.token ?? data.access_token).toBeTruthy();
});

/**
 * @scenario case=auto_redirect
 * @effects e2e_auto_redirect_goes_to_idp, e2e_denied_auto_redirect_does_not_loop, e2e_local_query_skips_auto_redirect
 */
test('사이트 강제 + 자동 이동이면 관리자 로그인 화면이 곧장 IdP 로 가고, 거부하고 돌아오면 다시 가지 않으며 ?local=1 은 비밀번호 폼이다', async ({ page }) => {
  putPolicy({ enforce_site: true, auto_redirect_idp: MOCK_OIDC });
  const starts: string[] = [];
  page.on('request', (r) => {
    if (new URL(r.url()).pathname === `/plugins/sirsoft-sso/login/${MOCK_OIDC}`) starts.push(r.url());
  });

  await page.goto('/admin/login');
  await page.waitForURL(/\/mock\/oidc\/authorize\?/, { timeout: 15_000 });
  expect(starts).toHaveLength(1);

  await decideMockOidc(page, { decision: 'deny' });
  await page.waitForURL(/\/admin\/login\?sso_error=access_denied/);
  await page.waitForTimeout(3_000);
  expect(new URL(page.url()).pathname).toBe('/admin/login');
  expect(starts).toHaveLength(1);

  await page.goto('/admin/login?local=1');
  await expect(page.locator('input[name="password"]')).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(2_000);
  expect(new URL(page.url()).pathname).toBe('/admin/login');
  expect(starts).toHaveLength(1);

  // 사용자 로그인 화면도 같은 자동 이동을 따른다.
  await page.goto('/login');
  await page.waitForURL(/\/mock\/oidc\/authorize\?/, { timeout: 15_000 });
  expect(starts).toHaveLength(2);
});

/**
 * @scenario case=admin_sso
 * @effects e2e_admin_sso_login_reaches_admin
 */
test('관리자 로그인 화면의 회사 계정 버튼으로 연결된 관리자가 로그인하면 관리자 화면으로 간다', async ({ page }) => {
  await page.goto('/admin/login');
  const button = page.locator(`#sso_admin_login_button_${MOCK_OIDC}`);
  await expect(button).toBeVisible({ timeout: 15_000 });
  await Promise.all([page.waitForURL(/\/mock\/oidc\/authorize\?/), button.click()]);
  const [handoff] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/auth/admin/login/handoff'),
    decideMockOidc(page, { variant: 'mfa', sub: ssoAdminSub, email: ssoAdmin }),
  ]);
  expect(handoff.status()).toBe(200);
  await page.waitForFunction(() => location.pathname.startsWith('/admin') && !location.pathname.startsWith('/admin/login'), undefined, { timeout: 15_000 });
});

/**
 * @scenario case=admin_sso_non_admin
 * @effects e2e_non_admin_sso_refused_on_admin_screen
 */
test('관리자 권한이 없는 회원이 관리자 로그인 화면에서 회사 계정으로 들어오면 403 안내가 보이고 로그인되지 않는다', async ({ page }) => {
  await page.goto('/admin/login');
  const button = page.locator(`#sso_admin_login_button_${MOCK_OIDC}`);
  await expect(button).toBeVisible({ timeout: 15_000 });
  await Promise.all([page.waitForURL(/\/mock\/oidc\/authorize\?/), button.click()]);
  const [handoff] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/auth/admin/login/handoff'),
    decideMockOidc(page, { variant: 'mfa', sub: hongSub, email: hong }),
  ]);
  expect(handoff.status()).toBe(403);
  await expect(page.getByText('관리자 권한이 필요합니다.')).toBeVisible({ timeout: 10_000 });
  expect(new URL(page.url()).pathname).toBe('/admin/login');
  expect(await page.evaluate(() => !!localStorage.getItem('auth_token'))).toBe(false);
});
