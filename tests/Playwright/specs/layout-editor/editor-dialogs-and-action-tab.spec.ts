/**
 * Layout Editor — 편집기 확인 대화상자 · [동작] 탭 원시값 표기 · 디바운스 혼재 경고 (7.1.0 P18·O-I2F-4·P13, engine-v1.78.7)
 *
 *  - P18: 커스텀 자산 창에 미저장 본문이 있을 때 Esc → 브라우저 기본 confirm 이 아니라 편집기 alertdialog.
 *    이름·설명·처음 초점([편집 계속])·대화상자 Esc 는 대화상자만 닫음(본문 유지)·[변경 사항 버리기] → 창 닫힘.
 *  - O-I2F-4: 원시값 onChange 부품(활동 로그 「행위자 선택」 SearchableDropdown)의 「값이 바뀌면」에 붙인 동작은
 *    `event: "onChange"` 로 적힌다.
 *  - P13: 디바운스 api-call 이 있는 「검색어를 입력하면」 칸에 바로 실행 동작을 더하면 칸에 경고(role=note)가 뜬다.
 *  - 저장하지 않는다(편집 상태는 페이지와 함께 버려진다).
 *
 * 축 요약(마커 아님 — 평문): dialog_kind, event_notation.
 * 효과 요약(마커 아님 — 평문): dialog_replaces_native_confirm_and_prompt, dialog_cancel_paths_resolve_cancel, raw_value_slot_writes_event_notation, mixed_debounce_slot_warns.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

async function enter(page: import('@playwright/test').Page, url: string): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(url);
  await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
}

test.describe('@layout-editor 편집기 대화상자·[동작] 탭', () => {
  /** @effects dialog_replaces_native_confirm_and_prompt, dialog_cancel_paths_resolve_cancel */
  test('P18 — 커스텀 자산 미저장 확인은 편집기 대화상자다', async ({ page }) => {
    let nativeDialogs = 0;
    page.on('dialog', async (d) => { nativeDialogs += 1; await d.dismiss(); });
    await enter(page, '/admin/layout-editor/sirsoft-basic');

    await page.getByTestId('g7le-toolbar-custom-assets').click();
    const body = page.getByTestId('g7le-custom-assets-content');
    await body.click();
    await page.keyboard.type('body{color:red}');
    await page.keyboard.press('Escape');

    const dialog = page.getByRole('alertdialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-label', /.+/);
    await expect(page.getByTestId('g7le-dialog-cancel')).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(body).toHaveValue('body{color:red}');

    await body.click();
    await page.keyboard.press('Escape');
    await page.getByTestId('g7le-dialog-confirm').click();
    await expect(body).toHaveCount(0);
    expect(nativeDialogs).toBe(0);
  });

  /** @effects raw_value_slot_writes_event_notation, mixed_debounce_slot_warns */
  test('O-I2F-4·P13 — 원시값 onChange 는 event 로 적고, 디바운스 혼재 칸에 경고', async ({ page }) => {
    await enter(page, '/admin/layout-editor/sirsoft-admin_basic');
    await page.locator('[data-testid="g7le-route-tree-search"] input, input[data-testid="g7le-route-tree-search"]').fill('activity');
    await page.getByTestId('g7le-route-tree-item').filter({ hasText: 'admin_activity_log_list' }).first().click();
    const sd = page.locator('[data-editor-name="SearchableDropdown"]').first();
    await expect(sd).toBeAttached({ timeout: 30_000 });
    const path = await sd.getAttribute('data-editor-path');
    await page.getByTestId(`g7le-dnd-handle-${path}`).click();
    await page.keyboard.press('Enter');
    await page.getByTestId('g7le-property-tab-action').click();

    // O-I2F-4
    await page.getByTestId('g7le-action-slot-onChange-add-picker-toggle').click();
    const changeSlot = page.getByTestId('g7le-action-event-onChange');
    await changeSlot.locator('[data-testid="g7le-init-action-spec-setState"]').first().click();
    const items = page.locator('[data-testid^="g7le-action-slot-onChange-item-"]');
    const last = (await items.count()) - 1;
    await page.getByTestId(`g7le-action-slot-onChange-code-${last}`).click();
    await expect(page.locator('pre, code, textarea').filter({ hasText: '"event": "onChange"' }).last()).toBeVisible();

    // P13
    await expect(page.getByTestId('g7le-action-slot-onSearch-mixed-debounce')).toHaveCount(0);
    await page.getByTestId('g7le-action-slot-onSearch-add-picker-toggle').click();
    await page.getByTestId('g7le-action-event-onSearch').locator('[data-testid="g7le-init-action-spec-setState"]').first().click();
    const warning = page.getByTestId('g7le-action-slot-onSearch-mixed-debounce');
    await expect(warning).toHaveAttribute('role', 'note');
    await expect(page.getByTestId('g7le-action-slot-onChange-mixed-debounce')).toHaveCount(0);
  });
});
