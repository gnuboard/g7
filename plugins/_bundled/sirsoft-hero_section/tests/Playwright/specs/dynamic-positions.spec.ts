/**
 * E2E: 동적 위치 — 게시판 목록·상품 상세·카테고리 목록에 그 대상의 배너만 나온다
 *
 * @scenario-manifest tests/scenarios/position-registry.yaml
 * @effects user_screen_reflects_admin_change, test_data_cleaned_up
 *
 * 개발 사이트에는 게시판(notice·free·gallery·event)·상품·카테고리(clothing·men)가 깔려 있다 — 사양은 그 대상에 배너를 만들고 지운다.
 * 조각은 화면 주소 값(게시판 slug·상품 코드·카테고리 slug)을 대상으로 공개 API 를 부르고, SPA 로 다른 대상으로 옮기면 다시 부른다.
 * 배너 없는 대상은 첫 방문에 자리(스켈레톤)도 잡지 않는다(D8).
 */
import { test, expect, authenticatePage, createBanner, gotoUser, hidePopups, uniqueTitle, ADMIN_LIST, API, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page } from '@playwright/test';

// 대상마다 배너를 만들고 화면을 여러 번 연다 — 개발 사이트 첫 로딩이 느리다
test.setTimeout(180_000);

// 개발 DB 의 대상 — 게시판 id·slug, 상품 id·코드, 카테고리 id·slug
const BOARD = { free: { id: '2', slug: 'free' }, event: { id: '4', slug: 'event' }, gallery: { id: '3', slug: 'gallery' } };
const PRODUCT = { id: '1', code: 'O2EPFKVNBO2TBX55' };
const OTHER_PRODUCT = { code: '8AM6MCBND70ZTXNP' };
const CATEGORY = { id: '2', slug: 'men' };

test.beforeEach(async ({ page }) => {
  await hidePopups(page);
  await page.setViewportSize(VIEWPORTS.desktop);
});

/**
 * 동적 위치 공개 응답(그 화면 값)이 올 때까지 기다리며 화면을 연다.
 *
 * @param page 페이지
 * @param path 사이트 경로
 * @param position 위치 키
 * @param target 화면 값
 */
async function openTarget(page: Page, path: string, position: string, target: string): Promise<void> {
  const response = page.waitForResponse((r) => r.url().includes(`${API}/hero`) && r.url().includes(`position=${position}`) && r.url().includes(`target=${encodeURIComponent(target)}`), { timeout: 60_000 });
  await gotoUser(page, path);
  await response;
}

// @scenario case=public_exposes_target_banners_only
// @effects user_screen_reflects_admin_change, test_data_cleaned_up
test('게시판마다 그 게시판 배너만 나오고, 배너 없는 게시판은 자리도 잡지 않는다', async ({ page, request, managerToken, created }) => {
  const free = uniqueTitle('자유게시판 배너');
  const event = uniqueTitle('이벤트 배너');
  created.push(await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.free.id, title: { ko: free } }));
  created.push(await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.event.id, title: { ko: event } }));

  await openTarget(page, `/board/${BOARD.free.slug}`, 'board_hero', BOARD.free.slug);
  await expect(page.locator('.hs-slider .hs-slider__title', { hasText: free }).first()).toBeAttached({ timeout: 30_000 });
  await expect(page.locator('.hs-slider__title', { hasText: event })).toHaveCount(0);

  await openTarget(page, `/board/${BOARD.event.slug}`, 'board_hero', BOARD.event.slug);
  await expect(page.locator('.hs-slider .hs-slider__title', { hasText: event }).first()).toBeAttached({ timeout: 30_000 });
  await expect(page.locator('.hs-slider__title', { hasText: free })).toHaveCount(0);

  // 배너 없는 게시판 — 첫 방문(브라우저 기억 없음)이라 받는 동안에도 스켈레톤이 없고, 받은 뒤에도 슬라이더가 없다
  await page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('sirsoft-hero_section:slot:board_hero')).forEach((k) => localStorage.removeItem(k)));
  await page.route(`**${API}/hero?*target=${BOARD.gallery.slug}*`, async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 1500));
    await route.continue();
  });
  const pending = page.waitForResponse((r) => r.url().includes(`target=${BOARD.gallery.slug}`), { timeout: 60_000 });
  await gotoUser(page, `/board/${BOARD.gallery.slug}`);
  await expect(page.locator('.hs-slider--skeleton')).toHaveCount(0);
  await pending;
  await expect(page.locator('section.hs-slider')).toHaveCount(0);
});

