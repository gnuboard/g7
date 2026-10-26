/**
 * 모의 OIDC IdP 로그인 종단 검증 — 로그인 화면의 회사 계정 버튼에서 모의 IdP 로 가서 승인하면 첫 로그인 동의를 거쳐
 * 비밀번호 없는 회원으로 자동 가입·연결되고 로그인 인계로 로그인된다. 거부·서명 변종·다른 브라우저 콜백은 로그인되지 않는다.
 * (계획서 §10 M1)
 */
import { test, expect, type Page } from '@playwright/test';
import { MOCK_OIDC, OWNED_DOMAIN, completeConsent, decideMockOidc, dismissCookieBanner, memberState, mockOff, mockOidcOn, runId, sso } from '../fixtures/sso';

test.describe.configure({ mode: 'serial' });

const id = runId();
const jitEmail = `e2e798.oidc.${id}@${OWNED_DOMAIN}`;
const jitSub = `e2e798-oidc-${id}`;

test.beforeAll(() => {
  sso('purge');
  mockOidcOn();
});

test.afterAll(() => {
  sso('purge');
  mockOff();
});

/**
 * 로그인 화면에서 모의 회사 계정 버튼을 눌러 모의 승인 화면까지 갑니다.
 *
 * @param page 페이지
 * @param path 시작 경로
 */
async function startMockLogin(page: Page, path = '/login'): Promise<void> {
  await page.goto(path);
  await dismissCookieBanner(page);
  const button = page.locator(`#sso_login_button_${MOCK_OIDC}`);
  await expect(button).toBeVisible({ timeout: 15_000 });
  await Promise.all([page.waitForURL(/\/mock\/oidc\/authorize\?/), button.click()]);
}

/**
 * 로그인 인계가 끝나 로그인 화면을 벗어났는지 기다립니다.
 *
 * @param page 페이지
 */
async function expectLoggedIn(page: Page): Promise<void> {
  await page.waitForFunction(() => !location.pathname.startsWith('/login'), undefined, { timeout: 15_000 });
  expect(await page.evaluate(() => !!localStorage.getItem('auth_token'))).toBe(true);
}

/**
 * @scenario case=entry
 * @effects e2e_login_screen_shows_company_buttons, e2e_start_redirects_with_state_nonce_pkce
 */
test('로그인 화면에 회사 계정 묶음이 소셜보다 위에 그려지고, 버튼은 state·nonce·PKCE 를 실은 모의 authorize 로 보낸다', async ({ page }) => {
  const [idps] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/plugins/sirsoft-sso/idps'),
    page.goto('/login'),
  ]);
  expect(idps.status()).toBe(200);
  expect(new URL(idps.url()).searchParams.get('guard')).toBe('user');
  await dismissCookieBanner(page);

  const button = page.locator(`#sso_login_button_${MOCK_OIDC}`);
  await expect(button).toBeVisible({ timeout: 15_000 });
  await expect(button).toHaveAttribute('aria-label', /\(으\)로 로그인$/);
  await expect(page.locator('body')).not.toContainText('$t:');

  await Promise.all([page.waitForURL(/\/mock\/oidc\/authorize\?/), button.click()]);
  const authorize = new URL(page.url());
  expect(authorize.searchParams.get('state')).toBeTruthy();
  expect(authorize.searchParams.get('nonce')).toBeTruthy();
  expect(authorize.searchParams.get('code_challenge_method')).toBe('S256');
  expect(authorize.searchParams.get('redirect_uri')).toContain(`/plugins/sirsoft-sso/oidc/${MOCK_OIDC}/callback`);
  const bind = (await page.context().cookies()).find((c) => c.name === 'g7_sso_bind');
  expect(bind?.httpOnly).toBe(true);
});

/**
 * @scenario case=jit_consent
 * @effects e2e_first_login_consent_modal_creates_passwordless_member, e2e_jit_link_and_consents_recorded
 */
test('처음 보는 회사 계정은 동의 모달을 거쳐 비밀번호 없는 회원으로 가입·연결되고 로그인된다', async ({ page }) => {
  await startMockLogin(page);
  await decideMockOidc(page, { email: jitEmail, sub: jitSub, name: '신규직원' });

  await expect(page).toHaveURL(/\/login\?sso_pending=/);
  await expect(page.locator('#sso_consent_form')).toContainText('신규직원');
  const result = await completeConsent(page);
  expect(result).toEqual({ complete: 200, handoff: 200 });
  await expectLoggedIn(page);

  const state = memberState(jitEmail);
  expect(state.exists).toBe(true);
  expect(state.has_password).toBe(false);
  expect(state.links).toEqual([{ idp: MOCK_OIDC, linked_via: 'jit' }]);
  expect(state.consents).toBe(2);
});

/**
 * @scenario case=returning
 * @effects e2e_returning_member_skips_consent
 */
