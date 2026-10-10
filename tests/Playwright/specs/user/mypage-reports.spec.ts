/**
 * 마이페이지 「내 신고」 서브탭 (#525 WP-E).
 *
 * 실브라우저로 두 갈래를 잰다:
 *   ① 내가 넣은 신고가 상태 배지와 함께 서고, 취소 버튼은 «대기 중인 건» 에만 뜬다
 *   ② 취소하면 그 신고가 목록에서 사라지고 남은 건은 그대로다
 *
 * ① 이 이 화면의 핵심이다. 운영자가 이미 판정한 건을 신고자가 되돌리면 처리 이력이 어긋난다 —
 * 버튼을 내고 403 을 받는 화면이면 안 된다. 시드가 대기 중 1건 · 반려 1건을 함께 심으므로
 * 두 갈래가 한 화면에 선다.
 *
 * @scenario case=subtab_lists_reports_with_status_badges
 * @scenario case=subtab_cancel_only_when_pending
 * @effects mypage_reports_status_badge_has_four_branches, mypage_reports_cancel_button_requires_can_cancel,
 *          mypage_reports_cancel_modal_calls_delete_and_refetches, lists_own_logs_with_status_and_can_cancel,
 *          cancel_non_pending_returns_403
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/reaction-seed';

/**
 * 마이페이지 게시판 활동을 열고 「내 신고」 탭으로 간다.
 *
 * @param page 대상 페이지
 * @param token 회원 토큰
 * @returns void
 */
async function openMyReports(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/mypage/board');

  const tab = page.locator('[data-testid="mypage-subtab-my-reports"]');
  await expect(tab).toBeVisible({ timeout: 20_000 });
  await tab.click();

  // 이 소스는 `auto_fetch=false` 라 탭을 눌러야 처음 받는다. 응답이 오기 전에 행을 세면
  // 0 이 나와 뒤따르는 기대값이 통째로 어긋난다.
  await page.waitForResponse(
    (r) => r.url().includes('/me/reports') && r.request().method() === 'GET' && r.ok(),
    { timeout: 20_000 },
  ).catch(() => undefined);
  await expect(page.locator('[data-testid^="my-report-row-"]').first()).toBeVisible({ timeout: 20_000 });
}

/**
 * 필터의 선택지를 하나씩 골라 검색을 돌리고, 요청에 실린 값과 응답 상태를 모은다.
 *
 * 필터는 네이티브 select 가 아니라 목록 단추(Select 컴포넌트)라 옵션에 value 속성이 없다 —
 * 그래서 화면이 실제로 보낸 쿼리 값을 요청 주소에서 읽는다. 목록은 문서 끝에 따로 그려진다.
 *
 * @param page 대상 페이지
 * @param testId 필터 래퍼의 data-testid
 * @param param 요청 쿼리에서 읽을 이름
 * @returns 선택지마다 보낸 값과 응답 상태
 */
async function chooseEachOption(page: Page, testId: string, param: string): Promise<Array<{ value: string; status: number }>> {
  const trigger = page.locator(`[data-testid="${testId}"] button`).first();
  await trigger.click();
  const count = await page.getByRole('listbox').getByRole('option').count();
  await page.keyboard.press('Escape');

  const searchButton = page.locator('[data-testid="my-reports-search-field"]')
    .locator('xpath=ancestor::*[.//button[normalize-space()="검색" or normalize-space()="Search"]][1]')
    .getByRole('button', { name: /^(검색|Search)$/ });

  const sent: Array<{ value: string; status: number }> = [];
  for (let i = 0; i < count; i++) {
    await trigger.click();
    await page.getByRole('listbox').getByRole('option').nth(i).click();
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/me/reports') && r.request().method() === 'GET'),
      searchButton.click(),
    ]);
    sent.push({ value: new URL(response.url()).searchParams.get(param) ?? '', status: response.status() });
  }

  return sent;
}

