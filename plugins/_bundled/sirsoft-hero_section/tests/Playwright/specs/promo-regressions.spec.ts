/**
 * 프로모션형 기존 옵션과 대상 설정 응답 격리 회귀.
 * @scenario-manifest tests/scenarios/target-settings.yaml
 * 공개 응답/저장 응답만 대체한다. 운영 배너·설정에 쓰지 않는다.
 */
import { test, expect, authenticatePage, hidePopups, gotoUser, API, ADMIN_LIST, auth, createBanner, uniqueTitle } from '../fixtures/hero-auth';
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

// @scenario surface=promo_mobile_media_share
// @effects promo_existing_media_and_height_options_work
test('휴대폰 프로모션형은 낮은 최대 높이에서도 그림 몫을 남기고 문구는 칸 안에서 스크롤한다', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const text = { title: '개발자 채용 설명회에 초대합니다', eyebrow: '이벤트', eyebrow_visible: true, description: '현직자가 직접 알려주는 기술 면접 준비법과 실무 이야기를 들어 보세요.' };
  // 넘김 단추가 문구 칸 아래쪽을 차지하는 2장 — 이 조건에서 그림 칸이 0 이 되던 반례(240·320)와, 문구 칸 200 도 남지 않는 160(문구 우선)
  const measured: Record<string, { media: number; img: number; text: number; scrollable: boolean }> = {};
  let max: number | null = 240;
  await hidePopups(page);
  // 높이마다 같은 응답에서 max_height_mobile 만 바꾼다
  await page.route('**/api/plugins/sirsoft-hero_section/hero?*', route => route.fulfill({ json: { success: true, data: { position: 'board_hero', target: 'event', banners: [{ ...banner(), ...text }, { ...banner(), ...text, id: 990875 }], settings: { autoplay: false, interval_seconds: 5, effect: 'none', width: 'full', pagination: 'dots', max_height_mobile: max }, abilities: { can_update: false } } } }));
  for (const cap of [240, 320, 160]) {
    max = cap;
    await gotoUser(page, '/board/event');
    const slide = page.locator('.hs-slider__slide--active');
    await expect(slide.locator('.hs-promo__media img')).toBeAttached({ timeout: 60_000 });
    await expect.poll(() => page.locator('section.hs-slider[role="region"]').evaluate(el => Math.round(el.getBoundingClientRect().height))).toBe(cap);
    measured[cap] = await slide.evaluate(el => {
      const h = (q: string) => Math.round((el.querySelector(q) as HTMLElement).getBoundingClientRect().height);
      const sc = el.querySelector('.hs-slider__scroll') as HTMLElement;
      return { media: h('.hs-promo__media'), img: h('.hs-promo__media img'), text: h('.hs-promo__text'), scrollable: sc.scrollHeight > sc.clientHeight && sc.getAttribute('tabindex') === '0' };
    });
  }
  await info.attach('media-share', { body: JSON.stringify(measured), contentType: 'application/json' });
  // 240: 문구 칸 200 을 남기고 그림 40 · 320: 그림 120(3:1 그림 전체) · 160: 문구 칸 200 도 안 되므로 문구 우선
  expect(measured[240].media).toBeGreaterThanOrEqual(39); expect(measured[240].img).toBeGreaterThan(30); expect(measured[240].text).toBeGreaterThanOrEqual(199);
  expect(measured[320].media).toBeGreaterThanOrEqual(119); expect(measured[320].img).toBeGreaterThanOrEqual(110);
  expect(measured[160].media).toBe(0); expect(measured[160].text).toBe(160);
  for (const cap of [240, 320, 160]) expect(measured[cap].scrollable).toBe(true);
});

