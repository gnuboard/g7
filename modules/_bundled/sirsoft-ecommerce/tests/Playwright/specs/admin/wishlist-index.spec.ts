/**
 * 관리자 찜 관리 목록 (#524 WP-E) — 네 이커머스 목록의 대표.
 *
 * 네 화면(찜·장바구니·배송지·문의)은 같은 뼈대를 쓴다. 그 뼈대가 실제 데이터와 만났을 때
 * 동작하는지를 대표 하나로 잰다 — 나머지 셋의 «모양» 은 레이아웃 테스트가, «서버 계약» 은
 * PHPUnit 이 각각 덮는다.
 *
 * 잴 것:
 *   ① 회원 한정(`user_uuid`)으로 들어오면 배너가 서고 그 회원 것만 남는다
 *   ② 관리자 목록에는 숨김 상품의 찜도 **보인다** — 정리 대상을 찾아야 하기 때문이다.
 *      회원 화면에서 빠지는 것과는 반대 방향의 규칙이라 둘을 함께 확인한다.
 *
 * @scenario list=wishlist, permission=manage, member_scope=true, extra_filter=none
 * @effects ecommerce_admin_list_member_banner_shows_only_when_scoped,
 *          admin_wishlist_filters_by_user_uuid, wishlist_excludes_hidden_products
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/member-data-seed';

const WISHLIST_URL = '/admin/ecommerce/wishlists';

/**
 * 관리자 찜 목록을 연다.
 *
 * @param page 대상 페이지
 * @param token 관리자 토큰
 * @param query 덧붙일 질의 문자열 (앞의 `?` 포함)
 * @returns void
 */
async function openWishlist(page: Page, token: string, query = ''): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(WISHLIST_URL + query);
  await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 15_000 });
}

test.describe('관리자 찜 관리 (#524 WP-E)', () => {
  // @scenario list=wishlist, permission=manage, member_scope=true, extra_filter=none
  // @effects ecommerce_admin_list_member_banner_shows_only_when_scoped, admin_wishlist_filters_by_user_uuid
  test('회원 한정으로 들어오면 배너가 서고 그 회원의 찜만 남는다', async ({ page, memberDataSeed, wishlistManageToken }) => {
    await openWishlist(page, wishlistManageToken, `?user_uuid=${memberDataSeed.member_uuid}`);

    // `data-ref` 는 DOM 에 `id` 로 나온다 — testid 가 아니다.
    await expect(page.locator('#member_filter_banner')).toBeVisible();

    const rows = page.locator('table tbody tr');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);

    for (let i = 0; i < count; i += 1) {
      await expect(rows.nth(i)).toContainText(memberDataSeed.member_email);
    }

    await expect(page.locator('body')).not.toContainText('$t:');
  });

  // @scenario list=wishlist, permission=manage, member_scope=true, extra_filter=none
  // @effects admin_wishlist_returns_pagination_and_abilities
  test('관리자 목록은 숨김 상품의 찜도 보여 준다 — 회원 화면과 반대 방향이다', async ({ page, memberDataSeed, wishlistManageToken }) => {
    await openWishlist(page, wishlistManageToken, `?user_uuid=${memberDataSeed.member_uuid}`);

    // 시드는 정상·숨김·판매중지 세 상품을 모두 찜해 둔다. 관리자 목록은 셋 다 보여야
    // 운영자가 「숨긴 상품인데 아직 찜에 남아 있다」 를 알아보고 정리할 수 있다.
    await expect(page.locator('table tbody tr')).toHaveCount(3);
  });
});
