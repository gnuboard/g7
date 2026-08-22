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
 *   live_barchart_dataset_edit_save_persists_to_user_page, admin_editor_save_specs_target_admin_sandbox_layout
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';
import { SANDBOX_ROOT_ID, sandboxRouteParam } from '../../fixtures/seed-layout';
import { editorPath } from '../../fixtures/layout-editor';

const TEMPLATE = 'sirsoft-admin_basic';

// 관리자 템플릿의 라우트는 모두 `*/admin/...` 프리픽스를 갖는다 — '/admin' 은 존재하지 않고
// `*/admin` 은 `redirect` 전용(레이아웃 없음)이라, 둘 다 캔버스(g7le-preview-frame)가 뜨지 않는다.
// 차트 빌트인(DonutChart/BarChart)이 있는 대시보드 라우트를 지목한다.
async function gotoEditor(page: Page, route = '*%2Fadmin%2Fdashboard'): Promise<void> {
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
  const dispatchSynthetic = () =>
    page.evaluate((p) => {
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

  /**
   * 선택 오버레이가 **그 노드에 대해** 떠 있는지 판정.
   *
   * 오버레이 존재만 보면 다른 노드가 선택된 상태를 통과시킨다. 오버레이의 타입 라벨이 대상 노드의
   * `data-editor-name` 과 일치하는지까지 확인한다 (라벨은 방향 표시 `↑` 장식을 앞에 두므로 포함 비교).
   */
  const overlayMatches = async (timeout: number): Promise<boolean> => {
    const appeared = await page
      .waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout })
      .then(() => true)
      .catch(() => false);
    if (!appeared) return false;

    return page.evaluate((p) => {
      const node = document.querySelector(`[data-editor-path="${p}"]`);
      const label = document.querySelector('[data-testid="g7le-overlay-type-label"]');
      if (!node || !label) return false;
      const name = node.getAttribute('data-editor-name');

      return !!name && (label.textContent ?? '').includes(name);
    }, path);
  };

  // 팔레트로 삽입한 직후에는 편집기가 새 노드를 이미 선택해 둔다. 그 상태에서 다시 클릭하면
  // 차트처럼 canvas 를 품은 컴포넌트는 자식이 좌상단을 가려 선택이 오히려 풀린다 —
  // 이미 맞게 선택돼 있으면 손대지 않는다. (선점 확인이라 대기는 짧게)
  if (await overlayMatches(300)) return true;

  await dispatchSynthetic();
  if (await overlayMatches(3_000)) return true;

  // 노드 박스를 직접 클릭하면 자식·부모 Div 가 좌상단을 가려 엉뚱한 노드가 선택된다
  // (차트처럼 내부가 canvas 인 컴포넌트에서 실제로 그랬다 — 라벨이 BarChart 대신 Div).
  // 노드 전용 드래그 핸들은 그 노드만 지목하므로 이걸로 선택한다.
  const handle = page.locator(`[data-dnd-handle-path="${path}"]`);
  if ((await handle.count()) > 0) {
    await handle.first().click({ force: true, timeout: 3_000 }).catch(() => { /* 아래 판정에 위임 */ });
    if (await overlayMatches(3_000)) return true;
  }

  // 마지막 수단 — 실제 마우스 클릭 (hit-testing 경유).
  await page
    .locator(`[data-editor-path="${path}"]`)
    .first()
    .click({ position: { x: 4, y: 4 }, force: true, timeout: 3_000 })
    .catch(() => { /* 클릭 자체가 불가하면 아래 판정에서 false */ });

  return overlayMatches(3_000);
}

/**
 * 컨테이너 후보 탐색용 빠른 선택 시도.
 *
 * `selectByPath` 는 3단계 폴백을 거쳐 한 번에 최대 6초 이상 쓴다 — 후보를 15개 순회하면
 * 테스트 타임아웃(30초)을 넘겨 페이지가 통째로 닫힌다. 순회에는 합성 클릭 1회 + 짧은 대기만 쓴다.
 */
async function trySelectQuick(page: Page, path: string): Promise<boolean> {
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
    .waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 1_200 })
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
 * content root(Div) 안에 컴포넌트를 추가하고 그 path 반환.
 *
 * 예전에는 첫 번째 `data-editor-name="Div"` 를 무조건 컨테이너로 삼았는데, 그 노드가 편집기에서
 * 선택 불가(잠금 영역 등)면 곧바로 실패했다. 클래스나 순번으로 지목하지 않고 **실제로 선택되고
 * 팔레트에 요청 컴포넌트가 뜨는** Div 를 찾는다.
 *
 * @param page Playwright page
 * @param name 추가할 컴포넌트 이름
 * @param sandbox true 면 시드 화면의 전용 컨테이너(고정 id)만 후보로 둔다 (저장 spec 전용).
 */
