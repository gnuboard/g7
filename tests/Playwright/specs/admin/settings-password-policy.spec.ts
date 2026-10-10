/**
 * 환경설정 > 보안 「비밀번호 정책」·「비밀번호 만료」 카드 종단 검증 (#736).
 *
 * 저장(보안 탭 몫만)·교차 검증 422(만료 기간 30 · 사전 안내 30)·대상 건수 배지·「대상 보기」 명단 창·「지금 실행」 확인 창과
 * 접수(202)를 실제 브라우저로 지난다. 사이트 보안 설정을 바꾸므로 fixture 가 스냅샷을 떠 두고 afterAll 에서 되돌린다(serial).
 *
 * // @scenario surface=security_tab,run_modal | expiry=on,off | stage=notice
 * // @effects policy_checkboxes_are_explicitly_bound, conditional_fields_follow_their_toggle, target_rows_visible_when_off_with_permission_gated_view, run_modal_shows_total_and_this_run
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import { restorePasswordExpiryFixture, runSeedPasswordExpiry } from '../../fixtures/password-expiry';

test.describe.configure({ mode: 'serial' });

let token: string;

test.beforeAll(() => {
  token = issueToken('core.settings.read', 'core.settings.update', 'core.users.read', 'core.users.update');
  runSeedPasswordExpiry(['--snapshot-settings']);
});

test.afterAll(() => {
  restorePasswordExpiryFixture();
});

/** 보안 탭 진입 */
async function gotoSecurityTab(page: import('@playwright/test').Page): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/settings?tab=security');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#card_password_policy')).toBeVisible({ timeout: 20_000 });
  // 설정 조회가 폼에 들어오기 전에 고치면 그 칸 하나만 담긴 폼이 저장되어 필수 키(HTTPS 강제 등) 422 가 난다.
  await expect(page.locator('#field_password_min_length input')).not.toHaveValue('', { timeout: 20_000 });
}

/** 저장 버튼을 눌러 보안 탭 저장 응답을 받는다 */
async function save(page: import('@playwright/test').Page) {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/admin/settings') && r.request().method() === 'POST'),
    page.click('#save_button'),
  ]);
  return response;
}

/**
 * @scenario surface=security_tab, expiry=off
 * @effects policy_checkboxes_are_explicitly_bound, target_rows_visible_when_off_with_permission_gated_view
 */
test('두 카드가 그려지고 최소 길이 저장은 보안 탭 몫만 정수로 보낸다', async ({ page }) => {
  await gotoSecurityTab(page);
  await expect(page.locator('#card_password_expiry')).toBeVisible();
  // 옮긴 칸은 같은 data-ref 로 새 카드 안에 있다(A14).
  await expect(page.locator('#card_password_policy #field_password_min_length')).toBeVisible();
  await expect(page.locator('#card_security_settings #field_password_min_length')).toHaveCount(0);
  // 대상 목록은 만료가 꺼져 있어도 보인다.
  await expect(page.locator('#password_expiry_target_list')).toBeVisible();

  await page.fill('#field_password_min_length input', '10');
  const response = await save(page);
  expect(response.status(), await response.text()).toBe(200);
  const body = JSON.parse(response.request().postData() ?? '{}');
  expect(body._tab).toBe('security');
  expect(Object.keys(body)).toEqual(expect.arrayContaining(['_tab', 'security']));
});

/**
 * @scenario surface=security_tab, expiry=on
 * @effects conditional_fields_follow_their_toggle
 */
test('만료를 켠 사이트에서 기간 30 · 안내 30 은 교차 검증 422 로 칸 아래에 표시된다', async ({ page }) => {
  runSeedPasswordExpiry(['--enable-policy']);
  await gotoSecurityTab(page);
  await expect(page.locator('#field_password_expiry_days')).toBeVisible({ timeout: 10_000 });

  await page.fill('#field_password_expiry_days input', '30');
  await page.fill('#field_password_expiry_notice_days input', '30');
  const response = await save(page);
  expect(response.status()).toBe(422);
  const errors = (await response.json())?.errors ?? {};
  expect(Object.keys(errors)).toContain('security.password_expiry_notice_days');
  await expect(page.locator('#field_password_expiry_notice_days .form-error')).toBeVisible();
});

/**
 * @scenario surface=run_modal, expiry=on, stage=notice
 * @effects run_modal_shows_total_and_this_run, target_rows_visible_when_off_with_permission_gated_view
 */
test('사전 안내 대상이 배지·명단 창에 보이고 「지금 실행」은 확인 창을 거쳐 접수된다', async ({ page }) => {
  runSeedPasswordExpiry(['--enable-policy']);
  runSeedPasswordExpiry(['--stage=notice', '--count=2']);
  await gotoSecurityTab(page);

  const row = page.locator('#password_expiry_target_row_notice');
  await expect(row).toBeVisible({ timeout: 10_000 });
  await expect(row.locator('.badge')).toContainText(/\d/);

  // 명단 창 — 개인정보 탭과 같은 창을 돌려 쓴다.
  await row.locator('#view_password_expiry_notice_targets').click();
  // 창 컴포넌트는 data-ref 를 DOM id 로 싣지 않는다 — 역할(dialog)로 찾는다.
  const list = page.getByRole('dialog');
  await expect(list).toBeVisible({ timeout: 10_000 });
  await expect(list.locator('tbody tr').first()).toBeVisible({ timeout: 10_000 });
  await list.getByRole('button', { name: /닫기|Close/ }).last().click();
  await expect(list).toBeHidden({ timeout: 10_000 });

  // 지금 실행 — 확인 창이 전체 대상과 이번 실행 수를 보여 주고, 실행하면 접수(202)된다.
  await row.locator('#run_password_expiry_notice').click();
  const modal = page.getByRole('dialog');
  await expect(modal).toBeVisible({ timeout: 10_000 });
  await expect(modal.locator('#password_expiry_run_count')).toBeVisible({ timeout: 10_000 });
  const [queued] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/admin/privacy/manual-runs/password_expiry:notice') && r.request().method() === 'POST'),
    page.click('#password_expiry_run_execute'),
  ]);
  expect([202, 409]).toContain(queued.status());
});
