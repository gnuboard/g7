/**
 * #810 후속 — 문구 자리·머리글·등장 효과와 메뉴 겹침.
 * @scenario-manifest tests/scenarios/text-layout-overlay.yaml
 *
 * 사이트 화면: 공개 API 응답을 `page.route` 로 바꿔(운영 배너·위치 설정은 건드리지 않는다) 메뉴 겹치기·문구 자리·효과를 켜고,
 * sirsoft-basic 헤더가 투명해져 배너 위에 올라가는지, 문구가 정한 자리(PC·휴대폰 따로)에 놓이는지, 줄마다 효과가 0.2초씩 늦게
 * 재생되는지, 화면을 내리면 메뉴가 원래 배경으로 돌아오는지 잰다. 관리자 화면: 비활성 시험 배너를 만들어 9칸·숫자·미리보기 끌기·
 * 화살표 키로 자리를 바꾸고 저장해 서버 값과 새로 연 화면 값을 확인한다. 위치 설정의 메뉴 겹치기 저장은 끝나면 되돌린다.
 */
import { test, expect, gotoUser, authenticatePage, createBanner, auth, uniqueTitle, ADMIN_LIST, API, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page, Route } from '@playwright/test';

test.setTimeout(180_000);

const HERO_API = '**/api/plugins/sirsoft-hero_section/hero?position=home_hero*';

/** 홈 공개 응답의 앞 두 장에 문구 설정을 입히고 슬라이더 설정을 바꾼다 */
async function decorate(page: Page, settings: Record<string, unknown>, first: Record<string, unknown>, second: Record<string, unknown> = {}): Promise<void> {
  await page.route(HERO_API, async (route: Route) => {
    const response = await route.fetch();
    const body = await response.json();
    const banners = body.data.banners as Array<Record<string, any>>;
    const make = (index: number, extra: Record<string, unknown>, title: string) => {
      const base = banners[index] ?? banners[0];
      return { ...base, id: 990100 + index, title, description: 'E2E 설명 문구', eyebrow: 'E2E 머리글', eyebrow_visible: true, eyebrow_color: '#FDE68A', presentation: { ...base.presentation, ...extra } };
    };
    body.data.banners = [make(0, first, 'E2E 첫 장'), make(1, second, 'E2E 둘째 장')];
    body.data.settings = { ...body.data.settings, ...settings };
    await route.fulfill({ response, json: body });
  });
}

/** 사이트 화면의 겹침·자리 측정값 */
async function measure(page: Page) {
  return page.evaluate(() => {
    const root = document.documentElement;
    const header = [...document.querySelectorAll<HTMLElement>('.g7-site-header')].find((e) => e.getBoundingClientRect().height > 0)!;
    const slide = document.querySelector<HTMLElement>('.hs__slide--active')!;
    const area = slide.querySelector<HTMLElement>('.hs__textbox')!.parentElement!;
    const box = slide.querySelector<HTMLElement>('.hs__textbox')!.getBoundingClientRect();
    const layer = slide.querySelector<HTMLElement>('.hs__text')!.getBoundingClientRect();
    const s = slide.getBoundingClientRect();
    const a = area.getBoundingClientRect();
    const pad = getComputedStyle(area);
    const inner = { left: a.left + parseFloat(pad.paddingLeft), right: a.right - parseFloat(pad.paddingRight) };
    const navLink = header.querySelector('nav a:not([aria-current])') ?? header.querySelector('button');
    return {
      overlay: root.getAttribute('data-header-overlay'),
      active: root.getAttribute('data-header-overlay-active'),
      headerHeight: Math.round(header.getBoundingClientRect().height),
      headerVar: root.style.getPropertyValue('--g7-header-height'),
      headerBg: getComputedStyle(header).backgroundColor,
      ink: navLink ? getComputedStyle(navLink).color : null,
      heroTop: Math.round(document.querySelector('section.hs')!.getBoundingClientRect().top),
      slideTop: s.top, slideBottom: s.bottom,
      layerTop: layer.top, layerBottom: layer.bottom,
      boxLeft: box.left, boxRight: box.right, boxWidth: box.width, innerLeft: inner.left, innerRight: inner.right, innerWidth: inner.right - inner.left,
      lines: [...slide.querySelectorAll<HTMLElement>('.hs__line')].map((l) => ({ cls: l.className, name: getComputedStyle(l).animationName, delay: getComputedStyle(l).animationDelay })),
      eyebrow: slide.querySelector('.hs__eyebrow')?.textContent ?? null,
    };
  });
}

