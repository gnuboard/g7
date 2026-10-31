/**
 * #810 후속 — 영상 배너: 영상 파일은 실브라우저에서 소리 없이 재생되고 끝나면 다음 장으로 넘어가며(간격보다 먼저),
 * 움직임 줄이기에서는 멈춘 채 시작해 단추로 재생한다. 유튜브는 마케팅 동의 전이면 대표 이미지와 재생 단추만 보이고 누르면
 * youtube-nocookie 플레이어를 부른다. 등록 화면은 미디어 종류에 따라 유튜브 주소·영상 칸과 저장 잠김 이유를 바꾼다.
 * @scenario-manifest tests/scenarios/hero-media.yaml
 *
 * 공개 API 응답은 `page.route` 로 홈 배너 앞에 시험 배너를 끼워 넣는다(운영 배너·설정은 건드리지 않는다). 시험 영상은 서버에
 * 인코더가 없어도 되도록 브라우저 안에서 canvas 를 2초 녹화한 WEBM 이다. 유튜브 서버 요청은 끊는다(주소만 본다).
 */
import { test, expect, gotoUser, authenticatePage, ADMIN_LIST, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page, Route } from '@playwright/test';

test.setTimeout(150_000);

const HERO_API = '**/api/plugins/sirsoft-hero_section/hero?position=home_hero*';
const VIDEO_URL = '/e2e-hero-media/clip.webm';
const YT = 'dQw4w9WgXcQ';

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
  link_url: null, link_new_window: false, images: poster, media_type: 'video', youtube_id: null,
  videos: { pc: { url: VIDEO_URL, mime: 'video/webm' }, mobile: null }, presentation: null,
});

const youtubeBanner = (poster: unknown) => ({
  ...videoBanner(poster), id: 990002, title: 'E2E 유튜브 배너', image_alt: 'E2E 유튜브 배너', media_type: 'youtube', youtube_id: YT, videos: null,
});

/** 슬라이더의 활성 장 번호 */
const realIndex = (page: Page) => page.evaluate(() => (document.querySelector('section.hs .swiper') as HTMLElement & { swiper: any })?.swiper?.realIndex ?? -1);

/** 활성 장 영상의 재생 상태 */
const videoState = (page: Page) => page.evaluate(() => {
  const video = document.querySelector('.hs__slide--active video.hs__video') as HTMLVideoElement | null;
  return video ? { paused: video.paused, time: video.currentTime, muted: video.muted, inline: video.playsInline } : null;
});

async function serveVideo(page: Page): Promise<void> {
  await gotoUser(page, '/');
  const webm = await recordWebm(page);
  await page.route(`**${VIDEO_URL}`, (route) => route.fulfill({ status: 200, contentType: 'video/webm', body: webm }));
}

// @scenario surface=video_plays_muted_inline
// @scenario surface=video_ends_then_next
test('영상 파일 배너는 소리 없이 화면 안에서 재생되고, 끝나면 간격(7초)보다 먼저 다음 장으로 넘어간다', async ({ page }) => {
  await page.setViewportSize(VIEWPORTS.desktop);
  await serveVideo(page);
  await injectBanners(page, (poster) => [videoBanner(poster)], { autoplay: true, interval_seconds: 7, effect: 'slide' });
  await page.reload();

  await expect(page.locator('.hs__slide--active video.hs__video')).toBeAttached({ timeout: 20_000 });
  await expect.poll(async () => (await videoState(page))?.time ?? 0, { timeout: 10_000 }).toBeGreaterThan(0.2);
  const playing = await videoState(page);
  expect(playing).toMatchObject({ paused: false, muted: true, inline: true });
  expect(await realIndex(page)).toBe(0);

  // 2초 영상 — 간격 7초를 기다리지 않고 끝나자마자 넘어간다
  const started = Date.now();
  await expect.poll(() => realIndex(page), { timeout: 6_000 }).toBe(1);
  expect(Date.now() - started).toBeLessThan(6_000);
});

