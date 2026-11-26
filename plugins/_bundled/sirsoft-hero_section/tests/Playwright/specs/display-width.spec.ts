/**
 * 위치별 「표시 폭」(본문 폭·화면 전체)이 사이트 홈·쇼핑몰 메인과 관리자 미리보기에서 같은 폭·정렬로 그려지는지.
 * @scenario-manifest tests/scenarios/display-width.yaml
 *
 * 템플릿(sirsoft-basic)은 히어로 자리를 본문 상자 밖(`_user_base` page_top)에 두고 본문 상자 클래스를 확장점 속성으로 넘긴다.
 * 본문 폭이면 슬라이더가 본문 상자 안(종전 화면과 같은 폭), 화면 전체면 화면 끝까지 그리고 제목만 본문과 같은 줄에 선다.
 * 값은 하드코딩하지 않고 같은 화면의 본문을 재어 비교한다 — 쇼핑몰 메인은 본문 상자(`#main_content`), 사이트 홈은 본문 상자 안에
 * 여백을 한 번 더 두므로 그 아래 카드 줄(`#home_top_row`)이다(히어로가 카드 영역과 같은 폭). 바꾼 위치 설정은 끝나면 되돌리고, 만든 배너는 fixture 가 지운다.
 */
import { test, expect, authenticatePage, createBanner, gotoUser, auth, API, ADMIN_LIST, VIEWPORTS } from '../fixtures/hero-auth';
import type { APIRequestContext, Page } from '@playwright/test';

test.setTimeout(180_000);

type Settings = { autoplay: boolean; interval_seconds: number; effect: string; width?: string };

/** 위치 설정을 읽는다. */
async function readSettings(request: APIRequestContext, token: string, position: string): Promise<Settings> {
  const response = await request.get(`${API}/admin/position-settings`, { headers: auth(token) });
  expect(response.status()).toBe(200);
  const positions = (await response.json()).data.positions as Array<{ key: string; settings: Settings }>;
  return positions.find((p) => p.key === position)!.settings;
}

/** 위치 설정을 저장한다. */
async function saveSettings(request: APIRequestContext, token: string, position: string, settings: Settings): Promise<void> {
  const response = await request.put(`${API}/admin/position-settings/${position}`, { headers: auth(token), data: settings });
  expect(response.status(), await response.text()).toBe(200);
}

/** 사이트 화면의 슬라이더·본문 상자·제목 위치 */
type Measure = { hsLeft: number; hsWidth: number; hsHeight: number; pageWidth: number; boxLeft: number; boxWidth: number; titleLeft: number; controlsRight: number | null; full: boolean };

/** 시험 폭 — 기본 3종에 1920px 보다 넓은 화면(화면 전체 높이 상한 확인용)을 더한다 */
const SIZES = { ...VIEWPORTS, wide: { width: 2400, height: 1200 } } as const;

async function measureSite(page: Page, path: string, viewport: keyof typeof SIZES, title: string): Promise<Measure> {
  await page.setViewportSize(SIZES[viewport]);
  await gotoUser(page, path);
  await expect(page.locator('.hs-slider .hs-slider__title', { hasText: title }).first()).toBeAttached({ timeout: 30_000 });
  await page.waitForTimeout(500);
  return page.evaluate((text) => {
    const hs = document.querySelector('section.hs-slider') as HTMLElement;
    const r = hs.getBoundingClientRect();
    const main = document.getElementById('main_content') as HTMLElement;
    const style = getComputedStyle(main);
    const m = main.getBoundingClientRect();
    // 사이트 홈은 카드 줄이 본문 폭이다(본문 상자 안 여백을 한 번 더 둔다)
    const cards = document.getElementById('home_top_row');
    const c = cards?.getBoundingClientRect();
    const titleEl = [...hs.querySelectorAll<HTMLElement>('.hs-slider__title')].find((el) => el.textContent === text) as HTMLElement;
    // 넘김 트랙은 장마다 옆으로 밀려 있다 — 제목 위치는 그 장 안에서 잰 뒤 슬라이더 왼쪽 기준으로 옮긴다
    const slide = titleEl.closest('.hs-slider__slide') as HTMLElement;
    return {
      hsLeft: r.left, hsWidth: r.width, hsHeight: r.height, pageWidth: document.documentElement.clientWidth,
      boxLeft: c ? c.left : m.left + parseFloat(style.paddingLeft), boxWidth: c ? c.width : m.width - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
      titleLeft: r.left + titleEl.getBoundingClientRect().left - slide.getBoundingClientRect().left, full: hs.classList.contains('hs-slider--full'),
      controlsRight: (hs.querySelector('.hs-slider__controls') as HTMLElement | null)?.getBoundingClientRect().right ?? null,
    };
  }, title);
}

