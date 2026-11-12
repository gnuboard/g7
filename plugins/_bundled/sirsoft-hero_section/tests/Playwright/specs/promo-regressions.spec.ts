/**
 * 프로모션형 기존 옵션과 대상 설정 응답 격리 회귀.
 * @scenario-manifest tests/scenarios/target-settings.yaml
 * 공개 응답/저장 응답만 대체한다. 운영 배너·설정에 쓰지 않는다.
 */
import { test, expect, authenticatePage, hidePopups, gotoUser, API, ADMIN_LIST, auth } from '../fixtures/hero-auth';
import type { Page, APIRequestContext } from '@playwright/test';

test.setTimeout(180_000);
const IMAGE = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="640"><rect width="1920" height="640" fill="#98b7ff"/></svg>');
const banner = (layout = 'promo') => ({ id: 990874, title: '회귀 시험 제목', title_visible: true, title_color: '#111827', title_align: 'left', description: '', description_visible: true, description_color: '#111827', button_text: '자세히 보기', link_url: '/board/event', images: { pc: { url: IMAGE, width: 1920, height: 640 }, mobile: null }, media_type: 'image', presentation: { layout, background_color: '#f4f5f8', text_width_pc: 40, text_width_mobile: 100, text_y_mobile: 0, overlay: 'none', text_animation: 'none' } });
async function inject(page: Page, item: any, extra = {}) {
  await hidePopups(page);
  await page.route('**/api/plugins/sirsoft-hero_section/hero?*', route => route.fulfill({ json: { success: true, data: { position: 'board_hero', target: 'event', banners: [item], settings: { autoplay: false, interval_seconds: 5, effect: 'none', width: 'full', pagination: 'dots', ...extra }, abilities: { can_update: false } } } }));
}

// @scenario surface=promo_video_poster_overlap
// @effects promo_existing_media_and_height_options_work
test('R1 프로모션 영상과 대표 이미지는 같은 그림 칸 안에 겹친다', async ({ page }, info) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const clip = await page.evaluate(async () => {
    const canvas = document.createElement('canvas'); canvas.width = 320; canvas.height = 180;
    canvas.getContext('2d')!.fillRect(0, 0, 320, 180);
    const stream = canvas.captureStream(20); const recorder = new MediaRecorder(stream, { mimeType: 'video/webm' }); const chunks: Blob[] = [];
    recorder.ondataavailable = e => chunks.push(e.data); recorder.start();
    await new Promise(r => setTimeout(r, 1000)); await new Promise(r => { recorder.onstop = r; recorder.stop(); }); stream.getTracks().forEach(t => t.stop());
    const bytes = new Uint8Array(await new Blob(chunks).arrayBuffer()); return btoa(Array.from(bytes, x => String.fromCharCode(x)).join(''));
  });
  await page.route('**/e2e-promo.webm', route => route.fulfill({ contentType: 'video/webm', body: Buffer.from(clip, 'base64') }));
  await inject(page, { ...banner(), media_type: 'video', videos: { pc: { url: '/e2e-promo.webm', mime: 'video/webm' }, mobile: null } });
  await gotoUser(page, '/board/event');
  const media = page.locator('.hs-slider__slide--active .hs-promo__media');
  await expect(media.locator('video')).toBeAttached({ timeout: 60_000 });
  const boxes = await media.evaluate(el => { const rect = (e: Element) => { const r = e.getBoundingClientRect(); return { left: r.left, right: r.right, width: r.width }; }; return { media: rect(el), image: rect(el.querySelector('img')!), video: rect(el.querySelector('video')!) }; });
  await info.attach('R1-measurements', { body: JSON.stringify(boxes), contentType: 'application/json' });
  for (const child of [boxes.image, boxes.video]) { expect(child.left).toBeGreaterThanOrEqual(boxes.media.left - 1); expect(child.right).toBeLessThanOrEqual(boxes.media.right + 1); }
  expect(Math.min(boxes.image.right, boxes.video.right) - Math.max(boxes.image.left, boxes.video.left)).toBeGreaterThan(100);
});

