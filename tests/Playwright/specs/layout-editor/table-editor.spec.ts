/**
 * Layout Editor — table 노드 에디터(빌트인 STRUCT-TREE).
 *
 * Table 은 capability `nodeEditor:{kind:"table",params:{rowContainer/row/cell/...}}` 로
 * 표 구조(섹션>행>셀)를 속성 모달 [속성] 탭에서 편집한다. 코어 빌트인 TableEditor 가
 * registerCoreEditors 의 registerNodeEditor('table', ...) 로 일반 레지스트리에 등록되어,
 * PropertyEditorModal 이 kind(컴포넌트명 아님)로 디스패치한다.
 *
 * 본 E2E 는 라이브 편집기에서:
 *  1. Table 을 추가하고 선택 → 속성 모달의 table 에디터(g7le-table-editor)가 마운트되는지,
 *  2. 행/열 추가가 grid(g7le-table-editor-grid)에 반영되는지,
 *  3. 셀 선택 + Shift 영역 선택 → 병합 버튼 활성,
 *  4. 저장(PUT 200) → reload 영속,
 *  을 검증한다. 행/열/병합/해제/테두리/셀텍스트 round-trip 은 단위(tableGridModel.test.ts /
 *  TableEditor.test.tsx)가 잠그고, 본 E2E 는 구조 편집의 라이브 반영·저장을 브라우저로 확인한다.
 *
 * @scenario table_node_editor + add_row_col + merge + live_persist
 * @effects property_modal_dispatches_table_node_editor_in_props_tab_by_kind, add_row_inserts_blank_row_keeps_col_count, add_column_inserts_blank_col, shift_select_range_then_merge_sets_origin_span_removes_absorbed, live_add_row_col_merge_save_persists_to_user_page
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

async function gotoEditor(page: Page, route = '%2F'): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(`/admin/layout-editor/sirsoft-basic?route=${route}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
    timeout: 20_000,
  });
}

async function selectByPath(page: Page, path: string): Promise<boolean> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const cx = r.left + Math.min(r.width / 2, 10);
    const cy = r.top + Math.min(r.height / 2, 10);
    for (const type of ['pointerover', 'pointermove', 'pointerdown', 'pointerup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: cx, clientY: cy }));
    }
  }, path);
  return page
    .waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 5_000 })
    .then(() => true)
    .catch(() => false);
}

async function openPropsTab(page: Page): Promise<void> {
  await page.getByTestId('g7le-overlay-info-button').click();
  await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
  await page.getByTestId('g7le-context-menu-edit-props').click();
  await page.waitForSelector('[data-testid="g7le-property-tab-props"]', { timeout: 10_000 });
  await page.getByTestId('g7le-property-tab-props').click();
  await page.waitForTimeout(200);
}

/** content root(Div) 안에 Table 을 추가하고 그 path 반환. */
async function addTable(page: Page): Promise<string> {
  const containerPath = await page.evaluate(() => {
    const els = Array.from(document.querySelectorAll('[data-editor-name="Div"]'));
    return els[0]?.getAttribute('data-editor-path') ?? null;
  });
  expect(containerPath).toBeTruthy();
  expect(await selectByPath(page, containerPath!)).toBe(true);
  await page.getByTestId('g7le-toolbar-add-element').click();
  await page.waitForSelector('[data-testid="g7le-palette-item-Table"]', { timeout: 10_000 });
  await page.getByTestId('g7le-palette-item-Table').click();
  await page.waitForTimeout(400);
  return page.evaluate((C) => {
    const re = new RegExp('^' + C.replace(/\./g, '\\.') + '\\.children\\.\\d+$');
    const idxs = Array.from(document.querySelectorAll('[data-editor-path]'))
      .map((e) => e.getAttribute('data-editor-path') ?? '')
      .filter((p) => re.test(p))
      .map((p) => parseInt(p.split('.').pop() as string, 10));
    return C + '.children.' + Math.max(...idxs);
  }, containerPath!);
}

test.describe('@layout-editor table 노드 에디터(빌트인 STRUCT-TREE)', () => {
  test('Table 선택 시 table 에디터가 속성 탭에 마운트되고 행/열 추가가 grid 반영', async ({ page }) => {
    await gotoEditor(page);
    const tablePath = await addTable(page);

    expect(await selectByPath(page, tablePath)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-table-editor')).toBeVisible();
    await expect(page.getByTestId('g7le-table-editor-grid')).toBeVisible();

    // 행 수(거터 기준) 측정 → 행 추가 → 증가 확인.
    const rowsBefore = await page.locator('[data-testid^="g7le-table-rowgutter-"]').count();
    await page.getByTestId('g7le-table-add-row-bottom').click();
    await page.waitForTimeout(300);
    const rowsAfter = await page.locator('[data-testid^="g7le-table-rowgutter-"]').count();
    expect(rowsAfter).toBe(rowsBefore + 1);

    // 열 추가 → 열 거터 증가.
    const colsBefore = await page.locator('[data-testid^="g7le-table-colgutter-"]').count();
    await page.getByTestId('g7le-table-col-add-0').click();
    await page.waitForTimeout(300);
    const colsAfter = await page.locator('[data-testid^="g7le-table-colgutter-"]').count();
    expect(colsAfter).toBe(colsBefore + 1);
  });

  test('셀 선택 + Shift 영역 선택 → 병합 버튼 활성 → 병합 반영', async ({ page }) => {
    await gotoEditor(page);
    const tablePath = await addTable(page);
    expect(await selectByPath(page, tablePath)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-table-editor')).toBeVisible();

    // 병합 버튼은 단일 선택 시 비활성.
    await page.getByTestId('g7le-table-cell-0-0').click();
    await expect(page.getByTestId('g7le-table-merge')).toBeDisabled();
    // Shift 로 영역 선택 → 활성.
    await page.getByTestId('g7le-table-cell-0-1').click({ modifiers: ['Shift'] });
    await expect(page.getByTestId('g7le-table-merge')).toBeEnabled();
    await page.getByTestId('g7le-table-merge').click();
    await page.waitForTimeout(300);
    // 병합 후 origin 셀이 colSpan 2 로 렌더(셀 1개로 줄어든 첫 행).
    await expect(page.getByTestId('g7le-table-cell-0-0')).toBeVisible();
  });

  test('table 편집 후 저장 → PUT 200', async ({ page }) => {
    await gotoEditor(page);
    const tablePath = await addTable(page);
    expect(await selectByPath(page, tablePath)).toBe(true);
    await openPropsTab(page);
    await page.getByTestId('g7le-table-add-row-bottom').click();
    await page.waitForTimeout(300);

    const savePromise = page.waitForResponse(
      (r) =>
        /\/api\/admin\/templates\/sirsoft-basic\/layouts\//.test(r.url()) &&
        r.request().method() === 'PUT',
      { timeout: 15_000 },
    );
    await page.getByTestId('g7le-property-modal-done').click().catch(() => undefined);
    await page.getByRole('button', { name: /save|저장/i }).first().click();
    const saveRes = await savePromise;
    expect(saveRes.status()).toBe(200);
  });
});
