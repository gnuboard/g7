/**
 * #810 후속 — 사이트 화면을 새로 열 때 히어로 배너가 늦게 끼어들며 본문을 밀지 않도록, 공개 API 응답 전에는 같은 비율·폭의
 * 스켈레톤이 자리를 잡고, 응답이 오면 실제 슬라이더로 바뀌는지(본문 위치 그대로). 스켈레톤 폭은 첫 로드 때 받는 플러그인 설정
 * 힌트(`slot_widths`)로 고르므로 브라우저 저장소가 비어 있어도(개인정보 동의 전 정리) 맞아야 한다.
 * @scenario-manifest tests/scenarios/hero-slider.yaml
 *
 * 공개 API 응답은 `page.route` 로 늦추기만 한다. 위치 설정을 바꾼 시험은 끝나면 되돌린다.
 */
import { test, expect, gotoUser, auth, API, VIEWPORTS } from '../fixtures/hero-auth';
import type { APIRequestContext, Page, Route } from '@playwright/test';

test.setTimeout(150_000);

const HERO_API = '**/api/plugins/sirsoft-hero_section/hero?position=*';

type Settings = { autoplay: boolean; interval_seconds: number; effect: string; width?: string };

/** 공개 API 응답을 늦춘다(본래 응답 그대로). */
async function delayHero(page: Page, ms: number): Promise<void> {
  await page.route(HERO_API, async (route: Route) => {
    const response = await route.fetch();
    await new Promise((r) => setTimeout(r, ms));
    await route.fulfill({ response });
  });
}

/** 저장소의 직전 모양을 지우고 응답을 늦춰 다시 연다 — 힌트만으로 스켈레톤 폭이 정해지는지 본다. */
async function reloadSlow(page: Page, path: string): Promise<void> {
  await gotoUser(page, path);
  await page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('sirsoft-hero_section:')).forEach((k) => localStorage.removeItem(k)));
  await delayHero(page, 2_500);
  await page.reload();
  await expect(page.locator('section.hs.hs--skeleton')).toBeVisible({ timeout: 10_000 });
}

/** 슬라이더 자리·본문 시작 위치를 잰다. */
const measure = (page: Page) => page.evaluate(() => {
  const hs = document.querySelector('section.hs') as HTMLElement;
  const r = hs.getBoundingClientRect();
  const main = document.getElementById('main_content') as HTMLElement;
  return {
    skeleton: hs.classList.contains('hs--skeleton'),
    hidden: hs.getAttribute('aria-hidden'),
    full: hs.classList.contains('hs--full'),
    left: Math.round(r.left),
    width: Math.round(r.width),
    height: Math.round(r.height * 10) / 10,
    mainTop: Math.round(main.getBoundingClientRect().top),
    pageWidth: document.documentElement.clientWidth,
  };
});

async function readSettings(request: APIRequestContext, token: string): Promise<Settings> {
  const response = await request.get(`${API}/admin/position-settings`, { headers: auth(token) });
  return ((await response.json()).data.positions as Array<{ key: string; settings: Settings }>).find((p) => p.key === 'shop_hero')!.settings;
}

async function saveSettings(request: APIRequestContext, token: string, settings: Settings): Promise<void> {
  const response = await request.put(`${API}/admin/position-settings/shop_hero`, { headers: auth(token), data: settings });
  expect(response.status(), await response.text()).toBe(200);
}

for (const viewport of ['desktop', 'mobile'] as const) {
  // @scenario surface=loading_skeleton_until_response
  test(`응답 전에는 스켈레톤이 자리를 잡고, 배너가 와도 본문이 밀리지 않는다 (${viewport})`, async ({ page }) => {
    await page.setViewportSize(VIEWPORTS[viewport]);
    await reloadSlow(page, '/');
    const before = await measure(page);
    expect(before.hidden).toBe('true');
    expect(before.height).toBeGreaterThan(0);

    await expect(page.locator('section.hs:not(.hs--skeleton) [data-banner-id]').first()).toBeAttached({ timeout: 15_000 });
    await expect(page.locator('section.hs.hs--skeleton')).toHaveCount(0);
    const after = await measure(page);
    // 같은 자리·같은 크기 — 본문 시작 위치가 그대로다(저장소가 비어 있어도 첫 로드 힌트로 폭이 맞는다)
    expect(after.full).toBe(before.full);
    expect(after.left).toBe(before.left);
    expect(after.width).toBe(before.width);
    expect(Math.abs(after.height - before.height)).toBeLessThan(1);
    expect(after.mainTop).toBe(before.mainTop);
    // 실제 배너는 서서히 나타난다
    expect(await page.evaluate(() => getComputedStyle(document.querySelector('section.hs') as HTMLElement).animationName)).toBe('hs-appear');
  });
}

