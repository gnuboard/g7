/**
 * E2E: 동적 위치 대상별 슬라이더 설정·프로모션형 배너 — 실제 저장 → 새로고침 → 공개 출력
 *
 * @scenario-manifest tests/scenarios/target-settings.yaml
 * @effects public_output_follows_target_override, target_cache_invalidated_only_for_that_target
 *
 * 개발 사이트의 배너 없는 게시판 둘(공지·갤러리)을 대상 A·B 로 쓴다. 시작 전에 두 대상에 재정의가 없음을 확인하고(있으면 운영 값이라
 * 건드리지 않고 멈춘다), 끝나면 시험이 만든 재정의를 지워(DELETE) 「행 없음」으로 정확히 되돌린다. 배너는 사양이 만들고 지운다.
 * 위치 기본값은 바꾸지 않는다 — 기대값은 그때의 위치 기본값을 읽어 비교한다. 위치 기본값을 바꿨을 때의 상속은 PHPUnit 이 잰다.
 */
import { test, expect, auth, authenticatePage, createBanner, gotoUser, hidePopups, uniqueTitle, ADMIN_LIST, API, VIEWPORTS } from '../fixtures/hero-auth';
import type { APIRequestContext, Browser, BrowserContext, BrowserContextOptions, Page } from '@playwright/test';

test.setTimeout(240_000);

const POSITION = 'board_hero';
const A = { id: '1', slug: 'notice' };
const B = { id: '3', slug: 'gallery' };

type Settings = { autoplay: boolean; interval_seconds: number; effect: string; width: string; header_overlay: boolean; pagination: string; max_height_pc: number | null; max_height_mobile: number | null };

const targetUrl = (id: string) => `${API}/admin/position-settings/${POSITION}/targets/${encodeURIComponent(id)}`;

/**
 * 대상 설정 상세(위치 기본값·재정의·최종값·출처)를 관리자 API 로 읽는다.
 *
 * @param request API 요청 컨텍스트
 * @param token 관리자 토큰
 * @param id 대상 키
 * @returns 상세
 */
async function detail(request: APIRequestContext, token: string, id: string): Promise<{ overrides: Partial<Settings>; settings: Settings; position_settings: Settings; sources: Record<string, string> }> {
  const response = await request.get(targetUrl(id), { headers: auth(token) });
  expect(response.status(), await response.text()).toBe(200);

  return (await response.json()).data;
}

/**
 * 공개 API 가 그 대상에 내는 슬라이더 설정.
 *
 * @param request API 요청 컨텍스트
 * @param slug 화면 값(게시판 slug)
 * @returns 설정
 */
async function publicSettings(request: APIRequestContext, slug: string): Promise<Settings> {
  const response = await request.get(`${API}/hero?position=${POSITION}&target=${slug}`, { headers: { Accept: 'application/json' } });
  expect(response.status()).toBe(200);

  return (await response.json()).data.settings;
}

/**
 * 게시판 화면을 열고 그 대상의 공개 응답·슬라이더가 붙을 때까지 기다린 뒤 슬라이더의 설정 흔적을 잰다.
 *
 * @param page 페이지
 * @param slug 게시판 slug
 * @returns 효과·폭·페이징 클래스와 최대 높이 변수
 */
async function openBoard(page: Page, slug: string): Promise<{ effect: string | null; full: boolean; pagination: string | null; maxPc: string; maxMobile: string }> {
  const response = page.waitForResponse((r) => r.url().includes(`${API}/hero`) && r.url().includes(`target=${slug}`), { timeout: 60_000 });
  await gotoUser(page, `/board/${slug}`);
  await response;

  return readSlider(page);
}

/**
 * 지금 화면의 슬라이더 클래스·변수를 읽는다.
 *
 * @param page 페이지
 * @returns 효과·폭·페이징 클래스와 최대 높이 변수
 */
