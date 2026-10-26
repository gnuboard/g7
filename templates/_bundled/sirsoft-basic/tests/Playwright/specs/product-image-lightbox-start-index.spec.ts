/**
 * 상품 상세 확대 창 — 고른 썸네일의 사진에서 열린다 (#821).
 * 템플릿 sirsoft-basic (유저 화면 `/shop/products/{상품 코드}` 의 상품 이미지 뷰어, 비회원).
 *
 * 결함: 썸네일로 큰 사진을 바꾼 뒤 큰 사진을 누르면 확대 창(라이트박스)이 고른 사진이 아니라 늘 첫 사진(「1 / N」)으로
 *   열렸다. ImageGallery 가 닫힌 채 마운트돼 있고 openGallery 가 startIndex 만 바꾸는데 현재 번호가 useState 초기값으로만
 *   잡혔기 때문이다. 열림 전환 렌더 중에 현재 번호를 startIndex 로 맞춰 고쳤다.
 *
 * 데이터: 공개 상품 목록·상세 API(GET)에서 이미지 3장 이상인 상품을 찾는다(`G7_E2E_PRODUCT` 로 상품 코드를 고정할 수 있다).
 *   없으면 사유와 함께 건너뛴다. 쓰기 요청은 가로챈다. 계정 불필요.
 *
 * @scenario-manifest tests/scenarios/e2e-product-image-lightbox-start-index.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/product-image-lightbox-start-index.spec.ts --workers=1
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { dismissCookieBanner, guardWrites, waitForSpa } from '../fixtures/env-account';

test.describe.configure({ timeout: 120_000 });

/** 상품 API */
const PRODUCTS_API = '/api/modules/sirsoft-ecommerce/products';

/** 판정에 필요한 최소 이미지 수 — 3번째 썸네일을 고른다 */
const MIN_IMAGES = 3;

/**
 * 이미지 3장 이상인 상품을 찾는다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns 상품 코드와 이미지 수(없으면 null)
 */
async function findProduct(request: APIRequestContext): Promise<{ code: string; count: number } | null> {
  const headers = { Accept: 'application/json' };
  const imageCount = async (code: string): Promise<number> => {
    const res = await request.get(`${PRODUCTS_API}/${code}`, { headers });
    if (!res.ok()) return 0;

    return ((await res.json())?.data?.images ?? []).length;
  };

  const fixed = process.env.G7_E2E_PRODUCT;
  if (fixed) {
    const count = await imageCount(fixed);

    return count >= MIN_IMAGES ? { code: fixed, count } : null;
  }

  const res = await request.get(`${PRODUCTS_API}?per_page=30`, { headers });
  if (!res.ok()) return null;
  const codes = ((await res.json())?.data?.data ?? [])
    .map((p: { product_code?: string }) => p.product_code)
    .filter((c: string | undefined): c is string => !!c);
  for (const code of codes) {
    const count = await imageCount(code);
    if (count >= MIN_IMAGES) return { code, count };
  }

  return null;
}

/** 이미지 뷰어 — 큰 사진 단추(확대 커서)와 썸네일 줄 */
const mainImage = (page: Page) => page.locator('button.cursor-zoom-in').first();
const thumbnails = (page: Page) => page.locator('button.cursor-zoom-in').first().locator('xpath=../following-sibling::div[1]').locator('button');

/** 확대 창 카운터 글자(공백 정리) */
const counter = (page: Page) => page.locator('.yarl__counter');

/**
 * n번째(1부터) 썸네일을 골라 큰 사진을 누르고 확대 창 카운터를 기다린다.
 *
 * @param page Playwright 페이지
 * @param n 고를 썸네일 번호(1부터)
 */
async function openAt(page: Page, n: number): Promise<void> {
  await thumbnails(page).nth(n - 1).click();
  await mainImage(page).click();
  await expect(counter(page)).toBeVisible();
}

test.describe('상품 상세 확대 창 시작 사진 (#821)', () => {
  let product: { code: string; count: number } | null = null;

  test.beforeEach(async ({ page, request }) => {
    product ??= await findProduct(request);
    test.skip(!product, `이미지 ${MIN_IMAGES}장 이상인 상품이 공개 목록에 없음 — G7_E2E_PRODUCT 로 상품 코드를 지정한다`);
    await guardWrites(page);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/shop/products/${product!.code}`);
    await waitForSpa(page);
    await dismissCookieBanner(page);
    await expect(thumbnails(page)).toHaveCount(product!.count);
  });

  /**
   * @scenario case=third_thumbnail
   * @effects lightbox_opens_at_selected_image
   */
  test('3번째 썸네일을 고른 뒤 큰 사진을 누르면 「3 / N」 으로 열린다', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

    await openAt(page, 3);

    await expect(counter(page)).toHaveText(new RegExp(`^\\s*3\\s*/\\s*${product!.count}\\s*$`));
    await expect(page.locator('.yarl__slide_current img')).toHaveAttribute('src', await thumbnails(page).nth(2).locator('img').getAttribute('src') ?? '');
    expect(errors).toEqual([]);
  });

  /**
   * @scenario case=navigate_then_reopen
   * @effects lightbox_opens_at_selected_image, lightbox_navigation_from_start
   */
  test('열린 뒤 ← 로 넘기면 「2 / N」, 닫고 1번째로 다시 열면 「1 / N」', async ({ page }) => {
    await openAt(page, 3);
    await page.keyboard.press('ArrowLeft');
    await expect(counter(page)).toHaveText(new RegExp(`^\\s*2\\s*/\\s*${product!.count}\\s*$`));

    await page.keyboard.press('Escape');
    await expect(counter(page)).toHaveCount(0);

    await openAt(page, 1);
    await expect(counter(page)).toHaveText(new RegExp(`^\\s*1\\s*/\\s*${product!.count}\\s*$`));
  });

  /**
   * @scenario case=mobile_width
   * @effects lightbox_opens_at_selected_image
   */
  test('390 폭에서도 고른 썸네일의 사진으로 열린다', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openAt(page, 2);

    await expect(counter(page)).toHaveText(new RegExp(`^\\s*2\\s*/\\s*${product!.count}\\s*$`));
  });
});
