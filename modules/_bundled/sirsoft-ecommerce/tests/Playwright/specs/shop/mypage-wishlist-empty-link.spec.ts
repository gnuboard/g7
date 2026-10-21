/**
 * 마이페이지 찜 목록 — 빈 목록의 「쇼핑하러 가기」 링크 (#757 WP-L)
 *
 * 찜한 상품이 없을 때 서는 「쇼핑하러 가기」는 단추가 아니라 상품 목록 주소를 가진 링크다 — 가운데 클릭·
 * 새 탭으로 상품 목록을 열 수 있고, 보통 클릭은 같은 창에서 상품 목록으로 옮긴다.
 *
 * 브라우저로만 드러나는 축: 링크 주소가 **상점 경로 설정(`_global.shopBase`)으로 풀린 실제 주소**인가.
 * 레이아웃 시험은 `{{_global.shopBase}}/products` 식 글자만 본다 — 바인딩이 풀리지 않으면 링크는 `/products`
 * 같은 엉뚱한 주소가 되거나 식 원문을 든다.
 *
 * 찜 목록 응답은 가로챈다 — 계정의 실제 찜 여부와 무관하게 빈 목록을 세운다.
 *
 * @scenario surface=mypage_empty
 * @effects mypage_wishlist_empty_state_links_to_products
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import { API, grantFunctionalConsent, shopBase, waitForApp } from '../../fixtures/claim-screens';

/**
 * 찜 목록 응답을 빈 목록으로 세웁니다.
 *
 * @param page Playwright 페이지
 */
async function mockEmptyWishlist(page: Page): Promise<void> {
  await page.route(`**${API}/wishlist?**`, async (route) => {
    if (route.request().method() !== 'GET') {
      await route.fallback();

      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: null,
        data: { data: [], pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 } },
      }),
    });
  });
}

test.describe('마이페이지 찜 목록 — 빈 목록 링크', () => {
  test.beforeEach(async ({ page, memberToken }) => {
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
  });

  /**
   * @scenario surface=mypage_empty
   * @effects mypage_wishlist_empty_state_links_to_products
   */
  test('「쇼핑하러 가기」는 상점 경로로 풀린 상품 목록 링크이고 보통 클릭은 그리로 옮긴다', async ({ page }) => {
    await mockEmptyWishlist(page);
    await page.goto('/mypage/wishlist');
    await waitForApp(page);

    await expect(page.getByText('찜한 상품이 없습니다.')).toBeVisible({ timeout: 30_000 });

    const base = await shopBase(page);
    const link = page.getByRole('link', { name: '쇼핑하러 가기', exact: true });
    await expect(link).toBeVisible();
    // 주소는 한 곳(href)이다 — 상점 경로 설정이 풀린 실제 주소여야 새 탭도 같은 곳을 연다.
    await expect(link, '「쇼핑하러 가기」가 상점 경로로 풀린 상품 목록 주소를 들지 않는다').toHaveAttribute('href', `${base}/products`);

    // 단추로 되돌아가면 새 탭으로 열 수 없다.
    await expect(page.getByRole('button', { name: '쇼핑하러 가기', exact: true })).toHaveCount(0);

    await link.click();
    await expect
      .poll(() => new URL(page.url()).pathname, { timeout: 20_000 })
      .toBe(`${base}/products`);
  });
});
