/**
 * 관리자 스크랩 관리 화면 (#524 WP-A).
 *
 * 실브라우저로 세 갈래를 잰다:
 *   ① 전 회원의 스크랩이 한 목록에 서고, 게시판이 지워진 행·스크랩 중지 행도 남는다
 *   ② 회원 한정(`user_uuid`)으로 들어오면 배너가 서고 목록이 그 회원 것만 남는다
 *   ③ 삭제 권한이 없는 운영자에게는 체크 칸과 일괄 버튼이 열리지 않는다
 *
 * ③ 은 단위 테스트가 «식» 만 잴 수 있고 실제 능력 값과 만나야 결론이 난다 — 서버가 내려주는
 * `abilities.can_manage` 가 화면의 잠금과 이어지는지는 여기서만 보인다.
 *
 * @scenario surface=admin_list, permission=manage, member_scope=false, board_state=deleted
 * @scenario surface=admin_list, permission=manage, member_scope=true, board_state=alive
 * @scenario surface=admin_list, permission=view, member_scope=false, board_state=alive
 * @effects admin_scrap_index_lists_all_members, admin_list_marks_deleted_board_and_post,
 *          admin_list_member_banner_shows_only_when_scoped, admin_scrap_index_filters_by_user_uuid,
 *          admin_list_bulk_button_requires_manage_and_selection
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/scrap-seed';

const ADMIN_SCRAPS_URL = '/admin/boards/scraps';

/**
 * 관리자 스크랩 목록을 연다.
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

test.describe('관리자 스크랩 관리 (#524)', () => {
  // @scenario surface=admin_list, permission=manage, member_scope=false, board_state=deleted
  // @effects admin_scrap_index_lists_all_members, admin_list_marks_deleted_board_and_post
  test('전 회원의 스크랩이 한 목록에 서고 지워진 게시판 행도 남는다', async ({ page, scrapSeed, scrapManageToken }) => {
    await openAdminScraps(page, scrapManageToken);

    const body = page.locator('body');

    // 두 회원의 스크랩이 함께 보여야 «전 회원 목록» 이다.
    await expect(body).toContainText('스크랩회원A');
    await expect(body).toContainText('스크랩회원B');

    // 게시판이 지워진 행과 원글이 지워진 행을 감추지 않는다 — 감추면 정리 대상을 못 찾는다.
    await expect(body).toContainText(scrapSeed.title_keyword);
    await expect(body).not.toContainText('$t:');
  });

  // @scenario surface=admin_list, permission=manage, member_scope=true, board_state=alive
  // @effects admin_list_member_banner_shows_only_when_scoped, admin_scrap_index_filters_by_user_uuid
  test('회원 한정으로 들어오면 배너가 서고 그 회원 것만 남는다', async ({ page, scrapSeed, scrapManageToken }) => {
    await openAdminScraps(page, scrapManageToken, `?user_uuid=${scrapSeed.member_a_uuid}`);

    // `data-ref` 는 DOM 에 `id` 로 나온다 — testid 가 아니다(실측 확인).
    const banner = page.locator('#member_filter_banner');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('특정 회원');

    // 모든 행의 «스크랩한 회원» 이 A 여야 한다. 작성자 칸에는 B 가 나올 수 있다 —
    // 시드가 임시게시판 글을 B 가 쓰게 만들었고, 그 글을 A 가 스크랩했기 때문이다.
    // 두 칸을 구분하지 않으면 필터가 멀쩡해도 테스트가 붉어진다.
    // 회원 A 가 건 스크랩 전량이 보여야 한다 — 건수는 시드에서 파생한다(박아 두면 시드가
    // 늘 때마다 진짜 회귀와 구분되지 않는다).
    const rows = page.locator('table tbody tr');
    const expected = scrapSeed.scrap_ids.length;
    await expect(rows).toHaveCount(expected);

    for (let i = 0; i < expected; i += 1) {
      await expect(rows.nth(i)).toContainText(scrapSeed.member_a_email);
    }
  });

  // @scenario surface=admin_list, permission=view, member_scope=false, board_state=alive
  // @effects admin_list_selectable_requires_manage, admin_list_bulk_button_requires_manage_and_selection
  test('삭제 권한이 없으면 체크 칸과 일괄 버튼이 열리지 않는다', async ({ page, scrapViewOnlyToken }) => {
    await openAdminScraps(page, scrapViewOnlyToken);

    // 권한이 없으면 표가 선택 모드로 들어가지 않는다 — 누를 수 없는 버튼을 보여 주지 않는다.
    await expect(page.locator('table input[type="checkbox"]')).toHaveCount(0);

    const bulkButton = page.getByTestId('admin-scrap-bulk-delete');
    if (await bulkButton.isVisible().catch(() => false)) {
      await expect(bulkButton).toBeDisabled();
    }
  });
});
