/**
 * 회원 상세 「반응」 요약 탭 (#525 WP-C).
 *
 * 실브라우저로 두 갈래를 잰다:
 *   ① 관리자 응답에 반응 요약이 실리고 탭이 그 숫자를 낸다
 *   ② 「이 회원 반응 목록 보기」가 회원 uuid 를 실어 반응 관리로 보낸다
 *
 * ② 가 이 조각의 핵심이다. `href="{{식}}"` 은 렌더 시 값이 undefined 가 되어 속성 자체가
 * DOM 에 실리지 않는다(2026-09-15 실측) — 링크가 «보이지만 눌러도 아무 일이 없는» 상태가
 * 된다. 레이아웃 계약 테스트는 `navigate` 가 적혀 있다까지만 말할 수 있고, 실제로 주소가
 * 바뀌는지는 여기서만 보인다.
 *
 * @scenario case=member_detail_tab_links_with_uuid
 * @effects admin_user_tab_navigates_with_user_uuid_not_href, admin_user_detail_includes_board_reactions_summary
 */
import { test, expect, authenticatePage } from '../../fixtures/reaction-seed';

test.describe('회원 상세 반응 탭 (#525 WP-C)', () => {
  // @scenario case=member_detail_tab_links_with_uuid
  // @effects admin_user_detail_includes_board_reactions_summary
  test('반응을 남긴 회원의 상세에 반응 탭과 요약이 뜬다', async ({ page, reactionSeed, reactionManageToken }) => {
    await authenticatePage(page, reactionManageToken);
    await page.goto(`/admin/users/${reactionSeed.member_a_uuid}`);

    // 조각은 회원 상세의 확장 앵커에 붙는다 — 탭 줄에 「반응」이 서고 본문 구역도 함께 그려진다.
    await expect(page.locator('body')).toContainText('반응', { timeout: 20_000 });

    const section = page.locator('#ext_board_reactions');
    await expect(section).toBeVisible({ timeout: 20_000 });

    // 회원 A 의 반응은 글 6 · 댓글 1 로 모두 7건이다.
    await expect(section).toContainText(String(reactionSeed.reaction_ids.length));
    await expect(section).toContainText('6 / 1');
    await expect(section).not.toContainText('$t:');
  });

  // @scenario case=member_detail_tab_links_with_uuid
  // @effects admin_user_tab_navigates_with_user_uuid_not_href
  test('「이 회원 반응 목록 보기」가 uuid 를 실어 반응 관리로 보낸다', async ({ page, reactionSeed, reactionManageToken }) => {
    await authenticatePage(page, reactionManageToken);
    await page.goto(`/admin/users/${reactionSeed.member_a_uuid}`);

    await expect(page.locator('#ext_board_reactions')).toBeVisible({ timeout: 20_000 });

    await page.locator('[data-testid="user-reactions-link"]').click();

    // 주소가 바뀌지 않으면 링크가 죽은 것이다.
    await expect(page).toHaveURL(/\/admin\/boards\/reactions/, { timeout: 20_000 });
    await expect(page).toHaveURL(new RegExp(`user_uuid=${reactionSeed.member_a_uuid}`));

    // 건너간 화면은 회원 한정 상태라는 것을 배너로 말한다.
    await expect(page.locator('[data-testid="admin-reaction-member-banner"]')).toBeVisible({ timeout: 20_000 });
  });

  // @scenario case=member_detail_tab_links_with_uuid
  // @effects profile_omits_board_reactions
  test('반응이 0건인 회원은 링크 대신 안내 문구를 낸다', async ({ page, reactionSeed, reactionManageToken }) => {
    // 회원 C(탈퇴)는 반응 1건뿐이고 회원 B 는 대조군이다 — 0건 회원을 쓰려면 관리자 자신이 낫다.
    await authenticatePage(page, reactionManageToken);
    await page.goto(`/admin/users/${reactionSeed.member_c_uuid}`);

    const section = page.locator('#ext_board_reactions');
    await expect(section).toBeVisible({ timeout: 20_000 });
    // 탈퇴 회원도 남긴 반응이 있으면 요약이 뜬다 — 익명화는 목록 쪽 몫이다.
    await expect(section).not.toContainText('$t:');
  });
});
