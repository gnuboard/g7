/**
 * 블라인드 게시글/댓글 '원글 보기' 원문 접근 차단 — 사용자 페이지 (이슈 #413 item 34-2).
 *
 * 블라인드 처리된 게시글·댓글의 원문은 게시판 관리자(manager/admin.manage) 또는 작성자
 * 본인만 열람할 수 있다. 백엔드(PostResource/CommentResource)가 권한 없는 사용자에게
 * content=null 을 응답하면, 상세 화면의 '원글 보기' 토글 버튼이 노출되지 않는다.
 *
 * 목록 제목·블라인드 안내·사유 문구는 전원 노출(투명성) — 차단 대상은 원문뿐.
 *
 * 단위/통합:
 *   - 백엔드 PostBlindedAccessControlTest 가 status×viewer cross product 로 content 차단을 검증
 *   - Vitest board-blinded-original-access 가 '원글 보기' if 게이트(content 의존)를 고정
 *   이 spec 은 브라우저 수준(비로그인=버튼 미노출 / 관리자=버튼 노출 + 원문 펼침)을 담당한다.
 *
 * @scenario board-blinded-original-access
 * @axes target=post target=comment status=blinded viewer=guest viewer=manager
 * @effects blinded_content_null_for_guest,
 *          blinded_content_visible_to_manager,
 *          blinded_comment_content_matrix
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능하고, 블라인드 상태 게시글/댓글 샘플이
 * 시드된 환경에서 test.describe.skip → test.describe 로 전환.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

// 블라인드 게시글이 시드된 게시판/게시글 (환경에 맞게 조정)
const BOARD_SLUG = 'notice';
const BLINDED_POST_URL = `/board/${BOARD_SLUG}/1`;

test.describe.skip('블라인드 원문 접근 차단 — 사용자 페이지 (#413)', () => {
  // @scenario status=blinded viewer=guest target=post
  // @effects blinded_content_null_for_guest
  test('비로그인 사용자에게 블라인드 게시글 안내는 보이나 원글 보기 버튼은 미노출', async ({ page }) => {
    await page.goto(BLINDED_POST_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 블라인드 안내 박스(눈가림 아이콘)는 노출되어야 함
    await expect(page.locator('[data-icon="eye-slash"]').first()).toBeVisible({ timeout: 10_000 });

    // 원글 보기 버튼은 노출되지 않아야 함 (content=null → if false)
    await expect(page.getByRole('button', { name: /원글 보기|View Original/ })).toHaveCount(0, {
      timeout: 10_000,
    });
  });

  // @scenario status=blinded viewer=manager target=post
  // @effects blinded_content_visible_to_manager
  test('게시판 관리자에게는 원글 보기 버튼이 노출되고 클릭 시 원문이 펼쳐진다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);

    await page.goto(BLINDED_POST_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const viewOriginal = page.getByRole('button', { name: /원글 보기|View Original/ });
    await expect(viewOriginal).toBeVisible({ timeout: 10_000 });

    await viewOriginal.click();

    // 원본 내용 블록이 펼쳐져야 함
    await expect(page.getByText(/원본 내용|Original Content/).first()).toBeVisible({
      timeout: 10_000,
    });
  });

  // @scenario status=blinded viewer=guest target=comment
  // @effects blinded_comment_content_matrix
  test('비로그인 사용자에게 블라인드 댓글 원문 보기 버튼이 미노출', async ({ page }) => {
    await page.goto(BLINDED_POST_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 블라인드 댓글 안내는 노출되나, 댓글 영역의 원문 보기 버튼은 미노출
    const commentArea = page.locator('[data-testid="comment-section"], #comment-section').first();
    await expect(
      commentArea.getByRole('button', { name: /원문 보기|View Original/ }),
    ).toHaveCount(0, { timeout: 10_000 });
  });
});