// @scenario surface=reduced_motion_starts_paused
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
  await toggle.click();
  await expect.poll(async () => (await videoState(page))?.paused, { timeout: 5_000 }).toBe(false);
  await expect(page.getByRole('button', { name: '배너 일시정지' })).toBeVisible();
});

// @scenario surface=youtube_gate_before_consent
// @scenario surface=youtube_play_button_loads
test('유튜브 배너는 마케팅 동의 전에는 대표 이미지와 재생 단추만 보이고, 누르면 youtube-nocookie 플레이어를 부른다', async ({ page }) => {
  const youtubeRequests: string[] = [];
  await page.route(/youtube(-nocookie)?\.com|ytimg\.com|googlevideo\.com/, (route) => {
    youtubeRequests.push(route.request().url());
    return route.abort();
  });
  await page.setViewportSize(VIEWPORTS.desktop);
  await injectBanners(page, (poster) => [youtubeBanner(poster)], { autoplay: false });
  await gotoUser(page, '/');

  await expect(page.locator('.hs__slide--active img.hs__image')).toBeVisible({ timeout: 20_000 });
  // 새 브라우저(동의 기록 없음) — 동의 관리 플러그인이 켜져 있으면 동의 전, 꺼져 있으면(none) 바로 불러온다. 어느 쪽이든 단언한다
  await expect.poll(() => page.evaluate(() => (window as any).G7Core?.consent?.getSnapshot?.()?.status ?? 'missing'), { timeout: 10_000 }).not.toBe('unknown');
  const consent = await page.evaluate(() => (window as any).G7Core?.consent?.getSnapshot?.() ?? null);
  const play = page.getByRole('button', { name: '유튜브 영상 재생' });
  if (consent?.status === 'none') {
    await expect(play).toHaveCount(0);
  } else {
    expect(consent?.categories?.marketing ?? false).toBe(false);
    await expect(play).toBeVisible();
    await expect(page.getByText('재생하면 YouTube 에서 영상을 불러옵니다.')).toBeVisible();
    await expect(page.locator('iframe.hs__youtube-frame')).toHaveCount(0);
    expect(youtubeRequests).toEqual([]);
    await play.click();
  }

  const frame = page.locator('.hs__slide--active iframe.hs__youtube-frame');
  await expect(frame).toBeAttached();
  const src = new URL((await frame.getAttribute('src'))!);
  expect(src.origin).toBe('https://www.youtube-nocookie.com');
  expect(src.pathname).toBe(`/embed/${YT}`);
  expect(src.searchParams.get('mute')).toBe('1');
  await expect(play).toHaveCount(0);
});

// @scenario surface=form_media_type_fields
test('등록 화면은 미디어 종류에 따라 유튜브 주소·영상 칸과 저장 잠김 이유를 바꾼다', async ({ page, managerToken }) => {
  await authenticatePage(page, managerToken);
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.goto(`${ADMIN_LIST}/create?position=home_hero`);
  const reason = page.locator('#hero_save_reason');
  await expect(page.locator('#media_card')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#hero_youtube_url')).toHaveCount(0);

  await page.getByRole('radio', { name: '유튜브 영상' }).check();
  await expect(page.locator('#hero_youtube_url')).toBeVisible();
  await expect(page.getByText('영상 배너에서는 이 이미지가 대표 이미지입니다.', { exact: false })).toBeVisible();

  await page.getByRole('radio', { name: '영상 파일' }).check();
  await expect(page.locator('#hero_youtube_url')).toHaveCount(0);
  await expect(page.locator('#hero_field_pc_video')).toBeVisible();
  await expect(page.locator('#hero_field_pc_video input[type="file"]')).toHaveAttribute('accept', '.mp4,.webm');
  // PC 이미지가 먼저 — 대표 이미지 이유가 앞선다
  await expect(reason).toContainText('PC 이미지를 올려야 저장할 수 있습니다.');

  await page.getByRole('radio', { name: '이미지', exact: true }).check();
  await expect(page.locator('#hero_field_pc_video')).toHaveCount(0);
});
