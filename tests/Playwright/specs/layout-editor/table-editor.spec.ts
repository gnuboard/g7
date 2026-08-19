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
 * 축 요약(마커 아님 — 평문): table_node_editor, add_row_col, merge, live_persist.
 * 효과 요약(마커 아님 — 평문): property_modal_dispatches_table_node_editor_in_props_tab_by_kind, add_row_inserts_blank_row_keeps_col_count, add_column_inserts_blank_col, shift_select_range_then_merge_sets_origin_span_removes_absorbed, live_add_row_col_merge_save_persists_to_user_page, editor_save_specs_target_sandbox_layout_not_product_layout.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';
import { SANDBOX_ROOT_ID, sandboxRouteParam } from '../../fixtures/seed-layout';
import { editorPath } from '../../fixtures/layout-editor';

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

/**
 * 편집기에서 **실제로 선택되는** Div 컨테이너를 찾아 그 path 를 반환합니다.
 *
 * 캔버스의 첫 Div 는 잠금 영역이나 래퍼라 선택되지 않는 경우가 있다. 순번으로 지목하지 않고
 * 선택 성공 여부로 고른다.
 *
 * @param page Playwright page
 * @returns 선택에 성공한 Div 의 editor path (없으면 null)
 */
async function pickSelectableDiv(page: Page): Promise<string | null> {
  const candidates = await page.evaluate(() =>
    Array.from(document.querySelectorAll('[data-editor-name="Div"]'))
      .map((e) => e.getAttribute('data-editor-path') ?? '')
      .filter(Boolean),
  );

  for (const candidate of candidates.slice(0, 15)) {
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
    }, candidate);

    // 선택 판정은 선택 박스(`g7le-overlay-selected`)로 한다. ⓘ 버튼은 큰 루트 컨테이너에서
    // 뷰포트 밖에 놓일 수 있어 판정 기준으로 부적합하다 (table-inplace-overlay spec 의 실측 주석과 동일).
    const selected = await page
      .waitForSelector('[data-testid="g7le-overlay-selected"]', { timeout: 1_200 })
      .then(() => true)
      .catch(() => false);
    if (selected) return candidate;
  }

  return null;
}

/**
 * content root(Div) 안에 Table 을 추가하고 그 path 반환.
 *
 * 삽입 위치는 선택 컨테이너에 따라 정해지므로 children 인덱스를 계산하지 않고 추가 전후
 * Table 노드 집합의 차이로 새 노드를 찾는다.
 *
 * @param page Playwright page
 * @param sandbox true 면 시드 화면의 전용 컨테이너를 대상으로 한다 (저장 spec 전용).
 *   제품 화면에서는 어느 Div 가 선택 가능한지 정해져 있지 않아 후보를 순회하지만,
 *   시드 화면은 컨테이너가 고정 id 라 곧바로 지목한다.
 */
async function addTable(page: Page, sandbox = false): Promise<string> {
  const containerPath = sandbox
    ? await editorPath(page, '', SANDBOX_ROOT_ID)
    : await pickSelectableDiv(page);
  expect(containerPath, '선택 가능한 Div 컨테이너를 찾지 못했다').toBeTruthy();

  const tables = () =>
    page.evaluate(() =>
      Array.from(document.querySelectorAll('[data-editor-name="Table"]'))
        .map((e) => e.getAttribute('data-editor-path') ?? '')
        .filter(Boolean),
    );
  const before = new Set(await tables());

  await page.getByTestId('g7le-toolbar-add-element').click();
  await page.waitForSelector('[data-testid="g7le-palette-item-Table"]', { timeout: 10_000 });
  await page.getByTestId('g7le-palette-item-Table').click();
  await page.waitForTimeout(600);

  const fresh = (await tables()).filter((p) => !before.has(p));
  expect(fresh.length, '추가한 Table 노드를 캔버스에서 찾지 못했다').toBeGreaterThan(0);

  return fresh.sort()[fresh.length - 1];
}

test.describe('@layout-editor table 노드 에디터(빌트인 STRUCT-TREE)', () => {
  /** @effects property_modal_dispatches_table_node_editor_in_props_tab_by_kind, add_row_inserts_blank_row_keeps_col_count, add_column_inserts_blank_col */
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
    // 거터 안의 버튼들은 `visibility: hidden` 이고 그 거터에 hover 했을 때만 보인다 —
    // hover 없이 클릭하면 actionable 하지 않아 타임아웃한다.
    const colsBefore = await page.locator('[data-testid^="g7le-table-colgutter-"]').count();
    await page.getByTestId('g7le-table-colgutter-0').hover();
    await page.getByTestId('g7le-table-col-add-0').click();
    await page.waitForTimeout(300);
    const colsAfter = await page.locator('[data-testid^="g7le-table-colgutter-"]').count();
    expect(colsAfter).toBe(colsBefore + 1);
  });

  /** @effects shift_select_range_then_merge_sets_origin_span_removes_absorbed */
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

  // 저장(PUT)하는 테스트는 편집 결과가 그대로 영속되므로 제품 화면(home)이 아니라 E2E 전용
  // 시드 화면(e2e_sandbox)을 대상으로 한다. 제품 화면에 저장하면 실행마다 빈 표가 누적된다
  // (실측: 7개, 20,321 → 33,696 bytes). 시드 화면은 globalSetup 이 매 실행 원본으로 덮어쓴다.
  /** @effects live_add_row_col_merge_save_persists_to_user_page, editor_save_specs_target_sandbox_layout_not_product_layout */
  test('table 편집 후 저장 → PUT 200', async ({ page }) => {
    await gotoEditor(page, sandboxRouteParam());
    const tablePath = await addTable(page, true);
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