// @scenario surface=site_overlay_pc_mobile
// @effects header_overlays_banner, text_placed_by_device, lines_staggered, scroll_restores_header
test('메뉴 겹치기 — 헤더가 배너 위에 투명하게 올라가고 문구는 정한 자리, 줄마다 0.2초 늦게 떠오르며 내리면 메뉴가 돌아온다', async ({ page }) => {
  await decorate(page, { width: 'full', header_overlay: true, autoplay: false },
    { text_pc_x: 50, text_pc_y: 50, text_mobile_x: 100, text_mobile_y: 0, text_width: 60, animation: 'rise', animation_speed: 'normal', animation_delay: 0.2, tone: 'dark' },
    { text_pc_x: 100, text_pc_y: 0, text_width: 50, animation: 'letters', tone: 'light' });

  // PC
  await page.setViewportSize(VIEWPORTS.desktop);
  await gotoUser(page, '/');
  await expect(page.locator('.hs__slide--active .hs__eyebrow')).toHaveText('E2E 머리글', { timeout: 30_000 });
  const pc = await measure(page);
  expect(pc.overlay).toBe('dark');
  expect(pc.active).toBe('dark');
  expect(pc.headerVar).toBe(`${pc.headerHeight}px`);
  expect(pc.heroTop).toBe(0);
  expect(pc.headerBg).toBe('rgba(0, 0, 0, 0)');
  expect(pc.ink).toBe('rgb(255, 255, 255)');
  // 머리글·제목·설명(+ 운영 첫 배너에 버튼이 있으면 버튼) — 시작 지연 0.2초 + 줄마다 0.2초
  expect(pc.lines.length).toBeGreaterThanOrEqual(3);
  expect(pc.lines.map((l) => [l.name, l.delay])).toEqual(pc.lines.map((_, i) => ['hs-text-rise', `${Math.round((0.2 + i * 0.2) * 10) / 10}s`]));
  // 문구 상자: 폭 60%, 가로 가운데
  expect(Math.abs(pc.boxWidth - pc.innerWidth * 0.6)).toBeLessThan(1.5);
  expect(Math.abs((pc.boxLeft - pc.innerLeft) - (pc.innerRight - pc.boxRight))).toBeLessThan(1.5);
  // 문구 띠: 세로 가운데, 메뉴 높이만큼 위 여백을 둔다
  expect(Math.abs((pc.layerTop - pc.slideTop) - (pc.slideBottom - pc.layerBottom))).toBeLessThan(1.5);

  // 내리면 원래 배경, 다시 올리면 투명
  await page.evaluate(() => window.scrollTo(0, 400));
  await expect.poll(() => page.evaluate(() => document.documentElement.getAttribute('data-header-overlay-active'))).toBeNull();
  const scrolled = await measure(page);
  expect(scrolled.headerBg).not.toBe('rgba(0, 0, 0, 0)');
  await page.evaluate(() => window.scrollTo(0, 0));
  await expect.poll(() => page.evaluate(() => document.documentElement.getAttribute('data-header-overlay-active'))).toBe('dark');

  // 둘째 장: 바탕 밝음 → 메뉴 글자 검정, 오른쪽 위, 글자 하나씩(원문은 화면 읽기용으로 한 번)
  await page.locator('.hs__pagination .swiper-pagination-bullet').nth(1).click();
  await expect.poll(() => page.evaluate(() => document.documentElement.getAttribute('data-header-overlay'))).toBe('light');
  await page.waitForTimeout(1_500);
  const second = await measure(page);
  expect(second.ink).toBe('rgb(17, 24, 39)');
  expect(Math.abs(second.boxRight - second.innerRight)).toBeLessThan(1.5);
  expect(Math.round(second.layerTop - second.slideTop)).toBe(0);
  expect(await page.locator('.hs__slide--active .hs__title .hs__sr').textContent()).toBe('E2E 둘째 장');
  expect(await page.locator('.hs__slide--active .hs__title .hs__letter').count()).toBe([...'E2E둘째장'].length);
  expect(second.lines.find((l) => l.cls.includes('hs__description'))?.name).toBe('hs-text-fade');

  // 휴대폰 — 휴대폰 자리(오른쪽 위), 툴바 높이
  await page.setViewportSize(VIEWPORTS.mobile);
  await gotoUser(page, '/');
  await expect(page.locator('.hs__slide--active .hs__eyebrow')).toHaveText('E2E 머리글', { timeout: 30_000 });
  await page.waitForTimeout(1_500);
  const mobile = await measure(page);
  expect(mobile.active).toBe('dark');
  expect(mobile.headerVar).toBe(`${mobile.headerHeight}px`);
  expect(mobile.headerBg).toBe('rgba(0, 0, 0, 0)');
  expect(mobile.heroTop).toBe(0);
  expect(Math.abs(mobile.boxRight - mobile.innerRight)).toBeLessThan(1.5);
  expect(Math.round(mobile.layerTop - mobile.slideTop)).toBe(0);
});

