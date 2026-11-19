/**
 * E2E: 「기존 회원에게도 입력 요구」를 켠 필수 항목 — 로그인 뒤 추가 정보 화면으로 유도 (#490 계획서 10절 ③, T7-c).
 *
 * 흐름: 그 항목이 비어 있는 기존 회원이 로그인한다 → 추가 정보 입력 화면(`/mypage/required-info`)으로 간다 → 다른 주소를 직접
 * 열어도 다시 그 화면으로 돌아오고 주소의 `redirect` 에 가려던 곳이 실린다 → 칸을 채워 저장하면 가려던 곳으로 간다 → 그 뒤로는
 * 유도되지 않는다.
 *
 * 사이트 전역을 바꾼다: 필수이면서 「기존 회원에게도 입력 요구」를 켠 운영자 추가 항목(키 접두 `e2e490_`) 하나와 시험 회원 하나.
 * 이 항목이 있는 동안에는 그 항목이 빈 **모든** 회원이 추가 정보 화면으로 유도되고 쓰기 요청이 403 이므로, 항목은 회원을 만든
 * 뒤에 만들고(관리자 회원 추가도 필수 항목을 요구한다) afterAll 이 가장 먼저 지운다. 지난 실행의 잔여물은 beforeAll 이 지운다.
 *
 * 축 요약(마커 아님 — 평문): case=required_info_redirect
 */
import type { APIRequestContext } from '@playwright/test';
import { test, expect, issueToken } from '../../fixtures/auth';
import {
  E2E_FIELD_PREFIX,
  createE2eMember,
  createMemberField,
  deleteE2eMember,
  deleteMemberField,
  dismissCookieBanner,
  openApiContext,
  purgeE2eMemberFields,
  purgeE2eMembers,
  uniqueTag,
  type E2eMember,
} from '../../fixtures/member-fields';

test.describe.configure({ mode: 'serial', timeout: 120_000 });

const tag = uniqueTag();
const fieldKey = `${E2E_FIELD_PREFIX}ri_${tag}`;
const fieldName = `E2E 추가 정보 ${tag}`;
const fieldValue = `E2E 채움 ${tag}`;

/** 직접 열어 볼 다른 주소 — 유도 제외 목록(`/mypage/profile` 등)에 없는 코어 화면 */
const OTHER_PATH = '/mypage/notifications';

let api: APIRequestContext;
let adminToken: string;
let member: E2eMember | null = null;
let fieldId = 0;

test.beforeAll(async ({ playwright }, testInfo) => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  api = await openApiContext(playwright, testInfo);
  adminToken = issueToken('core.settings.read', 'core.settings.update', 'core.users.read', 'core.users.create', 'core.users.delete');
  await purgeE2eMemberFields(api, adminToken);
  await purgeE2eMembers(api, adminToken);

  // 회원을 먼저 만든다 — 필수 항목이 생긴 뒤에는 관리자 회원 추가도 그 값을 요구한다.
  member = await createE2eMember(api, adminToken, { tag, role: 'pending', name: `E2E 유도 ${tag}`, nickname: `e2e유도${tag}` });

  const field = await createMemberField(api, adminToken, {
    key: fieldKey,
    type: 'text',
    name: { ko: fieldName, en: `E2E required info ${tag}` },
    required: true,
    require_existing_members: true,
    edit_scope: 'self',
    show_on_signup: false,
  });
  fieldId = field.id;
  expect(field.require_existing_members).toBe(true);
});

test.afterAll(async () => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  const errors: unknown[] = [];
  // 항목을 가장 먼저 지운다 — 남아 있는 동안 사이트의 모든 회원이 유도된다.
  for (const step of [
    () => deleteMemberField(api, adminToken, fieldId),
    () => purgeE2eMemberFields(api, adminToken),
    () => deleteE2eMember(api, adminToken, member?.uuid ?? ''),
  ]) {
    try {
      await step();
    } catch (error) {
      errors.push(error);
    }
  }
  await api?.dispose();
  if (errors.length > 0) {
    throw errors[0];
  }
});

