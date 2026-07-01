/**
 * Layout Editor — 별도 편집 모드(base/modal/extension/iteration) 브라우저 통합
 *
 * Phase 1 에서 액션 골격만 도입됐던 4개 별도 편집 모드의 캔버스 단독 렌더 + 진입/이탈 +
 * URL 동기화를 브라우저에서 검증한다(단위 테스트가 못 잡는 render-cycle + URL pushState).
 *
 * @scenario edit_mode + url_sync + followup_action
 * @effects route_tree_renders_base_modal_extension_groups + base_edit_loads_and_renders_base_layout_standalone + modal_edit_renders_modal_open_standalone_no_dim + extension_edit_loads_via_layout_extensions_api + enter_edit_mode_pushes_edit_query_to_url + refresh_with_edit_query_restores_edit_mode + back_button_exits_edit_mode_to_route
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const TEMPLATE = 'sirsoft-admin_basic';
const EDITOR_URL = `/admin/layout-editor/${TEMPLATE}`;

async function enterEditor(page: import('@playwright/test').Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(EDITOR_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-route-tree-item"]', { timeout: 30_000 });
}

/** 트리에서 텍스트로 항목을 찾아 클릭 */
async function clickTreeItem(page: import('@playwright/test').Page, text: RegExp): Promise<void> {
  const item = page.locator('[data-testid="g7le-route-tree-item"]', { hasText: text }).first();
  await item.click();
}

/** 캔버스의 편집 가능 노드(data-editor-path) 개수 */
async function editorNodeCount(page: import('@playwright/test').Page): Promise<number> {
  return page.evaluate(() => document.querySelectorAll('[data-editor-path]').length);
}

test.describe('@layout-editor 별도 편집 모드', () => {
  test('base 진입 → URL ?edit= pushState + base 캔버스 단독 렌더', async ({ page }) => {
    await enterEditor(page);

    // 트리에 [공통 레이아웃] 그룹 + base 항목 존재.
    await clickTreeItem(page, /_admin_base/);
    // 모드 배지(확장 편집/공통 레이아웃 편집 중) + URL ?edit= 변경.
    await expect.poll(() => page.url()).toMatch(/edit=__base__/);
    await expect(page.locator('[data-mode="base"]')).toBeAttached();
    // base 레이아웃 단독 렌더 — 편집 노드가 존재.
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 20_000 });
    await expect.poll(() => editorNodeCount(page)).toBeGreaterThan(0);
  });

  test('base 진입 후 뒤로가기 → 편집 모드 종료(URL ?edit= 제거)', async ({ page }) => {
    await enterEditor(page);
    await clickTreeItem(page, /_admin_base/);
    await expect.poll(() => page.url()).toMatch(/edit=__base__/);

    await page.goBack();
    // 뒤로가기로 편집 모드 종료 — URL 에서 ?edit= 제거.
    await expect.poll(() => page.url()).not.toMatch(/edit=__base__/);
    await expect(page.locator('[data-mode="base"]')).toHaveCount(0);
  });

  test('확장 주입 그룹 항목 진입 → 확장 조각 캔버스 렌더 + URL ?edit=__extension__', async ({ page }) => {
    await enterEditor(page);

    // [확장 주입] 그룹의 확장 항목(출처 · 대상명) 클릭.
    await clickTreeItem(page, /·\s*(inquiry_board_setting|html_content|admin_user_detail)/);
    await expect.poll(() => page.url()).toMatch(/edit=__extension__/);
    await expect(page.locator('[data-mode="extension"]')).toBeAttached();
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 20_000 });
    await expect.poll(() => editorNodeCount(page)).toBeGreaterThan(0);
  });

  test('?edit=__extension__ URL 직접 진입(새로고침) → 확장 편집 모드 복원', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    // 확장 id=1 로 직접 진입.
    await page.goto(`${EDITOR_URL}?edit=__extension__/1`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });

    // 새로고침/직접 URL 로도 확장 편집 모드가 복원되고 캔버스가 렌더된다(크래시 회귀 가드).
    await expect(page.locator('[data-mode="extension"]')).toBeAttached({ timeout: 20_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 20_000 });
    await expect.poll(() => editorNodeCount(page)).toBeGreaterThan(0);
  });

  test('모달 그룹 항목 진입 → 모달 단독 open 렌더', async ({ page }) => {
    await enterEditor(page);

    // [모달] 그룹의 인라인 모달 항목 클릭(admin_user_list 의 delete_confirm_modal 등).
    await clickTreeItem(page, /delete_confirm_modal|error_modal|bulk_/);
    await expect(page.locator('[data-mode="modal"]')).toBeAttached();
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 20_000 });
    // 모달이 열린 상태로 단독 렌더 — 편집 노드 존재.
    await expect.poll(() => editorNodeCount(page)).toBeGreaterThan(0);
  });
});
