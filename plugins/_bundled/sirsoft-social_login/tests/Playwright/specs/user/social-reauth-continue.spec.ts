/**
 * 대체 이메일 회원의 본인 확인 종단 검증 — 이메일 없이 소셜 로그인으로 가입해 메일을 받을 수 없고 비밀번호도 없는 회원.
 *
 * 1. 마이페이지 > 내 정보 수정에서 「본인 확인 계속하기」를 누르면 연결한 제공자(모의 카카오) 동의 화면으로 갔다가 돌아와 정보 수정 폼이
 *    열린다. 종전에는 「본인 확인 화면으로 이동할 수 없습니다」 안내로 끝났다.
 * 2. 비밀번호 변경 화면에서 처음 비밀번호를 저장할 때 본인 확인(428)이 연결한 제공자로 가고 비밀번호 변경 화면으로 돌아온다 — 종전에는
 *    첫 화면으로 떨어졌다. 돌아와 다시 저장하면 통과한다(본인 확인 유예 시간 안).
 * 3. 회사 계정과 소셜을 둘 다 연결한 회원은 본인 확인이 회사 계정으로 가므로, 정보 수정·비밀번호 변경 화면에 「연결한 소셜 계정으로 다시
 *    로그인」 안내와 제공자 버튼이 보이지 않는다(실제 수단과 같은 안내).
 *
 * 전제: 사용자 템플릿 sirsoft-basic, 모의 제공자(`sirsoft-social_login:mock`), 3 은 회사 계정 연동 플러그인의 모의 OIDC IdP 도 쓴다.
 * 운영자가 민감 작업 수단을 따로 지정하지 않은 기본 설정, 비밀번호 변경 본인 확인 정책(설치 기본 켜짐, 유예 5분).
 *
 * 재확인 요청 응답은 화면이 곧바로 제공자 화면으로 옮겨 가 응답 본문이 사라지므로, 요청을 가로채 본문을 먼저 받아 둔다.
 * 모의 OIDC IdP 행의 칸은 모의 IdP 를 켜기 전 값으로 끝에 되돌린다.
 */
import { test, expect, type Page, type Response, type Route } from '@playwright/test';
import { authenticatePage } from '../../../../../../../tests/Playwright/fixtures/auth';
import { approveMock, dismissCookieBanner, members, mockOff, mockOn, runId } from '../../fixtures/mock-provider';
import { MOCK_OIDC, mockOff as ssoMockOff, mockOidcOn, sso } from '../../../../../sirsoft-sso/tests/Playwright/fixtures/sso';

test.describe.configure({ mode: 'serial' });

const id = runId();
const email = `mock_kakao_${id}_e2e40@social.invalid`;
const subject = `e2e-reauth-kakao-${id}`;
const pwEmail = `mock_kakao_${id}pw_e2e40@social.invalid`;
const pwSubject = `e2e-reauth-kakao-pw-${id}`;
// 두 플러그인 시험 도구가 모두 받아들이는 이메일(회사 계정 도구는 e2e798. 접두어, 소셜 도구는 _e2e40 포함)
const bothEmail = `e2e798.both_e2e40_${id}@sso.invalid`;
const bothCompanySubject = `e2e798-both-${id}`;
const bothKakaoSubject = `e2e-reauth-kakao-both-${id}`;
let token: string;
let pwToken: string;
let bothToken: string;
/** 모의 OIDC IdP 를 켜기 전 IdP 칸 (행이 없던 사이트면 null) */
let idpOriginal: Record<string, unknown> | null = null;

test.beforeAll(() => {
  members('purge');
  sso('purge');
  members('member', email);
  members('link', email, 'mock_kakao', subject);
  token = members('token', email);
  members('member', pwEmail);
  members('link', pwEmail, 'mock_kakao', pwSubject);
  pwToken = members('token', pwEmail);
  mockOn();

  try {
    idpOriginal = JSON.parse(sso('idp-get', MOCK_OIDC));
  } catch {
    idpOriginal = null;
  }
  mockOidcOn();
  // 비밀번호 변경 화면의 본인 확인 자리는 비밀번호를 만들 수 있는 회원에게만 보인다 — 회사 계정 IdP 가 이 사이트 비밀번호를 허용하게 둔다
  sso('idp-put', MOCK_OIDC, JSON.stringify({ allow_local_password: true }));
  sso('member', bothEmail, '-');
  sso('link', bothEmail, MOCK_OIDC, bothCompanySubject);
  members('link', bothEmail, 'mock_kakao', bothKakaoSubject);
  bothToken = members('token', bothEmail);
});

