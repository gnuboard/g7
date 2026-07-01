/**
 * Layout Editor — 라우트↔확장 연결 목록
 *
 * 백엔드 host_layouts 기반 정적 매칭으로, 라우트(화면)를 클릭하지 않아도 그 화면에 주입되는
 * 모달·확장이 트리에 표시되는지 + 연결 그룹 기본 접힘/토글 + 확장 편집 진입 시 목록 유지 +
 * 호스트 라우트 강조 유지 + 별도 편집 모드에서 라우트 클릭 시 캔버스 정상 렌더를 가드한다.
 *
 * 배경(회귀): (1) 동적 캔버스 수집 방식에서는 라우트를 클릭(캔버스 로드)해야만 EP 가 보이고
 * 확장 편집 모드에서 목록이 줄어들던 결함, (2) 확장/모달 편집 모드에서 라우트를 클릭하면
 * "이 레이아웃에는 표시할 컴포넌트가 없습니다" 가 뜨던 결함을 가드한다.
 *
 * @scenario ext_type + host_match + conn_count + conn_group_state + enter_mode_from_child + select_route_from_mode + host_highlight
 * @effects connected_extensions_attached_statically_by_host_layouts_without_canvas_load + connected_group_collapsed_by_default + connected_group_toggles_on_header_click + connected_count_stable_when_entering_extension_edit_mode + extension_child_click_enters_extension_edit_keeping_host_route_highlight + select_route_from_separate_edit_mode_restores_route_mode_and_renders_canvas
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const EDITOR_URL = '/admin/layout-editor/sirsoft-basic';
// shop/checkout 은 결제 플러그인(extension_point) + 우편번호/오버레이가 주입되는 대표 화면.
const CHECKOUT_GROUP_PATH = '__conngroup__/extensions//shop/checkout';
const CHECKOUT_ROUTE_PATH = '/shop/checkout';

async function enterEditor(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(EDITOR_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-route-tree-panel"]', { timeout: 30_000 });
  // 라우트 트리 + 확장 목록(별도 fetch) 로드 완료까지 — checkout 연결 그룹이 트리에 나타날 때까지.
  await page.waitForFunction(
    (groupPath) => !!document.querySelector(`[data-route-path="${groupPath}"]`),
    CHECKOUT_GROUP_PATH,
    { timeout: 30_000 },
  );
}

function connGroupHeader(page: Page) {
  return page.locator(`[data-route-path="${CHECKOUT_GROUP_PATH}"]`);
}

/** 연결 그룹 라벨의 count 숫자를 읽는다 (예: "주입되는 확장 (5)" → 5). */
async function readConnCount(page: Page): Promise<number> {
  const text = (await connGroupHeader(page).innerText()).replace(/\s+/g, ' ');
  const m = text.match(/\((\d+)\)/);
  return m ? Number(m[1]) : -1;
}

test.describe('@layout-editor route↔connected extensions', () => {
  test('클릭 없이 host_layouts 정적 매칭으로 연결 확장이 표시 + 기본 접힘', async ({ page }) => {
    await enterEditor(page);

    const header = connGroupHeader(page);
    await expect(header).toBeVisible();

    // 라우트를 클릭하지 않은 상태에서도 연결 확장이 1건 이상 정적 부착됨(host_layouts 기반).
    const count = await readConnCount(page);
    expect(count).toBeGreaterThan(0);

    // 연결 그룹은 기본 접힘(aria-expanded=false) — 자식 확장 항목이 트리에 렌더되지 않음.
    await expect(header).toHaveAttribute('aria-expanded', 'false');
  });

  test('연결 그룹 헤더 클릭 → 펼침 → 자식 표시', async ({ page }) => {
    await enterEditor(page);
    const header = connGroupHeader(page);

    await header.click();
    await expect(header).toHaveAttribute('aria-expanded', 'true');

    // 펼친 뒤 그 그룹의 확장 자식이 1건 이상 렌더됨.
    const expandedCount = await page.evaluate((groupPath) => {
      const h = document.querySelector(`[data-route-path="${groupPath}"]`);
      const li = h?.closest('li');
      if (!li) return 0;
      return [...li.querySelectorAll('[data-testid="g7le-route-tree-item"]')].filter(
        (c) => c.getAttribute('data-route-kind') === 'extension',
      ).length;
    }, CHECKOUT_GROUP_PATH);
    expect(expandedCount).toBeGreaterThan(0);
  });

  test('확장 편집 모드 진입 후에도 연결 목록 count 유지 + 호스트 라우트 강조', async ({ page }) => {
    await enterEditor(page);
    const header = connGroupHeader(page);

    await header.click(); // 펼치기
    const before = await readConnCount(page);
    expect(before).toBeGreaterThan(0);

    // 첫 확장 자식 클릭 → 확장 편집 모드 진입
    const firstExtChild = page
      .locator(`[data-route-path="${CHECKOUT_GROUP_PATH}"]`)
      .locator('xpath=ancestor::li[1]')
      .locator('[data-testid="g7le-route-tree-item"][data-route-kind="extension"]')
      .first();
    await firstExtChild.click();

    // URL 이 확장 편집 모드로 전환
    await page.waitForURL(/edit=__extension__/, { timeout: 15_000 });

    // 연결 목록 count 가 그대로 유지(줄어들지 않음)
    const after = await readConnCount(page);
    expect(after).toBe(before);

    // 호스트 라우트(/shop/checkout) 노드 강조 유지 — 선택 배경색(#eff6ff = rgb(239,246,255))
    const hostHighlighted = await page.evaluate((routePath) => {
      const node = document.querySelector(`[data-route-path="${routePath}"]`) as HTMLElement | null;
      return (node?.getAttribute('style') ?? '').includes('rgb(239, 246, 255)');
    }, CHECKOUT_ROUTE_PATH);
    expect(hostHighlighted).toBe(true);
  });

  test('확장 편집 모드에서 라우트 클릭 → 캔버스 정상 렌더(빈 화면 회귀 가드)', async ({ page }) => {
    await enterEditor(page);
    const header = connGroupHeader(page);
    await header.click();

    const firstExtChild = page
      .locator(`[data-route-path="${CHECKOUT_GROUP_PATH}"]`)
      .locator('xpath=ancestor::li[1]')
      .locator('[data-testid="g7le-route-tree-item"][data-route-kind="extension"]')
      .first();
    await firstExtChild.click();
    await page.waitForURL(/edit=__extension__/, { timeout: 15_000 });

    // 확장 편집 모드에서 checkout 라우트(화면) 클릭 → route 모드 복원
    await page.locator(`[data-route-path="${CHECKOUT_ROUTE_PATH}"]`).click();
    await page.waitForURL(/route=%2Fshop%2Fcheckout/, { timeout: 15_000 });

    // 캔버스가 비어 있지 않고("표시할 컴포넌트가 없습니다") 편집 가능 노드가 렌더됨.
    await expect
      .poll(
        async () =>
          page.evaluate(() => document.querySelectorAll('[data-editor-path]').length),
        { timeout: 20_000 },
      )
      .toBeGreaterThan(0);

    const hasEmptyMsg = await page.evaluate(() =>
      document.body.innerText.includes('표시할 컴포넌트가 없습니다'),
    );
    expect(hasEmptyMsg).toBe(false);
  });
});
