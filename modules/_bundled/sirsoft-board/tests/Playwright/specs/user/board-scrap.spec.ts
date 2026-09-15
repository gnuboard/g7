/**
 * 게시글 스크랩 사용자 흐름 (#524 WP-A).
 *
 * 실브라우저로 네 갈래를 잰다:
 *   ① 마이페이지 목록이 세 상태의 행(정상 · 스크랩 중지 · 삭제글)을 구분해 그린다
 *   ② 검색 대상 「내용」 으로 고르면 본문에만 있는 낱말이 걸리고 제목 매칭은 빠진다
 *   ③ 두 건을 골라 선택 삭제하면 한 요청으로 지워지고 선택이 비워진다
 *   ④ 단건 해제는 확인 창을 거친다
 *
 * 단위 테스트(board-scrap.test.tsx)는 레이아웃의 «모양» 을 잰다. 여기서 잴 것은 그 모양이
 * 실제 데이터와 만났을 때의 «동작» 이다 — 검색 요청이 정말 나가는지, 삭제가 목록에서 사라지는지.
 *
 * 선택자 주의: PC 표 행과 모바일 카드가 **같은 접두사**를 쓰되 모바일만 `-m-` 이 붙는다
 * (`scrap-card-{id}` / `scrap-card-m-{id}`). 접두사만으로 세면 둘이 함께 잡혀 건수가 두 배가
 * 된다 — 이 spec 은 PC 쪽만 골라 센다.
 *
 * @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
 * @scenario action=mypage_search,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
 * @scenario action=mypage_bulk_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
 * @effects mypage_list_marks_scrap_disabled_row, mypage_list_marks_deleted_board_row,
 *          mypage_list_search_field_content_ignores_title, mypage_bulk_modal_sends_ids_and_clears_selection,
 *          mypage_delete_opens_confirm_modal
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/scrap-seed';

const SCRAP_TAB_URL = '/mypage/scrap';

/** PC 표의 행만 고른다 (모바일 카드는 `-m-` 접두를 더 갖는다). */
const pcRows = (page: Page) =>
  page.locator('[data-testid^="scrap-card-"]:not([data-testid^="scrap-card-m-"])');

/**
 * 쿠키 동의 배너를 치운다.
 *
 * 동의 전에는 배너가 화면을 덮어 클릭이 배너로 간다. 배너가 사라진 것까지 기다려야 그 다음
 * 클릭이 목록에 닿는다. 배너가 없는 사이트에서는 아무 일도 하지 않는다.
 *
 * @param page 대상 페이지
 * @returns void
 */
async function dismissCookieBanner(page: Page): Promise<void> {
  const accept = page.getByRole('button', { name: /모두 동의|전체 동의|Accept all/i }).first();

  if (await accept.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await accept.click();
    await expect(accept).toBeHidden({ timeout: 5_000 });
  }
}

/**
 * 커스텀 셀렉트에서 옵션을 고른다.
 *
 * 템플릿의 `Select` 는 `options` prop 이 있으면 네이티브 `<select>` 를 렌더하지 않는다 —
 * 루트 Div + `button[aria-haspopup="listbox"]` + `role="option"` 항목의 커스텀 드롭다운이다.
 * 그래서 `selectOption()` 이 「select 요소가 아니다」로 실패한다.
 *
 * @param page 대상 페이지
 * @param testId 셀렉트 루트의 data-testid
 * @param label 고를 옵션의 화면 표기
 * @returns void
 */
async function chooseOption(page: Page, testId: string, label: string): Promise<void> {
  const root = page.getByTestId(testId);

  await root.getByRole('button').first().click();
  await page.getByRole('option', { name: label, exact: true }).click();
}

/**
 * 시드 회원으로 로그인해 스크랩 탭을 연다.
 *
 * @param page 대상 페이지
 * @param token 시드가 발급한 회원 토큰
 * @returns void
 */
async function openScrapTab(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(SCRAP_TAB_URL);
  await dismissCookieBanner(page);
  await expect(pcRows(page).first()).toBeVisible();
}

