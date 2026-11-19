/**
 * #810 후속 — 슬라이더 페이징 모양(위치 설정): 진행 막대 점은 지금 장 점이 막대로 길어져 자동 넘김까지 차고, 배너 이름 탭은
 * 배너 제목 단추(휴대폰에서는 막대만, 이름은 화면 읽기용), 번호는 「01 / 03」. 관리 화면에서 고르면 저장 전에 미리보기가 따라가고
 * 저장 본문에 실린다.
 * @scenario-manifest tests/scenarios/slider-pagination.yaml
 *
 * 사용자 화면은 공개 API 응답을 `page.route` 로 시험 배너 3장 + 페이징 설정으로 바꾼다(운영 배너·설정은 건드리지 않는다).
 * 관리 화면 저장은 끝나면 원래 설정으로 되돌린다.
 */
import { test, expect, createBanner, gotoUser, hidePopups, authenticatePage, auth, API, ADMIN_LIST, VIEWPORTS } from '../fixtures/hero-auth';
import type { APIRequestContext, Page, Route } from '@playwright/test';

test.setTimeout(120_000);

// 팝업이 슬라이더 클릭을 가로채지 않게 비우고, 운영 배너가 비노출이어도 돌도록 시험 배너를 둔다(끝나면 지운다)
test.beforeEach(async ({ page, request, managerToken, created }) => {
  await hidePopups(page);
  created.push(await createBanner(request, managerToken));
});

const HERO_API = '**/api/plugins/sirsoft-hero_section/hero?position=home_hero*';
const TITLES = ['E2E 페이징 하나', 'E2E 페이징 둘', 'E2E 페이징 셋'];

/**
 * 홈 배너 응답을 시험 배너 3장과 주어진 페이징·자동 넘김 설정으로 바꾼다.
 *
 * @param page 페이지
 * @param settings 덮어쓸 위치 설정
 */
async function injectBanners(page: Page, settings: Record<string, unknown>): Promise<void> {
  await page.route(HERO_API, async (route: Route) => {
    const response = await route.fetch();
    const body = await response.json();
    const images = (body.data.banners as Array<Record<string, any>>)[0]?.images ?? { pc: null, mobile: null };
    body.data.banners = TITLES.map((title, i) => ({
      id: 990301 + i, title, description: '', button_text: '', image_alt: title, title_visible: true, description_visible: false,
      link_url: null, link_new_window: false, images, media_type: 'image', videos: null, presentation: null,
    }));
    body.data.settings = { ...body.data.settings, effect: 'slide', ...settings };
    await route.fulfill({ response, json: body });
  });
}

const hs = (page: Page) => page.locator('section.hs-slider[data-position="home_hero"]');

// @scenario surface=site_pagination_variants
// @effects pagination_follows_position_setting
test('진행 막대 점 — 지금 장 점이 24px 막대로 길어지고 자동 넘김 동안 막대가 차며, 마우스를 올리면 가득 찬 채 멈춘다 (desktop)', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await injectBanners(page, { autoplay: true, interval_seconds: 7, pagination: 'progress' });
  await gotoUser(page, '/');
  const active = hs(page).locator('.hs-slider__pagination--progress .hs-slider__bullet--active');
  await expect(active).toHaveCount(1, { timeout: 30_000 });
  await page.mouse.move(5, 5); // 슬라이더 밖
  await expect.poll(() => active.evaluate((el) => el.getBoundingClientRect().width)).toBe(24);
  const fill = active.locator('.hs-slider__bullet-fill');
  const width = () => fill.evaluate((el) => el.getBoundingClientRect().width);
  const first = await width();
  await page.waitForTimeout(1500);
  const later = await width();
  expect(later, `막대가 찬다 ${first} → ${later}`).toBeGreaterThan(first);
  expect(later).toBeLessThan(24);
  // 다른 점은 10px 그대로
  expect(await hs(page).locator('.hs-slider__pagination--progress .hs-slider__bullet:not(.hs-slider__bullet--active)').first().evaluate((el) => el.getBoundingClientRect().width)).toBe(10);

  await hs(page).hover();
  await expect.poll(width).toBe(24);
});

