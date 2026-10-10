/**
 * 게시판 폼 「댓글 반응 사용」 토글 (#525 WP-D).
 *
 * 실브라우저로 두 갈래를 잰다:
 *   ① 토글이 폼에 서고, 글 반응이 꺼져 있으면 함께 잠긴다
 *   ② 끄고 저장하면 그 게시판의 댓글에서 반응 버튼이 사라진다
 *
 * ② 가 이 토글의 핵심이다. 설정은 저장됐는데 화면이 그대로면 운영자는 껐다고 믿고,
 * 회원은 계속 누른다 — 레이아웃 계약 테스트는 «조건에 두 토글이 들어 있다» 까지만 말할 수
 * 있고, 저장이 실제 화면까지 닿는지는 여기서만 보인다.
 *
 * @scenario case=comment_reaction_toggle_off_blocks
 * @effects comment_reaction_requires_parent_toggle, comment_reaction_off_returns_422,
 *          comment_reaction_group_requires_both_toggles
 */
import { test, expect, authenticatePage } from '../../fixtures/reaction-seed';

test.describe('게시판 폼 댓글 반응 토글 (#525 WP-D)', () => {
  // @scenario case=comment_reaction_toggle_off_blocks
  // @effects comment_reaction_requires_parent_toggle
  test('게시판 폼에 댓글 반응 토글이 선다', async ({ page, reactionSeed, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(`/admin/boards/${reactionSeed.board_free_slug}/edit`);

    // 시드 게시판은 반응·댓글 반응을 둘 다 켠 상태다.
    await expect(page.locator('body')).toContainText('댓글 반응 사용', { timeout: 25_000 });
    await expect(page.locator('body')).not.toContainText('$t:');
  });

  // @scenario case=comment_reaction_toggle_off_blocks
  // @effects comment_reaction_off_returns_422, comment_reaction_group_requires_both_toggles
  test('댓글 반응을 끄면 그 게시판 댓글에서 반응 버튼이 사라진다', async ({ page, reactionSeed, settingsToken }) => {
    // 켠 상태에서는 버튼이 있다 — 끈 뒤와 견줄 기준이다.
    await authenticatePage(page, reactionSeed.member_a_token);
    await page.goto(`/board/${reactionSeed.board_free_slug}/${reactionSeed.post_alive_id}`);
    await expect(page.locator(`[data-testid^="comment-react-${reactionSeed.comment_alive_id}-"]`).first())
      .toBeVisible({ timeout: 20_000 });

    // 관리자로 토글을 끈다 — 화면을 거치지 않고 API 로 저장해 폼 위젯 구조에 기대지 않는다.
    const saved = await page.evaluate(
      async ({ slug, token }) => {
        const res = await fetch(`/api/modules/sirsoft-board/admin/boards/${slug}`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ use_comment_reaction: false }),
        });

        return { ok: res.ok, status: res.status };
      },
      { slug: reactionSeed.board_free_slug, token: settingsToken },
    );

    expect(saved.ok, `PUT 응답 ${saved.status}`).toBe(true);

    // 회원 화면을 다시 연다 — 설정이 화면까지 닿아야 한다.
    await page.goto(`/board/${reactionSeed.board_free_slug}/${reactionSeed.post_alive_id}`);
    await expect(page.locator('body')).toContainText('댓글', { timeout: 20_000 });
    await expect(page.locator(`[data-testid^="comment-react-${reactionSeed.comment_alive_id}-"]`)).toHaveCount(0);

    // 글 반응은 그대로다 — 댓글 반응은 하위 토글이라 상위를 끄지 않는다.
    await expect(page.locator('[data-testid^="post-react-"]').first()).toBeVisible();
  });
});
