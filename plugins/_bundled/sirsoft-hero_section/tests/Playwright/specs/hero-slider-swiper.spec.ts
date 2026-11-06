/**
 * E2E: 사이트 홈 히어로 슬라이더의 실제 넘김(Swiper) — 단추·무한 반복·자동 재생·손가락 넘기기 (#810)
 *
 * @scenario-manifest tests/scenarios/e2e-flows.yaml
 * @effects user_screen_reflects_admin_change, test_data_cleaned_up
 *
 * 단위 시험(jsdom)은 Swiper 대역으로 연결 계약만 본다. 여기서는 실브라우저에서 Swiper 가 실제로 장을 넘기는지 본다.
 * 홈에는 다른 배너(운영 데이터·다른 사양)가 함께 있을 수 있으므로 장 수는 화면에서 세고, 단언은 사양이 만든 제목으로 한다.
 * 무한 반복 모드의 Swiper 는 전환(0.5초) 중에 누른 단추를 무시한다(`loopPreventsSliding` 기본값) — 단추를 잇달아 누를 때는
 * 전환이 끝나기를 기다린다(`settle`). 장 번호는 전환이 시작될 때 바뀌므로 번호만 보고 바로 누르면 그 누름이 무시된다.
 */
import type { CDPSession, Page } from '@playwright/test';
import {
  test, expect, createBanner, gotoUser, hidePopups, slider, slideTitles, activeTitle, uniqueTitle, VIEWPORTS,
} from '../fixtures/hero-auth';

// 배너를 만들고 자동 넘김 간격(5초)을 몇 번 기다리므로 기본 30초로는 개발 사이트에서 빠듯하다
test.setTimeout(90_000);

// 사이트 팝업이 슬라이더를 덮어 단추·점 클릭을 가로채지 않게 비운다
test.beforeEach(async ({ page }) => {
  await hidePopups(page);
});

/**
 * 홈을 열고 사양이 만든 제목이 슬라이더에 붙을 때까지 기다린다.
 *
 * @param page 페이지
 * @param titles 기다릴 제목
 * @returns 슬라이더의 전체 장 수
 */
async function openHome(page: Page, titles: string[]): Promise<number> {
  await gotoUser(page, '/');
  await expect.poll(() => slideTitles(page), { timeout: 30_000 }).toEqual(expect.arrayContaining(titles));
  await expect(slider(page)).toBeVisible();

  return (await slideTitles(page)).length;
}

/**
 * Swiper 전환이 끝날 때까지 기다린다(컨테이너 요소의 `swiper` 인스턴스 `animating`).
 *
 * @param page 페이지
 */
async function settle(page: Page): Promise<void> {
  await expect.poll(() => page.locator('section.hs-slider .hs-slider__track').evaluate((el) => (el as any).swiper?.animating === false)).toBe(true);
}

// @scenario flow=slider_autoplay_advances, viewport=desktop
// @effects user_screen_reflects_admin_change, test_data_cleaned_up
test('키보드 포커스가 남아 있으면 마우스 이탈로 자동 재생하지 않는다', async ({ page, request, managerToken, created }) => {
  const a = uniqueTitle('포커스A');
  const b = uniqueTitle('포커스B');
  created.push(await createBanner(request, managerToken, { title: { ko: a } }));
  created.push(await createBanner(request, managerToken, { title: { ko: b } }));
  await page.setViewportSize(VIEWPORTS.desktop);
  await openHome(page, [a, b]);
  await page.locator('section.hs-slider .hs-slider__next').focus();
  await slider(page).hover();
  await page.mouse.move(0, 0);
  await expect.poll(() => page.locator('section.hs-slider .hs-slider__track').evaluate((el) => (el as any).swiper.autoplay.running)).toBe(false);
  const current = await activeTitle(page);
  await page.waitForTimeout(7_100);
  expect(await activeTitle(page)).toBe(current);
});

/**
 * 손가락으로 오른쪽에서 왼쪽으로 쓸어 넘긴다(CDP 터치 — PC 마우스 끌기는 swiper-ui.spec.ts 가 본다).
 *
 * @param page 페이지
 * @param client CDP 세션
 */
async function swipeLeft(page: Page, client: CDPSession): Promise<void> {
  const box = (await slider(page).boundingBox())!;
  const y = Math.round(box.y + box.height * 0.4);
  const from = Math.round(box.x + box.width * 0.85);
  const to = Math.round(box.x + box.width * 0.15);

  await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: from, y }] });
  for (let i = 1; i <= 8; i++) {
    await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: Math.round(from + ((to - from) * i) / 8), y }] });
  }
  await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
}