// @scenario surface=promo_mobile_capped_text_scroll
// @effects promo_existing_media_and_height_options_work
test('R2 프로모션 모바일 높이160에서도 긴 문구를 스크롤해 버튼에 도달한다', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await inject(page, { ...banner(), title: '긴 제목 '.repeat(20), eyebrow: '머리글 '.repeat(10), eyebrow_visible: true, description: '긴 설명을 끝까지 읽을 수 있어야 합니다. '.repeat(10) }, { max_height_mobile: 160 });
  await gotoUser(page, '/board/event');
  const scroll = page.locator('.hs-slider__slide--active .hs-slider__scroll');
  await expect(scroll).toBeAttached({ timeout: 60_000 });
  await expect(scroll).toHaveAttribute('tabindex', '0');
  const data = await scroll.evaluate(el => { const slider = el.closest('section')!.getBoundingClientRect(); const box = el.getBoundingClientRect(); return { client: el.clientHeight, scroll: el.scrollHeight, top: box.top, bottom: box.bottom, sliderTop: slider.top, sliderBottom: slider.bottom, overflow: getComputedStyle(el).overflowY }; });
  await info.attach('R2-measurements', { body: JSON.stringify(data), contentType: 'application/json' });
  expect(data.client).toBeGreaterThan(20); expect(data.scroll).toBeGreaterThan(data.client); expect(data.bottom).toBeLessThanOrEqual(data.sliderBottom + 1);
  await scroll.focus(); await page.keyboard.press('End');
  await expect.poll(() => scroll.evaluate(el => el.scrollTop)).toBeGreaterThan(0);
  const button = scroll.locator('.hs-slider__button'); await expect(button).toBeInViewport();
});

// @scenario surface=target_settings_late_response_isolated
// @effects target_draft_preserved_after_late_response
async function lateResponse(page: Page, request: APIRequestContext, managerToken: string, method: 'PUT' | 'DELETE') {
  await hidePopups(page); await authenticatePage(page, managerToken);
  const endpoint = `${API}/admin/position-settings/board_hero/targets/4`;
  const original = await (await request.get(endpoint, { headers: auth(managerToken) })).json();
  let release!: () => void; const gate = new Promise<void>(r => { release = r; }); let started = false;
  await page.route(`**${endpoint}`, async route => {
    if (route.request().method() === 'GET' && method === 'DELETE') return route.fulfill({ json: { ...original, data: { ...original.data, overrides: { effect: 'fade' }, settings: { ...original.data.position_settings, effect: 'fade' } } } });
    if (route.request().method() !== method) return route.continue();
    started = true; await gate;
    const overrides = method === 'DELETE' ? {} : route.request().postDataJSON().overrides;
    await route.fulfill({ json: { ...original, message: '시험 저장', data: { ...original.data, overrides, settings: { ...original.data.position_settings, ...overrides } } } });
  });
  try {
    await page.goto(`${ADMIN_LIST}?position=board_hero&target=4`);
    const effect = page.locator('#hero_target_inherit_effect'); await expect(effect).toBeEnabled({ timeout: 60_000 });
    if (method === 'PUT') await effect.check();
    await page.getByRole('button', { name: method === 'PUT' ? '이 대상 설정 저장' : '위치 기본값으로 되돌리기', exact: true }).click(); await expect.poll(() => started).toBe(true);
    await page.locator('button').filter({ hasText: /^이벤트$/ }).click();
    await page.locator('input[placeholder*="게시판"]').fill('자유');
    await page.getByRole('option').filter({ hasText: '자유게시판' }).click();
    const own = page.locator('#hero_target_inherit_pagination'); await expect(own).toBeEnabled(); await own.check();
    expect(await page.evaluate(() => Object.keys((window as any).G7Core.state.getLocal().targetForm.overrides))).toEqual(['pagination']);
    release(); await expect(page.getByText('시험 저장', { exact: true })).toHaveCount(0);
    await page.waitForTimeout(1500);
    await expect(own).toBeChecked(); await expect(effect).toBeEnabled(); await expect(page.getByRole('button', { name: '이 대상 설정 저장', exact: true })).toBeEnabled(); await expect(page.getByText('이 대상의 설정을 불러오는 중입니다', { exact: false })).toHaveCount(0);
  } finally { release(); }
}

// @scenario surface=target_settings_late_response_isolated
// @effects target_draft_preserved_after_late_response
test('R3 A 저장 응답이 늦어도 B의 재정의 작업본과 설정 응답을 보존한다', async ({ page, request, managerToken }) => lateResponse(page, request, managerToken, 'PUT'));
// @scenario surface=target_settings_late_response_isolated
// @effects target_draft_preserved_after_late_response
test('R3 A 되돌리기 응답이 늦어도 B의 재정의 작업본과 설정 응답을 보존한다', async ({ page, request, managerToken }) => lateResponse(page, request, managerToken, 'DELETE'));

