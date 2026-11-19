/**
 * @file admin-popup-to-user.spec.ts
 * @description 관리자가 등록·수정·순서를 바꾼 팝업이 사용자 화면에 조건대로 뜨는지 — 이미지형·직접 작성형, 노출 페이지 경계,
 * 회원만, 모바일만, 순서 변경, 오늘 하루 보지 않기 뒤 새 팝업, 닫기 뒤 SPA 이동, 끌어서 넘기기(마우스·터치), 하단 바로 창 옮기기(시트는 끌어도 그대로),
 * 본문 위에 겹친 넘김 컨트롤 캡슐(눌러 넘김, 링크 장에서 눌러도 이동 없음), 조회 전용 관리자.
 * (매니페스트 e2e-flows.yaml)
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

/** PC 폭에서 닫은 뒤 같은 페이지에서 휴대폰 폭으로 줄여도 모바일 전용 팝업이 레이어를 다시 열지 않고, 새로고침하면 뜬다(#902) */
async function flow_close_survives_resize_to_mobile({ page, request, managerToken, created }: Ctx): Promise<void> {
    const pc = await createHtmlPopup(request, managerToken, { device: 'pc' });
    const mobile = await createHtmlPopup(request, managerToken, { device: 'mobile' });
    created.push(pc, mobile);

    await page.setViewportSize(VIEWPORTS.desktop);
    await gotoUser(page, '/');
    await expect(page.locator(`.g7pp-slide[data-popup-id="${pc}"]`)).toHaveCount(1);
    await expect(page.locator(`.g7pp-slide[data-popup-id="${mobile}"]`)).toHaveCount(0);
    await layer(page).getByRole('button', { name: '닫기' }).click();
    await expect(layer(page)).toHaveCount(0);

    await page.setViewportSize(VIEWPORTS.mobile);
    await page.waitForTimeout(1500);
    await expect(layer(page)).toHaveCount(0);
    await expect(page.locator(`.g7pp-slide[data-popup-id="${mobile}"]`)).toHaveCount(0);

    await page.reload();
    await expect(page.locator(`.g7pp-slide[data-popup-id="${mobile}"]`)).toHaveCount(1);
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

  // 직접 작성형 본문 글자 위에서 오른쪽으로 끌어 되돌린다 — 끄는 동안 글자가 선택되지 않는다.
  // 본문은 내부 스크롤 영역이라 그 안에서도 가로 터치를 레이어가 받아야 한다(브라우저가 가로채면 pointercancel 로 끝난다)
  const text = page.locator(`.g7pp-slide[data-popup-id="${next}"] .g7pp-html p`);
  const textBox = (await text.boundingBox())!;
  const textY = textBox.y + textBox.height / 2;
  if (viewport === 'desktop') {
    await page.mouse.move(textBox.x + 8, textY);
    await page.mouse.down();
    await page.mouse.move(textBox.x + 8 + box.width * 0.5, textY, { steps: 12 });
    expect(await page.evaluate(() => window.getSelection()?.toString() ?? '')).toBe('');
    await page.mouse.up();
  } else {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: textBox.x + 8, y: textY }] });
    for (let i = 1; i <= 10; i++) {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: textBox.x + 8 + (box.width * 0.5 * i) / 10, y: textY }] });
    }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  }
  await expect.poll(() => currentSlideId(page)).toBe(linked);
  expect(opened).toHaveLength(0);
}

/**
 * 창을 옮긴다 — 데스크톱은 하단 바의 「닫기」 위에서 마우스로 눌러 끌어 옮기고(그 동작으로 닫히지 않음, 장은 그대로, 헤더 아래·배너 위),
 * 다른 페이지로 가면 원래 자리, 끌지 않고 누른 「닫기」는 닫는다. 위쪽 손잡이 띠는 없다.
 * 모바일 하단 시트는 손잡이 막대가 없고 하단 바를 손가락(실제 터치 입력)으로 끌어 내려도 움직이거나 닫히지 않으며, 「닫기」를 누르면 닫힌다.
 * 모바일 표시 방식이 가운데 창이면 하단 바를 손가락으로 끌어 옮긴다.
 */
