/**
 * 이미지 썸네일 — 코어 첨부 서빙 경로 (이슈 #869).
 *
 * 코어 첨부 주소 `/api/attachment/{hash}` 에 `?size={규격}`·`?w={숫자}` 를 붙였을 때 서버가 낸 응답을 잰다.
 * 재는 것: 규격 요청의 miss·hit·크기, 숫자 요청(`?w=`)의 규격 선택, 미정의·잘못된 값의 원본 응답,
 * 래스터가 아닌 첨부(PDF)의 기존 다운로드, 관리자 헤더 아바타 img 주소.
 *
 * 시드 의존이 없다 — 원본은 이번 실행이 관리자 첨부 API(`POST /api/admin/attachments`)로 새로 올리고 끝에 지운다.
 * 새로 올린 원본이라 첫 썸네일 요청은 반드시 miss 다(지난 실행의 파생본과 섞이지 않는다).
 * 아바타 시나리오는 토큰 계정 본인 아바타를 `POST /api/me/avatar` 로 올리고 `DELETE /api/me/avatar` 로 지운다.
 *
 * 규격 값(sm 160×160 자르기, md 640·lg 1280·xl 1920 가로 맞춤, xs 64×64 자르기)은 박제하지 않고
 * 관리자 설정 API(`GET /api/admin/settings` 의 `data.upload.thumbnail_presets`)에서 읽는다 — 운영자가 바꿔도 맞는다.
 *
 * 실행 (PowerShell):
 *   npx playwright test tests/Playwright/specs/image-thumbnails.spec.ts
 */
import type { APIRequestContext, Browser } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../fixtures/auth';
import { probeThumbnail, probeTwice, withThumbnailQuery } from '../fixtures/image-thumbnail';

/** 원본 크기 — 업로드 최대 가로·세로(기본 2000) 안이라 저장 때 줄지 않는다 */
const ORIGINAL = { width: 2000, height: 1500 };

/** 관리자 설정의 규격 한 줄 */
type Preset = { name: string; width: number; height: number | null; fit: string; numeric?: boolean; enabled?: boolean };

type Uploaded = { id: number; hash: string; size: number; url: string };

let token = '';
let presets: Record<string, Preset> = {};
let raster: Uploaded;
let pdf: Uploaded;
/** 아바타 시나리오가 토큰 계정에 아바타를 올렸는지 — afterAll 이 지운다 */
let avatarUploaded = false;

/**
 * 브라우저 캔버스로 진짜 JPEG 바이트를 만든다 (줄무늬 + 잡음 — 파생본이 원본보다 확실히 작게).
 */
async function makeJpeg(browser: Browser, width: number, height: number): Promise<Buffer> {
  const page = await browser.newPage();
  try {
    const base64 = await page.evaluate(
      async ({ width, height }) => {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d')!;
        const gradient = ctx.createLinearGradient(0, 0, width, height);
        gradient.addColorStop(0, '#1d4ed8');
        gradient.addColorStop(1, '#f97316');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, width, height);
        for (let i = 0; i < 4000; i += 1) {
          ctx.fillStyle = `rgba(${(i * 37) % 255}, ${(i * 91) % 255}, ${(i * 53) % 255}, 0.5)`;
          ctx.fillRect((i * 7919) % width, (i * 104729) % height, 12, 12);
        }
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 120px sans-serif';
        ctx.fillText('E2E THUMBNAIL', 120, height / 2);
        const blob: Blob = await new Promise((resolve) => canvas.toBlob((b) => resolve(b!), 'image/jpeg', 0.92));
        const bytes = new Uint8Array(await blob.arrayBuffer());
        let binary = '';
        for (let i = 0; i < bytes.length; i += 1) binary += String.fromCharCode(bytes[i]);
        return btoa(binary);
      },
      { width, height },
    );
    return Buffer.from(base64, 'base64');
  } finally {
    await page.close();
  }
}

/** 최소 PDF 한 쪽 (래스터가 아닌 첨부) */
function makePdf(): Buffer {
  return Buffer.from(
    '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n' +
      '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n',
    'latin1',
  );
}

/** 관리자 첨부 API 로 소유자 없는 코어 첨부를 올린다 */
async function upload(request: APIRequestContext, name: string, mimeType: string, buffer: Buffer): Promise<Uploaded> {
  const res = await request.post('/api/admin/attachments', {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    multipart: { file: { name, mimeType, buffer }, collection: 'e2e_thumbnail' },
  });
  const json = await res.json();
  expect(res.status(), `업로드 실패: ${JSON.stringify(json).slice(0, 400)}`).toBe(201);
  const data = json.data;

  return { id: data.id, hash: data.hash, size: buffer.length, url: `/api/attachment/${data.hash}` };
}

