/**
 * #810 후속 — 동시 수정 충돌(409) 뒤 같은 창에서 최신본과 비교해 재적용하고 저장한다.
 * @scenario-manifest tests/scenarios/preview-frame-and-conflict.yaml
 * @effects others_changes_not_overwritten, stale_image_id_never_resent, my_temp_images_preserved
 *
 * 「다른 운영자」의 저장은 API 로 끼워 넣는다. 화면 조작은 실제 단추·입력으로만 한다. 사양이 만든 배너는 fixture 가 지운다.
 */
import { test, expect, authenticatePage, createBanner, auth, ADMIN_LIST, API, PNG_1X1, VIEWPORTS } from '../fixtures/hero-auth';
import type { APIRequestContext, Page } from '@playwright/test';

test.setTimeout(120_000);

/** 수정 화면을 엽니다. */
async function edit(page: Page, token: string, id: number): Promise<void> {
  await authenticatePage(page, token);
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.goto(`${ADMIN_LIST}/${id}/edit`);
  await expect(page.locator('#footer_save_button')).toBeEnabled({ timeout: 30_000 });
}

/** 다른 운영자가 상세를 받아 일부를 바꿔 저장합니다(revision 을 함께 보내 정상 저장). */
async function otherSaves(request: APIRequestContext, token: string, id: number, over: Record<string, unknown>): Promise<Record<string, any>> {
  const data = (await (await request.get(`${API}/admin/banners/${id}`, { headers: auth(token) })).json()).data;
  const response = await request.put(`${API}/admin/banners/${id}`, {
    headers: auth(token),
    data: { ...data, starts_at: data.starts_at_local, ends_at: data.ends_at_local, pc_image_id: data.images.pc.id, mobile_image_id: data.images.mobile?.id ?? null, ...over },
  });
  expect(response.status(), await response.text()).toBe(200);
  return (await response.json()).data;
}

/** 저장 단추를 눌러 PUT 상태를 돌려줍니다. */
async function save(page: Page, id: number): Promise<number> {
  const response = page.waitForResponse((r) => r.url().endsWith(`/admin/banners/${id}`) && r.request().method() === 'PUT');
  await page.locator('#footer_save_button').click();
  return (await response).status();
}

// @scenario surface=conflict_both_changed_requires_choice
// @scenario surface=conflict_stale_image_not_resent
// @effects others_changes_not_overwritten, stale_image_id_never_resent
test('충돌 뒤 최신본을 불러와 비교하고, 둘 다 바꾼 항목을 골라 적용하면 다른 운영자의 PC 교체를 지킨 채 저장된다', async ({ page, request, managerToken, created }) => {
  const id = await createBanner(request, managerToken, { is_active: false, title: { ko: '충돌 원본' } });
  created.push(id);
  await edit(page, managerToken, id);
  const pageErrors: string[] = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  await page.locator('input[name="title[ko]"]').fill('내 제목');
  await page.locator('#hero_link_url').fill('/mine');

  const tempKey = `e2e-other-${Date.now()}`;
  const upload = await request.post(`${API}/admin/banner-images`, { headers: auth(managerToken), multipart: { file: { name: 'other-pc.png', mimeType: 'image/png', buffer: PNG_1X1 }, device: 'pc', temp_key: tempKey } });
  const otherPc = (await upload.json()).data;
  const latest = await otherSaves(request, managerToken, id, { title: { ko: '다른 운영자 제목' }, temp_key: tempKey, pc_image_id: otherPc.id });

  expect(await save(page, id)).toBe(409);
  await expect(page.locator('input[name="title[ko]"]')).toHaveValue('내 제목');
  await page.locator('#conflict_load_button').click();
  const title = page.locator('[data-conflict-field="title"]');
  await expect(title).toHaveAttribute('data-change', 'both');
  await expect(page.locator('[data-conflict-field="link"]')).toHaveAttribute('data-change', 'mine');
  await expect(page.locator('[data-conflict-field="pc_image"]')).toHaveAttribute('data-change', 'theirs');
  await expect(page.locator('[data-conflict-field="pc_image"] input[value="mine"]')).toBeDisabled();
  await expect(page.locator('.hs-conflict__apply')).toBeDisabled();

  await title.locator('input[value="mine"]').check();
  await page.locator('.hs-conflict__apply').click();
  await expect(page.locator('#conflict_panel')).toHaveCount(0);
  await expect(page.locator('input[name="title[ko]"]')).toHaveValue('내 제목');

  const sent = page.waitForRequest((r) => r.url().endsWith(`/admin/banners/${id}`) && r.method() === 'PUT');
  expect(await save(page, id)).toBe(200);
  const body = JSON.parse((await sent).postData() ?? '{}');
  expect(body.revision).toBe(latest.revision);
  expect(body.pc_image_id).toBe(otherPc.id);

  const saved = (await (await request.get(`${API}/admin/banners/${id}`, { headers: auth(managerToken) })).json()).data;
  expect(saved.title).toEqual({ ko: '내 제목' });
  expect(saved.link_url).toBe('/mine');
  expect(saved.images.pc.id).toBe(otherPc.id);
  expect(pageErrors).toEqual([]);
});

