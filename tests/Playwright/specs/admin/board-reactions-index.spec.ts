/**
 * 관리자 반응 관리 (#525 WP-C).
 *
 * 실브라우저로 네 갈래를 잰다:
 *   ① 전 회원의 반응이 한 목록에 서고 상태가 배지로 갈린다(삭제된 대상 · 게시판 삭제됨 ·
 *      반응 꺼짐 · 탈퇴 · 비활성 유형)
 *   ② 유형·대상 필터가 서버까지 닿아 건수를 바꾼다 — 장식 셀렉트가 아니다
 *   ③ 삭제 권한이 없으면 체크 칸과 일괄 버튼이 열리지 않는다
 *   ④ 회원 상세에서 건너온 상태(`user_uuid`)를 배너가 말하고, 해제하면 전체로 돌아온다
 *
 * ② 와 ④ 가 이 화면의 핵심이다. 레이아웃 계약 테스트(vitest)는 셀렉트가 응답 메타를 순회하고
 * 명명 액션이 두 조건을 싣는다는 것까지 말할 수 있지만, 그 값이 서버까지 닿아 결과를 바꾸는지는
 * 여기서만 보인다.
 *
 * @scenario case=screen_binds_all_filters_from_query
 * @scenario case=screen_gates_bulk_on_manage_ability
 * @effects admin_screen_datasource_reads_every_filter_from_query, admin_screen_type_select_iterates_response_meta,
 *          admin_screen_named_action_carries_type_and_target, admin_screen_selectable_follows_can_manage,
 *          index_marks_deleted_target_board_off_withdrawn_and_inactive_type
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/reaction-seed';

const LIST_URL = '/admin/boards/reactions';

/**
 * 반응 관리 목록을 연다.
 *
 * @param page 대상 페이지
 * @param token 관리자 토큰
 * @param query 덧붙일 질의 문자열 (앞의 `?` 포함)
 * @returns void
 */
async function openList(page: Page, token: string, query = ''): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(LIST_URL + query);
  await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 15_000 });
}

