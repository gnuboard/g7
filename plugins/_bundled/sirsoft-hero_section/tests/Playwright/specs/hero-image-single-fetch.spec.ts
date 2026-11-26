/**
 * 히어로 첫 장 이미지는 한 번만 받는다 — 본문 폭 칸(1920 화면의 1216px) × 화면 배율에 맞는 후보 하나(DPR1 은 `?size=lg`, DPR2 는 원본).
 * @scenario-manifest tests/scenarios/image-thumbnail.yaml
 * 효과 요약(마커 아님, 평문): 슬라이더 이미지 후보가 그려진 칸 폭을 따른다. 효과 표식은 아래 시험에 있다.
 *
 * 슬라이더는 상자를 잰 뒤에 `<img>` 주소를 붙이는데, React 는 이때(갱신) 속성을 적은 순서대로 붙인다. WebKit 은 srcset 이 sizes
 * 보다 먼저 붙으면 기본 100vw 로 원본을 한 번 받고 sizes 가 붙은 뒤 lg 를 또 받았다(실측 2/2, Chromium 1건). 속성은
 * sizes → srcSet → src 순서로 적는다. 이 플러그인 설정은 다른 확장처럼 Chromium 프로젝트 하나다 — WebKit 으로 잴 때는 webkit 프로젝트를
 * 더한 임시 설정으로 이 spec 을 돌린다(WebKit 은 밑줄 든 호스트의 https 접속이 안 되어 http 주소를 쓴다).
 */
import { test, expect, createBanner, gotoUser, hidePopups, slider, uploadBannerImage, PNG_1X1 } from '../fixtures/hero-auth';

test.setTimeout(120_000);

// @scenario thumbnail_query=slider_sizes_slot_width
// @effects hero_slider_sizes_follow_rendered_slot
test('1920 화면 본문 폭 배너의 첫 장 이미지는 칸 폭과 화면 배율에 맞는 후보 하나만 받는다(원본을 먼저 받지 않는다)', async ({ page, request, managerToken, created }) => {
  // 1920×640 PNG 를 브라우저 캔버스로 만든다 — 숫자 규격(md·lg)보다 커야 srcset 이 붙는다
  await page.goto('about:blank');
  const base64 = await page.evaluate(() => {
    const canvas = document.createElement('canvas');
    canvas.width = 1920;
    canvas.height = 640;
    const context = canvas.getContext('2d')!;
    context.fillStyle = '#336699';
    context.fillRect(0, 0, 1920, 640);
    return canvas.toDataURL('image/png').split(',')[1];
  });
  const tempKey = `e2e-single-${Date.now()}`;
  await uploadBannerImage(request, managerToken, { name: 'e2e-wide.png', mimeType: 'image/png', buffer: Buffer.from(base64, 'base64') }, tempKey);
  created.push(await createBanner(request, managerToken, { temp_key: tempKey, position: 'home_hero' }));

  await page.setViewportSize({ width: 1920, height: 1080 });
  const fetched: string[] = [];
  page.on('request', (req) => {
    if (req.resourceType() === 'image' && req.url().includes('/api/plugins/sirsoft-hero_section/images/')) fetched.push(req.url());
  });
  await gotoUser(page, '/');
  const image = page.locator('section.hs-slider[role="region"] img.hs-slider__image').first();
  await expect(image).toHaveAttribute('srcset', /\?size=lg 1280w/, { timeout: 30_000 });
  await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0), { timeout: 30_000 }).toBe(true);
  await page.waitForTimeout(1_000);

  const sizes = await image.getAttribute('sizes');
  expect(sizes, '본문 폭 칸을 잰 값').toMatch(/^\d+px$/);
  const slot = Number(sizes!.replace('px', ''));
  expect(slot).toBeLessThan(1920);
  const hash = (await image.getAttribute('src'))!.split('/').pop()!.split('?')[0];
  const first = fetched.filter((url) => url.includes(hash));
  expect(first, `받은 주소: ${first.join(', ')}`).toHaveLength(1);

  // 기대 후보는 화면 배율(DPR)로 정한다 — 칸 폭 × DPR 이상인 가장 작은 후보(없으면 원본 1920w). DPR1 은 1216 → lg 1280w,
  // DPR2(Desktop Safari) 는 2432 → 원본이 맞는 선택이다. 후보 폭은 srcset 에서 읽는다
  const dpr = await page.evaluate(() => window.devicePixelRatio);
  const candidates = (await image.getAttribute('srcset'))!.split(',').map((entry) => {
    const [url, descriptor] = entry.trim().split(/\s+/);
    return { url, width: Number(descriptor.replace('w', '')) };
  }).sort((a, b) => a.width - b.width);
  const expected = candidates.find((candidate) => candidate.width >= slot * dpr) ?? candidates[candidates.length - 1];
  expect(new URL(first[0]).search, `DPR ${dpr}, 칸 ${slot}px`).toBe(new URL(expected.url, first[0]).search);
});

// @scenario surface=hidden_slides_load_only_current_and_next
// @effects hidden_slide_media_deferred
test('fade 3장 첫 방문은 지금 장·다음 장 이미지 2장만 받고, 다음으로 넘기면 셋째 장을 그때 받는다', async ({ page }) => {
  // 효과·장 수는 공개 응답을 가로채 정한다(DB 를 바꾸지 않는다). fade 는 모든 장이 화면 안에 겹쳐 있어 loading=lazy 로는 막히지 않던 경우다
  await hidePopups(page);
  const fetched: string[] = [];
  await page.route('**/e2e-hero-fade-*.png', (route) => route.fulfill({ contentType: 'image/png', body: PNG_1X1 }));
  page.on('request', (req) => {
    if (/e2e-hero-fade-\d\.png/.test(req.url())) fetched.push(req.url());
  });
  const banners = [1, 2, 3].map((n) => ({
    id: 990900 + n, title: `페이드 ${n}`, title_visible: true, title_color: '#FFFFFF', title_align: 'left', description: '', description_visible: false,
    description_color: '#E5E7EB', button_text: '', link_url: '/', media_type: 'image', images: { pc: { url: `/e2e-hero-fade-${n}.png`, width: 1920, height: 640 }, mobile: null },
  }));
  await page.route('**/api/plugins/sirsoft-hero_section/hero?*', (route) => route.fulfill({ json: { success: true, data: { position: 'home_hero', banners, settings: { autoplay: false, interval_seconds: 5, effect: 'fade', width: 'full', pagination: 'dots' }, abilities: { can_update: false } } } }));

  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoUser(page, '/');
  const section = slider(page);
  await expect(section.locator('[data-banner-id="990901"] img.hs-slider__image')).toHaveAttribute('src', /e2e-hero-fade-1/, { timeout: 60_000 });
  await expect.poll(() => new Set(fetched).size, { timeout: 30_000 }).toBe(2);
  await page.waitForTimeout(1_500);
  expect([...new Set(fetched)].map((url) => url.match(/fade-(\d)/)![1]).sort(), `받은 주소: ${fetched.join(', ')}`).toEqual(['1', '2']);
  expect(await section.locator('[data-banner-id="990903"] img.hs-slider__image').getAttribute('src')).toBeNull();

  await section.locator('.hs-slider__next').click();
  await expect.poll(() => new Set(fetched).size, { timeout: 30_000 }).toBe(3);
  expect(fetched, '같은 그림을 두 번 받지 않는다').toHaveLength(3);
});
