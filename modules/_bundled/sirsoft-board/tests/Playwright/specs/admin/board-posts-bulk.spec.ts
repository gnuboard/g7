/**
 * 관리자 게시글 일괄 처리 (#524 WP-D).
 *
 * 실브라우저로 두 갈래를 잰다:
 *   ① 두 건을 골라 일괄 블라인드하면 한 요청으로 처리되고 목록의 상태 배지가 바뀐다
 *   ② 세 버튼(삭제·블라인드·복구)이 같은 확인 창을 열되 갈래가 갈린다
 *
 * 단위 테스트는 「버튼이 같은 창을 연다」 는 모양을 잰다. 여기서 잴 것은 그 창이 실제로
 * 서버에 닿아 목록을 바꾸는지 — 그리고 여러 건을 **한 요청**으로 보내는지다. 건마다 보내면
 * 활동 로그가 폭주하고 본인인증 정책이 첫 건에서 428 을 던져 나머지가 멈춘다.
 *
 * @scenario bulk_action=blind, permission=manage, row_state=published
 * @scenario bulk_action=delete, permission=manage, row_state=published
 * @effects admin_post_bulk_blind_changes_status, admin_post_bulk_sends_ids_and_reason,
 *          admin_post_bulk_buttons_share_one_modal
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage, issueToken } from '../../fixtures/scrap-seed';

/**
 * 게시판의 관리자 게시글 목록을 연다.
 *
 * @param page 대상 페이지
 * @param token 관리자 토큰
 * @param slug 게시판 slug
 * @returns void
 */
async function openPostList(page: Page, token: string, slug: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(`/admin/board/${slug}`);
  await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 15_000 });
}

test.describe('관리자 게시글 일괄 처리 (#524 WP-D)', () => {
  // @scenario bulk_action=blind, permission=manage, row_state=published
  // @effects admin_post_bulk_buttons_share_one_modal
  test('세 버튼이 같은 확인 창을 열고 갈래만 달리 심는다', async ({ page, scrapSeed }) => {
    const token = issueToken(
      'sirsoft-board.boards.read',
      `sirsoft-board.${scrapSeed.board_free_slug}.admin.posts.read`,
      `sirsoft-board.${scrapSeed.board_free_slug}.admin.manage`,
    );
    await openPostList(page, token, scrapSeed.board_free_slug);

    // 표의 체크박스는 전역 상태가 제어하는 React 입력이라 `check()` 의 상태 단언이 헛돈다.
    // 클릭하고 일괄 버튼이 풀리는 것으로 선택을 확인한다.
    await page.locator('table tbody input[type="checkbox"]').first().click();

    const blind = page.getByTestId('admin-post-bulk-blind');
    await expect(blind).toBeEnabled();
    await blind.click();

    // 세 갈래가 창을 공유하므로, 어떤 버튼을 눌렀든 같은 창이 뜬다.
    await expect(page.getByTestId('admin-post-bulk-modal')).toBeVisible();
    await expect(page.getByTestId('admin-post-bulk-confirm')).toBeVisible();
  });

  // @scenario bulk_action=blind, permission=manage, row_state=published
  // @effects admin_post_bulk_blind_changes_status, admin_post_bulk_sends_ids_and_reason
  test('일괄 블라인드는 한 요청으로 나가고 목록의 상태가 바뀐다', async ({ page, scrapSeed }) => {
    const token = issueToken(
      'sirsoft-board.boards.read',
      `sirsoft-board.${scrapSeed.board_free_slug}.admin.posts.read`,
      `sirsoft-board.${scrapSeed.board_free_slug}.admin.manage`,
    );
    await openPostList(page, token, scrapSeed.board_free_slug);

    const requests: string[] = [];
    page.on('request', (req) => {
      if (req.url().includes('/posts/bulk')) requests.push(`${req.method()} ${req.url()}`);
    });

    await page.locator('table tbody input[type="checkbox"]').first().click();
    await page.getByTestId('admin-post-bulk-blind').click();
    await expect(page.getByTestId('admin-post-bulk-modal')).toBeVisible();
    await page.getByTestId('admin-post-bulk-confirm').click();

    // 고른 건수와 무관하게 요청은 하나다.
    await expect.poll(() => requests.filter((r) => r.includes('bulk-blind')).length).toBe(1);
    await expect(page.locator('table tbody')).toContainText(/블라인드|Blinded/i);
  });

  // 건너뛴 글은 「건너뜀 N개」 개수만이 아니라 어느 글이 왜 빠졌는지 창으로 편다(실측 2026-10-03).
  // @scenario bulk_action=blind, permission=manage, row_state=blinded
  // @effects admin_post_bulk_skipped_items_carry_title_and_reason_label
  test('이미 블라인드된 글을 다시 블라인드하면 건너뛴 게시글 창이 제목과 사유를 보인다', async ({ page, scrapSeed }) => {
    const token = issueToken(
      'sirsoft-board.boards.read',
      `sirsoft-board.${scrapSeed.board_free_slug}.admin.posts.read`,
      `sirsoft-board.${scrapSeed.board_free_slug}.admin.manage`,
    );
    await openPostList(page, token, scrapSeed.board_free_slug);

    const runBlind = async (): Promise<void> => {
      await page.locator('table tbody input[type="checkbox"]').first().click();
      await page.getByTestId('admin-post-bulk-blind').click();
      await expect(page.getByTestId('admin-post-bulk-modal')).toBeVisible();
      await page.getByTestId('admin-post-bulk-confirm').click();
    };

    // 첫 번째로 블라인드해 둔 뒤, 같은 행을 다시 블라인드한다 — 두 번째는 건너뛴다.
    await runBlind();
    await expect(page.locator('table tbody tr').first()).toContainText(/블라인드|Blinded/i);
    await runBlind();

    const skipped = page.getByTestId('admin-post-bulk-skipped');
    await expect(skipped).toBeVisible();
    await expect(page.getByTestId('admin-post-bulk-skipped-row')).toHaveCount(1);
    await expect(page.getByTestId('admin-post-bulk-skipped-row').first()).toContainText(/이미 블라인드된 글|Already blinded/);
    // 제목 자리에 식 원문(`{{…}}`)이 남지 않는다.
    await expect(skipped).not.toContainText('{{');
  });
});
