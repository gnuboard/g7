/**
 * Layout Editor — 버전 이력 목록의 상한 조회와 '더 보기'.
 *
 * 버전 행은 저장할 때마다 쌓이고 정리되지 않는다. 목록이 전량을 내려주면 오래 쓴 레이아웃일수록
 * 모달 하나를 여는 데 수천 행이 실린다(#518 / 공개 #76 의 목록 하위 컬렉션과 같은 형태). 그래서
 * 서버가 조회 건수를 제한하고, 화면은 한 묶음씩 넓혀 간다.
 *
 * 단위 테스트(VersionHistoryModal.test.tsx)는 훅의 상태 전이를 잠근다. 이 E2E 는 브라우저에서
 *  1. 실제 편집기에서 버전 모달이 열리고 목록이 뜨는지,
 *  2. 첫 요청이 `?limit=` 을 달고 나가는지(상한 없이 전량 요청하지 않는지),
 *  3. 한 묶음이 가득 찼을 때만 '더 보기' 가 뜨고, 누르면 **더 넓은 상한**으로 재조회하는지,
 *  4. 가득 차지 않으면 '더 보기' 가 뜨지 않는지
 * 를 확인한다. 3·4 는 개발 사이트의 실제 버전 수에 좌우되지 않도록 응답만 고정한다(요청은 실제로
 * 브라우저가 만든 것을 검사한다).
 *
 * @scenario resource=layout_version,endpoint=list,observation=consumer_screen
 * @effects version_list_request_carries_limit, version_list_load_more_widens_limit, version_list_load_more_hidden_when_batch_not_full
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page, Route } from '@playwright/test';

/** 서버 기본 한 묶음 크기 — useLayoutVersions.VERSION_PAGE_SIZE 와 같은 값 */
const VERSION_PAGE_SIZE = 100;

const VERSIONS_URL_RE = /\/api\/admin\/templates\/[^/]+\/layouts\/[^/]+\/versions(\?|$)/;

/**
 * 버전 목록 응답 한 건을 만듭니다.
 *
 * @param id 버전 행 ID
 * @returns 목록 항목
 */
function versionRow(id: number): Record<string, unknown> {
  return {
    id,
    version: id,
    is_latest: id === 1,
    created_at: '2026-01-01T00:00:00+09:00',
    created_at_formatted: '2026-01-01 00:00',
    author_name: 'tester',
    changes_summary: { added_count: 1, removed_count: 0, char_diff: 10 },
  };
}

/**
 * 편집기를 열고 버전 이력 모달을 띄웁니다.
 *
 * @param page Playwright page
 */
async function openVersionHistory(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

  await page.waitForSelector('[data-testid="g7le-toolbar-versions"]', { timeout: 20_000 });
  await page.getByTestId('g7le-toolbar-versions').click();
  await page.waitForSelector('[data-testid="g7le-version-history"]', { timeout: 20_000 });
}

/**
 * 버전 목록 응답을 고정 개수로 대체합니다.
 *
 * @param page Playwright page
 * @param count 응답에 담을 행 수
 * @param seen 요청 URL 수집 배열
 */
async function stubVersions(page: Page, count: number, seen: string[]): Promise<void> {
  await page.route(VERSIONS_URL_RE, async (route: Route) => {
    seen.push(route.request().url());
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: Array.from({ length: count }, (_, i) => versionRow(i + 1)),
      }),
    });
  });
}

test.describe('레이아웃 편집기 버전 이력 — 상한 조회', () => {
  test('첫 조회가 상한(limit)을 달고 나간다 @layout-editor', async ({ page }) => {
    const seen: string[] = [];
    await stubVersions(page, 3, seen);

    await openVersionHistory(page);
    await page.waitForSelector('[data-testid="g7le-version-history-list"]', { timeout: 20_000 });

    expect(seen.length).toBeGreaterThan(0);
    // 상한 없이 나가면 오래 쓴 레이아웃에서 전량이 실린다
    expect(seen[0]).toContain(`limit=${VERSION_PAGE_SIZE}`);
  });

  test('한 묶음이 가득 차지 않으면 더 보기가 없다 @layout-editor', async ({ page }) => {
    const seen: string[] = [];
    await stubVersions(page, 3, seen);

    await openVersionHistory(page);
    await page.waitForSelector('[data-testid="g7le-version-history-list"]', { timeout: 20_000 });

    // 존재를 먼저 확정한 뒤 부재를 단언한다 — 렌더 전에는 어떤 부재 단언도 무조건 통과한다
    await expect(page.getByTestId('g7le-version-row-1')).toBeVisible();
    await expect(page.getByTestId('g7le-version-history-load-more')).toHaveCount(0);
  });

  test('묶음이 가득 차면 더 보기로 상한을 넓혀 재조회한다 @layout-editor', async ({ page }) => {
    const seen: string[] = [];
    await stubVersions(page, VERSION_PAGE_SIZE, seen);

    await openVersionHistory(page);
    await page.waitForSelector('[data-testid="g7le-version-history-list"]', { timeout: 20_000 });

    const loadMore = page.getByTestId('g7le-version-history-load-more');
    await expect(loadMore).toBeVisible();

    await loadMore.click();
    await expect.poll(() => seen.length, { timeout: 20_000 }).toBeGreaterThan(1);

    // 두 번째 요청은 반드시 더 넓은 상한이어야 한다 — 같은 상한으로 다시 부르면
    // 같은 묶음이 돌아와 '더 보기' 가 영원히 아무 일도 하지 않는다
    expect(seen[1]).toContain(`limit=${VERSION_PAGE_SIZE * 2}`);
  });
});
