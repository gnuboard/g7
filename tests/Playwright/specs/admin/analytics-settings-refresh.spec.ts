/**
 * 방문자 통계 › 설정 — 「새로고침」은 고친 값을 버리고, 탭 왕복은 고친 값을 지킨다
 *
 * @scenario-manifest templates/_bundled/sirsoft-admin_basic/tests/scenarios/analytics-settings-refresh.yaml
 * @effects refresh_restores_server_values,
 *          refresh_disables_save,
 *          tab_roundtrip_keeps_edits
 *
 * 배경(#736 브라우저 실측 2026-10-05, 트러블슈팅 state-global 사례 11): 새로고침이 같은 응답을 다시
 * 받으면 엔진이 initLocal 적용을 건너뛰고, 입력칸이 폼 감싸개 상태에 고친 값을 들고 있어 칸·저장 버튼이
 * 그대로였다. 데이터 소스에 refetchOnMount 를 다는 방법은 탭 왕복마다 다시 받아 「여러 탭을 고쳐 두고
 * 한 번에 저장」 흐름을 깨므로(실측 37 → 30), 탭 왕복이 편집을 지키는 것도 함께 잠근다.
 *
 * 저장은 하지 않는다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const FIELD = 'input[name="settings.visit_timeout_minutes"]';

/** 설정 화면 진입 — 폼 적재까지 기다린다 */
async function gotoSettings(page: Page): Promise<string> {
  await page.goto('/admin/analytics/settings');
  await expect
    .poll(async () => await page.evaluate(() => (window as any).G7Core?.state?.getLocal?.()?.form?.settings !== undefined), { timeout: 20_000 })
    .toBe(true);
  await expect(page.locator(FIELD)).toBeVisible({ timeout: 20_000 });

  return await page.locator(FIELD).inputValue();
}

/** 서버값과 다른 허용 범위(5~120) 안의 값 */
function other(current: string): string {
  return current.trim() === '37' ? '41' : '37';
}

/** 실제 타이핑으로 값을 바꾼다 */
async function typeInto(page: Page, value: string): Promise<void> {
  const input = page.locator(FIELD);
  await input.click();
  await input.press('ControlOrMeta+a');
  await input.pressSequentially(value, { delay: 30 });
}

function saveButton(page: Page) {
  return page.locator('button:visible', { hasText: /^저장$/ }).last();
}

test.describe('분석 설정 새로고침', () => {
  /**
   * @scenario action=refresh
   *
   * @effects refresh_restores_server_values, refresh_disables_save
   */
  test('칸을 고친 뒤 새로고침하면 서버 값으로 돌아오고 저장 버튼이 꺼진다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    const server = await gotoSettings(page);

    await typeInto(page, other(server));
    await expect(saveButton(page)).toBeEnabled();

    await page.getByRole('button', { name: '새로고침' }).first().click();

    await expect(page.locator(FIELD)).toHaveValue(server, { timeout: 10_000 });
    await expect(saveButton(page)).toBeDisabled();
    expect(await page.evaluate(() => (window as any).G7Core.state.getLocal()?.hasChanges)).not.toBe(true);
  });

  /**
   * @scenario action=tab_roundtrip
   *
   * @effects tab_roundtrip_keeps_edits
   */
  test('칸을 고친 뒤 다른 탭에 갔다 오면 고친 값과 저장 버튼 활성이 남는다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    const server = await gotoSettings(page);
    const edited = other(server);

    await typeInto(page, edited);
    await expect(saveButton(page)).toBeEnabled();

    await page.getByRole('tab').nth(1).click();
    await expect(page.locator(FIELD)).toHaveCount(0, { timeout: 10_000 });
    await page.getByRole('tab').nth(0).click();

    await expect(page.locator(FIELD)).toHaveValue(edited, { timeout: 10_000 });
    await expect(saveButton(page)).toBeEnabled();
  });
});
