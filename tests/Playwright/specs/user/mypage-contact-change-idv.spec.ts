/**
 * 마이페이지 정보 수정 — 연락처 변경 본인인증 종단 검증 (#736 D21).
 *
 * 기본 켜진 정책 `core.profile.contact_change` 가 실제로 발동하는지 본다 — 종전에는 바뀐 항목을 저장 전 모델에서 읽어
 * 늘 빈 목록이 되어 휴대폰·전화·이메일을 바꿔도 본인인증 없이 저장됐다.
 *
 * - 휴대폰 번호만 바꿔 저장 → 428, 코어 인터셉터가 본인인증을 시작(`POST /api/identity/challenges`)하고 값은 저장되지 않는다.
 *   창이 실제로 뜨는지는 인증 코드 메일 발송이 되는 환경에서만 성립한다(로컬 SMTP 미설정이면 시작 요청이 502 이고 「인증 코드를
 *   보내지 못했습니다」 토스트가 뜬다) — 그래서 시작 요청이 나갔는지로 잰다. 창·코드 입력은 §8.6 실측이 메일을 로그 발송으로 바꿔 지난다.
 * - 닉네임만 바꿔 저장 → 200, 창 없이 저장된다.
 *
 * 인증 코드 입력 → 원 요청 재실행(200)은 메일 로그에서 코드를 읽어야 해 브라우저 실측(§8.6 T3)과 코어 PHPUnit
 * `ContactChangeIdentityHttpTest::retry_with_verification_token_passes` 가 덮는다. 시드 계정은 afterAll 에서 회수한다.
 *
 * // @scenario entry_point=update_profile, changed=mobile|nickname, idv=none, actor=member
 * // @effects contact_change_requires_identity_verification, non_contact_changes_pass_without_verification
 */
import { test, expect } from '../../fixtures/auth';
import { authenticatePage } from '../../fixtures/auth';
import { restorePasswordExpiryFixture, runSeedPasswordExpiry, seedAccount } from '../../fixtures/password-expiry';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  runSeedPasswordExpiry(['--snapshot-settings']);
});

test.afterAll(() => {
  restorePasswordExpiryFixture();
});

/** 정보 수정 화면 진입 — 비밀번호 재확인을 지나 편집 폼까지 */
async function openProfileEdit(page: import('@playwright/test').Page, password: string): Promise<void> {
  await page.goto('/mypage/profile/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const verify = page.locator('#verifyPasswordForm input[name="password"]');
  await expect(verify).toBeVisible({ timeout: 20_000 });
  await verify.fill(password);
  await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/me/verify-password') && r.request().method() === 'POST'),
    // 반응형 사본 버튼이 있어 선택자 클릭이 숨은 쪽에 갈 수 있다 — 칸에서 Enter 로 폼을 제출한다.
    verify.press('Enter'),
  ]);

  await expect(page.locator('#profileEditForm input[name="mobile"]')).toBeVisible({ timeout: 20_000 });
}

/** 편집 폼을 저장하고 응답을 받는다 */
async function saveProfile(page: import('@playwright/test').Page) {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/me') && r.request().method() === 'PUT'),
    page.locator('#profileEditForm button[type="submit"]').last().click(),
  ]);
  return response;
}

/**
 * @scenario entry_point=update_profile, changed=mobile, idv=none, actor=member
 * @effects contact_change_requires_identity_verification
 */
test('휴대폰 번호만 바꾸면 428 과 함께 본인인증이 시작되고 값은 저장되지 않는다', async ({ page }) => {
  const account = seedAccount('fresh', { issueToken: true });
  await authenticatePage(page, account.otherDeviceToken!);
  await openProfileEdit(page, account.password);

  await page.fill('#profileEditForm input[name="mobile"]', '010-7364-2021');
  const challengeStarted = page.waitForRequest(
    (r) => r.url().includes('/api/identity/challenges') && r.method() === 'POST',
    { timeout: 15_000 },
  );
  const response = await saveProfile(page);

  expect(response.status(), await response.text()).toBe(428);
  const body = await response.json();
  expect(body?.error_code).toBe('identity_verification_required');
  expect(body?.verification?.policy_key ?? body?.policy_key ?? JSON.stringify(body)).toContain('core.profile.contact_change');
  // 코어 인터셉터가 428 을 받아 본인인증을 시작했다(창을 띄우는 launcher 진입).
  await challengeStarted;

  const me = await page.evaluate(async (token) => {
    const r = await fetch('/api/me', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    return (await r.json())?.data?.mobile ?? null;
  }, account.otherDeviceToken!);
  expect(me).not.toBe('010-7364-2021');
});

/**
 * @scenario entry_point=update_profile, changed=nickname, idv=none, actor=member
 * @effects non_contact_changes_pass_without_verification
 */
test('닉네임만 바꾸면 본인인증 없이 저장된다', async ({ page }) => {
  const account = seedAccount('fresh', { issueToken: true });
  await authenticatePage(page, account.otherDeviceToken!);
  await openProfileEdit(page, account.password);

  await page.fill('#profileEditForm input[name="nickname"]', `d21-${Date.now() % 100000}`);
  const response = await saveProfile(page);

  expect(response.status(), await response.text()).toBe(200);
  await expect(page.getByRole('dialog')).toHaveCount(0);
});
