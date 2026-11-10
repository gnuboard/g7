/**
 * 이미지 썸네일 — 이커머스 상품·리뷰 이미지 서빙 경로 (이슈 #869).
 *
 * 상품 이미지 `/api/modules/sirsoft-ecommerce/product-image/{hash}` 와 리뷰 이미지 `/review-image/{hash}` 에
 * `?size=`·`?w=` 를 붙였을 때의 응답, 숨김 리뷰(권한 원본)의 게이트가 썸네일 요청에도 같은지, 상점 화면 img 가
 * 썸네일 주소만 부르는지 잰다. 재는 것: 상품 이미지의 규격·숫자 요청·미정의 규격, 숨김 리뷰 이미지의
 * 비로그인·변조 서명·유효 서명, 상점 카테고리 화면의 img 주소.
 *
 * 데이터는 실측 시더가 만든다(이 spec 은 시더를 부르지 않는다 — 시드·정리는 실행하는 쪽 몫). 시드가 없으면 전부 건너뛴다:
 *   $env:G7_PLAYWRIGHT_BYPASS='1'
 *   php artisan db:seed --class="Database\Seeders\Sample\ThumbnailMeasureSeeder"
 *   php artisan module:seed sirsoft-ecommerce --class="Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ThumbnailMeasureSeeder"
 *   npx playwright test --config modules/_bundled/sirsoft-ecommerce/tests/Playwright/playwright.config.ts specs/shop/product-image-thumbnails.spec.ts
 *   php artisan module:seed sirsoft-ecommerce --class="Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder"
 *   php artisan db:seed --class="Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder"
 *
 * 이미지 해시는 박제하지 않고 공개 상품 API·관리자 리뷰 API 로 찾는다(시더를 다시 돌리면 해시가 바뀐다).
 * 규격 크기도 박제하지 않고 관리자 설정 API 에서 읽는다.
 * 상품 이미지는 공개 원본이라 공개 썸네일 위치로 간다 — 그 디스크가 공개 주소를 가지면(CDN·public) 302,
 * 아니면 200 스트림이다. 두 경우 모두 첫 응답의 `X-G7-Thumbnail` 을 읽고 본문은 Location 을 따라가 잰다.
 * 다시 넣지 않은 시드로 두 번째 실행하면 첫 요청이 이미 hit 일 수 있어 첫 요청은 miss·hit 를 모두 허용한다.
 */
import { test, expect, type APIRequestContext } from '@playwright/test';
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

const API = '/api/modules/sirsoft-ecommerce';
const PRODUCT_A = 'THUMBMEASUREA001';
const CATEGORY = 'thumb-measure';
/** 실측 시드 상품명 표지 — 관리자 리뷰 목록을 이 상품의 리뷰로 좁힌다 */
const SEED_MARK = '[실측-썸네일]';
const SEED_HINT =
  '실측 시더가 필요합니다 — 코어 Database\\Seeders\\Sample\\ThumbnailMeasureSeeder 뒤에 ' +
  'module:seed sirsoft-ecommerce --class="Modules\\Sirsoft\\Ecommerce\\Database\\Seeders\\Sample\\ThumbnailMeasureSeeder" 를 실행하세요.';

type ProductImage = { hash: string; original_filename: string; download_url: string; width: number; height: number; mime_type: string; file_size: number };
type ReviewImage = { original_filename: string; download_url: string; width: number; height: number };

/** 상품 A 이미지 (원래 파일명 → 이미지) */
const images: Record<string, ProductImage> = {};
/** 숨김 리뷰 이미지 (관리자 응답 — download_url 이 서명 주소) */
let hiddenReview: ReviewImage | undefined;
let settings: ThumbnailSettings;

/**
 * 관리자 리뷰 목록을 숨김 상태·시드 상품명으로 좁혀 끝 쪽까지 넘기며 숨김 리뷰 이미지를 찾는다.
 */
