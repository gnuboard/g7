/**
 * 관리자 스크랩·반응 목록의 «회원 칸과 시각 칸» (이슈 #525 후속, PO 제보 2026-09-16).
 *
 * 두 갈래를 실브라우저로 잰다:
 *   ① 시각 칸이 **초 단위 절대 시각**으로 선다 — 「2시간 전」·「09-14」 같은 어림 표기가 아니다.
 *   ② 회원 이름을 누르면 **문맥 메뉴**(회원정보 보기 / 이 회원으로 검색)가 열리고, 「이 회원으로
 *      검색」이 목록을 그 회원으로 좁힌다.
 *
 * 이 두 축은 단위 테스트가 잡지 못한다 — ①은 서버가 내려준 두 필드(`scrapped_at` 상대 /
 * `created_at` 절대) 중 **어느 쪽을 화면이 골랐는가**의 문제라 응답만 봐서는 판정되지 않고,
 * ②는 `action-menu` 가 셀 밖으로 포털되어 렌더되므로 DOM 조각 단언으로는 열림을 확인할 수 없다.
 * 실제로 이 세션에서 셀 범위로만 찾다가 「메뉴가 안 열린다」고 오판했다.
 *
 * 시각은 UTC 로 저장하고 표시할 때만 회원 시간대로 환산한다 — spec 은 시간대 값을 고정하지 않고
 * **초까지 있는 절대 형식인가**만 본다(실행 환경의 회원 설정에 따라 시:분:초 값이 달라진다).
 *
 * @scenario surface=admin_list, permission=manage, member_scope=false, board_state=alive
 * @scenario surface=admin_list, permission=manage, member_scope=true, board_state=alive
 * @effects admin_list_shows_absolute_timestamp_with_seconds,
 *          admin_list_member_cell_opens_context_menu,
 *          admin_list_member_menu_search_scopes_list
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/scrap-seed';

const ADMIN_SCRAPS_URL = '/admin/boards/scraps';

/** 초 단위 절대 시각 — `2026-09-14 15:05:31`. 「2시간 전」·「09-14」 는 걸리지 않는다. */
const ABSOLUTE_TIMESTAMP = /\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/;

/**
 * 관리자 스크랩 목록을 열고 첫 행이 설 때까지 기다린다.
 *
 * @param page 대상 페이지
 * @param token 관리자 토큰
 * @param query 덧붙일 질의 문자열 (앞의 `?` 포함)
 * @returns void
 */
async function openAdminScraps(page: Page, token: string, query = ''): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(ADMIN_SCRAPS_URL + query);
  await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 15_000 });
}

test.describe('관리자 목록의 회원 칸·시각 칸 (#525)', () => {
  // 문맥 메뉴는 `position: fixed` 로 뜬다 — 뷰포트 밖으로 나가면 Playwright 가 스크롤로
  // 끌어올 수 없어(고정 요소는 스크롤에 따라오지 않는다) 클릭이 30초 동안 재시도만 하다 죽는다.
  // 기본 Desktop Chrome(1280×720)에서는 표 아래쪽 행의 메뉴가 그 밖으로 떨어지므로 높이를 준다.
  test.use({ viewport: { width: 1280, height: 1000 } });

  // @scenario surface=admin_list, permission=manage, member_scope=false, board_state=alive
  // @effects admin_list_shows_absolute_timestamp_with_seconds
  test('스크랩 시각이 초 단위 절대 시각으로 선다', async ({ page, scrapManageToken }) => {
    await openAdminScraps(page, scrapManageToken);

    const firstRow = page.locator('table tbody tr').first();

    // 행 어딘가에 초까지 있는 절대 시각이 있어야 한다. 열 번호를 박으면 열 순서가 바뀔 때
    // 엉뚱한 칸을 재고도 초록이 되므로, 행 전체 문자열로 «형식» 을 본다.
    await expect(firstRow).toContainText(ABSOLUTE_TIMESTAMP);

    // 상대 표기가 남아 있으면 고치기 전 필드를 그대로 그리고 있다는 뜻이다.
    await expect(firstRow).not.toContainText(/\d+\s*(분|시간|일)\s*전/);
    await expect(firstRow).not.toContainText('방금 전');
  });

  // @scenario surface=admin_list, permission=manage, member_scope=true, board_state=alive
  // @effects admin_list_member_cell_opens_context_menu, admin_list_member_menu_search_scopes_list
  test('회원 이름을 누르면 문맥 메뉴가 열리고 「이 회원으로 검색」이 목록을 좁힌다', async ({
    page,
    scrapSeed,
    scrapManageToken,
  }) => {
    await openAdminScraps(page, scrapManageToken);

    // 회원 이름은 action-menu 트리거 안에 있다. 메뉴 자체는 셀 밖으로 포털되므로
    // 열림 확인은 **페이지 전역**에서 해야 한다 — 셀 안만 보면 못 찾는다.
    await page.getByText(scrapSeed.member_a_email, { exact: false }).first().waitFor();
    const memberTrigger = page
      .locator('table tbody tr')
      .filter({ hasText: scrapSeed.member_a_email })
      .first()
      .locator('td')
      .nth(2)
      .locator('div.relative > div.cursor-pointer');

    await memberTrigger.click();

    const viewMember = page.getByText('회원정보 보기', { exact: false }).first();
    const searchMember = page.getByText('이 회원으로 검색', { exact: false }).first();

    await expect(viewMember).toBeVisible({ timeout: 5_000 });
    await expect(searchMember).toBeVisible();

    // 원문 키가 그대로 보이면 언어 키가 풀리지 않은 것이다.
    await expect(page.locator('body')).not.toContainText('cell.view_member');

    // 「이 회원으로 검색」 — 같은 창에서 회원 조건만 갈아끼운다.
    await searchMember.click();

    await expect(page).toHaveURL(new RegExp(`user_uuid=${scrapSeed.member_a_uuid}`), {
      timeout: 10_000,
    });

    // 좁혀진 목록에는 그 회원만 남는다 — 다른 회원이 섞여 있으면 조건이 먹지 않은 것이다.
    await expect(page.locator('table tbody').first()).toContainText(scrapSeed.member_a_email);
    await expect(page.locator('table tbody').first()).not.toContainText(scrapSeed.member_b_email);
  });
});