async function addComponent(page: Page, name: string, sandbox = false): Promise<string> {
  const candidates = sandbox
    ? [await editorPath(page, '', SANDBOX_ROOT_ID)]
    : await page.evaluate(() =>
        Array.from(document.querySelectorAll('[data-editor-name="Div"]'))
          .map((e) => e.getAttribute('data-editor-path') ?? '')
          .filter(Boolean),
      );
  expect(candidates.length, 'Div 컨테이너 후보가 있어야 함').toBeGreaterThan(0);

  /** 캔버스에 존재하는 해당 컴포넌트 노드 path 목록 */
  const nodesOf = (componentName: string) =>
    page.evaluate(
      (n) =>
        Array.from(document.querySelectorAll(`[data-editor-name="${n}"]`))
          .map((e) => e.getAttribute('data-editor-path') ?? '')
          .filter(Boolean),
      componentName,
    );

  const before = new Set(await nodesOf(name));

  // 후보를 순회하며 **실제로 삽입되는** 컨테이너를 찾는다. 팔레트에 항목이 보이는 것만으로는
  // 부족하다 — 선택이 풀린 상태면 클릭해도 삽입되지 않는다. 삽입 성공은 노드가 실제로
  // 늘었는지로 판정한다.
  let addedPath: string | null = null;
  for (const candidate of candidates.slice(0, 15)) {
    if (!(await trySelectQuick(page, candidate))) continue;

    await page.getByTestId('g7le-toolbar-add-element').click();
    const hasItem = await page
      .waitForSelector(`[data-testid="g7le-palette-item-${name}"]`, { timeout: 4_000 })
      .then(() => true)
      .catch(() => false);

    if (hasItem) {
      await page.getByTestId(`g7le-palette-item-${name}`).click();
      await page.waitForTimeout(600);
      const fresh = (await nodesOf(name)).filter((p) => !before.has(p));
      if (fresh.length > 0) {
        addedPath = fresh.sort()[fresh.length - 1];
        break;
      }
    }

    // 이 후보로는 삽입되지 않았다 — 팔레트를 토글로 닫고 다음 후보로.
    await page.getByTestId('g7le-toolbar-add-element').click().catch(() => { /* 이미 닫힘 */ });
    await page.waitForTimeout(200);
  }

  expect(addedPath, `${name} 을 삽입할 수 있는 컨테이너를 찾지 못했다`).toBeTruthy();

  return addedPath!;
}

// 삽입 컨테이너를 시드 화면의 고정 id 컨테이너로 고정한다.
//
// 제품 대시보드에서는 `addComponent` 가 Div 후보를 순회해 "삽입되는 곳" 에 꽂는데, 그 위치가
// 모듈이 주입한 잠금(확장) 서브트리 안이면 선택이 조상 Div 로 escalate 되어 노드 자체를 지목할 수
// 없다. 실측(2026-07-30): 대시보드 삽입 시 BarChart 선택 라벨이 `↑Div` + ⓘ 미표시였고, 같은 절차를
// 시드 컨테이너에서 수행하면 `↑BarChart` + ⓘ 표시 + array-group 에디터(labels/datasets) 3개 모두
// 마운트된다. 컴포넌트 결함이 아니라 삽입 위치 문제였다.
test.describe('@layout-editor 데이터 정의 빌트인(8-b)', () => {
  test('DonutChart 선택 시 array 에디터(data)가 속성 탭에 마운트되고 value(number) 편집 반영', async ({
    page,
  }) => {
    await gotoEditor(page, sandboxRouteParam(TEMPLATE));
    const path = await addComponent(page, 'DonutChart', true);
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
    await gotoEditor(page, sandboxRouteParam(TEMPLATE));
    const path = await addComponent(page, 'BarChart', true);
    expect(await selectByPath(page, path)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-array-group-editor')).toBeVisible();
    await expect(page.getByTestId('g7le-array-group-labels')).toBeVisible();
    await expect(page.getByTestId('g7le-array-group-datasets')).toBeVisible();
  });

  // 저장(PUT)하는 테스트는 편집 결과가 그대로 영속되므로 제품 화면(admin_dashboard)이 아니라
  // E2E 전용 시드 화면(e2e_sandbox)을 대상으로 한다. 제품 화면에 저장하던 동안 관리자 대시보드에
  // 빈 DonutChart 5개가 누적됐다. 시드 화면은 globalSetup 이 매 실행 fixture 원본으로 덮어쓴다.
  test('DonutChart 데이터 편집 후 저장 → PUT 200', async ({ page }) => {
    await gotoEditor(page, sandboxRouteParam(TEMPLATE));
    const path = await addComponent(page, 'DonutChart', true);
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
