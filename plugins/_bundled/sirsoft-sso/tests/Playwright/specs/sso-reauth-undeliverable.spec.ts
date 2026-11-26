/**
 * 메일을 받을 수 없는 회사 계정 회원의 본인 확인 종단 검증 — 회사 계정으로만 가입해 이메일이 대체 주소(`….invalid`)이고 비밀번호도 없는 회원.
 *
 * 1. 마이페이지 > 내 정보 수정의 「본인 확인 계속하기」 가 연결된 회사 계정(모의 OIDC) 로그인으로 보내고, 돌아오면 정보 수정 폼이 열린다.
 *    휴대폰 본인확인 플러그인(회원별 판정이 없는 수단)이 켜진 사이트에서도 그 수단으로 빠지지 않고, 받을 곳 없는 인증번호 칸이나 소셜
 *    계정 안내가 보이지 않는다.
 * 2. IdP 가 이 사이트 비밀번호를 허용하면, 비밀번호 변경 화면에서 처음 비밀번호를 저장할 때 본인 확인(428)이 회사 계정 다시 로그인으로
 *    가고 비밀번호 변경 화면으로 돌아온다. 돌아와 다시 저장하면 통과한다(본인 확인 유예 시간 안).
 * 3. IdP 가 이 사이트 비밀번호를 허용하지 않으면(기본), 비밀번호 변경 화면과 관리자 회원 수정 화면이 비밀번호 칸·본인 확인 대신
 *    「비밀번호를 만들 수 없습니다」 안내를 미리 보인다.
 *
 * 전제: 사용자 템플릿 sirsoft-basic, 관리자 템플릿 sirsoft-admin_basic, 모의 OIDC IdP(`sirsoft-sso:mock`), 운영자가 민감 작업 수단을 따로
 * 지정하지 않은 기본 설정, 비밀번호 변경 본인 확인 정책(설치 기본 켜짐, 유예 5분).
 *
 * 모의 OIDC IdP 행의 칸(소유 도메인·비밀번호 허용 등)은 모의 IdP 를 켜기 전 값으로 끝에 되돌린다 — 켜는 명령이 소유 도메인을 시험 값으로 바꾼다.
 * 재확인 요청 응답은 화면이 곧바로 IdP 로 옮겨 가 응답 본문이 사라지므로, 요청을 가로채 본문을 먼저 받아 둔다.
 */
import { test, expect, type Page, type Response, type Route } from '@playwright/test';
import { MOCK_OIDC, completeConsent, decideMockOidc, dismissCookieBanner, memberState, mockOff, mockOidcOn, randomPassword, runId, sso } from '../fixtures/sso';

test.describe.configure({ mode: 'serial' });

const id = runId();
const email = `e2e798.reauth.${id}@sso.invalid`;
const subject = `e2e798-reauth-${id}`;
const pwEmail = `e2e798.reauthpw.${id}@sso.invalid`;
const pwSubject = `e2e798-reauthpw-${id}`;
const adminEmail = `e2e798.reauthadm.${id}@sso.invalid`;
const adminSubject = `e2e798-reauthadm-${id}`;

/** 모의 IdP 를 켜기 전 IdP 칸 (행이 없던 사이트면 null) */
let idpOriginal: Record<string, unknown> | null = null;
/** 모의 IdP 를 켠 직후 IdP 칸 — 시험마다 이 값으로 되돌린다 */
let idpSeeded: Record<string, unknown>;