// @scenario surface=site_overlay_content_width
// @effects overlay_requires_full_width
test('본문 폭이면 메뉴 겹치기를 켜도 헤더는 그대로다', async ({ page }) => {
  await decorate(page, { width: 'content', header_overlay: true, autoplay: false }, { tone: 'dark' });
  await page.setViewportSize(VIEWPORTS.desktop);
  await gotoUser(page, '/');
  await expect(page.locator('.hs__slide--active .hs__eyebrow')).toHaveText('E2E 머리글', { timeout: 30_000 });
  const m = await measure(page);
  expect(m.overlay).toBeNull();
  expect(m.active).toBeNull();
  expect(m.headerBg).not.toBe('rgba(0, 0, 0, 0)');
  expect(m.heroTop).toBeGreaterThanOrEqual(m.headerHeight);
});

// @scenario surface=site_overlay_reduced_motion
// @effects reduced_motion_disables_text_effect
test('움직임 줄이기면 등장 효과 없이 보인다', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await decorate(page, { width: 'full', header_overlay: false }, { animation: 'zoom', animation_delay: 1 });
  await page.setViewportSize(VIEWPORTS.desktop);
  await gotoUser(page, '/');
  await expect(page.locator('.hs__slide--active .hs__eyebrow')).toHaveText('E2E 머리글', { timeout: 30_000 });
  const m = await measure(page);
  expect(m.overlay).toBeNull();
  expect(m.lines.every((l) => l.name === 'none')).toBe(true);
  await expect(page.locator('.hs__slide--active .hs__textbox')).toHaveClass(/hs__textbox--none/);
});

/** 수정 화면의 저장 전 표현값을 미리보기 상자 속성과 숫자 칸으로 읽는다 */
async function padValues(page: Page) {
  return {
    x: Number(await page.locator('.hs-pad input[data-axis="x"]').inputValue()),
    y: Number(await page.locator('.hs-pad input[data-axis="y"]').inputValue()),
  };
}

/** 편집 미리보기(iframe) 안의 문구 상자 */
const previewBox = (page: Page) => page.frameLocator('#hero_form_preview iframe').first().locator('.hs__textbox');

