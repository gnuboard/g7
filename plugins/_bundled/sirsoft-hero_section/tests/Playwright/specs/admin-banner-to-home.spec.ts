/**
 * E2E: 관리자가 배너를 다루면 사이트 홈(순서 변경은 쇼핑몰 메인) 상단 슬라이더에 그대로 반영된다 (#810)
 *
 * @scenario-manifest tests/scenarios/e2e-flows.yaml
 * @effects user_screen_reflects_admin_change, test_data_cleaned_up
 *
 * 관리자 조작은 데스크톱 폭에서 하고(관리자 표는 휴대폰 폭에서 카드 보기라 조작 경로가 다르다 — 그 화면 계약은
 * 레이아웃 테스트가 본다), 사용자 화면 확인은 축의 폭(데스크톱·휴대폰)으로 한다. 단언은 운영 문구가 아니라 사양이
 * 만든 배너 제목으로 하고, 사양이 만든 배너는 끝날 때 fixture 가 지운다(이미지 파일 포함).
 */
import {
  test, expect, authenticatePage, createBanner, gotoUser, slider, slideTitles, uniqueTitle,
  ADMIN_LIST, API, PNG_1X1, VIEWPORTS, auth,
} from '../fixtures/hero-auth';
import type { Page } from '@playwright/test';

// 관리자 조작 → 사용자 화면 확인을 한 시험에서 여러 번 오가므로 기본 30초로는 개발 PC 에서 빠듯하다
test.setTimeout(90_000);

/**
 * 관리자 목록(위치 탭)을 열고 표가 붙을 때까지 기다린다.
 *
 * @param page 페이지
 * @param position 위치 키(기본 사이트 홈)
 */
async function gotoAdminList(page: Page, position = 'home_hero'): Promise<void> {
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.goto(`${ADMIN_LIST}?position=${position}`);
  await expect(page.locator('#hero_banner_datagrid')).toBeAttached({ timeout: 30_000 });
}

/**
 * 사용자 화면(기본 사이트 홈)을 그 폭으로 열고 슬라이더 제목 목록을 돌려준다.
 *
 * @param page 페이지
 * @param viewport 폭
 * @param position 위치 키
 * @param path 그 위치가 붙는 사용자 경로
 * @returns 슬라이드 제목(순서대로)
 */
async function homeTitles(page: Page, viewport: keyof typeof VIEWPORTS, position = 'home_hero', path = '/'): Promise<string[]> {
  await page.setViewportSize(VIEWPORTS[viewport]);
  await gotoUser(page, path);
  // 공개 API 응답을 기다리지 않고 슬라이드가 붙기를 기다린다 — 응답이 엔진 캐시로 채워지면 네트워크 응답이 없어 30초를
  // 다 쓰고, 응답이 와도 슬라이더 번들·Swiper 가 붙기 전일 수 있다(갱신 직후 첫 방문). 배너가 없는 위치면 그냥 지나간다.
  await expect.poll(() => page.locator(`section.hs-slider[data-position="${position}"] [data-index]`).count(), { timeout: 30_000 })
    .toBeGreaterThan(0).catch(() => undefined);
  await page.waitForTimeout(500);
  return slideTitles(page);
}

// @scenario flow=double_click_save_creates_one, viewport=desktop
// @effects test_data_cleaned_up
test('등록 화면에서 저장을 빠르게 두 번 눌러도 배너는 한 번만 등록된다', async ({ page, managerToken, created }) => {
  const title = uniqueTitle('연타');
  await authenticatePage(page, managerToken);
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.goto(`${ADMIN_LIST}/create?position=home_hero`);
  await expect(page.locator('#footer_save_button')).toBeVisible({ timeout: 30_000 });
  await page.locator('input[name="title[ko]"]').fill(title);
  const uploaded = page.waitForResponse((r) => r.url().includes(`${API}/admin/banner-images`) && r.request().method() === 'POST');
  await page.locator('#pc_image_uploader input[type="file"]').setInputFiles({ name: 'e2e-pc.png', mimeType: 'image/png', buffer: PNG_1X1 });
  expect((await uploaded).status()).toBe(201);
  await expect(page.locator('#footer_save_button')).toBeEnabled();

  const posts: number[] = [];
  page.on('response', async (r) => {
    if (r.url().endsWith(`${API}/admin/banners`) && r.request().method() === 'POST') {
      posts.push(r.status());
      if (r.status() === 201) created.push((await r.json()).data.id);
    }
  });
  // 같은 틱에 두 번 — 잠금(isSaving)이 화면에 반영되기 전이다
  await page.locator('#footer_save_button').evaluate((button: HTMLButtonElement) => { button.click(); button.click(); });
  await expect(page).toHaveURL(/\/admin\/plugins\/sirsoft-hero_section\/banners\?.*position=home_hero/, { timeout: 30_000 });
  await page.waitForTimeout(1_000);
  expect(posts).toEqual([201]);
});