async function readSlider(page: Page): Promise<{ effect: string | null; full: boolean; pagination: string | null; maxPc: string; maxMobile: string }> {
  const section = page.locator('section.hs-slider[role="region"]').first();
  await expect(section).toBeAttached({ timeout: 30_000 });

  return section.evaluate((el) => {
    const classes = [...el.classList];
    const style = (el as HTMLElement).style;

    return {
      effect: classes.find((c) => /^hs-slider--(slide|fade|cube|flip|coverflow|creative|none)$/.test(c))?.replace('hs-slider--', '') ?? null,
      full: classes.includes('hs-slider--full'),
      pagination: classes.find((c) => c.startsWith('hs-slider--pagination-'))?.replace('hs-slider--pagination-', '') ?? null,
      maxPc: style.getPropertyValue('--hs-max-height-pc'),
      maxMobile: style.getPropertyValue('--hs-max-height-mobile'),
    };
  });
}

/**
 * 게스트·밝기별 새 창을 연다 — `browser.newContext()` 는 설정 파일의 `use`(기본 주소·일반 Chrome UA)를 물려받지 않는다.
 * UA 가 HeadlessChrome 이면 사이트가 SEO HTML 을 내 히어로 조각이 공개 API 를 부르지 않는다.
 *
 * @param browser 브라우저
 * @param extra 더할 창 옵션
 * @returns 새 창
 */
async function siteContext(browser: Browser, extra: BrowserContextOptions = {}): Promise<BrowserContext> {
  const { baseURL, userAgent, ignoreHTTPSErrors, locale } = test.info().project.use;

  return browser.newContext({ baseURL, userAgent, ignoreHTTPSErrors, locale, ...extra });
}

const LABELS: Record<string, Record<string, string>> = {
  effect: { slide: '슬라이드', fade: '페이드' },
  width: { content: '본문 폭에 맞춤', full: '화면 전체 폭' },
  pagination: { dots: '점', tabs: '배너 이름 탭', pill: '알약 번호 (‹ 1 / 5 ›)' },
};

/**
 * 관리 화면 선택 칸(단추 + 목록)에서 값을 고른다.
 *
 * @param page 페이지
 * @param key 설정 항목
 * @param value 고를 값
 */
async function choose(page: Page, key: string, value: string): Promise<void> {
  await page.locator(`#hero_target_${key} button`).first().click();
  await page.getByRole('option', { name: LABELS[key][value], exact: true }).click();
}

const px = (value: number | null) => (value === null ? '' : `${value}px`);

/**
 * 공개 슬라이더의 흔적을 기대 설정과 비교할 모양으로 바꾼다.
 *
 * @param settings 기대 설정
 * @returns 비교 값
 */
const expected = (settings: Settings) => ({ effect: settings.effect, full: settings.width === 'full', pagination: settings.pagination, maxPc: px(settings.max_height_pc), maxMobile: px(settings.max_height_mobile) });