async function flow_move_window({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
  const id = await createHtmlPopup(request, managerToken, { body_html: { ko: '<p>창을 옮겨 보는 팝업 본문입니다.</p>' } });
  created.push(id);

  await gotoUser(page, '/');
  await expect(page.locator(`.g7pp-slide[data-popup-id="${id}"]`)).toHaveCount(1);
  const dialog = page.locator('[data-popup-layer=""] .g7pp-dialog');
  const sheet = (await dialog.getAttribute('class'))?.includes('g7pp-dialog--m-sheet') ?? false;
  const closeButton = layer(page).getByRole('button', { name: '닫기' });
  // 위쪽 손잡이 띠·시트 손잡이 막대는 없다
  await expect(page.locator('[data-popup-layer=""] .g7pp-grip, [data-popup-layer=""] .g7pp-handle')).toHaveCount(0);
  // 잡는 곳(하단 바의 「닫기」)이 헤더·배너 등 다른 요소에 덮이지 않았다
  expect(await closeButton.evaluate((el) => {
    const rect = el.getBoundingClientRect();
    const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);

    return el === hit || el.contains(hit);
  })).toBe(true);
  const start = (await dialog.boundingBox())!;
  const grab = (await closeButton.boundingBox())!;
  const x = grab.x + grab.width / 2;
  const y = grab.y + grab.height / 2;
  const before = await currentSlideId(page);

  if (viewport === 'desktop') {
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x - 200, y, { steps: 10 });
    await page.mouse.up();
    // 「닫기」 위에서 시작한 끌기라도 닫히지 않고 옮겨진다
    await expect(layer(page)).toBeVisible();
    const moved = (await dialog.boundingBox())!;
    expect(Math.round(moved.x - start.x)).toBe(-200);
    // 위아래 끝까지 끌어도 헤더 밑·GDPR 배너 밑으로 들어가지 않는다(배너가 있으면 그 사이에 맞게 본문이 줄어 있다)
    const headerBottom = await page.evaluate(() => document.querySelector('header')?.getBoundingClientRect().bottom ?? 0);
    const bannerTop = await page.evaluate(() => document.getElementById('gdpr_cookie_banner')?.getBoundingClientRect().top ?? window.innerHeight);
    const footer = page.locator('[data-popup-layer=""] .g7pp-footer');
    for (const toY of [0, 800]) {
      const grab2 = (await footer.boundingBox())!;
      await page.mouse.move(grab2.x + grab2.width / 2, grab2.y + grab2.height / 2);
      await page.mouse.down();
      await page.mouse.move(grab2.x + grab2.width / 2, toY, { steps: 10 });
      await page.mouse.up();
      await expect(layer(page)).toBeVisible();
      const box = (await dialog.boundingBox())!;
      expect(box.y).toBeGreaterThanOrEqual(headerBottom);
      expect(box.y + box.height).toBeLessThanOrEqual(bannerTop);
    }
    await expect(closeButton).toBeVisible();
    expect(await currentSlideId(page)).toBe(before);
    expect(await page.evaluate(() => window.getSelection()?.toString() ?? '')).toBe('');
    expect(await page.evaluate(() => document.cookie)).not.toContain('g7_popup_dismissed');

    // 다른 페이지로 가면 원래 자리
    await navigateSpa(page, '/page/about');
    await expect.poll(async () => Math.round((await dialog.boundingBox())!.x)).toBe(Math.round(start.x));

    // 끌지 않고 누른 「닫기」는 닫는다
    await closeButton.click();
    await expect(layer(page)).toHaveCount(0);

    return;
  }

  const cdp = await page.context().newCDPSession(page);
  const distance = sheet ? start.height * 0.6 : -80;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
  for (let i = 1; i <= 10; i++) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: y + (distance * i) / 10 }] });
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });

  if (!sheet) {
    await expect.poll(async () => Math.round((await dialog.boundingBox())!.y - start.y)).toBe(-80);
    await expect(layer(page)).toBeVisible();

    return;
  }
  // 시트는 끌어 내려도 그대로 — 움직이지도 닫히지도 않는다
  await page.waitForTimeout(500);
  await expect(layer(page)).toBeVisible();
  expect(Math.round((await dialog.boundingBox())!.y)).toBe(Math.round(start.y));
  expect(await dialog.evaluate((el) => (el as HTMLElement).style.getPropertyValue('--g7pp-move-y'))).toBe('');
  // 「닫기」를 누르면 닫힌다(쿠키 없음 — 새로고침하면 다시 뜬다)
  await closeButton.tap();
  await expect(layer(page)).toHaveCount(0);
  expect(await page.evaluate(() => document.cookie)).not.toContain('g7_popup_dismissed');
  await gotoUser(page, '/');
  await expect(page.locator(`.g7pp-slide[data-popup-id="${id}"]`)).toHaveCount(1);
}

