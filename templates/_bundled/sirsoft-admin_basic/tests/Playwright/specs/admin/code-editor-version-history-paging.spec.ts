/**
 * sirsoft-admin_basic — 코드 편집기 버전 이력의 상한 조회와 '더 보기'
 *
 * 버전 행은 저장할 때마다 쌓이고 정리되지 않는다. 목록이 전량을 내려주면 오래 쓴 레이아웃일수록
 * 모달 하나를 여는 데 수천 행이 실린다(#518). 서버가 조회 건수를 제한한 뒤로 화면이 상한을
 * 넘겨주지 않으면 100건 밖의 버전은 이 화면에서 도달할 수 없다 — 오류 없이 목록이 끝난 것처럼
 * 보일 뿐이라 화면만 보고는 알 수 없다.
 *
 * 위지윅 편집기의 같은 기능은 React 훅(useLayoutVersions)이 담당하고 코어
 * layout-editor/version-history-paging.spec.ts 가 잠근다. 이 spec 은 **레이아웃 JSON 이
 * 구동하는 코드 편집기** 라는 별개 표면을 잠근다 — 두 화면은 코드를 공유하지 않으므로
 * 한쪽이 초록이어도 다른 쪽은 전량 요청일 수 있다.
 *
 * 응답은 고정한다(개발 사이트의 실제 버전 수에 좌우되지 않도록). 요청 URL 은 브라우저가
 * 실제로 만든 것을 검사한다.
 *
 * @scenario resource=layout_version,endpoint=list,surface=code_editor
 * @effects version_list_request_carries_limit, version_list_load_more_widens_limit, version_list_load_more_hidden_when_batch_not_full, version_list_limit_ceiling_disclosed
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page, Route } from '@playwright/test';

/** 한 묶음 크기 — admin_template_layout_edit.json 의 layoutVersionLimit 기본값과 같은 값 */
const VERSION_PAGE_SIZE = 100;

/** 서버 상한 — LayoutVersionListRequest::MAX_LIMIT 과 같은 값 */
const VERSION_MAX_LIMIT = 500;

const VERSIONS_URL_RE = /\/api\/admin\/templates\/[^/]+\/layouts\/[^/]+\/versions(\?|$)/;

/**
 * 버전 목록 응답 한 건을 만듭니다.
 *
 * @param version 버전 번호
 * @returns 목록 항목
 */
function versionRow(version: number): Record<string, unknown> {
  return {
    id: version,
    version,
    is_latest: version === 1,
    created_at: '2026-01-01T00:00:00+09:00',
    created_by_name: 'tester',
    description: `v${version}`,
    lines_count: 10,
    chars_count: 100,
  };
}

/**
 * 버전 목록 응답을 대체하고, 나간 요청 URL 을 수집합니다.
 *
 * `count` 를 생략하면 요청한 상한만큼(서버가 가진 버전이 상한보다 많은 상태) 돌려준다.
 * 응답 크기가 상한을 따라 커져야 화면에 "이 회차가 반영됐다" 는 관측 지점이 생긴다 —
 * 매번 같은 개수를 돌려주면 재렌더를 기다릴 방법이 없어 다음 클릭이 이전 화면에 꽂힌다.
 *
 * @param page Playwright page
 * @param count 응답에 담을 행 수 (생략 시 요청 상한과 동일)
 * @param seen 요청 URL 수집 배열
 */
async function stubVersions(page: Page, count: number | null, seen: string[]): Promise<void> {
  await page.route(VERSIONS_URL_RE, async (route: Route) => {
    const url = route.request().url();
    seen.push(url);

    const requested = Number(new URL(url).searchParams.get('limit') ?? VERSION_PAGE_SIZE);
    const rows = count ?? Math.min(requested, VERSION_MAX_LIMIT);

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: Array.from({ length: rows }, (_, i) => versionRow(i + 1)),
      }),
    });
  });
}

/**
 * 코드 편집기를 열고 버전 이력 모달을 띄웁니다.
 *
 * @param page Playwright page
 * @param token 인증 토큰
 */