// @scenario surface=promo_tablet_min_height
// @effects promo_existing_media_and_height_options_work
test('태블릿에서 프로모션형 띠는 최소 400px(최대 높이 이하)라 단추까지 띠 안에 들고, 배경형만 있으면 높이가 그대로다', async ({ page }, info) => {
  test.setTimeout(480_000); // 같은 화면을 세 번 연다 — 개발 서버의 첫 로딩이 느린 날이 있다
  await page.setViewportSize({ width: 768, height: 1000 });
  await hidePopups(page);
  const text = { title: '소음은 줄이고\n음악은 더 선명하게', eyebrow: '신제품 출시', eyebrow_visible: true, description: '주변 소리를 걸러 주는 노이즈 캔슬링으로 출퇴근길에도 내 음악에만 집중하세요.' };
  let current: { layout: string; max: number | null } = { layout: 'promo', max: 420 };
  await page.route('**/api/plugins/sirsoft-hero_section/hero?*', route => route.fulfill({ json: { success: true, data: { position: 'board_hero', target: 'event', banners: [{ ...banner(current.layout), ...text }, { ...banner(current.layout), ...text, id: 990875 }], settings: { autoplay: false, interval_seconds: 5, effect: 'fade', width: 'full', pagination: 'pill', max_height_pc: current.max }, abilities: { can_update: false } } } }));
  const measured: Record<string, { hero: number; buttonBottom: number; heroBottom: number }> = {};
  for (const [name, layout, max] of [['promo-420', 'promo', 420], ['promo-300', 'promo', 300], ['background-420', 'background', 420]] as const) {
    current = { layout, max };
    await gotoUser(page, '/board/event');
    const section = page.locator('section.hs-slider[role="region"]');
    await expect(section.locator('.hs-slider__slide--active .hs-slider__button')).toBeAttached({ timeout: 150_000 });
    measured[name] = await section.evaluate(el => { const r = el.getBoundingClientRect(); const b = el.querySelector('.hs-slider__slide--active .hs-slider__button')!.getBoundingClientRect(); return { hero: Math.round(r.height), buttonBottom: Math.round(b.bottom), heroBottom: Math.round(r.bottom) }; });
  }
  await info.attach('tablet-heights', { body: JSON.stringify(measured), contentType: 'application/json' });
  expect(measured['promo-420'].hero).toBe(400);
  expect(measured['promo-420'].buttonBottom).toBeLessThanOrEqual(measured['promo-420'].heroBottom);
  expect(measured['promo-300'].hero).toBe(300);
  expect(measured['background-420'].hero).toBe(256);
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
test('R4 수정 폼 대상 설정500은 잘못된 미리보기를 숨기고, 실패 중 새로 입력한 문구를 다시 받은 뒤에도 남긴다', async ({ page, request, managerToken, created }) => {
  // 운영 배너를 열지 않는다 — 비노출 시험 배너를 만들고(created 픽스처가 지운다) 저장하지 않은 입력만 확인한다
  const id = await createBanner(request, managerToken, { position: 'board_hero', target_key: '4', is_active: false });
  created.push(id);
  await authenticatePage(page, managerToken);
  let broken = true;
  const writes: string[] = [];
  page.on('request', r => { if (r.url().includes(`${API}/admin/banners`) && r.method() !== 'GET') writes.push(`${r.method()} ${r.url()}`); });
  await page.route(`**${API}/admin/position-settings/board_hero/targets/4`, route => broken ? route.fulfill({ status: 500, json: { success: false, message: '시험 오류' } }) : route.continue());
  await page.goto(`${ADMIN_LIST}/${id}/edit`);
  await expect(page.getByText('이 대상의 설정을 받지 못했습니다.', { exact: false })).toBeVisible({ timeout: 60_000 });
  await expect(page.locator('.hs-preview__viewport')).toHaveCount(0);
  const typed = uniqueTitle('실패 중 입력');
  const title = page.locator('[name="title[ko]"]');
  await title.fill(typed);
  await expect.poll(() => page.evaluate(() => (window as any).G7Core.state.getLocal().form.title)).toMatchObject({ ko: typed });
  broken = false; await page.getByRole('button', { name: '다시 받기', exact: true }).click();
  await expect(page.locator('.hs-preview__viewport')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('이 대상의 설정을 받지 못했습니다.', { exact: false })).toHaveCount(0);
  await expect(title).toHaveValue(typed);
  expect(await page.evaluate(() => (window as any).G7Core.state.getLocal().form.title)).toMatchObject({ ko: typed });
  expect(await page.evaluate(() => (window as any).G7Core.state.getLocal().hasChanges)).toBe(true);
  expect(writes).toEqual([]);
});

/** 대상 설정 GET 을 이 대상만 재정의(effect=fade)한 응답으로 바꾸고 받은 횟수를 센다 — 서버에 쓰지 않는다. */
async function fadeTarget(page: Page, request: APIRequestContext, token: string, target: string) {
  const endpoint = `${API}/admin/position-settings/board_hero/targets/${target}`;
  const original = await (await request.get(endpoint, { headers: auth(token) })).json();
  const seen = { count: 0 };
  await page.route(`**${endpoint}`, route => {
    if (route.request().method() !== 'GET') return route.abort();
    seen.count++;
    return route.fulfill({ json: { ...original, data: { ...original.data, overrides: { effect: 'fade' }, settings: { ...original.data.position_settings, effect: 'fade' } } } });
  });
  return seen;
}

// @scenario surface=target_settings_failure_retry
// @effects target_settings_retry_restores_controls
test('쿼리 없이 연 동적 위치 배너 수정 화면도 그 대상의 설정을 받아 적용 설정·미리보기를 그린다', async ({ page, request, managerToken, created }) => {
  // 목록의 제목 링크·사이트의 수정 단추는 /banners/{id}/edit 로만 연다 — 쿼리의 대상에 기대지 않는다
  const id = await createBanner(request, managerToken, { position: 'board_hero', target_key: '4', is_active: false });
  created.push(id);
  await authenticatePage(page, managerToken);
  const seen = await fadeTarget(page, request, managerToken, '4');
  await page.goto(`${ADMIN_LIST}/${id}/edit`);
  await expect(page.locator('.hs-preview__viewport')).toBeVisible({ timeout: 60_000 });
  await expect(page.getByText('적용 설정: 페이드', { exact: false })).toBeVisible();
  await expect(page.getByText('이 대상만의 설정이 포함돼 있습니다.', { exact: false })).toBeVisible();
  await expect(page.getByText('이 대상의 설정을 불러오는 중입니다.', { exact: true })).toHaveCount(0);
  expect(seen.count).toBe(1);
});

// @scenario surface=target_settings_failure_retry
// @effects target_settings_retry_restores_controls
test('등록 화면에서 정적 위치를 동적 위치로 바꾸고 대상을 고르면 그 대상의 설정을 받는다', async ({ page, request, managerToken }) => {
  await authenticatePage(page, managerToken);
  const seen = await fadeTarget(page, request, managerToken, '4');
  const writes: string[] = [];
  page.on('request', r => { if (r.url().includes(`${API}/admin/`) && r.method() !== 'GET') writes.push(r.method()); });
  await page.goto(`${ADMIN_LIST}/create?position=home_hero`);
  // 그림이 없는 새 배너라 미리보기는 빈 안내로 그린다 — 미리보기 자리와 「적용 설정」 요약을 본다
  const preview = page.locator('.hs-preview').first();
  await expect(page.getByText('적용 설정:', { exact: false })).toBeVisible({ timeout: 60_000 });
  await page.getByRole('radio', { name: /게시판 목록/ }).check();
  // 대상을 고르기 전에도 위치 기본값으로 요약·미리보기를 그린다
  await expect(page.getByText('위치 기본값입니다.', { exact: false })).toBeVisible();
  await expect(preview).toBeAttached();
  await page.locator('button').filter({ hasText: '게시판을(를) 고르세요' }).click();
  await page.getByPlaceholder('게시판 이름으로 찾기').fill('자유');
  const option = page.getByRole('option').first();
  await expect(option).toBeVisible({ timeout: 30_000 });
  const picked = await option.textContent();
  await page.unroute(`**${API}/admin/position-settings/board_hero/targets/4`);
  const pickedSeen = { count: 0 };
  await page.route(`**${API}/admin/position-settings/board_hero/targets/*`, route => { pickedSeen.count++; return route.continue(); });
  await option.click();
  await expect.poll(() => pickedSeen.count, { timeout: 30_000 }).toBe(1);
  await expect(page.getByText('적용 설정:', { exact: false })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('이 대상의 설정을 받지 못했습니다.', { exact: false })).toHaveCount(0);
  await expect(preview).toBeAttached();
  expect(picked).toBeTruthy(); expect(seen.count).toBe(0); expect(writes).toEqual([]);
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