// @scenario case=dynamic_fragments_pass_route_target
// @effects user_screen_reflects_admin_change, test_data_cleaned_up
test('SPA 로 다른 게시판으로 옮기면 그 게시판 배너로 바뀌고, 새 응답이 오기 전 앞 게시판 배너가 남아 보이지 않는다', async ({ page, request, managerToken, created }) => {
  const free = uniqueTitle('이동 전 배너');
  const event = uniqueTitle('이동 뒤 배너');
  created.push(await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.free.id, title: { ko: free } }));
  created.push(await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.event.id, title: { ko: event } }));

  await openTarget(page, `/board/${BOARD.free.slug}`, 'board_hero', BOARD.free.slug);
  await expect(page.locator('.hs-slider .hs-slider__title', { hasText: free }).first()).toBeAttached({ timeout: 30_000 });
  // 새로고침 없이(SPA) 옮겼는지 창 표식으로 본다
  await page.evaluate(() => { (window as any).__heroSpaMark = true; });

  // 새 게시판 응답을 늦춰, 그 사이 앞 게시판 배너가 남아 있는지 본다
  await page.route(`**${API}/hero?*target=${BOARD.event.slug}*`, async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 2000));
    await route.continue();
  });
  const response = page.waitForResponse((r) => r.url().includes(`target=${BOARD.event.slug}`), { timeout: 60_000 });
  await page.evaluate((path) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path } }), `/board/${BOARD.event.slug}`);
  await page.waitForURL(`**/board/${BOARD.event.slug}`);
  await page.waitForTimeout(600);
  await expect(page.locator('.hs-slider__title', { hasText: free })).toHaveCount(0);

  await response;
  await expect(page.locator('.hs-slider .hs-slider__title', { hasText: event }).first()).toBeAttached({ timeout: 30_000 });
  expect(await page.evaluate(() => (window as any).__heroSpaMark === true)).toBe(true);
});

// @scenario case=public_exposes_target_banners_only
// @effects user_screen_reflects_admin_change, test_data_cleaned_up
test('상품 상세는 그 상품, 카테고리 목록은 그 카테고리 배너만 나온다', async ({ page, request, managerToken, created }) => {
  const productTitle = uniqueTitle('상품 배너');
  const categoryTitle = uniqueTitle('카테고리 배너');
  created.push(await createBanner(request, managerToken, { position: 'product_hero', target_key: PRODUCT.id, title: { ko: productTitle } }));
  created.push(await createBanner(request, managerToken, { position: 'category_hero', target_key: CATEGORY.id, title: { ko: categoryTitle } }));

  await openTarget(page, `/shop/products/${PRODUCT.code}`, 'product_hero', PRODUCT.code);
  await expect(page.locator('.hs-slider .hs-slider__title', { hasText: productTitle }).first()).toBeAttached({ timeout: 30_000 });

  await openTarget(page, `/shop/products/${OTHER_PRODUCT.code}`, 'product_hero', OTHER_PRODUCT.code);
  await expect(page.locator('.hs-slider__title', { hasText: productTitle })).toHaveCount(0);

  await openTarget(page, `/shop/category/${CATEGORY.slug}`, 'category_hero', CATEGORY.slug);
  await expect(page.locator('.hs-slider .hs-slider__title', { hasText: categoryTitle }).first()).toBeAttached({ timeout: 30_000 });
});

// @scenario case=admin_target_picker
// @effects test_data_cleaned_up
test('관리자: 동적 위치 탭은 대상을 먼저 고르게 하고, 고르면 그 대상 배너 목록·사이트 주소를 보인다', async ({ page, request, managerToken, created }) => {
  const title = uniqueTitle('관리자 고르기');
  created.push(await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.free.id, title: { ko: title } }));
  await authenticatePage(page, managerToken);

  await page.goto(`${ADMIN_LIST}?position=board_hero`);
  await expect(page.getByText('게시판을(를) 먼저 고르세요', { exact: false }).first()).toBeVisible({ timeout: 60_000 });
  await expect(page.getByRole('link', { name: /배너 등록/ })).toHaveCount(0);

  await page.getByRole('button', { name: '게시판 고르기' }).first().click();
  await page.getByRole('textbox').last().fill('자유');
  await page.getByText('자유게시판', { exact: false }).last().click({ timeout: 30_000 });

  await page.waitForURL(/target=2/);
  await expect(page.locator('#hero_banner_datagrid, table').getByText(title).first()).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('link', { name: '사이트에서 보기' })).toHaveAttribute('href', '/board/free');
  await expect(page.getByRole('link', { name: /배너 등록/ }).first()).toHaveAttribute('href', /position=board_hero.*target=2|target=2.*position=board_hero/);

  // 상품 상세 탭으로 옮기면 게시판에서 찾은 후보(자유게시판)가 상품 고르기 칸에 남지 않는다 — 남으면 그 후보를 눌러 게시판 id 를 상품 대상으로 고르게 된다
  await page.getByRole('link', { name: /상품 상세/ }).first().click();
  await page.waitForURL(/position=product_hero/);
  expect(new URL(page.url()).searchParams.get('target')).toBeNull();
  await expect(page.getByText('상품을(를) 먼저 고르세요', { exact: false }).first()).toBeVisible({ timeout: 30_000 });
  await page.getByRole('button', { name: '상품 고르기' }).first().click();
  const listbox = page.getByRole('listbox').last();
  await expect(listbox).toBeVisible();
  await expect(listbox.getByText('이름을 입력하면 찾습니다')).toBeVisible();
  await expect(listbox.getByRole('option', { name: /자유게시판/ })).toHaveCount(0);
});