/**
 * 넘김 컨트롤은 본문 안 아래쪽 가운데에 겹친 캡슐이다 — 별도 띠가 없고, 장이 적으면 점·많으면 「n / N」이 그 안에 보인다.
 * 이미지 링크 장에서 캡슐을 눌러도 링크로 가지 않고, 점(점 줄이면) 또는 본문 양옆 [다음 팝업]을 눌러 넘긴다(모바일은 탭).
 * 사이트에 다른 팝업이 먼저 떠 있을 수 있어 점/숫자 중 어느 쪽인지는 그때 장 수로 정한다.
 */
async function flow_pager_overlay_controls({ page, request, managerToken, created }: Ctx, viewport: Viewport): Promise<void> {
  const linked = await createImagePopup(request, managerToken, { link_url: '/page/about' });
  const next = await createHtmlPopup(request, managerToken, { body_html: { ko: '<p>넘김 컨트롤 캡슐 아래 여백을 보는 본문입니다.</p>' } });
  created.push(linked, next);

  await gotoUser(page, '/');
  await expect(page.locator(`.g7pp-slide[data-popup-id="${next}"]`)).toHaveCount(1);
  await goToSlide(page, linked);
  const startUrl = page.url();
  const body = page.locator('[data-popup-layer=""] .g7pp-body');
  const pager = body.locator('.g7pp-pager');
  await expect(pager).toBeVisible();
  // 본문과 하단 바 사이 띠·아래 줄 화살표는 없다
  await expect(page.locator('[data-popup-layer=""] .g7pp-nav, [data-popup-layer=""] .g7pp-nav__arrow')).toHaveCount(0);

  // 캡슐은 본문 안 아래쪽 가운데
  const bodyBox = (await body.boundingBox())!;
  const pagerBox = (await pager.boundingBox())!;
  expect(pagerBox.x).toBeGreaterThanOrEqual(bodyBox.x);
  expect(pagerBox.x + pagerBox.width).toBeLessThanOrEqual(bodyBox.x + bodyBox.width);
  expect(Math.abs(pagerBox.x + pagerBox.width / 2 - (bodyBox.x + bodyBox.width / 2))).toBeLessThanOrEqual(1);
  const gap = bodyBox.y + bodyBox.height - (pagerBox.y + pagerBox.height);
  expect(gap).toBeGreaterThanOrEqual(0);
  expect(gap).toBeLessThanOrEqual(24);
  // 캡슐 한가운데를 누르면 덮는 링크가 아니라 캡슐(안의 점·숫자)이 맞는다
  expect(await pager.evaluate((el) => {
    const rect = el.getBoundingClientRect();
    const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);

    return !!hit && el.contains(hit);
  })).toBe(true);
  // 이전·다음은 PC·휴대폰 모두 본문 안 양옆
  for (const name of ['이전 팝업', '다음 팝업']) {
    const arrow = (await body.getByRole('button', { name }).boundingBox())!;
    expect(arrow.x).toBeGreaterThanOrEqual(bodyBox.x);
    expect(arrow.x + arrow.width).toBeLessThanOrEqual(bodyBox.x + bodyBox.width);
  }

  const ids = await layerIds(page);
  const dotCount = await pager.locator('.g7pp-dot').count();
  const dotMode = dotCount > 0;
  if (dotMode) {
    expect(dotCount).toBe(ids.length);
  } else {
    await expect(pager.locator('.g7pp-counter')).toHaveText(`${ids.indexOf(linked) + 1} / ${ids.length}`);
  }
  const press = async (target: any) => (viewport === 'mobile' ? target.tap() : target.click());

  // 링크 장 위의 캡슐을 눌러도 링크로 가지 않는다 — 점 줄이면 지금 장 점, 숫자 줄이면 숫자
  await press(dotMode ? pager.locator('.g7pp-dot[aria-current="true"]') : pager.locator('.g7pp-counter'));
  await page.waitForTimeout(500);
  expect(page.url()).toBe(startUrl);
  await expect(layer(page)).toBeVisible();
  expect(await currentSlideId(page)).toBe(linked);

  // 눌러 넘긴다 — 점 줄이면 다음 장 점, 숫자 줄이면 본문 양옆 [다음 팝업]
  await press(dotMode ? pager.getByRole('button', { name: `${ids.indexOf(next) + 1}번째 팝업 보기` }) : body.getByRole('button', { name: '다음 팝업' }));
  await expect.poll(() => currentSlideId(page)).toBe(next);
  expect(page.url()).toBe(startUrl);
  if (!dotMode) {
    await expect(pager.locator('.g7pp-counter')).toHaveText(`${ids.indexOf(next) + 1} / ${ids.length}`);
  }
  // 직접 작성형 본문은 캡슐 높이와 띄운 거리만큼 아래 여백이 있다
  const padding = await page.locator(`.g7pp-slide[data-popup-id="${next}"] .g7pp-html`).evaluate((el) => parseFloat(getComputedStyle(el).paddingBottom));
  expect(padding).toBeGreaterThanOrEqual(pagerBox.height + gap);
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

  // @scenario flow=close_survives_resize_to_mobile, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('PC 폭에서 닫은 뒤 휴대폰 폭으로 줄여도 레이어가 다시 열리지 않고, 새로고침하면 모바일 전용이 뜬다 (#902)', async ({ page, request, managerToken, created }) => {
    await flow_close_survives_resize_to_mobile({ page, request, managerToken, created } as Ctx);
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

  // @scenario flow=move_window, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('하단 바를 마우스로 끌어 창을 옮기고(「닫기」 위에서 끌어도 닫히지 않음), 다른 페이지로 가면 원래 자리 (desktop)', async ({ page, request, managerToken, created }) => {
    // 높이 800 노트북 화면 — 가운데 정렬한 창의 위쪽이 사이트 헤더 자리에 걸리고 아래쪽이 쿠키 배너에 닿는 크기(그 사이로 비켜 뜬다)
    await page.setViewportSize({ width: 1280, height: 800 });
    await flow_move_window({ page, request, managerToken, created } as Ctx, 'desktop');
  });

  // @scenario flow=pager_overlay_controls, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('넘김 컨트롤은 본문 아래 가운데 캡슐 — 눌러 넘기고, 링크 장에서 캡슐을 눌러도 이동하지 않는다 (desktop)', async ({ page, request, managerToken, created }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await flow_pager_overlay_controls({ page, request, managerToken, created } as Ctx, 'desktop');
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

  // @scenario flow=move_window, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('하단 시트는 손잡이가 없고 하단 바를 끌어도 그대로, 「닫기」로 닫힌다(가운데 창이면 하단 바로 옮긴다) (mobile)', async ({ page, request, managerToken, created }) => {
    await flow_move_window({ page, request, managerToken, created } as Ctx, 'mobile');
  });

  // @scenario flow=pager_overlay_controls, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('넘김 컨트롤은 본문 아래 가운데 캡슐 — 탭해 넘기고, 링크 장에서 캡슐을 탭해도 이동하지 않는다 (mobile)', async ({ page, request, managerToken, created }) => {
    await flow_pager_overlay_controls({ page, request, managerToken, created } as Ctx, 'mobile');
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

    // 이미지 칸은 크기가 아니라 비율을 안내하고(2배 크기 예시 포함), 1×1 사진은 비율이 달라 여백 경고가 뜬다
    const display = (await (await request.get(`${API}/admin/display-settings`, { headers: auth(managerToken) })).json()).data.settings;
    const { pc_width: w, pc_height: h } = display;
    await expect(page.getByText(`같은 비율 ${w}:${h}(예: ${w}×${h}, 더 선명하게는 ${w * 2}×${h * 2})`)).toBeVisible();
    await expect(page.getByText(`권장: 비율 약 ${display.mobile_style === 'center' ? '4:5' : '1:1'}`)).toBeVisible();
    await expect(page.getByText(`올린 이미지는 1×1 입니다. 권장 비율(${w}:${h})과 달라`)).toBeVisible();

    await page.locator('#preview_device_mobile').click();
    await expect(page.locator('[data-preview-device="mobile"]')).toBeVisible();
    expect(await previewBodyHeight()).toBeGreaterThan(20);

    await page.goto('/admin/plugins/sirsoft-popup/settings');
    await expect(page.locator('.g7pp-root--preview .g7pp-sample').first()).toBeVisible();
    expect(await previewBodyHeight()).toBeGreaterThan(20);
  });

  // @scenario flow=admin_preview_follows_editor_typing, viewport=desktop
  // @effects test_data_cleaned_up
  test('직접 작성형 본문을 편집기에 입력하면 다른 칸을 건드리지 않아도 미리보기가 바로 따라온다', async ({ page, request, managerToken, created }) => {
    const id = await createHtmlPopup(request, managerToken, { body_html: { ko: '<p>Notice one</p>' } });
    created.push(id);
    const previewBody = page.locator('.g7pp-root--preview .g7pp-html').first();

    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}/${id}/edit`);
    await expect(previewBody).toHaveText('Notice one');
    // CKEditor 5 는 본문을 화면 다시 그리기 없이 상태에만 쓴다 — 그 경로가 이 흐름의 대상이다
    const editable = page.locator('#ckeditor5-body_html .ck-editor__editable').first();
    await expect(editable).toBeVisible();

    for (const word of ['two', 'three']) {
      await editable.click();
      await page.keyboard.press('ControlOrMeta+a');
      await page.keyboard.type(`Notice ${word}`);
      await expect(previewBody).toHaveText(`Notice ${word}`, { timeout: 3000 });
    }
  });

  // @scenario flow=admin_summary_expands_beyond_first_items, viewport=mobile
  // @effects test_data_cleaned_up
  test('「지금 노출 중」은 휴대폰에서 한 줄 3개만 보이고(검색칸이 첫 화면에) 「더 보기」로 펼치고 접는다 (mobile)', async ({ page, request, managerToken, created }) => {
    // 사이트의 다른 노출 중 팝업 수와 무관하게 8개를 넘도록 9건을 만든다
    for (let i = 0; i < 9; i += 1) created.push(await createHtmlPopup(request, managerToken));
    await page.setViewportSize(VIEWPORTS.mobile);

    await authenticatePage(page, managerToken);
    await page.goto(ADMIN_LIST);
    // 휴대폰 폭은 접힌 동안 한 줄 3개만 보인다(DOM 에는 8개, 나머지는 숨김)
    const items = page.locator('#popup_summary_items > li:has(.g7pp-summary-link, [title]):visible');
    await expect(items).toHaveCount(3);
    const toggle = page.locator('#popup_summary_items').getByRole('button', { name: /개 더 보기/ });
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    // 검색칸이 첫 화면(844px) 안에 있다 — 26개를 다 펼치던 때는 1,610px, 8개일 때도 914px 아래였다
    const search = await page.locator('#popup_filter_search').boundingBox();
    expect((search?.y ?? Infinity) + (search?.height ?? 0)).toBeLessThanOrEqual(VIEWPORTS.mobile.height);

    await toggle.click();
    await expect(page.locator('#popup_summary_items').getByRole('button', { name: '접기' })).toHaveAttribute('aria-expanded', 'true');
    expect(await items.count()).toBeGreaterThanOrEqual(9);
    await page.locator('#popup_summary_items').getByRole('button', { name: '접기' }).click();
    await expect(items).toHaveCount(3);
  });

  // @scenario flow=admin_save_lock_reason_shown, viewport=mobile
  // @effects test_data_cleaned_up
  test('이미지형 등록에서 이미지가 없으면 저장 단추 위 한 줄에 이유가 보이고 단추가 그 문구를 가리키며, 휴대폰에서 「취소」가 갈라지지 않는다 (mobile)', async ({ page, managerToken }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}/create`);
    const save = page.locator('button', { hasText: '저장' }).last();
    await expect(save).toBeDisabled();
    const reason = page.locator('#popup_save_blocked_reason');
    await expect(reason).toHaveText('PC 이미지를 올려야 저장할 수 있습니다.');
    await expect(save).toHaveAttribute('aria-describedby', 'popup_save_blocked_reason');
    await expect(page.locator('#popup_title')).toHaveAttribute('aria-required', 'true');
    await expect(page.locator('#popup_title')).toHaveAttribute('aria-describedby', 'popup_title_hint');
    // 이유는 단추들보다 위 줄이고, 「취소」 글자는 한 줄이다(두 줄로 갈라지면 글자 칸 높이가 한 줄의 두 배 가까이 된다)
    const cancel = await page.locator('a', { hasText: '취소' }).last().boundingBox();
    const reasonBox = await reason.boundingBox();
    expect(reasonBox!.y + reasonBox!.height).toBeLessThanOrEqual(cancel!.y + 1);
    const label = page.locator('a', { hasText: '취소' }).last().locator('span');
    const lineHeight = await label.evaluate((el) => parseFloat(getComputedStyle(el).lineHeight));
    expect((await label.boundingBox())!.height).toBeLessThan(lineHeight * 1.5);
  });

  // @scenario flow=admin_preview_actual_size, viewport=desktop
  // @effects test_data_cleaned_up
  test('미리보기 「실제 크기로 보기」는 큰 창에 줄이지 않은 크기로 그리고(팝업 폭 = 설정 폭), 표시 방식 화면에서도 같다', async ({ page, request, managerToken, created }) => {
    const id = await createImagePopup(request, managerToken);
    created.push(id);
    const display = (await (await request.get(`${API}/admin/display-settings`, { headers: auth(managerToken) })).json()).data.settings;
    const dialogWidth = async (): Promise<number> => (await page.locator('.g7pp-frame--actual .g7pp-dialog').first().boundingBox())?.width ?? 0;

    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}/${id}/edit`);
    await expect(page.locator('.g7pp-root--preview .g7pp-media__img').first()).toBeVisible();
    // 줄인 틀의 팝업은 설정 폭보다 훨씬 작다
    expect((await page.locator('#popup_form_preview .g7pp-dialog').boundingBox())?.width ?? 0).toBeLessThan(display.pc_width / 2);
    await page.getByRole('button', { name: '실제 크기로 보기' }).click();
    const frame = page.locator('.g7pp-frame-scroll .g7pp-frame--actual');
    await expect(frame).toBeVisible();
    await expect(page.getByText('1280×800 화면 기준 실제 크기입니다.')).toBeVisible();
    // 코어 위치는 화면 폭보다 넓은 1280 틀이 창 안에서 스크롤한다 — 팝업 자체는 설정 폭 그대로
    expect(Math.abs((await dialogWidth()) - Math.min(display.pc_width, 1280 - 32))).toBeLessThanOrEqual(2);
    await expect(frame.locator('.g7pp-media__img').first()).toBeVisible();

    await page.goto('/admin/plugins/sirsoft-popup/settings');
    await expect(page.locator('.g7pp-root--preview .g7pp-sample').first()).toBeVisible();
    await page.getByRole('button', { name: '실제 크기로 보기' }).click();
    await expect(page.locator('.g7pp-frame--actual .g7pp-sample').first()).toBeVisible();
    expect(Math.abs((await dialogWidth()) - Math.min(display.pc_width, 1280 - 32))).toBeLessThanOrEqual(2);
  });

  // @scenario flow=admin_actual_preview_shows_latest_body, viewport=desktop
  // @effects test_data_cleaned_up
  test('본문을 고치고 다른 칸을 건드리지 않은 채 「실제 크기로 보기」를 열어도 방금 쓴 본문이 보인다(수정·등록)', async ({ page, request, managerToken, created }) => {
    const id = await createHtmlPopup(request, managerToken, { body_html: { ko: '<p>Saved before</p>' } });
    created.push(id);
    const actualBody = page.locator('.g7pp-frame--actual .g7pp-html').first();
    const typeBody = async (text: string) => {
      const editable = page.locator('#ckeditor5-body_html .ck-editor__editable').first();
      await expect(editable).toBeVisible();
      await editable.click();
      await page.keyboard.press('ControlOrMeta+a');
      await page.keyboard.type(text);
      // 줄인 미리보기가 따라온 뒤(편집기의 상태 쓰기가 끝난 뒤) 연다
      await expect(page.locator('#popup_form_preview .g7pp-html').first()).toHaveText(text, { timeout: 3000 });
    };

    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}/${id}/edit`);
    await typeBody('Unsaved edit body');
    await page.getByRole('button', { name: '실제 크기로 보기' }).click();
    await expect(actualBody).toHaveText('Unsaved edit body');
    await page.keyboard.press('Escape');

    // 새 팝업은 스냅숏에 본문이 아예 없다
    await page.goto(`${ADMIN_LIST}/create`);
    await page.getByRole('radio', { name: '직접 작성형' }).check();
    await typeBody('New popup body');
    await page.getByRole('button', { name: '실제 크기로 보기' }).click();
    await expect(actualBody).toHaveText('New popup body');
  });

  // @scenario flow=admin_actual_preview_mobile_scrolls_to_popup, viewport=mobile
  // @effects test_data_cleaned_up
  test('휴대폰에서 PC 실제 크기를 열면 1280 틀이 팝업 쪽으로 스크롤돼 팝업이 바로 보인다 (mobile)', async ({ page, request, managerToken, created }) => {
    const id = await createImagePopup(request, managerToken);
    created.push(id);
    await page.setViewportSize(VIEWPORTS.mobile);

    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}/${id}/edit`);
    await page.getByRole('button', { name: '실제 크기로 보기' }).click();
    const scroller = page.locator('.g7pp-frame-scroll');
    await expect(scroller.locator('.g7pp-dialog')).toBeVisible();
    await expect.poll(async () => scroller.evaluate((el) => el.scrollLeft)).toBeGreaterThan(0);
    // 칸 안에 보이는 팝업 폭 — 열자마자 0 이 아니라 칸 폭 거의 전부
    const visibleWidth = await scroller.evaluate((el) => {
      const box = el.getBoundingClientRect();
      const dialog = (el.querySelector('.g7pp-dialog') as HTMLElement).getBoundingClientRect();
      return Math.max(0, Math.min(box.right, dialog.right) - Math.max(box.left, dialog.left));
    });
    expect(visibleWidth).toBeGreaterThan(200);
  });

  // @scenario flow=admin_summary_item_opens_edit, viewport=desktop
  // @effects test_data_cleaned_up
  test('「지금 노출 중」 항목을 누르면 그 팝업 수정 화면으로 가고 보던 목록 조건이 이어진다', async ({ page, request, managerToken, created }) => {
    const id = await createHtmlPopup(request, managerToken);
    created.push(id);
    const title = (await (await request.get(`${API}/admin/popups/${id}`, { headers: auth(managerToken) })).json()).data.title;

    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}?status=active`);
    // 요약은 처음 8개만 보인다 — 사이트에 노출 중 팝업이 많아 새 팝업이 뒤에 있으면 「더 보기」로 펼친 뒤 찾는다
    await expect(page.locator('#popup_summary_items')).toBeVisible();
    const more = page.locator('#popup_summary_items').getByRole('button', { name: /개 더 보기/ });
    if (await more.isVisible()) await more.click();
    const link = page.locator('[aria-labelledby="popup_summary_heading"]').getByRole('link', { name: new RegExp(`「${title}」 수정`) });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('title', title);
    // 링크임을 보이는 hover·키보드 초점 표시가 실제로 그려진다(플러그인 CSS — 관리자 템플릿 빌드에는 그 유틸이 없다)
    await link.hover();
    await expect(link.locator('.g7pp-summary-link__thumb')).toHaveCSS('border-top-color', 'rgb(59, 130, 246)');
    await expect(link.locator('.g7pp-summary-link__label')).toHaveCSS('text-decoration-line', 'underline');
    await link.focus();
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Tab');
    await expect(link).toHaveCSS('outline-style', 'solid');
    await expect(link).toHaveCSS('outline-color', 'rgb(37, 99, 235)');
    await link.click();

    await expect(page).toHaveURL(new RegExp(`/popups/${id}/edit\\?(.*&)?status=active`));
    await expect(page.locator('#popup_title')).toHaveValue(title);
  });

  // @scenario flow=admin_list_paginates, viewport=desktop
  // @effects test_data_cleaned_up
  test('팝업 목록은 한 페이지를 넘으면 페이지 이동 줄이 보이고 2페이지로 간다', async ({ page, request, managerToken, created }) => {
    // 사이트의 다른 팝업 수와 무관하게 사양이 만든 2건만으로 2페이지가 되도록 한 페이지 1건으로 연다
    created.push(await createHtmlPopup(request, managerToken), await createHtmlPopup(request, managerToken));

    await authenticatePage(page, managerToken);
    await page.goto(`${ADMIN_LIST}?per_page=1&sort_by=created_at&sort_order=desc&page=1`);
    // 검색칸은 플러그인 자기 CSS(gpp: 접두 빌드)로 최소 폭 12rem 을 받는다 — 관리자 템플릿 CSS 에 기대면 빠지는 규칙이다
    await expect(page.locator('label[for="popup_filter_search"]')).toHaveCSS('min-width', '192px');
    const pager = page.getByRole('navigation', { name: '페이지네이션' });
    await expect(pager).toBeVisible();
    await pager.getByRole('link', { name: '페이지 2', exact: true }).click();
    await expect(page).toHaveURL(/[?&]page=2(&|$)/);
    await expect(page).toHaveURL(/per_page=1/);
  });

  // @scenario flow=read_only_admin_cannot_edit, viewport=desktop
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test('조회 권한만 있는 관리자는 등록·일괄 작업·순서 변경 단추와 노출 중 항목의 수정 링크가 없다', async ({ page, request, managerToken, readerToken, created }) => {
    created.push(await createHtmlPopup(request, managerToken), await createHtmlPopup(request, managerToken));

    await authenticatePage(page, readerToken);
    await page.goto(ADMIN_LIST);
    await expect(page.getByRole('heading', { name: '팝업 관리' })).toBeVisible();
    await expect(page.getByRole('link', { name: '팝업 등록' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: '순서 변경' })).toHaveCount(0);
    // 「지금 노출 중」 항목은 보이되 수정 화면 링크는 없다(수정 화면이 403)
    const summary = page.locator('[aria-labelledby="popup_summary_heading"]');
    await expect(summary.locator('li').first()).toBeVisible();
    await expect(summary.getByRole('link', { name: / 수정$/ })).toHaveCount(0);
    const blocked = await request.post(`${API}/admin/popups/bulk-copy`, { headers: auth(readerToken), data: { ids: [created[0]] } });
    expect(blocked.status()).toBe(403);
  });
});
