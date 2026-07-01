/**
 * Layout Editor — 버전 히스토리 모달 + 실데이터 미리보기
 *
 * 기존 LayoutController 의 versions/restoreVersion/storePreview API 재사용(신규 백엔드 없음).
 * 브라우저 통합 검증:
 *  - 🕘 버전 기록 버튼 활성 + 클릭 시 모달 열림 + 버전 목록(또는 빈 상태) 노출
 *  - 👁 미리보기 버튼 활성 + 클릭 시 /preview/{token} 새 창 열림
 *  - 라우트 미선택(layoutName 없음) 시 두 버튼 비활성
 *
 * 버전 목록·복원의 응답 정합/권한 가드는 LayoutControllerTest.php 가 커버 — 본 spec 은
 * 위지윅 chrome 의 버튼 활성/모달 노출/새 창 열림 책임만.
 *
 * @scenario toolbar_button + edit_mode + layout_name_present + versions_state + preview_result
 * @effects preview_button_enabled_when_layout_selected + preview_creates_temp_record_and_opens_window_with_token + version_button_disabled_when_no_layout_name + version_modal_lists_saved_versions_with_change_summary + version_modal_empty_state_when_no_versions
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const EDITOR_URL_HOME = '/admin/layout-editor/sirsoft-basic?route=%2F';
const EDITOR_URL_NO_ROUTE = '/admin/layout-editor/sirsoft-basic';

async function enterEditor(
  page: import('@playwright/test').Page,
  url: string,
): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(url);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
}

test.describe('@layout-editor version history + preview', () => {
  test('🕘 버전 기록 버튼 클릭 시 모달 열림 + 목록 또는 빈 상태 노출', async ({ page }) => {
    await enterEditor(page, EDITOR_URL_HOME);
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const versionBtn = page.getByTestId('g7le-toolbar-versions');
    await expect(versionBtn).toBeEnabled();
    await versionBtn.click();

    // 모달 본체 노출
    await expect(page.getByTestId('g7le-version-history')).toBeVisible();
    await expect(page.getByTestId('g7le-version-history-title')).toBeVisible();

    // 로딩이 끝나면 목록(list) 또는 빈 상태(empty) 중 하나가 노출 — 둘 다 정상.
    await expect
      .poll(
        async () =>
          (await page.locator('[data-testid="g7le-version-history-list"]').count()) +
          (await page.locator('[data-testid="g7le-version-history-empty"]').count()),
        { timeout: 15_000 },
      )
      .toBeGreaterThan(0);

    // 닫기
    await page.getByTestId('g7le-version-history-close').click();
    await expect(page.getByTestId('g7le-version-history')).toHaveCount(0);
  });

  test('👁 미리보기 버튼 클릭 시 /preview/{token} 새 창 열림', async ({ page, context }) => {
    await enterEditor(page, EDITOR_URL_HOME);
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const previewBtn = page.getByTestId('g7le-toolbar-preview');
    await expect(previewBtn).toBeEnabled();

    const popupPromise = context.waitForEvent('page', { timeout: 20_000 });
    await previewBtn.click();
    const popup = await popupPromise;
    await popup.waitForLoadState('domcontentloaded', { timeout: 20_000 });

    // 새 창 URL 은 /preview/{token}
    expect(new URL(popup.url()).pathname).toMatch(/^\/preview\/[0-9a-f-]+$/);

    // 원래 위지윅 편집 화면은 이탈하지 않고 유지
    expect(page.url()).toMatch(/\/admin\/layout-editor\/sirsoft-basic/);
    await popup.close();
  });

  test('라우트 미선택 시 버전/미리보기 버튼 비활성', async ({ page }) => {
    await enterEditor(page, EDITOR_URL_NO_ROUTE);
    // 라우트 미선택 상태(layoutName 없음) — 두 버튼 모두 disabled
    await expect(page.getByTestId('g7le-toolbar-versions')).toBeDisabled();
    await expect(page.getByTestId('g7le-toolbar-preview')).toBeDisabled();
  });
});