test.describe('마이페이지 내 신고 (#525 WP-E)', () => {
  // @scenario case=subtab_lists_reports_with_status_badges
  // @effects lists_own_logs_with_status_and_can_cancel
  test('내가 넣은 신고가 상태 배지와 함께 선다', async ({ page, reactionSeed }) => {
    await openMyReports(page, reactionSeed.member_a_token);

    // 시드는 대기 중 1 · 반려 1 을 심는다.
    await expect(page.locator('[data-testid^="my-report-row-"]')).toHaveCount(2);

    const body = page.locator('body');
    await expect(body).toContainText('접수');
    await expect(body).toContainText('반려');
    // 언어 키가 풀리지 않은 채 노출되면 안 된다.
    await expect(body).not.toContainText('$t:');
  });

  // @scenario case=subtab_cancel_only_when_pending
  // @effects mypage_reports_cancel_button_requires_can_cancel
  test('취소 버튼은 대기 중인 건에만 뜬다', async ({ page, reactionSeed }) => {
    await openMyReports(page, reactionSeed.member_a_token);

    // 반려된 건에는 버튼이 없어야 한다 — 두 건 중 하나만 취소할 수 있다.
    await expect(page.locator('[data-testid^="my-report-cancel-"]')).toHaveCount(1);
    await expect(page.locator(`[data-testid="my-report-cancel-${reactionSeed.report_log_pending_id}"]`)).toBeVisible();
    await expect(page.locator(`[data-testid="my-report-cancel-${reactionSeed.report_log_rejected_id}"]`)).toHaveCount(0);
  });

  // @scenario case=subtab_lists_reports_with_status_badges
  // @effects mypage_reports_status_badge_has_four_branches
  test('상태 배지가 건마다 서버 판정을 그대로 낸다', async ({ page, reactionSeed }) => {
    await openMyReports(page, reactionSeed.member_a_token);

    await expect(page.locator(`[data-testid="my-report-status-${reactionSeed.report_log_pending_id}"]`))
      .toContainText('접수');
    await expect(page.locator(`[data-testid="my-report-status-${reactionSeed.report_log_rejected_id}"]`))
      .toContainText('반려');
  });

  // @scenario case=subtab_lists_reports_with_status_badges
  // @effects mypage_reports_status_badge_has_four_branches
  test('상태 필터의 모든 선택지가 서버에 받아들여진다', async ({ page, reactionSeed }) => {
    // 화면이 보내는 상태 값이 서버 ReportStatus 에 없으면 422 로 목록 자체가 뜨지 않는다.
    // 2026-09-16 5차 검증: 「검토 중」·「처리 완료」가 서버에 없는 값이라 전 회원이 422 를 받았다.
    await openMyReports(page, reactionSeed.member_a_token);

    const sent = await chooseEachOption(page, 'my-reports-status-filter', 'status');
    expect(sent.length).toBeGreaterThan(1);
    for (const { value, status } of sent) {
      expect(status, `상태 '${value}' 가 서버에 거부됐다`).toBe(200);
    }
  });

  // @scenario case=subtab_lists_reports_with_status_badges
  // @effects mypage_reports_search_field_matches_backend
  test('검색 대상의 모든 선택지가 서버에 받아들여진다', async ({ page, reactionSeed }) => {
    // 화면이 파라미터를 아예 안 보내면 422 가 아니라 **조용히 무시**된다 — 저장소가
    // 'title_content' 로 떨어져 사유 설명으로는 영영 찾지 못한다. 그래서 값이 실제로
    // 쿼리에 실려 나가는지까지 본다(6차 검증이 연 축).
    await openMyReports(page, reactionSeed.member_a_token);
    await expect(page.locator('[data-testid="my-reports-search-field"]')).toBeVisible();

    const sent = await chooseEachOption(page, 'my-reports-search-field', 'search_field');
    expect(sent.length).toBeGreaterThan(1);
    expect(sent.map((s) => s.value), '신고 사유로 찾는 선택지가 없다').toContain('reason');
    for (const { value, status } of sent) {
      expect(status, `검색 대상 '${value}' 가 서버에 거부됐다`).toBe(200);
    }
  });

  // 취소 창은 목록 행의 머리줄(글은 제목, 댓글은 본문 요약)을 그대로 보여 어느 신고를 거두는지 알린다.
  // 맞는 것이 없는 검색은 「아직 넣은 신고가 없다」가 아니라 검색 결과가 없다고 말한다(실측 2026-10-03).
  // @scenario case=subtab_lists_reports_with_status_badges
  // @effects mypage_reports_post_row_leads_with_title, mypage_filtered_empty_says_no_results
  test('취소 창이 거둘 신고의 대상을 보이고, 맞는 것이 없는 검색은 검색 결과 없음을 낸다', async ({ page, reactionSeed }) => {
    await openMyReports(page, reactionSeed.member_a_token);

    const row = page.locator(`[data-testid="my-report-row-${reactionSeed.report_log_pending_id}"]`);
    const headline = (await row.locator('button.text-left, span.line-clamp-2').first().innerText()).trim();
    expect(headline.length).toBeGreaterThan(0);

    await page.locator(`[data-testid="my-report-cancel-${reactionSeed.report_log_pending_id}"]`).click();
    await expect(page.locator('[data-testid="report-cancel-target"]')).toHaveText(headline);
    await page.getByRole('dialog').getByRole('button', { name: /^(취소|Cancel)$/ }).first().click();
    await expect(page.locator('[data-testid="report-cancel-target"]')).toBeHidden();

    const filterRow = page.locator('[data-testid="my-reports-search-field"]').locator('xpath=ancestor::*[.//button][1]');
    await filterRow.locator('input').first().fill('zz-없는-검색어-000');
    await filterRow.getByRole('button', { name: /^(검색|Search)$/ }).click();
    await expect(page.locator('[data-testid="my-reports-empty"]')).toContainText(/검색 결과가 없습니다|No search results found/, { timeout: 20_000 });
  });

  // @scenario case=subtab_cancel_only_when_pending
  // @effects mypage_reports_cancel_modal_calls_delete_and_refetches
  test('취소하면 그 건만 사라지고 나머지는 남는다', async ({ page, reactionSeed }) => {
    await openMyReports(page, reactionSeed.member_a_token);

    const [request] = await Promise.all([
      page.waitForRequest((r) => /\/me\/reports\/\d+$/.test(r.url()) && r.method() === 'DELETE'),
      (async () => {
        await page.locator(`[data-testid="my-report-cancel-${reactionSeed.report_log_pending_id}"]`).click();
        await page.locator('[data-testid="report-cancel-confirm"]').click();
      })(),
    ]);

    expect(request.url()).toContain(String(reactionSeed.report_log_pending_id));

    // 취소해도 신고 대상(글)은 그대로다 — 사라지는 것은 「신고를 넣은 사실」뿐이고,
    // 판정이 끝난 다른 건도 남는다.
    await expect(page.locator('[data-testid^="my-report-row-"]')).toHaveCount(1, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText('반려');
  });
});
