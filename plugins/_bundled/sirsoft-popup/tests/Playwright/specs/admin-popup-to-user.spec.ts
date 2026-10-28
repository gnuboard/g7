/**
 * @file admin-popup-to-user.spec.ts
 * @description 관리자가 등록·수정·순서를 바꾼 팝업이 사용자 화면에 조건대로 뜨는지 — 이미지형·직접 작성형, 노출 페이지 경계,
 * 회원만, 모바일만, 순서 변경, 오늘 하루 보지 않기 뒤 새 팝업, 닫기 뒤 SPA 이동, 끌어서 넘기기(마우스·터치), 조회 전용 관리자. (매니페스트 e2e-flows.yaml)
 */
import { test, expect, auth, authenticatePage, createHtmlPopup, createImagePopup, gotoUser, layer, layerIds, navigateSpa, uniqueTitle, ADMIN_LIST, API, VIEWPORTS } from '../fixtures/popup-auth';

type Viewport = 'desktop' | 'mobile';
// 흐름 함수가 받는 사양 fixture — 각 test() 가 필요한 것만 넘긴다
type Ctx = { page: any; request: any; managerToken: string; memberToken: string; created: number[] };

/** 이미지형 팝업이 홈에 뜬다 */
async function flow_admin_creates_image_popup_shows_on_home({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
    const id = await createImagePopup(request, managerToken, { link_url: '/page/about' });
    created.push(id);

    await gotoUser(page, '/');
    await expect(layer(page)).toBeVisible();
    expect(await layerIds(page)).toContain(id);
    const slide = page.locator(`.g7pp-slide[data-popup-id="${id}"]`);
    await expect(slide.locator('img')).toHaveAttribute('src', /\/api\/plugins\/sirsoft-popup\/images\//);
    await expect(slide.locator('a.g7pp-cover-link')).toHaveAttribute('aria-label', '이미지 대체 문구');
}

/** 노출 경로 — 하위 경로에 뜨고 경계가 아닌 경로에는 안 뜬다 */
async function flow_route_scope_shows_on_child_not_on_prefix_sibling({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
    const shop = await createHtmlPopup(request, managerToken, { page_scope: 'routes', routes: ['/shop'] });
    const prefix = await createHtmlPopup(request, managerToken, { page_scope: 'routes', routes: ['/page/ab'] });
    created.push(shop, prefix);

    await gotoUser(page, '/shop/cart');
    await expect(layer(page)).toBeVisible();
    expect(await layerIds(page)).toContain(shop);
    expect(await layerIds(page)).not.toContain(prefix);

    await gotoUser(page, '/page/about');
    // /page/ab 는 /page/about 의 경로 경계가 아니다
    await expect(page.locator(`.g7pp-slide[data-popup-id="${prefix}"]`)).toHaveCount(0);
    await expect(page.locator(`.g7pp-slide[data-popup-id="${shop}"]`)).toHaveCount(0);
}

/** 회원만 팝업 — 손님에게 안 뜨고 회원에게 뜬다 */
async function flow_audience_member_only({ page, request, managerToken, memberToken, created }: Ctx, viewport: Viewport): Promise<void> {
    const marker = await createHtmlPopup(request, managerToken);
    const memberOnly = await createHtmlPopup(request, managerToken, { audience: 'member' });
    created.push(marker, memberOnly);

    await gotoUser(page, '/');
    await expect(page.locator(`.g7pp-slide[data-popup-id="${marker}"]`)).toHaveCount(1);
    expect(await layerIds(page)).not.toContain(memberOnly);

    await authenticatePage(page, memberToken);
    await gotoUser(page, '/');
    await expect(page.locator(`.g7pp-slide[data-popup-id="${memberOnly}"]`)).toHaveCount(1);
}

/** 모바일 전용 팝업은 모바일 폭에서만 */
async function flow_device_mobile_only({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
    const marker = await createHtmlPopup(request, managerToken);
    const mobileOnly = await createHtmlPopup(request, managerToken, { device: 'mobile' });
    created.push(marker, mobileOnly);

    await gotoUser(page, '/');
    await expect(page.locator(`.g7pp-slide[data-popup-id="${marker}"]`)).toHaveCount(1);
    await expect(page.locator(`.g7pp-slide[data-popup-id="${mobileOnly}"]`)).toHaveCount(viewport === 'mobile' ? 1 : 0);
    if (viewport === 'mobile') {
      await expect(layer(page)).toHaveClass(/g7pp-dialog--m-(sheet|center)/);
    }
}

/** 오늘 하루 보지 않기 뒤 새로 올린 팝업은 바로 뜬다 */
async function flow_dismiss_today_hides_then_new_popup_shows({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
    const first = await createHtmlPopup(request, managerToken);
    created.push(first);

    await gotoUser(page, '/');
    await expect(page.locator(`.g7pp-slide[data-popup-id="${first}"]`)).toHaveCount(1);
    await page.getByRole('button', { name: '오늘 하루 보지 않기' }).click();
    await expect(layer(page)).toHaveCount(0);

    await gotoUser(page, '/');
    await page.waitForTimeout(1500);
    await expect(page.locator(`.g7pp-slide[data-popup-id="${first}"]`)).toHaveCount(0);

    const second = await createHtmlPopup(request, managerToken);
    created.push(second);
    await gotoUser(page, '/');
    await expect(page.locator(`.g7pp-slide[data-popup-id="${second}"]`)).toHaveCount(1);
    await expect(page.locator(`.g7pp-slide[data-popup-id="${first}"]`)).toHaveCount(0);
    const cookie = (await page.context().cookies()).find((c) => c.name === 'g7_popup_dismissed');
    expect(cookie?.path).toBe('/');
}

/** 닫기 뒤 SPA 로 이동해도 다시 뜨지 않고, 새로고침하면 뜬다 */
async function flow_close_hides_across_spa_navigation({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
    const id = await createHtmlPopup(request, managerToken);
    created.push(id);

    await gotoUser(page, '/');
    await expect(page.locator(`.g7pp-slide[data-popup-id="${id}"]`)).toHaveCount(1);
    await layer(page).getByRole('button', { name: '닫기' }).click();
    await expect(layer(page)).toHaveCount(0);

    await navigateSpa(page, '/page/about');
    await page.waitForTimeout(1500);
    await expect(page.locator(`.g7pp-slide[data-popup-id="${id}"]`)).toHaveCount(0);

    await page.reload();
    await expect(page.locator(`.g7pp-slide[data-popup-id="${id}"]`)).toHaveCount(1);
}

/** 직접 작성형 본문이 정화된 채로 뜬다 */
async function flow_html_popup_with_editor_content({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
    const id = await createHtmlPopup(request, managerToken, { body_html: { ko: '<h2>점검 안내</h2><p onclick="window.__popupXss=1">본문</p><script>window.__popupXss=2</script>' } });
    created.push(id);

    await gotoUser(page, '/');
    await expect(page.locator(`.g7pp-slide[data-popup-id="${id}"]`)).toHaveCount(1);
    await goToSlide(page, id);
    const body = page.locator(`.g7pp-slide[data-popup-id="${id}"] .g7pp-html`);
    await expect(body.locator('h2')).toHaveText('점검 안내');
    await body.locator('p').click();
    expect(await page.evaluate(() => (window as any).__popupXss)).toBeUndefined();
    expect(await body.innerHTML()).not.toContain('onclick');
}

/** 지금 장(보조기술에 보이는 장)의 팝업 id — 넘기는 동안의 옆 장은 aria-hidden 이다 */
async function currentSlideId(page: any): Promise<number> {
  return Number(await page.locator('[data-popup-layer=""] .g7pp-slide:not([hidden]):not([aria-hidden="true"])').getAttribute('data-popup-id'));
}

/**
 * 사양이 만든 장으로 간다 — 사이트에 다른 팝업이 먼저 떠 있을 수 있고, 장이 많으면 점 단추 없이 「n / N」만 있으므로
 * [다음 팝업]으로 한 장씩 넘긴다.
 */
async function goToSlide(page: any, id: number): Promise<void> {
  const total = (await layerIds(page)).length;
  for (let i = 0; i < total && (await currentSlideId(page)) !== id; i++) {
    await layer(page).getByRole('button', { name: '다음 팝업' }).first().click();
    await expect(page.locator('[data-popup-layer=""] .g7pp-slide[aria-hidden="true"]')).toHaveCount(0);
  }
  await expect.poll(() => currentSlideId(page)).toBe(id);
}

/**
 * 끌어서 넘긴다 — 데스크톱은 마우스로 끌고(끌다 놓은 새 창 링크는 열리지 않는다), 모바일은 손가락(실제 터치 입력)으로 끈다.
 * 다른 팝업이 앞에 있을 수 있어 사양이 만든 첫 장으로 넘겨 간 뒤 끈다.
 */
async function flow_drag_to_next_slide({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
  const linked = await createImagePopup(request, managerToken, { link_url: 'https://example.com/popup-drag', link_new_window: true });
  const next = await createHtmlPopup(request, managerToken, { body_html: { ko: '<p>끌어서 넘긴 두 번째 장 본문입니다. 글자를 끌어도 선택되지 않아야 합니다.</p>' } });
  created.push(linked, next);

  await gotoUser(page, '/');
  await expect(page.locator(`.g7pp-slide[data-popup-id="${next}"]`)).toHaveCount(1);
  const ids = await layerIds(page);
  expect(ids.indexOf(next)).toBe(ids.indexOf(linked) + 1);
  await goToSlide(page, linked);
  await expect(page.locator('[data-popup-layer=""] .g7pp-slide[aria-hidden="true"]')).toHaveCount(0);

  const opened: unknown[] = [];
  page.context().on('page', (p: unknown) => opened.push(p));
  const startUrl = page.url();
  const box = (await page.locator('[data-popup-layer=""] .g7pp-body').boundingBox())!;
  const y = box.y + box.height / 2;
  const fromX = box.x + box.width * 0.75;
  const toX = box.x + box.width * 0.25;

  if (viewport === 'desktop') {
    // 덮는 링크(새 창) 위에서 눌러 왼쪽으로 끌고 놓는다
    await page.mouse.move(fromX, y);
    await page.mouse.down();
    await page.mouse.move(toX, y, { steps: 12 });
    await page.mouse.up();
  } else {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: fromX, y }] });
    for (let i = 1; i <= 10; i++) {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: fromX + ((toX - fromX) * i) / 10, y }] });
    }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  }

  await expect.poll(() => currentSlideId(page)).toBe(next);
  // 미끄러짐이 끝나면 옆 장이 숨는다
  await expect(page.locator(`.g7pp-slide[data-popup-id="${linked}"]`)).toHaveAttribute('hidden', '');
  await page.waitForTimeout(500);
  expect(opened).toHaveLength(0);
  expect(page.url()).toBe(startUrl);

  if (viewport === 'desktop') {
    // 본문 글자 위에서 오른쪽으로 끌어 되돌린다 — 끄는 동안 글자가 선택되지 않는다
    const text = page.locator(`.g7pp-slide[data-popup-id="${next}"] .g7pp-html p`);
    const textBox = (await text.boundingBox())!;
    await page.mouse.move(textBox.x + 8, textBox.y + textBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(textBox.x + 8 + box.width * 0.5, textBox.y + textBox.height / 2, { steps: 12 });
    expect(await page.evaluate(() => window.getSelection()?.toString() ?? '')).toBe('');
    await page.mouse.up();
    await expect.poll(() => currentSlideId(page)).toBe(linked);
    expect(opened).toHaveLength(0);
  }
}

test.describe('팝업 → 사용자 화면', () => {
  // @scenario flow=admin_creates_image_popup_shows_on_home, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('이미지형 팝업이 홈에 뜬다 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_admin_creates_image_popup_shows_on_home({ page, request, managerToken, created } as Ctx, 'desktop');
  });

  // @scenario flow=admin_creates_image_popup_shows_on_home, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('이미지형 팝업이 홈에 뜬다 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flow_admin_creates_image_popup_shows_on_home({ page, request, managerToken, created } as Ctx, 'mobile');
  });

  // @scenario flow=route_scope_shows_on_child_not_on_prefix_sibling, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('노출 경로 — 하위 경로에 뜨고 경계가 아닌 경로에는 안 뜬다 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_route_scope_shows_on_child_not_on_prefix_sibling({ page, request, managerToken, created } as Ctx, 'desktop');
  });

  // @scenario flow=route_scope_shows_on_child_not_on_prefix_sibling, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('노출 경로 — 하위 경로에 뜨고 경계가 아닌 경로에는 안 뜬다 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flow_route_scope_shows_on_child_not_on_prefix_sibling({ page, request, managerToken, created } as Ctx, 'mobile');
  });

  // @scenario flow=audience_member_only, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('회원만 팝업 — 손님에게 안 뜨고 회원에게 뜬다 (desktop)', async ({ page, request, managerToken, memberToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_audience_member_only({ page, request, managerToken, memberToken, created } as Ctx, 'desktop');
  });

  // @scenario flow=audience_member_only, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('회원만 팝업 — 손님에게 안 뜨고 회원에게 뜬다 (mobile)', async ({ page, request, managerToken, memberToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flow_audience_member_only({ page, request, managerToken, memberToken, created } as Ctx, 'mobile');
  });

  // @scenario flow=device_mobile_only, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('모바일 전용 팝업은 모바일 폭에서만 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_device_mobile_only({ page, request, managerToken, created } as Ctx, 'desktop');
  });

  // @scenario flow=device_mobile_only, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('모바일 전용 팝업은 모바일 폭에서만 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flow_device_mobile_only({ page, request, managerToken, created } as Ctx, 'mobile');
  });

  // @scenario flow=dismiss_today_hides_then_new_popup_shows, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('오늘 하루 보지 않기 뒤 새로 올린 팝업은 바로 뜬다 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_dismiss_today_hides_then_new_popup_shows({ page, request, managerToken, created } as Ctx, 'desktop');
  });

  // @scenario flow=dismiss_today_hides_then_new_popup_shows, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('오늘 하루 보지 않기 뒤 새로 올린 팝업은 바로 뜬다 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flow_dismiss_today_hides_then_new_popup_shows({ page, request, managerToken, created } as Ctx, 'mobile');
  });

  // @scenario flow=close_hides_across_spa_navigation, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('닫기 뒤 SPA 로 이동해도 다시 뜨지 않고, 새로고침하면 뜬다 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_close_hides_across_spa_navigation({ page, request, managerToken, created } as Ctx, 'desktop');
  });

  // @scenario flow=close_hides_across_spa_navigation, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('닫기 뒤 SPA 로 이동해도 다시 뜨지 않고, 새로고침하면 뜬다 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flow_close_hides_across_spa_navigation({ page, request, managerToken, created } as Ctx, 'mobile');
  });

  // @scenario flow=html_popup_with_editor_content, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('직접 작성형 본문이 정화된 채로 뜬다 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_html_popup_with_editor_content({ page, request, managerToken, created } as Ctx, 'desktop');
  });

  // @scenario flow=html_popup_with_editor_content, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('직접 작성형 본문이 정화된 채로 뜬다 (mobile)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await flow_html_popup_with_editor_content({ page, request, managerToken, created } as Ctx, 'mobile');
  });

  // @scenario flow=drag_to_next_slide, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('마우스로 끌어 다음 장으로 넘기고, 끌다 놓은 링크는 열리지 않는다 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await flow_drag_to_next_slide({ page, request, managerToken, created } as Ctx, 'desktop');
  });
});

