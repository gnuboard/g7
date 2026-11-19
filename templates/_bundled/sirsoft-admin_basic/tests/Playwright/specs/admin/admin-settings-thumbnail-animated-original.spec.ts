/**
 * sirsoft-admin_basic — 환경설정 업로드 탭 「움직이는 이미지는 원본 사용」 토글 (이슈 #869).
 *
 * 토글(`upload.thumbnail_animated_original`)은 움직이는 GIF·WebP·PNG 에 썸네일을 요청했을 때 원본을 그대로 낼지(켬),
 * 첫 장면으로 썸네일을 만들지(끔)를 정한다. 이 spec 은 화면에서 토글을 바꾸고 저장해 서버 값이 바뀌는지,
 * 새로고침 뒤에도 유지되는지, 설정 수정 권한이 없는 관리자에게는 잠기는지를 잰다.
 *
 * 쓰기 원복: 첫 시나리오가 끝에서 화면으로 원래 값을 다시 저장한다. 중간에 실패해도 afterAll 이 화면이 보낸
 * 저장 본문을 그대로 쓰되 토글 값만 원래 값으로 바꿔 다시 보낸다(본문 모양을 spec 이 지어내지 않는다).
 *
 * 대기: 관리자 화면은 실시간 연결 때문에 네트워크가 조용해지지 않아 `networkidle` 을 쓰지 않는다 — 화면 요소·응답으로 기다린다.
 */
import { test, expect, type Page } from '@playwright/test';
import { issueToken, issueScopedToken, authenticatePage } from '../../../../../../../tests/Playwright/fixtures/auth';

const SETTINGS_API = '/api/admin/settings';
const KEY = 'thumbnail_animated_original';
// 첫 화면은 템플릿 자산·번들을 받느라 개발 서버에서 수십 초 걸린다 — 넉넉히 기다린다
const WAIT = 90_000;

let token = '';
/** 시작할 때의 서버 값 */
let original: boolean | undefined;
/** 화면이 보낸 업로드 탭 저장 본문 (afterAll 원복에 그대로 쓴다) */
let capturedBody: Record<string, any> | null = null;
/** 첫 시나리오가 화면으로 원래 값을 다시 저장했는지 */
let restored = false;

async function serverValue(page: Page): Promise<boolean> {
  const res = await page.request.get(SETTINGS_API, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  expect(res.status()).toBe(200);
  const value = (await res.json()).data?.upload?.[KEY];
  expect(typeof value, `서버 응답의 upload.${KEY}`).toBe('boolean');

  return value;
}

function toggleOf(page: Page) {
  return page.locator('#field_thumbnail_animated_original [role="switch"]');
}

/** 업로드 탭을 열고 토글이 서버 값과 같아질 때까지 기다린다 (폼 시드 도착 전 조작 방지). */
async function openUploadTab(page: Page, expected: boolean): Promise<void> {
  await page.goto('/admin/settings?tab=upload');
  const toggle = toggleOf(page);
  await expect(toggle).toBeVisible({ timeout: WAIT });
  await expect(toggle).toHaveAttribute('aria-checked', String(expected), { timeout: WAIT });
}

/** 토글을 누르고 저장해 200 응답과 그 요청 본문을 돌려준다. */
async function toggleAndSave(page: Page, next: boolean): Promise<Record<string, any>> {
  const toggle = toggleOf(page);
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-checked', String(next));

  const saved = page.waitForResponse(
    (res) => new URL(res.url()).pathname === SETTINGS_API && res.request().method() === 'POST',
    { timeout: WAIT },
  );
  await page.locator('#save_button').click();
  const res = await saved;
  expect(res.status(), (await res.text()).slice(0, 300)).toBe(200);

  return res.request().postDataJSON();
}

test.describe('@sirsoft-admin_basic 업로드 탭 — 움직이는 이미지는 원본 사용', () => {
  test.describe.configure({ mode: 'serial', timeout: 240_000 });

  test.beforeAll(() => {
    token = issueToken('core.settings.read', 'core.settings.update');
  });

  test.afterAll(async ({ playwright, baseURL }) => {
    // 훅은 describe 의 timeout 설정을 받지 않는다
    test.setTimeout(120_000);
    if (restored || original === undefined || capturedBody === null) return;

    const request = await playwright.request.newContext({ baseURL, ignoreHTTPSErrors: true });
    const body = { ...capturedBody, upload: { ...capturedBody.upload, [KEY]: original } };
    const res = await request.post(SETTINGS_API, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      data: body,
    });
    expect(res.status(), `원복 저장 실패: ${(await res.text()).slice(0, 300)}`).toBe(200);
    await request.dispose();
  });

  // @scenario surface=animated_original_toggle
  // @effects animated_original_toggle_saved_and_persisted
  test('토글이 라벨과 함께 보이고, 바꿔 저장하면 서버 값이 바뀌고 새로고침 뒤에도 유지된다 — 끝에 원래 값으로 되돌린다', async ({ page }) => {
    await authenticatePage(page, token);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    original = await serverValue(page);
    const flipped = !original;

    await openUploadTab(page, original);
    const field = page.locator('#field_thumbnail_animated_original');
    await expect(field).toContainText('움직이는 이미지는 원본 사용');
    await expect(toggleOf(page)).not.toHaveAttribute('aria-disabled', 'true');

    const body = await toggleAndSave(page, flipped);
    capturedBody = body;
    expect(body._tab).toBe('upload');
    expect(body.upload?.[KEY], '저장 본문에 토글 값이 실린다').toBe(flipped);
    expect(await serverValue(page)).toBe(flipped);

    await page.reload();
    await expect(toggleOf(page)).toBeVisible({ timeout: WAIT });
    await expect(toggleOf(page), '새로고침 뒤에도 저장한 값').toHaveAttribute('aria-checked', String(flipped), { timeout: WAIT });

    const back = await toggleAndSave(page, original);
    expect(back.upload?.[KEY]).toBe(original);
    expect(await serverValue(page)).toBe(original);
    restored = true;
  });

  // @scenario surface=animated_original_toggle
  // @effects animated_original_toggle_locked_when_read_only
  test('설정 수정 권한이 없는 관리자에게는 토글이 잠긴다', async ({ page }) => {
    await authenticatePage(page, issueScopedToken('core.settings.read'));
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto('/admin/settings?tab=upload');

    const toggle = toggleOf(page);
    await expect(toggle).toBeVisible({ timeout: WAIT });
    await expect(toggle).toHaveAttribute('aria-disabled', 'true', { timeout: WAIT });
    const before = await toggle.getAttribute('aria-checked');
    await toggle.click({ force: true });
    await expect(toggle, '잠긴 토글은 눌러도 바뀌지 않는다').toHaveAttribute('aria-checked', before ?? '');
  });
});