test.describe('대상별 슬라이더 설정', () => {
  // 이 사양이 재정의를 만든 대상 — 끝나면 지운다(시작 때 재정의가 없던 대상만 담는다)
  const touched = new Set<string>();

  test.beforeEach(async ({ page, request, managerToken }) => {
    await hidePopups(page);
    await page.setViewportSize(VIEWPORTS.desktop);
    for (const target of [A, B]) {
      const current = await detail(request, managerToken, target.id);
      // 운영자가 둔 재정의가 있으면 덮지 않는다 — 정확히 되돌릴 수 없는 시험은 하지 않는다
      expect(current.overrides, `${target.slug} 에 기존 재정의가 있어 시험하지 않는다`).toEqual({});
      touched.add(target.id);
    }
  });

  test.afterEach(async ({ request, managerToken }) => {
    for (const id of touched) {
      const response = await request.delete(targetUrl(id), { headers: auth(managerToken) });
      expect(response.status()).toBe(200);
      expect((await detail(request, managerToken, id)).overrides).toEqual({});
    }
    touched.clear();
  });

  // @scenario surface=target_settings_isolated_per_target
  // @effects public_output_follows_target_override, target_cache_invalidated_only_for_that_target
  test('관리 화면에서 A 만 효과·폭·페이징·높이·자동 재생을 따로 저장하면 새로고침 뒤에도 남고, 공개 화면은 A 만 바뀌며 B 는 위치 기본값이다(A→B→A 이동 포함)', async ({ page, request, managerToken, created }) => {
    for (const target of [A, B]) {
      for (const n of [1, 2]) {
        created.push(await createBanner(request, managerToken, { position: POSITION, target_key: target.id, title: { ko: uniqueTitle(`대상설정 ${target.slug} ${n}`) } }));
      }
    }
    const base = (await detail(request, managerToken, A.id)).position_settings;
    // 위치 기본값과 다른 값을 고른다 — 같으면 재정의가 적용됐는지 화면에서 구분되지 않는다
    const want = {
      effect: base.effect === 'fade' ? 'slide' : 'fade',
      width: base.width === 'full' ? 'content' : 'full',
      pagination: base.pagination === 'tabs' ? 'dots' : 'tabs',
      max_height_pc: base.max_height_pc === 320 ? 360 : 320,
      autoplay: false,
    };
    // 공개 캐시를 먼저 채운다 — 저장이 A 의 캐시만 비우는지 본다
    const beforeB = await publicSettings(request, B.slug);
    await publicSettings(request, A.slug);

    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}?position=${POSITION}&target=${A.id}`);
    const card = page.locator('section[aria-labelledby="hero_target_settings_heading"]');
    await expect(card).toBeVisible({ timeout: 60_000 });
    await expect(card.getByRole('button', { name: '이 대상 설정 저장' })).toBeDisabled();
    for (const key of ['effect', 'width', 'pagination', 'max_height_pc', 'autoplay']) {
      await expect(page.locator(`#hero_target_inherit_${key}`)).not.toBeChecked();
      await page.locator(`#hero_target_inherit_${key}`).check();
    }
    await choose(page, 'effect', want.effect);
    await choose(page, 'width', want.width);
    await choose(page, 'pagination', want.pagination);
    await page.locator('#hero_target_max_height_pc').fill(String(want.max_height_pc));
    await page.locator('#hero_target_max_height_pc').blur();
    await page.locator('#hero_target_autoplay').uncheck();

    const saved = page.waitForResponse((r) => r.url().includes(`/targets/${A.id}`) && r.request().method() === 'PUT');
    await card.getByRole('button', { name: '이 대상 설정 저장' }).click();
    const response = await saved;
    expect(response.status(), await response.text()).toBe(200);
    expect(JSON.parse(response.request().postData() ?? '{}').overrides).toEqual(want);
    touched.add(A.id);

    // 새로고침해도 켠 항목·값·출처가 남는다
    await page.reload();
    await expect(card).toBeVisible({ timeout: 60_000 });
    for (const key of ['effect', 'width', 'pagination', 'max_height_pc', 'autoplay']) {
      await expect(page.locator(`#hero_target_inherit_${key}`)).toBeChecked();
      await expect(page.locator(`#hero_target_source_${key}`)).toHaveText('이 대상');
    }
    await expect(page.locator('#hero_target_source_interval_seconds')).toHaveText('위치 기본값');
    await expect(page.locator('#hero_target_effect button').first()).toContainText(LABELS.effect[want.effect]);
    await expect(page.locator('#hero_target_max_height_pc')).toHaveValue(String(want.max_height_pc));

    // DB·API — A 는 재정의, B 는 그대로, 위치 기본값은 그대로
    const afterA = await detail(request, managerToken, A.id);
    expect(afterA.overrides).toEqual(want);
    expect(afterA.position_settings).toEqual(base);
    expect((await detail(request, managerToken, B.id)).overrides).toEqual({});
    const publicA = await publicSettings(request, A.slug);
    expect({ ...publicA }).toEqual({ ...base, ...want });
    expect(await publicSettings(request, B.slug)).toEqual(beforeB);

    // 공개 화면 — A 는 재정의, B 는 위치 기본값, SPA 로 A→B→A 를 옮겨도 섞이지 않는다
    const guest = await siteContext(page.context().browser()!);
    const site = await guest.newPage();
    await hidePopups(site);
    await site.setViewportSize(VIEWPORTS.desktop);
    expect(await openBoard(site, A.slug)).toEqual(expected({ ...base, ...want }));
    const go = async (slug: string) => {
      const pending = site.waitForResponse((r) => r.url().includes(`${API}/hero`) && r.url().includes(`target=${slug}`), { timeout: 60_000 });
      await site.evaluate((path) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path } }), `/board/${slug}`);
      await site.waitForURL(`**/board/${slug}`);
      await pending;
      await expect(site.locator('section.hs-slider[role="region"] .hs-slider__title', { hasText: `대상설정 ${slug}` }).first()).toBeAttached({ timeout: 30_000 });

      return readSlider(site);
    };
    expect(await go(B.slug)).toEqual(expected(base));
    expect(await go(A.slug)).toEqual(expected({ ...base, ...want }));
    // 관리자 조회 직후라도 게스트 화면에는 관리자 수정 단추가 없다
    await expect(site.locator('.hs-slider__edit-layer')).toHaveCount(0);
    await guest.close();
  });

  // @scenario surface=target_settings_reset
  test('항목 하나를 상속으로 되돌려 저장하면 그 항목만 위치 기본값이 되고, 「위치 기본값으로 되돌리기」는 재정의를 지워 공개 화면이 위치 기본값이 된다', async ({ page, request, managerToken, created }) => {
    created.push(await createBanner(request, managerToken, { position: POSITION, target_key: A.id, title: { ko: uniqueTitle('상속복귀 1') } }));
    created.push(await createBanner(request, managerToken, { position: POSITION, target_key: A.id, title: { ko: uniqueTitle('상속복귀 2') } }));
    const base = (await detail(request, managerToken, A.id)).position_settings;
    const effect = base.effect === 'fade' ? 'slide' : 'fade';
    const pagination = base.pagination === 'tabs' ? 'dots' : 'tabs';
    const put = await request.put(targetUrl(A.id), { headers: auth(managerToken), data: { overrides: { effect, pagination, max_height_mobile: null } } });
    expect(put.status(), await put.text()).toBe(200);
    touched.add(A.id);

    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}?position=${POSITION}&target=${A.id}`);
    const card = page.locator('section[aria-labelledby="hero_target_settings_heading"]');
    await expect(card).toBeVisible({ timeout: 60_000 });
    await expect(page.locator('#hero_target_inherit_effect')).toBeChecked();
    await page.locator('#hero_target_inherit_effect').uncheck();
    await expect(page.locator('#hero_target_source_effect')).toHaveText('위치 기본값');
    await expect(page.locator('#hero_target_effect button').first()).toContainText(LABELS.effect[base.effect] ?? base.effect);
    await expect(page.locator('#hero_target_effect button').first()).toBeDisabled();

    const saved = page.waitForResponse((r) => r.url().includes(`/targets/${A.id}`) && r.request().method() === 'PUT');
    await card.getByRole('button', { name: '이 대상 설정 저장' }).click();
    expect((await saved).status()).toBe(200);
    await page.reload();
    await expect(card).toBeVisible({ timeout: 60_000 });
    await expect(page.locator('#hero_target_inherit_effect')).not.toBeChecked();
    await expect(page.locator('#hero_target_inherit_pagination')).toBeChecked();
    // null(제한 없음) 명시값은 상속과 다르게 남는다
    await expect(page.locator('#hero_target_inherit_max_height_mobile')).toBeChecked();
    expect((await detail(request, managerToken, A.id)).overrides).toEqual({ pagination, max_height_mobile: null });

    const guest = await siteContext(page.context().browser()!);
    const site = await guest.newPage();
    await hidePopups(site);
    await site.setViewportSize(VIEWPORTS.desktop);
    expect(await openBoard(site, A.slug)).toEqual(expected({ ...base, pagination, max_height_mobile: null }));

    // 전체 되돌리기 — DELETE 후 상세·공개 응답·화면 모두 위치 기본값
    const reset = page.waitForResponse((r) => r.url().includes(`/targets/${A.id}`) && r.request().method() === 'DELETE');
    await card.getByRole('button', { name: '위치 기본값으로 되돌리기' }).click();
    expect((await reset).status()).toBe(200);
    for (const key of ['effect', 'pagination', 'max_height_mobile']) {
      await expect(page.locator(`#hero_target_source_${key}`)).toHaveText('위치 기본값');
    }
    await expect(card.getByRole('button', { name: '위치 기본값으로 되돌리기' })).toBeDisabled();
    const after = await detail(request, managerToken, A.id);
    expect(after.overrides).toEqual({});
    expect(after.settings).toEqual(base);
    expect(await publicSettings(request, A.slug)).toEqual(base);
    expect(await openBoard(site, A.slug)).toEqual(expected(base));
    await guest.close();
  });
});