for (const viewport of ['desktop', 'mobile'] as const) {
  test.describe(viewport, () => {
    test.use({ hasTouch: viewport === 'mobile' });

    // @scenario surface=site_pagination_variants
    // @effects pagination_follows_position_setting
    test(`배너 이름 탭 — 배너 제목 단추를 누르면 그 장으로 가고, 휴대폰에서는 막대만 보이되 단추 이름은 남는다 (${viewport})`, async ({ page }) => {
      await page.setViewportSize(VIEWPORTS[viewport]);
      // 자동 넘김을 켜 일시정지 단추가 있는 상태에서 겹침을 보고, 장이 넘어가지 않게 먼저 멈춘다
      await injectBanners(page, { autoplay: true, interval_seconds: 7, pagination: 'tabs' });
      await gotoUser(page, '/');
      const tabs = hs(page).locator('.hs-slider__tabs > button.hs-slider__tab');
      await expect(tabs).toHaveCount(3, { timeout: 30_000 });
      const toggleButton = hs(page).locator('[data-hs-control="toggle"]');
      if (viewport === 'mobile') await toggleButton.tap();
      else await toggleButton.click();
      await expect(toggleButton).toHaveAttribute('aria-pressed', 'true');
      // 단추 이름 = 배너 제목(휴대폰에서도)
      await expect(hs(page).getByRole('button', { name: TITLES[2], exact: true })).toHaveCount(1);
      const labelWidth = await tabs.nth(0).locator('.hs-slider__tab-label').evaluate((el) => el.getBoundingClientRect().width);
      const boxes = await tabs.evaluateAll((els) => els.map((el) => { const r = el.getBoundingClientRect(); return { h: r.height, w: r.width }; }));
      if (viewport === 'desktop') {
        await expect(tabs.nth(0).locator('.hs-slider__tab-label')).toBeVisible();
        expect(labelWidth).toBeGreaterThan(20);
      } else {
        expect(labelWidth).toBeLessThanOrEqual(1);
      }
      for (const box of boxes) expect(box.h).toBeGreaterThanOrEqual(24);
      // 일시정지 단추와 겹치지 않는다
      const toggle = await toggleButton.boundingBox();
      const last = await tabs.nth(2).boundingBox();
      if (toggle && last) expect(last.x + last.width).toBeLessThanOrEqual(toggle.x);

      if (viewport === 'mobile') await tabs.nth(2).tap();
      else await tabs.nth(2).click();
      await expect(hs(page).locator('.hs-slider__slide--active')).toHaveAttribute('data-index', '2');
      await expect(tabs.nth(2)).toHaveAttribute('aria-current', 'true');
      await expect(tabs.nth(2).locator('.hs-slider__bullet-fill')).toHaveCount(1);
    });
  });
}

// @scenario surface=site_pagination_variants
// @effects pagination_follows_position_setting
test('번호 — 「01 / 03」을 보이고 넘기면 「02」로 바뀐다 (desktop)', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await injectBanners(page, { autoplay: false, pagination: 'fraction' });
  await gotoUser(page, '/');
  const fraction = hs(page).locator('.hs-slider__fraction');
  await expect(fraction).toBeVisible({ timeout: 30_000 });
  await expect(fraction).toHaveText(/01\s*\/\s*03/);
  await expect(hs(page).locator('.hs-slider__bullet')).toHaveCount(0);
  await hs(page).locator('.hs-slider__next').click();
  await expect(fraction.locator('.hs-slider__fraction-current')).toHaveText('02');
});

