/**
 * #810 후속 — 사용자 히어로 슬라이더의 Swiper 화면 요소: 기본 모양 좌우 화살표(데스크톱만)·점 표시·PC 마우스 끌기와
 * 추가 전환 효과(cube·flip·coverflow·creative)가 실브라우저 Swiper 에서 무한 반복과 함께 넘어가는지.
 * @scenario-manifest tests/scenarios/hero-slider.yaml
 *
 * 위치 설정은 사양이 바꾸고 끝나면 되돌린다. 배너는 사양이 만들고 fixture 가 지운다(운영 배너는 건드리지 않는다).
 */
import { test, expect, createBanner, gotoUser, auth, API, VIEWPORTS } from '../fixtures/hero-auth';
import type { APIRequestContext, Page } from '@playwright/test';

test.setTimeout(240_000);

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
  const el = document.querySelector('section.hs .swiper') as HTMLElement & { swiper: any };
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
      await expect(page.locator('section.hs .hs__pagination button').first()).toBeVisible({ timeout: 30_000 });
      const count = await page.locator('section.hs .hs__pagination button').count();
      const s = await state(page);
      expect(s.effect, effect).toBe(effect);
      expect(s.loop).toBe(true);
      expect(s.classes).toContain(`swiper-${effect}`);

      // 첫 장에서 이전 화살표 → 마지막 장(무한 반복), 다음 화살표 → 첫 장
      await page.locator('section.hs .hs__prev').click();
      await settle(page, count - 1);
      await page.locator('section.hs .hs__next').click();
      await settle(page, 0);
      // 점 → 그 장, aria-current 가 따라간다
      await page.locator('section.hs .hs__pagination button').nth(1).click();
      await settle(page, 1);
      await expect(page.locator('section.hs .hs__pagination button').nth(1)).toHaveAttribute('aria-current', 'true');
    }

    // PC 마우스 끌기 — 오른쪽에서 왼쪽으로 끌면 다음 장
    await saveSettings(request, managerToken, { ...original, autoplay: false, effect: 'slide' });
    await gotoUser(page, '/shop/products');
    await expect(page.locator('section.hs .hs__pagination button').first()).toBeVisible({ timeout: 30_000 });
    expect(await page.evaluate(() => getComputedStyle(document.querySelector('section.hs .swiper-wrapper') as HTMLElement).cursor)).toBe('grab');
    const box = (await page.locator('section.hs').boundingBox())!;
    const y = box.y + box.height * 0.3;
    await page.mouse.move(box.x + box.width * 0.7, y);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.45, y, { steps: 8 });
    await page.mouse.move(box.x + box.width * 0.2, y, { steps: 8 });
    await page.mouse.up();
    await settle(page, 1);
  } finally {
    await saveSettings(request, managerToken, original);
  }
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
    await expect(page.locator('section.hs .hs__pagination button').first()).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('section.hs .hs__prev')).toBeHidden();
    await expect(page.locator('section.hs .hs__next')).toBeHidden();
    await page.locator('section.hs .hs__pagination button').nth(1).click();
    await settle(page, 1);
  } finally {
    await saveSettings(request, managerToken, original);
  }
});
