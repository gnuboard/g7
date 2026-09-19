/**
 * 마이페이지 「내 반응」 서브탭 (#525 WP-B).
 *
 * 실브라우저로 세 갈래를 잰다:
 *   ① 세 상태의 행(정상 · 반응 꺼짐/게시판 삭제됨 · 대상 삭제됨)이 한 목록에 서고,
 *      어느 갈래든 해제 버튼은 남는다 — 게시판 사정으로 내가 남긴 것을 못 거두면 안 된다
 *   ② 유형·대상 필터가 서버까지 닿아 건수를 바꾼다
 *   ③ 여러 건을 골라 한 번에 해제하면 그만큼만 줄고, 선택은 비워진다
 *
 * ① 이 이 화면의 핵심이다. 레이아웃 계약 테스트(vitest)는 세 갈래 `tr` 이 배타 조건을 쓴다는
 * 것까지 말할 수 있지만, 실제 데이터에서 그 셋이 동시에 서는지는 여기서만 보인다.
 *
 * @scenario case=subtab_lists_rows_in_three_states
 * @scenario case=subtab_bulk_selection_clears_on_navigation
 * @effects mypage_reactions_rows_split_into_three_states, mypage_reactions_datasource_passes_seven_filters,
 *          mypage_reactions_single_and_bulk_modals_call_delete, remove_own_only_and_recalculates_counts
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/reaction-seed';

const MYPAGE_URL = '/mypage/board';

/**
 * 마이페이지 게시판 활동을 열고 「내 반응」 탭으로 간다.
 *
 * @param page 대상 페이지
 * @param token 회원 토큰
 * @returns void
 */
async function openMyReactions(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(MYPAGE_URL);

  const tab = page.locator('[data-testid="mypage-subtab-my-reactions"]');
  await expect(tab).toBeVisible({ timeout: 20_000 });
  await tab.click();

  // 이 소스는 `auto_fetch=false` 라 탭을 눌러야 처음 받는다. 목록 응답이 온 뒤 표가 서는데,
  // 그전에 행을 세면 0 이 나와 뒤따르는 기대값이 통째로 어긋난다.
  await page.waitForResponse(
    (r) => r.url().includes('/me/reactions') && r.request().method() === 'GET' && r.ok(),
    { timeout: 20_000 },
  ).catch(() => undefined);
  await expect(tableRows(page).first()).toBeVisible({ timeout: 20_000 });
}

/**
 * 넓은 화면 표의 행만 센다.
 *
 * 표(PC)와 카드(좁은 화면)가 같은 배열을 그리고 `my-reaction-row-` testid 도 공유하므로,
 * 구분 없이 세면 한 건이 둘로 잡힌다. 표 안쪽으로 한정한다.
 *
 * @param page 대상 페이지
 * @returns 행 로케이터
 */
function tableRows(page: Page) {
  return page.locator('table tbody [data-testid^="my-reaction-row-"]');
}

