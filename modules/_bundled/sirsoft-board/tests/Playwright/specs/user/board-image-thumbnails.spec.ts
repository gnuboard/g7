/**
 * 이미지 썸네일 — 게시판 첨부 서빙 경로 (이슈 #869).
 *
 * 게시판 첨부 미리보기 `/api/modules/sirsoft-board/boards/{slug}/attachment/{hash}/preview` 에 `?size=`·`?w=` 를 붙였을
 * 때의 응답과, 게이트(비밀글)가 썸네일 요청에도 원본과 같이 거부하는지, 실제 화면 img 가 썸네일 주소만 부르는지 잰다.
 * 재는 것: 이미지 변종(움직이는 GIF·작은 원본·WebP·SVG), 비밀글 첨부의 비로그인·변조 서명·유효 서명,
 * 갤러리형 목록 화면의 img 주소.
 *
 * 데이터는 실측 시더가 만든다(이 spec 은 시더를 부르지 않는다 — 시드·정리는 실행하는 쪽 몫). 시드가 없으면 전부 건너뛴다:
 *   $env:G7_PLAYWRIGHT_BYPASS='1'
 *   php artisan db:seed --class="Database\Seeders\Sample\ThumbnailMeasureSeeder"
 *   php artisan module:seed sirsoft-board --class="Modules\Sirsoft\Board\Database\Seeders\Sample\ThumbnailMeasureSeeder"
 *   npx playwright test --config modules/_bundled/sirsoft-board/tests/Playwright/playwright.config.ts specs/user/board-image-thumbnails.spec.ts
 *   php artisan module:seed sirsoft-board --class="Modules\Sirsoft\Board\Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder"
 *   php artisan db:seed --class="Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder"
 *
 * 첨부 해시는 박제하지 않고 공개 목록·상세 API 로 찾는다(시더를 다시 돌리면 해시가 바뀐다). 비밀글 첨부의
 * 서명 주소는 관리자 토큰으로 상세를 읽어 얻는다 — 화면이 `<img>` 에 쓰는 바로 그 주소다.
 * 규격 크기와 「움직이는 이미지는 원본 사용」 설정도 박제하지 않고 관리자 설정 API 에서 읽는다.
 *
 * 다시 넣지 않은 시드로 두 번째 실행하면 첫 썸네일 요청이 이미 hit 일 수 있다. 그래서 첫 요청은 miss·hit 를
 * 모두 허용하고 둘째 요청이 hit 인지를 본다. 첫 요청 miss 의 엄격한 측정은 코어 spec
 * (`tests/Playwright/specs/image-thumbnails.spec.ts`, 실행마다 새 원본을 올린다)이 맡는다.
 */
import type { APIRequestContext } from '@playwright/test';
import { test, expect } from '../../fixtures/board-auth';
import { issueToken } from '../../../../../../../tests/Playwright/fixtures/auth';
import {
  expectedDimensions,
  loadThumbnailSettings,
  presetForWidth,
  probeThumbnail,
  probeTwice,
  withThumbnailQuery,
  type ThumbnailSettings,
} from '../../../../../../../tests/Playwright/fixtures/image-thumbnail';

const API = '/api/modules/sirsoft-board';
const CARD = 'thumb-measure-card';
const GALLERY = 'thumb-measure-gallery';
const SEED_HINT =
  '실측 시더가 필요합니다 — 코어 Database\\Seeders\\Sample\\ThumbnailMeasureSeeder 뒤에 ' +
  'module:seed sirsoft-board --class="Modules\\Sirsoft\\Board\\Database\\Seeders\\Sample\\ThumbnailMeasureSeeder" 를 실행하세요.';

type Size = { width: number; height: number };

type Attachment = {
  hash: string;
  original_filename: string;
  mime_type: string;
  size: number;
  download_url: string;
  preview_url: string | null;
  meta: Size | null;
};

/** 시드 글의 첨부 (원래 파일명 → 첨부) */
const files: Record<string, Attachment> = {};
let secret: Attachment;
let adminToken = '';
let settings: ThumbnailSettings;

