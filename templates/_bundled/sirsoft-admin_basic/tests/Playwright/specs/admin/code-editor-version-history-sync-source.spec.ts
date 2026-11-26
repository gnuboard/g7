/**
 * sirsoft-admin_basic — 코드 편집기 [버전 히스토리] 창의 동기화 판 표시
 *
 * 확장 업데이트·레이아웃 갱신이 운영자 판을 새 판으로 덮기 직전에 남긴 판은 목록 맨 위(최신)에 놓여도
 * 지금 본문과 다르다. 그 판에 [복구] 가 없으면 덮인 운영자 판을 되돌릴 길이 없다. 또 사람이 아니라 동기화가
 * 남긴 판의 저장자는 「시스템」 으로 보이고, 저장자를 모르는 옛 판에 대체 글자(「admin」)를 지어내지 않는다.
 *
 * 단위 시험(admin-template-layout-version-source.test.tsx)은 구운 창 JSON 의 판 카드를 그려 문구를 잰다.
 * 이 E2E 는 실제 편집 화면에서 창을 열어 브라우저가 그린 결과와, [복구] 가 맨 위 판의 복구 주소로 나가는지를 본다.
 * 목록 응답과 복구 요청은 고정한다 — 개발 사이트의 실제 버전 이력에 좌우되지 않고, 레이아웃을 실제로 되돌리지 않는다.
 *
 * @scenario-manifest tests/scenarios/admin-template-layout-version-source.yaml
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page, Route } from '@playwright/test';

const VERSIONS_URL_RE = /\/api\/admin\/templates\/[^/]+\/layouts\/[^/]+\/versions(\?|$)/;
const RESTORE_URL_RE = /\/api\/admin\/templates\/[^/]+\/layouts\/[^/]+\/versions\/(\d+)\/restore$/;

/**
 * 버전 목록 응답 한 건을 만듭니다 (`changes_summary` 는 LayoutVersionResource 가 내리는 모양).
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
    version: id,
    created_at: `2026-11-1${id} 10:00`,
    created_by_name: createdByName,
    changes_summary: {
      added_count: 1,
      removed_count: 0,
      char_diff: 3,
      source: null,
      before_overwrite: false,
      conflict_count: 0,
      ...summary,
    },
  };
}

/** 맨 위 = 레이아웃 갱신이 덮기 직전에 남긴 판(저장자 없음), 가운데 = 사람이 저장한 판, 맨 아래 = 출처·저장자 없는 옛 판 */
const ROWS = [
  versionRow(3, { source: 'refresh', before_overwrite: true }, null),
  versionRow(2, {}, '홍길동'),
  versionRow(1, {}, null),
];

/**
 * 목록 응답을 고정하고, 복구 요청을 가로채 그 버전 ID 를 모읍니다.
 *
 * @param page Playwright page
 * @param restored 복구 요청 버전 ID 수집 배열
 */
async function stubVersions(page: Page, restored: number[]): Promise<void> {
  await page.route(RESTORE_URL_RE, async (route: Route) => {
    const match = RESTORE_URL_RE.exec(new URL(route.request().url()).pathname);
    restored.push(Number(match?.[1]));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { version: 4 } }),
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
 * 코드 편집기를 열고 버전 히스토리 창을 띄웁니다.
 *
 * @param page Playwright page
 * @param token 인증 토큰
 */
async function openVersionHistory(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/templates/sirsoft-basic/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  await page.waitForSelector('[data-testid="layout-version-history-open"]', { timeout: 60_000 });
  // 편집 화면은 파일 목록·본문을 다 받을 때까지 스켈레톤 덮개가 클릭을 가로챈다 — 걷힌 뒤 누른다
  await expect(page.locator('#g7-skeleton-overlay')).toHaveCount(0, { timeout: 60_000 });
  await page.getByTestId('layout-version-history-open').click();
  await expect(page.getByTestId('layout-version-row-3')).toBeVisible({ timeout: 20_000 });
}

test.describe('@sirsoft-admin_basic 코드 편집기 버전 히스토리 — 동기화 판', () => {
  // 편집 화면 첫 진입(Monaco·파일 목록)이 개발 서버에서 30초를 넘길 수 있다
  test.describe.configure({ timeout: 150_000 });

  /**
   * @scenario case=restore_latest_before_overwrite
   * @effects version_row_latest_before_overwrite_can_restore, version_row_shows_sync_source_wording
   */
  test('맨 위 덮기 전 판에 출처 한 줄과 [복구] 가 있고, 누르면 그 판의 복구 주소로 나간다', async ({ page, layoutEditToken }) => {
    const restored: number[] = [];
    await stubVersions(page, restored);
    await openVersionHistory(page, layoutEditToken);

    const top = page.getByTestId('layout-version-row-3');
    await expect(page.getByTestId('layout-version-source-0')).toHaveText('레이아웃 갱신으로 덮기 전 운영자 판');
    // 맨 위(최신)라도 덮기 전 판이면 [복구] 가 있다 — 세 판 모두 복구 가능
    const topRestore = top.getByRole('button', { name: /복구/ });
    await expect(topRestore).toBeVisible();
    await expect(page.locator('[data-testid^="layout-version-row-"]').getByRole('button', { name: /복구/ })).toHaveCount(3);

    await topRestore.click();
    await expect.poll(() => restored, { timeout: 20_000 }).toEqual([3]);
  });

  /**
   * @scenario case=saved_by_system
   * @effects version_row_saved_by_system_for_sync_rows
   */
  test('저장자: 동기화 판은 「시스템」, 사람 판은 이름, 옛 판은 줄 없음 — 「admin」 대체 글자 없음', async ({ page, layoutEditToken }) => {
    await stubVersions(page, []);
    await openVersionHistory(page, layoutEditToken);

    await expect(page.getByTestId('layout-version-saved-by-system-0')).toHaveText('저장자: 시스템');
    await expect(page.getByTestId('layout-version-saved-by-1')).toHaveText('저장자: 홍길동');
    // 존재를 확정한 뒤 부재를 단언한다
    await expect(page.getByTestId('layout-version-row-1')).toBeVisible();
    await expect(page.getByTestId('layout-version-saved-by-2')).toHaveCount(0);
    await expect(page.getByTestId('layout-version-saved-by-system-2')).toHaveCount(0);

    const listText = (await page.getByTestId('layout-version-history-list').textContent()) ?? '';
    expect(listText).not.toContain('admin');
    expect(listText).not.toContain('$t:');
  });
});
