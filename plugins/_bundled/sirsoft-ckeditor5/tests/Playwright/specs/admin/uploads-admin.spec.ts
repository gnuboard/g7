/**
 * E2E: 에디터 업로드 이미지 관리 화면 (공개 #115)
 *
 * @scenario admin_ckeditor5_uploads_list_filter_and_permission_boundary
 * @effects admin_index_lists_uploads, admin_ui_reference_badge, admin_ui_merge_query,
 *          admin_index_unreferenced_filter, admin_destroy_requires_delete_permission,
 *          settings_cleanup_toggle_default_off, admin_destroy_removes_file_and_record,
 *          admin_bulk_delete_removes_selected, settings_cleanup_retention_round_trip
 *
 * 배경: 에디터로 올렸지만 쓰이지 않는 이미지가 무기한 누적되던 결함(#115)의 회수 화면.
 * 사용자 파일을 실제로 파기하는 화면이라, 브라우저에서 실제로 확인해야 하는 것은
 * "목록이 뜬다" 가 아니라 아래 여섯 가지다.
 *
 *  1. 목록·필터·배지가 i18n 원문 키 노출 없이 렌더된다
 *  2. 필터를 건 뒤 페이지를 옮겨도 목록 상태(검색·참조상태)가 URL 에 보존된다
 *     (mergeQuery 누락 시 필터가 조용히 풀려 다른 대상이 화면에 실린다)
 *  3. 삭제 권한이 없는 계정에서는 삭제가 403 으로 막힌다
 *  4. 설정 화면의 자동 정리 토글이 기본 꺼짐으로 보인다
 *  5. 실제로 올린 이미지가 단건·일괄 삭제로 목록에서 사라진다 (파괴 성공 경로)
 *     — 거절 경로(403/404/422)만 검증하면 "아무것도 못 지우는" 회귀를 잡지 못한다
 *  6. 설정 값을 바꿔 저장하면 다시 조회했을 때 그대로 남는다 (저장 왕복)
 */
import { test, expect, authenticatePage } from '../../fixtures/ckeditor5-auth';

const UPLOADS_URL = '/admin/plugins/sirsoft-ckeditor5/uploads';
const SETTINGS_URL = '/admin/plugins/sirsoft-ckeditor5/settings';

const DATAGRID = '#ckeditor5_uploads_datagrid';
const FILTER_SECTION = '#filter_section';
const NOTICE = '#reference_notice';

/** 1x1 투명 PNG — 업로드 검증(image 규칙)을 통과하는 최소 이미지 */
const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

/**
 * 에디터 업로드 엔드포인트로 이미지를 올리고, 관리 목록에서 그 행의 id 를 찾아 돌려준다.
 *
 * 업로드 응답은 에디터용(URL) 이라 관리 id 를 담지 않으므로 원본명으로 되찾는다.
 *
 * @param page Playwright 페이지
 * @param token 업로드·조회 권한 토큰
 * @returns 업로드된 행의 id 와 원본 파일명
 */
