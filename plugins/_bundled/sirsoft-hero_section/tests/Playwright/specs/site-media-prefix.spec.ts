/**
 * 사용자 화면 — 하위 폴더 설치에서 히어로 이미지·모바일 이미지·영상·대표 이미지 주소에 사이트 마운트 접두사가 붙는다 (W7-006)
 *
 * 화면 기준: 사이트가 `https://host/g7/` 에 설치돼 있으면 배너 이미지·영상은 `/g7/api/plugins/sirsoft-hero_section/images/{hash}` 에서
 *   받아야 한다. 접두사 없는 `/api/…` 는 사이트 밖(문서 루트의 다른 앱)이라 깨진다. 서버가 접두사를 붙이기 전에 담긴 공개 캐시처럼 응답에
 *   접두사가 없어도 슬라이더가 붙이고(`withSiteMedia`), 이미 붙은 값에는 두 번 붙이지 않는다.
 *
 * 하위 폴더 흉내(개발 서버는 루트 설치다): 브라우저 주소 `/g7/…` 요청은 `/g7` 를 벗겨 실제 서버에 묻고, 첫 문서의 `basePath` 만 `/g7` 로
 *   바꾼다. 엔진·확장은 이 값으로 접두사를 붙인다. 접두사 없는 히어로 미디어 요청은 404(사이트 밖)로, 접두사 붙은 요청은 1x1 PNG·짧은
 *   WEBM 으로 응답한다. 공개 히어로 응답(`/hero?position=home_hero`)은 시험 배너로 fulfill 한다 — 개발 DB 를 바꾸지 않는다.
 *   GET·HEAD·OPTIONS 가 아닌 `/api/` 요청은 끊는다(fixtures/hero-head.ts `guardWrites`).
 *
 * 수정 전 대조: `G7_E2E_HERO_HEAD_DIR`(fixtures/hero-head.ts 머리 주석). 수정 전 번들에서는 이미지가 `/api/…` 로 나가 red 다.
 *
 * @scenario-manifest tests/scenarios/hero-media.yaml
 *
 * 실행(플러그인 디렉토리에서):
 *   PLAYWRIGHT_BASE_URL=https://<개발 사이트 주소> npm run test:e2e -- specs/site-media-prefix.spec.ts
 */
import { test, expect, type Page, type Route } from '@playwright/test';
import { guardWrites, useHeadHero, HEAD_DIR, PNG_1X1 } from '../fixtures/hero-head';

test.describe.configure({ timeout: 150_000 });

/** 흉내 낸 사이트 마운트 접두사 */
const MOUNT = '/g7';
const MEDIA = '/api/plugins/sirsoft-hero_section/images';

/** 브라우저 안에서 canvas 를 녹화해 짧은 WEBM 을 만든다(서버 인코더 불필요). */
async function recordWebm(page: Page, ms = 1_500): Promise<Buffer> {
  const base64 = await page.evaluate(async (duration) => {
    const canvas = document.createElement('canvas');
    canvas.width = 160;
    canvas.height = 90;
    const context = canvas.getContext('2d')!;
    const recorder = new MediaRecorder(canvas.captureStream(25), { mimeType: 'video/webm' });
    const chunks: Blob[] = [];
    recorder.ondataavailable = (event) => chunks.push(event.data);
    const draw = () => {
      context.fillStyle = `hsl(${(performance.now() / 5) % 360}, 70%, 50%)`;
      context.fillRect(0, 0, 160, 90);
      if (recorder.state === 'recording') requestAnimationFrame(draw);
    };
    recorder.start(100);
    draw();
    await new Promise((resolve) => setTimeout(resolve, duration));
    await new Promise((resolve) => { recorder.onstop = resolve; recorder.stop(); });
    const buffer = await new Blob(chunks, { type: 'video/webm' }).arrayBuffer();
    let binary = '';
    new Uint8Array(buffer).forEach((byte) => { binary += String.fromCharCode(byte); });

    return btoa(binary);
  }, ms);

  return Buffer.from(base64, 'base64');
}

/** 공개 응답 모양(`PublicBannerPresenter`) 배너 */
function publicBanner(id: number, title: string, overrides: Record<string, unknown>): Record<string, unknown> {
  return {
    id, title, description: '', button_text: '', image_alt: title, title_visible: true, title_color: '#FFFFFF', title_align: 'left',
    description_visible: false, description_color: '#E5E7EB', description_align: 'left', link_url: null, link_new_window: false,
    media_type: 'image', videos: null, presentation: null, ...overrides,
  };
}

