/**
 * 영상 배너: 영상 파일은 실브라우저에서 소리 없이 재생되고 끝나면 다음 장으로 넘어가며(간격보다 먼저),
 * 움직임 줄이기에서는 멈춘 채 시작해 단추로 재생한다. 유튜브 등 제3자 영상 플랫폼은 받지 않는다 — 옛 응답에 남은 유튜브 종류도
 * 대표 이미지로만 그리고 외부 플레이어를 부르지 않는다. 등록 화면은 미디어 종류에 따라 영상 칸과 저장 잠김 이유를 바꾼다.
 * @scenario-manifest tests/scenarios/hero-media.yaml
 *
 * 공개 API 응답은 `page.route` 로 홈 배너 앞에 시험 배너를 끼워 넣는다(운영 배너·설정은 건드리지 않는다). 시험 영상은 서버에
 * 인코더가 없어도 되도록 브라우저 안에서 canvas 를 2초 녹화한 WEBM 이다.
 */
import { test, expect, createBanner, gotoUser, hidePopups, authenticatePage, ADMIN_LIST, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page, Route } from '@playwright/test';

test.setTimeout(150_000);

// 운영 배너가 비노출이어도 돌도록 시험 배너를 직접 둔다(끝나면 지운다)
test.beforeEach(async ({ page, request, managerToken, created }) => {
  await hidePopups(page);
  created.push(await createBanner(request, managerToken));
});

const HERO_API = '**/api/plugins/sirsoft-hero_section/hero?position=home_hero*';
const VIDEO_URL = '/e2e-hero-media/clip.webm';

