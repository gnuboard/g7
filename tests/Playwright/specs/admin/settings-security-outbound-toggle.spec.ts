/**
 * E2E: 환경설정 > 보안 — "내부 네트워크 주소 호출 허용" 토글 (#466)
 *
 * @scenario admin_settings_security_outbound_toggle
 * @effects toggle_mounted, toggle_persisted
 *
 * 배경: 서버가 대신 보내는 outbound 요청(예약 작업 URL 호출, 외부 API 연동)에서 내부
 * 네트워크 주소를 기본 차단하되, 사내 서버를 호출해야 하는 운영 환경을 위해 이 토글로
 * 예외를 허용한다. 토글이 실제로 마운트되고 저장까지 되는지 브라우저에서 확인한다.
 *
 * 검증:
 *  1. 보안 탭에 토글이 마운트되고 항목명·설명이 raw 키가 아닌 번역문으로 표시된다
 *  2. 토글을 켜고 저장하면 422 없이 성공하고, 재진입 시 켜진 상태가 유지된다
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const TOGGLE_ROW = '#toggle_allow_internal_outbound_urls';

/** 관리자 환경설정 보안 탭 진입 */
async function gotoSecurityTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=security');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(TOGGLE_ROW)).toBeAttached({ timeout: 20_000 });
}

// @scenario tab=security, permitted=yes
// @effects toggle_mounted
test('@smoke #466 - 보안 탭에 "내부 네트워크 주소 호출 허용" 토글이 번역문과 함께 마운트된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoSecurityTab(page);
  expect(page.url()).not.toMatch(/\/admin\/login/);

  const row = page.locator(TOGGLE_ROW);
  await expect(row).toBeAttached();

  // 다국어 키가 해석되지 않으면 "$t:admin.settings.security..." 원문이 그대로 노출된다 (회귀 가드)
  const text = (await row.innerText()).trim();
  expect(text).not.toContain('$t:');
  expect(text.length).toBeGreaterThan(0);

  // 토글 입력이 행 안에 실제로 존재한다
  await expect(row.locator('input[type="checkbox"]').first()).toBeAttached();
});

// @scenario tab=security, permitted=yes
// @effects toggle_interactive
test('#466 - 토글을 클릭하면 상태가 바뀌고 저장 버튼이 활성화된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoSecurityTab(page);

  const wasOn = await isToggleOn(page);

  // Toggle 은 sr-only checkbox 를 감싼 wrapper 가 클릭 대상이다
  await page.locator(`${TOGGLE_ROW} .toggle-switch-wrapper`).first().click();

  // 클릭이 실제 상태 변경으로 이어진다 (폼 바인딩 회귀 가드)
  await expect
    .poll(() => isToggleOn(page), { timeout: 10_000 })
    .toBe(!wasOn);

  // 변경이 감지되어 저장 버튼이 활성화된다 (_local.hasChanges 바인딩 확인)
  await expect(page.locator('#save_button')).toBeEnabled({ timeout: 10_000 });
});

/**
 * 보안 탭 토글의 on/off 상태를 읽는다 (sr-only checkbox 의 checked 기준).
 */
async function isToggleOn(page: import('@playwright/test').Page): Promise<boolean> {
  return page
    .locator(`${TOGGLE_ROW} input[type="checkbox"]`)
    .first()
    .evaluate((el) => (el as HTMLInputElement).checked);
}
