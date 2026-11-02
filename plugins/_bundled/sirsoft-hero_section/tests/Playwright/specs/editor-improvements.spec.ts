/**
 * #810 편집 개선 — 실제 업로드 대기·기기/언어 미리보기·수정 충돌.
 * @scenario-manifest tests/scenarios/editor-improvements.yaml
 * @effects editor_state_protected
 */
import { test, expect, authenticatePage, createBanner, auth, ADMIN_LIST, API, uniqueTitle, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page } from '@playwright/test';

test.use({ timezoneId: 'America/Los_Angeles' });
test.setTimeout(90_000);

/** PC 이미지가 있는 수정 화면을 엽니다. */
async function edit(page: Page, token: string, id: number): Promise<void> {
  await authenticatePage(page, token);
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.goto(`${ADMIN_LIST}/${id}/edit`);
  await expect(page.locator('#footer_save_button')).toBeEnabled({ timeout: 30_000 });
}

/** 실제 권장 크기의 서로 다른 색 PNG를 메모리에서 만듭니다. */
async function mobilePng(page: Page) {
  const encoded = await page.evaluate(() => {
    const canvas = document.createElement('canvas');
    canvas.width = 750;
    canvas.height = 1000;
    const context = canvas.getContext('2d')!;
    context.fillStyle = '#be185d';
    context.fillRect(0, 0, 750, 1000);
    return canvas.toDataURL('image/png').split(',')[1];
  });
  return { name: 'editor-mobile.png', mimeType: 'image/png', buffer: Buffer.from(encoded, 'base64') };
}

// @scenario case=editor_upload_gate
test('모바일 업로드 지연·실패는 저장을 막고 파일 제거 후 해제한다', async ({ page, request, managerToken, created }) => {
  const id = await createBanner(request, managerToken, { is_active: false });
  created.push(id);
  await edit(page, managerToken, id);
  let release!: () => void;
  const gate = new Promise<void>((resolve) => { release = resolve; });
  await page.route('**/admin/banner-images', async (route) => {
    await gate;
    await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ success: false, message: '검수용 업로드 실패' }) });
  });
  try {
    await page.locator('#mobile_image_uploader input[type=file]').setInputFiles(await mobilePng(page));
    await expect(page.locator('#footer_save_button')).toBeDisabled();
    release();
    await expect(page.locator('#mobile_image_uploader').getByRole('button', { name: '재시도', exact: true })).toBeVisible();
    await expect(page.locator('#footer_save_button')).toBeDisabled();
    await page.locator('#mobile_image_uploader').getByRole('button', { name: '삭제', exact: true }).click();
    await expect(page.locator('#footer_save_button')).toBeEnabled();
  } finally {
    release();
    await page.unroute('**/admin/banner-images');
  }
});

// @scenario case=editor_preview_roundtrip
test('기기·콘텐츠 언어·확대·초점 설정을 저장하고 새로고침해도 유지한다', async ({ page, request, managerToken, created }) => {
  const id = await createBanner(request, managerToken, { is_active: false, title: { ko: uniqueTitle('편집'), en: 'English preview' } });
  created.push(id);
  await edit(page, managerToken, id);
  await expect(page.locator('iframe')).toHaveAttribute('width', '1366');
  await expect(page.frameLocator('iframe').locator('img.hs-slider__image')).toBeVisible();
  await page.locator('#preview_device_mobile').click();
  await expect(page.locator('iframe')).toHaveAttribute('width', '390');
  await page.locator('.hs-editor-language select').selectOption('en');
  await expect(page.frameLocator('iframe').locator('h2')).toHaveText('English preview');
  const title = uniqueTitle('연동');
  await page.locator('input[name="title[ko]"]').fill(title);
  await expect(page.locator('.hs-editor-language select')).toHaveValue('ko');
  await expect(page.frameLocator('iframe').locator('h2')).toHaveText(title);
  await page.getByRole('button', { name: '크게 보기', exact: true }).click();
  await expect(page.locator('dialog')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('dialog')).toHaveCount(0);
  await expect(page.getByRole('button', { name: '크게 보기', exact: true })).toBeFocused();
  await page.locator('#hero_focus_mobile_x').focus();
  await page.locator('#hero_focus_mobile_x').press('End');
  await page.getByRole('radio', { name: '어두운 배경', exact: true }).check();
  await expect.poll(() => page.evaluate(() => (window as any).G7Core.state.getLocal().form.presentation.overlay)).toBe('dark');
  const save = page.waitForResponse((r) => r.url().endsWith(`/admin/banners/${id}`) && r.request().method() === 'PUT');
  await page.locator('#footer_save_button').click();
  const response = await save;
  expect(response.status()).toBe(200);
  expect((await response.json()).data.presentation.mobile_x).toBe(100);
  await page.goto(`${ADMIN_LIST}/${id}/edit`);
  await expect(page.locator('#hero_focus_mobile_x')).toHaveValue('100');
  await page.reload();
  await expect(page.locator('#hero_focus_mobile_x')).toHaveValue('100');
  await expect(page.locator('[data-period-status]')).toContainText('사이트 시간대:');
});

// @scenario case=editor_conflict_preserves_input
test('다른 운영자의 저장 뒤에는 409를 알리고 내 입력과 임시 이미지를 유지한다', async ({ page, request, managerToken, created }) => {
  const id = await createBanner(request, managerToken, { is_active: false });
  created.push(id);
  await edit(page, managerToken, id);
  const mine = uniqueTitle('내 입력');
  await page.locator('input[name="title[ko]"]').fill(mine);
  const latest = await request.get(`${API}/admin/banners/${id}`, { headers: auth(managerToken) });
  const data = (await latest.json()).data;
  const changed = await request.put(`${API}/admin/banners/${id}`, { headers: auth(managerToken), data: { ...data, title: { ko: '먼저 저장한 운영자' } } });
  expect(changed.status()).toBe(200);
  const save = page.waitForResponse((r) => r.url().endsWith(`/admin/banners/${id}`) && r.request().method() === 'PUT');
  await page.locator('#footer_save_button').click();
  expect((await save).status()).toBe(409);
  await expect(page.locator('input[name="title[ko]"]')).toHaveValue(mine);
  await expect(page.getByRole('link', { name: '최신 배너를 다른 탭에서 확인' })).toBeVisible();
  expect(new URL(page.url()).pathname).toBe(`${ADMIN_LIST}/${id}/edit`);
});