/** 브라우저 안에서 canvas 를 녹화해 짧은 WEBM 을 만든다. */
async function recordWebm(page: Page, ms = 2_000): Promise<Buffer> {
  const base64 = await page.evaluate(async (duration) => {
    const canvas = document.createElement('canvas');
    canvas.width = 320;
    canvas.height = 180;
    const context = canvas.getContext('2d')!;
    const stream = canvas.captureStream(25);
    const recorder = new MediaRecorder(stream, { mimeType: 'video/webm' });
    const chunks: Blob[] = [];
    recorder.ondataavailable = (event) => chunks.push(event.data);
    const started = performance.now();
    const draw = () => {
      context.fillStyle = `hsl(${(performance.now() - started) / 5 % 360}, 70%, 50%)`;
      context.fillRect(0, 0, 320, 180);
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

/**
 * 홈 공개 응답 앞에 시험 배너를 끼우고 슬라이더 설정을 바꾼다. 대표 이미지는 운영 첫 배너의 것을 빌린다.
 *
 * @param page 페이지
 * @param make 대표 이미지 → 끼울 배너들
 * @param settings 덮어쓸 슬라이더 설정
 */
async function injectBanners(page: Page, make: (poster: unknown) => Record<string, unknown>[], settings: Record<string, unknown>): Promise<void> {
  await page.route(HERO_API, async (route: Route) => {
    const response = await route.fetch();
    const body = await response.json();
    const real = body.data.banners as Array<Record<string, any>>;
    const poster = real[0]?.images ?? { pc: null, mobile: null };
    body.data.banners = [...make(poster), ...real.slice(0, 1)];
    body.data.settings = { ...body.data.settings, ...settings };
    await route.fulfill({ response, json: body });
  });
}

const videoBanner = (poster: unknown) => ({
  id: 990001, title: 'E2E 영상 배너', description: '', button_text: '', image_alt: 'E2E 영상 배너', title_visible: true, description_visible: false,
  link_url: null, link_new_window: false, images: poster, media_type: 'video',
  videos: { pc: { url: VIDEO_URL, mime: 'video/webm' }, mobile: null }, presentation: null,
});

/** 옛 공개 응답 — 유튜브를 없애기 전에 담긴 캐시가 유튜브 종류와 영상 ID 를 실어 올 수 있다 */
const legacyYoutubeBanner = (poster: unknown) => ({
  ...videoBanner(poster), id: 990002, title: 'E2E 옛 유튜브 배너', image_alt: 'E2E 옛 유튜브 배너', media_type: 'youtube', youtube_id: 'dQw4w9WgXcQ', videos: null,
});

/** 슬라이더의 활성 장 번호 */
const realIndex = (page: Page) => page.evaluate(() => (document.querySelector('section.hs-slider .swiper') as HTMLElement & { swiper: any })?.swiper?.realIndex ?? -1);

/** 활성 장 영상의 재생 상태 */
const videoState = (page: Page) => page.evaluate(() => {
  const video = document.querySelector('.hs-slider__slide--active video.hs-slider__video') as HTMLVideoElement | null;
  return video ? { paused: video.paused, time: video.currentTime, muted: video.muted, inline: video.playsInline } : null;
});

async function serveVideo(page: Page): Promise<void> {
  await gotoUser(page, '/');
  const webm = await recordWebm(page);
  await page.route(`**${VIDEO_URL}`, (route) => route.fulfill({ status: 200, contentType: 'video/webm', body: webm }));
}

// @scenario surface=video_plays_muted_inline
// @scenario surface=video_ends_then_next
// @effects video_plays_muted_with_device_source, video_drives_slide_advance
test('영상 파일 배너는 소리 없이 화면 안에서 재생되고, 끝나면 간격(7초)보다 먼저 다음 장으로 넘어간다', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await serveVideo(page);
  await injectBanners(page, (poster) => [videoBanner(poster)], { autoplay: true, interval_seconds: 7, effect: 'slide' });
  await page.reload();

  await expect(page.locator('.hs-slider__slide--active video.hs-slider__video')).toBeAttached({ timeout: 20_000 });
  await expect.poll(async () => (await videoState(page))?.time ?? 0, { timeout: 10_000 }).toBeGreaterThan(0.2);
  const playing = await videoState(page);
  expect(playing).toMatchObject({ paused: false, muted: true, inline: true });
  expect(await realIndex(page)).toBe(0);

  // 2초 영상 — 간격 7초를 기다리지 않고 끝나자마자 넘어간다
  const started = Date.now();
  await expect.poll(() => realIndex(page), { timeout: 6_000 }).toBe(1);
  expect(Date.now() - started).toBeLessThan(6_000);
});

// @scenario surface=pause_keeps_video_position
// @effects pause_controls_video_playback
test('일시정지(❚❚)는 그 자리에서 멈추고, 다시 재생(▶)하면 이어서 본다', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await gotoUser(page, '/');
  const webm = await recordWebm(page, 4_000);
  await page.route(`**${VIDEO_URL}`, (route) => route.fulfill({ status: 200, contentType: 'video/webm', body: webm }));
  await injectBanners(page, (poster) => [videoBanner(poster)], { autoplay: true, interval_seconds: 7, effect: 'slide' });
  await page.reload();

  await expect.poll(async () => (await videoState(page))?.time ?? 0, { timeout: 20_000 }).toBeGreaterThan(1);
  await page.getByRole('button', { name: '배너 일시정지' }).click();
  await expect.poll(async () => (await videoState(page))?.paused, { timeout: 5_000 }).toBe(true);
  const stopped = (await videoState(page))!.time;
  expect(stopped).toBeGreaterThan(1);
  await page.waitForTimeout(500);
  expect((await videoState(page))!.time).toBeCloseTo(stopped, 1);

  await page.getByRole('button', { name: '배너 다시 재생' }).click();
  await expect.poll(async () => (await videoState(page))?.paused, { timeout: 5_000 }).toBe(false);
  expect((await videoState(page))!.time).toBeGreaterThanOrEqual(stopped - 0.05);
});

// @scenario surface=video_play_abort_is_not_failure
// @effects video_drives_slide_advance
test('영상을 받는 중(재생 시작 전)에 다른 배너로 넘겼다 돌아와도 그 영상은 재생된다', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await gotoUser(page, '/');
  const webm = await recordWebm(page, 4_000);
  // 영상 응답을 늦춰 재생 요청이 끝나기 전에 넘긴다 — 브라우저는 그 재생 요청을 취소(AbortError)한다
  await page.route(`**${VIDEO_URL}`, async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 2_500));
    await route.fulfill({ status: 200, contentType: 'video/webm', body: webm });
  });
  await injectBanners(page, (poster) => [videoBanner(poster)], { autoplay: true, interval_seconds: 7, effect: 'slide' });
  // 재생 요청이 실제로 취소됐는지 확인하려고 거절 이유를 모은다(취소가 일어나지 않으면 이 시험은 아무것도 보지 않는다)
  await page.addInitScript(() => {
    const original = HTMLMediaElement.prototype.play;
    (window as any).__playRejections = [];
    HTMLMediaElement.prototype.play = function play(this: HTMLMediaElement) {
      const result = original.call(this);
      result.catch((error: Error) => (window as any).__playRejections.push(error.name));
      return result;
    };
  });
  await page.reload();

  await expect(page.locator('.hs-slider__slide--active video.hs-slider__video')).toBeAttached({ timeout: 20_000 });
  await page.locator('section.hs-slider .hs-slider__next').click();
  await expect.poll(() => realIndex(page), { timeout: 5_000 }).toBe(1);
  await page.waitForTimeout(700);
  await page.locator('section.hs-slider .hs-slider__prev').click();
  await expect.poll(() => realIndex(page), { timeout: 5_000 }).toBe(0);
  await page.mouse.move(0, 0);
  await expect.poll(async () => (await videoState(page))?.time ?? 0, { timeout: 15_000 }).toBeGreaterThan(0.2);
  expect((await videoState(page))?.paused).toBe(false);
  expect(await page.evaluate(() => (window as any).__playRejections)).toContain('AbortError');
});

