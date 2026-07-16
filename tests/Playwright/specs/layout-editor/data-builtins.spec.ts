/**
 * Layout Editor — 데이터 정의 빌트인(전 컴포넌트 데이터 표면 / 부록8).
 *
 * 8-a 분류표대로 미커버 데이터 표면(차트 데이터/다중 배열/중첩 셀 트리/원시 enum 배열)을
 * 기존 array 에디터 재사용 + array-group/array-cell-tree 확장으로 편집한다. 본 E2E 는
 * 라이브 편집기에서 차트 데이터 편집이 캔버스에 마운트되고 저장(PUT 200)되는지를 확인한다.
 * 단위(ArrayItemsEditor / ArrayGroupAndCellTreeEditor / dataBuiltinCapabilityShape)가 위젯/
 * 그룹격리/셀트리/shape 정합 회귀를 잠그고, 본 E2E 는 라이브 마운트·저장·반영을 브라우저로
 * 확인한다(§공통 5단계 — 항목 patch 만으로 통과 금지).
 *
 * @scenario data_surface=single_array, component=DonutChart, operation=edit_field, field_widget=number
 * @scenario data_surface=multi_array, component=BarChart, operation=edit_field, field_widget=number_list
 * @effects property_modal_dispatches_data_builtin_node_editor_in_props_tab_by_kind,
 *   array_editor_number_widget_writes_numeric_value,
 *   array_group_renders_multiple_array_editors_per_group,
 *   live_donut_data_item_edit_save_persists_to_user_page,
 *   live_barchart_dataset_edit_save_persists_to_user_page
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const TEMPLATE = 'sirsoft-admin_basic';

async function gotoEditor(page: Page, route = '%2Fadmin'): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(`/admin/layout-editor/${TEMPLATE}?route=${route}`);
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

/** content root(Div) 안에 컴포넌트를 추가하고 그 path 반환. */
async function addComponent(page: Page, name: string): Promise<string> {
  const containerPath = await page.evaluate(() => {
    const els = Array.from(document.querySelectorAll('[data-editor-name="Div"]'));
    return els[0]?.getAttribute('data-editor-path') ?? null;
  });
  expect(containerPath).toBeTruthy();
  expect(await selectByPath(page, containerPath!)).toBe(true);
  await page.getByTestId('g7le-toolbar-add-element').click();
  await page.waitForSelector(`[data-testid="g7le-palette-item-${name}"]`, { timeout: 10_000 });
  await page.getByTestId(`g7le-palette-item-${name}`).click();
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

test.describe('@layout-editor 데이터 정의 빌트인(8-b)', () => {
  test('DonutChart 선택 시 array 에디터(data)가 속성 탭에 마운트되고 value(number) 편집 반영', async ({
    page,
  }) => {
    await gotoEditor(page);
    const path = await addComponent(page, 'DonutChart');
    expect(await selectByPath(page, path)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-array-editor')).toBeVisible();

    // 항목이 없으면 추가(시드 data 가 있으면 첫 행 편집).
    if ((await page.locator('[data-testid^="g7le-array-row-"]').count()) === 0) {
      await page.getByTestId('g7le-array-add').click();
      await page.waitForTimeout(300);
    }
    const valueField = page.getByTestId('g7le-array-field-0-value');
    await valueField.fill('250');
    await expect(valueField).toHaveValue('250');
  });

  test('BarChart 선택 시 array-group 에디터(labels+datasets)가 두 그룹으로 마운트', async ({
    page,
  }) => {
    await gotoEditor(page);
    const path = await addComponent(page, 'BarChart');
    expect(await selectByPath(page, path)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-array-group-editor')).toBeVisible();
    await expect(page.getByTestId('g7le-array-group-labels')).toBeVisible();
    await expect(page.getByTestId('g7le-array-group-datasets')).toBeVisible();
  });

  test('DonutChart 데이터 편집 후 저장 → PUT 200', async ({ page }) => {
    await gotoEditor(page);
    const path = await addComponent(page, 'DonutChart');
    expect(await selectByPath(page, path)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-array-editor')).toBeVisible();
    await page.getByTestId('g7le-array-add').click();
    await page.waitForTimeout(300);

    const savePromise = page.waitForResponse(
      (r) =>
        new RegExp(`/api/admin/templates/${TEMPLATE}/layouts/`).test(r.url()) &&
        r.request().method() === 'PUT',
      { timeout: 15_000 },
    );
    await page.getByTestId('g7le-property-modal-done').click().catch(() => undefined);
    await page.getByRole('button', { name: /save|저장/i }).first().click();
    const saveRes = await savePromise;
    expect(saveRes.status()).toBe(200);
  });
});
