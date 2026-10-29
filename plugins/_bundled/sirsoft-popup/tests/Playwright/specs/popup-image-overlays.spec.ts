/**
 * @file popup-image-overlays.spec.ts
 * @description 이미지 위 글 상자 — 관리자 편집기에서 놓은 상자가 사용자 화면 이미지 위 같은 비율 자리(±1%)에 보이고,
 * 모바일 이미지를 따로 올리면 모바일 배치가 쓰이며, 상자 위 누름은 이미지 링크로·상자 위 손가락 밀기는 장 넘김으로 가고,
 * 언어를 바꾸면 상자 글이 바뀐다. (매니페스트 image-overlays-e2e.yaml)
 */
import { test, expect, auth, authenticatePage, createHtmlPopup, gotoUser, layer, layerIds, API, ADMIN_LIST, PNG_1X1, VIEWPORTS } from '../fixtures/popup-auth';

type Ctx = { page: any; request: any; managerToken: string; created: number[] };

/** 저장 모양 상자 */
const box = (extra: Record<string, unknown> = {}) => ({
  id: 'e2ebox01', x: 10, y: 60, w: 80, text: { ko: '가을 할인', en: 'Autumn sale' }, size: 8,
  color: '#FFFFFF', bold: true, align: 'center', bg: { color: '#000000', opacity: 50 }, shadow: true, ...extra,
});

/**
 * PC(원하면 모바일) 이미지를 올리고 글 상자가 있는 이미지형 팝업을 만든다.
 *
 * @param ctx 사양 fixture
 * @param overrides 바꿀 팝업 값 (overlays 포함)
 * @param mobile 모바일 이미지도 올릴지
 * @returns 팝업 id
 */
async function createOverlayPopup({ request, managerToken }: Ctx, overrides: Record<string, unknown>, mobile = false): Promise<number> {
  const tempKey = `e2e-ov-${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  for (const device of mobile ? ['pc', 'mobile'] : ['pc']) {
    const upload = await request.post(`${API}/admin/popup-images`, {
      headers: auth(managerToken),
      multipart: { file: { name: `e2e-${device}.png`, mimeType: 'image/png', buffer: PNG_1X1 }, device, temp_key: tempKey },
    });
    expect(upload.status(), await upload.text()).toBe(201);
  }

  return createHtmlPopup(request, managerToken, { content_type: 'image', image_alt: { ko: '글 상자 팝업' }, body_html: undefined, temp_key: tempKey, ...overrides });
}

/** 지금 보이는 장의 팝업 id */
async function currentSlideId(page: any): Promise<number> {
  return Number(await page.locator('[data-popup-layer=""] .g7pp-slide:not([hidden]):not([aria-hidden="true"])').getAttribute('data-popup-id'));
}

/** 사양이 만든 장으로 [다음 팝업]으로 넘겨 간다(사이트에 다른 팝업이 앞에 있을 수 있다) */
async function goToSlide(page: any, id: number): Promise<void> {
  const total = (await layerIds(page)).length;
  for (let i = 0; i < total && (await currentSlideId(page)) !== id; i++) {
    await layer(page).getByRole('button', { name: '다음 팝업' }).first().click();
    await expect(page.locator('[data-popup-layer=""] .g7pp-slide[aria-hidden="true"]')).toHaveCount(0);
  }
  await expect.poll(() => currentSlideId(page)).toBe(id);
}

/**
 * 상자 왼쪽 위·폭을 이미지 프레임 대비 % 로 잰다.
 *
 * @param page 페이지
 * @param scope 프레임을 찾을 범위 선택자
 * @param boxId 상자 id
 * @returns x·y·w (%)
 */
async function measure(page: any, scope: string, boxId: string): Promise<{ x: number; y: number; w: number }> {
  const frame = (await page.locator(`${scope} .g7pp-media__frame`).first().boundingBox())!;
  const el = (await page.locator(`${scope} .g7pp-overlay[data-overlay-id="${boxId}"]`).first().boundingBox())!;

  return { x: ((el.x - frame.x) / frame.width) * 100, y: ((el.y - frame.y) / frame.height) * 100, w: (el.width / frame.width) * 100 };
}

/** 관리자 편집기에서 상자를 놓고 저장하면 사용자 화면 이미지 위 같은 비율 자리에 같은 글이 보인다 */
async function flow_admin_places_boxes_shows_on_user(ctx: Ctx, viewport: 'desktop' | 'mobile'): Promise<void> {
  const { page, request, managerToken, created } = ctx;
  let id: number;
  let expected: { x: number; y: number; w: number };
  let boxId: string;

  if (viewport === 'desktop') {
    // 편집기 조작 — 상자 추가 → 글 입력 → 끌어 옮기기 → 저장
    id = await createOverlayPopup(ctx, {});
    created.push(id);
    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}/${id}/edit`);
    const editor = page.locator('[data-overlay-editor]');
    await editor.getByRole('button', { name: '글 상자 추가' }).click();
    const added = editor.locator('[data-overlay-canvas] .g7pp-overlay[data-overlay-id]').first();
    await expect(added).toBeVisible();
    await editor.locator('#g7pp-ov-text').fill('E2E 글 상자\n둘째 줄');
    // 이 배치는 휴대폰에도 보이므로(모바일 이미지 없음) 작은 휴대폰 기준 예상 글자 크기를 보인다
    await expect(editor.locator('[data-overlay-phone-size]')).toContainText('px');
    const frame = (await editor.locator('.g7pp-media__frame').boundingBox())!;
    const start = (await added.boundingBox())!;
    await page.mouse.move(start.x + 6, start.y + 6);
    await page.mouse.down();
    await page.mouse.move(start.x + 6 - frame.width * 0.05, start.y + 6 - frame.height * 0.2, { steps: 8 });
    await page.mouse.up();
    await page.getByRole('button', { name: '저장' }).click();
    await expect(page).toHaveURL(new RegExp(`${ADMIN_LIST}(\\?|$)`));

    const saved = (await (await request.get(`${API}/admin/popups/${id}`, { headers: auth(managerToken) })).json()).data.overlays;
    expect(saved.pc).toHaveLength(1);
    expect(saved.pc[0].text.ko).toBe('E2E 글 상자\n둘째 줄');
    // 새 상자는 x 10·y 40 에서 시작 — 왼쪽 5%·위로 20% 끌었다(이미지 밖으로 나가지 않음)
    expect(saved.pc[0].x).toBeCloseTo(5, 0);
    expect(saved.pc[0].y).toBeCloseTo(20, 0);
    expected = saved.pc[0];
    boxId = saved.pc[0].id;
    await page.context().clearCookies();
  } else {
    id = await createOverlayPopup(ctx, { overlays: { pc: [box()], mobile: null } });
    created.push(id);
    expected = box();
    boxId = 'e2ebox01';
  }

  await gotoUser(page, '/');
  await goToSlide(page, id);
  const slide = `.g7pp-slide[data-popup-id="${id}"]`;
  const overlay = page.locator(`${slide} .g7pp-overlay[data-overlay-id="${boxId}"]`);
  await expect(overlay).toBeVisible();
  const got = await measure(page, slide, boxId);
  expect(Math.abs(got.x - expected.x)).toBeLessThanOrEqual(1);
  expect(Math.abs(got.y - expected.y)).toBeLessThanOrEqual(1);
  expect(Math.abs(got.w - expected.w)).toBeLessThanOrEqual(1);
  // 글자 크기는 이미지 폭에 비례하고 글자는 선택되지 않는다
  const [fontPx, frameW, userSelect] = await overlay.evaluate((el: HTMLElement) => [
    parseFloat(getComputedStyle(el).fontSize),
    (el.parentElement as HTMLElement).getBoundingClientRect().width,
    getComputedStyle(el).userSelect,
  ]);
  expect(Math.abs((fontPx / frameW) * 100 - Number((expected as any).size ?? 6))).toBeLessThanOrEqual(0.5);
  expect(userSelect).toBe('none');
  // 글이 길어 넘쳐도 이미지 경계에서 잘린다
  expect(await page.locator(`${slide} .g7pp-media__frame`).evaluate((el: HTMLElement) => getComputedStyle(el).overflow)).toBe('hidden');
}

