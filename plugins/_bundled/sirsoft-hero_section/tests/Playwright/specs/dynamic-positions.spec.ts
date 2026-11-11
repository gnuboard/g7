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
test('관리자: 동적 위치 탭은 배너 없는 대상도 찾아 고르게 하고, 고르면 그 대상 배너 목록·사이트 주소를 보인다', async ({ page, request, managerToken, created }) => {
  const title = uniqueTitle('관리자 고르기');
  created.push(await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.free.id, title: { ko: title } }));
  await authenticatePage(page, managerToken);

  await page.goto(`${ADMIN_LIST}?position=board_hero`);
  await expect(page.getByText('배너가 없는 게시판은(는) 위 고르기 칸에서', { exact: false }).first()).toBeVisible({ timeout: 60_000 });
  await expect(page.getByRole('link', { name: /배너 등록/ })).toHaveCount(0);

  await page.getByRole('button', { name: '게시판 고르기' }).first().click();
  await page.getByRole('textbox').last().fill('자유');
  // 대상 목록에도 같은 이름이 있으니 고르기 칸의 후보를 누른다
  await page.getByRole('listbox').last().getByRole('option', { name: /자유게시판/ }).click({ timeout: 30_000 });

  await page.waitForURL(/target=2/);
  await expect(page.locator('#hero_banner_datagrid, table').getByText(title).first()).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('link', { name: '사이트에서 보기' })).toHaveAttribute('href', '/board/free');
  await expect(page.getByRole('link', { name: /배너 등록/ }).first()).toHaveAttribute('href', /position=board_hero.*target=2|target=2.*position=board_hero/);

  // 상품 상세 탭으로 옮기면 게시판에서 찾은 후보(자유게시판)가 상품 고르기 칸에 남지 않는다 — 남으면 그 후보를 눌러 게시판 id 를 상품 대상으로 고르게 된다
  await page.getByRole('link', { name: /상품 상세/ }).first().click();
  await page.waitForURL(/position=product_hero/);
  expect(new URL(page.url()).searchParams.get('target')).toBeNull();
  await expect(page.getByText('배너가 없는 상품은(는) 위 고르기 칸에서', { exact: false }).first()).toBeVisible({ timeout: 30_000 });
  await page.getByRole('button', { name: '상품 고르기' }).first().click();
  const listbox = page.getByRole('listbox').last();
  await expect(listbox).toBeVisible();
  await expect(listbox.getByText('이름을 입력하면 찾습니다')).toBeVisible();
  await expect(listbox.getByRole('option', { name: /자유게시판/ })).toHaveCount(0);
});