for (const viewport of ['desktop', 'mobile'] as const) {
  test.describe(`관리자 → 사이트 홈 (${viewport})`, () => {
    // @scenario flow=create_banner_appears_on_home, viewport=desktop
    // @scenario flow=create_banner_appears_on_home, viewport=mobile
    // @effects user_screen_reflects_admin_change, test_data_cleaned_up
    test(`등록 화면에서 만든 배너가 홈 슬라이더에 보인다 (${viewport})`, async ({ page, request, managerToken, created }) => {
      const errors: string[] = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(`${m.text()} ${m.location().url}`); });
      const title = uniqueTitle('등록');

      await authenticatePage(page, managerToken);
      await page.setViewportSize(VIEWPORTS.desktop);
      await page.goto(`${ADMIN_LIST}/create?position=home_hero`);
      await expect(page.locator('#footer_save_button')).toBeVisible({ timeout: 30_000 });
      // PC 이미지가 없으면 저장이 잠겨 있다
      await expect(page.locator('#footer_save_button')).toBeDisabled();

      await page.locator('input[name="title[ko]"]').fill(title);
      const uploaded = page.waitForResponse((r) => r.url().includes(`${API}/admin/banner-images`) && r.request().method() === 'POST');
      await page.locator('#pc_image_uploader input[type="file"]').setInputFiles({ name: 'e2e-pc.png', mimeType: 'image/png', buffer: PNG_1X1 });
      expect((await uploaded).status()).toBe(201);
      // 1x1 이미지는 권장 크기와 달라 안내가 뜨지만 저장은 막지 않는다
      await expect(page.locator('#pc_size_warning')).toBeVisible();
      await expect(page.locator('#footer_save_button')).toBeEnabled();

      const saved = page.waitForResponse((r) => r.url().endsWith(`${API}/admin/banners`) && r.request().method() === 'POST');
      await page.locator('#footer_save_button').click();
      const response = await saved;
      expect(response.status()).toBe(201);
      created.push((await response.json()).data.id);
      // 저장 뒤에는 같은 위치 탭 목록으로 돌아온다
      await expect(page).toHaveURL(/\/admin\/plugins\/sirsoft-hero_section\/banners\?.*position=home_hero/, { timeout: 30_000 });

      expect(await homeTitles(page, viewport)).toContain(title);
      await expect(slider(page)).toBeVisible();
      expect(errors.filter((e) => !/favicon/i.test(e))).toEqual([]);
    });

    // @scenario flow=scheduled_banner_hidden_until_start, viewport=desktop
    // @scenario flow=scheduled_banner_hidden_until_start, viewport=mobile
    // @effects user_screen_reflects_admin_change, test_data_cleaned_up
    test(`시작 전인 예약 배너는 목록에 「예약」으로 보이고 홈에는 나오지 않는다 (${viewport})`, async ({ page, request, managerToken, created }) => {
      const now = uniqueTitle('지금');
      const later = uniqueTitle('예약');
      const tomorrow = new Date(Date.now() + 2 * 86_400_000).toISOString().slice(0, 16);
      const nextWeek = new Date(Date.now() + 9 * 86_400_000).toISOString().slice(0, 16);
      created.push(await createBanner(request, managerToken, { title: { ko: now } }));
      created.push(await createBanner(request, managerToken, { title: { ko: later }, display_period: 'range', starts_at: tomorrow, ends_at: nextWeek }));

      await authenticatePage(page, managerToken);
      await gotoAdminList(page);
      const row = page.locator('#hero_banner_datagrid tr', { hasText: later });
      await expect(row).toContainText('예약');

      const titles = await homeTitles(page, viewport);
      expect(titles).toContain(now);
      expect(titles).not.toContain(later);
    });

    // @scenario flow=reorder_reflected_on_shop_main, viewport=desktop
    // @scenario flow=reorder_reflected_on_shop_main, viewport=mobile
    // @effects user_screen_reflects_admin_change, test_data_cleaned_up
    test(`[순서 변경] 창에서 바꾼 순서가 쇼핑몰 메인 슬라이더 순서가 된다 (${viewport})`, async ({ page, request, managerToken, created }) => {
      // 순서 저장은 위치의 배너 전부를 다시 쓴다(빠진 배너가 있으면 거부) — 운영 배너가 있는 위치에서 돌리면 그 배너의
      // 수정 시각까지 바뀐다. 그래서 배너가 없는 쇼핑몰 메인 위치에서 이 사양이 만든 배너만으로 순서를 바꾼다.
      const position = 'shop_hero';
      const path = '/shop/products';
      const first = uniqueTitle('첫째');
      const second = uniqueTitle('둘째');
      created.push(await createBanner(request, managerToken, { position, title: { ko: first } }));
      const secondId = await createBanner(request, managerToken, { position, title: { ko: second } });
      created.push(secondId);
      const order = await request.get(`${API}/admin/banners/order?position=${position}`, { headers: auth(managerToken) });
      expect((await order.json()).data.data.map((b: { id: number }) => b.id), '쇼핑몰 메인 위치에 사양 밖 배너가 있으면 그 배너까지 다시 저장된다').toEqual(created);

      const before = await homeTitles(page, viewport, position, path);
      expect(before.indexOf(first)).toBeLessThan(before.indexOf(second));

      await authenticatePage(page, managerToken);
      await gotoAdminList(page, position);
      await page.locator('#reorder_button').click();
      const modal = page.getByRole('dialog', { name: '배너 순서 변경' });
      await expect(modal).toBeVisible({ timeout: 20_000 });
      // 바뀐 것이 없으면 저장이 잠겨 있다
      await expect(page.getByTestId('hero-reorder-save')).toBeDisabled();
      await page.getByTestId(`hero-reorder-up-${secondId}`).click();
      const saved = page.waitForResponse((r) => r.url().includes(`${API}/admin/banners/order`) && r.request().method() === 'PUT');
      await page.getByTestId('hero-reorder-save').click();
      expect((await saved).status()).toBe(200);
      await expect(modal).toBeHidden({ timeout: 20_000 });

      const after = await homeTitles(page, viewport, position, path);
      expect(after.indexOf(second)).toBeLessThan(after.indexOf(first));
    });

    // @scenario flow=bulk_hide_removes_from_home, viewport=desktop
    // @scenario flow=bulk_hide_removes_from_home, viewport=mobile
    // @effects user_screen_reflects_admin_change, test_data_cleaned_up
    test(`표에서 골라 [비노출] 하면 홈에서 사라진다 (${viewport})`, async ({ page, request, managerToken, created }) => {
      const keep = uniqueTitle('유지');
      const hide = uniqueTitle('숨김');
      created.push(await createBanner(request, managerToken, { title: { ko: keep } }));
      created.push(await createBanner(request, managerToken, { title: { ko: hide } }));

      await authenticatePage(page, managerToken);
      await gotoAdminList(page);
      // 선택칸은 엔진 상태를 거쳐 체크 표시가 바뀐다 — 누른 뒤 체크 상태를 기다린다
      const pick = page.getByRole('checkbox', { name: `${hide} 선택` });
      await pick.click();
      await expect(pick).toBeChecked();
      await expect(page.locator('#bulk_action_bar')).toContainText('1');
      const done = page.waitForResponse((r) => r.url().includes(`${API}/admin/banners/bulk-status`));
      await page.locator('#bulk_hide_button').click();
      expect((await done).status()).toBe(200);
      await expect(page.locator('#hero_banner_datagrid tr', { hasText: hide })).toContainText('비활성', { timeout: 20_000 });

      const titles = await homeTitles(page, viewport);
      expect(titles).toContain(keep);
      expect(titles).not.toContain(hide);
    });

    // @scenario flow=delete_removes_from_home, viewport=desktop
    // @scenario flow=delete_removes_from_home, viewport=mobile
    // @effects user_screen_reflects_admin_change, test_data_cleaned_up
    test(`행 메뉴 [삭제] 로 지운 배너는 홈에서 사라지고 이미지도 지워진다 (${viewport})`, async ({ page, request, managerToken, created }) => {
      const title = uniqueTitle('삭제');
      const id = await createBanner(request, managerToken, { title: { ko: title } });
      created.push(id);
      const detail = await request.get(`${API}/admin/banners/${id}`, { headers: auth(managerToken) });
      const imageUrl = (await detail.json()).data.images.pc.url as string;
      expect(await homeTitles(page, viewport)).toContain(title);

      await authenticatePage(page, managerToken);
      await gotoAdminList(page);
      const row = page.locator('#hero_banner_datagrid tbody tr', { hasText: title });
      await row.getByRole('button', { name: '작업' }).click();
      await page.getByRole('menuitem', { name: '삭제' }).click();
      const modal = page.getByRole('dialog', { name: '배너 삭제' });
      await expect(modal).toBeVisible();
      await expect(modal).toContainText(title);
      const removed = page.waitForResponse((r) => r.url().endsWith(`${API}/admin/banners/${id}`) && r.request().method() === 'DELETE');
      await modal.getByRole('button', { name: '삭제' }).click();
      expect((await removed).status()).toBe(200);
      await expect(page.locator('#hero_banner_datagrid tbody tr', { hasText: title })).toHaveCount(0, { timeout: 20_000 });

      expect(await homeTitles(page, viewport)).not.toContain(title);
      // 영구 삭제 — 공개 이미지 주소도 더는 열리지 않는다
      expect((await request.get(imageUrl)).status()).toBe(404);
    });

    // @scenario flow=edit_image_applies_on_save, viewport=desktop
    // @scenario flow=edit_image_applies_on_save, viewport=mobile
    // @effects user_screen_reflects_admin_change, test_data_cleaned_up
    test(`수정 화면에서 바꾼 이미지는 [저장] 전에는 홈에 반영되지 않고, 저장하면 바뀐다 (${viewport})`, async ({ page, request, managerToken, created }) => {
      const errors: string[] = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(`${m.text()} ${m.location().url}`); });
      const title = uniqueTitle('이미지');
      const id = await createBanner(request, managerToken, { title: { ko: title } });
      created.push(id);
      const publicImage = async (): Promise<string | undefined> => {
        const res = await request.get(`${API}/hero?position=home_hero`, { headers: { Accept: 'application/json' } });
        const banners = (await res.json()).data.banners as Array<{ title: string; images: { pc: { url: string } } }>;
        return banners.find((b) => b.title === title)?.images.pc.url;
      };
      const before = await publicImage();
      expect(before).toBeTruthy();

      await authenticatePage(page, managerToken);
      await page.setViewportSize(VIEWPORTS.desktop);
      await page.goto(`${ADMIN_LIST}/${id}/edit`);
      await expect(page.locator('#footer_save_button')).toBeEnabled({ timeout: 30_000 });
      // PC 업로더는 1장이 차 있으면 올리기 칸이 없다 — 운영자처럼 [삭제]로 화면에서 뺀 뒤 새로 올린다(서버는 아직 그대로)
      await page.locator('#pc_image_uploader').getByRole('button', { name: '삭제' }).click();
      await expect(page.locator('#footer_save_button')).toBeDisabled();
      expect(await publicImage()).toBe(before);
      const uploaded = page.waitForResponse((r) => r.url().includes(`${API}/admin/banner-images`) && r.request().method() === 'POST');
      await page.locator('#pc_image_uploader input[type="file"]').setInputFiles({ name: 'e2e-pc-new.png', mimeType: 'image/png', buffer: PNG_1X1 });
      const upload = await uploaded;
      expect(upload.status()).toBe(201);
      const next = (await upload.json()).data.url as string;
      // 저장 전 미리보기는 올린 이미지를 그린다(임시 이미지도 공개 주소로 열린다)
      await expect(page.locator('section[aria-labelledby="hero_form_preview_heading"]').frameLocator('iframe').locator(`img.hs-slider__image[src="${next}"]`)).toBeVisible({ timeout: 20_000 });

      // 올리기만 해서는 사용자 화면이 바뀌지 않는다
      expect(await publicImage()).toBe(before);

      const saved = page.waitForResponse((r) => r.url().endsWith(`${API}/admin/banners/${id}`) && r.request().method() === 'PUT');
      await page.locator('#footer_save_button').click();
      expect((await saved).status()).toBe(200);
      await expect(page).toHaveURL(/\/admin\/plugins\/sirsoft-hero_section\/banners\?/, { timeout: 30_000 });

      expect(await publicImage()).toBe(next);
      // 옛 이미지는 지워졌다
      expect((await request.get(before!)).status()).toBe(404);
      expect(await homeTitles(page, viewport)).toContain(title);
      await expect(page.locator(`section.hs-slider img.hs-slider__image[src="${next}"]`)).toHaveCount(1);
      expect(errors.filter((e) => !/favicon/i.test(e))).toEqual([]);
    });
  });
}
