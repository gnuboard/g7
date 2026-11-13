/**
 * #810 후속 — 사용자 슬라이더 접근성: 점 표시는 10px 로 보이되 누를 자리는 24px 이고 이웃 점과 겹치지 않는다(WCAG 2.5.8).
 * 화면 읽기 프로그램은 배너 제목을 한 번만 읽고(전체 링크면 링크 이름만, 제목과 같은 대체 문구의 이미지는 장식), 장 번호는
 * 「3개 중 1번째」처럼 사이트 언어로 슬라이드 이름에만 있으며 방문자가 넘긴 때만 잠깐 알린다.
 * @scenario-manifest tests/scenarios/hero-slider.yaml
 *
 * 공개 API 응답은 `page.route` 로 시험 배너 3장으로 바꾼다(운영 배너·설정은 건드리지 않는다). 이미지는 홈 첫 배너의 것을 빌린다.
 */
import { test, expect, createBanner, gotoUser, hidePopups, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page, Route } from '@playwright/test';

test.setTimeout(90_000);

// 운영 배너가 비노출이어도 돌도록 시험 배너를 직접 둔다(끝나면 지운다)
test.beforeEach(async ({ page, request, managerToken, created }) => {
  await hidePopups(page);
  created.push(await createBanner(request, managerToken));
});

const HERO_API = '**/api/plugins/sirsoft-hero_section/hero?position=home_hero*';
const TITLES = ['E2E 접근성 하나', 'E2E 접근성 둘', 'E2E 접근성 셋'];

/**
 * 홈 배너 응답을 시험 배너 3장(버튼 없는 전체 링크, 대체 문구 = 제목 — 대체 문구를 비우면 공개 응답이 제목으로 채운다)으로 바꾼다.
 *
 * @param page 페이지
 * @param autoplay 자동 넘김
 */
async function injectBanners(page: Page, autoplay = false): Promise<void> {
  await page.route(HERO_API, async (route: Route) => {
    const response = await route.fetch();
    const body = await response.json();
    const images = (body.data.banners as Array<Record<string, any>>)[0]?.images ?? { pc: null, mobile: null };
    body.data.banners = TITLES.map((title, i) => ({
      id: 990101 + i, title, description: `${title} 설명`, button_text: '', image_alt: title, title_visible: true, description_visible: true,
      link_url: '/shop/products', link_new_window: false, images, media_type: 'image', videos: null, presentation: null,
    }));
    body.data.settings = { ...body.data.settings, autoplay, effect: 'slide' };
    await route.fulfill({ response, json: body });
  });
}

for (const viewport of ['desktop', 'mobile'] as const) {
  test.describe(viewport, () => {
    test.use({ hasTouch: viewport === 'mobile' });

    // @scenario surface=pagination_bullet_target_24px
    // @effects pagination_bullets_have_24px_touch_target
    test(`점 표시는 10px 로 보이고 누를 자리는 24px 이며 이웃 점과 겹치지 않는다 (${viewport})`, async ({ page }) => {
      await page.setViewportSize(VIEWPORTS[viewport]);
      await injectBanners(page);
      await gotoUser(page, '/');
      const bullets = page.locator('section.hs-slider .hs-slider__pagination button.hs-slider__bullet');
      await expect(bullets).toHaveCount(3, { timeout: 60_000 });

      const boxes = await bullets.evaluateAll((els) => els.map((el) => {
        const dot = el.getBoundingClientRect();
        const before = getComputedStyle(el, '::before');
        // 누를 자리 = 점 상자 + 보이지 않는 테두리 층(inset 음수)
        const grow = -parseFloat(before.top);
        // 점 가운데에서 ±11px(24px 정사각형 안쪽 끝) 지점을 눌렀을 때 그 점이 받는지
        const cx = dot.left + dot.width / 2;
        const cy = dot.top + dot.height / 2;
        const hits = [[cx - 11, cy], [cx + 11, cy], [cx, cy - 11], [cx, cy + 11]]
          .map(([x, y]) => document.elementFromPoint(x, y) === el);
        return { width: dot.width, height: dot.height, target: dot.width + grow * 2, cx, hits };
      }));

      for (const box of boxes) {
        expect(box.width).toBe(10);
        expect(box.height).toBe(10);
        expect(box.target).toBe(24);
        expect(box.hits).toEqual([true, true, true, true]);
      }
      // 가운데끼리 24px 이상 — 누를 자리가 이웃과 겹치지 않는다
      expect(boxes[1].cx - boxes[0].cx).toBeGreaterThanOrEqual(24);
      expect(boxes[2].cx - boxes[1].cx).toBeGreaterThanOrEqual(24);

      // 가장자리 지점을 실제로 눌러도 그 장으로 간다
      const last = boxes[2];
      const dotTop = await bullets.nth(2).evaluate((el) => el.getBoundingClientRect().top + 5);
      if (viewport === 'mobile') await page.touchscreen.tap(last.cx + 11, dotTop);
      else await page.mouse.click(last.cx + 11, dotTop);
      await expect(page.locator('section.hs-slider .hs-slider__slide--active')).toHaveAttribute('data-index', '2');
    });
  });
}

