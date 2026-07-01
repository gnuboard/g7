/**
 * Layout Editor — table 캔버스 인플레이스 오버레이(빌트인).
 *
 * Table 은 capability `canvasOverlay:{kind:"table",params:{...}}` 로 캔버스에서 직접
 * 셀 단위 핸들(행/열 거터, 병합)을 노출한다. 코어 빌트인 TableInplaceOverlay 가
 * registerCoreEditors 의 registerCanvasOverlay('table', ...) 로 일반 레지스트리에 등록되어,
 * EditorCanvasOverlay 가 kind(컴포넌트명 아님)로 디스패치하며 측정 셀 박스를 주입한다.
 * 모든 구조 변형은 속성 패널 TableEditor 와 동일한 tableGridMutations 를 호출(단일 패치 SSoT).
 *
 * 본 E2E 는 라이브 편집기에서:
 *  1. Table 추가·선택 시 캔버스 인플레이스 오버레이(g7le-table-inplace)가 마운트,
 *  2. 인플레이스 행/열 추가 거터가 캔버스 표에 반영(셀 핸들 수 증가),
 *  3. 인플레이스 셀 선택 + Shift 영역 → 병합 버튼 활성·병합,
 *  4. 인플레이스 편집 후 저장(PUT 200),
 *  을 검증한다. 변형 정합(span 보정/흡수셀/밴드 이동)은 단위(TableInplaceOverlay.test.tsx /
 *  tableGridModel.test.ts)가 잠그고, 본 E2E 는 인플레이스 라이브 반영·저장을 브라우저로 확인.
 *
 * @scenario table_canvas_inplace_overlay + inplace_add_row_col + inplace_merge + live_persist
 * @effects editorcanvasoverlay_dispatches_canvasoverlay_by_kind_with_measured_cellboxes, table_inplace_overlay_registered_via_registercoreeditors_kind_agnostic, inplace_gutter_add_row_col_shares_tablegridmutations_with_property_panel, inplace_shift_select_merge_sets_origin_span_removes_absorbed, live_inplace_cell_edit_save_persists_to_user_page
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
  // 선택 성공 판정 = 선택 박스(g7le-overlay-selected). ⓘ 버튼은 큰 루트 컨테이너에서
  // 뷰포트 밖에 위치할 수 있어 판정 기준으로 부적합(라이브 실측 확인 — selected 는 항상 등장).
  return page
    .waitForSelector('[data-testid="g7le-overlay-selected"]', { timeout: 5_000 })
    .then(() => true)
    .catch(() => false);
}

/** 컨테이너 Div 를 선택하고 Table 을 추가한 뒤, **신규 Table path 를 diff 로 식별**해 반환.
 * 팔레트 삽입 위치는 선택 컨테이너에 따라 결정되므로 children 인덱스를 계산하지 않고
 * 추가 전후 Table data-editor-path 집합 차이로 새 노드를 찾는다(라이브 실측 — 삽입 path 가
 * 컨테이너 children 이 아닐 수 있음). */
async function addTable(page: Page): Promise<string> {
  const containerPath = await page.evaluate(() => {
    const els = Array.from(document.querySelectorAll('[data-editor-name="Div"]'));
    return els[0]?.getAttribute('data-editor-path') ?? null;
  });
  expect(containerPath).toBeTruthy();
  expect(await selectByPath(page, containerPath!)).toBe(true);
  const before = await page.evaluate(() =>
    Array.from(document.querySelectorAll('[data-editor-name="Table"]')).map((e) => e.getAttribute('data-editor-path')),
  );
  await page.getByTestId('g7le-toolbar-add-element').click();
  await page.waitForSelector('[data-testid="g7le-palette-item-Table"]', { timeout: 10_000 });
  await page.getByTestId('g7le-palette-item-Table').click();
  await page.waitForTimeout(600);
  const newPath = await page.evaluate((prev: (string | null)[]) => {
    const prevSet = new Set(prev);
    const all = Array.from(document.querySelectorAll('[data-editor-name="Table"]')).map((e) => e.getAttribute('data-editor-path'));
    return all.find((p) => !prevSet.has(p)) ?? null;
  }, before);
  expect(newPath).toBeTruthy();
  return newPath!;
}

/** 2단계 선택 — 표가 이미 선택된 상태에서 첫 셀 픽 영역(g7le-inplace-cell-*)을 클릭해 셀 선택.
 * 셀 픽 영역의 onClick 이 내부 pickedCell 을 설정 → 거터/도구 노출. */
async function selectFirstCell(page: Page): Promise<boolean> {
  const cell = page.locator('[data-testid^="g7le-inplace-cell-"]').first();
  if ((await cell.count()) === 0) return false;
  await cell.click({ position: { x: 4, y: 4 } });
  await page.waitForTimeout(200);
  return true;
}