// @scenario case=admin_placement_list
// @effects test_data_cleaned_up
test('관리자: 동적 위치는 배너가 있는 대상 목록(전체·노출 중 건수)에서 관리로 들어가고, 대상 목록으로 돌아오며, 뒤로 가기·새로고침에도 같은 화면이다', async ({ page, request, managerToken, created }) => {
  const shown = uniqueTitle('대상목록 노출');
  const hidden = uniqueTitle('대상목록 비노출');
  created.push(await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.event.id, title: { ko: shown } }));
  created.push(await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.event.id, title: { ko: hidden }, is_active: false }));
  await authenticatePage(page, managerToken);

  await page.goto(`${ADMIN_LIST}?position=board_hero`);
  const card = page.locator('section[aria-labelledby="hero_target_list_heading"]');
  await expect(card).toBeVisible({ timeout: 60_000 });
  // 대상을 고르기 전에는 선택된 대상처럼 「총 0건·지금 노출 0개」를 그리지 않는다
  await expect(page.locator('#hero_banner_datagrid')).toHaveCount(0);
  await expect(page.locator('#hero_preview_heading')).toHaveCount(0);

  const eventRow = card.locator('li').filter({ has: page.getByRole('link', { name: '관리' }) }).filter({ hasText: '전체 2개 · 지금 노출 1개' });
  await expect(eventRow).toHaveCount(1, { timeout: 30_000 });
  await expect(eventRow.getByRole('link', { name: '사이트에서 보기' })).toHaveAttribute('href', `/board/${BOARD.event.slug}`);

  await eventRow.getByRole('link', { name: '관리' }).click();
  await page.waitForURL(new RegExp(`target=${BOARD.event.id}`));
  await expect(page.locator('#hero_banner_datagrid, table').getByText(shown).first()).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#hero_banner_datagrid, table').getByText(hidden).first()).toBeVisible();
  await expect(page.getByRole('heading', { level: 2 }).filter({ hasText: '배너' }).first()).toBeVisible();

  // 대상 목록으로 → 뒤로 가기(상세) → 새로고침(상세 유지)
  await page.getByRole('link', { name: '대상 목록으로' }).click();
  await page.waitForURL((url) => url.searchParams.get('position') === 'board_hero' && !url.searchParams.get('target'));
  await expect(card).toBeVisible({ timeout: 30_000 });
  await page.goBack();
  await page.waitForURL(new RegExp(`target=${BOARD.event.id}`));
  await page.reload();
  await expect(page.locator('#hero_banner_datagrid, table').getByText(shown).first()).toBeVisible({ timeout: 60_000 });

  // 대상 목록의 다른 대상으로 화면 링크 이동(SPA) — 앞 대상 배너가 섞이지 않는다
  await page.getByRole('link', { name: '대상 목록으로' }).click();
  await expect(card).toBeVisible({ timeout: 30_000 });
  await page.evaluate(() => { (window as any).__heroSpaMark = true; });
  const freeRow = card.locator('li').filter({ has: page.getByRole('link', { name: '관리' }) }).filter({ has: page.locator(`a[href="/board/${BOARD.free.slug}"]`) });
  await freeRow.getByRole('link', { name: '관리' }).click();
  await page.waitForURL(new RegExp(`target=${BOARD.free.id}`));
  await expect(page.locator('#hero_banner_datagrid')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#hero_banner_datagrid').getByText(shown)).toHaveCount(0);
  expect(await page.evaluate(() => (window as any).__heroSpaMark === true)).toBe(true);
});

/**
 * 대상 목록 응답을 꾸며 돌려줍니다 — 외부 확장이 주는 기호 키·여러 쪽을 개발 DB 를 바꾸지 않고 만든다.
 *
 * @param page 페이지
 * @param items 행
 */
async function fakePlacements(page: Page, items: Array<Record<string, unknown>>): Promise<void> {
  await page.route(`**${API}/admin/positions/board_hero/placements*`, async (route) => {
    const current = Number(new URL(route.request().url()).searchParams.get('page') ?? 1);
    await route.fulfill({ json: { success: true, message: '', data: { position: 'board_hero', data: items, summary: { banners: 25, targets: 22 }, pagination: { current_page: current, last_page: 2, total: 22, per_page: 20 } } } });
  });
}

// @scenario case=admin_placement_list
test('관리자: 대상 목록의 「관리」는 기호(&·=)가 든 저장 키를 통째로 싣고 보던 쪽을 이고 가며, 「대상 목록으로」가 그 쪽으로 돌아온다', async ({ page, managerToken }) => {
  await fakePlacements(page, [{ key: 'event&split=1', label: '기호 대상', exists: true, site_url: null, total: 1, active: 1 }]);
  await authenticatePage(page, managerToken);

  await page.goto(`${ADMIN_LIST}?position=board_hero&tpage=2`);
  const card = page.locator('section[aria-labelledby="hero_target_list_heading"]');
  const row = card.locator('li').filter({ hasText: '기호 대상' });
  await expect(row).toHaveCount(1, { timeout: 60_000 });
  await row.getByRole('link', { name: '관리' }).click();
  await page.waitForURL((url) => url.searchParams.get('target') === 'event&split=1');
  const detail = new URL(page.url());
  expect(detail.searchParams.get('split')).toBeNull();
  expect(detail.searchParams.get('tpage')).toBe('2');

  await page.getByRole('link', { name: '대상 목록으로' }).click();
  await page.waitForURL((url) => !url.searchParams.get('target') && url.searchParams.get('tpage') === '2');
  await expect(card.getByText('2 / 2')).toBeVisible({ timeout: 30_000 });
});