/** 모바일 이미지를 따로 올리면 휴대폰은 모바일 배치를 쓴다 */
async function flow_mobile_separate_layout(ctx: Ctx): Promise<void> {
  const { page, created } = ctx;
  const id = await createOverlayPopup(ctx, { overlays: { pc: [box()], mobile: [box({ id: 'e2emob01', y: 5, w: 60, text: { ko: '모바일 글' } })] } }, true);
  created.push(id);

  await gotoUser(page, '/');
  await goToSlide(page, id);
  const slide = `.g7pp-slide[data-popup-id="${id}"]`;
  await expect(page.locator(`${slide} .g7pp-overlay[data-overlay-id="e2emob01"]`)).toHaveText('모바일 글');
  await expect(page.locator(`${slide} .g7pp-overlay[data-overlay-id="e2ebox01"]`)).toHaveCount(0);
  const got = await measure(page, slide, 'e2emob01');
  expect(Math.abs(got.y - 5)).toBeLessThanOrEqual(1);
  expect(Math.abs(got.w - 60)).toBeLessThanOrEqual(1);
}

/** 상자 위를 눌러도 이미지 링크로 간다 */
async function flow_box_click_follows_image_link(ctx: Ctx): Promise<void> {
  const { page, created } = ctx;
  const id = await createOverlayPopup(ctx, { link_url: '/page/about', overlays: { pc: [box()], mobile: null } });
  created.push(id);

  await gotoUser(page, '/');
  await goToSlide(page, id);
  const target = (await page.locator(`.g7pp-slide[data-popup-id="${id}"] .g7pp-overlay`).boundingBox())!;
  await page.mouse.click(target.x + target.width / 2, target.y + target.height / 2);
  await expect(page).toHaveURL(/\/page\/about$/);
}

