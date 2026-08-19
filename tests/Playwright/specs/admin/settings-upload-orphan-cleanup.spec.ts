/**
 * E2E: 환경설정 > 업로드 — "고아 첨부 정리" 카드 (공개 #115)
 *
 * @scenario admin_settings_upload_orphan_cleanup
 * @effects core_settings_orphan_toggle_default_off, core_settings_orphan_keys_declared
 *
 * 배경: 폼을 저장하지 않고 나가 소유자 없이 남은 첨부를 매일 파기하는 기능의 운영자 스위치다.
 * 사용자 파일을 실제로 지우는 설정이라 "기본 꺼짐" 이 인수 기준이고, 그 상태는 서버 기본값과
 * 화면 렌더가 함께 맞아야 성립한다. 단위 테스트(admin-settings-orphan-cleanup)는 레이아웃 JSON
 * 의 바인딩 키만 검증하므로, 실제 값이 실려 렌더되는지는 브라우저로만 확인된다.
 *
 * 검증:
 *  1. 업로드 탭에 카드가 마운트되고 항목명·설명이 raw 키가 아닌 번역문으로 표시된다
 *  2. 자동 정리 토글이 기본 꺼짐으로 보인다 (설정을 만지지 않은 사이트의 인수 기준)
 *  3. 보존기간에 0 을 넣고 저장하면 422 로 거절되고, 안내 문구에 항목명이 사람 말로 나온다
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const CARD = '#card_orphan_cleanup';
const TOGGLE_ROW = '#field_orphan_cleanup_enabled';
const RETENTION_ROW = '#field_orphan_retention_days';

/** 관리자 환경설정 업로드 탭 진입 */
async function gotoUploadTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=upload');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(CARD)).toBeAttached({ timeout: 20_000 });
}

// @scenario tab=upload, permitted=yes
// @effects core_settings_orphan_keys_declared
test('#115 - 업로드 탭에 "고아 첨부 정리" 카드가 번역문과 함께 마운트된다', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });

  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoUploadTab(page);
  expect(page.url()).not.toMatch(/\/admin\/login/);

  const card = page.locator(CARD);
  const text = (await card.innerText()).trim();

  // 다국어 키가 해석되지 않으면 "$t:admin.settings.upload.orphan_..." 원문이 그대로 노출된다.
  expect(text).not.toContain('$t:');
  expect(text).not.toContain('orphan_cleanup');
  expect(text.length).toBeGreaterThan(0);

  await expect(page.locator(TOGGLE_ROW)).toBeAttached();
  await expect(page.locator(RETENTION_ROW)).toBeAttached();

  expect(consoleErrors).toEqual([]);
});

// @scenario tab=upload, permitted=yes
// @effects core_settings_orphan_toggle_default_off
test('#115 - 자동 정리 토글은 기본 꺼짐으로 렌더된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoUploadTab(page);

  const toggle = page.locator(TOGGLE_ROW).locator('input[type="checkbox"]').first();
  await expect(toggle).toBeAttached();

  // 설정을 만지지 않은 사이트에서 자동 삭제 0 이 인수 기준이다 — 화면도 그 상태를 보여야 한다.
  expect(await toggle.isChecked()).toBe(false);
});

// @scenario tab=upload, permitted=yes, value=invalid
// @effects core_settings_orphan_keys_declared
test('#115 - 보존기간 0 은 항목명이 드러난 안내와 함께 거절된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoUploadTab(page);

  const input = page.locator(RETENTION_ROW).locator('input').first();
  await input.fill('0');

  const response = page.waitForResponse(
    (res) => res.url().includes('/api/admin/settings') && res.request().method() === 'POST',
    { timeout: 20_000 },
  );

  await page.getByRole('button', { name: /저장|Save/ }).first().click();

  const res = await response;
  expect(res.status()).toBe(422);

  const body = await res.json();
  const message = JSON.stringify(body);

  // 원시 키(`upload.orphan retention days`)가 그대로 나오면 attributes 정의가 빠진 것이다.
  expect(message).not.toContain('orphan retention days');
});
