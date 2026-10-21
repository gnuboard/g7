/**
 * 관리자 게시판 — 회원 상세 링크는 주소가 있을 때만 (#757 2단계 후속, M1 실측).
 *
 * 회원 글인데 회원 계정이 남지 않으면 작성자 응답이 `is_guest: false · uuid: null` 이다. 작성자 자리가
 * `!is_guest` 만 보고 링크를 그리면 글자 없는 `href="/admin/users/"` 링크가 생긴다(M1 크롤: 게시글 목록 첫 행).
 * 단위 시험(`member-detail-link-guard.test.ts`)은 굽기 산출물의 조건식을, 이 spec 은 실제 화면에서
 * 「빈 회원 링크 0 · 회원 링크는 모두 글자를 가진다」를 잰다. 회원 계정 없는 글이 없는 사이트에서도
 * 단언은 성립한다(그때는 빈 링크가 생길 자리가 없다).
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

/** 실측 대상 게시판 — 시드가 항상 만드는 자유게시판 */
const POSTS_URL = '/admin/board/free';

test.describe('관리자 게시판 — 회원 상세 링크 가드 (#757)', () => {
  // @scenario guard=member_link
  // @effects member_link_requires_uuid, member_name_shown_without_uuid
  test('게시글 목록에 주소 없는 회원 링크가 없고, 회원 링크는 모두 이름을 가진다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(POSTS_URL);
    await page.locator('#advanced_search_toggle').waitFor({ state: 'visible', timeout: 60_000 });
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 게시글 표 안만 본다 — 사이드바 「사용자 관리」(/admin/users)는 정상 메뉴다(data-ref 는 DOM 에 id 로 내려온다)
    const grid = page.locator('#posts_data_grid');
    await expect(grid).toBeVisible({ timeout: 30_000 });
    // 행이 실제로 그려진 뒤에 잰다 — 목록이 비거나 아직 안 왔으면 아래 「0개」 단언이 아무것도 재지 않고 통과한다
    await expect(grid.locator('a[href^="/admin/board/free/post/"]').first()).toBeVisible({ timeout: 30_000 });

    // uuid 가 비어 「/admin/users/」로 끝나는 회원 상세 링크는 하나도 없어야 한다
    await expect(grid.locator('a[href="/admin/users/"]')).toHaveCount(0);

    // 남은 회원 상세 링크는 모두 글자(작성자 이름)를 가진다
    const memberLinks = grid.locator('a[href^="/admin/users/"]');
    const count = await memberLinks.count();
    // 회원 글이 하나도 없는 사이트도 정상이다 — 그때는 이름 확인이 0건이었다는 사실을 실행 기록에 남긴다
    test.info().annotations.push({ type: 'member-links', description: String(count) });
    for (let i = 0; i < count; i++) {
      await expect(memberLinks.nth(i)).not.toHaveText(/^\s*$/);
    }
  });
});
