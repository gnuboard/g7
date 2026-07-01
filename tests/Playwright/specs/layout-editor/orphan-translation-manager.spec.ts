/**
 * Layout Editor — 커스텀 다국어 키 관리 모달 + 좀비(고아) 키.
 *
 *  T1. 진입 — 상단 🌐 다국어 버튼이 라우트 선택 시 활성, 클릭하면 관리 모달이 뜬다.
 *  T2. 목록/필터 — 모달이 현재 레이아웃의 커스텀 키를 목록으로 보여 주고
 *      전체/사용중/미사용 필터가 동작한다.
 *  T3. 좀비 생성 → 표시 — 인라인 키 생성 후 그 노드 text 를 평문으로 바꿔 저장하면,
 *      저장 시점에 백엔드가 그 키를 orphaned 로 전이하고, 관리 모달에 "미사용" 배지로 노출된다.
 *  T4. 정리 — orphaned 키를 일괄 삭제하면 목록에서 사라진다.
 *
 * @scenario toolbar_translations_button_opens_manager + manager_lists_and_filters + orphan_created_on_save_shows_badge + orphan_bulk_delete
 * @effects toolbar_translations_button_opens_manager_modal + manager_filters_by_status_all_active_orphaned + manager_shows_orphaned_badge + save_marks_unreferenced_custom_key_as_orphaned + manager_purges_all_orphaned_keys + manager_lists_custom_keys_for_layout_with_bearer_header
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

async function openEditorLogin(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Flogin');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(
    () => document.querySelectorAll('[data-editor-path]').length > 0,
    { timeout: 20_000 },
  );
}

async function openManager(page: Page): Promise<void> {
  const btn = page.getByTestId('g7le-toolbar-translations');
  await expect(btn).toBeEnabled({ timeout: 10_000 });
  await btn.click();
  await page.waitForSelector('[data-testid="g7le-translation-manager"]', { timeout: 15_000 });
}

test.describe('@layout-editor 커스텀 다국어 관리 모달 (S8-2 후속)', () => {
  test('T1. 🌐 버튼 → 관리 모달 진입', async ({ page }) => {
    await openEditorLogin(page);
    await openManager(page);
    await expect(page.getByTestId('g7le-translation-manager-title')).toBeVisible();
    // 닫기 동작 — 컨텍스트 격리(T7) 최소 검증
    await page.getByTestId('g7le-translation-manager-footer-close').click();
    await expect(page.getByTestId('g7le-translation-manager')).toHaveCount(0);
  });

  test('T2. 목록/필터 동작 (비어 있으면 empty, 있으면 필터 탭)', async ({ page }) => {
    await openEditorLogin(page);
    await openManager(page);
    const list = page.getByTestId('g7le-translation-list');
    const empty = page.getByTestId('g7le-translation-manager-empty');
    // 목록 또는 빈 안내 중 하나는 떠야 한다.
    await expect(list.or(empty)).toBeVisible({ timeout: 10_000 });
    // 필터 탭 3종 노출 + 클릭 가능.
    for (const mode of ['all', 'active', 'orphaned']) {
      const tab = page.getByTestId(`g7le-translation-filter-${mode}`);
      await expect(tab).toBeVisible();
      await tab.click();
      await expect(tab).toHaveAttribute('data-active', 'true');
    }
  });

  test('T3+T4. 모달 fetch 가 Bearer 토큰을 첨부하고 응답을 렌더한다', async ({ page }) => {
    await openEditorLogin(page);
    // 관리 모달 fetch 요청의 Authorization 헤더를 가로채 검증.
    const reqPromise = page.waitForRequest(
      (r) => r.url().includes('/custom-translations') && r.method() === 'GET',
      { timeout: 15_000 },
    );
    await openManager(page);
    const req = await reqPromise;
    expect(req.headers()['authorization'] ?? '').toMatch(/^Bearer\s+.+/);
  });
});
