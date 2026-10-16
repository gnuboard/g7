/**
 * 게시글 상세 이전/다음 네비게이션 + 목록 복귀 상태 보존 — 사용자 페이지 (이슈 #413 item 45, 47).
 *
 * - 45-1: 목록(category/search/page)에서 상세 진입 후 '목록' 클릭 시 동일 query 로 복귀.
 *         행 클릭/목록 버튼 navigate 의 mergeQuery:true 가 URL 상태를 왕복 보존.
 * - 47-1: 카테고리가 섞인 게시판에서 이전/다음 글은 현재 글과 동일 카테고리 글만 이동.
 * - 47-4: 답글 상세에서는 이전/다음 링크가 노출되지 않는다 (원글 인용 블록이 안내 담당).
 *
 * 단위/통합:
 *   - 백엔드 PostNavigationTest 가 category/reply/notice/secret/blinded/boundary 를 cross product 로 검증
 *   - Vitest board-post-navigation-list-return 이 mergeQuery 왕복 + 답글 버튼 미렌더를 고정
 *   이 spec 은 브라우저 수준(URL 보존 / 동일 카테고리 이동 / 답글 버튼 부재)을 담당한다.
 *
 * @scenario board-post-navigation
 * @axes post_kind=original post_kind=reply category=same position=middle
 * @effects list_return_preserves_query,
 *          navigation_limited_to_same_category,
 *          navigation_null_for_reply
 *
 * 활성화 절차: navtest 게시판(원글 20 + 답글 9 + 비밀글 2 + 블라인드 1, 일부 카테고리)이 시드되고
 * PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe 로 전환.
 */
import { test, expect } from '../../fixtures/board-auth';

const BOARD_SLUG = 'navtest';

// 동일 카테고리(자유) 원글들이 섞여 있는 중간 글 id (시드 환경에 맞게 조정)
const FREE_MIDDLE_POST_ID = 49;
// depth 2 답글 상세 (시드: /board/navtest/66)
const REPLY_POST_ID = 66;

test.describe.skip('게시글 네비게이션 + 목록 복귀 — 사용자 페이지 (#413)', () => {
  // @scenario post_kind=original, position=middle
  // @effects list_return_preserves_query
  test('목록(카테고리+페이지) → 상세 → 목록 복귀 시 URL query 가 보존된다', async ({ page }) => {
    const listUrl = `/board/${BOARD_SLUG}?category=자유&page=2`;
    await page.goto(listUrl);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 첫 번째 글 행(상세 주소를 가진 링크, #757) 클릭 → 상세 진입. 글쓰기 링크는 제외한다
    const firstRow = page.locator(`a[href^="/board/${BOARD_SLUG}/"]:not([href*="/write"])`).first();
    await firstRow.click();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 상세 URL 에 목록 상태가 부착되어 있어야 함 (mergeQuery)
    expect(page.url()).toContain('category=');
    expect(page.url()).toContain('page=2');

    // '목록' 링크 클릭 → 동일 query 로 복귀
    await page.getByRole('link', { name: /(목록|Back to List)$/i }).first().click();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    expect(page.url()).toContain(`/board/${BOARD_SLUG}`);
    expect(page.url()).toContain('category=');
    expect(page.url()).toContain('page=2');
  });

  // @scenario post_kind=original, category=same, position=middle
  // @effects navigation_limited_to_same_category
  test('카테고리 글 상세의 이전/다음 이동은 동일 카테고리 글로만 이동한다', async ({ page }) => {
    await page.goto(`/board/${BOARD_SLUG}/${FREE_MIDDLE_POST_ID}?category=자유`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const nextLink = page.getByRole('link', { name: /(다음글|Next)(?!\s*page)/i }).first();
    await expect(nextLink).toBeVisible({ timeout: 10_000 });
    await nextLink.click();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 이동한 상세의 카테고리 라벨이 동일(자유)해야 함
    await expect(page.getByText('자유').first()).toBeVisible({ timeout: 10_000 });
  });

  // @scenario post_kind=reply
  // @effects navigation_null_for_reply
  test('답글 상세에서는 이전/다음 링크가 노출되지 않는다', async ({ page }) => {
    await page.goto(`/board/${BOARD_SLUG}/${REPLY_POST_ID}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 같은 네비게이션 영역의 '목록' 링크가 그려진 뒤에 부재를 잰다 — 렌더 전 0 으로 통과하지 않게
    await expect(page.getByRole('link', { name: /(목록|Back to List)$/i }).first()).toBeVisible({ timeout: 10_000 });

    // 이전/다음은 링크(#757)다. 단추로 되돌아가도 거짓 통과하지 않도록 두 역할을 함께 단언한다.
    // '이전 페이지'·'Previous page'(댓글 페이지 번호)는 제외한다.
    const prevName = /(이전글|Previous)(?!\s*page)/i;
    const nextName = /(다음글|Next)(?!\s*page)/i;
    for (const role of ['link', 'button'] as const) {
      await expect(page.getByRole(role, { name: prevName })).toHaveCount(0);
      await expect(page.getByRole(role, { name: nextName })).toHaveCount(0);
    }
  });
});