test.afterAll(() => {
  members('purge');
  sso('purge');
  if (idpOriginal) {
    sso('idp-put', MOCK_OIDC, JSON.stringify(idpOriginal));
  }
  ssoMockOff();
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
 * @scenario member=placeholder_passwordless, entry=core_challenge_continue
 * @effects e2e_reauth_continue_goes_to_linked_provider, e2e_reauth_continue_roundtrip_opens_profile_form
 */
test('「본인 확인 계속하기」 는 연결한 제공자 다시 로그인으로 가고, 돌아오면 정보 수정 폼이 열린다', async ({ page }) => {
  const challenges = await captureChallenges(page);
  await authenticatePage(page, token);
  await page.goto('/mypage/profile/edit');
  await dismissCookieBanner(page);

  const continueButton = page.locator('#reauth_continue_button');
  await expect(continueButton).toBeVisible({ timeout: 15_000 });

  await continueButton.click();
  await expect(page.locator('select[name="variant"]')).toBeVisible({ timeout: 15_000 });
  expect(challenges).toHaveLength(1);
  expect(challenges[0].status).toBe(201);
  expect(challenges[0].body.data.provider_id).toBe('sirsoft-social_login');
  expect(challenges[0].body.data.render_hint).toBe('external_redirect');
  expect(challenges[0].body.data.redirect_url).toMatch(/^\/plugins\/sirsoft-social_login\/reauth\/[A-Za-z0-9]{40}$/);

  await approveMock(page, { subject });

  await page.waitForURL(/\/mypage\/profile\/edit/, { timeout: 15_000 });
  await expect(page.locator('input[name="name"]')).toBeVisible({ timeout: 15_000 });
  await expect(continueButton).toHaveCount(0);
  await expect(page.locator('body')).not.toContainText('본인 확인 화면으로 이동할 수 없습니다');
});

/**
 * @scenario member=placeholder_passwordless, entry=password_first_set_428
 * @effects e2e_password_reauth_returns_to_change_password, e2e_password_saved_after_social_return
 */
test('처음 비밀번호 저장의 본인 확인은 연결한 제공자로 가고, 비밀번호 변경 화면으로 돌아와 다시 저장하면 통과한다', async ({ page }) => {
  const challenges = await captureChallenges(page);
  await authenticatePage(page, pwToken);
  const memberInfo = waitForMemberInfo(page);
  await page.goto('/mypage/change-password');
  await dismissCookieBanner(page);
  await expect(page.locator('input[name="password"]')).toBeVisible({ timeout: 15_000 });
  // 회원 정보를 받기 전에 채우고 누르면 저장이 나가지 않는다 — 받고 화면이 반영할 때까지 기다린다
  await expectPasswordFormReady(page, memberInfo);

  const password = `E2e!${Math.random().toString(36).slice(2, 10)}${Date.now().toString(36)}`;
  await page.locator('input[name="password"]').fill(password);
  await page.locator('input[name="password_confirmation"]').fill(password);
  const [guarded] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/me/password' && r.request().method() === 'PUT'),
    page.locator('form#form button[type="submit"]').click(),
  ]);
  expect(guarded.status()).toBe(428);
  await expect(page.locator('select[name="variant"]')).toBeVisible({ timeout: 15_000 });
  expect(challenges).toHaveLength(1);
  expect(challenges[0].body.data.provider_id).toBe('sirsoft-social_login');
  // 지금 화면으로 돌려보내 달라고 싣는다
  expect(challenges[0].request.return_path).toBe('/mypage/change-password');

  const returnedMemberInfo = waitForMemberInfo(page);
  await approveMock(page, { subject: pwSubject });
  await page.waitForURL((url) => url.pathname === '/mypage/change-password', { timeout: 15_000 });
  expect(new URL(page.url()).searchParams.has('social_reauth')).toBe(true);
  await expect(page.locator('#social_change_password_reauth_done')).toBeVisible({ timeout: 15_000 });
  // 돌아온 화면도 새로 열린 화면이라 회원 정보를 다시 받는다 — 받고 반영된 뒤에 다시 저장한다
  await expectPasswordFormReady(page, returnedMemberInfo);

  await page.locator('input[name="password"]').fill(password);
  await page.locator('input[name="password_confirmation"]').fill(password);
  const [saved] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/me/password' && r.request().method() === 'PUT'),
    page.locator('form#form button[type="submit"]').click(),
  ]);
  expect(saved.status()).toBe(200);
});

/**
 * @scenario member=company_and_social, screen=mypage_profile_edit|mypage_change_password
 * @effects e2e_both_linked_member_sees_no_social_wording
 */
test('회사 계정과 소셜을 둘 다 연결한 회원에게는 소셜 다시 로그인 안내·제공자 버튼이 보이지 않고, 「본인 확인 계속하기」 는 회사 계정으로 간다', async ({ page }) => {
  const challenges = await captureChallenges(page);
  await authenticatePage(page, bothToken);

  await page.goto('/mypage/change-password');
  await dismissCookieBanner(page);
  await expect(page.locator('#social_change_password_reauth_other')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('body')).not.toContainText('연결한 소셜 계정으로');
  await expect(page.locator('#social_change_password_reauth_buttons')).toHaveCount(0);

  await page.goto('/mypage/profile/edit');
  await dismissCookieBanner(page);
  const continueButton = page.locator('#reauth_continue_button');
  await expect(continueButton).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#social_reauth_not_eligible')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#reauth_alternative_section')).not.toContainText('연결한 소셜 계정으로');
  await expect(page.locator('#social_reauth_buttons')).toHaveCount(0);

  await Promise.all([page.waitForURL(/\/mock\/oidc\/authorize\?/, { timeout: 15_000 }), continueButton.click()]);
  expect(challenges).toHaveLength(1);
  expect(challenges[0].body.data.provider_id).toBe('sirsoft-sso');
});