test.beforeAll(() => {
  sso('purge');
  try {
    idpOriginal = JSON.parse(sso('idp-get', MOCK_OIDC));
  } catch {
    idpOriginal = null;
  }
  mockOidcOn();
  idpSeeded = JSON.parse(sso('idp-get', MOCK_OIDC));
  sso('member', email, '-');
  sso('link', email, MOCK_OIDC, subject);
  sso('member', pwEmail, '-');
  sso('link', pwEmail, MOCK_OIDC, pwSubject);
  sso('member', adminEmail, '-', 'admin');
  sso('link', adminEmail, MOCK_OIDC, adminSubject);
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
 * 화면이 회원 정보(`GET /api/me`)를 받는 때를 기다릴 약속을 만듭니다 — 비밀번호 변경 화면을 열거나 그 화면으로 돌아오게 하는 동작 **전에** 부른다.
 *
 * 저장 버튼과 칸 구성(현재 비밀번호 칸이 있는지 등)은 이 응답으로 정해진다. 받기 전에 채우고 누르면 저장이 나가지 않으므로(응답이 늦은 사이트에서
 * 드러난다) 저장 전에 이 응답과 화면 신호를 함께 기다린다.
 *
 * @param page 페이지
 * @returns 회원 정보 응답 약속
 */
function waitForMemberInfo(page: Page): Promise<Response> {
  return page.waitForResponse((r) => new URL(r.url()).pathname === '/api/me' && r.request().method() === 'GET', { timeout: 30_000 });
}

/**
 * 비밀번호 없는 회원의 비밀번호 변경 화면이 회원 정보를 반영해 저장할 수 있게 됐는지 확인합니다 — 응답이 성공이고, 저장 버튼이 풀렸고,
 * 현재 비밀번호 칸이 없다.
 *
 * @param page 페이지
 * @param memberInfo {@link waitForMemberInfo} 가 돌려준 약속
 */
async function expectPasswordFormReady(page: Page, memberInfo: Promise<Response>): Promise<void> {
  expect((await memberInfo).ok()).toBe(true);
  await expect(page.locator('form#form button[type="submit"]')).toBeEnabled({ timeout: 15_000 });
  await expect(page.locator('input[name="current_password"]')).toHaveCount(0);
}

/** 가로챈 재확인 요청 한 건 */
type CapturedChallenge = { status: number; request: Record<string, any>; body: any };

/**
 * 재확인 요청(`POST /api/identity/challenges`)을 가로채 요청·응답 본문을 받아 둡니다 — 화면이 응답 직후 외부 화면으로 옮겨 가도 남는다.
 *
 * @param page 페이지
 * @returns 받아 둔 요청 목록 (요청 순서)
 */
async function captureChallenges(page: Page): Promise<CapturedChallenge[]> {
  const captured: CapturedChallenge[] = [];
  await page.route('**/api/identity/challenges', async (route: Route) => {
    if (route.request().method() !== 'POST') {
      await route.continue();
      return;
    }
    const response = await route.fetch();
    const raw = await response.body();
    captured.push({ status: response.status(), request: route.request().postDataJSON() ?? {}, body: JSON.parse(raw.toString('utf8')) });
    await route.fulfill({ response, body: raw });
  });
  return captured;
}

/**
 * 회사 계정 버튼으로 로그인합니다 — 첫 로그인 동의가 뜨면 마친다.
 *
 * @param page 페이지
 * @param sub 모의 IdP subject
 */
async function loginWithCompanyAccount(page: Page, sub: string): Promise<void> {
  await page.goto('/login');
  await dismissCookieBanner(page);
  const button = page.locator(`#sso_login_button_${MOCK_OIDC}`);
  await expect(button).toBeVisible({ timeout: 15_000 });
  await Promise.all([page.waitForURL(/\/mock\/oidc\/authorize\?/), button.click()]);
  await decideMockOidc(page, { sub });
  await page.waitForFunction(() => !location.pathname.startsWith('/mock/') && !location.pathname.includes('/oidc/'), undefined, { timeout: 15_000 });
  if (new URL(page.url()).searchParams.has('sso_pending')) {
    await completeConsent(page);
  }
  await page.waitForFunction(() => !location.pathname.startsWith('/login'), undefined, { timeout: 15_000 });
}

/**
 * @scenario member=company_account_undeliverable, screen=mypage_profile_edit
 * @effects e2e_reauth_screen_has_no_social_wording, e2e_reauth_continue_picks_company_account, e2e_reauth_roundtrip_opens_profile_form
 */
test('「본인 확인 계속하기」 는 회사 계정 다시 로그인으로 보내고, 돌아오면 정보 수정 폼이 열린다', async ({ page }) => {
  const challenges = await captureChallenges(page);
  await loginWithCompanyAccount(page, subject);

  await page.goto('/mypage/profile/edit');
  await dismissCookieBanner(page);
  const continueButton = page.locator('#reauth_continue_button');
  await expect(continueButton).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#reauth_alternative_section')).not.toContainText('소셜');
  await expect(page.locator('input[name="reauth_code"]')).toHaveCount(0);

  await Promise.all([page.waitForURL(/\/mock\/oidc\/authorize\?/, { timeout: 15_000 }), continueButton.click()]);
  expect(challenges).toHaveLength(1);
  expect(challenges[0].status).toBe(201);
  expect(challenges[0].body.data.provider_id).toBe('sirsoft-sso');
  expect(challenges[0].body.data.render_hint).toBe('external_redirect');

  await decideMockOidc(page, { sub: subject });

  await page.waitForURL(/\/mypage\/profile\/edit/, { timeout: 15_000 });
  await expect(continueButton).toHaveCount(0, { timeout: 15_000 });
  await expect(page.locator('input[name="name"]')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('input[name="reauth_code"]')).toHaveCount(0);
  expect(new URL(page.url()).searchParams.has('verification_token')).toBe(false);
});

/**
 * @scenario member=company_account_undeliverable, screen=mypage_change_password, allow_local_password=true
 * @effects e2e_password_reauth_notice_matches_company_account, e2e_password_reauth_returns_to_change_password, e2e_password_saved_after_return
 */
test('비밀번호를 허용하는 IdP 면 처음 비밀번호 저장의 본인 확인이 회사 계정으로 가고, 비밀번호 변경 화면으로 돌아와 다시 저장하면 통과한다', async ({ page }) => {
  sso('idp-put', MOCK_OIDC, JSON.stringify({ allow_local_password: true }));
  const challenges = await captureChallenges(page);
  await loginWithCompanyAccount(page, pwSubject);

  const memberInfo = waitForMemberInfo(page);
  await page.goto('/mypage/change-password');
  await dismissCookieBanner(page);
  // 회원 정보를 받기 전에 채우고 누르면 저장이 나가지 않는다 — 받고 화면이 반영할 때까지 기다린다
  await expectPasswordFormReady(page, memberInfo);
  // 안내는 실제 수단(회사 계정)과 맞아야 한다 — 소셜 안내도, 「관리자에게 문의」 도 아니다
  // 엔진 스코프: 확장점 호스트 아래(기본 내용 포함)의 정적 노드 id 는 DOM 에 {id}__{호스트 id} 로 나가므로 접두어 [id^="{id}__"] 로 찾는다.
  await expect(page.locator('[id^="password_reauth_other_notice__"], [id^="social_change_password_reauth_other__"]').first()).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('body')).not.toContainText('연결한 소셜 계정으로');
  await expect(page.locator('#password_set_unavailable')).toHaveCount(0);

  const password = randomPassword();
  await page.locator('input[name="password"]').fill(password);
  await page.locator('input[name="password_confirmation"]').fill(password);
  const [guarded] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/me/password' && r.request().method() === 'PUT'),
    page.locator('form#form button[type="submit"]').click(),
  ]);
  expect(guarded.status()).toBe(428);
  await page.waitForURL(/\/mock\/oidc\/authorize\?/, { timeout: 15_000 });
  expect(challenges).toHaveLength(1);
  expect(challenges[0].body.data.provider_id).toBe('sirsoft-sso');
  // 지금 화면으로 돌려보내 달라고 싣는다
  expect(challenges[0].request.return_path).toBe('/mypage/change-password');

  const returnedMemberInfo = waitForMemberInfo(page);
  await decideMockOidc(page, { sub: pwSubject });
  await page.waitForURL((url) => url.pathname === '/mypage/change-password', { timeout: 15_000 });

  // 돌아온 화면에서 다시 저장하면 본인 확인 유예 시간 안이라 통과한다 — 돌아온 화면도 회원 정보를 다시 받으므로 받고 반영된 뒤에 채운다
  await expect(page.locator('input[name="password"]')).toBeVisible({ timeout: 15_000 });
  await expectPasswordFormReady(page, returnedMemberInfo);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('input[name="password_confirmation"]').fill(password);
  const [saved] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/me/password' && r.request().method() === 'PUT'),
    page.locator('form#form button[type="submit"]').click(),
  ]);
  expect(saved.status()).toBe(200);
  expect(memberState(pwEmail).has_password).toBe(true);
});

