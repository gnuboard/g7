/**
 * #810 후속 — 문구 등장 효과(떠오르기·글자 하나씩)가 줄을 아래에서 올리는 동안 문구 영역에 세로 스크롤 막대가 생기지 않는다.
 * 넘침은 줄의 배치 높이로 판정해 효과가 밀어낸 만큼은 세지 않고, 세로 스크롤은 실제로 넘칠 때만(`hs-slider__scroll--overflow`) 켠다.
 * 정말 긴 문구는 여전히 스크롤로 끝까지 읽는다.
 * @scenario-manifest tests/scenarios/hero-slider.yaml
 *
 * 공개 API 응답은 `page.route` 로 시험 배너로 바꾼다(운영 배너·설정은 건드리지 않는다). 이미지는 홈 첫 배너의 것을 빌린다.
 */
import { test, expect, gotoUser, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page, Route } from '@playwright/test';

test.setTimeout(90_000);

const HERO_API = '**/api/plugins/sirsoft-hero_section/hero?position=home_hero*';
const LONG = '가을 신상품 기획전 할인 행사 안내 '.repeat(40).trim();

/**
 * 홈 배너 응답을 시험 배너(떠오르기 · 글자 하나씩 · 아주 긴 설명)로 바꾼다.
 *
 * @param page 페이지
 */
async function injectBanners(page: Page): Promise<void> {
  await page.route(HERO_API, async (route: Route) => {
    const response = await route.fetch();
    const body = await response.json();
    const images = (body.data.banners as Array<Record<string, any>>)[0]?.images ?? { pc: null, mobile: null };
    const base = { button_text: '', title_visible: true, description_visible: true, link_url: null, link_new_window: false, images, media_type: 'image', youtube_id: null, videos: null };
    body.data.banners = [
      { ...base, id: 990201, title: 'E2E 떠오르기', description: '아래에서 올라오는 설명', image_alt: '', presentation: { animation: 'rise', animation_speed: 'slow' } },
      { ...base, id: 990202, title: 'E2E 글자 하나씩', description: '한 글자씩 나타나는 설명', image_alt: '', presentation: { animation: 'letters', animation_speed: 'slow' } },
      { ...base, id: 990203, title: 'E2E 긴 문구', description: LONG, image_alt: '', presentation: { animation: 'rise' } },
    ];
    body.data.settings = { ...body.data.settings, autoplay: false, effect: 'slide' };
    await route.fulfill({ response, json: body });
  });
}

/** 활성 장 문구 영역의 상태 */
const scrollState = (page: Page) => page.evaluate(() => {
  const el = document.querySelector('section.hs-slider[data-position="home_hero"] .hs-slider__slide--active .hs-slider__scroll') as HTMLElement;
  return { overflowY: getComputedStyle(el).overflowY, overflowClass: el.classList.contains('hs-slider__scroll--overflow'), tabindex: el.getAttribute('tabindex'), pushed: el.scrollHeight > el.clientHeight + 1 };
});

for (const viewport of ['desktop', 'mobile'] as const) {
  // @scenario surface=motion_does_not_make_text_scroll
  test(`등장 효과가 재생되는 동안에도 문구 영역에 스크롤이 켜지지 않고, 정말 긴 문구만 스크롤된다 (${viewport})`, async ({ page }) => {
    await page.setViewportSize(VIEWPORTS[viewport]);
    await injectBanners(page);
    await gotoUser(page, '/');
    const active = page.locator('section.hs-slider[data-position="home_hero"] .hs-slider__slide--active');
    await expect(active).toHaveAttribute('data-banner-id', '990201', { timeout: 30_000 });

    for (const [index, id] of [[0, '990201'], [1, '990202']] as const) {
      await page.evaluate((n) => (document.querySelector('section.hs-slider[data-position="home_hero"] .swiper') as any).swiper.slideToLoop(n, 0), index);
      await expect(active).toHaveAttribute('data-banner-id', id);
      // 효과(느리게 1초) 재생 중 여러 번 잰다 — 밀린 순간이 있어도 스크롤은 꺼져 있다
      let sawPush = false;
      for (let i = 0; i < 8; i++) {
        const state = await scrollState(page);
        sawPush ||= state.pushed;
        expect(state, `${id} ${i * 150}ms`).toMatchObject({ overflowY: 'hidden', overflowClass: false, tabindex: null });
        await page.waitForTimeout(150);
      }
      if (id === '990201') expect(sawPush, '전제: 떠오르기가 재생 중 줄을 영역 밖으로 밀어낸다').toBe(true);
    }

    await page.evaluate(() => (document.querySelector('section.hs-slider[data-position="home_hero"] .swiper') as any).swiper.slideToLoop(2, 0));
    await expect(active).toHaveAttribute('data-banner-id', '990203');
    await expect.poll(() => scrollState(page)).toMatchObject({ overflowY: 'auto', overflowClass: true, tabindex: '0' });
  });
}
