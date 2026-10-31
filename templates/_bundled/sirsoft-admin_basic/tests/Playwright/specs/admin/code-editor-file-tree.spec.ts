/**
 * sirsoft-admin_basic — 파일 트리 (#476)
 *
 * 종전 좌측 목록은 144개 파일이 한 줄씩 늘어선 평면 리스트였다. 이름이
 * `partials/admin_identity_logs/_modal_log_detail` 처럼 슬래시가 든 긴 문자열이라 원하는 파일을
 * 눈으로 찾는 것이 사실상 불가능했고, `<div>` + click 이라 키보드로는 닿지도 않았다.
 *
 * 여기서 잰다 — **폴더가 실제로 접히고 펼쳐지는가**, **키보드로 걸을 수 있는가**.
 * 검색은 같은 트리의 다른 약속이라 `code-editor-search.spec.ts` 가 따로 잰다.
 *
 * @scenario surface=code_editor,entry=direct
 * @effects code_editor_file_tree_is_navigable
 *
 * 실행: `code-editor-*.spec.ts` 는 `--workers=1` 로 돌린다 — 사유는
 * `code-editor-file-crud.spec.ts` 헤더 참조(같은 시드 화면을 공유해 병렬에서 서로를 밟는다).
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

async function openEditor(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/templates/sirsoft-admin_basic/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[role="tree"] [role="treeitem"]', { timeout: 30_000 });
}

// 같은 시드 화면(e2e_sandbox)에서 저장·생성·삭제를 겹쳐 하므로 병렬이면 서로의 상태를 밟는다 — 직렬이 정본이다.
test.describe.configure({ mode: 'serial' });

test.describe('@sirsoft-admin_basic 코드 편집기 — 파일 트리', () => {
  /**
   * @scenario promise=tree_structure, surface=browser
   *
   * @effects code_editor_builds_folders_from_layout_names
   */
  test('이름의 슬래시로 폴더가 서고 접기·펼치기가 동작한다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const folders = page.locator('[role="treeitem"][aria-expanded]');
    expect(await folders.count(), '폴더가 하나도 없으면 평면 목록 그대로다').toBeGreaterThan(0);

    // 접힌 폴더 하나를 펼치면 보이는 행이 늘어난다.
    const collapsed = page.locator('[role="treeitem"][aria-expanded="false"]').first();
    const before = await page.locator('[role="treeitem"]').count();

    await collapsed.click();

    await expect
      .poll(() => page.locator('[role="treeitem"]').count(), { timeout: 10_000 })
      .toBeGreaterThan(before);
  });

  /**
   * 트리 규약이 없으면 스크린리더는 목록을 구조로 읽지 못한다.
   *
   * @scenario promise=tree_accessibility, surface=browser
   *
   * @effects code_editor_tree_follows_the_aria_tree_contract
   */
  test('role=tree 규약과 roving tabindex 를 지킨다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const measured = await page.evaluate(() => {
      const items = [...document.querySelectorAll('[role="treeitem"]')];

      return {
        trees: document.querySelectorAll('[role="tree"]').length,
        items: items.length,
        focusable: items.filter((el) => el.getAttribute('tabindex') === '0').length,
        selected: items.filter((el) => el.getAttribute('aria-selected') === 'true').length,
        foldersWithExpanded: items.filter((el) => el.hasAttribute('aria-expanded')).length,
      };
    });

    expect(measured.trees).toBe(1);
    expect(measured.items).toBeGreaterThan(10);
    // roving — 활성 노드 하나만 0 이어야 Tab 한 번에 트리를 빠져나간다.
    expect(measured.focusable, 'tabindex=0 이 여럿이면 Tab 으로 트리를 빠져나갈 수 없다').toBe(1);
    expect(measured.selected).toBe(1);
    expect(measured.foldersWithExpanded).toBeGreaterThan(0);
  });

  /**
   * @scenario promise=tree_keyboard, surface=browser
   *
   * @effects code_editor_tree_moves_with_arrow_keys
   */
  test('방향키로 보이는 행 사이를 걷는다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const activeBefore = await page.evaluate(
      () => document.querySelector('[role="treeitem"][tabindex="0"]')?.textContent?.trim() ?? '',
    );

    await page.locator('[role="tree"]').click({ position: { x: 5, y: 5 } });
    await page.keyboard.press('ArrowDown');

    const activeAfter = await page.evaluate(
      () => document.querySelector('[role="treeitem"][tabindex="0"]')?.textContent?.trim() ?? '',
    );

    expect(activeAfter).not.toBe(activeBefore);
  });

  /**
   * 파일 선택은 주소에 남는다 — 새로고침해도 같은 파일이 열려야 한다.
   *
   * @scenario promise=selection_survives_reload, surface=browser
   *
   * @effects code_editor_file_select_syncs_route_query
   */
  test('파일을 고르면 주소에 남고 새로고침해도 같은 파일이 열린다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    // 라우트가 걸린 파일을 고른다 — `?route=` 는 그런 파일에만 붙는다.
    const rows = page.locator('[role="treeitem"]:not([aria-expanded])');
    const total = await rows.count();

    for (let index = 0; index < Math.min(total, 12); index += 1) {
      await rows.nth(index).click();
      await page.waitForTimeout(700);

      if (new URL(page.url()).searchParams.get('route')) {
        break;
      }
    }

    const route = new URL(page.url()).searchParams.get('route');
    test.skip(!route, '라우트가 걸린 레이아웃을 찾지 못했다 — 이 템플릿에서는 검증 대상이 없다');

    const openedBefore = await page.locator('#editor_card').innerText();

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[role="tree"] [role="treeitem"]', { timeout: 30_000 });

    await expect
      .poll(async () => (await page.locator('#editor_card').innerText()).slice(0, 60), { timeout: 20_000 })
      .toBe(openedBefore.slice(0, 60));
  });
});