// @scenario surface=admin_editor_grid_drag_keyboard_save
// @effects editor_position_persisted, eyebrow_persisted
test('수정 화면 — 9칸·숫자·미리보기 끌기·화살표로 자리를 정하고 머리글·효과와 함께 저장한다', async ({ page, request, managerToken, created }) => {
  const title = uniqueTitle('문구자리');
  const id = await createBanner(request, managerToken, { is_active: false, title: { ko: title } });
  created.push(id);
  await authenticatePage(page, managerToken);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${ADMIN_LIST}/${id}/edit`);
  await expect(page.locator('#footer_save_button')).toBeEnabled({ timeout: 30_000 });

  // T1 — 기존 배너는 기본 자리(왼쪽 아래), 9칸 중 왼쪽 아래가 눌려 있다
  await expect(page.locator('.hs-pad__cell[data-spot="bottom_left"]')).toHaveAttribute('aria-pressed', 'true');
  expect(await padValues(page)).toEqual({ x: 0, y: 100 });

  // T2 — 9칸: 정가운데
  await page.locator('.hs-pad__cell[data-spot="middle_center"]').click();
  expect(await padValues(page)).toEqual({ x: 50, y: 50 });
  await expect(page.locator('.hs-pad__cell[data-spot="middle_center"]')).toHaveAttribute('aria-pressed', 'true');

  // 폭 50% — 끌어 옮길 거리가 생긴다
  await page.locator('#hero_text_width').fill('50');
  await expect(previewBox(page)).toBeVisible();

  // T3 — 미리보기 끌기(오른쪽 위로) 한 번, 화살표 키 세 번(왼쪽 1% ×2, Shift+아래 10%)
  const box = await previewBox(page).boundingBox();
  expect(box).not.toBeNull();
  await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2);
  await page.mouse.down();
  await page.mouse.move(box!.x + box!.width / 2 + 400, box!.y + box!.height / 2 - 400, { steps: 8 });
  await page.mouse.up();
  await expect.poll(() => padValues(page)).toEqual({ x: 100, y: 0 });
  await previewBox(page).focus();
  await page.keyboard.press('ArrowLeft');
  await page.keyboard.press('ArrowLeft');
  await page.keyboard.press('Shift+ArrowDown');
  await expect.poll(() => padValues(page)).toEqual({ x: 98, y: 10 });

  // 휴대폰 자리는 따로 — 기기를 바꾸면 휴대폰 값(기본)이 보인다
  await page.locator('.hs-pad__device').nth(1).click();
  expect(await padValues(page)).toEqual({ x: 0, y: 100 });
  await page.locator('.hs-pad__cell[data-spot="top_center"]').click();
  expect(await padValues(page)).toEqual({ x: 50, y: 0 });

  // 머리글·효과·바탕
  await page.locator('input[name="eyebrow[ko]"]').fill('E2E 머리글');
  await page.locator('#hero_text_animation').selectOption('blur');
  await page.locator('#hero_text_animation_speed').selectOption('fast');
  await page.locator('#hero_text_animation_delay').fill('0.5');
  await page.getByRole('radio', { name: '밝음 (메뉴 글자 검은색)' }).check();
  await expect(page.locator('[data-hs-action="replay"]')).toBeVisible();

  // T4 — 저장 200
  const saved = page.waitForResponse((r) => r.url().includes(`/admin/banners/${id}`) && r.request().method() === 'PUT');
  await page.locator('#footer_save_button').click();
  expect((await saved).status()).toBe(200);

  // T5 — 서버 값
  const detail = await (await request.get(`${API}/admin/banners/${id}`, { headers: auth(managerToken) })).json();
  expect(detail.data.eyebrow).toMatchObject({ ko: 'E2E 머리글' });
  expect(detail.data.presentation).toMatchObject({
    text_pc_x: 98, text_pc_y: 10, text_mobile_x: 50, text_mobile_y: 0, text_width: 50,
    animation: 'blur', animation_speed: 'fast', animation_delay: 0.5, tone: 'light',
  });

  // T6 — 저장하면 목록으로 돌아간다. 수정 화면을 새로 열어도 같은 값(PC 자리)
  await page.goto(`${ADMIN_LIST}/${id}/edit`);
  await expect(page.locator('#footer_save_button')).toBeEnabled({ timeout: 30_000 });
  await page.locator('.hs-pad__device').first().click();
  expect(await padValues(page)).toEqual({ x: 98, y: 10 });
  await expect(page.locator('#hero_text_animation')).toHaveValue('blur');

  // T7 — 목록으로 갔다가 다른(새) 배너 수정 화면으로 옮기면 그 배너의 기본 자리가 보인다(앞 배너 값이 남지 않는다)
  const otherTitle = uniqueTitle('다른배너');
  const other = await createBanner(request, managerToken, { is_active: false, title: { ko: otherTitle } });
  created.push(other);
  await page.goto(`${ADMIN_LIST}?position=home_hero`);
  await page.locator(`a[href$="/banners/${other}/edit"]`, { hasText: otherTitle }).click({ timeout: 30_000 });
  await expect(page).toHaveURL(new RegExp(`/banners/${other}/edit`));
  await expect(page.locator('#footer_save_button')).toBeEnabled({ timeout: 30_000 });
  await page.locator('.hs-pad__device').first().click();
  expect(await padValues(page)).toEqual({ x: 0, y: 100 });
  await expect(page.locator('#hero_text_animation')).toHaveValue('none');
});

// @scenario surface=admin_settings_header_overlay_save
// @effects position_setting_overlay_persisted
test('위치 설정 — 메뉴 겹치기를 켜서 저장하면 공개 응답 설정에 실리고 본문 폭이면 안내가 보인다', async ({ page, request, managerToken }) => {
  const list = await (await request.get(`${API}/admin/position-settings`, { headers: auth(managerToken) })).json();
  const original = list.data.positions.find((p: { key: string }) => p.key === 'shop_hero').settings;
  try {
    await authenticatePage(page, managerToken);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${ADMIN_LIST}?position=shop_hero`);
    const toggle = page.locator('#hero_slider_header_overlay');
    await expect(toggle).toBeVisible({ timeout: 30_000 });
    // shop_hero 는 본문 폭(운영 값) — 관리자 템플릿 Select 는 버튼형이라 고른 값은 버튼 글자로 본다
    await expect(page.locator('#hero_slider_width button').first()).toHaveText('본문 폭에 맞춤');
    if (!(await toggle.isChecked())) {
      await toggle.check();
    }
    await expect(page.locator('#header_overlay_needs_full')).toBeVisible();
    const saved = page.waitForResponse((r) => r.url().includes('/admin/position-settings/shop_hero') && r.request().method() === 'PUT');
    await page.locator('#slider_save_button').click();
    expect((await saved).status()).toBe(200);
    const after = await (await request.get(`${API}/admin/position-settings`, { headers: auth(managerToken) })).json();
    expect(after.data.positions.find((p: { key: string }) => p.key === 'shop_hero').settings).toMatchObject({ header_overlay: true, width: 'content' });
  } finally {
    await request.put(`${API}/admin/position-settings/shop_hero`, { headers: auth(managerToken), data: original });
  }
});