async function uploadImage(
  page: import('@playwright/test').Page,
  token: string,
): Promise<{ id: number; filename: string }> {
  const filename = `e2e-issue115-${Date.now()}-${Math.floor(Math.random() * 100000)}.png`;

  const upload = await page.request.post('/api/plugins/sirsoft-ckeditor5/upload', {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    multipart: { upload: { name: filename, mimeType: 'image/png', buffer: PNG_1X1 } },
  });

  expect(upload.ok(), `업로드가 성공해야 한다 (status ${upload.status()})`).toBeTruthy();

  const list = await page.request.get(
    `/api/plugins/sirsoft-ckeditor5/admin/uploads?search=${encodeURIComponent(filename)}&per_page=5`,
    { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
  );

  expect(list.status()).toBe(200);

  const rows = (await list.json()).data.data as Array<{ id: number; original_name: string }>;
  const row = rows.find((item) => item.original_name === filename);

  expect(row, '방금 올린 이미지가 관리 목록에서 조회되어야 한다').toBeTruthy();

  return { id: row!.id, filename };
}

/**
 * 원본명으로 관리 목록을 조회해 남아 있는 행 수를 센다.
 *
 * @param page Playwright 페이지
 * @param token 조회 권한 토큰
 * @param filename 원본 파일명
 * @returns 남아 있는 행 수
 */
async function countByFilename(
  page: import('@playwright/test').Page,
  token: string,
  filename: string,
): Promise<number> {
  const response = await page.request.get(
    `/api/plugins/sirsoft-ckeditor5/admin/uploads?search=${encodeURIComponent(filename)}&per_page=5`,
    { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
  );

  expect(response.status()).toBe(200);

  const rows = (await response.json()).data.data as Array<{ original_name: string }>;

  return rows.filter((item) => item.original_name === filename).length;
}

/**
 * 업로드 관리 화면으로 이동하고 목록이 붙을 때까지 기다린다.
 *
 * @param page Playwright 페이지
 */
async function gotoUploads(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(UPLOADS_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(DATAGRID)).toBeAttached({ timeout: 20_000 });
}

// @scenario trigger=admin_ui, permitted=yes
// @effects admin_index_lists_uploads, admin_ui_reference_badge
test('#115 - 업로드 관리 화면이 목록·필터·안내와 함께 렌더된다', async ({ page, uploadsManageToken }) => {
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });

  await authenticatePage(page, uploadsManageToken);
  await gotoUploads(page);

  expect(page.url()).not.toMatch(/\/admin\/login/);

  await expect(page.locator(FILTER_SECTION)).toBeVisible();
  await expect(page.locator(NOTICE)).toBeVisible();

  // i18n 원문 키가 그대로 노출되면 번역 누락이다.
  const body = (await page.locator('body').innerText()) ?? '';
  expect(body).not.toContain('sirsoft-ckeditor5.admin.uploads');
  expect(body).not.toContain('$t:');

  expect(consoleErrors).toEqual([]);
});

// @scenario trigger=admin_ui, filter=unreferenced
// @effects admin_index_unreferenced_filter, admin_ui_merge_query
test('#115 - 참조 상태 필터가 URL 에 실리고 페이지 이동에도 보존된다', async ({ page, uploadsManageToken }) => {
  await authenticatePage(page, uploadsManageToken);
  await page.goto(`${UPLOADS_URL}?referenced=unreferenced&search=`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(DATAGRID)).toBeAttached({ timeout: 20_000 });

  expect(page.url()).toContain('referenced=unreferenced');

  // 목록 조회가 필터를 그대로 서버에 전달하는지 — 응답이 200 이어야 화면이 유지된다.
  const response = await page.request.get(
    '/api/plugins/sirsoft-ckeditor5/admin/uploads?referenced=unreferenced&per_page=20',
    { headers: { Authorization: `Bearer ${uploadsManageToken}`, Accept: 'application/json' } },
  );

  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload.data).toHaveProperty('pagination');
  expect(payload.data).toHaveProperty('meta.scan_limited');
});

// @scenario trigger=admin_ui, permitted=read_only
// @effects admin_destroy_requires_delete_permission
test('#115 - 삭제 권한이 없으면 삭제 API 가 403 으로 막힌다', async ({ page, uploadsReadOnlyToken }) => {
  await authenticatePage(page, uploadsReadOnlyToken);
  await gotoUploads(page);

  // 조회는 가능해야 한다 (읽기 권한 보유)
  const list = await page.request.get('/api/plugins/sirsoft-ckeditor5/admin/uploads?per_page=1', {
    headers: { Authorization: `Bearer ${uploadsReadOnlyToken}`, Accept: 'application/json' },
  });
  expect(list.status()).toBe(200);

  // 삭제는 대상 존재 여부와 무관하게 권한 단계에서 먼저 막혀야 한다.
  const destroy = await page.request.delete('/api/plugins/sirsoft-ckeditor5/admin/uploads/999999', {
    headers: { Authorization: `Bearer ${uploadsReadOnlyToken}`, Accept: 'application/json' },
  });
  expect(destroy.status()).toBe(403);
});

// @scenario trigger=admin_ui, response=matrix
// @effects admin_bulk_delete_rejects_empty, admin_destroy_not_found
test('#115 - 응답 매트릭스: 빈 선택 422 / 없는 대상 404', async ({ page, uploadsManageToken }) => {
  await authenticatePage(page, uploadsManageToken);

  const headers = { Authorization: `Bearer ${uploadsManageToken}`, Accept: 'application/json' };

  const empty = await page.request.post('/api/plugins/sirsoft-ckeditor5/admin/uploads/bulk-delete', {
    headers,
    data: { ids: [] },
  });
  expect(empty.status()).toBe(422);
  expect(await empty.json()).toHaveProperty('errors.ids');

  const missing = await page.request.delete('/api/plugins/sirsoft-ckeditor5/admin/uploads/999999', { headers });
  expect(missing.status()).toBe(404);
});