/** 상자 위에서 손가락으로 밀어도 장이 넘어간다 */
async function flow_swipe_on_box_changes_slide(ctx: Ctx): Promise<void> {
  const { page, request, managerToken, created } = ctx;
  const id = await createOverlayPopup(ctx, { link_url: '/page/about', overlays: { pc: [box({ y: 30, w: 84, x: 8 })], mobile: null } });
  const next = await createHtmlPopup(request, managerToken, { body_html: { ko: '<p>상자 위 밀기로 넘긴 장</p>' } });
  created.push(id, next);

  await gotoUser(page, '/');
  const ids = await layerIds(page);
  expect(ids.indexOf(next)).toBe(ids.indexOf(id) + 1);
  await goToSlide(page, id);
  const target = (await page.locator(`.g7pp-slide[data-popup-id="${id}"] .g7pp-overlay`).boundingBox())!;
  const y = target.y + target.height / 2;
  const fromX = target.x + target.width * 0.85;
  const toX = target.x + target.width * 0.1;
  const startUrl = page.url();
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: fromX, y }] });
  for (let i = 1; i <= 10; i++) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: fromX + ((toX - fromX) * i) / 10, y }] });
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });

  await expect.poll(() => currentSlideId(page)).toBe(next);
  expect(page.url()).toBe(startUrl);
}

/** 언어를 바꾸면 상자 글이 그 언어로(빈 언어는 기본 언어 글) */
async function flow_locale_switch_changes_box_text(ctx: Ctx): Promise<void> {
  const { page, created } = ctx;
  const id = await createOverlayPopup(ctx, { overlays: { pc: [box(), box({ id: 'e2eko001', y: 10, text: { ko: '한국어만' } })], mobile: null } });
  created.push(id);

  await gotoUser(page, '/');
  const slide = `.g7pp-slide[data-popup-id="${id}"]`;
  await expect(page.locator(`${slide} .g7pp-overlay[data-overlay-id="e2ebox01"]`)).toHaveText('가을 할인');
  await page.evaluate(() => (window as any).G7Core.dispatch({ handler: 'setLocale', target: 'en' }));
  await expect(page.locator(`${slide} .g7pp-overlay[data-overlay-id="e2ebox01"]`)).toHaveText('Autumn sale', { timeout: 15_000 });
  await expect(page.locator(`${slide} .g7pp-overlay[data-overlay-id="e2eko001"]`)).toHaveText('한국어만');
  await page.evaluate(() => (window as any).G7Core.dispatch({ handler: 'setLocale', target: 'ko' }));
}

test.describe('이미지 위 글 상자 → 사용자 화면', () => {
  // @scenario flow=admin_places_boxes_shows_on_user, viewport=desktop
  // @effects boxes_follow_image_scale, test_data_cleaned_up
  test('관리자 편집기에서 놓은 상자가 사용자 화면 이미지 위 같은 비율 자리에 보인다 (desktop)', async ({ page, request, managerToken, created }) => {
    // 관리자 편집·저장 뒤 사용자 화면에서 사이트의 다른 팝업을 넘겨 가야 해 기본 30초로는 모자란다
    test.setTimeout(90_000);
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_admin_places_boxes_shows_on_user({ page, request, managerToken, created }, 'desktop');
  });

  // @scenario flow=admin_places_boxes_shows_on_user, viewport=mobile
  // @effects boxes_follow_image_scale, test_data_cleaned_up
  test('PC 배치는 휴대폰 화면 이미지 위에서도 같은 비율 자리 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flow_admin_places_boxes_shows_on_user({ page, request, managerToken, created }, 'mobile');
  });

  // @scenario flow=mobile_separate_layout, viewport=mobile
  // @effects boxes_follow_image_scale, test_data_cleaned_up
  test('모바일 이미지를 따로 올리면 휴대폰은 모바일 배치 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flow_mobile_separate_layout({ page, request, managerToken, created });
  });

  // @scenario flow=box_click_follows_image_link, viewport=desktop
  // @effects image_link_and_swipe_unchanged, test_data_cleaned_up
  test('상자 위를 눌러도 이미지 링크로 간다 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_box_click_follows_image_link({ page, request, managerToken, created });
  });

  // @scenario flow=locale_switch_changes_box_text, viewport=desktop
  // @effects test_data_cleaned_up
  test('언어를 바꾸면 상자 글이 그 언어로 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_locale_switch_changes_box_text({ page, request, managerToken, created });
  });
});

test.describe('이미지 위 글 상자 → 사용자 화면 (터치)', () => {
  // 실제 터치 입력(CDP touch)을 받으려면 터치 지원 문맥이어야 한다
  test.use({ hasTouch: true, viewport: VIEWPORTS.mobile });

  // @scenario flow=swipe_on_box_changes_slide, viewport=mobile
  // @effects image_link_and_swipe_unchanged, test_data_cleaned_up
  test('상자 위에서 손가락으로 밀어도 장이 넘어가고 링크로 가지 않는다 (mobile)', async ({ page, request, managerToken, created }) => {
    await flow_swipe_on_box_changes_slide({ page, request, managerToken, created });
  });
});