// @scenario surface=target_settings_failure_retry
// @effects target_settings_retry_restores_controls
test('R4 목록 대상 설정500 뒤 재시도로 복구한다', async ({ page, managerToken }) => {
  await authenticatePage(page, managerToken);
  let broken = true;
  await page.route(`**${API}/admin/position-settings/board_hero/targets/4`, route => broken ? route.fulfill({ status: 500, json: { success: false, message: '시험 오류' } }) : route.continue());
  await page.goto(`${ADMIN_LIST}?position=board_hero&target=4`);
  const failed = page.getByText('이 대상의 설정을 받지 못했습니다.', { exact: false });
  await expect(failed).toBeVisible({ timeout: 60_000 });
  await expect(page.getByText('이 대상의 설정을 불러오는 중입니다.', { exact: true })).toHaveCount(0);
  await expect(page.locator('#hero_target_inherit_effect')).toBeDisabled();
  broken = false; await page.getByRole('button', { name: '다시 받기', exact: true }).click();
  await expect(page.locator('#hero_target_inherit_effect')).toBeEnabled({ timeout: 30_000 }); await expect(failed).toHaveCount(0);
});

// @scenario surface=target_settings_failure_retry
// @effects target_settings_retry_restores_controls
test('R4 수정 폼 대상 설정500은 잘못된 미리보기를 숨기고 다시 받을 때 입력을 보존한다', async ({ page, managerToken }) => {
  await authenticatePage(page, managerToken);
  let broken = true;
  await page.route(`**${API}/admin/position-settings/board_hero/targets/4`, route => broken ? route.fulfill({ status: 500, json: { success: false, message: '시험 오류' } }) : route.continue());
  await page.goto(`${ADMIN_LIST}/1442/edit?position=board_hero&target=4`);
  await expect(page.getByText('이 대상의 설정을 받지 못했습니다.', { exact: false })).toBeVisible({ timeout: 60_000 });
  await expect(page.locator('.hs-preview__viewport')).toHaveCount(0);
  const before = await page.evaluate(() => (window as any).G7Core.state.getLocal().form.title);
  broken = false; await page.getByRole('button', { name: '다시 받기', exact: true }).click();
  await expect(page.locator('.hs-preview__viewport')).toBeVisible({ timeout: 30_000 });
  expect(await page.evaluate(() => (window as any).G7Core.state.getLocal().form.title)).toEqual(before);
});

// @scenario surface=target_settings_partial_inherit
// @effects inherited_items_follow_position_change
test('R5 위치 기본값 저장 뒤 상속 대상 카드도 최신 효과를 쓴다', async ({ page, request, managerToken }) => {
  await authenticatePage(page, managerToken);
  const original = await (await request.get(`${API}/admin/position-settings`, { headers: auth(managerToken) })).json();
  let saved: any = null;
  await page.route(`**${API}/admin/position-settings`, route => route.fulfill({ json: saved ? { ...original, data: { ...original.data, positions: original.data.positions.map((p: any) => p.key === 'board_hero' ? { ...p, settings: saved } : p) } } : original }));
  await page.route(`**${API}/admin/position-settings/board_hero`, route => {
    if (route.request().method() !== 'PUT') return route.continue();
    saved = route.request().postDataJSON(); return route.fulfill({ json: { success: true, message: '시험 위치 저장', data: { position: 'board_hero', settings: saved } } });
  });
  await page.goto(`${ADMIN_LIST}?position=board_hero&target=4`);
  await expect(page.locator('#hero_target_inherit_effect')).toBeEnabled({ timeout: 60_000 });
  const next = original.data.positions.find((p: any) => p.key === 'board_hero').settings.effect === 'fade' ? '슬라이드' : '페이드';
  await page.locator('#hero_slider_effect button').first().click(); await page.getByRole('option', { name: next, exact: true }).click();
  await page.getByRole('button', { name: '설정 저장', exact: true }).click();
  await expect(page.locator('#hero_target_effect button').first()).toHaveText(next, { timeout: 30_000 });
  await expect(page.locator('#hero_target_inherit_effect')).not.toBeChecked();
});
