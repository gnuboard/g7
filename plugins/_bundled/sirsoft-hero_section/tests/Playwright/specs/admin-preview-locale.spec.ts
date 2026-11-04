/**
 * 관리자 화면 — 목록 미리보기의 다국어 칸·오류 경계, 언어 이름 표시, 링크 칸 예시 (W1-001, W2-002 같은 결함군, W7-005)
 *
 * 화면 기준:
 *   - 머리글(언어별 입력)이 있는 배너가 노출 중이어도 「지금 노출 중」 미리보기는 머리글을 글자로 그린다(React #31 로 「컴포넌트 로드
 *     실패」가 되지 않는다).
 *   - 미리보기 안에서 렌더가 깨지면 그 자리에만 안내가 뜨고, [쇼핑몰 메인] 탭으로 옮기면 쇼핑 배너로 다시 그린다.
 *   - 목록 「제목 없음」 배지, 수정 화면의 미리보기 언어 선택과 「기본 언어로 대신 표시」 안내는 언어 탭과 같은 언어 이름(한국어, English)을 쓴다.
 *   - 등록·수정 화면 링크 칸 예시는 번들 사용자 템플릿에 있는 쇼핑몰 첫 화면 주소(/shop/products)다.
 *
 * 데이터: 개발 DB 를 바꾸지 않는다. 미리보기·목록 응답은 실제 응답을 받아 `data.data` 만 시험 배너로 바꿔 fulfill 하고, 이미지는 1x1 PNG
 *   로 응답한다. GET·HEAD·OPTIONS 가 아닌 `/api/` 요청은 네트워크 단에서 끊는다(fixtures/hero-head.ts `guardWrites`).
 *
 * 계정·수정 전 대조: fixtures/hero-head.ts 머리 주석(`G7_E2E_TOKEN` 또는 `G7_E2E_ADMIN_EMAIL`+`G7_E2E_ADMIN_PASSWORD_FILE`,
 *   `G7_E2E_HERO_HEAD_DIR`). 수정 전 판에서는 네 시험 모두 red 다.
 *
 * @scenario-manifest tests/scenarios/admin-screens.yaml
 *
 * 실행(플러그인 디렉토리에서):
 *   PLAYWRIGHT_BASE_URL=https://<개발 사이트 주소> npm run test:e2e -- specs/admin-preview-locale.spec.ts
 */
import { test, expect, type Page, type Route } from '@playwright/test';
import { resolveToken, guardWrites, useHeadHero, HEAD_DIR, PNG_1X1, type HeadSwap } from '../fixtures/hero-head';

// 시험끼리 상태를 나누지 않는다 — 하나가 깨져도 나머지를 잰다(수정 전 대조에서 시험마다 red 를 보려고). 워커는 설정대로 1개
test.describe.configure({ timeout: 150_000 });

const API = '/api/plugins/sirsoft-hero_section/admin';
const LIST = '/admin/plugins/sirsoft-hero_section/banners';
/** 수정 화면 — 배너 상세 응답은 시험 배너로 fulfill 한다(DB 에 없는 id) */
const EDIT_ID = 990705;
const EDIT = `/admin/plugins/sirsoft-hero_section/banners/${EDIT_ID}/edit`;
/** 엔진 오류 경계가 그리는 기본 박스 제목(엔진 고정 문구) */
const ENGINE_BOX = '컴포넌트 로드 실패';
/** 시험 이미지 주소(1x1 PNG 로 응답) */
const IMAGE = '/api/plugins/sirsoft-hero_section/images/e2e-h-preview';

/**
 * 관리자 상세 모양(미리보기 응답 `BannerResource::toArray`) 배너 — 문구 칸은 언어별 원본 객체다.
 *
 * @param id 배너 id
 * @param overrides 바꿀 값
 * @returns 배너
 */
function detailBanner(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id, position: 'home_hero', media_type: 'image', is_active: true, status: 'active', sort_order: 1, display_period: 'always',
    title: { ko: 'E2E 미리보기 배너' }, title_localized: 'E2E 미리보기 배너', title_locales: ['ko'],
    eyebrow: null, eyebrow_visible: true, eyebrow_color: '#FFFFFF',
    description: { ko: 'E2E 미리보기 설명' }, button_text: { ko: '' }, image_alt: { ko: '' },
    presentation: { tone: 'dark', overlay: 'none', animation: 'none' },
    title_visible: true, title_color: '#FFFFFF', title_align: 'left',
    description_visible: true, description_color: '#E5E7EB', description_align: 'left',
    link_url: null, link_new_window: false, youtube_id: null, youtube_url: null,
    images: { pc: { url: IMAGE, width: 1920, height: 640 }, mobile: null },
    videos: { pc: null, mobile: null },
    ...overrides,
  };
}