async function openVersionHistory(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/templates/sirsoft-basic/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  await page.waitForSelector('[data-testid="layout-version-history-open"]', { timeout: 30_000 });
  await page.getByTestId('layout-version-history-open').click();
  await page.waitForSelector('[data-testid="layout-version-history-list"]', { timeout: 20_000 });
}

test.describe('@sirsoft-admin_basic 코드 편집기 버전 이력 — 상한 조회', () => {
  test('첫 조회가 상한(limit)을 달고 나간다', async ({ page, layoutEditToken }) => {
    const seen: string[] = [];
    await stubVersions(page, 3, seen);

    await openVersionHistory(page, layoutEditToken);

    expect(seen.length).toBeGreaterThan(0);
    // 상한 없이 나가면 오래 쓴 레이아웃에서 전량이 실린다
    expect(seen[seen.length - 1]).toContain(`limit=${VERSION_PAGE_SIZE}`);
  });

  test('한 묶음이 가득 차지 않으면 더 보기가 없다', async ({ page, layoutEditToken }) => {
    const seen: string[] = [];
    await stubVersions(page, 3, seen);

    await openVersionHistory(page, layoutEditToken);

    // 존재를 먼저 확정한 뒤 부재를 단언한다 — 렌더 전에는 어떤 부재 단언도 무조건 통과한다
    await expect(page.getByTestId('layout-version-row-1')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByTestId('layout-version-history-load-more')).toHaveCount(0);
    await expect(page.getByTestId('layout-version-history-limit-reached')).toHaveCount(0);
  });

  test('묶음이 가득 차면 더 보기로 상한을 넓혀 재조회한다', async ({ page, layoutEditToken }) => {
    const seen: string[] = [];
    await stubVersions(page, VERSION_PAGE_SIZE, seen);

    await openVersionHistory(page, layoutEditToken);

    const loadMore = page.getByTestId('layout-version-history-load-more');
    await expect(loadMore).toBeVisible({ timeout: 20_000 });

    const before = seen.length;
    await loadMore.click();
    await expect.poll(() => seen.length, { timeout: 20_000 }).toBeGreaterThan(before);

    // 두 번째 요청은 반드시 더 넓은 상한이어야 한다 — 같은 상한으로 다시 부르면
    // 같은 묶음이 돌아와 '더 보기' 가 영원히 아무 일도 하지 않는다
    expect(seen[seen.length - 1]).toContain(`limit=${VERSION_PAGE_SIZE * 2}`);
  });

  test('서버 상한까지 넓히면 더 보기 대신 도달 한계를 알린다', async ({ page, layoutEditToken }) => {
    const seen: string[] = [];
    // 서버에 상한보다 많은 버전이 있는 상태 — 응답은 늘 요청한 상한만큼 꽉 차서 돌아온다
    await stubVersions(page, null, seen);

    await openVersionHistory(page, layoutEditToken);

    const loadMore = page.getByTestId('layout-version-history-load-more');
    await expect(loadMore).toBeVisible({ timeout: 20_000 });

    // 100 → 200 → 300 → 400 → 500. 다음 회차를 누르기 전에 화면이 그 묶음을 다 그렸는지
    // 확인한다 — 요청이 나갔다는 것만으로 누르면 아직 이전 상한을 들고 있는 화면에 꽂힌다.
    for (const expected of [200, 300, 400, VERSION_MAX_LIMIT]) {
      await loadMore.click();
      await expect
        .poll(() => seen.some((url) => url.includes(`limit=${expected}`)), { timeout: 20_000 })
        .toBe(true);
      await expect
        .poll(async () => page.locator('[data-testid^="layout-version-row-"]').count(), { timeout: 20_000 })
        .toBe(expected);
    }

    // 상한을 넘겨 요청하면 목록 전체가 422 가 되어 보고 있던 이력까지 사라진다.
    // 그래서 더 보기는 감추되, 그냥 사라지면 목록이 끝난 것으로 읽히므로 한계를 명시한다.
    await expect(page.getByTestId('layout-version-history-limit-reached')).toBeVisible();
    await expect(loadMore).toHaveCount(0);
  });
});
