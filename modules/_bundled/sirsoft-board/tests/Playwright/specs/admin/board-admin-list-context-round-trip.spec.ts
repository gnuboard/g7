/**
 * 관리자 게시글 목록 컨텍스트 왕복 — 목록 상태(page/검색)가 상세·폼을 거쳐도 살아남는지 (공개 이슈 #75).
 *
 * 결함: 목록 클러스터(목록 ↔ 상세 ↔ 작성/수정 폼) 안의 이동에 `mergeQuery` 가 빠져 있어,
 *   2페이지에서 글을 열었다가 목록으로 돌아오면 1페이지로 되돌아갔다. 특히 폼 '취소' 버튼은
 *   목적지가 삼항 표현식이라 정적 검사에서도 오래 빠져 있었다 — 저장 leg 만 고쳐지고 취소 leg 는
 *   남아 "저장하면 2페이지 유지 / 취소하면 1페이지 리셋" 비대칭이 생겼다.
 *
 * 단위/정적:
 *   - modules/_bundled/sirsoft-board/resources/js/__tests__/layouts/admin-list-context-round-trip.test.ts
 *     가 실 JSON 의 액션 params 를 전수 고정
 *   - audit 룰 layout-list-context-navigate-merge-query 가 표현식 경로까지 판정해 재발 차단
 *   이 spec 은 브라우저 수준(실제 주소창 URL)을 담당한다.
 *
 * 시드 의존: 샘플 시드의 자유게시판(`free`, 30건) — 관리자 목록 15건/페이지이므로 2페이지가 존재한다.
 *
 * @scenario board-list-context-round-trip
 * @axes leg=enter leg=back
 * @effects list_return_preserves_query
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SLUG = 'free';
const LIST_URL = `/admin/board/${SLUG}`;

/**
 * 현재 URL 의 쿼리 파라미터를 돌려줍니다.
 *
 * @param url 페이지 URL
 * @returns 쿼리 파라미터
 */
function queryOf(url: string): URLSearchParams {
  return new URL(url).searchParams;
}

test.describe('관리자 게시글 목록 컨텍스트 왕복 (#75)', () => {
  // @scenario leg=enter
  // @effects list_return_preserves_query
  test('2페이지에서 글 제목을 누르면 상세 URL 에 page 가 실린다', async ({ page, boardManageToken }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(`${LIST_URL}?page=2`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 제목 Span 이 이동 액션을 갖는다 (행 전체가 아니라 제목만 클릭 대상).
    await page.locator('tbody tr span.cursor-pointer').first().click();
    await page.waitForURL(new RegExp(`/admin/board/${SLUG}/post/\\d+`), { timeout: 30_000 });

    expect(queryOf(page.url()).get('page')).toBe('2');
  });

  // @scenario leg=back
  // @effects list_return_preserves_query
  test('상세에서 목록으로 돌아오면 2페이지가 복원된다', async ({ page, boardManageToken }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(`${LIST_URL}?page=2`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    await page.locator('tbody tr span.cursor-pointer').first().click();
    await page.waitForURL(new RegExp(`/admin/board/${SLUG}/post/\\d+`), { timeout: 30_000 });
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await page.locator('#back_button').click();
    await page.waitForURL(new RegExp(`/admin/board/${SLUG}\\?`), { timeout: 30_000 });

    expect(queryOf(page.url()).get('page')).toBe('2');
  });

  // @scenario leg=back
  // @effects list_return_preserves_query
  test('작성 폼에서 취소하면 보고 있던 페이지로 돌아온다', async ({ page, boardManageToken }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(`${LIST_URL}/create?page=2`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await page.locator('#header_cancel_button').click();
    await page.waitForURL(new RegExp(`/admin/board/${SLUG}\\?`), { timeout: 30_000 });

    expect(queryOf(page.url()).get('page')).toBe('2');
  });
});