/**
 * 목록 행 모양(`BannerResource::toListArray`) 배너.
 *
 * @param id 배너 id
 * @param title 한국어 제목
 * @returns 행
 */
function listRow(id: number, title: string): Record<string, unknown> {
  return {
    id, position: 'home_hero', media_type: 'image', is_active: true, status: 'active', sort_order: 1, display_period: 'always',
    starts_at_display: null, ends_at_display: null, title: { ko: title }, title_localized: title, title_locales: ['ko'],
    created_at_display: '2026-11-04 10:00:00', thumbnail: { url: IMAGE, width: 1920, height: 640 },
    has_mobile_image: true, button_without_link: false, abilities: { can_update: true, can_delete: true },
  };
}

type Mocks = { preview?: Record<string, Record<string, unknown>[]>; rows?: Record<string, Record<string, unknown>[]> };

/**
 * 토큰을 심고 쓰기를 막은 뒤, 수정 전 대조(켜져 있으면)와 위치별 미리보기·목록 응답을 건다.
 *
 * @param page 페이지
 * @param token 관리자 토큰
 * @param mocks 위치별 미리보기 배너·목록 행(없는 위치는 실제 응답 그대로)
 * @returns 끊은 쓰기, 바꿔 끼운 기록, pageerror
 */
async function prepare(page: Page, token: string, mocks: Mocks): Promise<{ writes: string[]; swap: HeadSwap | null; pageErrors: string[] }> {
  const pageErrors: string[] = [];
  page.on('pageerror', (e) => pageErrors.push(String(e.message).split(/\r?\n/)[0].slice(0, 160)));
  await page.addInitScript((t) => localStorage.setItem('auth_token', t), token);
  const writes = await guardWrites(page);
  const swap = await useHeadHero(page);

  await page.route((url) => url.pathname === IMAGE, (route) => route.fulfill({ status: 200, contentType: 'image/png', body: PNG_1X1 }));
  await page.route((url) => url.pathname === `${API}/banners/preview` || url.pathname === `${API}/banners`, async (route: Route) => {
    const url = new URL(route.request().url());
    const position = url.searchParams.get('position') || 'home_hero';
    const isPreview = url.pathname.endsWith('/preview');
    const replacement = (isPreview ? mocks.preview : mocks.rows)?.[position];
    if (!replacement) return route.fallback();
    const response = await route.fetch();
    const body = await response.json();
    body.data.data = replacement;
    if (!isPreview) {
      body.data.pagination = { ...(body.data.pagination ?? {}), total: replacement.length, current_page: 1, last_page: 1 };
      body.data.statistics = { ...(body.data.statistics ?? {}), positions: { ...(body.data.statistics?.positions ?? {}), [position]: replacement.length } };
    }

    return route.fulfill({ response, json: body });
  });
  await page.setViewportSize({ width: 1440, height: 900 });

  return { writes, swap, pageErrors };
}

/**
 * 수정 전 대조가 켜져 있으면 실제로 바꿔 끼웠는지 단언한다.
 *
 * @param swap 바꿔 끼운 기록
 * @param layout 이 화면 레이아웃 이름
 */
function expectSwapped(swap: HeadSwap | null, layout: string): void {
  if (!HEAD_DIR || !swap) return;
  expect(swap.errors, '수정 전 판 바꿔 끼우기 오류 없음').toEqual([]);
  expect(swap.bundle, '수정 전 히어로 번들이 쓰였다').toBeGreaterThan(0);
  expect(swap.layouts[layout], `수정 전 ${layout} 레이아웃이 쓰였다`).toBeGreaterThan(0);
}

/** 목록 미리보기 카드 */
const previewCard = (page: Page) => page.locator('section[aria-labelledby="hero_preview_heading"]');

/** 미리보기 iframe 안 */
const previewFrame = (page: Page) => previewCard(page).frameLocator('.hs-preview__viewport iframe').first();

