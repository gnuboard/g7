/**
 * #810 후속 — 사용자 히어로 슬라이더의 Swiper 화면 요소: 기본 모양 좌우 화살표(데스크톱만)·점 표시·PC 마우스 끌기와
 * 추가 전환 효과(cube·flip·coverflow·creative)가 실브라우저 Swiper 에서 무한 반복과 함께 넘어가는지.
 * @scenario-manifest tests/scenarios/hero-slider.yaml
 *
 * 위치 설정은 사양이 바꾸고 끝나면 되돌린다. 배너는 사양이 만들고 fixture 가 지운다(운영 배너는 건드리지 않는다).
 */
import { test, expect, createBanner, gotoUser, hidePopups, auth, API, VIEWPORTS } from '../fixtures/hero-auth';
import type { APIRequestContext, Page } from '@playwright/test';

test.setTimeout(240_000);

// 팝업이 슬라이더 클릭을 가로채지 않게 비우고, 홈 응답을 복제하는 시험이 운영 배너 없이도 돌도록 시험 배너를 둔다(끝나면 지운다)
test.beforeEach(async ({ page, request, managerToken, created }) => {
  await hidePopups(page);
  created.push(await createBanner(request, managerToken));
});

type Settings = { autoplay: boolean; interval_seconds: number; effect: string; width?: string };

async function readSettings(request: APIRequestContext, token: string): Promise<Settings> {
  const response = await request.get(`${API}/admin/position-settings`, { headers: auth(token) });
  return ((await response.json()).data.positions as Array<{ key: string; settings: Settings }>).find((p) => p.key === 'shop_hero')!.settings;
}

async function saveSettings(request: APIRequestContext, token: string, settings: Settings): Promise<void> {
  const response = await request.put(`${API}/admin/position-settings/shop_hero`, { headers: auth(token), data: settings });
  expect(response.status(), await response.text()).toBe(200);
}

/** 쇼핑몰 메인 슬라이더의 Swiper 상태 */
const state = (page: Page) => page.evaluate(() => {
  const el = document.querySelector('section.hs-slider .swiper') as HTMLElement & { swiper: any };
  return { effect: el.swiper.params.effect, loop: el.swiper.params.loop, realIndex: el.swiper.realIndex, animating: el.swiper.animating, classes: el.className };
});

/**
 * 넘김이 끝날 때까지 기다린다 — 장 번호는 전환이 시작될 때 바뀌므로 전환 중(animating)이 풀린 것까지 본다. 전환 중에 누른
 * 단추는 Swiper 가 무시한다(무한 반복의 loopPreventsSliding).
 */
const settle = (page: Page, index: number) => expect.poll(async () => {
  const s = await state(page);
  return s.animating ? -1 : s.realIndex;
}, { timeout: 5_000 }).toBe(index);

// @scenario surface=swiper_extra_effects
// @scenario surface=swiper_pagination_and_arrows
test('추가 효과 4종이 무한 반복으로 넘어가고, 화살표·점·마우스 끌기로 장을 옮긴다(데스크톱)', async ({ page, request, managerToken, created }) => {
  const original = await readSettings(request, managerToken);
  for (let i = 0; i < 3; i++) {
    created.push(await createBanner(request, managerToken, { position: 'shop_hero', title: { ko: `E2E Swiper ${i} ${Date.now()}` } }));
  }

  try {
    await page.setViewportSize(VIEWPORTS.desktop);
    for (const effect of ['cube', 'flip', 'coverflow', 'creative'] as const) {
      await saveSettings(request, managerToken, { ...original, autoplay: false, effect });
      await gotoUser(page, '/shop/products');
      await expect(page.locator('section.hs-slider .hs-slider__pagination button').first()).toBeVisible({ timeout: 30_000 });
      const count = await page.locator('section.hs-slider .hs-slider__pagination button').count();
      const s = await state(page);
      expect(s.effect, effect).toBe(effect);
      expect(s.loop).toBe(true);
      expect(s.classes).toContain(`swiper-${effect}`);

      // 첫 장에서 이전 화살표 → 마지막 장(무한 반복), 다음 화살표 → 첫 장
      await page.locator('section.hs-slider .hs-slider__prev').click();
      await settle(page, count - 1);
      await page.locator('section.hs-slider .hs-slider__next').click();
      await settle(page, 0);
      // 점 → 그 장, aria-current 가 따라간다
      await page.locator('section.hs-slider .hs-slider__pagination button').nth(1).click();
      await settle(page, 1);
      await expect(page.locator('section.hs-slider .hs-slider__pagination button').nth(1)).toHaveAttribute('aria-current', 'true');
    }

    // PC 마우스 끌기 — 오른쪽에서 왼쪽으로 끌면 다음 장
    await saveSettings(request, managerToken, { ...original, autoplay: false, effect: 'slide' });
    await gotoUser(page, '/shop/products');
    await expect(page.locator('section.hs-slider .hs-slider__pagination button').first()).toBeVisible({ timeout: 30_000 });
    expect(await page.evaluate(() => getComputedStyle(document.querySelector('section.hs-slider .swiper-wrapper') as HTMLElement).cursor)).toBe('grab');
    const box = (await page.locator('section.hs-slider').boundingBox())!;
    const y = box.y + box.height * 0.3;
    // 폭의 65% 를 끈다 — Swiper 는 300ms 넘게 끈 「긴 끌기」를 폭의 절반(longSwipesRatio 0.5)을 넘어야 넘기므로, 딱 50% 면
    // 끄는 시간이 300ms 언저리에서 갈려 넘어가기도 되돌아오기도 한다(실측 307~321ms)
    await page.mouse.move(box.x + box.width * 0.8, y);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.5, y, { steps: 8 });
    await page.mouse.move(box.x + box.width * 0.15, y, { steps: 8 });
    await page.mouse.up();
    await settle(page, 1);
  } finally {
    await saveSettings(request, managerToken, original);
  }
});