test.describe('마이페이지 내 반응 (#525 WP-B)', () => {
  // @scenario case=subtab_lists_rows_in_three_states
  // @effects mypage_reactions_rows_split_into_three_states
  test('세 상태의 행이 한 목록에 서고 어느 갈래든 해제 버튼이 남는다', async ({ page, reactionSeed }) => {
    await openMyReactions(page, reactionSeed.member_a_token);

    const body = page.locator('body');
    // 게시판이 반응을 껐거나 사라졌거나 대상이 지워진 행도 남아 있어야 한다.
    await expect(body).toContainText('반응 꺼짐');
    await expect(body).toContainText('게시판 삭제됨');
    await expect(body).toContainText('삭제됨');
    await expect(body).not.toContainText('$t:');

    // 해제 버튼은 상태와 무관하게 행마다 있다.
    const removeButtons = page.locator('table tbody [data-testid^="my-reaction-remove-"]');
    expect(await removeButtons.count()).toBeGreaterThanOrEqual(3);
  });

  // @scenario case=subtab_lists_rows_in_three_states
  // @effects mypage_reactions_target_badge_splits_post_and_comment
  test('글 반응과 댓글 반응이 배지로 갈린다', async ({ page, reactionSeed }) => {
    await openMyReactions(page, reactionSeed.member_a_token);

    // 시드는 글 6 · 댓글 1 이다. 배지가 없으면 어느 쪽에 남긴 반응인지 알 수 없다.
    const body = page.locator('body');
    await expect(body).toContainText('게시글');
    await expect(body).toContainText('댓글');
  });

  // @scenario case=subtab_lists_rows_in_three_states
  // @effects mypage_reactions_datasource_passes_seven_filters
  test('대상 필터가 서버까지 닿아 건수를 바꾼다', async ({ page, reactionSeed }) => {
    await openMyReactions(page, reactionSeed.member_a_token);

    // 이 셀렉트는 네이티브 `<select>` 가 아니라 버튼 + 목록으로 그려진다 —
    // `selectOption` 은 쓸 수 없고 열어서 고른다.
    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/me/reactions') && r.url().includes('target_type=comment')),
      (async () => {
        await page.locator('[data-testid="my-reactions-target-filter"] button').first().click();
        await page.getByRole('option', { name: '댓글', exact: true }).click();
      })(),
    ]);

    expect(request.url()).toContain('target_type=comment');
    // 회원 A 의 댓글 반응은 1건이다. 필터가 무시되면 7건이 그대로 남는다.
    await expect(tableRows(page)).toHaveCount(1, { timeout: 20_000 });
  });

  // @scenario case=subtab_bulk_selection_clears_on_navigation
  // @effects mypage_reactions_single_and_bulk_modals_call_delete
  test('단건 해제가 그 반응만 지우고 목록에서 사라진다', async ({ page, reactionSeed }) => {
    await openMyReactions(page, reactionSeed.member_a_token);

    const before = reactionSeed.reaction_ids.length;
    await expect(tableRows(page)).toHaveCount(before);

    const [request] = await Promise.all([
      page.waitForRequest((r) => /\/me\/reactions\/\d+$/.test(r.url()) && r.method() === 'DELETE'),
      (async () => {
        await page.locator('table tbody [data-testid^="my-reaction-remove-"]').first().click();
        await page.locator('[data-testid="reaction-delete-confirm"]').click();
      })(),
    ]);

    expect(request.method()).toBe('DELETE');
    await expect(tableRows(page)).toHaveCount(before - 1, { timeout: 20_000 });
  });

  // @scenario case=subtab_bulk_selection_clears_on_navigation
  // @effects mypage_reactions_single_and_bulk_modals_call_delete
  test('2건을 골라 한 번에 해제하면 그만큼만 줄고 선택이 비워진다', async ({ page, reactionSeed }) => {
    await openMyReactions(page, reactionSeed.member_a_token);

    const rows = tableRows(page);
    const before = reactionSeed.reaction_ids.length;
    await expect(rows).toHaveCount(before);

    const checkboxes = page.locator('table tbody [data-testid^="my-reaction-check-"]');
    await checkboxes.nth(0).click();
    await expect(checkboxes.nth(0)).toBeChecked();
    await checkboxes.nth(1).click();
    await expect(checkboxes.nth(1)).toBeChecked();

    // 선택 바는 고른 것이 있을 때만 서고 건수를 말한다.
    await expect(page.locator('[data-testid="my-reactions-selected-count"]')).toContainText('2');

    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().endsWith('/me/reactions') && r.method() === 'DELETE'),
      (async () => {
        await page.locator('[data-testid="my-reactions-bulk-remove-btn"]').click();
        await page.locator('[data-testid="reaction-bulk-delete-confirm"]').click();
      })(),
    ]);

    expect(JSON.parse(request.postData() ?? '{}').ids).toHaveLength(2);
    await expect(rows).toHaveCount(before - 2, { timeout: 20_000 });
    // 선택이 남아 있으면 다음 일괄 해제가 이미 없는 ID 를 싣는다.
    await expect(page.locator('[data-testid="my-reactions-selected-count"]')).toHaveCount(0);
  });

  // @scenario case=subtab_lists_rows_in_three_states
  // @effects remove_allowed_when_board_reaction_off
  test('반응이 꺼진 게시판의 행도 해제된다', async ({ page, reactionSeed }) => {
    await openMyReactions(page, reactionSeed.member_a_token);

    // 「반응 꺼짐」 배지가 붙은 행을 집는다 — 그 행에서도 해제가 되어야 한다.
    const offRow = tableRows(page).filter({ hasText: '반응 꺼짐' }).first();
    await expect(offRow).toBeVisible();

    const before = reactionSeed.reaction_ids.length;
    await expect(tableRows(page)).toHaveCount(before);
    await offRow.locator('[data-testid^="my-reaction-remove-"]').first().click();
    await page.locator('[data-testid="reaction-delete-confirm"]').click();

    await expect(tableRows(page)).toHaveCount(before - 1, { timeout: 20_000 });
  });

  // @scenario case=subtab_lists_rows_in_three_states
  // @effects mypage_reactions_stats_card_counts_total_reactions
  test('통계 카드의 「남긴 반응」 숫자가 해제와 함께 줄어든다', async ({ page, reactionSeed }) => {
    await openMyReactions(page, reactionSeed.member_a_token);

    const before = reactionSeed.reaction_ids.length;
    await expect(tableRows(page)).toHaveCount(before);

    await page.locator('table tbody [data-testid^="my-reaction-remove-"]').first().click();
    await page.locator('[data-testid="reaction-delete-confirm"]').click();
    await expect(tableRows(page)).toHaveCount(before - 1, { timeout: 20_000 });

    // 숫자만 남아 있으면 화면이 거짓말을 한다 — 목록과 통계를 함께 다시 받아야 한다.
    await expect(page.locator('[data-testid="mypage-subtab-my-reactions"]')).toContainText(String(before - 1), {
      timeout: 20_000,
    });
  });
});
