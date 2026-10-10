/**
 * 환경설정 > 보안 「활동 로그(접속기록) 보관」 카드 종단 검증 (#736 D14).
 *
 * 법정 최소(365일) 미만·상한(3650일) 초과는 422 로 칸 아래에 문구가 나오고, 범위 안 값은 저장된 뒤 새로고침해도 남는다.
 * 사이트 보안 설정을 바꾸므로 비밀번호 만료 fixture 의 보안 설정 스냅샷을 떠 두고 afterAll 에서 되돌린다(serial).
 *
 * // @scenario entry_point=settings_save, retention_source=setting
 * // @effects retention_setting_bounds_enforced
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import { restorePasswordExpiryFixture, runSeedPasswordExpiry } from '../../fixtures/password-expiry';

test.describe.configure({ mode: 'serial' });

let token: string;

test.beforeAll(() => {
  token = issueToken('core.settings.read', 'core.settings.update');
  runSeedPasswordExpiry(['--snapshot-settings']);
});

test.afterAll(() => {
  restorePasswordExpiryFixture();
});

const FIELD = '#field_activity_log_retention_days';

/** 보안 탭 진입 — 설정 조회가 폼에 들어온 뒤까지 기다린다 */
async function gotoSecurityTab(page: import('@playwright/test').Page): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/settings?tab=security');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#card_activity_log_retention')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator(`${FIELD} input`)).not.toHaveValue('', { timeout: 20_000 });
}

/** 보관 기간을 넣고 저장 응답을 받는다 */
async function saveRetention(page: import('@playwright/test').Page, value: string) {
  await page.fill(`${FIELD} input`, value);
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/admin/settings') && r.request().method() === 'POST'),
    page.click('#save_button'),
  ]);
  return response;
}

/**
 * @scenario entry_point=settings_save, retention_source=setting
 * @effects retention_setting_bounds_enforced
 */
test('카드가 기본값과 법정 안내를 보여 준다', async ({ page }) => {
  await gotoSecurityTab(page);

  const input = page.locator(`${FIELD} input`);
  await expect(input).toHaveAttribute('min', '365');
  await expect(input).toHaveAttribute('max', '3650');
  await expect(page.locator('#activity_log_retention_legal_notice')).toContainText('3년');
});

/**
 * @scenario entry_point=settings_save, retention_source=setting
 * @effects retention_setting_bounds_enforced
 */
test('365 미만·3650 초과는 422 로 칸 아래에 표시되고 범위 안 값은 정수로 저장돼 새로고침 뒤에도 남는다', async ({ page }) => {
  await gotoSecurityTab(page);

  const tooShort = await saveRetention(page, '364');
  expect(tooShort.status()).toBe(422);
  expect(Object.keys((await tooShort.json())?.errors ?? {})).toContain('security.activity_log_retention_days');
  await expect(page.locator(`${FIELD} .form-error`)).toContainText('365');

  const tooLong = await saveRetention(page, '3651');
  expect(tooLong.status()).toBe(422);
  await expect(page.locator(`${FIELD} .form-error`)).toBeVisible();

  const saved = await saveRetention(page, '730');
  expect(saved.status(), await saved.text()).toBe(200);
  await expect(page.locator(`${FIELD} .form-error`)).toHaveCount(0);

  await page.reload();
  await expect(page.locator('#card_activity_log_retention')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator(`${FIELD} input`)).toHaveValue('730', { timeout: 20_000 });
});