// @scenario case=admin_placement_list
test('관리자: 대상 목록을 받지 못하면 「불러오는 중」에 머물지 않고 실패를 알리고, 다시 받기로 목록이 나온다', async ({ page, managerToken }) => {
  let fail = true;
  await page.route(`**${API}/admin/positions/board_hero/placements*`, async (route) => {
    if (fail) {
      await route.fulfill({ status: 500, json: { success: false, message: 'injected' } });
      return;
    }
    await route.fulfill({ json: { success: true, message: '', data: { position: 'board_hero', data: [{ key: '2', label: '다시 받은 대상', exists: true, site_url: '/board/free', total: 1, active: 1 }], summary: { banners: 1, targets: 1 }, pagination: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } } });
  });
  await authenticatePage(page, managerToken);

  await page.goto(`${ADMIN_LIST}?position=board_hero`);
  const card = page.locator('section[aria-labelledby="hero_target_list_heading"]');
  await expect(card.getByText('대상 목록을 받지 못했습니다.')).toBeVisible({ timeout: 60_000 });
  await expect(card.getByText('대상 목록을 불러오는 중입니다')).toHaveCount(0);

  fail = false;
  await card.getByRole('button', { name: '다시 받기' }).click();
  await expect(card.locator('li').filter({ hasText: '다시 받은 대상' })).toHaveCount(1, { timeout: 30_000 });
  await expect(card.getByText('대상 목록을 받지 못했습니다.')).toHaveCount(0);
});

// @scenario case=admin_form_position_target
// @effects test_data_cleaned_up
test('관리자: 수정 화면은 위치 설정이 오기 전에 저장을 막아 대상 없이(422) 보내지 않고, 받은 뒤 그 대상으로 저장한다', async ({ page, request, managerToken, created }) => {
  const id = await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.event.id, title: { ko: uniqueTitle('설정 지연 저장') } });
  created.push(id);
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => { release = resolve; });
  await page.route(`**${API}/admin/position-settings*`, async (route) => {
    await held;
    await route.continue();
  });
  await authenticatePage(page, managerToken);

  await page.goto(`/admin/plugins/sirsoft-hero_section/banners/${id}/edit?position=board_hero&target=${BOARD.event.id}`);
  const save = page.locator('#footer_save_button');
  await expect(page.getByText('위치 정보를 받는 중이라 아직 저장할 수 없습니다.')).toBeVisible({ timeout: 60_000 });
  await expect(save).toBeDisabled();

  release();
  await expect(save).toBeEnabled({ timeout: 30_000 });
  const put = page.waitForResponse((r) => r.url().endsWith(`${API}/admin/banners/${id}`) && r.request().method() === 'PUT');
  await save.click();
  const response = await put;
  expect(response.status()).toBe(200);
  expect(response.request().postDataJSON().target_key).toBe(BOARD.event.id);
});

/** HTTP 성공도 내용이 없으면 위치를 판정할 수 없다 — 정상 응답 재시도를 양성 대조로 둔다. */
const unusablePositionSettings = [
  ['루트 null', null],
  ['data null', { success: true, data: null }],
  ['빈 배열', { success: true, data: { positions: [] } }],
  ['배열 형식 오류', { success: true, data: { positions: {} } }],
] as const;