for (const [position, path] of [['home_hero', '/'], ['shop_hero', '/shop/products']] as const) {
  // @scenario surface=site_width_home_hero
  // @scenario surface=site_width_shop_hero
  // @effects site_slider_matches_width_setting
  test(`${position} 표시 폭 — 본문 폭은 본문 상자 안, 화면 전체는 화면 끝까지(제목은 본문 줄)`, async ({ page, request, managerToken, created }) => {
    const original = await readSettings(request, managerToken, position);
    const title = `E2E 표시폭 ${position} ${Date.now()}`;
    created.push(await createBanner(request, managerToken, { position, title: { ko: title } }));

    try {
      for (const width of ['content', 'full'] as const) {
        await saveSettings(request, managerToken, position, { ...original, width });
        for (const viewport of ['desktop', 'tablet', 'mobile', 'wide'] as const) {
          const m = await measureSite(page, path, viewport, title);
          const label = `${position} ${width} ${viewport}`;
          expect(m.full, label).toBe(width === 'full');
          if (width === 'content') {
            // 종전 화면과 같다 — 본문 상자(여백 안쪽)와 같은 왼쪽·폭
            expect(Math.abs(m.hsLeft - m.boxLeft), `${label} left`).toBeLessThan(0.5);
            expect(Math.abs(m.hsWidth - m.boxWidth), `${label} width`).toBeLessThan(0.5);
          } else {
            // 화면 끝에서 끝까지, 높이는 640px 상한
            expect(Math.abs(m.hsLeft), `${label} left`).toBeLessThan(0.5);
            expect(Math.abs(m.hsWidth - m.pageWidth), `${label} width`).toBeLessThan(0.5);
            expect(m.hsHeight, `${label} height`).toBeLessThanOrEqual(640.5);
            // 제목은 본문과 같은 줄(본문 상자 안쪽 왼쪽)에 선다
            expect(Math.abs(m.titleLeft - m.boxLeft), `${label} title ${m.titleLeft} vs ${m.boxLeft}`).toBeLessThan(1);
            // 넘김 단추(배너 2장 이상)는 본문 상자 오른쪽 끝에 맞는다
            if (m.controlsRight !== null) {
              expect(Math.abs(m.controlsRight - (m.boxLeft + m.boxWidth)), `${label} controls`).toBeLessThan(1);
            }
            // 1920px 보다 넓으면 비율대로 키우지 않고 640px 에서 멈춘다
            if (viewport === 'wide') expect(Math.abs(m.hsHeight - 640), `${label} capped height`).toBeLessThan(0.5);
          }
        }
      }
    } finally {
      await saveSettings(request, managerToken, position, original);
    }
  });
}

// @scenario surface=admin_width_setting
// @effects preview_follows_width_before_save, width_saved_and_restored
test('관리자 목록 — 표시 폭을 고르면 저장 전에 미리보기가 바뀌고, 저장하면 새로고침 뒤에도 남는다', async ({ page, request, managerToken, created }) => {
  const original = await readSettings(request, managerToken, 'home_hero');
  created.push(await createBanner(request, managerToken, { position: 'home_hero', title: { ko: `E2E 표시폭 관리자 ${Date.now()}` } }));
  await saveSettings(request, managerToken, 'home_hero', { ...original, width: 'content' });

  const preview = () => page.evaluate(() => {
    const frame = document.querySelector('#hero_preview iframe, .hs-preview iframe') as HTMLIFrameElement | null;
    const hs = frame?.contentDocument?.querySelector('section.hs-slider') as HTMLElement | null;
    return hs ? { full: hs.classList.contains('hs-slider--full'), width: hs.getBoundingClientRect().width, viewport: Number(frame!.getAttribute('width')) } : null;
  });

  try {
    await page.setViewportSize(VIEWPORTS.desktop);
    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}?position=home_hero`);
    // 관리자 템플릿 Select 는 버튼형 드롭다운이다 — id 는 바깥 상자, 고른 값은 버튼 글자
    const select = page.locator('#hero_slider_width button').first();
    await expect(select).toHaveText('본문 폭에 맞춤', { timeout: 30_000 });
    await expect.poll(async () => (await preview())?.full).toBe(false);
    const contentWidth = (await preview())!.width;

    await select.click();
    await page.getByRole('option', { name: '화면 전체 폭', exact: true }).click();
    // 저장 전 — 미리보기만 화면 전체(기기 viewport 폭)로 바뀐다
    await expect.poll(async () => (await preview())?.full).toBe(true);
    const fullPreview = (await preview())!;
    expect(Math.abs(fullPreview.width - fullPreview.viewport)).toBeLessThan(0.5);
    expect(fullPreview.width).toBeGreaterThan(contentWidth);
    expect((await readSettings(request, managerToken, 'home_hero')).width).toBe('content');

    const saved = page.waitForResponse((r) => r.url().includes('/admin/position-settings/home_hero') && r.request().method() === 'PUT');
    await page.locator('#slider_save_button').click();
    const response = await saved;
    expect(response.status()).toBe(200);
    expect(JSON.parse(response.request().postData() ?? '{}').width).toBe('full');
    expect((await readSettings(request, managerToken, 'home_hero')).width).toBe('full');

    await page.reload();
    await expect(select).toHaveText('화면 전체 폭', { timeout: 30_000 });
    await expect(page.locator('#slider_save_button')).toBeDisabled();
  } finally {
    await saveSettings(request, managerToken, 'home_hero', original);
  }
});