test.describe('팝업 → 사용자 화면 (터치)', () => {
  // 실제 터치 입력(CDP touch)을 받으려면 터치 지원 문맥이어야 한다
  test.use({ hasTouch: true, viewport: VIEWPORTS.mobile });

  // @scenario flow=drag_to_next_slide, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('손가락으로 끌어 다음 장으로 넘긴다 (mobile)', async ({ page, request, managerToken, created }) => {
    await flow_drag_to_next_slide({ page, request, managerToken, created } as Ctx, 'mobile');
  });
});

test.describe('관리자 화면', () => {
  test.use({ viewport: VIEWPORTS.desktop });

  // @scenario flow=carousel_order_follows_reorder, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('순서 변경 창에서 바꾼 순서가 사용자 캐러셀 순서가 된다', async ({ page, request, managerToken, created }) => {
    const a = await createHtmlPopup(request, managerToken, { title: uniqueTitle('A') });
    const b = await createHtmlPopup(request, managerToken, { title: uniqueTitle('B') });
    created.push(a, b);

    await authenticatePage(page, managerToken);
    await page.goto(ADMIN_LIST);
    await page.getByRole('button', { name: '순서 변경' }).click();
    await expect(page.getByTestId(`popup-reorder-row-${b}`)).toBeVisible();
    await page.getByTestId(`popup-reorder-up-${b}`).click();
    await page.getByTestId('popup-reorder-save').click();
    await expect(page.getByTestId('popup-reorder-save')).toHaveCount(0);

    const order = await request.get(`${API}/admin/popups/order`, { headers: auth(managerToken) });
    const ids = ((await order.json()).data.data as Array<{ id: number }>).map((p) => p.id);
    expect(ids.indexOf(b)).toBeLessThan(ids.indexOf(a));

    const userPage = await page.context().newPage();
    await gotoUser(userPage, '/');
    await expect(userPage.locator(`.g7pp-slide[data-popup-id="${b}"]`)).toHaveCount(1);
    const shown = await layerIds(userPage);
    expect(shown.indexOf(b)).toBeLessThan(shown.indexOf(a));
  });

  // @scenario flow=admin_preview_shows_content, viewport=desktop
  // @effects test_data_cleaned_up
  test('관리자 미리보기(수정 화면 PC·모바일, 환경설정)는 본문이 접히지 않고 이미지가 보인다', async ({ page, request, managerToken, created }) => {
    const id = await createImagePopup(request, managerToken);
    created.push(id);
    const previewBodyHeight = async (): Promise<number> => (await page.locator('.g7pp-root--preview .g7pp-body').first().boundingBox())?.height ?? 0;

    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}/${id}/edit`);
    const img = page.locator('.g7pp-root--preview .g7pp-media__img').first();
    await expect(img).toBeVisible();
    expect(await previewBodyHeight()).toBeGreaterThan(20);
    expect((await img.boundingBox())?.height ?? 0).toBeGreaterThan(20);

    await page.locator('#preview_device_mobile').click();
    await expect(page.locator('[data-preview-device="mobile"]')).toBeVisible();
    expect(await previewBodyHeight()).toBeGreaterThan(20);

    await page.goto('/admin/plugins/sirsoft-popup/settings');
    await expect(page.locator('.g7pp-root--preview .g7pp-sample').first()).toBeVisible();
    expect(await previewBodyHeight()).toBeGreaterThan(20);
  });

  // @scenario flow=read_only_admin_cannot_edit, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('조회 권한만 있는 관리자는 등록·일괄 작업·순서 변경 단추가 없다', async ({ page, request, managerToken, readerToken, created }) => {
    created.push(await createHtmlPopup(request, managerToken), await createHtmlPopup(request, managerToken));

    await authenticatePage(page, readerToken);
    await page.goto(ADMIN_LIST);
    await expect(page.getByRole('heading', { name: '팝업 관리' })).toBeVisible();
    await expect(page.getByRole('link', { name: '팝업 등록' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: '순서 변경' })).toHaveCount(0);
    const blocked = await request.post(`${API}/admin/popups/bulk-copy`, { headers: auth(readerToken), data: { ids: [created[0]] } });
    expect(blocked.status()).toBe(403);
  });
});