async function getJson(request: APIRequestContext, url: string, token?: string): Promise<any> {
  const res = await request.get(url, {
    headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
  });
  expect(res.status(), `${url} → ${res.status()}`).toBe(200);

  return res.json();
}

/** 첨부의 원본 크기 (시드 메타) */
function sizeOf(a: Attachment): Size {
  expect(a.meta, `${a.original_filename} 의 크기 메타가 없다`).toBeTruthy();

  return { width: a.meta!.width, height: a.meta!.height };
}

test.describe('이미지 썸네일 — 게시판 첨부', () => {
  test.describe.configure({ mode: 'serial', timeout: 180_000 });

  test.beforeAll(async ({ playwright, baseURL }) => {
    // 훅은 describe 의 timeout 설정을 받지 않는다 — 개발 서버 첫 응답이 느린 날에도 준비가 끊기지 않게
    test.setTimeout(180_000);
    const request = await playwright.request.newContext({ baseURL, ignoreHTTPSErrors: true });
    try {
      const list = await request.get(`${API}/boards/${CARD}/posts?per_page=50`, { headers: { Accept: 'application/json' } });
      const posts: Array<{ id: number; is_secret: boolean; attachment_count: number }> =
        list.status() === 200 ? ((await list.json()).data?.data ?? []) : [];
      // 수동 실측 시더가 없는 환경(CI·새 설치)에서는 판정할 데이터가 없다 — 실패가 아니라 건너뛴다
      test.skip(posts.length === 0, SEED_HINT);

      adminToken = issueToken();
      settings = await loadThumbnailSettings(request, adminToken);
      for (const post of posts) {
        if (post.attachment_count === 0 && !post.is_secret) continue;
        // 비밀글은 관리자 토큰으로 읽어야 첨부(서명 주소)가 실린다 — 공개 글은 비로그인 그대로 읽는다
        const detail = await getJson(request, `${API}/boards/${CARD}/posts/${post.id}`, post.is_secret ? adminToken : undefined);
        for (const a of (detail.data?.attachments ?? []) as Attachment[]) {
          if (post.is_secret) secret = a;
          else files[a.original_filename] = a;
        }
      }
    } finally {
      await request.dispose();
    }

    for (const name of ['thumb-board-2000.jpg', 'thumb-board-animated.gif', 'thumb-board-100.png', 'thumb-board-2000.webp', 'thumb-board-vector.svg']) {
      expect(files[name], `${name} 첨부가 없다 — ${SEED_HINT}`).toBeTruthy();
    }
    expect(secret, `비밀글 첨부가 없다 — ${SEED_HINT}`).toBeTruthy();
    expect(secret.preview_url ?? '', '관리자에게는 비밀글 첨부 미리보기가 서명 주소로 나간다').toMatch(/[?&]signature=/);
  });

  // @scenario case=preset
  // @effects preview_with_size_serves_the_preset_dimensions, gated_thumbnail_is_private_and_hardened, preview_without_size_serves_the_original_unchanged
  test('공개 글 첨부 ?size=sm 은 규격 크기 파생본이고 재요청은 hit 다', async ({ request }) => {
    const { presets } = settings;
    const jpeg = files['thumb-board-2000.jpg'];
    const source = sizeOf(jpeg);
    const original = await probeThumbnail(request, jpeg.preview_url!);
    expect(original.status).toBe(200);
    expect(original.thumbnail, '썸네일 질의가 없으면 머리를 붙이지 않는다').toBeNull();
    expect(original.dimensions).toEqual(source);
    expect(original.body.length).toBe(jpeg.size);

    const [first, second] = await probeTwice(request, withThumbnailQuery(jpeg.preview_url!, 'size=sm'));
    expect(first.status).toBe(200);
    expect(first.thumbnail).toMatch(/^(miss|hit)$/);
    expect(second.thumbnail).toBe('hit');
    expect(first.dimensions).toEqual(expectedDimensions(presets.sm, source));
    expect(first.body.length).toBeLessThan(original.body.length / 10);
    // 게시판 첨부는 게이트 원본이라 썸네일도 비공개 위치에서 private 캐시로 나가고(302 아님) nosniff 로 보강된다
    expect(first.cacheControl ?? '').toContain('private');
    expect(first.nosniff).toBe('nosniff');

    // ?w=300 → 그 폭 이상인 가장 작은 숫자 규격
    const w = await probeThumbnail(request, withThumbnailQuery(jpeg.preview_url!, 'w=300'));
    expect(w.thumbnail).toMatch(/^(miss|hit)$/);
    expect(w.dimensions).toEqual(expectedDimensions(presetForWidth(presets, 300), source));

    // 가로형·세로형 원본에도 xs 규격 크기 그대로
    for (const name of ['thumb-board-wide-2000x800.jpg', 'thumb-board-tall-600x2000.jpg']) {
      const a = files[name];
      expect(a, `${name} 첨부가 없다 — ${SEED_HINT}`).toBeTruthy();
      const xs = await probeThumbnail(request, withThumbnailQuery(a.preview_url!, 'size=xs'));
      expect(xs.dimensions, name).toEqual(expectedDimensions(presets.xs, sizeOf(a)));
    }
  });

  // @scenario case=unknown_preset
  // @effects unknown_preset_serves_the_original_with_bypass_reason, download_route_ignores_thumbnail_query
  test('변종 원본: 움직이는 GIF 는 설정대로, 작은 원본·미정의 규격은 원본, WebP 는 WebP 파생본, 다운로드는 질의 무시', async ({ request }) => {
    const { presets, animatedOriginal } = settings;
    const gif = files['thumb-board-animated.gif'];
    const gifOriginal = await probeThumbnail(request, gif.preview_url!);
    const gifThumb = await probeThumbnail(request, withThumbnailQuery(gif.preview_url!, 'size=sm'));
    if (animatedOriginal) {
      // 「움직이는 이미지는 원본 사용」 켬 — 원본 그대로
      expect(gifThumb.thumbnail).toBe('bypass:animated');
      expect(gifThumb.body.equals(gifOriginal.body)).toBe(true);
    } else {
      // 끔 — 첫 장면으로 만든 규격 크기 썸네일
      expect(gifThumb.thumbnail).toMatch(/^(miss|hit)$/);
      expect(gifThumb.dimensions).toEqual(expectedDimensions(presets.sm, sizeOf(gif)));
    }

    const small = files['thumb-board-100.png'];
    const smallSource = sizeOf(small);
    expect(presets.sm.width, '이 시나리오는 sm 이 작은 원본보다 큰 규격이어야 성립한다').toBeGreaterThan(smallSource.width);
    const smallSm = await probeThumbnail(request, withThumbnailQuery(small.preview_url!, 'size=sm'));
    expect(smallSm.thumbnail).toBe('bypass:smaller-than-preset');
    expect(smallSm.dimensions).toEqual(smallSource);
    const smallXs = await probeThumbnail(request, withThumbnailQuery(small.preview_url!, 'size=xs'));
    expect(smallXs.thumbnail).toMatch(/^(miss|hit)$/);
    expect(smallXs.dimensions).toEqual(expectedDimensions(presets.xs, smallSource));

    const webp = files['thumb-board-2000.webp'];
    const webpSm = await probeThumbnail(request, withThumbnailQuery(webp.preview_url!, 'size=sm'));
    expect(webpSm.thumbnail).toMatch(/^(miss|hit)$/);
    expect(webpSm.contentType).toMatch(/^image\/webp/);
    expect(webpSm.dimensions).toEqual(expectedDimensions(presets.sm, sizeOf(webp)));

    const jpeg = files['thumb-board-2000.jpg'];
    const unknown = await probeThumbnail(request, withThumbnailQuery(jpeg.preview_url!, 'size=e2e-undefined'));
    expect(unknown.thumbnail).toBe('bypass:unknown-preset');
    expect(unknown.dimensions).toEqual(sizeOf(jpeg));
    expect(unknown.body.length).toBe(jpeg.size);

    // SVG 는 미리보기 통로에 닿지 않고(이미지 아님 400), 다운로드는 썸네일 질의를 보지 않는 기존 통로다
    const svg = files['thumb-board-vector.svg'];
    expect(svg.preview_url).toBeNull();
    const svgDownload = await probeThumbnail(request, withThumbnailQuery(svg.download_url, 'size=sm'));
    expect(svgDownload.status).toBe(200);
    expect(svgDownload.disposition ?? '').toMatch(/^attachment/);
    expect(svgDownload.thumbnail).toBeNull();
    expect(svgDownload.body.length).toBe(svg.size);
  });

  // @scenario case=secret_gate
  // @effects secret_post_gate_applies_to_thumbnail, gated_thumbnail_is_private_and_hardened
  test('비밀글 첨부: 비로그인·변조 서명은 썸네일 요청도 원본과 같이 거부하고, 서명 주소 + size 는 파생본을 낸다', async ({ request }) => {
    const signed = new URL(secret.preview_url!, 'https://placeholder.invalid');
    const bare = signed.pathname;

    const original = await probeThumbnail(request, bare);
    const thumb = await probeThumbnail(request, withThumbnailQuery(bare, 'size=sm'));
    expect(original.status).toBe(403);
    expect(thumb.status, '썸네일 요청도 원본과 같은 거부').toBe(original.status);
    expect(thumb.thumbnail).toBeNull();

    const tampered = new URL(signed.toString());
    tampered.searchParams.set('signature', 'deadbeef'.repeat(8));
    tampered.searchParams.set('size', 'sm');
    const forged = await probeThumbnail(request, tampered.pathname + tampered.search);
    expect(forged.status).toBe(403);

    // 화면이 서명 주소 뒤에 size 를 덧붙여도 서명은 깨지지 않는다(서명은 size·w 를 빼고 검증)
    const ok = await probeThumbnail(request, withThumbnailQuery(secret.preview_url!, 'size=sm'));
    expect(ok.status).toBe(200);
    expect(ok.thumbnail).toMatch(/^(miss|hit)$/);
    expect(ok.dimensions).toEqual(expectedDimensions(settings.presets.sm, sizeOf(secret)));
    expect(ok.cacheControl ?? '').toContain('private');
    expect(ok.nosniff).toBe('nosniff');
  });

  // @scenario case=preset
  // @effects screen_img_requests_thumbnail_only
  test('갤러리형 목록 화면의 첨부 img 는 size= 주소를 쓰고 원본 미리보기 요청이 없다', async ({ page }) => {
    const previewRequests: string[] = [];
    page.on('request', (req) => {
      const url = new URL(req.url());
      if (url.pathname.startsWith(`${API}/boards/${GALLERY}/attachment/`)) previewRequests.push(req.url());
    });

    await page.goto(`/board/${GALLERY}`);
    const imgs = page.locator(`img[src*="/boards/${GALLERY}/attachment/"]`);
    await expect(imgs.first()).toBeAttached({ timeout: 60_000 });
    await expect.poll(() => imgs.count(), { timeout: 15_000 }).toBeGreaterThanOrEqual(2);

    const srcs = await imgs.evaluateAll((els) => els.map((el) => (el as HTMLImageElement).getAttribute('src') ?? ''));
    for (const src of srcs) {
      expect(new URL(src, 'https://placeholder.invalid').searchParams.get('size'), src).toBeTruthy();
    }
    // 실제로 받아 그린 크기가 원본 폭보다 작다 — 파생본을 그렸다
    await expect
      .poll(() => imgs.first().evaluate((el: HTMLImageElement) => (el.complete ? el.naturalWidth : 0)), { timeout: 20_000 })
      .toBeGreaterThan(0);
    expect(await imgs.first().evaluate((el: HTMLImageElement) => el.naturalWidth), '원본 2000px 이 아닌 파생본을 그렸다').toBeLessThan(2000);

    expect(previewRequests.length).toBeGreaterThan(0);
    for (const url of previewRequests) {
      const q = new URL(url).searchParams;
      expect(q.has('size') || q.has('w'), `원본 주소 요청: ${url}`).toBe(true);
    }
  });
});