test.describe('관리자 히어로섹션 화면 — 미리보기 다국어 칸·오류 경계·언어 이름·링크 예시', () => {
  let token = '';

  test.beforeAll(async ({ request }) => {
    token = await resolveToken(request);
    const probe = await request.get(`${API}/banners?position=home_hero`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
    test.skip(probe.status() === 404, '히어로섹션 플러그인이 설치·활성화되지 않아 건너뛴다');
    expect(probe.status(), '목록 조회').toBe(200);
  });

  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  // @scenario surface=list_preview_localizes_all_multilingual_fields
  // @effects preview_survives_multilingual_fields
  test('머리글이 언어별 원본인 배너가 노출 중이어도 목록 미리보기는 머리글을 글자로 그리고, 「컴포넌트 로드 실패」·pageerror 가 없다', async ({ page }) => {
    const { writes, swap, pageErrors } = await prepare(page, token, {
      preview: { home_hero: [detailBanner(990701, { eyebrow: { ko: 'E2E 머리글 한 줄', en: 'E2E eyebrow line' }, eyebrow_visible: true })] },
    });
    await page.goto(`${LIST}?position=home_hero`, { waitUntil: 'domcontentloaded' });

    await expect(previewFrame(page).locator('.hs-slider__eyebrow'), '미리보기 슬라이드의 머리글').toHaveText('E2E 머리글 한 줄', { timeout: 45_000 });
    await expect(previewFrame(page).locator('h2.hs-slider__title')).toHaveText('E2E 미리보기 배너');
    await page.waitForTimeout(1_000);
    await expect(page.getByText(ENGINE_BOX), '엔진 오류 박스 없음').toHaveCount(0);
    await expect(previewCard(page).locator('[data-hs-preview-error]'), '미리보기 자체 오류 안내 없음').toHaveCount(0);
    expectSwapped(swap, 'hero_section_banner_list');
    expect(pageErrors, 'pageerror 0 (React #31 없음)').toEqual([]);
    expect(writes.filter((w) => w.includes('sirsoft-hero_section')), '히어로섹션 쓰기 0').toEqual([]);
  });

  // @scenario surface=list_preview_error_contained_and_recovers_on_tab
  // @effects preview_error_stays_in_preview
  test('미리보기 안에서 렌더가 깨지면 그 자리에만 안내가 뜨고, [쇼핑몰 메인] 탭이면 쇼핑 배너로 다시 그린다', async ({ page }) => {
    // 깨뜨리기: 「글자 하나씩」 효과의 제목은 글자여야 한다 — 숫자 제목은 렌더 중 `text.split is not a function` 으로 던진다(시험용 고장 주입)
    const broken = detailBanner(990702, { title_localized: 20261104, presentation: { tone: 'dark', overlay: 'none', animation: 'letters' } });
    const { writes, swap, pageErrors } = await prepare(page, token, {
      preview: { home_hero: [broken], shop_hero: [detailBanner(990703, { position: 'shop_hero', title_localized: 'E2E 쇼핑 미리보기' })] },
    });
    await page.goto(`${LIST}?position=home_hero`, { waitUntil: 'domcontentloaded' });

    const notice = previewCard(page).locator('[data-hs-preview-error]');
    await expect(notice, '홈: 미리보기 자리의 안내').toHaveText('미리보기를 그리지 못했습니다. 배너 문구를 확인한 뒤 다시 열어 주세요.', { timeout: 45_000 });
    await expect(page.getByText(ENGINE_BOX), '홈: 엔진 오류 박스 없음(미리보기 밖으로 번지지 않음)').toHaveCount(0);
    await expect(page.locator('nav a[href*="position=shop_hero"]').first(), '위치 탭은 그대로 쓸 수 있다').toBeVisible();

    await page.locator('nav a[href*="position=shop_hero"]').first().click();
    await expect(previewFrame(page).locator('h2.hs-slider__title'), '쇼핑: 미리보기가 쇼핑 배너로 다시 그려진다').toHaveText('E2E 쇼핑 미리보기', { timeout: 30_000 });
    await expect(notice, '쇼핑: 안내가 사라진다').toHaveCount(0);
    await expect(page.getByText(ENGINE_BOX)).toHaveCount(0);

    await page.locator('nav a[href*="position=home_hero"]').first().click();
    await expect(notice, '홈 복귀: 다시 그 자리에만 안내').toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(ENGINE_BOX)).toHaveCount(0);
    expectSwapped(swap, 'hero_section_banner_list');
    expect(pageErrors, 'pageerror 0 (경계가 받은 오류는 페이지 오류로 새지 않는다)').toEqual([]);
    expect(writes.filter((w) => w.includes('sirsoft-hero_section')), '히어로섹션 쓰기 0').toEqual([]);
  });

  // @scenario surface=list_badge_names_language
  // @effects languages_named_like_tabs
  test('목록의 「제목 없음」 배지는 언어 탭과 같은 언어 이름(English)으로 보인다', async ({ page }) => {
    const title = 'E2E 배지 배너';
    const { swap, pageErrors } = await prepare(page, token, { rows: { home_hero: [listRow(990704, title)] } });
    const settings = page.waitForResponse((r) => r.url().includes(`${API}/position-settings`) && r.status() === 200, { timeout: 60_000 });
    await page.goto(`${LIST}?position=home_hero`, { waitUntil: 'domcontentloaded' });
    const locales: string[] = (await (await settings).json())?.data?.editor?.locales ?? [];
    test.skip(!locales.includes('en'), '사이트 언어에 en 이 없어 배지 언어를 잴 수 없다');
    // 언어 이름 표는 셸이 심은 설정(`#g7-config`)에서 읽는다 — 화면의 `_global.appConfig.localeNames` 와 같은 출처
    const names: Record<string, string> = await page.evaluate(() => {
      const find = (node: any): any => {
        if (!node || typeof node !== 'object') return null;
        if (node.localeNames && typeof node.localeNames === 'object') return node.localeNames;
        for (const value of Object.values(node)) {
          const hit = find(value);
          if (hit) return hit;
        }
        return null;
      };
      try {
        return find(JSON.parse(document.getElementById('g7-config')?.textContent ?? '{}')) ?? {};
      } catch {
        return {};
      }
    });

    const row = page.locator('tr', { hasText: title });
    await expect(row).toBeVisible({ timeout: 45_000 });
    await expect(row.getByText('English 제목 없음', { exact: true }), 'en 배지는 언어 이름').toBeVisible();
    await expect(row.getByText('EN 제목 없음', { exact: true }), '코드 대문자 배지 없음').toHaveCount(0);
    for (const code of locales.filter((l) => l !== 'ko')) {
      const label = names[code] || code.toUpperCase();
      await expect(row.getByText(`${label} 제목 없음`, { exact: true }), `${code} 배지`).toBeVisible();
    }
    expectSwapped(swap, 'hero_section_banner_list');
    expect(pageErrors, 'pageerror 0').toEqual([]);
  });

  // @scenario surface=form_preview_language_names, surface=form_link_example_path_exists
  // @effects languages_named_like_tabs
  test('수정 화면의 미리보기 언어 선택과 「기본 언어로 대신 표시」 안내는 언어 이름을 쓰고, 링크 칸 예시는 /shop/products 다', async ({ page }) => {
    const { writes, swap, pageErrors } = await prepare(page, token, {});
    // 미리보기는 PC 이미지가 있어야 그려진다(새 등록은 업로드 = 쓰기). 한국어 제목만 있는 기존 배너를 여는 것으로 만든다
    await page.route((url) => url.pathname === `${API}/banners/${EDIT_ID}`, (route) => route.request().method() === 'GET'
      ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: '', data: detailBanner(EDIT_ID, {
        title: { ko: 'E2E 기본 언어 제목' }, title_localized: 'E2E 기본 언어 제목', revision: 1, starts_at_local: null, ends_at_local: null,
        abilities: { can_update: true, can_delete: true },
      }) }) })
      : route.fallback());
    await page.goto(EDIT, { waitUntil: 'domcontentloaded' });

    const select = page.locator('.hs-editor-language select');
    await expect(select, '미리보기 언어 선택').toBeVisible({ timeout: 45_000 });
    // 선택지는 위치 설정 응답(사이트 언어 목록)이 온 뒤 채워진다
    await expect.poll(() => select.locator('option').count(), { message: '미리보기 언어 선택지가 채워진다', timeout: 30_000 }).toBeGreaterThan(1);
    const options = await select.locator('option').evaluateAll((els) => els.map((el) => [(el as HTMLOptionElement).value, el.textContent?.trim() ?? '']));
    console.log(`[admin-preview-locale] 미리보기 언어 선택지 ${JSON.stringify(options)}`);
    test.skip(!options.some(([v]) => v === 'en'), '사이트 언어에 en 이 없어 언어 이름을 잴 수 없다');
    expect(Object.fromEntries(options), '선택지는 언어 이름').toMatchObject({ ko: '한국어', en: 'English' });

    await expect(page.locator('.hs-editor-preview .hs-preview__viewport iframe, .hs-preview__viewport iframe').first(), '미리보기가 그려진다').toBeAttached({ timeout: 30_000 });
    await select.selectOption('en');
    const fallback = page.locator('.hs-preview__fallback');
    await expect(fallback, '영어로 미리 보며 영어 제목이 비면 대체 안내').toBeVisible({ timeout: 20_000 });
    await expect(fallback).toContainText('기본 언어(한국어)');

    await expect(page.locator('input[name="link_url"]'), '링크 칸 예시').toHaveAttribute('placeholder', '/shop/products 또는 https://example.com');
    if (HEAD_DIR && swap) {
      expect(swap.errors).toEqual([]);
      expect(swap.bundle).toBeGreaterThan(0);
      expect(swap.layouts.hero_section_banner_form, '수정 전 등록 레이아웃이 쓰였다').toBeGreaterThan(0);
      expect(swap.lang, '수정 전 언어 문구가 쓰였다').toBeGreaterThan(0);
    }
    expect(pageErrors, 'pageerror 0').toEqual([]);
    expect(writes.filter((w) => w.includes('sirsoft-hero_section/admin/banners')), '배너 저장 요청 0').toEqual([]);
  });
});
