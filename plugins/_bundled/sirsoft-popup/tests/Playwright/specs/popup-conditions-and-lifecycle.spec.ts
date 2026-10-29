/**
 * @file popup-conditions-and-lifecycle.spec.ts
 * @description GDPR 쿠키 배너와 동시 표시(가리지 않음), 언어 전환, alt 없는 링크 이름(관리용 제목 비노출), 다크 모드 대비, 검색엔진 봇에는 출력 안 함, 플러그인 비활성화 즉시 숨김.
 * (매니페스트 e2e-flows.yaml)
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, createHtmlPopup, createImagePopup, gotoUser, layer, uniqueTitle, VIEWPORTS, API } from '../fixtures/popup-auth';

const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../../../');
const BOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

type Viewport = 'desktop' | 'mobile';
// 흐름 함수가 받는 사양 fixture
type Ctx = { page: any; request: any; managerToken: string; created: number[] };

/** 상대 휘도 대비(WCAG) */
function contrast(fg: number[], bg: number[]): number {
  const lum = (c: number[]) => {
    const [r, g, b] = c.map((v) => {
      const s = v / 255;
      return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const [a, b] = [lum(fg), lum(bg)].sort((x, y) => y - x);
  return (a + 0.05) / (b + 0.05);
}

/** GDPR 배너가 함께 보이고 팝업이 배너를 가리지 않는다 */
async function flowGdpr({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
  created.push(await createHtmlPopup(request, managerToken));
  await gotoUser(page, '/');
  const banner = page.locator('#gdpr_cookie_banner');
  await expect(banner).toBeVisible();
  await expect(layer(page)).toBeVisible();

  const box = (await banner.boundingBox())!;
  // 배너 한가운데를 누르면 배너가 맞아야 한다(팝업 배경·레이어가 덮지 않음)
  const hit = await page.evaluate(([x, y]) => !!document.elementFromPoint(x, y)?.closest('#gdpr_cookie_banner'), [box.x + box.width / 2, box.y + box.height / 2]);
  expect(hit).toBe(true);
  if (viewport === 'mobile') {
    const dialog = (await layer(page).boundingBox())!;
    expect(dialog.y + dialog.height).toBeLessThanOrEqual(box.y + 1);
  }
}

/** 언어를 바꾸면 alt·본문이 그 언어로 바뀐다 */
async function flowLocale({ page, request, managerToken, created }: Ctx): Promise<void> {
  const image = await createImagePopup(request, managerToken, { image_alt: { ko: '한국어 대체', en: 'English alt' }, link_url: '/page/about' });
  const html = await createHtmlPopup(request, managerToken, { body_html: { ko: '<p>한국어 본문</p>', en: '<p>English body</p>' } });
  created.push(image, html);

  await gotoUser(page, '/');
  await expect(page.locator(`.g7pp-slide[data-popup-id="${image}"] a.g7pp-cover-link`)).toHaveAttribute('aria-label', '한국어 대체');
  await page.evaluate(() => (window as any).G7Core.dispatch({ handler: 'setLocale', target: 'en' }));
  await expect(page.locator(`.g7pp-slide[data-popup-id="${image}"] a.g7pp-cover-link`)).toHaveAttribute('aria-label', 'English alt', { timeout: 15_000 });
  await expect(page.locator(`.g7pp-slide[data-popup-id="${html}"] .g7pp-html`)).toContainText('English body');
  await page.evaluate(() => (window as any).G7Core.dispatch({ handler: 'setLocale', target: 'ko' }));
}

/** alt 를 비운 링크 팝업 — 덮는 링크 이름은 일반 문구이고, 관리용 제목은 손님 화면·공개 응답 어디에도 없다 */
async function flowNoAlt({ page, request, managerToken, created }: Ctx): Promise<void> {
  const title = uniqueTitle('내부 메모 alt 없음');
  const image = await createImagePopup(request, managerToken, { title, image_alt: null, link_url: '/page/about' });
  created.push(image);

  await gotoUser(page, '/');
  const link = page.locator(`.g7pp-slide[data-popup-id="${image}"] a.g7pp-cover-link`);
  await expect(link).toHaveAttribute('aria-label', '팝업 링크 열기');
  expect(await page.locator('[data-popup-layer]').innerHTML()).not.toContain(title);
  // 응답 JSON 은 한글을 \uXXXX 로 낼 수 있어 풀어서 비교한다
  const payload = await page.evaluate(async (url) => JSON.stringify(await (await fetch(url, { headers: { Accept: 'application/json' } })).json()), `${API}/popups`);
  expect(payload).toContain(`"id":${image}`);
  expect(payload).not.toContain(title);
}

/** 다크 모드에서 레이어 바탕·글자 대비가 4.5:1 이상 */
async function flowDark({ page, request, managerToken, created }: Ctx): Promise<void> {
  created.push(await createHtmlPopup(request, managerToken, { body_html: { ko: '<p>다크 모드 본문</p>' } }));
  await page.addInitScript(() => localStorage.setItem('g7_color_scheme', 'dark'));
  await gotoUser(page, '/');
  await expect(page.locator('html')).toHaveClass(/dark/);
  await expect(layer(page)).toBeVisible();
  const [fg, bg, footFg, footBg] = await page.evaluate(() => {
    const rgb = (v: string) => (v.match(/\d+/g) ?? []).slice(0, 3).map(Number);
    const dialog = document.querySelector('[data-popup-layer] [role="dialog"]') as HTMLElement;
    const body = dialog.querySelector('.g7pp-html') as HTMLElement;
    const close = dialog.querySelector('.g7pp-footer__close') as HTMLElement;
    const footer = dialog.querySelector('.g7pp-footer') as HTMLElement;
    return [rgb(getComputedStyle(body).color), rgb(getComputedStyle(dialog).backgroundColor), rgb(getComputedStyle(close).color), rgb(getComputedStyle(footer).backgroundColor)];
  });
  expect(contrast(fg, bg)).toBeGreaterThanOrEqual(4.5);
  expect(contrast(footFg, footBg)).toBeGreaterThanOrEqual(4.5);
  // 어두운 바탕
  expect(bg.reduce((a: number, b: number) => a + b, 0)).toBeLessThan(300);
}

test.describe('팝업 조건과 생애주기', () => {
  // @scenario flow=gdpr_banner_visible_with_popup, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('GDPR 쿠키 배너와 함께 떠도 배너를 가리지 않는다 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flowGdpr({ page, request, managerToken, created }, 'desktop');
  });

  // @scenario flow=gdpr_banner_visible_with_popup, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('GDPR 쿠키 배너와 함께 떠도 배너를 가리지 않는다 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flowGdpr({ page, request, managerToken, created }, 'mobile');
  });

  // @scenario flow=locale_switch_changes_alt_and_body, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('언어를 바꾸면 alt·본문이 그 언어로 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flowLocale({ page, request, managerToken, created });
  });

  // @scenario flow=link_without_alt_hides_admin_title, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('alt 를 비운 링크 팝업은 일반 링크 이름이고 관리용 제목이 나가지 않는다 (desktop)', async ({ page, request, managerToken, created }) => {
    test.setTimeout(90_000);
    await page.setViewportSize(VIEWPORTS.desktop);
    await flowNoAlt({ page, request, managerToken, created });
  });

  // @scenario flow=locale_switch_changes_alt_and_body, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('언어를 바꾸면 alt·본문이 그 언어로 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flowLocale({ page, request, managerToken, created });
  });

  // @scenario flow=dark_mode_contrast, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('다크 모드 대비 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flowDark({ page, request, managerToken, created });
  });

  // @scenario flow=dark_mode_contrast, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('다크 모드 대비 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flowDark({ page, request, managerToken, created });
  });

  // @scenario flow=bot_user_agent_gets_no_layer, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('검색엔진 봇에게는 레이어를 출력하지 않는다', async ({ request, managerToken, created }) => {
    const id = await createHtmlPopup(request, managerToken, { body_html: { ko: '<p>봇 확인 본문</p>' } });
    created.push(id);

    const human = await (await request.get(`${API}/popups`, { headers: { Accept: 'application/json' } })).json();
    expect(human.data.popups.map((p: { id: number }) => p.id)).toContain(id);

    const bot = await (await request.get(`${API}/popups`, { headers: { Accept: 'application/json', 'User-Agent': BOT_UA } })).json();
    expect(bot.data.popups).toEqual([]);

    const html = await (await request.get('/', { headers: { 'User-Agent': BOT_UA } })).text();
    expect(html).not.toContain('봇 확인 본문');
    expect(html).not.toContain('data-popup-layer');
  });

  // @scenario flow=plugin_deactivate_hides_layer, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('플러그인을 끄면 레이어가 즉시 사라지고, 다시 켜면 돌아온다', async ({ page, request, managerToken, created }) => {
    test.setTimeout(240_000);
    const id = await createHtmlPopup(request, managerToken);
    created.push(id);
    await page.setViewportSize(VIEWPORTS.desktop);

    try {
      execSync('php artisan plugin:deactivate sirsoft-popup', { cwd: CORE_ROOT, stdio: 'pipe' });
      await gotoUser(page, '/');
      await page.waitForTimeout(2000);
      await expect(page.locator('[data-popup-layer]')).toHaveCount(0);
      expect((await request.get(`${API}/popups`, { headers: { Accept: 'application/json' } })).status()).toBe(404);
    } finally {
      execSync('php artisan plugin:activate sirsoft-popup', { cwd: CORE_ROOT, stdio: 'pipe' });
    }

    await gotoUser(page, '/');
    await expect(page.locator(`.g7pp-slide[data-popup-id="${id}"]`)).toHaveCount(1);
  });
});