for (const viewport of ['desktop', 'mobile'] as const) {
  test.describe(`홈 슬라이더 넘김 (${viewport})`, () => {
    // @scenario flow=slider_buttons_loop_both_ways, viewport=desktop
    // @scenario flow=slider_buttons_loop_both_ways, viewport=mobile
    // @effects user_screen_reflects_admin_change, test_data_cleaned_up
    test(`다음 단추로 끝까지 가면 첫 장으로, 첫 장에서 이전 단추면 마지막 장으로 돈다 (${viewport})`, async ({ page, request, managerToken, created }) => {
      const errors: string[] = [];
      // 자원 오류 문구에는 주소가 없다 — 주소를 붙여야 아래 걸러내기가 듣고 실패 메시지로 대상을 안다
      page.on('console', (m) => { if (m.type() === 'error') errors.push(`${m.text()} ${m.location().url}`); });
      const a = uniqueTitle('넘김A');
      const b = uniqueTitle('넘김B');
      created.push(await createBanner(request, managerToken, { title: { ko: a } }));
      created.push(await createBanner(request, managerToken, { title: { ko: b } }));

      await page.setViewportSize(VIEWPORTS[viewport]);
      const count = await openHome(page, [a, b]);
      const order = await slideTitles(page);
      // 자동 재생이 단언 사이에 끼지 않게 멈춘다
      await page.locator('section.hs-slider [data-hs-control="toggle"]').click();
      await settle(page);
      const start = await activeTitle(page);
      const startIndex = order.indexOf(start);

      // 휴대폰은 화살표를 감추므로(문구를 가리지 않게) 다음 장의 점을 누른다 — 마지막 장 다음은 첫 장 점
      const next = (i: number) => viewport === 'desktop'
        ? page.locator('section.hs-slider .hs-slider__next').click()
        : page.locator('section.hs-slider .hs-slider__pagination button').nth((startIndex + i + 1) % count).click();
      const seen: string[] = [];
      for (let i = 0; i < count; i++) {
        await next(i);
        await expect.poll(() => activeTitle(page)).toBe(order[(startIndex + i + 1) % count]);
        await settle(page);
        seen.push(await activeTitle(page));
      }
      // 장 수만큼 넘기면 다시 처음 장 — 끝에서 멈추지 않고 돈다
      expect(seen[seen.length - 1]).toBe(start);
      expect(new Set(seen).size).toBe(count);
      await expect(page.locator('section.hs-slider .hs-slider__slide--active')).toHaveAttribute('aria-label', `${count}개 중 ${startIndex + 1}번째`);

      if (viewport === 'desktop') await page.locator('section.hs-slider .hs-slider__prev').click();
      else await page.locator('section.hs-slider .hs-slider__pagination button').nth((startIndex - 1 + count) % count).click();
      await expect.poll(() => activeTitle(page)).toBe(order[(startIndex - 1 + count) % count]);
      expect(errors.filter((e) => !/favicon|analytics/i.test(e))).toEqual([]);
    });
  });
}

// @scenario flow=slider_autoplay_advances, viewport=desktop
// @effects user_screen_reflects_admin_change, test_data_cleaned_up
test('자동 재생은 손대지 않으면 다음 장으로 넘어가고, 마우스를 올리면 멈춘다 (desktop)', async ({ page, request, managerToken, created }) => {
  const a = uniqueTitle('자동A');
  const b = uniqueTitle('자동B');
  created.push(await createBanner(request, managerToken, { title: { ko: a } }));
  created.push(await createBanner(request, managerToken, { title: { ko: b } }));

  await page.setViewportSize(VIEWPORTS.desktop);
  await page.mouse.move(0, 0);
  const count = await openHome(page, [a, b]);
  expect(count).toBeGreaterThan(1);
  const first = await activeTitle(page);
  // 위치 기본 간격은 5초 — 여유를 두고 기다린다
  await expect.poll(() => activeTitle(page), { timeout: 12_000, intervals: [500] }).not.toBe(first);

  await slider(page).hover();
  const held = await activeTitle(page);
  await page.waitForTimeout(7_000);
  expect(await activeTitle(page)).toBe(held);
});

test.describe('홈 슬라이더 손가락 넘기기 (mobile)', () => {
  test.use({ hasTouch: true, viewport: VIEWPORTS.mobile });

  // @scenario flow=slider_touch_swipe, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('휴대폰에서 손가락으로 쓸면 다음 장으로 넘어간다 (mobile)', async ({ page, request, managerToken, created }) => {
    const a = uniqueTitle('쓸기A');
    const b = uniqueTitle('쓸기B');
    created.push(await createBanner(request, managerToken, { title: { ko: a } }));
    created.push(await createBanner(request, managerToken, { title: { ko: b } }));

    const count = await openHome(page, [a, b]);
    const order = await slideTitles(page);
    await page.locator('section.hs-slider [data-hs-control="toggle"]').tap();
    await settle(page);
    const start = await activeTitle(page);

    const client = await page.context().newCDPSession(page);
    await swipeLeft(page, client);
    await expect.poll(() => activeTitle(page)).toBe(order[(order.indexOf(start) + 1) % count]);
  });
});
