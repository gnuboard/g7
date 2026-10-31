/**
 * 비밀번호 재설정 링크를 받은 뒤 회사 계정 로그인이 강제된 회원의 재설정 화면 종단 검증.
 *
 * 1. 관리자 재설정 화면(`/admin/reset-password?token=…&email=…`)은 링크 검증 422(`auth.password_auth_disabled`) 뒤에 서버 안내 문구와
 *    「…(으)로 로그인」 버튼을 보이고 비밀번호 칸은 없다. 링크 검증 요청은 `redirect_prefix=admin` 을 실어 버튼이 관리자 쪽 회사 계정
 *    로그인(`guard=admin`)으로 간다.
 * 2. 사용자 재설정 화면(`/reset-password?…`)은 같은 회원에게 같은 안내와 버튼을 보이고 버튼은 사용자 쪽(`guard=user`)이다.
 * 3. 강제를 끄면 같은 링크가 종전대로 새 비밀번호 입력 폼이다.
 *
 * 전제: 사용자 템플릿 sirsoft-basic, 관리자 템플릿 sirsoft-admin_basic, 모의 OIDC IdP(`sirsoft-sso:mock` — 소유 도메인 corp798.test).
 * 재설정 토큰은 시험 도구(`reset-token`)가 메일 없이 만든다. 모의 OIDC IdP 행의 칸은 켜기 전 값으로 끝에 되돌린다.
 */
import { test, expect, type Page, type Response } from '@playwright/test';
import { MOCK_OIDC, OWNED_DOMAIN, dismissCookieBanner, mockOff, mockOidcOn, randomPassword, runId, sso } from '../fixtures/sso';

test.describe.configure({ mode: 'serial' });

const id = runId();
const email = `e2e798.resetblk.${id}@${OWNED_DOMAIN}`;

/** 모의 IdP 를 켜기 전 IdP 칸 (행이 없던 사이트면 null) */
let idpOriginal: Record<string, unknown> | null = null;
/** 모의 IdP 를 켠 직후 IdP 칸 — 시험마다 이 값으로 되돌린다 */
let idpSeeded: Record<string, unknown>;
/** 재설정 링크 토큰 */
let token: string;

test.beforeAll(() => {
  sso('purge');
  try {
    idpOriginal = JSON.parse(sso('idp-get', MOCK_OIDC));
  } catch {
    idpOriginal = null;
  }
  mockOidcOn();
  idpSeeded = JSON.parse(sso('idp-get', MOCK_OIDC));
  // 링크를 받을 때는 비밀번호로 로그인하던 관리자다
  sso('member', email, randomPassword(), 'admin');
  token = sso('reset-token', email);
});

test.afterEach(() => {
  sso('idp-put', MOCK_OIDC, JSON.stringify(idpSeeded));
});

test.afterAll(() => {
  sso('purge');
  if (idpOriginal) {
    sso('idp-put', MOCK_OIDC, JSON.stringify(idpOriginal));
  }
  mockOff();
});

/**
 * 소유 도메인 강제를 켭니다 (링크를 받은 뒤 강제가 켜진 경우).
 */
function enforceOwnedDomain(): void {
  sso('idp-put', MOCK_OIDC, JSON.stringify({ ...idpSeeded, enabled: true, enforce_owned_domains: true }));
}

/**
 * 재설정 링크를 열고 링크 검증 응답을 받습니다.
 *
 * @param page 페이지
 * @param path 재설정 화면 경로 (`/admin/reset-password` | `/reset-password`)
 * @returns 링크 검증 응답
 */
async function openResetLink(page: Page, path: string): Promise<Response> {
  const query = new URLSearchParams({ token, email }).toString();
  const [response] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/auth/validate-reset-token'),
    page.goto(`${path}?${query}`),
  ]);
  return response;
}

/**
 * @scenario screen=admin_reset_password, enforce_owned_domains=on
 * @effects e2e_admin_reset_blocked_notice_and_button, e2e_admin_reset_button_goes_admin_guard
 */
test('관리자 재설정 링크 — 강제가 켜진 뒤 열면 안내 문구와 회사 계정 로그인 버튼이 보이고 버튼은 관리자 쪽 로그인으로 간다', async ({ page }) => {
  enforceOwnedDomain();
  const response = await openResetLink(page, '/admin/reset-password');

  expect(response.status()).toBe(422);
  expect(response.request().postDataJSON()).toMatchObject({ redirect_prefix: 'admin' });
  const body = await response.json();
  expect(body.errors?.code).toBe('auth.password_auth_disabled');
  const redirectUrl = new URL(String(body.errors?.redirect_url), page.url());
  expect(redirectUrl.pathname).toBe(`/plugins/sirsoft-sso/login/${MOCK_OIDC}`);
  expect(redirectUrl.searchParams.get('guard')).toBe('admin');

  const notice = page.locator('#reset_blocked_notice');
  await expect(notice).toBeVisible({ timeout: 15_000 });
  await expect(notice).toContainText(String(body.message));
  // 화면 init 이 끝난 뒤에도 남는다 — 한 번 더 그려질 시간을 둔 뒤 다시 본다
  await page.waitForTimeout(1_000);
  await expect(notice).toBeVisible();
  await expect(page.locator('input[type="password"]')).toHaveCount(0);
  await expect(page.locator('#reset_token_error')).toHaveCount(0);

  const button = page.locator('#reset_alternate_button');
  await expect(button).toBeVisible();
  const [start] = await Promise.all([
    page.waitForRequest((r) => new URL(r.url()).pathname === `/plugins/sirsoft-sso/login/${MOCK_OIDC}`),
    button.click(),
  ]);
  expect(new URL(start.url()).searchParams.get('guard')).toBe('admin');
  // 모의 OIDC 승인 화면까지 간다
  await expect(page.locator('select[name="variant"]')).toBeVisible({ timeout: 15_000 });
});

/**
 * @scenario screen=user_reset_password, enforce_owned_domains=on
 * @effects e2e_user_reset_blocked_notice_user_guard
 */
test('사용자 재설정 링크 — 같은 회원에게 같은 안내와 버튼이 보이고 버튼은 사용자 쪽 로그인이다', async ({ page }) => {
  enforceOwnedDomain();
  const response = await openResetLink(page, '/reset-password');
  await dismissCookieBanner(page);

  expect(response.status()).toBe(422);
  expect(response.request().postDataJSON()?.redirect_prefix).toBeUndefined();
  const body = await response.json();
  expect(body.errors?.code).toBe('auth.password_auth_disabled');
  expect(new URL(String(body.errors?.redirect_url), page.url()).searchParams.get('guard')).toBe('user');

  const notice = page.locator('#password_auth_blocked');
  await expect(notice).toBeVisible({ timeout: 15_000 });
  await expect(notice).toContainText(String(body.message));
  await expect(notice.getByRole('button')).toBeVisible();
  await expect(page.locator('input[type="password"]')).toHaveCount(0);
});

/**
 * @scenario screen=admin_reset_password, enforce_owned_domains=off
 * @effects e2e_reset_form_when_not_enforced
 */
test('강제를 끄면 같은 관리자 재설정 링크가 새 비밀번호 입력 폼이다', async ({ page }) => {
  sso('idp-put', MOCK_OIDC, JSON.stringify({ ...idpSeeded, enforce_owned_domains: false }));
  const response = await openResetLink(page, '/admin/reset-password');

  expect(response.status()).toBe(200);
  await expect(page.locator('input[type="password"]')).toHaveCount(2, { timeout: 15_000 });
  await expect(page.locator('#reset_blocked_notice')).toHaveCount(0);
});