for (const viewport of ['desktop', 'mobile'] as const) {
  test.describe(`pill ${viewport}`, () => {
    test.use({ hasTouch: viewport === 'mobile' });

    // @scenario surface=slider_pagination_pill
    // @effects pagination_follows_position_setting
    test(`알약 번호 — 문구 아래 왼쪽 둥근 상자에 「1 / 3」과 이전·다음 단추를 두고 양옆 화살표는 없으며, 문구와 겹치지 않는다 (${viewport})`, async ({ page }) => {
      await page.setViewportSize(VIEWPORTS[viewport]);
      // 자동 넘김을 켜 일시정지 단추가 있는 상태에서 같은 줄인지 보고, 장이 넘어가지 않게 먼저 멈춘다
      await injectBanners(page, { autoplay: true, interval_seconds: 7, pagination: 'pill' });
      await gotoUser(page, '/');
      const pill = hs(page).locator('.hs-slider__pill-layer .hs-slider__pill');
      await expect(pill).toBeVisible({ timeout: 30_000 });
      const toggleButton = hs(page).locator('[data-hs-control="toggle"]');
      if (viewport === 'mobile') await toggleButton.tap();
      else await toggleButton.click();
      await expect(toggleButton).toHaveAttribute('aria-pressed', 'true');
      await expect(pill.locator('.hs-slider__pill-count')).toHaveText(/1\s*\/\s*3/);
      await expect(hs(page).locator('.hs-slider__arrow')).toHaveCount(0);
      const geometry = await hs(page).evaluate((section) => {
        const box = (selector: string) => section.querySelector(selector)?.getBoundingClientRect();
        const s = section.getBoundingClientRect();
        const p = box('.hs-slider__pill')!;
        const text = box('.hs-slider__slide--active .hs-slider__textbox')!;
        const toggle = box('[data-hs-control="toggle"]');
        return { toggleCenter: toggle ? toggle.top + toggle.height / 2 : null, pillCenter: p.top + p.height / 2, pillLeft: p.left - s.left, pillBottomGap: s.bottom - p.bottom, pillHeight: p.height, textLeft: text.left - s.left, textBottom: text.bottom, pillTop: p.top, bg: getComputedStyle(section.querySelector('.hs-slider__pill')!).backgroundColor, radius: getComputedStyle(section.querySelector('.hs-slider__pill')!).borderRadius };
      });
      // 문구와 같은 왼쪽 줄, 아래 넘김 단추 자리 안(문구 아래)
      expect(Math.abs(geometry.pillLeft - geometry.textLeft), JSON.stringify(geometry)).toBeLessThanOrEqual(2);
      expect(geometry.textBottom).toBeLessThanOrEqual(geometry.pillTop);
      expect(geometry.pillHeight).toBe(32);
      expect(geometry.pillBottomGap).toBeGreaterThanOrEqual(16);
      // 일시정지 단추와 같은 줄(세로 가운데 ±1px)
      expect(geometry.toggleCenter, JSON.stringify(geometry)).not.toBeNull();
      expect(Math.abs(geometry.toggleCenter! - geometry.pillCenter), JSON.stringify(geometry)).toBeLessThanOrEqual(1);
      expect(geometry.bg).toBe('rgba(17, 24, 39, 0.9)');
      expect(parseFloat(geometry.radius)).toBeGreaterThanOrEqual(16);

      const next = pill.getByRole('button', { name: '다음 배너', exact: true });
      if (viewport === 'mobile') await next.tap();
      else await next.click();
      await expect(hs(page).locator('.hs-slider__slide--active')).toHaveAttribute('data-index', '1');
      await expect(pill.locator('.hs-slider__pill-current')).toHaveText('2');
      // Swiper 반복 모드는 넘어가는 동안 들어온 넘김을 무시한다(양옆 화살표와 같다) — 전환이 끝난 뒤 누른다
      await page.waitForTimeout(1000);
      const prev = pill.getByRole('button', { name: '이전 배너', exact: true });
      if (viewport === 'mobile') await prev.tap();
      else await prev.click();
      await expect(pill.locator('.hs-slider__pill-current')).toHaveText('1');
    });
  });
}

/**
 * 위치 설정을 읽는다.
 *
 * @param request 요청 컨텍스트
 * @param token 관리자 토큰
 * @returns 홈 위치 설정
 */
async function readSettings(request: APIRequestContext, token: string): Promise<Record<string, any>> {
  const response = await request.get(`${API}/admin/position-settings`, { headers: auth(token) });
  return (await response.json()).data.positions.find((p: { key: string }) => p.key === 'home_hero').settings;
}