test.describe('이미지 썸네일 — 코어 첨부', () => {
  // 원본·파생본을 시나리오끼리 이어 쓰므로 순서대로 돈다. 관리자 화면 첫 그림이 개발 서버에서 20초를 넘기도 해 시간을 넉넉히 둔다.
  test.describe.configure({ mode: 'serial', timeout: 180_000 });

  test.beforeAll(async ({ browser, playwright, baseURL }) => {
    // 훅은 describe 의 timeout 설정을 받지 않는다 — 개발 서버 첫 응답이 느린 날에도 준비·정리가 끊기지 않게
    test.setTimeout(180_000);
    token = issueToken('core.attachments.create', 'core.attachments.delete', 'core.settings.read');
    const request = await playwright.request.newContext({ baseURL, ignoreHTTPSErrors: true });

    const settings = await request.get('/api/admin/settings', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    expect(settings.status()).toBe(200);
    const list: Preset[] = (await settings.json()).data?.upload?.thumbnail_presets ?? [];
    presets = Object.fromEntries(list.map((p) => [p.name, p]));
    for (const name of ['xs', 'sm', 'md', 'lg', 'xl']) {
      expect(presets[name], `고정 규격 ${name} 이 설정에 없다`).toBeTruthy();
    }

    raster = await upload(request, 'e2e-thumbnail-2000x1500.jpg', 'image/jpeg', await makeJpeg(browser, ORIGINAL.width, ORIGINAL.height));
    pdf = await upload(request, 'e2e-thumbnail.pdf', 'application/pdf', makePdf());
    await request.dispose();
  });

  test.afterAll(async ({ playwright, baseURL }) => {
    // 훅은 describe 의 timeout 설정을 받지 않는다 — 개발 서버 첫 응답이 느린 날에도 준비·정리가 끊기지 않게
    test.setTimeout(180_000);
    const request = await playwright.request.newContext({ baseURL, ignoreHTTPSErrors: true });
    if (avatarUploaded) {
      const removed = await request.delete('/api/me/avatar', {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      expect(removed.status(), '토큰 계정 아바타 삭제').toBe(200);
    }
    for (const item of [raster, pdf]) {
      if (!item) continue;
      const res = await request.delete(`/api/admin/attachments/${item.id}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      expect(res.status(), `첨부 ${item.id} 삭제`).toBe(200);
    }
    // 원본 삭제 후 썸네일 주소도 404 다
    if (raster) {
      const gone = await request.get(withThumbnailQuery(raster.url, 'size=sm'), { failOnStatusCode: false });
      expect(gone.status()).toBe(404);
    }
    await request.dispose();
  });

  // @scenario serving_point=core_attachment, request_param=size
  // @effects thumbnail_generated_on_first_request, thumbnail_served_from_cache_on_second, fit_produces_declared_geometry, original_response_unchanged_without_params
  test('?size=sm 첫 요청 miss → 재요청 hit, 파생본은 원본보다 작고 규격 크기다', async ({ request }) => {
    const sm = presets.sm;
    const original = await probeThumbnail(request, raster.url);
    expect(original.status).toBe(200);
    expect(original.thumbnail, '썸네일 질의가 없으면 머리를 붙이지 않는다').toBeNull();
    expect(original.dimensions).toEqual(ORIGINAL);

    const [first, second] = await probeTwice(request, withThumbnailQuery(raster.url, 'size=sm'));
    expect([200, 302]).toContain(first.status);
    expect(first.thumbnail).toBe('miss');
    expect(second.thumbnail).toBe('hit');
    expect(first.contentType).toMatch(/^image\/jpeg/);
    // sm 은 자르기(cover) 규격 — 가로·세로가 규격 그대로다
    expect(sm.fit).toBe('cover');
    expect(first.dimensions).toEqual({ width: sm.width, height: sm.height });
    expect(first.body.length).toBeLessThan(original.body.length / 10);
    expect(second.body.equals(first.body), 'hit 은 miss 가 만든 같은 파생본을 낸다').toBe(true);
  });

  // @scenario serving_point=core_attachment, request_param=w
  // @effects preset_resolution_table_holds
  test('?w= 는 그 폭 이상인 가장 작은 숫자 규격으로 올려 맞춘다', async ({ request }) => {
    const numeric = Object.values(presets)
      .filter((p) => p.numeric && p.enabled !== false && p.fit === 'width' && p.width <= ORIGINAL.width)
      .sort((a, b) => a.width - b.width);
    expect(numeric.map((p) => p.name)).toEqual(expect.arrayContaining(['md', 'lg', 'xl']));

    const cases: Array<[number, Preset]> = [
      [1, numeric[0]],
      [300, numeric.find((p) => p.width >= 300)!],
      [700, numeric.find((p) => p.width >= 700)!],
      [5000, numeric[numeric.length - 1]],
    ];

    for (const [w, preset] of cases) {
      const res = await probeThumbnail(request, withThumbnailQuery(raster.url, `w=${w}`));
      expect(res.thumbnail, `w=${w}`).toMatch(/^(miss|hit)$/);
      expect(res.dimensions, `w=${w} → ${preset.name}`).toEqual({
        width: preset.width,
        height: Math.round((ORIGINAL.height * preset.width) / ORIGINAL.width),
      });
      expect(res.disposition ?? '', `w=${w}`).toContain(`${preset.name}_`);
    }
  });

  // @scenario serving_point=core_attachment, request_param=invalid
  // @effects invalid_params_fall_back_to_original, original_bytes_on_bypass
  test('정의 안 된 규격·잘못된 값은 bypass:unknown-preset 으로 원본을 낸다', async ({ request }) => {
    const original = await probeThumbnail(request, raster.url);

    for (const query of ['size=e2e-undefined', 'size[]=sm', 'w=-1', 'w=abc']) {
      const res = await probeThumbnail(request, withThumbnailQuery(raster.url, query));
      expect(res.status, query).toBe(200);
      expect(res.thumbnail, query).toBe('bypass:unknown-preset');
      expect(res.dimensions, query).toEqual(ORIGINAL);
      expect(res.body.equals(original.body), `${query} 는 원본 바이트 그대로`).toBe(true);
    }
  });

  // @scenario serving_point=core_attachment, request_param=size
  // @effects non_raster_bypassed, original_bytes_on_bypass
  test('래스터가 아닌 첨부(PDF)는 bypass:not-raster 로 기존 다운로드를 그대로 낸다', async ({ request }) => {
    const original = await probeThumbnail(request, pdf.url);
    const res = await probeThumbnail(request, withThumbnailQuery(pdf.url, 'size=sm'));

    expect(original.status).toBe(200);
    expect(original.thumbnail).toBeNull();
    expect(res.status).toBe(200);
    expect(res.thumbnail).toBe('bypass:not-raster');
    expect(res.contentType).toMatch(/^application\/pdf/);
    expect(res.disposition ?? '').toMatch(/^attachment/);
    expect(res.dimensions).toBeNull();
    expect(res.body.equals(original.body)).toBe(true);
    expect(res.body.length).toBe(pdf.size);
  });

  // @scenario serving_point=core_attachment, request_param=size
  // @effects screen_img_requests_thumbnail_only
  test('관리자 헤더 아바타 img 는 size=xs 주소를 쓰고 원본 주소 요청이 없다', async ({ page, browser, request }) => {
    const auth = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
    const avatar = await makeJpeg(browser, 800, 800);
    const uploaded = await request.post('/api/me/avatar', {
      headers: auth,
      multipart: { avatar: { name: 'e2e-thumbnail-avatar.jpg', mimeType: 'image/jpeg', buffer: avatar } },
    });
    expect(uploaded.status(), (await uploaded.text()).slice(0, 300)).toBe(200);
    avatarUploaded = true;

    const me = await (await request.get('/api/me', { headers: auth })).json();
    const avatarUrl: string = me.data?.avatar ?? '';
    expect(avatarUrl).toMatch(/^\/api\/attachment\/[A-Za-z0-9]+$/);

    const avatarRequests: string[] = [];
    page.on('request', (req) => {
      if (new URL(req.url()).pathname === avatarUrl) avatarRequests.push(req.url());
    });
    await authenticatePage(page, token);
    await page.goto('/admin/dashboard');

    const img = page.locator('#user_avatar_img');
    await expect(img).toBeAttached({ timeout: 30_000 });
    await expect(img).toHaveAttribute('src', new RegExp(`${avatarUrl}\\?(.*&)?size=xs`));
    await expect
      .poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth), { timeout: 15_000 })
      .toBe(presets.xs.width);

    expect(avatarRequests.length, '아바타 요청이 한 건 이상 있어야 한다').toBeGreaterThan(0);
    for (const url of avatarRequests) {
      expect(new URL(url).searchParams.has('size'), `원본 주소 요청: ${url}`).toBe(true);
    }
  });
});
