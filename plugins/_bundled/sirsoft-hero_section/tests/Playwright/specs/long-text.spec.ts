/**
 * #810 후속 — 최대 길이 문구(제목 100·설명 300·버튼 30자)가 사이트 홈 구좌에서 잘리거나 넘치지 않는다.
 * @scenario-manifest tests/scenarios/preview-frame-and-conflict.yaml
 * @effects long_text_not_lost
 *
 * 실측(수정 전): 한글·일본어 최대 길이는 문구 상자가 구좌 위로 넘어 제목 위쪽이 가려졌고(태블릿 한글은 제목 전체),
 * 공백 없는 문자열은 줄을 바꾸지 못해 오른쪽이 잘렸으며 모바일 버튼이 구좌 폭을 넘었다. 문구를 줄이거나 지우지 않고
 * 모두 구좌 안(필요하면 문구 영역 안 스크롤)에서 읽을 수 있어야 하고, 스크롤 영역이 넘김 컨트롤 밑으로 지나가면 안 된다.
 * 사양이 만든 배너는 fixture 가 지운다(확인용 배너와 함께 2장이 되어 컨트롤이 보인다).
 */
import { test, expect, createBanner, gotoUser, auth, API, VIEWPORTS } from '../fixtures/hero-auth';
import type { APIRequestContext } from '@playwright/test';

test.setTimeout(150_000);

/** 길이 n 으로 반복한다. 서버가 앞뒤 공백을 지우므로 끝이 공백이 되지 않게 마지막 글자를 바꾼다. */
const rep = (s: string, n: number) => {
  const out = s.repeat(Math.ceil(n / s.length)).slice(0, n);
  return out.endsWith(' ') ? `${out.slice(0, -1)}.` : out;
};
const VARIANTS = {
  ko: [rep('가을 신상품 기획전 할인 행사 안내 ', 100), rep('새로 들어온 상품을 확인하고 혜택을 받아 보세요. ', 300), rep('자세히 보기 ', 30)],
  ja: [rep('秋の新商品フェア開催中のお知らせ ', 100), rep('新しく入荷した商品をチェックして特典を受け取ってください。', 300), rep('詳しく見る ', 30)],
  nospace: [rep('A', 100), rep('B', 300), rep('C', 30)],
};