// @scenario surface=title_read_once
// @scenario surface=counter_announced_only_on_visitor_change
// @effects accessible_carousel_semantics
test('화면 읽기 프로그램은 제목을 한 번만 읽고, 장 번호는 슬라이드 이름에 있으며 넘긴 때만 잠깐 알린다 (desktop)', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await injectBanners(page);
  await gotoUser(page, '/');
  const hs = page.locator('section.hs-slider[role="region"]');
  const active = hs.locator('.hs-slider__slide--active');
  await expect(active).toHaveAttribute('data-index', '0', { timeout: 30_000 });

  await expect(hs).toHaveAttribute('aria-roledescription', '캐러셀');
  await expect(active).toHaveAttribute('aria-roledescription', '슬라이드');
  await expect(active).toHaveAttribute('aria-label', '3개 중 1번째');

  // 접근성 트리에서 제목은 링크 이름 한 번 — 이미지·제목 줄로 다시 읽히지 않는다. 설명은 그대로 읽힌다
  const tree = await active.ariaSnapshot();
  expect(tree.split(TITLES[0]).length - 1, tree).toBe(2); // 「E2E 접근성 하나」 + 「E2E 접근성 하나 설명」
  await expect(active.getByRole('link', { name: TITLES[0], exact: true })).toHaveCount(1);
  await expect(active.getByRole('heading')).toHaveCount(0);
  await expect(active.getByRole('img')).toHaveCount(0);
  await expect(active.getByText(`${TITLES[0]} 설명`)).toBeVisible();
  // 제목 글자는 화면에 그대로 보인다
  await expect(active.locator('.hs-slider__title')).toBeVisible();

  // 알림 칸은 처음엔 비어 있다(훑어 읽기에서 장 번호를 두 번 듣지 않게)
  const counter = hs.locator('.hs-slider__counter');
  await expect(counter).toHaveText('');
  await hs.locator('.hs-slider__next').click();
  await expect(active).toHaveAttribute('data-index', '1');
  await expect(counter).toHaveText('3개 중 2번째');
  await expect(counter).toHaveText('', { timeout: 6_000 });
});

// @scenario surface=swiper_styles_vendor_asset
// @effects autoplay_follows_interval_and_visitor
test('배너가 Swiper CSS 보다 먼저 와도 누른 뒤 키보드로 들어오면 자동 넘김이 멈춘다 (desktop)', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await injectBanners(page, true);
  // 슬라이더가 스켈레톤으로 시작해 나중에 그려지는 순서를 고정한다 — 키보드 감지가 이 순서에서 빠졌었다
  await page.route('**/dist/vendor/swiper/**/*.css', async (route: Route) => {
    await new Promise((r) => setTimeout(r, 3_000));
    await route.continue();
  });
  await gotoUser(page, '/');
  const hs = page.locator('section.hs-slider[role="region"]');
  const counter = hs.locator('.hs-slider__counter');
  await expect(counter).toHaveAttribute('aria-live', 'off', { timeout: 40_000 });

  const bullets = hs.locator('.hs-slider__pagination button.hs-slider__bullet');
  await bullets.nth(1).click();
  await page.mouse.move(0, 0);
  // 마우스로 눌러 들어온 포커스는 자동 넘김을 붙잡지 않는다
  await expect(counter).toHaveAttribute('aria-live', 'off');

  await page.keyboard.press('Tab');
  await expect.poll(() => page.evaluate(() => !!document.activeElement?.closest('section.hs-slider'))).toBe(true);
  await expect(counter).toHaveAttribute('aria-live', 'polite');
});

// @scenario surface=carousel_labels_follow_locale
// @effects carousel_labels_follow_site_locale
test('사이트 언어를 영어로 바꾸면 캐러셀·슬라이드 역할과 장 번호도 영어로 읽는다 (desktop)', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await injectBanners(page);
  await gotoUser(page, '/');
  const hs = page.locator('section.hs-slider[role="region"]');
  await expect(hs.locator('.hs-slider__slide--active')).toHaveAttribute('aria-label', '3개 중 1번째', { timeout: 30_000 });

  await page.evaluate(() => (window as any).G7Core.locale.change('en'));
  await expect(hs).toHaveAttribute('aria-roledescription', 'carousel', { timeout: 20_000 });
  const active = hs.locator('.hs-slider__slide--active');
  await expect(active).toHaveAttribute('aria-roledescription', 'slide');
  await expect(active).toHaveAttribute('aria-label', '1 of 3');
});
