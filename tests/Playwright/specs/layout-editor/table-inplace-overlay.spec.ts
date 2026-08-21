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
 * 축 요약(마커 아님 — 평문): table_canvas_inplace_overlay, inplace_add_row_col, inplace_merge, live_persist.
 * 효과 요약(마커 아님 — 평문): editorcanvasoverlay_dispatches_canvasoverlay_by_kind_with_measured_cellboxes, table_inplace_overlay_registered_via_registercoreeditors_kind_agnostic, inplace_gutter_add_row_col_shares_tablegridmutations_with_property_panel, inplace_shift_select_merge_sets_origin_span_removes_absorbed, live_inplace_cell_edit_save_persists_to_user_page, editor_save_specs_target_sandbox_layout_not_product_layout.
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
  // 선택 성공 판정 = 선택 박스(g7le-overlay-selected). ⓘ 버튼은 큰 루트 컨테이너에서
  // 뷰포트 밖에 위치할 수 있어 판정 기준으로 부적합(라이브 실측 확인 — selected 는 항상 등장).
  return page
    .waitForSelector('[data-testid="g7le-overlay-selected"]', { timeout: 5_000 })
    .then(() => true)
    .catch(() => false);
}

/**
 * 편집기에서 **실제로 선택되는** Div 컨테이너를 찾아 그 path 를 반환합니다.
 *
 * 캔버스의 첫 Div 는 잠금 영역이나 래퍼라 선택되지 않는 경우가 있다. 순번·클래스로 지목하지 않고
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

    const selected = await page
      .waitForSelector('[data-testid="g7le-overlay-selected"]', { timeout: 1_200 })
      .then(() => true)
      .catch(() => false);
    if (selected) return candidate;
  }

  return null;
}

/** 컨테이너 Div 를 선택하고 Table 을 추가한 뒤, **신규 Table path 를 diff 로 식별**해 반환.
 * 팔레트 삽입 위치는 선택 컨테이너에 따라 결정되므로 children 인덱스를 계산하지 않고
 * 추가 전후 Table data-editor-path 집합 차이로 새 노드를 찾는다(라이브 실측 — 삽입 path 가
 * 컨테이너 children 이 아닐 수 있음).
 *
 * @param page Playwright page
 * @param sandbox true 면 시드 화면의 전용 컨테이너(고정 id)를 대상으로 한다 (저장 spec 전용).
 */
async function addTable(page: Page, sandbox = false): Promise<string> {
  // 첫 번째 Div 를 무조건 컨테이너로 삼으면 그 노드가 편집기에서 선택 불가일 때 바로 실패한다.
  // 순번이 아니라 **실제로 선택되는** Div 를 찾는다. 시드 화면은 컨테이너 id 가 고정이라 직접 지목.
  const containerPath = sandbox
    ? await editorPath(page, '', SANDBOX_ROOT_ID)
    : await pickSelectableDiv(page);
  expect(containerPath, '선택 가능한 Div 컨테이너를 찾지 못했다').toBeTruthy();
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
  /** @effects editorcanvasoverlay_dispatches_canvasoverlay_by_kind_with_measured_cellboxes, table_inplace_overlay_registered_via_registercoreeditors_kind_agnostic */
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
    // 셀 픽 영역과 드래그 핸들이 마운트될 때까지 기다린다. 고정 sleep 만 두면 마운트 전에
    // pointerdown 을 쏴 `cellArea`/`handle` 이 null 로 떨어지고 조용히 false 가 된다(간헐 실패).
    await page.waitForSelector('[data-testid^="g7le-inplace-cell-"]', { timeout: 10_000 });
    await page.waitForSelector(`[data-dnd-handle-path="${tablePath}"]`, { timeout: 10_000 });
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

  /** @effects inplace_gutter_add_row_col_shares_tablegridmutations_with_property_panel */
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

  /** @effects inplace_shift_select_merge_sets_origin_span_removes_absorbed */
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

  // 저장(PUT)하는 테스트는 편집 결과가 그대로 영속되므로 제품 화면(home)이 아니라 E2E 전용
  // 시드 화면(e2e_sandbox)을 대상으로 한다.
  //
  // 배경: 이 테스트가 home 에 저장하던 동안 빈 표가 누적됐다(실측 7개, 20,321 → 33,696 bytes).
  // spec 안에 "추가한 표 삭제 → 재저장" 원복을 넣어도 소용이 없었다 — 원복 후에도 레이아웃이
  // 오염 시점과 정확히 같은 크기로 되돌아왔다(편집기가 들고 있던 문서를 통째로 다시 저장).
  // 그래서 원복에 기대지 않고 저장 대상 자체를 시드 화면으로 분리했다. 시드 화면은 globalSetup 이
  // 매 실행 fixture 원본으로 덮어쓰므로 회차 간 누적이 성립하지 않는다.
  /** @effects live_inplace_cell_edit_save_persists_to_user_page, editor_save_specs_target_sandbox_layout_not_product_layout */
  test('인플레이스 편집 후 저장 → PUT 200', async ({ page }) => {
    await gotoEditor(page, sandboxRouteParam());
    const tablePath = await addTable(page, true);
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
    expect((await savePromise).status()).toBe(200);
  });
});