/**
 * @scenario case=required_info_redirect
 * @effects existing_member_guided_to_required_info_after_login, direct_entry_redirects_back_to_required_info, required_info_save_returns_to_original_destination
 */
test('③ 비어 있는 회원은 로그인 뒤 추가 정보 화면으로 가고, 다른 주소로 가도 돌아오며, 저장하면 가려던 곳으로 간다', async ({ page }) => {
  // 로그인 — 응답이 비어 있는 필수 항목을 싣는다.
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await dismissCookieBanner(page);
  await page.fill('input[name="email"]', member!.email);
  await page.fill('input[name="password"]', member!.password);
  const [login] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/auth/login') && r.request().method() === 'POST'),
    page.locator('form button[type="submit"]').first().click(),
  ]);
  expect(login.status()).toBe(200);
  expect((await login.json())?.data?.pending_required_fields ?? []).toContain(fieldKey);

  // 로그인 직후 추가 정보 화면 — 비어 있는 필수 칸만 있다.
  await page.waitForURL((url) => url.pathname === '/mypage/required-info', { timeout: 30_000 });
  await expect(page.locator('#required_info_card')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator(`#field_${fieldKey}`)).toBeVisible({ timeout: 20_000 });
  await expect(page.locator(`#field_${fieldKey} label`)).toContainText(fieldName);

  // 다른 주소를 직접 열어도 다시 추가 정보 화면으로 돌아오고, 가려던 곳이 redirect 에 실린다.
  await page.goto(OTHER_PATH);
  await page.waitForURL(
    (url) => url.pathname === '/mypage/required-info' && url.searchParams.get('redirect') === OTHER_PATH,
    { timeout: 30_000 },
  );
  await expect(page.locator(`#field_${fieldKey}`)).toBeVisible({ timeout: 20_000 });

  // 채워 저장하면 가려던 곳으로 간다.
  await page.locator(`#field_${fieldKey} input`).first().fill(fieldValue);
  const [saved] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/me/required-fields') && r.request().method() === 'PUT'),
    page.locator('#required_info_save').click(),
  ]);
  const savedBody = await saved.json();
  expect(saved.status(), JSON.stringify(savedBody?.errors ?? savedBody?.message ?? '').slice(0, 300)).toBe(200);
  expect(saved.request().postDataJSON()?.custom_fields?.[fieldKey]).toBe(fieldValue);
  expect(savedBody?.data?.pending_required_fields ?? []).toEqual([]);
  await page.waitForURL((url) => url.pathname === OTHER_PATH, { timeout: 30_000 });
});

/**
 * @scenario case=required_info_redirect
 * @effects completed_member_not_guided_again
 */
test('③ 채운 회원은 다시 열어도 유도되지 않는다', async ({ page }) => {
  // 새 문맥이라 로그인부터 다시 한다(API 로 받은 토큰을 심는 대신 같은 로그인 화면을 지난다).
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await dismissCookieBanner(page);
  await page.fill('input[name="email"]', member!.email);
  await page.fill('input[name="password"]', member!.password);
  const [login] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/auth/login') && r.request().method() === 'POST'),
    page.locator('form button[type="submit"]').first().click(),
  ]);
  expect((await login.json())?.data?.pending_required_fields ?? []).not.toContain(fieldKey);
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 30_000 });

  const current = page.waitForResponse(
    (r) => new URL(r.url()).pathname === '/api/auth/user' && r.request().method() === 'GET',
    { timeout: 30_000 },
  );
  await page.goto(OTHER_PATH);
  await current;
  // 유도는 로그인 사용자 응답을 받자마자 일어난다 — 받은 뒤 잠시 지나도 주소가 그대로인지 본다.
  await page.waitForTimeout(2000);
  expect(new URL(page.url()).pathname).toBe(OTHER_PATH);
});