test('연결된 회사 계정으로 다시 로그인하면 동의 모달 없이 바로 로그인되고 연결은 1건 그대로다', async ({ page }) => {
  await startMockLogin(page);
  const [handoff] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/auth/login/handoff'),
    decideMockOidc(page, { email: jitEmail, sub: jitSub }),
  ]);
  expect(handoff.status()).toBe(200);
  await expectLoggedIn(page);
  await expect(page.locator('#sso_consent_submit')).toHaveCount(0);
  expect(memberState(jitEmail).links).toHaveLength(1);
});

/**
 * @scenario case=redirect
 * @effects e2e_redirect_kept_same_origin_only
 */
test('로그인 뒤 돌아갈 주소는 같은 출처 경로만 따르고 다른 호스트 주소는 첫 화면으로 바뀐다', async ({ page }) => {
  await startMockLogin(page, '/login?redirect=%2Fmypage%2Fprofile');
  await decideMockOidc(page, { email: jitEmail, sub: jitSub });
  await page.waitForURL((url) => url.pathname === '/mypage/profile', { timeout: 15_000 });

  await page.evaluate(() => localStorage.clear());
  await page.context().clearCookies();
  await startMockLogin(page, '/login?redirect=%2F%2Fevil.example');
  await decideMockOidc(page, { email: jitEmail, sub: jitSub });
  await expectLoggedIn(page);
  expect(new URL(page.url()).host).toBe(new URL(test.info().project.use.baseURL!).host);
  expect(new URL(page.url()).pathname).toBe('/');
});

/**
 * @scenario case=deny
 * @effects e2e_denied_returns_error_without_login
 */
test('모의 IdP 에서 거부하면 로그인 화면으로 돌아와 오류 안내를 보이고 로그인되지 않는다', async ({ page }) => {
  await startMockLogin(page);
  await decideMockOidc(page, { decision: 'deny' });
  await page.waitForURL(/\/login\?sso_error=access_denied/);
  await expect(page.getByText('회사 계정 로그인을 취소했거나 IdP 가 거절했습니다.')).toBeVisible({ timeout: 10_000 });
  expect(await page.evaluate(() => !!localStorage.getItem('auth_token'))).toBe(false);
});

/**
 * @scenario case=idps_failure
 * @effects e2e_idps_failure_hides_buttons_keeps_password_form
 */
test('IdP 목록 요청이 실패하면 회사 계정 묶음만 빠지고 비밀번호 로그인 폼은 그대로다', async ({ page }) => {
  await page.route('**/api/plugins/sirsoft-sso/idps**', (route) => route.abort());
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto('/login');
  await dismissCookieBanner(page);
  await expect(page.locator('input[name="password"]')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#sso_login_buttons_section')).toHaveCount(0);
  expect(errors).toEqual([]);
});

for (const variant of ['nonce_mismatch', 'aud_other', 'alg_none']) {
  /**
   * @scenario case=token_variant
   * @effects e2e_invalid_id_token_refused
   */
  test(`서명·클레임이 어긋난 id_token(${variant})은 id_token_invalid 로 돌아오고 회원이 생기지 않는다`, async ({ page }) => {
    const email = `e2e798.bad.${variant}.${id}@${OWNED_DOMAIN}`;
    await startMockLogin(page);
    await decideMockOidc(page, { variant, email, sub: `e2e798-bad-${variant}-${id}` });
    await page.waitForURL(/\/login\?sso_error=id_token_invalid/);
    expect(memberState(email).exists).toBe(false);
  });
}

/**
 * @scenario case=bind_mismatch
 * @effects e2e_callback_in_other_browser_refused
 */
test('콜백 주소를 묶음 쿠키가 없는 다른 브라우저에 붙여 넣으면 bind_mismatch 로 돌아오고 로그인되지 않는다', async ({ page, browser }) => {
  // 콜백은 승인 POST 의 302 로 가는 요청이라 route 가 가로채지 못한다 — 승인 응답의 Location 을 잡고 이 브라우저에서는 따라가지 않는다.
  let callback = '';
  await page.route('**/plugins/sirsoft-sso/mock/oidc/authorize**', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.continue();
      return;
    }
    const response = await route.fetch({ maxRedirects: 0 });
    callback = response.headers()['location'] ?? '';
    await route.abort();
  });
  await startMockLogin(page);
  await decideMockOidc(page, { email: jitEmail, sub: jitSub }).catch(() => undefined);
  await expect.poll(() => callback, { timeout: 10_000 }).toContain('code=');

  const other = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ko-KR' });
  const otherPage = await other.newPage();
  await otherPage.goto(callback);
  await otherPage.waitForURL(/\/login\?sso_error=bind_mismatch/);
  expect(await otherPage.evaluate(() => !!localStorage.getItem('auth_token'))).toBe(false);
  await other.close();
});