/**
 * 홈 공개 응답의 첫 배너를 복제해 세 장으로 만들고 자동 넘김(3초)을 켠다 — 운영 배너·설정은 건드리지 않는다.
 *
 * @param page 페이지
 */
async function injectThreeSlides(page: Page): Promise<void> {
  await page.route('**/api/plugins/sirsoft-hero_section/hero?position=home_hero*', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    const first = body.data.banners[0];
    if (!first) {
      // 홈에 배너가 없으면 복제할 수 없다 — 응답을 그대로 넘겨 장 수 단언에서 드러나게 한다
      await route.fulfill({ response });
      return;
    }
    body.data.banners = [0, 1, 2].map((i) => ({ ...first, id: 990100 + i, title: `E2E 자동 넘김 ${i}`, media_type: 'image' }));
    body.data.settings = { ...body.data.settings, autoplay: true, interval_seconds: 3, effect: 'slide' };
    await route.fulfill({ response, json: body });
  });
}

/** 자동 넘김이 돌고 있는지(Swiper autoplay.running) */
const autoplayRunning = (page: Page) => page.evaluate(() => (document.querySelector('section.hs-slider .swiper') as HTMLElement & { swiper: any }).swiper.autoplay.running);

// @scenario surface=pointer_click_does_not_hold_autoplay
test('마우스로 화살표를 누르고 나가면 자동 넘김이 다시 돌고, 단추 표시도 「재생 중」 그대로다(데스크톱)', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await injectThreeSlides(page);
  await gotoUser(page, '/');
  await expect(page.locator('section.hs-slider .hs-slider__pagination button')).toHaveCount(3, { timeout: 30_000 });

  await page.locator('section.hs-slider .hs-slider__next').click();
  await settle(page, 1);
  await page.mouse.move(0, 0);
  await expect.poll(() => autoplayRunning(page), { timeout: 5_000 }).toBe(true);
  await expect(page.locator('section.hs-slider [data-hs-control="toggle"]')).toHaveAttribute('aria-pressed', 'false');
  await expect.poll(() => state(page).then((s) => s.realIndex), { timeout: 8_000 }).toBe(2);
});

test.describe('휴대폰 점 탭 뒤 자동 넘김', () => {
  test.use({ hasTouch: true, viewport: VIEWPORTS.mobile });

  // @scenario surface=pointer_click_does_not_hold_autoplay
  test('휴대폰에서 점을 탭해 옮긴 뒤에도 자동 넘김이 계속 돈다', async ({ page }) => {
    await injectThreeSlides(page);
    await gotoUser(page, '/');
    await expect(page.locator('section.hs-slider .hs-slider__pagination button')).toHaveCount(3, { timeout: 30_000 });

    await page.locator('section.hs-slider .hs-slider__pagination button').nth(1).tap();
    await settle(page, 1);
    await expect.poll(() => autoplayRunning(page), { timeout: 5_000 }).toBe(true);
    await expect.poll(() => state(page).then((s) => s.realIndex), { timeout: 8_000 }).toBe(2);
  });
});

// @scenario surface=swiper_pagination_and_arrows
test('휴대폰에서는 화살표를 감추고(문구를 가리지 않게) 점 표시로 장을 옮긴다', async ({ page, request, managerToken, created }) => {
  const original = await readSettings(request, managerToken);
  for (let i = 0; i < 2; i++) {
    created.push(await createBanner(request, managerToken, { position: 'shop_hero', title: { ko: `E2E Swiper 휴대폰 ${i} ${Date.now()}` } }));
  }
  try {
    await saveSettings(request, managerToken, { ...original, autoplay: false, effect: 'slide' });
    await page.setViewportSize(VIEWPORTS.mobile);
    await gotoUser(page, '/shop/products');
    await expect(page.locator('section.hs-slider .hs-slider__pagination button').first()).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('section.hs-slider .hs-slider__prev')).toBeHidden();
    await expect(page.locator('section.hs-slider .hs-slider__next')).toBeHidden();
    await page.locator('section.hs-slider .hs-slider__pagination button').nth(1).click();
    await settle(page, 1);
  } finally {
    await saveSettings(request, managerToken, original);
  }
});