// @scenario surface=video_poster_matches_device
// @effects video_plays_muted_with_device_source
test('휴대폰에서는 영상 대표 이미지로 모바일 이미지만 받고 PC 이미지는 받지 않는다', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.mobile);
  await serveVideo(page);
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  const requested: string[] = [];
  await page.route('**/e2e-hero-media/*.png', (route) => {
    requested.push(new URL(route.request().url()).pathname);
    return route.fulfill({ status: 200, contentType: 'image/png', body: png });
  });
  const images = { pc: { url: '/e2e-hero-media/pc.png', width: 1920, height: 640 }, mobile: { url: '/e2e-hero-media/m.png', width: 750, height: 1000 } };
  await injectBanners(page, () => [{ ...videoBanner(null), images }], { autoplay: false, effect: 'slide' });
  await page.reload();

  await expect(page.locator('.hs-slider__slide--active video.hs-slider__video')).toHaveAttribute('poster', '/e2e-hero-media/m.png', { timeout: 20_000 });
  await expect.poll(() => requested).toContain('/e2e-hero-media/m.png');
  await page.waitForTimeout(1_000);
  expect(requested).not.toContain('/e2e-hero-media/pc.png');
});

// @scenario surface=reduced_motion_starts_paused
// @scenario surface=video_preload_by_turn
// @effects pause_controls_video_playback, video_downloaded_only_on_its_turn
test('움직임 줄이기에서는 영상이 멈춘 채 시작하고, 「배너 다시 재생」 단추로 재생한다', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await serveVideo(page);
  await injectBanners(page, (poster) => [videoBanner(poster)], { autoplay: true, interval_seconds: 7 });
  await page.reload();

  const toggle = page.getByRole('button', { name: '배너 다시 재생' });
  await expect(toggle).toBeVisible({ timeout: 20_000 });
  await page.waitForTimeout(1_000);
  expect((await videoState(page))?.paused).toBe(true);
  // 재생하지 않는 영상은 받지 않는다 — 큰 파일이 멈춘 화면 뒤에서 내려받히지 않게
  const video = page.locator('.hs-slider__slide--active video.hs-slider__video');
  await expect(video).toHaveAttribute('preload', 'none');
  await toggle.click();
  await expect.poll(async () => (await videoState(page))?.paused, { timeout: 5_000 }).toBe(false);
  await expect(video).toHaveAttribute('preload', 'auto');
  await expect(page.getByRole('button', { name: '배너 일시정지' })).toBeVisible();
});

// @scenario surface=youtube_legacy_renders_image
// @effects legacy_youtube_renders_poster_only
test('옛 응답에 유튜브 종류가 와도 대표 이미지만 그리고 외부 플레이어를 부르지 않는다', async ({ page }) => {
  const external: string[] = [];
  await page.route(/youtube(-nocookie)?\.com|ytimg\.com|googlevideo\.com/, (route) => {
    external.push(route.request().url());
    return route.abort();
  });
  await page.setViewportSize(VIEWPORTS.desktop);
  await injectBanners(page, (poster) => [legacyYoutubeBanner(poster)], { autoplay: false });
  await gotoUser(page, '/');

  await expect(page.locator('.hs-slider__slide--active img.hs-slider__image')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('section.hs-slider iframe')).toHaveCount(0);
  await expect(page.getByRole('button', { name: '유튜브 영상 재생' })).toHaveCount(0);
  expect(external).toEqual([]);
});

// @scenario surface=form_media_type_fields
// @effects media_type_choice_switches_form_fields
test('등록 화면은 미디어 종류로 이미지·영상 파일만 고르고, 종류에 따라 영상 칸과 저장 잠김 이유를 바꾼다', async ({ page, managerToken }) => {
  await authenticatePage(page, managerToken);
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.goto(`${ADMIN_LIST}/create?position=home_hero`);
  const reason = page.locator('#hero_save_reason');
  await expect(page.locator('#media_card')).toBeVisible({ timeout: 30_000 });
  // 유튜브 등 제3자 영상 플랫폼은 고를 수 없다
  await expect(page.getByRole('radio', { name: '유튜브 영상' })).toHaveCount(0);
  await expect(page.locator('#hero_youtube_url')).toHaveCount(0);

  await page.getByRole('radio', { name: '영상 파일' }).check();
  await expect(page.getByText('영상 배너에서는 이 이미지가 대표 이미지입니다.', { exact: false })).toBeVisible();
  await expect(page.locator('#hero_field_pc_video')).toBeVisible();
  await expect(page.locator('#hero_field_pc_video input[type="file"]')).toHaveAttribute('accept', '.mp4,.webm');
  // PC 이미지가 먼저 — 대표 이미지 이유가 앞선다
  await expect(reason).toContainText('PC 이미지를 올려야 저장할 수 있습니다.');

  await page.getByRole('radio', { name: '이미지', exact: true }).check();
  await expect(page.locator('#hero_field_pc_video')).toHaveCount(0);
});