/**
 * @scenario member=company_account_undeliverable, screen=mypage_change_password|admin_user_form, allow_local_password=false
 * @effects e2e_unsettable_password_notice_on_mypage, e2e_unsettable_password_notice_on_admin_form
 */
test('비밀번호를 허용하지 않는 IdP 면 비밀번호 변경 화면과 관리자 회원 수정 화면이 칸 대신 「만들 수 없습니다」 안내를 보인다', async ({ page, browser }) => {
  sso('idp-put', MOCK_OIDC, JSON.stringify({ allow_local_password: false }));
  await loginWithCompanyAccount(page, subject);

  await page.goto('/mypage/change-password');
  await dismissCookieBanner(page);
  await expect(page.locator('#password_set_unavailable')).toContainText('비밀번호를 만들 수 없습니다', { timeout: 15_000 });
  await expect(page.locator('input[name="password"]')).toHaveCount(0);
  await expect(page.locator('#password_first_time_notice')).toHaveCount(0);
  await expect(page.locator('#password_blocked_back')).toBeVisible();

  // 회사 계정으로만 로그인하는 관리자가 자기 정보를 고치는 화면
  const admin = await browser.newContext();
  const adminPage = await admin.newPage();
  try {
    await adminPage.goto('/admin/login');
    const button = adminPage.locator(`#sso_admin_login_button_${MOCK_OIDC}`);
    await expect(button).toBeVisible({ timeout: 15_000 });
    await Promise.all([adminPage.waitForURL(/\/mock\/oidc\/authorize\?/), button.click()]);
    await decideMockOidc(adminPage, { variant: 'mfa', sub: adminSubject });
    await adminPage.waitForFunction(() => !location.pathname.startsWith('/mock/') && !location.pathname.includes('/oidc/'), undefined, { timeout: 15_000 });
    if (new URL(adminPage.url()).searchParams.has('sso_pending')) {
      await completeConsent(adminPage, 'admin');
    }
    await adminPage.waitForFunction(() => location.pathname.startsWith('/admin') && !location.pathname.startsWith('/admin/login'), undefined, { timeout: 15_000 });

    const uuid = memberState(adminEmail).uuid;
    expect(uuid).toBeTruthy();
    await adminPage.goto(`/admin/users/${uuid}/edit`);
    await expect(adminPage.locator('#password_unsettable_notice')).toContainText('비밀번호를 만들 수 없습니다', { timeout: 15_000 });
    await expect(adminPage.locator('input[name="password"]')).toHaveCount(0);
    await expect(adminPage.locator('#password_reauth_verification')).toHaveCount(0);
  } finally {
    await admin.close();
  }
});
