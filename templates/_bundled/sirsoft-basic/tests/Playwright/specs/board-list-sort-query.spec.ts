/**
 * 사용자 게시판 목록 — URL 정렬 파라미터가 서버까지 도달하는지 검증 (#534 검수 발견)
 *
 * 배경:
 * - 서버 계약(`PostService::extractSortParams`)의 정렬 우선순위는
 *   쿼리 파라미터(sort_by/sort_order) > 게시판 설정(order_by) > 기본값 이다.
 * - 그런데 사용자 목록 레이아웃의 데이터소스가 sort_by/sort_order 를 싣지 않아
 *   `/board/{slug}?sort_by=view_count` 로 들어가도 요청이 `posts?page=1` 로 나갔다.
 *   목록은 조용히 기존 정렬 그대로였다 — 예외도 콘솔 경고도 없다.
 * - 관리자 목록은 정상 동작했으므로 단위 테스트로는 드러나지 않았고, 실제 요청 URL 을
 *   봐야만 확인된다. 그래서 **네트워크 요청을 가로채 파라미터를 직접 검사한다.**
 *
 * 회귀 방향이 둘이라 양쪽을 다 본다:
 *  1) URL 에 정렬이 있으면  → 요청에 실려야 한다
 *  2) URL 에 정렬이 없으면  → 실리지 **않아야** 한다.
 *     기본값을 박아 `sort_by=created_at` 이 항상 나가면 게시판 설정(order_by)이
 *     덮여, 운영자가 "조회순" 으로 설정해도 최신순으로 나온다.
 */
import { test, expect, type Page } from '@playwright/test';

/** 게시글 목록 API 경로 (게시판 slug 무관 매칭) */
const POSTS_ENDPOINT = /\/api\/modules\/sirsoft-board\/boards\/[^/]+\/posts(\?|$)/;

/**
 * 목록 화면 진입 중 발생한 게시글 목록 요청의 쿼리 파라미터를 수집합니다.
 *
 * @param page Playwright 페이지
 * @param path 진입할 사용자 목록 경로
 * @return 수집된 URLSearchParams 배열 (요청 순서)
 */
async function capturePostsRequests(page: Page, path: string): Promise<URLSearchParams[]> {
  const captured: URLSearchParams[] = [];

  page.on('request', (req) => {
    if (POSTS_ENDPOINT.test(req.url())) {
      captured.push(new URL(req.url()).searchParams);
    }
  });

  await page.goto(path);
  await page.waitForResponse(
    (res) => POSTS_ENDPOINT.test(res.url()) && res.status() === 200,
    { timeout: 15_000 }
  );

  return captured;
}

/**
 * 목록이 있는 공개 게시판 slug 하나를 공개 API 로 찾습니다.
 * 시드 데이터에 특정 게시판이 있다고 가정하지 않는다 — 회귀는 레이아웃에 있지 특정 게시판에 있지 않다.
 *
 * @param page Playwright 페이지
 * @return 게시판 slug 또는 없으면 null
 */
async function findPublicBoardSlug(page: Page): Promise<string | null> {
  const res = await page.request.get('/api/modules/sirsoft-board/boards/board-menu', {
    headers: { Accept: 'application/json' },
  });

  if (!res.ok()) return null;

  const body = await res.json();
  const boards = body?.data?.data ?? body?.data ?? [];

  return Array.isArray(boards) && boards.length > 0 ? (boards[0].slug ?? null) : null;
}

test.describe('사용자 게시판 목록 — URL 정렬 파라미터', () => {
  test('URL 의 sort_by/sort_order 가 목록 요청에 실린다', async ({ page }) => {
    const slug = await findPublicBoardSlug(page);
    test.skip(!slug, '공개 게시판이 없어 검증할 수 없습니다');

    const requests = await capturePostsRequests(
      page,
      `/board/${slug}?sort_by=view_count&sort_order=desc`
    );

    expect(requests.length, '게시글 목록 요청').toBeGreaterThan(0);

    // 마지막 요청이 화면에 실제로 반영된 요청이다.
    const params = requests[requests.length - 1];

    expect(params.get('sort_by'), 'sort_by 파라미터').toBe('view_count');
    expect(params.get('sort_order'), 'sort_order 파라미터').toBe('desc');
  });

  test('URL 에 정렬이 없으면 정렬 파라미터를 보내지 않는다 (게시판 설정 보존)', async ({ page }) => {
    const slug = await findPublicBoardSlug(page);
    test.skip(!slug, '공개 게시판이 없어 검증할 수 없습니다');

    const requests = await capturePostsRequests(page, `/board/${slug}`);

    expect(requests.length, '게시글 목록 요청').toBeGreaterThan(0);

    for (const params of requests) {
      // 빈 값으로도 보내면 안 된다 — 서버의 `??` 사슬에서 빈 문자열이 게시판 설정을
      // 가로챌 여지를 남기지 않는다.
      expect(params.get('sort_by') || '', 'sort_by 미전송').toBe('');
      expect(params.get('sort_order') || '', 'sort_order 미전송').toBe('');
    }
  });
});