test.describe('관리자 반응 관리 (#525 WP-C)', () => {
  // @scenario case=screen_binds_all_filters_from_query
  // @effects index_marks_deleted_target_board_off_withdrawn_and_inactive_type
  test('전 회원 반응이 서고 상태가 배지로 갈린다', async ({ page, reactionSeed, reactionManageToken }) => {
    await openList(page, reactionManageToken, `?user_uuid=${reactionSeed.member_a_uuid}`);

    // 회원 A 의 반응 7건 — 게시판이 지워졌거나 대상이 삭제된 행도 남아 있어야 한다.
    await expect(page.locator('table tbody tr')).toHaveCount(reactionSeed.reaction_ids.length);

    const body = page.locator('body');
    await expect(body).toContainText(reactionSeed.member_a_name);
    // 세 상태가 한 화면에 선다.
    await expect(body).toContainText('삭제된 대상');
    await expect(body).toContainText('게시판 삭제됨');
    await expect(body).toContainText('반응 꺼짐');
    // 언어 키가 풀리지 않은 채 노출되면 안 된다.
    await expect(body).not.toContainText('$t:');
  });

  // @scenario case=screen_binds_all_filters_from_query
  // @effects admin_screen_type_select_iterates_response_meta
  test('유형 필터가 서버까지 닿아 건수를 바꾼다', async ({ page, reactionSeed, reactionManageToken }) => {
    await openList(
      page,
      reactionManageToken,
      `?user_uuid=${reactionSeed.member_a_uuid}&reaction_type_id=${reactionSeed.reaction_type_dislike_id}`,
    );

    // 회원 A 의 비추천은 1건이다. 필터가 무시되면 7건이 그대로 남는다.
    await expect(page.locator('table tbody tr')).toHaveCount(1);
  });

  // @scenario case=screen_binds_all_filters_from_query
  // @effects admin_screen_named_action_carries_type_and_target
  test('대상 필터가 글과 댓글을 갈라 낸다', async ({ page, reactionSeed, reactionManageToken }) => {
    await openList(
      page,
      reactionManageToken,
      `?user_uuid=${reactionSeed.member_a_uuid}&target_type=comment`,
    );

    // 회원 A 의 댓글 반응은 1건이다.
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(1);
    await expect(page.locator('body')).toContainText('댓글');
  });

  // @scenario case=screen_binds_all_filters_from_query
  // @effects admin_screen_type_select_iterates_response_meta
  test('유형 셀렉트가 비활성 유형까지 선택지로 낸다', async ({ page, reactionSeed, reactionManageToken }) => {
    await openList(page, reactionManageToken);

    // 선택지는 목록 응답이 실어 준 메타로 채워진다 — 반응 조회 권한만 가진 매니저는
    // settings.read 가 없어 유형 API 를 부를 수 없으므로 이 메타가 유일한 출처다.
    // 먼저 응답 자체에 비활성 유형이 실려 오는지 본다(서버 계약).
    const listResponse = await page.waitForResponse(
      (r) => r.url().includes('/admin/reactions') && r.request().method() === 'GET' && r.ok(),
      { timeout: 20_000 },
    );
    const meta = (await listResponse.json())?.data?.reaction_types ?? [];

    expect(meta.some((t: { id: number }) => t.id === reactionSeed.reaction_type_inactive_id)).toBe(true);

    // 그 메타가 실제로 셀렉트까지 닿는지 본다(화면 계약). 상세검색 패널은 접혀 있고 셀렉트는
    // 메타가 도착한 뒤에 채워지므로, 펼친 상태에서 옵션이 붙을 때까지 기다린다.
    const typeFilter = page.locator('[data-testid="admin-reaction-type-filter"]').first();
    await expect(async () => {
      if (!(await typeFilter.isVisible())) {
        await page.locator('[data-testid="admin-reaction-advanced-toggle"]').first().click();
      }
      // 이미 비활성으로 돌린 유형도 남아야 그 유형으로 쌓인 과거 반응을 찾을 수 있다.
      await expect(typeFilter.locator('option').filter({ hasText: '(비활성)' })).toHaveCount(1, {
        timeout: 3_000,
      });
    }).toPass({ timeout: 30_000 });
  });

  // @scenario case=screen_binds_all_filters_from_query
  // @effects index_unknown_user_uuid_returns_empty
  test('없는 회원 uuid 는 빈 결과다 — 필터를 무시하고 전체를 보여주지 않는다', async ({ page, reactionManageToken }) => {
    await authenticatePage(page, reactionManageToken);
    await page.goto(`${LIST_URL}?user_uuid=00000000-0000-4000-8000-000000000999`);

    // 「이 회원의 반응」 을 요청한 화면에 남의 반응이 뜨면 안 된다.
    await expect(page.locator('table tbody tr')).toHaveCount(0, { timeout: 15_000 });
  });

  // @scenario case=screen_binds_all_filters_from_query
  // @effects admin_screen_named_action_carries_type_and_target
  test('회원 한정 배너가 서고 해제하면 전체로 돌아온다', async ({ page, reactionSeed, reactionManageToken }) => {
    await openList(page, reactionManageToken, `?user_uuid=${reactionSeed.member_a_uuid}`);

    // 배너가 없으면 운영자는 전체 목록을 보고 있다고 믿고 건수를 읽는다.
    const banner = page.locator('[data-testid="admin-reaction-member-banner"]');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('특정 회원의 반응만 표시 중');

    await page.locator('[data-testid="admin-reaction-member-banner-clear"]').click();

    await expect(page).toHaveURL(/\/admin\/boards\/reactions/);
    await expect(page).not.toHaveURL(/user_uuid=[0-9a-f]/);
    // 회원 한정이 풀리면 다른 회원의 반응도 함께 선다.
    await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 15_000 });
  });

  // @scenario case=screen_gates_bulk_on_manage_ability
  // @effects admin_screen_selectable_follows_can_manage
  test('삭제 권한이 없으면 체크 칸도 일괄 버튼도 열리지 않는다', async ({ page, reactionSeed, reactionViewOnlyToken }) => {
    await openList(page, reactionViewOnlyToken, `?user_uuid=${reactionSeed.member_a_uuid}`);

    // 고를 수단 자체가 없어야 한다 — 눌러 보고 403 을 받는 화면이면 안 된다.
    await expect(page.locator('table tbody input[type="checkbox"]')).toHaveCount(0);
    await expect(page.locator('[data-testid="admin-reaction-bulk-delete"]')).toBeDisabled();
  });

  // @scenario case=screen_gates_bulk_on_manage_ability
  // @effects admin_screen_modals_call_single_and_bulk_delete
  test('2건을 골라 한 번에 지우면 그만큼만 줄어든다', async ({ page, reactionSeed, reactionManageToken }) => {
    await openList(page, reactionManageToken, `?user_uuid=${reactionSeed.member_a_uuid}`);

    const before = reactionSeed.reaction_ids.length;
    // 목록이 완전히 자리잡기 전에 체크하면 곧바로 이어지는 재조회가 상태를 되돌린다.
    await expect(page.locator('table tbody tr')).toHaveCount(before);
    await page.waitForLoadState('networkidle');

    const checkboxes = page.locator('table tbody input[type="checkbox"]');
    // `check()` 는 예약된 이동이 끝나기를 기다리다 죽는다(표가 제자리에서 다시 그려진다).
    // 클릭 후 상태를 직접 단언하면 같은 것을 재면서 그 대기에 걸리지 않는다.
    await checkboxes.nth(0).click();
    await expect(checkboxes.nth(0)).toBeChecked();
    await checkboxes.nth(1).click();
    await expect(checkboxes.nth(1)).toBeChecked();

    // 선택 건수는 한 자리에서만 센다 — 두 곳이 세면 숫자가 어긋난다(#524 회귀).
    const selectedLabels = await page.locator('body').innerText();
    expect(selectedLabels.match(/개 선택됨/g)?.length ?? 0).toBe(1);

    const [request] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/admin/reactions/bulk') && r.method() === 'DELETE'),
      (async () => {
        await page.locator('[data-testid="admin-reaction-bulk-delete"]').click();
        await page.locator('[data-testid="admin-reaction-bulk-delete-confirm"]').click();
      })(),
    ]);

    expect(JSON.parse(request.postData() ?? '{}').ids).toHaveLength(2);
    await expect(page.locator('table tbody tr')).toHaveCount(before - 2, { timeout: 15_000 });
  });
});