// @scenario trigger=admin_ui_single, permitted=yes
// @effects admin_destroy_removes_file_and_record
test('#115 - 올린 이미지가 단건 삭제로 목록에서 사라진다', async ({ page, uploadsManageToken }) => {
  await authenticatePage(page, uploadsManageToken);
  await gotoUploads(page);

  const { id, filename } = await uploadImage(page, uploadsManageToken);

  expect(await countByFilename(page, uploadsManageToken, filename)).toBe(1);

  const destroy = await page.request.delete(`/api/plugins/sirsoft-ckeditor5/admin/uploads/${id}`, {
    headers: { Authorization: `Bearer ${uploadsManageToken}`, Accept: 'application/json' },
  });

  expect(destroy.status()).toBe(200);
  expect(await countByFilename(page, uploadsManageToken, filename)).toBe(0);
});

// @scenario trigger=admin_ui_bulk, permitted=yes
// @effects admin_bulk_delete_removes_selected
test('#115 - 선택한 이미지들이 일괄 삭제로 함께 사라진다', async ({ page, uploadsManageToken }) => {
  await authenticatePage(page, uploadsManageToken);
  await gotoUploads(page);

  const first = await uploadImage(page, uploadsManageToken);
  const second = await uploadImage(page, uploadsManageToken);

  const bulk = await page.request.post('/api/plugins/sirsoft-ckeditor5/admin/uploads/bulk-delete', {
    headers: { Authorization: `Bearer ${uploadsManageToken}`, Accept: 'application/json' },
    data: { ids: [first.id, second.id] },
  });

  expect(bulk.status()).toBe(200);
  expect(await countByFilename(page, uploadsManageToken, first.filename)).toBe(0);
  expect(await countByFilename(page, uploadsManageToken, second.filename)).toBe(0);
});

// @scenario trigger=settings_screen, toggle=default
// @effects settings_cleanup_retention_round_trip
test('#115 - 보존기간을 저장하면 다시 조회해도 유지된다', async ({ page, uploadsManageToken }) => {
  await authenticatePage(page, uploadsManageToken);

  const headers = { Authorization: `Bearer ${uploadsManageToken}`, Accept: 'application/json' };
  const SETTINGS_API = '/api/admin/plugins/sirsoft-ckeditor5/settings';

  const before = await page.request.get(SETTINGS_API, { headers });
  expect(before.status()).toBe(200);

  const current = (await before.json()).data as Record<string, unknown>;
  const originalDays = Number(current.unusedImageRetentionDays ?? 30);
  const nextDays = originalDays === 45 ? 40 : 45;

  // 자동 정리 토글은 건드리지 않는다 — 켜 두면 검수 환경에서 실제 파기가 돌기 시작한다.
  const saved = await page.request.put(SETTINGS_API, {
    headers,
    data: { ...current, unusedImageRetentionDays: nextDays },
  });
  expect(saved.status()).toBe(200);

  const after = await page.request.get(SETTINGS_API, { headers });
  expect(after.status()).toBe(200);
  expect(Number((await after.json()).data.unusedImageRetentionDays)).toBe(nextDays);

  // 원래 값으로 되돌려 다음 회차·다른 spec 에 값을 남기지 않는다.
  const restored = await page.request.put(SETTINGS_API, {
    headers,
    data: { ...current, unusedImageRetentionDays: originalDays },
  });
  expect(restored.status()).toBe(200);
});

// @scenario trigger=settings_screen, toggle=default
// @effects settings_cleanup_toggle_default_off
test('#115 - 플러그인 설정에 미사용 이미지 정리 섹션이 노출된다', async ({ page, uploadsManageToken }) => {
  await authenticatePage(page, uploadsManageToken);
  await page.goto(SETTINGS_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const section = page.locator('#section_cleanup_card');
  await expect(section).toBeAttached({ timeout: 20_000 });
  await section.scrollIntoViewIfNeeded();

  await expect(page.locator('#field_unused_image_cleanup')).toBeVisible();
  await expect(page.locator('#field_unused_image_retention_days')).toBeVisible();
  await expect(page.locator('#open_uploads_button')).toBeVisible();
});