test.describe('게시글 스크랩 사용자 흐름 (#524)', () => {
  // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_off,post_state=alive,post_scope=same_board
  // @effects mypage_list_marks_scrap_disabled_row, mypage_list_marks_deleted_board_row
  test('목록이 세 상태의 행을 구분해 그린다', async ({ page, scrapSeed }) => {
    await openScrapTab(page, scrapSeed.member_a_token);

    // 시드가 건 스크랩이 하나도 빠지지 않아야 한다 — 건수를 박아 두면 시드가 늘 때마다
    // 테스트가 «실패» 로 붉어져 진짜 회귀와 구분되지 않는다. 시드가 돌려준 수를 쓴다.
    await expect(pcRows(page)).toHaveCount(scrapSeed.scrap_ids.length);

    // 네 상태가 모두 화면에 서야 한다 — 하나라도 감추면 회원이 정리할 대상을 찾지 못한다.
    const table = page.locator('body');
    await expect(table).toContainText('게시판 삭제됨');
    await expect(table).toContainText('스크랩 중지');
    await expect(table).toContainText('삭제된 게시글');

    // 번역 키가 그대로 새어 나오면 안 된다.
    await expect(table).not.toContainText('$t:');
  });

  // @scenario action=mypage_search,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board,search_field=content
  // @effects mypage_list_search_field_content_ignores_title, mypage_list_datasource_passes_search_field
  test('검색 대상을 바꾸면 그 값이 그대로 요청에 실린다', async ({ page, scrapSeed }) => {
    await openScrapTab(page, scrapSeed.member_a_token);

    const requests: string[] = [];
    page.on('request', (req) => {
      if (req.url().includes('/me/scraps')) requests.push(req.url());
    });

    await chooseOption(page, 'scrap-search-field', '내용');
    await page.getByTestId('scrap-search-input').fill(scrapSeed.body_keyword);
    await page.getByTestId('scrap-search-input').press('Enter');

    await expect
      .poll(() => requests.some((u) => u.includes('search_field=content')))
      .toBe(true);

    await chooseOption(page, 'scrap-search-field', '제목');
    await page.getByTestId('scrap-search-input').fill(scrapSeed.title_keyword);
    await page.getByTestId('scrap-search-input').press('Enter');

    await expect
      .poll(() => requests.some((u) => u.includes('search_field=title')))
      .toBe(true);
  });

  // @scenario action=mypage_bulk_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
  // @effects mypage_bulk_modal_sends_ids_and_clears_selection, mypage_bulk_delete_removes_only_owned_rows
  test('두 건을 골라 선택 삭제하면 한 요청으로 지워진다', async ({ page, scrapSeed }) => {
    await openScrapTab(page, scrapSeed.member_a_token);

    const checkboxes = page.locator(
      '[data-testid^="scrap-check-"]:not([data-testid^="scrap-check-m-"])',
    );

    await checkboxes.nth(0).check();
    await checkboxes.nth(1).check();

    const bulkButton = page.getByTestId('scrap-bulk-delete-btn');
    await expect(bulkButton).toBeEnabled();

    const deleteRequests: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'DELETE' && req.url().includes('/me/scraps')) deleteRequests.push(req.url());
    });

    await bulkButton.click();
    await expect(page.getByTestId('scrap-bulk-delete-modal')).toBeVisible();
    await page.getByTestId('scrap-bulk-delete-confirm').click();

    // 두 건을 한 요청으로 보낸다 — 건마다 보내면 활동 로그가 폭주하고 중간 실패를 설명할 수 없다.
    await expect.poll(() => deleteRequests.length).toBe(1);
    await expect(pcRows(page)).toHaveCount(scrapSeed.scrap_ids.length - 2);
  });

  // @scenario action=mypage_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
  // @effects mypage_delete_opens_confirm_modal
  test('단건 해제는 확인 창을 거친다', async ({ page, scrapSeed }) => {
    await openScrapTab(page, scrapSeed.member_a_token);

    const before = await pcRows(page).count();

    await page
      .locator('[data-testid^="scrap-remove-"]:not([data-testid^="scrap-remove-m-"])')
      .first()
      .click();
    await expect(page.getByTestId('scrap-delete-modal')).toBeVisible();
    await page.getByTestId('scrap-delete-confirm').click();

    await expect(pcRows(page)).toHaveCount(before - 1);
  });
});