// @scenario surface=conflict_load_latest_and_retry
// @scenario surface=conflict_reapply_and_conflict_again
// @effects others_changes_not_overwritten, my_temp_images_preserved
test('최신본 조회가 실패하면 입력을 둔 채 다시 시도할 수 있고, 적용 뒤 또 충돌해도 입력이 남아 다시 비교·저장된다', async ({ page, request, managerToken, created }) => {
  const id = await createBanner(request, managerToken, { is_active: false, description: { ko: '원래 설명' } });
  created.push(id);
  await edit(page, managerToken, id);

  await page.locator('textarea[name="description[ko]"]').fill('내 설명');
  await otherSaves(request, managerToken, id, { link_url: '/other' });
  expect(await save(page, id)).toBe(409);

  let failOnce = true;
  await page.route(`**${API}/admin/banners/${id}`, (route) => {
    if (failOnce && route.request().method() === 'GET') {
      failOnce = false;
      return route.fulfill({ status: 500, contentType: 'application/json', body: '{"success":false,"message":"stub"}' });
    }
    return route.continue();
  });
  await page.locator('#conflict_load_button').click();
  await expect(page.locator('#conflict_load_error')).toBeVisible();
  await expect(page.locator('[data-conflict-field]')).toHaveCount(0);
  await expect(page.locator('textarea[name="description[ko]"]')).toHaveValue('내 설명');

  await page.locator('#conflict_load_button').click();
  await expect(page.locator('#conflict_load_error')).toHaveCount(0);
  await expect(page.locator('[data-conflict-field="link"]')).toHaveAttribute('data-change', 'theirs');
  await page.locator('.hs-conflict__apply').click();

  await otherSaves(request, managerToken, id, { title: { ko: '또 바뀐 제목' } });
  expect(await save(page, id)).toBe(409);
  await expect(page.locator('textarea[name="description[ko]"]')).toHaveValue('내 설명');
  await page.locator('#conflict_load_button').click();
  await expect(page.locator('[data-conflict-field="title"]')).toHaveAttribute('data-change', 'theirs');
  await expect(page.locator('[data-conflict-field="link"]')).toHaveCount(0);
  await page.locator('.hs-conflict__apply').click();
  expect(await save(page, id)).toBe(200);

  const saved = (await (await request.get(`${API}/admin/banners/${id}`, { headers: auth(managerToken) })).json()).data;
  expect(saved.description).toEqual({ ko: '내 설명' });
  expect(saved.link_url).toBe('/other');
  expect(saved.title).toEqual({ ko: '또 바뀐 제목' });
});