test.describe('사용자 홈 — 하위 폴더 설치의 히어로 미디어 주소', () => {
  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  // @scenario surface=media_urls_carry_site_mount_prefix
  // @effects media_served_inside_site_mount
  test('접두사 없이 온 이미지·모바일 이미지·영상·대표 이미지 주소에 /g7 이 붙고, 이미 붙은 주소는 두 번 붙지 않으며, 사이트 밖 요청이 0건이다', async ({ page, context }) => {
    page.setDefaultTimeout(30_000);
    const probe = await page.request.get('/api/plugins/sirsoft-hero_section/hero?position=home_hero', { headers: { Accept: 'application/json' } });
    test.skip(probe.status() === 404, '히어로섹션 플러그인이 설치·활성화되지 않아 건너뛴다');

    const recorder = await context.newPage();
    const webm = await recordWebm(recorder);
    await recorder.close();

    const pageErrors: string[] = [];
    page.on('pageerror', (e) => pageErrors.push(String(e.message).split(/\r?\n/)[0].slice(0, 160)));
    const media: { inside: string[]; outside: string[] } = { inside: [], outside: [] };

    // 1. 하위 폴더 흉내 — /g7 을 벗겨 실제 서버에 묻는다(가장 먼저 등록 → 가장 나중에 탄다)
    await page.route((url) => url.pathname === MOUNT || url.pathname.startsWith(`${MOUNT}/`), async (route: Route) => {
      const url = new URL(route.request().url());
      url.pathname = url.pathname.slice(MOUNT.length) || '/';
      let response;
      try {
        response = await route.fetch({ url: url.toString() });
      } catch {
        return route.abort().catch(() => undefined);
      }
      if (route.request().resourceType() === 'document') {
        const html = (await response.text()).replace(/"basePath":""/g, `"basePath":"${MOUNT.replace(/\//g, '\\/')}"`);

        return route.fulfill({ response, body: html });
      }

      return route.fulfill({ response });
    });
    const writes = await guardWrites(page);
    const swap = await useHeadHero(page);

    // 2. 히어로 미디어 — 접두사 붙은 요청만 사이트 안
    await page.route((url) => url.pathname.startsWith(`${MOUNT}${MEDIA}/`), (route) => {
      const path = new URL(route.request().url()).pathname;
      media.inside.push(path);

      return path.endsWith('-vid')
        ? route.fulfill({ status: 200, contentType: 'video/webm', body: webm })
        : route.fulfill({ status: 200, contentType: 'image/png', body: PNG_1X1 });
    });
    await page.route((url) => url.pathname.startsWith(`${MEDIA}/`), (route) => {
      media.outside.push(new URL(route.request().url()).pathname);

      return route.fulfill({ status: 404, contentType: 'text/plain', body: 'outside the site mount' });
    });

    // 3. 공개 히어로 응답 — 첫 장 영상(접두사 없음, 대표 이미지도 접두사 없음), 둘째 장 이미지(이미 접두사 붙음)
    const hero = { count: 0 };
    await page.route((url) => /^(\/g7)?\/api\/plugins\/sirsoft-hero_section\/hero$/.test(url.pathname), (route) => {
      hero.count += 1;

      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: '', data: {
        position: 'home_hero',
        settings: { autoplay: false, interval_seconds: 5, effect: 'slide', width: 'content', header_overlay: false, pagination: 'dots' },
        banners: [
          publicBanner(990901, 'E2E 접두사 영상', {
            media_type: 'video',
            images: { pc: { url: `${MEDIA}/e2e-h-pc`, width: 1920, height: 640 }, mobile: { url: `${MEDIA}/e2e-h-mo`, width: 750, height: 1000 } },
            videos: { pc: { url: `${MEDIA}/e2e-h-vid`, mime: 'video/webm' }, mobile: null },
          }),
          publicBanner(990902, 'E2E 접두사 이미지', {
            images: { pc: { url: `${MOUNT}${MEDIA}/e2e-h-pc2`, width: 1920, height: 640 }, mobile: null },
          }),
        ],
      } }) });
    });

    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(`${MOUNT}/`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => (window as any).G7Config?.basePath === '/g7' && !!(window as any).G7Core, undefined, { timeout: 60_000 });
    await expect(page.locator('section.hs-slider[role="region"]'), '슬라이더').toBeVisible({ timeout: 60_000 });
    expect(hero.count, '공개 히어로 응답을 시험 배너로 바꿨다').toBeGreaterThan(0);

    const attrs = async () => page.evaluate(() => {
      const slider = document.querySelector('section.hs-slider');
      const all = (selector: string, attr: string) => [...(slider?.querySelectorAll(selector) ?? [])].map((el) => el.getAttribute(attr) ?? '').filter(Boolean);

      return {
        images: all('img.hs-slider__image', 'src'),
        sources: all('picture source', 'srcset'),
        videos: [...all('video', 'src'), ...all('video source', 'src')],
        posters: all('video', 'poster'),
        loaded: [...(slider?.querySelectorAll('img.hs-slider__image') ?? [])].map((img) => [img.getAttribute('src'), (img as HTMLImageElement).naturalWidth] as [string | null, number]),
      };
    });
    await expect.poll(async () => (await attrs()).images.length, { message: '슬라이드 이미지가 그려진다', timeout: 30_000 }).toBeGreaterThan(1);
    await expect.poll(async () => (await attrs()).videos.length, { message: '첫 장 영상이 붙는다', timeout: 30_000 }).toBeGreaterThan(0);
    console.log(`[site-media-prefix] 그려진 직후 ${JSON.stringify(await attrs())}`);
    // srcset(1920w 후보) 이 붙어 시험용 1×1 PNG 응답에서는 naturalWidth 가 0 일 수 있다 — 받았는지는 사이트 안 주소로 그 미디어를 요청했는지로 본다.
    await expect.poll(() => media.inside.some((p) => p.endsWith('e2e-h-pc') || p.includes('e2e-h-pc?')), { message: '첫 장 대표 이미지를 사이트 안 주소로 받았다', timeout: 30_000 }).toBe(true);
    const seen = await attrs();
    console.log(`[site-media-prefix] ${JSON.stringify({ ...seen, inside: [...new Set(media.inside)], outside: [...new Set(media.outside)] })}`);

    const everyUrl = [...seen.images, ...seen.sources, ...seen.videos, ...seen.posters];
    for (const url of everyUrl) {
      expect(url, `미디어 주소에 사이트 마운트 접두사: ${url}`).toMatch(/^\/g7\/api\/plugins\/sirsoft-hero_section\/images\//);
      expect(url, '접두사가 두 번 붙지 않는다').not.toContain('/g7/g7/');
    }
    expect(seen.images, 'PC 이미지(접두사 없이 온 값)').toContain(`${MOUNT}${MEDIA}/e2e-h-pc`);
    expect(seen.images, 'PC 이미지(이미 접두사 붙은 값)').toContain(`${MOUNT}${MEDIA}/e2e-h-pc2`);
    // 주 이미지는 창 폭 미디어(<picture><source media>)가 아니라 엔진 폭으로 고른다 — PC 폭에서는 <source> 가 없고,
    // 휴대폰 폭(767 이하)에서 img 가 모바일 이미지를 받는다.
    expect(seen.sources, 'PC 폭에서 창 폭 미디어 <source> 없음').toEqual([]);
    await page.setViewportSize({ width: 375, height: 800 });
    await expect.poll(async () => (await attrs()).images, { message: '휴대폰 폭에서 모바일 이미지', timeout: 30_000 }).toContain(`${MOUNT}${MEDIA}/e2e-h-mo`);
    const mobile = await attrs();
    for (const url of mobile.images) {
      expect(url, `휴대폰 폭 미디어 주소에 사이트 마운트 접두사: ${url}`).toMatch(/^\/g7\/api\/plugins\/sirsoft-hero_section\/images\//);
    }
    await page.setViewportSize({ width: 1366, height: 900 });
    expect(seen.videos, '영상').toContain(`${MOUNT}${MEDIA}/e2e-h-vid`);
    if (seen.posters.length) expect(seen.posters.every((p) => p.startsWith(`${MOUNT}${MEDIA}/`)), '영상 대표 이미지').toBe(true);
    expect([...new Set(media.outside)], '사이트 밖(접두사 없는) 히어로 미디어 요청 0').toEqual([]);
    expect(media.inside.some((p) => p.endsWith('e2e-h-vid')), '영상 파일을 사이트 안 주소로 받았다').toBe(true);

    if (HEAD_DIR && swap) {
      expect(swap.errors, '수정 전 판 바꿔 끼우기 오류 없음').toEqual([]);
      expect(swap.bundle, '수정 전 히어로 번들이 쓰였다').toBeGreaterThan(0);
    }
    expect(pageErrors, 'pageerror 0').toEqual([]);
    expect(writes.filter((w) => w.includes('sirsoft-hero_section')), '히어로섹션 쓰기 0').toEqual([]);
  });
});