test.describe('프로모션형 배너 화면 폭·밝기', () => {
  const widths = [390, 768, 1440, 2400];

  for (const scheme of ['light', 'dark'] as const) {
    // @scenario surface=promo_layout_render
    test(`${scheme}: 프로모션형은 PC 에서 문구 왼쪽·그림 오른쪽, 휴대폰에서 그림 위·문구 아래로 겹치지 않고, 두 줄 제목이 잘리지 않으며 가로 넘침이 없다`, async ({ browser, request, managerToken, created }, testInfo) => {
      // 폭 4개를 새로 연다 — 개발 사이트 첫 로딩이 느리다
      test.setTimeout(600_000);
      const title = uniqueTitle('프로모션');
      created.push(await createBanner(request, managerToken, {
        position: POSITION,
        target_key: A.id,
        title: { ko: `${title}\n둘째 줄 제목` },
        title_color: '#111827',
        description: { ko: '설명 문구 한 줄' },
        description_color: '#374151',
        presentation: { layout: 'promo', background_color: '#F4F5F8', text_pc_x: 0, text_pc_y: 50, text_mobile_x: 0, text_mobile_y: 100, text_width_pc: 40, text_width_mobile: 100, overlay: 'none', tone: 'light', animation: 'sequence', animation_speed: 'normal', animation_delay: 0, media_animation: 'right' },
      }));
      created.push(await createBanner(request, managerToken, { position: POSITION, target_key: A.id, title: { ko: uniqueTitle('프로모션 둘째') }, title_color: '#111827', presentation: { layout: 'promo', background_color: '#F4F5F8' } }));

      const context = await siteContext(browser, { colorScheme: scheme });
      await context.addInitScript((value) => { try { localStorage.setItem('g7_color_scheme', value); } catch { /* 무시 */ } }, scheme);
      const page = await context.newPage();
      await hidePopups(page);
      const errors: string[] = [];
      page.on('pageerror', (error) => errors.push(error.message));
      page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
      // 라이트에서는 영어·일본어 화면도 한 폭씩 본다(배너 문구는 한국어만이라 기본 언어로 대체되고, 화면 문구·컨트롤 이름이 바뀐다)
      const cases = [...widths.map((width) => ({ width, locale: 'ko' })), ...(scheme === 'light' ? [{ width: 390, locale: 'en' }, { width: 1440, locale: 'ja' }] : [])];
      for (const { width, locale } of cases) {
        await page.setViewportSize({ width, height: width < 768 ? 844 : 1000 });
        if (locale !== 'ko') await page.evaluate((value) => { try { localStorage.setItem('g7_locale', value); } catch { /* 무시 */ } }, locale);
        // 같은 주소를 다시 열면 공개 응답을 브라우저 캐시가 줄 수 있어 응답 대신 슬라이드를 기다린다.
        // 개발 사이트가 드물게 첫 화면을 못 그리면(빈 화면) 한 번만 다시 연다 — 오류는 아래에서 따로 단언한다
        const slide = page.locator('section.hs-slider [data-index="0"]').first();
        for (let attempt = 0; ; attempt += 1) {
          await gotoUser(page, `/board/${A.slug}`);
          try {
            await expect(slide.locator('.hs-promo__text')).toBeAttached({ timeout: 45_000 });
            break;
          } catch (error) {
            if (attempt > 0) throw error;
            testInfo.annotations.push({ type: 'retry-open', description: `${scheme} ${width}px ${locale}` });
          }
        }
        // 순차 등장(최대 0.6 + 0.4초)·그림 등장(0.8초)이 끝난 뒤 잰다
        await page.waitForTimeout(1500);
        const m = await slide.evaluate((el) => {
          const box = (s: string) => el.querySelector(s)?.getBoundingClientRect() ?? null;
          const titleEl = el.querySelector('.hs-slider__title') as HTMLElement | null;
          const text = box('.hs-promo__text')!;
          const media = box('.hs-slider__media') ?? box('img');

          return {
            text: { left: text.left, right: text.right, top: text.top, bottom: text.bottom },
            media: media ? { left: media.left, right: media.right, top: media.top, bottom: media.bottom } : null,
            titleLines: titleEl ? Math.round(titleEl.getBoundingClientRect().height / parseFloat(getComputedStyle(titleEl).lineHeight)) : 0,
            titleClipped: titleEl ? titleEl.scrollHeight > titleEl.clientHeight + 1 || titleEl.scrollWidth > titleEl.clientWidth + 1 : true,
            titleShadow: titleEl ? getComputedStyle(titleEl).textShadow : '',
            background: getComputedStyle(el).getPropertyValue('--hs-slide-bg') || (el as HTMLElement).style.getPropertyValue('--hs-slide-bg'),
            overflow: document.documentElement.scrollWidth - window.innerWidth,
            dark: document.documentElement.classList.contains('dark'),
            lang: document.documentElement.lang,
          };
        });
        // 컨트롤(알약 번호·일시정지)이 보이는 장의 글 상자와 겹치지 않는다 — 문구 칸(.hs-promo__text)은 아래에 컨트롤 자리
        // (padding-bottom)를 품고 있어 칸 자체는 컨트롤과 겹치는 것이 정상이고, 글이 그 자리까지 내려오지 않는지를 본다
        const controls = await page.evaluate(() => {
          const rect = (el: Element | null) => (el ? el.getBoundingClientRect() : null);
          const text = rect(document.querySelector('section.hs-slider .hs-slider__slide--active .hs-slider__textbox'));
          const hits = (other: DOMRect | null) => !!(text && other && other.width > 0 && !(other.right <= text.left || other.left >= text.right || other.bottom <= text.top || other.top >= text.bottom));

          return { pill: hits(rect(document.querySelector('section.hs-slider .hs-slider__pill'))), toggle: hits(rect(document.querySelector('section.hs-slider [data-hs-control="toggle"]'))), active: !!text };
        });
        const label = `${scheme} ${width}px ${locale} ${JSON.stringify(m)} ${JSON.stringify(controls)}`;
        expect(m.lang, label).toMatch(new RegExp(`^${locale}`));
        expect(controls, label).toEqual({ pill: false, toggle: false, active: true });
        expect(m.overflow, label).toBeLessThanOrEqual(0);
        expect(m.titleLines, label).toBeGreaterThanOrEqual(2);
        expect(m.titleClipped, label).toBe(false);
        expect(m.titleShadow, label).toBe('none');
        expect(m.background.trim().toUpperCase(), label).toBe('#F4F5F8');
        expect(m.dark, label).toBe(scheme === 'dark');
        expect(m.media, label).not.toBeNull();
        if (width < 768) {
          // 휴대폰 — 그림 위, 문구 아래(겹치지 않음)
          expect(m.media!.bottom, label).toBeLessThanOrEqual(m.text.top + 1);
        } else {
          // PC·태블릿 — 문구 왼쪽, 그림 오른쪽(겹치지 않음)
          expect(m.text.right, label).toBeLessThanOrEqual(m.media!.left + 1);
        }
        await page.screenshot({ path: testInfo.outputPath(`promo-${scheme}-${width}-${locale}.png`) });
      }
      expect(errors.filter((text) => /hero|hs-|sirsoft-hero_section/i.test(text)), errors.join('\n')).toEqual([]);
      await context.close();
    });
  }
});
