/**
 * 관리자 답변 작성 폼 — 부모글 작성자명 노출 (이슈 #413 item 70).
 *
 * 답변(reply) 작성 폼 상단의 "원본 게시글" 카드는 부모글의 작성자명을 표시한다.
 * 백엔드 form-meta 응답은 작성자를 `parent_post.author.name` 으로 노출하므로
 * 레이아웃 바인딩도 동일 키를 참조해야 한다. (이전: `parent_post.user.name` →
 * 항상 undefined 로 평가되어 "작성자 미상" fallback 만 노출되던 회귀를 교정)
 *
 * 단위(Vitest admin-reply-parent-author) 는 레이아웃 JSON 이 author.name 키를
 * 참조하는지 고정하고, 백엔드 form-meta 응답이 author.name 을 포함하는지는 기존
 * Feature 테스트가 검증하므로, 브라우저 수준(실제 작성자명 DOM 렌더)은 이 spec 이 담당한다.
 *
 * @scenario board-reply-parent-author
 * @axes viewer=manager parent_state=published render=author_name
 * @effects reply_form_renders_parent_post_author_name
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능하고, 작성자가 있는 부모글이
 * 시드된 환경에서 test.describe.skip → test.describe 로 전환.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const BOARD_SLUG = 'inquiry';
// 작성자(회원)가 있는 부모글 ID 및 그 작성자명 (환경에 맞게 조정)
const PARENT_ID = 100;
const PARENT_AUTHOR_NAME = '홍길동';

test.describe.skip('관리자 답변 폼 — 부모글 작성자명 노출 (#413)', () => {
  // @scenario viewer=manager parent_state=published render=author_name
  // @effects reply_form_renders_parent_post_author_name
  test('답변 작성 폼의 원본 게시글 카드에 부모글 작성자명이 노출된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);

    await page.goto(`/admin/boards/${BOARD_SLUG}/posts/create?parent_id=${PARENT_ID}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 부모글 작성자명이 실제로 렌더되어야 함 ("작성자 미상" fallback 이 아님)
    await expect(page.getByText(PARENT_AUTHOR_NAME)).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText(/작성자 미상|Unknown author/)).toHaveCount(0, {
      timeout: 5_000,
    });
  });
});