for (const [shape, payload] of unusablePositionSettings) {
  // @scenario case=admin_placement_list
  test(`관리자: 위치 설정 ${shape} 응답은 목록을 열지 않고 다시 받기로 복구한다`, async ({ page, managerToken }) => {
    await authenticatePage(page, managerToken);
    await page.route(`**${API}/admin/position-settings*`, (route) => route.fulfill({ status: 200, json: payload }));
    await page.goto(`${ADMIN_LIST}?position=board_hero`);
    await expect(page.getByText('위치 정보를 받지 못했습니다. 다시 받기를 눌러 주세요.', { exact: true })).toBeVisible({ timeout: 60_000 });
    await expect(page.getByText('위치 정보를 불러오는 중입니다', { exact: true })).toHaveCount(0);
    await expect(page.locator('table')).toHaveCount(0);
    await expect(page.locator('section[aria-labelledby="hero_preview_heading"]')).toHaveCount(0);
    await expect(page.locator('section[aria-labelledby="hero_slider_heading"]')).toHaveCount(0);
    await page.unroute(`**${API}/admin/position-settings*`);
    await page.getByRole('button', { name: '다시 받기', exact: true }).click();
    await expect(page.locator('section[aria-labelledby="hero_target_list_heading"]')).toBeVisible({ timeout: 60_000 });
    await expect(page.getByRole('button', { name: '다시 받기', exact: true })).toHaveCount(0);
  });

  // @scenario case=admin_form_position_target
  // @effects test_data_cleaned_up
  test(`관리자: 위치 설정 ${shape} 응답은 수정 저장을 막고 복구 뒤 대상 키를 보존한다`, async ({ page, request, managerToken, created }) => {
    const title = uniqueTitle('빈 설정 복구');
    const id = await createBanner(request, managerToken, { position: 'board_hero', target_key: BOARD.event.id, title: { ko: title } });
    created.push(id);
    await authenticatePage(page, managerToken);
    await page.route(`**${API}/admin/position-settings*`, (route) => route.fulfill({ status: 200, json: payload }));
    await page.goto(`${ADMIN_LIST}/${id}/edit?position=board_hero&target=${BOARD.event.id}`);
    const save = page.locator('#footer_save_button');
    await expect(page.getByText('위치 정보를 받지 못해 저장할 수 없습니다', { exact: false })).toBeVisible({ timeout: 60_000 });
    await expect(save).toBeDisabled();
    await expect(page.locator('input[name="title[ko]"]')).toHaveValue(title);
    await page.locator('input[name="title[ko]"]').fill(`${title} 수정`);
    await page.unroute(`**${API}/admin/position-settings*`);
    await page.getByRole('button', { name: '다시 받기', exact: true }).click();
    await expect(save).toBeEnabled({ timeout: 60_000 });
    await expect(page.locator('input[name="title[ko]"]')).toHaveValue(`${title} 수정`);
    const put = page.waitForResponse((r) => r.url().endsWith(`${API}/admin/banners/${id}`) && r.request().method() === 'PUT');
    await save.click();
    const response = await put;
    expect(response.status()).toBe(200);
    expect(response.request().postDataJSON().target_key).toBe(BOARD.event.id);
  });
}

/** 서버는 등록되지 않은 위치를 배너가 남았을 때만 내준다 — 위치 목록은 왔는데 주소의 위치만 없으면 다시 받아도 오지 않는다. 실제 응답으로 본다. */
const GONE_POSITION = 'qa_gone_hero';

// @scenario case=admin_placement_list
test('관리자: 위치 목록에 없는 위치 주소는 다시 받기 대신 없는 위치로 알리고 탭으로 옮길 수 있다', async ({ page, managerToken }) => {
  await authenticatePage(page, managerToken);
  await page.goto(`${ADMIN_LIST}?position=${GONE_POSITION}`);
  await expect(page.getByText('이 위치는 지금 없습니다', { exact: false })).toBeVisible({ timeout: 60_000 });
  await expect(page.getByRole('button', { name: '다시 받기', exact: true })).toHaveCount(0);
  await expect(page.getByText('위치 정보를 불러오는 중입니다', { exact: true })).toHaveCount(0);
  await expect(page.locator('table')).toHaveCount(0);
  await expect(page.locator('section[aria-labelledby="hero_slider_heading"]')).toHaveCount(0);
  await page.locator('a[href$="?position=home_hero"]').first().click();
  await expect(page.locator('section[aria-labelledby="hero_slider_heading"]')).toBeVisible({ timeout: 60_000 });
  await expect(page.getByText('이 위치는 지금 없습니다', { exact: false })).toHaveCount(0);
});

// @scenario case=admin_form_position_target
test('관리자: 없는 위치로 연 등록 화면은 다시 받기 대신 위치를 다시 고르게 하고 저장을 잠근다', async ({ page, managerToken }) => {
  await authenticatePage(page, managerToken);
  await page.goto(`${ADMIN_LIST}/create?position=${GONE_POSITION}`);
  await expect(page.getByText('고른 위치가 없어 저장할 수 없습니다', { exact: false })).toBeVisible({ timeout: 60_000 });
  await expect(page.locator('#footer_save_button')).toBeDisabled();
  await expect(page.getByRole('button', { name: '다시 받기', exact: true })).toHaveCount(0);
  await page.getByRole('radio', { name: '사이트 홈', exact: true }).check();
  await expect(page.getByText('고른 위치가 없어 저장할 수 없습니다', { exact: false })).toHaveCount(0);
  await expect(page.getByText('PC 이미지를 올려야 저장할 수 있습니다', { exact: false })).toBeVisible();
});