for (const [name, [title, description, button]] of Object.entries(VARIANTS)) {
  // @scenario surface=long_text_fits_slot
  // @scenario surface=long_text_fits_slot, script=ko
  // @scenario surface=long_text_fits_slot, script=ja
  // @scenario surface=long_text_fits_slot, script=nospace
  // @effects long_text_not_lost
  test(`최대 길이 문구(${name})가 데스크톱·태블릿·모바일 구좌에서 잘리지 않는다`, async ({ page, request, managerToken, created }) => {
    const marker = `[${name}-${Date.now()}]`;
    const fullTitle = (marker + title).slice(0, 100);
    created.push(await createBanner(request, managerToken, { title: { ko: fullTitle }, description: { ko: description }, button_text: { ko: button } }));

    for (const viewport of ['desktop', 'tablet', 'mobile'] as const) {
      await page.setViewportSize(VIEWPORTS[viewport]);
      await gotoUser(page, '/');
      await expect(page.locator('.hs-slider .hs-slider__title', { hasText: marker }).first()).toBeAttached({ timeout: 30_000 });
      await page.waitForTimeout(500);
      const m = await page.evaluate((mark) => {
        const t = [...document.querySelectorAll<HTMLElement>('.hs-slider .hs-slider__title')].find((el) => el.textContent?.startsWith(mark))!;
        const text = t.closest('.hs-slider__text') as HTMLElement;
        const scroll = (t.closest('.hs-slider__scroll') ?? text) as HTMLElement;
        // 컨트롤은 구좌(.hs-slider) 기준, 문구는 제 슬라이드 기준 좌표로 맞춘다 — 그 슬라이드가 지금 보이는 장이 아니어도 비교된다
        const hsBox = t.closest('.hs-slider')!.getBoundingClientRect();
        const ctrl = t.closest('.hs-slider')?.querySelector('.hs-slider__controls')?.getBoundingClientRect() ?? null;
        const slide = (t.closest('.swiper-slide') ?? t.closest('.hs-slider__slide')) as HTMLElement;
        const f = slide.getBoundingClientRect();
        const box = (el: Element | null) => (el ? el.getBoundingClientRect() : null);
        const parts = [t, text.querySelector('.hs-slider__description'), text.querySelector('.hs-slider__button')].filter(Boolean) as HTMLElement[];
        return {
          textTop: box(text)!.top - f.top,
          textBottom: box(text)!.bottom - f.top,
          slideHeight: f.height,
          scrollable: scroll.scrollHeight <= scroll.clientHeight + 1 || ['auto', 'scroll'].includes(getComputedStyle(scroll).overflowY),
          // 글자가 보이는 스크롤 영역이 넘김 컨트롤과 겹치는 높이(가로로 겹칠 때만)
          hasControls: ctrl !== null,
          underControls: ctrl && box(scroll)!.right - f.left > ctrl.left - hsBox.left && box(scroll)!.left - f.left < ctrl.right - hsBox.left
            ? Math.max(0, box(scroll)!.bottom - f.top - (ctrl.top - hsBox.top)) : 0,
          overflowX: parts.map((el) => el.scrollWidth - el.clientWidth),
          rightOver: parts.map((el) => box(el)!.right - f.right),
          chars: parts.map((el) => el.textContent?.length ?? 0),
        };
      }, marker);
      const label = `${name} ${viewport} ${JSON.stringify(m)}`;
      expect(m.textTop, label).toBeGreaterThanOrEqual(-0.5);
      expect(m.textBottom, label).toBeLessThanOrEqual(m.slideHeight + 0.5);
      expect(m.scrollable, label).toBe(true);
      expect(m.hasControls, label).toBe(true);
      expect(m.underControls, label).toBeLessThanOrEqual(0.5);
      for (const over of m.overflowX) expect(over, label).toBeLessThanOrEqual(1);
      for (const over of m.rightOver) expect(over, label).toBeLessThanOrEqual(0.5);
      // 문구는 줄이거나 지우지 않는다
      expect(m.chars, label).toEqual([fullTitle.length, description.length, button.length]);
    }
  });
}

type Settings = { autoplay: boolean; interval_seconds: number; effect: string; width?: string };

/** 사이트 홈 위치 설정을 읽는다. */
async function readHomeSettings(request: APIRequestContext, token: string): Promise<Settings> {
  const response = await request.get(`${API}/admin/position-settings`, { headers: auth(token) });
  return ((await response.json()).data.positions as Array<{ key: string; settings: Settings }>).find((p) => p.key === 'home_hero')!.settings;
}

/** 사이트 홈 위치 설정을 저장한다. */
async function saveHomeSettings(request: APIRequestContext, token: string, settings: Settings): Promise<void> {
  const response = await request.put(`${API}/admin/position-settings/home_hero`, { headers: auth(token), data: settings });
  expect(response.status(), await response.text()).toBe(200);
}

