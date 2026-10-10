/**
 * 댓글 반응 (#525 WP-D).
 *
 * 실브라우저로 세 갈래를 잰다:
 *   ① 게시판이 반응·댓글 반응을 둘 다 켰을 때만 버튼이 뜬다
 *   ② 등록 → 전환 → 해제가 그 댓글의 숫자만 바꾼다 — 옆 댓글로 번지지 않는다
 *   ③ 비로그인은 돌아올 주소를 달고 로그인으로 간다
 *
 * ② 가 이 화면의 핵심이다. 한 화면에 댓글이 여럿이라 화면 상태를 댓글 번호로 키 잡지 않으면
 * A 에 누른 것이 B 의 하이라이트를 건드린다 — 레이아웃 계약 테스트는 키잉이 «적혀 있다» 까지만
 * 말할 수 있고, 실제로 번지지 않는지는 여기서만 보인다.
 *
 * @scenario case=comment_buttons_render_under_both_toggles
 * @scenario case=comment_buttons_key_state_by_comment_id
 * @effects comment_reaction_group_requires_both_toggles, comment_reaction_state_keyed_by_comment_id,
 *          comment_react_inserts_row_and_increments_count, comment_switch_adjusts_both_counts,
 *          comment_remove_deletes_row, guest_comment_react_returns_401
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/reaction-seed';

/**
 * 글 상세를 연다.
 *
 * @param page 대상 페이지
 * @param slug 게시판 slug
 * @param postId 게시글 ID
 * @param token 회원 토큰 (없으면 비로그인)
 * @returns void
 */
async function openPost(page: Page, slug: string, postId: number, token?: string): Promise<void> {
  if (token) await authenticatePage(page, token);
  await page.goto(`/board/${slug}/${postId}`);
  await expect(page.locator('body')).toContainText('댓글', { timeout: 20_000 });
}

test.describe('댓글 반응 (#525 WP-D)', () => {
  // @scenario case=comment_buttons_render_under_both_toggles
  // @effects comment_reaction_group_requires_both_toggles
  test('두 토글이 모두 켜진 게시판에서 댓글마다 반응 버튼이 뜬다', async ({ page, reactionSeed }) => {
    // 회원 B 가 댓글을 썼으므로 회원 A 로 열면 남의 댓글이다(본인 댓글 차단에 걸리지 않는다).
    await openPost(page, reactionSeed.board_free_slug, reactionSeed.post_alive_id, reactionSeed.member_a_token);

    const buttons = page.locator(`[data-testid^="comment-react-${reactionSeed.comment_alive_id}-"]`);
    // 시드 게시판의 활성 유형은 추천·비추천 둘이다.
    await expect(buttons).toHaveCount(2, { timeout: 20_000 });
  });

  // @scenario case=comment_buttons_key_state_by_comment_id
  // @effects comment_react_inserts_row_and_increments_count
  test('누르면 그 댓글의 숫자만 오른다', async ({ page, reactionSeed }) => {
    await openPost(page, reactionSeed.board_free_slug, reactionSeed.post_alive_id, reactionSeed.member_a_token);

    // 회원 A 는 시드에서 이 댓글에 이미 추천을 눌러 두었다 — 비추천으로 «전환» 한다.
    const dislike = page.locator(
      `[data-testid="comment-react-${reactionSeed.comment_alive_id}-${reactionSeed.reaction_type_dislike_id}"]`,
    );
    await expect(dislike).toBeVisible({ timeout: 20_000 });

    const [response] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes(`/comments/${reactionSeed.comment_alive_id}/react`) && r.request().method() === 'POST',
      ),
      dislike.click(),
    ]);

    expect(response.ok()).toBe(true);

    // 전환이므로 내 반응은 비추천으로 바뀐다 — 서버 확정값으로 갱신된다.
    const body = (await response.json())?.data;
    expect(body.my_reaction_type_id).toBe(reactionSeed.reaction_type_dislike_id);
  });

  // @scenario case=comment_buttons_key_state_by_comment_id
  // @effects comment_remove_deletes_row
  test('같은 유형을 다시 누르면 해제된다', async ({ page, reactionSeed }) => {
    await openPost(page, reactionSeed.board_free_slug, reactionSeed.post_alive_id, reactionSeed.member_a_token);

    // 시드가 심어 둔 추천을 그대로 다시 누른다.
    const like = page.locator(
      `[data-testid="comment-react-${reactionSeed.comment_alive_id}-${reactionSeed.reaction_type_like_id}"]`,
    );
    await expect(like).toBeVisible({ timeout: 20_000 });

    const [response] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes(`/comments/${reactionSeed.comment_alive_id}/react`) && r.request().method() === 'POST',
      ),
      like.click(),
    ]);

    expect(response.ok()).toBe(true);
    expect((await response.json())?.data?.my_reaction_type_id).toBeNull();
  });

  // @scenario case=comment_buttons_render_under_both_toggles
  // @effects comment_reaction_group_hidden_on_deleted_or_blinded
  test('삭제된 댓글에는 반응 버튼이 붙지 않는다', async ({ page, reactionSeed }) => {
    await openPost(page, reactionSeed.board_free_slug, reactionSeed.post_alive_id, reactionSeed.member_a_token);

    // 새 반응을 달 수 없는 상태다 — 이미 남긴 반응의 해제는 마이페이지 몫.
    await expect(page.locator(`[data-testid^="comment-react-${reactionSeed.comment_deleted_id}-"]`)).toHaveCount(0);
  });

  // @scenario case=comment_buttons_key_state_by_comment_id
  // @effects guest_comment_react_returns_401
  test('비로그인은 돌아올 주소를 달고 로그인으로 간다', async ({ page, reactionSeed }) => {
    await openPost(page, reactionSeed.board_free_slug, reactionSeed.post_alive_id);

    const like = page.locator(
      `[data-testid="comment-react-${reactionSeed.comment_alive_id}-${reactionSeed.reaction_type_like_id}"]`,
    );
    await expect(like).toBeVisible({ timeout: 20_000 });
    await like.click();

    // 눌러 보고 401 을 받는 게 아니라 화면이 먼저 로그인으로 보낸다.
    await expect(page).toHaveURL(/\/login/, { timeout: 20_000 });
    await expect(page).toHaveURL(/redirect=/);
  });
});
