/**
 * 관리자 회원 알림함 (#524 WP-F).
 *
 * 실브라우저로 세 갈래를 잰다:
 *   ① 전 회원의 수신함이 한 목록에 서고 읽음/안 읽음이 배지로 갈린다
 *   ② 검색 대상 「제목」/「본문」 이 서로 다른 결과를 낸다 — 장식 셀렉트가 아니다
 *   ③ 삭제 권한이 없으면 체크 칸과 일괄 버튼이 열리지 않는다
 *
 * ② 가 이 화면의 핵심이다. 발송 이력 화면의 같은 이름 셀렉트는 서버 규칙에 없어 무시되는
 * 장식이었다(계획서 부록 B D7). 단위 테스트는 셀렉트에 네 값이 있다는 것까지만 말할 수 있고,
 * 그 값이 서버까지 닿아 결과를 바꾸는지는 여기서만 보인다.
 *
 * @scenario surface=admin_member_notifications, permission=delete, search_type=all, read=all, member_scope=false
 * @scenario surface=admin_member_notifications, permission=delete, search_type=subject, read=all, member_scope=false
 * @scenario surface=admin_member_notifications, permission=read, search_type=all, read=all, member_scope=false
 * @effects admin_member_notifications_lists_all_members, read_cell_shows_read_unread_badge,
 *          admin_member_notifications_search_type_branches, grid_selectable_only_with_delete_permission
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/member-notification-seed';

const INBOX_URL = '/admin/member-notifications';

/**
 * 관리자 회원 알림함을 연다.
 *
 * @param page 대상 페이지
 * @param token 관리자 토큰
 * @param query 덧붙일 질의 문자열 (앞의 `?` 포함)
 * @returns void
 */
async function openInbox(page: Page, token: string, query = ''): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(INBOX_URL + query);
  await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 15_000 });
}

test.describe('관리자 회원 알림함 (#524 WP-F)', () => {
  // @scenario surface=admin_member_notifications, permission=delete, search_type=all, read=all, member_scope=true
  // @effects admin_member_notifications_lists_all_members, read_cell_shows_read_unread_badge
  test('전 회원 수신함이 서고 읽음·안 읽음이 배지로 갈린다', async ({ page, notificationSeed, notificationManageToken }) => {
    await openInbox(page, notificationManageToken, `?user_uuid=${notificationSeed.member_uuid}`);

    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(notificationSeed.total_count);

    const body = page.locator('body');
    await expect(body).toContainText(notificationSeed.member_email);
    await expect(body).toContainText('안 읽음');
    await expect(body).toContainText('읽음');
    await expect(body).not.toContainText('$t:');
  });

  // @scenario surface=admin_member_notifications, permission=delete, search_type=all, read=unread, member_scope=true
  // @effects admin_member_notifications_read_filter
  test('읽음 필터가 서버까지 닿아 건수를 바꾼다', async ({ page, notificationSeed, notificationManageToken }) => {
    await openInbox(
      page,
      notificationManageToken,
      `?user_uuid=${notificationSeed.member_uuid}&read=unread`,
    );

    // 시드는 안 읽음 2 · 읽음 1 이다. 필터가 무시되면 3건이 그대로 남는다.
    await expect(page.locator('table tbody tr')).toHaveCount(notificationSeed.unread_count);
  });

  // @scenario surface=admin_member_notifications, permission=delete, search_type=subject, read=all, member_scope=true
  // @effects admin_member_notifications_search_type_branches
  test('제목 검색과 본문 검색이 서로 다른 결과를 낸다 — 장식 셀렉트가 아니다', async ({ page, notificationSeed, notificationManageToken }) => {
    const subjectQuery = `?user_uuid=${notificationSeed.member_uuid}`
      + `&search_type=subject&search=${encodeURIComponent(notificationSeed.subject_keyword)}`;
    await openInbox(page, notificationManageToken, subjectQuery);
    await expect(page.locator('table tbody tr')).toHaveCount(1);

    const bodyQuery = `?user_uuid=${notificationSeed.member_uuid}`
      + `&search_type=body&search=${encodeURIComponent(notificationSeed.body_keyword)}`;
    await page.goto(INBOX_URL + bodyQuery);
    await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('table tbody tr')).toHaveCount(1);

    // 제목 낱말을 본문에서 찾으면 걸리지 않아야 한다 — 두 갈래가 같은 곳을 보면 구분이 없는 것이다.
    const crossQuery = `?user_uuid=${notificationSeed.member_uuid}`
      + `&search_type=body&search=${encodeURIComponent(notificationSeed.subject_keyword)}`;
    await page.goto(INBOX_URL + crossQuery);
    await expect(page.locator('table tbody')).toContainText(/조회된 알림이 없습니다|No notifications found/i);
  });

  // @scenario surface=admin_member_notifications, permission=read, search_type=all, read=all, member_scope=true
  // @effects grid_selectable_only_with_delete_permission, admin_member_notifications_read_only_cannot_delete
  test('삭제 권한이 없으면 체크 칸과 일괄 버튼이 열리지 않는다', async ({ page, notificationSeed, notificationReadOnlyToken }) => {
    await openInbox(page, notificationReadOnlyToken, `?user_uuid=${notificationSeed.member_uuid}`);

    await expect(page.locator('table input[type="checkbox"]')).toHaveCount(0);
    await expect(page.getByTestId('bulk_delete_button')).toBeHidden();
  });
});