// @scenario surface=admin_pagination_setting
// @effects pagination_follows_position_setting
test('관리자 목록 — 페이징 모양을 고르면 저장 전에 미리보기가 바뀌고, 저장 본문에 실린다', async ({ page, request, managerToken, created }) => {
  // 페이징은 2장부터 그려진다
  created.push(await createBanner(request, managerToken));
  const original = await readSettings(request, managerToken);
  const preview = () => page.evaluate(() => {
    const frame = document.querySelector('#hero_preview iframe, .hs-preview iframe') as HTMLIFrameElement | null;
    const section = frame?.contentDocument?.querySelector('section.hs-slider') as HTMLElement | null;
    return section ? { tabs: !!section.querySelector('.hs-slider__tabs'), fraction: !!section.querySelector('.hs-slider__fraction'), cls: section.className } : null;
  });

  try {
    await page.setViewportSize(VIEWPORTS.desktop);
    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}?position=home_hero`);
    const select = page.locator('#hero_slider_pagination button').first();
    await expect(select).toBeVisible({ timeout: 30_000 });
    await expect.poll(async () => (await preview())?.cls ?? '').toContain(`hs-slider--pagination-${original.pagination ?? 'dots'}`);

    await select.click();
    await page.getByRole('option', { name: '배너 이름 탭', exact: true }).click();
    // 저장 전 — 미리보기만 탭으로
    await expect.poll(async () => (await preview())?.tabs).toBe(true);
    expect((await readSettings(request, managerToken)).pagination ?? 'dots').toBe(original.pagination ?? 'dots');

    await select.click();
    await page.getByRole('option', { name: '번호 (01 / 05)', exact: true }).click();
    await expect.poll(async () => (await preview())?.fraction).toBe(true);

    const saved = page.waitForResponse((r) => r.url().includes('/admin/position-settings/home_hero') && r.request().method() === 'PUT');
    await page.locator('#slider_save_button').click();
    const response = await saved;
    expect(response.status()).toBe(200);
    expect(JSON.parse(response.request().postData() ?? '{}').pagination).toBe('fraction');
    expect((await readSettings(request, managerToken)).pagination).toBe('fraction');
  } finally {
    await request.put(`${API}/admin/position-settings/home_hero`, { headers: auth(managerToken), data: original });
  }
});

// @scenario surface=admin_preview_without_swiper_errors
// @effects pagination_follows_position_setting
test('관리자 미리보기 — 자동 넘김 설정이어도 오류 없이 첫 장에 머물고, 미리보기 안 다음 단추·탭으로 장을 옮긴다 (desktop)', async ({ page, request, managerToken, created }) => {
  // 셋째 탭까지 누르므로 3장이 있어야 한다
  for (let i = 0; i < 2; i++) created.push(await createBanner(request, managerToken));
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.setViewportSize(VIEWPORTS.desktop);
  await authenticatePage(page, managerToken);
  await page.goto(`${ADMIN_LIST}?position=home_hero`);
  await expect(page.locator('#hero_slider_pagination')).toBeVisible({ timeout: 30_000 });
  const frame = page.frameLocator('#hero_preview iframe, .hs-preview iframe').first();
  const section = frame.locator('section.hs-slider');
  await expect(section).toHaveClass(/hs-slider--static/, { timeout: 30_000 });
  const active = () => section.locator('.hs-slider__slide--active').getAttribute('data-index');
  // 자동 넘김 간격(5초)을 두 번 넘겨 기다려도 오류가 없고 장이 그대로다
  await page.waitForTimeout(11_000);
  expect(errors).toEqual([]);
  expect(await active()).toBe('0');

  await section.locator('.hs-slider__next').click();
  await expect.poll(active).toBe('1');
  // 저장 전 탭 모양으로 바꿔 탭으로도 옮긴다
  await page.locator('#hero_slider_pagination button').first().click();
  await page.getByRole('option', { name: '배너 이름 탭', exact: true }).click();
  await expect(section.locator('.hs-slider__tabs > button.hs-slider__tab')).not.toHaveCount(0);
  await section.locator('.hs-slider__tabs > button.hs-slider__tab').nth(2).click();
  await expect.poll(active).toBe('2');
  // 보이는 장이 실제로 옮겨졌다(줄이 장 번호만큼 왼쪽으로)
  const shift = await section.locator('.swiper-wrapper').evaluate((el) => new DOMMatrixReadOnly(getComputedStyle(el).transform).m41);
  const width = await section.locator('.swiper').evaluate((el) => el.getBoundingClientRect().width);
  expect(Math.round(-shift / width)).toBe(2);
  expect(errors).toEqual([]);
});