test.describe('긴 문구 영역 키보드·손가락 (mobile)', () => {
  test.use({ hasTouch: true, viewport: VIEWPORTS.mobile });

  // 화면 전체 폭은 휴대폰에서 구좌가 넓고 높아져 최대 길이 문구도 넘치지 않는다 — 넘치는 문구 영역을 보려고 본문 폭으로 두고, 끝나면 되돌린다
  let original: Settings | null = null;
  test.beforeEach(async ({ request, managerToken }) => {
    original = await readHomeSettings(request, managerToken);
    if (original.width !== 'content') await saveHomeSettings(request, managerToken, { ...original, width: 'content' });
  });
  test.afterEach(async ({ request, managerToken }) => {
    if (original && original.width !== 'content') await saveHomeSettings(request, managerToken, original);
    original = null;
  });

  // @scenario surface=long_text_scroll_keyboard_and_touch
  // @effects long_text_not_lost
  test('넘치는 문구 영역만 탭으로 들어가 키로 스크롤하고, 손가락 세로 쓸기는 문구를 스크롤·가로 쓸기는 장을 넘긴다', async ({ page, request, managerToken, created }) => {
    const [title, description, button] = VARIANTS.ko;
    const marker = `[touch-${Date.now()}]`;
    const fullTitle = (marker + title).slice(0, 100);
    const shortTitle = `[short-${Date.now()}] 짧은 제목`;
    created.push(await createBanner(request, managerToken, { title: { ko: fullTitle }, description: { ko: description }, button_text: { ko: button } }));
    created.push(await createBanner(request, managerToken, { title: { ko: shortTitle } }));

    await gotoUser(page, '/');
    const hs = page.locator('section.hs-slider[role="region"]');
    await expect(hs.locator('.hs-slider__title', { hasText: marker })).toBeAttached({ timeout: 30_000 });
    // 자동 넘김이 단언 사이에 장을 바꾸지 않게 멈춘다
    if (await hs.locator('[data-hs-control="toggle"]').count()) await hs.locator('[data-hs-control="toggle"]').tap();
    const slideOf = (text: string) => hs.locator('.hs-slider__slide', { has: page.locator('.hs-slider__title', { hasText: text }) });
    // 휴대폰은 화살표를 감추므로 그 장의 점을 누른다
    await hs.locator('.hs-slider__pagination button').nth(Number(await slideOf(marker).getAttribute('data-index'))).tap();
    await page.waitForTimeout(700);
    await expect(slideOf(marker)).toHaveClass(/hs-slider__slide--active/);
    const scroll = slideOf(marker).locator('.hs-slider__scroll');
    const scrollTop = () => scroll.evaluate((el) => el.scrollTop);

    // 넘치는 보이는 장만 탭 순서에 들어오고 배너 제목으로 이름 붙는다. 넘치지 않는 짧은 문구 영역은 그대로다
    await expect(scroll).toHaveAttribute('tabindex', '0');
    await expect(scroll).toHaveAttribute('role', 'region');
    // 영역 이름은 제목과 겹쳐 읽히지 않게 「배너 문구」 — 제목은 안의 제목 줄이 읽는다
    await expect(scroll).toHaveAttribute('aria-label', '배너 문구');
    const short = slideOf(shortTitle).locator('.hs-slider__scroll');
    await expect(short).not.toHaveAttribute('tabindex', /.*/);
    await expect(short).not.toHaveAttribute('role', /.*/);

    // 탭으로 들어가 아래 화살표로 스크롤한다(좌우 화살표는 장 넘김이라 그대로 둔다)
    await hs.locator('.hs-slider__pagination button').first().focus();
    await page.keyboard.press('Shift+Tab');
    for (let i = 0; i < 6 && !(await scroll.evaluate((el) => el === document.activeElement)); i++) await page.keyboard.press('Shift+Tab');
    expect(await scroll.evaluate((el) => el === document.activeElement)).toBe(true);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    await expect.poll(scrollTop).toBeGreaterThan(0);
    await expect(slideOf(marker)).toHaveClass(/hs-slider__slide--active/);
    await scroll.evaluate((el) => { el.scrollTop = 0; (el as HTMLElement).blur(); });

    // 손가락 세로 쓸기: 문구 영역 안에서 위로 밀면 문구가 스크롤되고 장은 그대로다
    const client = await page.context().newCDPSession(page);
    const box = (await scroll.boundingBox())!;
    const x = Math.round(box.x + box.width / 2);
    const fromY = Math.round(box.y + box.height * 0.8);
    const toY = Math.round(box.y + box.height * 0.2);
    await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: fromY }] });
    for (let i = 1; i <= 8; i++) {
      await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: Math.round(fromY + ((toY - fromY) * i) / 8) }] });
    }
    await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await expect.poll(scrollTop).toBeGreaterThan(0);
    await page.waitForTimeout(700);
    await expect(slideOf(marker)).toHaveClass(/hs-slider__slide--active/);

    // 손가락 가로 쓸기: 같은 문구 영역 위에서도 다음 장으로 넘어간다
    const y = Math.round(box.y + box.height / 2);
    const fromX = Math.round(box.x + box.width * 0.9);
    const toX = Math.round(box.x + box.width * 0.1);
    await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: fromX, y }] });
    for (let i = 1; i <= 8; i++) {
      await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: Math.round(fromX + ((toX - fromX) * i) / 8), y }] });
    }
    await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await expect(slideOf(marker)).not.toHaveClass(/hs-slider__slide--active/, { timeout: 5_000 });
    // 보이지 않게 된 장의 문구 영역은 탭 순서에서 빠진다
    await expect(scroll).toHaveAttribute('tabindex', '-1');
  });

  // @scenario surface=long_text_scroll_keyboard_and_touch, link=whole_slide
  // @effects long_text_not_lost
  test('버튼 없는 전체 링크 배너도 넘치는 문구 영역이 링크 밖에 있어 탭·스크롤이 되고, 문구를 누르면 링크로 간다', async ({ page, request, managerToken, created }) => {
    // 버튼이 없으면 한글 최대 길이는 휴대폰 구좌에 들어간다 — 더 많이 줄바꿈하는 일본어 최대 길이로 넘치게 한다
    const [title, description] = VARIANTS.ja;
    const marker = `[link-${Date.now()}]`;
    // 서버가 앞뒤 공백을 지우므로 100자로 자른 끝 공백을 미리 지운다
    const fullTitle = (marker + title).slice(0, 100).trimEnd();
    // 버튼 문구를 비우면 슬라이드 전체가 링크다
    created.push(await createBanner(request, managerToken, { title: { ko: fullTitle }, description: { ko: description }, link_url: '/shop/products' }));

    await gotoUser(page, '/');
    const hs = page.locator('section.hs-slider[role="region"]');
    await expect(hs.locator('.hs-slider__title', { hasText: marker })).toBeAttached({ timeout: 30_000 });
    if (await hs.locator('[data-hs-control="toggle"]').count()) await hs.locator('[data-hs-control="toggle"]').tap();
    const slide = hs.locator('.hs-slider__slide', { has: page.locator('.hs-slider__title', { hasText: marker }) });
    await hs.locator('.hs-slider__pagination button').nth(Number(await slide.getAttribute('data-index'))).tap();
    await page.waitForTimeout(700);
    await expect(slide).toHaveClass(/hs-slider__slide--active/);
    const link = slide.locator('a.hs-slider__link');
    const scroll = slide.locator('.hs-slider__scroll');

    expect(await scroll.evaluate((el) => el.scrollHeight > el.clientHeight + 1), '전제: 문구가 넘친다').toBe(true);
    // 링크는 비어 있고(문구 영역을 품지 않는다) 제목으로 이름 붙는다. 넘치는 문구 영역은 링크 밖에서 탭 순서에 든다
    await expect(link).toHaveAttribute('aria-label', fullTitle);
    expect(await link.evaluate((el) => el.childElementCount)).toBe(0);
    expect(await slide.locator('a .hs-slider__scroll, a [tabindex]').count()).toBe(0);
    await expect(scroll).toHaveAttribute('tabindex', '0');

    // 탭 순서: 링크 → 문구 영역, 문구 영역에서 ↓ 로 스크롤해도 이동하지 않는다
    await link.focus();
    await page.keyboard.press('Tab');
    expect(await scroll.evaluate((el) => el === document.activeElement)).toBe(true);
    await page.keyboard.press('ArrowDown');
    await expect.poll(() => scroll.evaluate((el) => el.scrollTop)).toBeGreaterThan(0);
    await scroll.evaluate((el) => { el.scrollTop = 0; (el as HTMLElement).blur(); });

    // 손가락 세로 쓸기는 문구만 스크롤하고 링크로 가지 않는다
    const client = await page.context().newCDPSession(page);
    const box = (await scroll.boundingBox())!;
    const x = Math.round(box.x + box.width / 2);
    const fromY = Math.round(box.y + box.height * 0.8);
    const toY = Math.round(box.y + box.height * 0.2);
    await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: fromY }] });
    for (let i = 1; i <= 8; i++) {
      await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: Math.round(fromY + ((toY - fromY) * i) / 8) }] });
    }
    await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await expect.poll(() => scroll.evaluate((el) => el.scrollTop)).toBeGreaterThan(0);
    await page.waitForTimeout(700);
    expect(new URL(page.url()).pathname).toBe('/');

    // 문구를 누르면 링크 주소로 간다
    await slide.locator('.hs-slider__title').tap();
    await expect(page).toHaveURL(/\/shop\/products/, { timeout: 30_000 });
  });
});