// @scenario surface=loading_skeleton_remembers_slot
test('받기에 실패한 방문은 자리를 비우되 「배너 없음」으로 기억하지 않아, 다음 방문에도 스켈레톤이 자리를 잡는다', async ({ page }) => {
  const memory = () => page.evaluate(() => JSON.parse(localStorage.getItem('sirsoft-hero_section:slot:home_hero') ?? 'null'));
  await page.setViewportSize(VIEWPORTS.desktop);
  await gotoUser(page, '/');
  await expect(page.locator('section.hs:not(.hs--skeleton) [data-banner-id]').first()).toBeAttached({ timeout: 30_000 });
  const remembered = await memory();
  expect(remembered.banners).toBeGreaterThan(0);

  // 공개 API 가 늦게 실패한다 — 받는 동안은 스켈레톤, 실패하면 자리를 비운다(화면 오류 없음)
  await page.route(HERO_API, async (route: Route) => {
    await new Promise((r) => setTimeout(r, 1_500));
    await route.fulfill({ status: 500, contentType: 'application/json', body: '{"success":false}' });
  });
  await page.reload();
  await expect(page.locator('section.hs.hs--skeleton')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('section.hs')).toHaveCount(0, { timeout: 15_000 });
  // 개인정보 동의 전에는 사이트가 새로 열 때 저장소를 비우기도 한다(그러면 null) — 어느 쪽이든 실패를 「0건」으로 남기면 안 된다
  expect((await memory())?.banners ?? null).not.toBe(0);

  // 다시 정상 응답(늦게) — 직전 실패와 관계없이 스켈레톤이 먼저 자리를 잡는다
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await delayHero(page, 2_500);
  await page.reload();
  await expect(page.locator('section.hs.hs--skeleton')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('section.hs:not(.hs--skeleton) [data-banner-id]').first()).toBeAttached({ timeout: 15_000 });
  await page.unrouteAll({ behavior: 'ignoreErrors' });
});

// @scenario surface=loading_skeleton_remembers_slot
test('표시 폭을 바꿔 저장하면 다음 새로고침의 스켈레톤도 그 폭(화면 전체 ↔ 본문 폭)이다', async ({ page, request, managerToken }) => {
  const original = await readSettings(request, managerToken);
  await page.setViewportSize(VIEWPORTS.desktop);
  try {
    await saveSettings(request, managerToken, { ...original, width: 'full' });
    await reloadSlow(page, '/shop/products');
    const full = await measure(page);
    expect(full.full).toBe(true);
    expect(full.left).toBe(0);
    expect(full.width).toBe(full.pageWidth);
    // 늦춘 응답이 끝난 뒤(실제 배너가 그려진 뒤) 다음 이동을 한다
    await expect(page.locator('section.hs:not(.hs--skeleton) [data-banner-id]').first()).toBeAttached({ timeout: 15_000 });
    await page.unroute(HERO_API);

    await saveSettings(request, managerToken, { ...original, width: 'content' });
    await reloadSlow(page, '/shop/products');
    const content = await measure(page);
    expect(content.full).toBe(false);
    expect(content.left).toBeGreaterThan(0);
    expect(content.width).toBeLessThan(content.pageWidth);
    await expect(page.locator('section.hs:not(.hs--skeleton) [data-banner-id]').first()).toBeAttached({ timeout: 15_000 });
  } finally {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await saveSettings(request, managerToken, original);
  }
});
