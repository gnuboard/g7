/**
 * Layout Editor — [버전 기록] 창의 동기화 판 (#888).
 *
 * 레이아웃 갱신·확장 업데이트가 운영자 판을 새 판으로 덮기 직전에 남긴 판은 목록 맨 위(최신)에 놓여도
 * 지금 본문과 다르다. 그 판에 [복원] 이 없으면 덮인 운영자 판을 되돌릴 길이 없다(관리자 코드 편집기 버전 창과 같은 조건).
 * 저장한 사람 없이 동기화가 남긴 판의 저장자는 「시스템」, 출처도 저장자도 없는 옛 판은 「알 수 없음」 이다.
 *
 * 단위 시험(VersionHistoryModal.test.tsx)은 컴포넌트를 jsdom 에 그려 조건을 잠근다. 이 E2E 는 서빙되는 엔진 번들로
 * 실제 편집기에서 창을 열어 같은 결과가 보이는지와, [복원] → 확인 대화상자 → 맨 위 판의 복원 주소로 나가는지를 본다.
 * 목록 응답과 복원 요청은 고정한다 — 개발 사이트의 버전 이력에 좌우되지 않고, 레이아웃을 실제로 되돌리지 않는다.
 *
 * @scenario-manifest tests/scenarios/layout-editor-version-history-pi2.yaml
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page, Route } from '@playwright/test';

const VERSIONS_URL_RE = /\/api\/admin\/templates\/[^/]+\/layouts\/.+\/versions(\?|$)/;
const RESTORE_URL_RE = /\/api\/admin\/templates\/[^/]+\/layouts\/.+\/versions\/(\d+)\/restore$/;

/**
 * 버전 목록 응답 한 건을 만듭니다.
 *
 * @param id 버전 행 ID (= 버전 번호)
 * @param summary 출처 요약 덮어쓰기
 * @param createdByName 저장자 이름 (null 이면 저장자 없음)
 * @returns 목록 항목
 */
function versionRow(
  id: number,
  summary: { source?: string | null; before_overwrite?: boolean },
  createdByName: string | null,
): Record<string, unknown> {
  return {
    id,
    layout_id: 1,
    version: id,
    created_at: '2026-11-16T10:00:00+09:00',
    created_by_name: createdByName,
    changes_summary: { added_count: 1, removed_count: 0, char_diff: 3, source: null, before_overwrite: false, conflict_count: 0, ...summary },
  };
}

/** 맨 위 = 확장 업데이트가 덮기 직전에 남긴 판(저장자 없음), 가운데 = 사람이 저장한 판, 맨 아래 = 출처·저장자 없는 옛 판 */
const ROWS = [
  versionRow(903, { source: 'extension_update', before_overwrite: true }, null),
  versionRow(902, {}, '홍길동'),
  versionRow(901, {}, null),
];

/**
 * 목록 응답을 고정하고 복원 요청을 가로채 버전 ID 를 모읍니다.
 *
 * @param page Playwright page
 * @param restored 복원 요청 버전 ID 수집 배열
 */
async function stubVersions(page: Page, restored: number[]): Promise<void> {
  await page.route(RESTORE_URL_RE, async (route: Route) => {
    const match = RESTORE_URL_RE.exec(new URL(route.request().url()).pathname);
    restored.push(Number(match?.[1]));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { version: 904 } }),
    });
  });
  await page.route(VERSIONS_URL_RE, async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: ROWS }),
    });
  });
}

/**
 * 편집기를 열고 버전 기록 창을 띄웁니다.
 *
 * @param page Playwright page
 */
async function openVersionHistory(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
  await page.waitForLoadState('domcontentloaded', { timeout: 60_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 60_000 });

  await page.waitForSelector('[data-testid="g7le-toolbar-versions"]', { timeout: 30_000 });
  await page.getByTestId('g7le-toolbar-versions').click();
  await expect(page.getByTestId('g7le-version-row-903')).toBeVisible({ timeout: 30_000 });
}

test.describe('레이아웃 편집기 버전 기록 — 동기화 판 (#888)', () => {
  test.describe.configure({ timeout: 150_000 });

  /**
   * @scenario area=version_restore
   * @effects version_latest_before_overwrite_row_keeps_restore
   */
  test('맨 위 덮기 전 판에도 [복원] 이 있고, 확인하면 그 판의 복원 주소로 나간다 @layout-editor', async ({ page }) => {
    const restored: number[] = [];
    await stubVersions(page, restored);
    await openVersionHistory(page);

    // 맨 위(최신) 배지가 있는 판이어도 덮기 전 판이면 [복원] 이 있다
    await expect(page.getByTestId('g7le-version-latest-903')).toBeVisible();
    const topRestore = page.getByTestId('g7le-version-restore-903');
    await expect(topRestore).toBeVisible();
    await expect(page.getByTestId('g7le-version-restore-902')).toBeVisible();

    await topRestore.click();
    await page.getByTestId('g7le-dialog-confirm').click();
    await expect.poll(() => restored, { timeout: 20_000 }).toEqual([903]);
  });

  /**
   * @scenario area=version_list
   * @effects version_row_sync_author_shows_system
   */
  test('저장자: 동기화 판은 「시스템」, 사람 판은 이름, 옛 판은 「알 수 없음」 @layout-editor', async ({ page }) => {
    await stubVersions(page, []);
    await openVersionHistory(page);

    await expect(page.getByTestId('g7le-version-author-903')).toHaveText(/시스템/);
    await expect(page.getByTestId('g7le-version-author-902')).toHaveText(/홍길동/);
    await expect(page.getByTestId('g7le-version-author-901')).toHaveText(/알 수 없음/);
    for (const id of [903, 902, 901]) {
      await expect(page.getByTestId(`g7le-version-author-${id}`)).not.toContainText('layout_editor.');
    }
  });
});