async function findHiddenReviewImage(request: APIRequestContext, token: string): Promise<ReviewImage | undefined> {
  for (let page = 1; page <= 20; page += 1) {
    const query = new URLSearchParams({
      status: 'hidden',
      search_field: 'product_name',
      search_keyword: SEED_MARK,
      per_page: '100',
      page: String(page),
    });
    const res = await request.get(`${API}/admin/reviews?${query}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    expect(res.status(), '관리자 리뷰 목록').toBe(200);
    const json = await res.json();
    const rows: Array<{ status: string; images?: ReviewImage[] }> = json.data?.data ?? [];
    for (const review of rows) {
      const found = (review.images ?? []).find((i) => i.original_filename === 'thumb-review-hidden.jpg');
      if (review.status === 'hidden' && found) return found;
    }
    const meta = json.data?.meta ?? json.data?.pagination ?? {};
    if (rows.length === 0 || !meta.last_page || page >= meta.last_page) return undefined;
  }

  return undefined;
}

test.describe('이미지 썸네일 — 이커머스 상품·리뷰 이미지', () => {
  test.describe.configure({ mode: 'serial', timeout: 180_000 });

  test.beforeAll(async ({ playwright, baseURL }) => {
    // 훅은 describe 의 timeout 설정을 받지 않는다 — 개발 서버 첫 응답이 느린 날에도 준비가 끊기지 않게
    test.setTimeout(180_000);
    const request = await playwright.request.newContext({ baseURL, ignoreHTTPSErrors: true });
    try {
      const product = await request.get(`${API}/products/${PRODUCT_A}`, { headers: { Accept: 'application/json' } });
      // 수동 실측 시더가 없는 환경(CI·새 설치)에서는 판정할 데이터가 없다 — 실패가 아니라 건너뛴다
      test.skip(product.status() !== 200, SEED_HINT);
      for (const img of ((await product.json()).data?.images ?? []) as ProductImage[]) {
        images[img.original_filename] = img;
      }
      for (const name of ['thumb-product-a-2000.jpg', 'thumb-product-a-wide-2000x1000.jpg']) {
        expect(images[name], `${name} 이미지가 없다 — ${SEED_HINT}`).toBeTruthy();
      }

      // 숨김 리뷰는 공개 API 에 나오지 않는다 — 관리자 리뷰 목록이 서명 주소로 이미지를 싣는다
      const token = issueToken();
      settings = await loadThumbnailSettings(request, token);
      hiddenReview = await findHiddenReviewImage(request, token);
      expect(hiddenReview?.download_url ?? '', `숨김 리뷰 이미지가 없다 — ${SEED_HINT}`).toMatch(/[?&]signature=/);
    } finally {
      await request.dispose();
    }
  });

  // @scenario surface=product_image, thumbnail_query=size
  // @effects ecommerce_product_image_without_size_returns_original_unchanged, ecommerce_product_image_size_returns_preset_dimensions
  test('공개 상품 이미지 ?size=sm 은 규격 크기 파생본이고 재요청은 hit 다 (공개 주소 디스크면 302)', async ({ request }) => {
    const jpeg = images['thumb-product-a-2000.jpg'];
    const source = { width: jpeg.width, height: jpeg.height };
    const original = await probeThumbnail(request, jpeg.download_url);
    expect(original.status).toBe(200);
    expect(original.thumbnail, '썸네일 질의가 없으면 머리를 붙이지 않는다').toBeNull();
    expect(original.dimensions).toEqual(source);
    expect(original.body.length).toBe(jpeg.file_size);

    const [first, second] = await probeTwice(request, withThumbnailQuery(jpeg.download_url, 'size=sm'));
    expect([200, 302]).toContain(first.status);
    expect(first.thumbnail).toMatch(/^(miss|hit)$/);
    expect(second.thumbnail).toBe('hit');
    if (first.status === 302) {
      // 공개 썸네일 디스크가 공개 주소를 가진 경우 — 파생본 주소로 보내고 공개 캐시로 둔다
      expect(first.location ?? '').toMatch(/\/sm_[0-9a-f]+\.jpg$/);
      expect(first.cacheControl ?? '').toContain('public');
    }
    expect(first.dimensions).toEqual(expectedDimensions(settings.presets.sm, source));
    expect(first.body.length).toBeLessThan(original.body.length / 10);
  });

  // @scenario surface=product_image, thumbnail_query=w
  // @effects ecommerce_product_image_w_rounds_up_to_numeric_preset
  test('?w= 는 그 폭 이상인 가장 작은 숫자 규격으로 올려 맞추고, 가장 큰 규격보다 크면 가장 큰 규격을 쓴다', async ({ request }) => {
    const { presets } = settings;
    const jpeg = images['thumb-product-a-2000.jpg'];
    const source = { width: jpeg.width, height: jpeg.height };
    for (const w of [1, 300, 700, 5000]) {
      const preset = presetForWidth(presets, w);
      const res = await probeThumbnail(request, withThumbnailQuery(jpeg.download_url, `w=${w}`));
      expect(res.thumbnail, `w=${w}`).toMatch(/^(miss|hit)$/);
      expect(res.dimensions, `w=${w} → ${preset.name}`).toEqual(expectedDimensions(preset, source));
    }

    // 가로형 원본은 가로 맞춤 규격에서 비율을 지킨다
    const wide = images['thumb-product-a-wide-2000x1000.jpg'];
    const md = await probeThumbnail(request, withThumbnailQuery(wide.download_url, 'size=md'));
    expect(md.dimensions).toEqual(expectedDimensions(presets.md, { width: wide.width, height: wide.height }));
  });

  // @scenario surface=product_image, thumbnail_query=bypass
  // @effects ecommerce_image_bypass_marks_original
  test('정의 안 된 규격은 bypass:unknown-preset 으로 원본을 낸다', async ({ request }) => {
    const jpeg = images['thumb-product-a-2000.jpg'];
    const original = await probeThumbnail(request, jpeg.download_url);
    const res = await probeThumbnail(request, withThumbnailQuery(jpeg.download_url, 'size=e2e-undefined'));
    expect(res.status).toBe(200);
    expect(res.thumbnail).toBe('bypass:unknown-preset');
    expect(res.dimensions).toEqual({ width: jpeg.width, height: jpeg.height });
    expect(res.body.equals(original.body)).toBe(true);
  });

  // @scenario surface=review_image, thumbnail_query=size_gate_denied
  // @effects ecommerce_gate_applies_to_thumbnail, ecommerce_signed_image_size_passes_signature, ecommerce_signed_image_tampered_or_expired_denied, ecommerce_gated_image_thumbnail_cache_is_private
  test('숨김 리뷰 이미지: 비로그인·변조 서명은 썸네일 요청도 원본과 같이 거부하고, 서명 주소 + size 는 private 파생본을 낸다', async ({ request }) => {
    const signed = hiddenReview!.download_url;
    const bare = new URL(signed, 'https://placeholder.invalid').pathname;
    const original = await probeThumbnail(request, bare);
    const thumb = await probeThumbnail(request, withThumbnailQuery(bare, 'size=sm'));
    expect(original.status).toBe(404);
    expect(thumb.status, '썸네일 요청도 원본과 같은 거부').toBe(original.status);
    expect(thumb.thumbnail).toBeNull();

    // 변조한 서명에 size 를 붙여도 원본과 같은 거부다
    const tampered = new URL(signed, 'https://placeholder.invalid');
    tampered.searchParams.set('signature', 'deadbeef'.repeat(8));
    tampered.searchParams.set('size', 'sm');
    const forged = await probeThumbnail(request, tampered.pathname + tampered.search);
    expect(forged.status).toBe(original.status);
    expect(forged.thumbnail).toBeNull();

    const ok = await probeThumbnail(request, withThumbnailQuery(signed, 'size=sm'));
    expect(ok.status, '권한 원본의 썸네일은 302 로 공개 주소에 내보내지 않는다').toBe(200);
    expect(ok.thumbnail).toMatch(/^(miss|hit)$/);
    expect(ok.dimensions).toEqual(expectedDimensions(settings.presets.sm, { width: hiddenReview!.width, height: hiddenReview!.height }));
    expect(ok.cacheControl ?? '').toContain('private');
  });

  // @scenario surface=product_image, thumbnail_query=size
  // @effects screen_img_requests_thumbnail_only
  test('상점 카테고리 화면의 상품·배너 img 는 size= 주소를 쓰고 원본 이미지 요청이 없다', async ({ page }) => {
    const imageRequests: string[] = [];
    page.on('request', (req) => {
      const path = new URL(req.url()).pathname;
      if (path.startsWith(`${API}/product-image/`) || path.startsWith(`${API}/category-image/`)) imageRequests.push(req.url());
    });

    await page.goto(`/shop/category/${CATEGORY}`);
    const productImgs = page.locator(`img[src*="${API}/product-image/"]`);
    await expect(productImgs.first()).toBeAttached({ timeout: 60_000 });
    await expect.poll(() => productImgs.count(), { timeout: 15_000 }).toBeGreaterThanOrEqual(2);

    const srcs = await productImgs.evaluateAll((els) => els.map((el) => (el as HTMLImageElement).getAttribute('src') ?? ''));
    for (const src of srcs) {
      expect(new URL(src, 'https://placeholder.invalid').searchParams.get('size'), src).toBeTruthy();
    }
    await expect
      .poll(() => productImgs.first().evaluate((el: HTMLImageElement) => (el.complete ? el.naturalWidth : 0)), { timeout: 30_000 })
      .toBeGreaterThan(0);
    expect(await productImgs.first().evaluate((el: HTMLImageElement) => el.naturalWidth), '원본 2000px 이 아닌 파생본을 그렸다').toBeLessThan(2000);

    expect(imageRequests.length).toBeGreaterThan(0);
    for (const url of imageRequests) {
      const q = new URL(url).searchParams;
      expect(q.has('size') || q.has('w'), `원본 주소 요청: ${url}`).toBe(true);
    }
  });
});
