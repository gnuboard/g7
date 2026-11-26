/**
 * 위치 설정의 기기별 최대 높이: 사이트 슬라이더는 비율대로 그리다가 그 기기의 최대 높이에서 멈추고(휴대폰은 767px 이하),
 * 넘치는 이미지는 초점 기준으로 위아래를 자른다. 비운 기기는 비율대로다. 관리 화면에서 고치면 저장 전에 미리보기가 따라가고,
 * 범위 밖이면 안내와 함께 저장이 잠기며, 저장 본문에 두 값이 실린다.
 * @scenario-manifest tests/scenarios/max-height.yaml
 *
 * 사용자 화면은 공개 API 응답을 `page.route` 로 시험 설정으로 바꾸고, 관리 화면 저장은 `page.route` 가 받아 응답만 돌려준다
 * (운영 배너·위치 설정은 건드리지 않는다).
 */
import { test, expect, createBanner, gotoUser, hidePopups, authenticatePage, ADMIN_LIST, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page, Route } from '@playwright/test';

test.setTimeout(240_000);

// 팝업이 슬라이더를 가리지 않게 비우고, 운영 배너가 비노출이어도 돌도록 시험 배너를 둔다(끝나면 지운다)
test.beforeEach(async ({ page, request, managerToken, created }) => {
  await hidePopups(page);
  created.push(await createBanner(request, managerToken));
});

const HERO_API = '**/api/plugins/sirsoft-hero_section/hero?position=home_hero*';

/**
 * 홈 배너 응답의 위치 설정을 덮어쓴다(배너는 그대로 — 시험 배너가 하나 이상 있다).
 *
 * @param page 페이지
 * @param settings 덮어쓸 위치 설정
 */
async function injectSettings(page: Page, settings: Record<string, unknown>): Promise<void> {
  await page.route(HERO_API, async (route: Route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.settings = { ...body.data.settings, autoplay: false, width: 'content', max_height_pc: null, max_height_mobile: null, ...settings };
    await route.fulfill({ response, json: body });
  });
}

/** 홈 슬라이더와 그 안 첫 이미지의 크기를 잰다. */
const measure = (page: Page) => page.locator('section.hs-slider[data-position="home_hero"]:not(.hs-slider--skeleton)').first().evaluate((el) => {
  const box = el.getBoundingClientRect();
  const image = el.querySelector('.hs-slider__image') as HTMLElement | null;
  return { width: box.width, height: box.height, imageHeight: image?.getBoundingClientRect().height ?? null, fit: image ? getComputedStyle(image).objectFit : null };
});

for (const viewport of ['desktop', 'mobile'] as const) {
  // @scenario surface=slider_max_height_vars
  // @effects slider_height_capped_by_device_max_height
  test(`사이트 — 그 기기의 최대 높이에서 멈추고 이미지는 잘려 채우며, 다른 기기 값은 쓰지 않는다 (${viewport})`, async ({ page }) => {
    await page.setViewportSize(VIEWPORTS[viewport]);
    // 비운 채 — 비율대로(PC 3:1, 휴대폰 3:4)
    await injectSettings(page, {});
    await gotoUser(page, '/');
    const ratio = viewport === 'desktop' ? 1920 / 640 : 750 / 1000;
    await expect.poll(async () => { const m = await measure(page); return Math.abs(m.width / m.height - ratio) < 0.02; }, { timeout: 60_000 }).toBe(true);
    const natural = await measure(page);

    // 이 기기의 최대 높이를 비율 높이보다 낮게 — 그 높이에서 멈춘다. 다른 기기 값은 더 낮아도 쓰지 않는다
    const cap = Math.floor(natural.height * 0.6);
    await page.unroute(HERO_API);
    await injectSettings(page, viewport === 'desktop' ? { max_height_pc: cap, max_height_mobile: 160 } : { max_height_pc: 160, max_height_mobile: cap });
    await page.reload();
    await expect.poll(async () => Math.round((await measure(page)).height), { timeout: 60_000 }).toBe(cap);
    const capped = await measure(page);
    expect(capped.width).toBeCloseTo(natural.width, 0);
    expect(capped.fit).toBe('cover');
    expect(Math.round(capped.imageHeight ?? 0)).toBe(cap);

    // 비율 높이보다 큰 값이면 비율대로
    await page.unroute(HERO_API);
    await injectSettings(page, viewport === 'desktop' ? { max_height_pc: 2000 } : { max_height_mobile: 2000 });
    await page.reload();
    await expect.poll(async () => Math.round((await measure(page)).height), { timeout: 60_000 }).toBe(Math.round(natural.height));
  });
}

// @scenario surface=admin_max_height_setting
// @effects preview_follows_max_height_before_save
test('관리자 목록 — 최대 높이를 넣으면 저장 전에 미리보기가 그 높이에서 멈추고, 범위 밖이면 저장이 잠기며, 저장 본문에 실린다 (desktop)', async ({ page, managerToken }) => {
  const puts: Array<Record<string, unknown>> = [];
  // 저장은 서버에 보내지 않고 받은 본문만 기록한다(운영 위치 설정 보존)
  await page.route('**/api/plugins/sirsoft-hero_section/admin/position-settings/home_hero', async (route: Route) => {
    if (route.request().method() !== 'PUT') return route.fallback();
    const body = JSON.parse(route.request().postData() ?? '{}');
    puts.push(body);
    await route.fulfill({ status: 200, json: { success: true, message: '슬라이더 설정을 저장했습니다.', data: { position: 'home_hero', settings: body } } });
  });
  const previewHeight = () => page.evaluate(() => {
    const frame = document.querySelector('.hs-preview iframe, #hero_preview iframe') as HTMLIFrameElement | null;
    const section = frame?.contentDocument?.querySelector('section.hs-slider') as HTMLElement | null;
    return section ? Math.round(section.getBoundingClientRect().height) : null;
  });

  await page.setViewportSize(VIEWPORTS.desktop);
  await authenticatePage(page, managerToken);
  await page.goto(`${ADMIN_LIST}?position=home_hero`);
  const pc = page.locator('#hero_slider_max_height_pc');
  await expect(pc).toBeVisible({ timeout: 60_000 });
  // 미리보기는 템플릿 CSS·Swiper CSS 가 실린 뒤에야 비율 높이로 자리 잡는다 — 그때의 높이를 기준으로 쓴다
  await expect.poll(async () => { const h = await previewHeight(); return h !== null && h > 100 && h < 700; }, { timeout: 60_000 }).toBe(true);
  const before = (await previewHeight())!;
  const save = page.locator('#slider_save_button');
  const original = await pc.inputValue();

  // 범위 밖 — 안내가 보이고 저장이 잠긴다
  await pc.fill('100');
  await pc.blur();
  await expect(page.locator('#max_height_invalid')).toBeVisible();
  await expect(save).toBeDisabled();

  // 비율 높이보다 낮은 값 — 저장 전에 미리보기가 그 높이에서 멈춘다
  const cap = Math.max(160, Math.floor(before * 0.6));
  await pc.fill(String(cap));
  await pc.blur();
  await expect(page.locator('#max_height_invalid')).toHaveCount(0);
  await expect.poll(previewHeight).toBe(cap);
  await expect(save).toBeEnabled();

  await save.click();
  await expect.poll(() => puts.length).toBe(1);
  expect(puts[0].max_height_pc).toBe(cap);
  expect(puts[0].max_height_mobile ?? null).toBe(null);
  expect(original === '' ? null : Number(original)).not.toBe(cap);
});
