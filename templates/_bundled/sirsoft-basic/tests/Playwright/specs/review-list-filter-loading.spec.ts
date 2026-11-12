/**
 * 후기 목록 조건을 바꾸는 동안 앞 목록을 가린다 — 리뷰 모아보기와 상품 상세 후기 탭.
 * 템플릿 sirsoft-basic (유저 화면 `/shop/reviews`, `/shop/products/{상품 코드}?tab=reviews`, 비회원).
 *
 * 결함: 리뷰 모아보기에서 「사진 후기만」을 누르면 바탕 레이아웃의 로딩 덮개가 렌더 직후 걷혀 두 번 반짝인 뒤 앞 목록이
 *   응답 전까지 그대로 보였다. 상품 상세 후기 탭은 「포토리뷰만 보기」를 누르면 단추만 바로 켜지고 앞 목록이 아무 표시 없이
 *   남았다. 이 spec 은 후기 응답만 이 브라우저에서 1.5초 늦춰, 그 사이 덮개나 흐림이 걸려 있고 응답 뒤에는 걷히는지 본다.
 *   수정 전 레이아웃에서는 늦춘 구간 가운데에 아무 가림이 없어 붉다.
 *
 * 데이터: 사진 후기가 달린 상품 코드는 공개 전체 후기 API 에서 고른다. 쓰기 요청은 가로챈다. 계정 불필요.
 *
 * @scenario-manifest tests/scenarios/e2e-review-list-filter-loading.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/review-list-filter-loading.spec.ts --workers=1
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { dismissCookieBanner, guardWrites, waitForSpa } from '../fixtures/env-account';

test.describe.configure({ timeout: 120_000 });

/** 늦출 시간 — 그 가운데쯤(DELAY_MS / 2)에 가림을 잰다 */
const DELAY_MS = 1500;

/**
 * 사진 후기가 달린 상품 코드 하나를 고른다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns 상품 코드(없으면 null)
 */
async function productWithPhotoReview(request: APIRequestContext): Promise<string | null> {
  const res = await request.get('/api/modules/sirsoft-ecommerce/reviews?photo_only=1&per_page=10', { headers: { Accept: 'application/json' } });
  if (!res.ok()) return null;
  const list = ((await res.json())?.data?.reviews?.data ?? []) as Array<{ product?: { product_code?: string } }>;

  return list.map((r) => r.product?.product_code).find((c): c is string => !!c) ?? null;
}

/**
 * 조건에 맞는 후기 요청만 늦춘다.
 *
 * @param page Playwright 페이지
 * @param path 후기 API 경로 판정
 * @param slow 늦출 요청인지(쿼리 판정)
 * @returns 늦춘 요청 수를 돌려주는 함수
 */
async function delayReviews(page: Page, path: (pathname: string) => boolean, slow: (q: URLSearchParams) => boolean): Promise<() => number> {
  let delayed = 0;
  await page.route(
    (url) => path(url.pathname),
    async (route) => {
      const url = new URL(route.request().url());
      if (slow(url.searchParams)) {
        delayed += 1;
        await new Promise((r) => setTimeout(r, DELAY_MS));
      }

      return route.continue();
    },
  );

  return () => delayed;
}

/**
 * 화면에 덮개 또는 흐림이 걸려 있는지.
 *
 * @param page Playwright 페이지
 * @returns 가림 여부
 */
async function veiled(page: Page): Promise<boolean> {
  return page.evaluate(
    () =>
      !!document.getElementById('g7-skeleton-overlay') ||
      Array.from(document.querySelectorAll('body *')).some((e) => (getComputedStyle(e).filter || '').includes('blur')),
  );
}

test.describe('후기 목록 조건 바꾸기 — 받는 동안 가림', () => {
  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  /**
   * @scenario case=feed_photo_only
   * @effects old_list_veiled_while_loading, veil_cleared_after_response
   */
  test('리뷰 모아보기에서 「사진 후기만」을 누르면 새 목록이 올 때까지 가림이 이어진다', async ({ page }) => {
    await guardWrites(page);
    const delayed = await delayReviews(
      page,
      (p) => p === '/api/modules/sirsoft-ecommerce/reviews',
      (q) => q.get('photo_only') === '1',
    );

    await page.goto('/shop/reviews');
    await waitForSpa(page);
    await dismissCookieBanner(page);
    const box = page.locator('#review_feed_photo_only');
    await expect(box).toBeVisible({ timeout: 30_000 });
    await expect.poll(() => veiled(page), { message: '누르기 전 가림 없음' }).toBe(false);

    await box.click();
    await expect.poll(() => delayed(), { message: '사진 후기만 요청이 나감' }).toBeGreaterThan(0);
    // 덮개가 렌더 직후 걷히면(수정 전) 이 시점에 앞 목록이 또렷하다
    await page.waitForTimeout(DELAY_MS / 2);
    expect(await veiled(page), '응답 전 가운데 시점에 덮개 또는 흐림').toBe(true);

    await expect.poll(() => veiled(page), { message: '응답 뒤 가림이 걷힘', timeout: 15_000 }).toBe(false);
    await expect(box).toBeChecked();
    expect(new URL(page.url()).searchParams.get('photo_only')).toBe('1');
  });

  /**
   * @scenario case=detail_tab_photo_only
   * @effects old_list_veiled_while_loading, veil_cleared_after_response
   */
  test('상품 상세 후기 탭에서 「포토리뷰만 보기」를 누르면 새 목록이 올 때까지 앞 목록이 흐려진다', async ({ page, request }) => {
    const code = await productWithPhotoReview(request);
    test.skip(!code, '사진 후기가 달린 상품이 없어 잴 수 없다');
    await guardWrites(page);
    const delayed = await delayReviews(
      page,
      (p) => p === `/api/modules/sirsoft-ecommerce/products/${code}/reviews`,
      (q) => q.get('photo_only') === 'true',
    );

    await page.goto(`/shop/products/${code}?tab=reviews`);
    await waitForSpa(page);
    await dismissCookieBanner(page);
    const photo = page.getByRole('button', { name: /포토리뷰만 보기|Photo Reviews Only/ }).first();
    await expect(photo).toBeVisible({ timeout: 30_000 });
    await expect.poll(() => veiled(page), { message: '누르기 전 가림 없음' }).toBe(false);

    await photo.click();
    await expect.poll(() => delayed(), { message: '포토리뷰 요청이 나감' }).toBeGreaterThan(0);
    await page.waitForTimeout(DELAY_MS / 2);
    expect(await veiled(page), '응답 전 가운데 시점에 앞 목록이 흐림').toBe(true);

    await expect.poll(() => veiled(page), { message: '응답 뒤 흐림이 걷힘', timeout: 15_000 }).toBe(false);
  });
});