test.describe('@layout-editor table 캔버스 인플레이스 오버레이(빌트인)', () => {
  test('표 선택 시 오버레이 마운트(모서리 추가) + 셀 선택 시 거터/도구 노출', async ({ page }) => {
    await gotoEditor(page);
    const tablePath = await addTable(page);
    expect(await selectByPath(page, tablePath)).toBe(true);
    await page.waitForTimeout(300);
    // 표 선택 — 오버레이 마운트 + 모서리 추가. 불투명 셀 레이어 없음(드래그/인라인 편집 보존).
    await expect(page.getByTestId('g7le-table-inplace')).toBeAttached();
    await expect(page.getByTestId('g7le-inplace-add-row-bottom')).toBeAttached();
    await expect(page.getByTestId('g7le-inplace-select-hint')).toBeAttached();

    // 셀 선택 → 그 행/열 거터 노출.
    const picked = await selectFirstCell(page);
    expect(picked).toBe(true);
    await page.waitForTimeout(300);
    await expect(page.locator('[data-testid^="g7le-inplace-colgutter-"]').first()).toBeAttached();
    await expect(page.locator('[data-testid^="g7le-inplace-rowgutter-"]').first()).toBeAttached();
  });

  test('표 선택 상태에서 셀 위 pointerdown 이 표 드래그 핸들로 forward(드래그 보존)', async ({ page }) => {
    await gotoEditor(page);
    const tablePath = await addTable(page);
    expect(await selectByPath(page, tablePath)).toBe(true);
    await page.waitForTimeout(300);
    // 2단계 모델 — 셀 픽 영역이 표를 덮지만 onPointerDown 을 하위 드래그 핸들로 forward 한다.
    // 셀 영역 중심에서 pointerdown 발사 → 표 드래그 핸들(data-dnd-handle-path)이 pointerdown 수신.
    const handleGotPointerDown = await page.evaluate((tp) => {
      const cellArea = document.querySelector('[data-testid^="g7le-inplace-cell-"]') as HTMLElement | null;
      if (!cellArea) return false;
      const handle = document.querySelector(`[data-dnd-handle-path="${tp}"]`) as HTMLElement | null;
      if (!handle) return false;
      let received = false;
      const onPd = (): void => { received = true; };
      handle.addEventListener('pointerdown', onPd);
      const r = cellArea.getBoundingClientRect();
      cellArea.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, clientX: r.left + r.width / 2, clientY: r.top + r.height / 2, pointerId: 1, isPrimary: true }));
      handle.removeEventListener('pointerdown', onPd);
      return received;
    }, tablePath);
    expect(handleGotPointerDown).toBe(true);
  });

  test('선택 셀 행/열 추가 거터 → 캔버스 행/열 증가', async ({ page }) => {
    await gotoEditor(page);
    const tablePath = await addTable(page);
    expect(await selectByPath(page, tablePath)).toBe(true);
    await page.waitForTimeout(300);
    await selectFirstCell(page);
    await page.waitForTimeout(300);
    const rowsBefore = await page.locator(`[data-editor-name="Tr"]`).count();
    await page.locator('[data-testid^="g7le-inplace-row-add-"]').first().click();
    await page.waitForTimeout(300);
    expect(await page.locator(`[data-editor-name="Tr"]`).count()).toBeGreaterThan(rowsBefore);
  });

  test('선택 셀 오른쪽 병합 → colspan 반영', async ({ page }) => {
    await gotoEditor(page);
    const tablePath = await addTable(page);
    expect(await selectByPath(page, tablePath)).toBe(true);
    await page.waitForTimeout(300);
    await selectFirstCell(page);
    await page.waitForTimeout(300);
    const mr = page.getByTestId('g7le-inplace-merge-right');
    await expect(mr).toBeEnabled();
    await mr.click();
    await page.waitForTimeout(300);
    // colspan=2 셀이 캔버스에 존재.
    const hasColspan2 = await page.evaluate(() =>
      Array.from(document.querySelectorAll('td,th')).some((c) => c.getAttribute('colspan') === '2'),
    );
    expect(hasColspan2).toBe(true);
  });

  test('인플레이스 편집 후 저장 → PUT 200', async ({ page }) => {
    await gotoEditor(page);
    const tablePath = await addTable(page);
    expect(await selectByPath(page, tablePath)).toBe(true);
    await page.waitForTimeout(300);
    await page.getByTestId('g7le-inplace-add-row-bottom').click();
    await page.waitForTimeout(300);

    const savePromise = page.waitForResponse(
      (r) =>
        /\/api\/admin\/templates\/sirsoft-basic\/layouts\//.test(r.url()) &&
        r.request().method() === 'PUT',
      { timeout: 15_000 },
    );
    await page.getByRole('button', { name: /save|저장/i }).first().click();
    const saveRes = await savePromise;
    expect(saveRes.status()).toBe(200);
  });
});
