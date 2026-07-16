/**
 * Layout Editor — array 노드 에디터(빌트인 ARRAY-PROP).
 *
 * TabNavigation 등 배열 prop 컴포넌트는 capability `nodeEditor:{kind:"array",params:{arrayProp,
 * fields,...}}` 로 props 배열(tabs/items/columns 등)을 속성 모달 [속성] 탭에서 항목 단위로
 * 편집한다. 코어 빌트인 ArrayItemsEditor 가 registerCoreEditors 의 registerNodeEditor('array', ...)
 * 로 일반 레지스트리에 등록되어, PropertyEditorModal 이 kind(컴포넌트명 아님)로 디스패치한다.
 *
 * 본 E2E 는 라이브 편집기에서:
 *  1. TabNavigation 을 추가하고 선택 → 속성 모달의 array 에디터(g7le-array-editor)가 마운트되는지,
 *  2. 항목 추가가 행(g7le-array-row-N)에 반영되는지,
 *  3. 필드 편집(id text 필드)이 즉시 반영되는지,
 *  4. 저장(PUT 200),
 *  을 검증한다. 추가/삭제/정렬/필드편집/디그레이드 회귀는 단위(ArrayItemsEditor.test.tsx)가
 *  잠그고, 본 E2E 는 라이브 반영·저장을 브라우저로 확인한다.
 *
 * @scenario array_node_editor + add_item + edit_text_field + live_persist
 * @effects property_modal_dispatches_array_node_editor_in_props_tab_by_kind, add_item_appends_newitem_skeleton_patches_whole_node, text_field_updates_item_immediately, live_add_tab_edit_label_reorder_save_persists_to_user_page
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

async function selectByPath(page: Page, path: string, timeout = 5_000): Promise<boolean> {
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
    .waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout })
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

/** content root(Div) 안에 TabNavigation 을 추가하고 그 path 반환. */
async function addTabNavigation(page: Page): Promise<string> {
  // 편집 가능한(ⓘ 가 뜨는) 컨테이너 Div 후보 순회 — 첫 Div 고정 선택은 루트/베이스 잠금
  // 노드가 첫 후보가 되는 레이아웃 구조 변화에 깨진다(children-list-editor.spec 와 동일 패턴).
  const candidates = await page.evaluate(() =>
    Array.from(document.querySelectorAll('[data-editor-name="Div"]'))
      .filter((e) => {
        const r = e.getBoundingClientRect();
        return r.width > 40 && r.height > 20;
      })
      .map((e) => e.getAttribute('data-editor-path') ?? '')
      .filter(Boolean)
      .slice(0, 10),
  );
  expect(candidates.length).toBeGreaterThan(0);
  let containerPath: string | null = null;
  for (const cand of candidates) {
    if (await selectByPath(page, cand, 2_000)) {
      containerPath = cand;
      break;
    }
  }
  expect(containerPath).toBeTruthy();
  await page.getByTestId('g7le-toolbar-add-element').click();
  await page.waitForSelector('[data-testid="g7le-palette-item-TabNavigation"]', { timeout: 10_000 });
  await page.getByTestId('g7le-palette-item-TabNavigation').click();
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

test.describe('@layout-editor array 노드 에디터(빌트인 ARRAY-PROP)', () => {
  test('TabNavigation 선택 시 array 에디터가 속성 탭에 마운트되고 항목 추가가 행 반영', async ({ page }) => {
    await gotoEditor(page);
    const navPath = await addTabNavigation(page);

    expect(await selectByPath(page, navPath)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-array-editor')).toBeVisible();

    const rowsBefore = await page.locator('[data-testid^="g7le-array-row-"]').count();
    await page.getByTestId('g7le-array-add').click();
    await page.waitForTimeout(300);
    const rowsAfter = await page.locator('[data-testid^="g7le-array-row-"]').count();
    expect(rowsAfter).toBe(rowsBefore + 1);
  });

  test('항목 id text 필드 편집이 즉시 반영(입력값 유지)', async ({ page }) => {
    await gotoEditor(page);
    const navPath = await addTabNavigation(page);
    expect(await selectByPath(page, navPath)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-array-editor')).toBeVisible();

    // 항목이 없으면 추가.
    if ((await page.locator('[data-testid^="g7le-array-row-"]').count()) === 0) {
      await page.getByTestId('g7le-array-add').click();
      await page.waitForTimeout(300);
    }
    const idField = page.getByTestId('g7le-array-field-0-id');
    await idField.fill('home');
    await expect(idField).toHaveValue('home');
  });

  test('array 편집 후 저장 → PUT 200', async ({ page }) => {
    test.setTimeout(60_000); // 후보 순회 + 저장 + 정리 합산 — 기본 30s 부족
    await gotoEditor(page);
    const navPath = await addTabNavigation(page);
    expect(await selectByPath(page, navPath)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-array-editor')).toBeVisible();
    await page.getByTestId('g7le-array-add').click();
    await page.waitForTimeout(300);

    // 모달을 확실히 닫은 뒤(미닫힘 상태 role 매칭은 모달 안 버튼이 잡히는 flake)
    // 툴바 저장 testid 클릭 (children-list-editor.spec 와 동일 패턴).
    await page
      .getByTestId('g7le-property-modal-done')
      .click({ timeout: 2_000 })
      .catch(() => undefined);
    await page
      .getByRole('button', { name: /^(닫기|Close)$/ })
      .first()
      .click({ timeout: 3_000 })
      .catch(() => undefined);
    await page.waitForTimeout(300);
    const savePromise = page.waitForResponse(
      (r) =>
        /\/api\/admin\/templates\/sirsoft-basic\/layouts\//.test(r.url()) &&
        r.request().method() === 'PUT',
      { timeout: 15_000 },
    );
    await page.getByTestId('g7le-toolbar-save').click();
    const saveRes = await savePromise;
    expect(saveRes.status()).toBe(200);

    // 정리 — 테스트가 추가한 TabNavigation 을 삭제하고 다시 저장(잔여물 누적 방지).
    expect(await selectByPath(page, navPath)).toBe(true);
    await page.getByTestId('g7le-overlay-info-button').click();
    await page.waitForSelector('[data-testid="g7le-context-menu-delete"]', { timeout: 5_000 });
    await page.getByTestId('g7le-context-menu-delete').click();
    await page.waitForTimeout(400);
    const cleanupPromise = page.waitForResponse(
      (r) =>
        /\/api\/admin\/templates\/sirsoft-basic\/layouts\//.test(r.url()) &&
        r.request().method() === 'PUT',
      { timeout: 15_000 },
    );
    await page.getByTestId('g7le-toolbar-save').click();
    expect((await cleanupPromise).status()).toBe(200);
  });

  // array kind `defaultItems` 시드.
  // IconSelect 는 options 미지정 시 내장 기본 아이콘 20종으로 렌더한다. 종전에는 옵션
  // 편집이 빈 목록에서 시작해 1개 추가가 내장 목록 전체를 교체하는 함정이었다. 스펙
  // params.defaultItems 선언으로 에디터가 기본 20종을 시작 목록으로 보여주고, 추가 시
  // 전체+추가분이 함께 커밋된다.
  test('IconSelect 옵션 에디터 — defaultItems 20종 시드 + 추가 시 기본 목록 보존', async ({ page }) => {
    // 관리자 템플릿 편집기(IconSelect 는 admin 전용 컴포넌트).
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await page.goto('/admin/layout-editor/sirsoft-admin_basic?route=*%2Fadmin%2Fdashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
    await page.waitForFunction(
      () => document.querySelectorAll('[data-editor-path]').length > 0,
      { timeout: 20_000 },
    );

    // 가시 노드(BarChart) 선택 → 아래 삽입 팔레트 → IconSelect 추가.
    const anchorPath = await page.evaluate(
      () => document.querySelector('[data-editor-name="BarChart"]')?.getAttribute('data-editor-path') ?? null,
    );
    expect(anchorPath).toBeTruthy();
    expect(await selectByPath(page, anchorPath!)).toBe(true);
    await page.getByTestId('g7le-insertion-below').click();
    await page.waitForSelector('[data-testid="g7le-palette-item-IconSelect"]', { timeout: 10_000 });
    await page.getByTestId('g7le-palette-item-IconSelect').click();
    await page.waitForTimeout(600);
    const nodePath = await page.evaluate(() => {
      const els = Array.from(document.querySelectorAll('[data-editor-name="IconSelect"]'));
      return els[els.length - 1]?.getAttribute('data-editor-path') ?? null;
    });
    expect(nodePath).toBeTruthy();
    expect(await selectByPath(page, nodePath!)).toBe(true);
    await openPropsTab(page);

    // 시드: 기본 20종이 시작 목록으로 표시(빈 목록 아님).
    await expect(page.getByTestId('g7le-array-editor')).toBeVisible();
    const rowsOf = () =>
      page.evaluate(() => document.querySelectorAll('[data-testid^="g7le-array-row-"]').length);
    expect(await rowsOf()).toBe(20);

    // 추가 → 21행, 기본 목록 보존(첫 항목 유지).
    await page.getByTestId('g7le-array-add').click();
    await page.waitForTimeout(300);
    const rows1 = await rowsOf();
    expect(rows1).toBe(21);
    await expect(page.locator('[data-testid="g7le-array-row-0"] input').first()).toHaveValue(
      'LayoutDashboard',
    );

    // 정리(저장 안 함) — 추가 행 삭제로 잔여 0.
    await page.getByTestId(`g7le-array-remove-${rows1 - 1}`).click();
    await page.waitForTimeout(300);
    expect(await rowsOf()).toBe(20);
  });
});
