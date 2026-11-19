/**
 * E2E: 관리자 회원 목록의 운영자 추가 항목 — 동적 열, 검색 대상, 「필수 항목 미입력」 조건 (#490 계획서 10절 ⑤).
 *
 * 운영자가 「회원 목록 열」과 「회원 검색」을 켠 추가 항목은 목록 응답 `member_fields` 로 와서 표의 열과 검색 대상 선택지가
 * 된다. 그 항목을 필수로 두고 「기존 회원에게도 입력 요구」를 켜면 값이 빈 회원이 「필수 항목 미입력」 조건에 걸리고
 * 「미입력」 열에 개수 배지가 붙는다. 화면에서 고른 조건 → 주소 → 서버 요청 → 표 결과를 잇는다.
 *
 * 사이트 전역을 바꾼다: 운영자 추가 항목 하나(키 접두 `e2e490_`)와 시험 회원 둘(값 있는 A, 값 없는 B). 항목이 필수이고
 * 기존 회원에게도 요구하는 동안 그 값이 빈 모든 회원이 추가 정보 화면으로 유도되므로 afterAll 이 항목을 가장 먼저 지운다.
 * 지난 실행의 잔여물은 beforeAll 이 지운다.
 *
 * 축 요약(마커 아님 — 평문): case=admin_list_member_fields
 */
import type { APIRequestContext, Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import {
  E2E_FIELD_PREFIX,
  createE2eMember,
  createMemberField,
  deleteE2eMember,
  deleteMemberField,
  openApiContext,
  purgeE2eMemberFields,
  purgeE2eMembers,
  uniqueTag,
  updateMemberField,
  type E2eMember,
} from '../../fixtures/member-fields';

test.describe.configure({ mode: 'serial', timeout: 120_000 });

const tag = uniqueTag();
const fieldKey = `${E2E_FIELD_PREFIX}ls_${tag}`;
const fieldLabel = `E2E 목록 항목 ${tag}`;
const valueA = `e2e값${tag}`;

let api: APIRequestContext;
let adminToken: string;
let fieldId = 0;
let memberA: E2eMember | null = null;
let memberB: E2eMember | null = null;

test.beforeAll(async ({ playwright }, testInfo) => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  api = await openApiContext(playwright, testInfo);
  adminToken = issueToken('core.settings.read', 'core.settings.update', 'core.users.read', 'core.users.create', 'core.users.delete');
  await purgeE2eMemberFields(api, adminToken);
  await purgeE2eMembers(api, adminToken);

  // 필수로 바꾸기 전에 만든다 — 회원 추가가 필수 값을 요구하지 않게.
  const field = await createMemberField(api, adminToken, {
    key: fieldKey,
    type: 'text',
    name: { ko: fieldLabel, en: `E2E list field ${tag}` },
    show_on_signup: false,
    admin_list_column: true,
    admin_searchable: true,
  });
  fieldId = field.id;
  memberA = await createE2eMember(api, adminToken, { tag, role: 'lista', name: `E2E 목록 A ${tag}`, customFields: { [fieldKey]: valueA } });
  memberB = await createE2eMember(api, adminToken, { tag, role: 'listb', name: `E2E 목록 B ${tag}` });

  const required = await updateMemberField(api, adminToken, fieldId, { required: true, require_existing_members: true });
  expect(required.required).toBe(true);
});

test.afterAll(async () => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  const errors: unknown[] = [];
  for (const step of [
    () => deleteMemberField(api, adminToken, fieldId),
    () => purgeE2eMemberFields(api, adminToken),
    () => deleteE2eMember(api, adminToken, memberA?.uuid ?? ''),
    () => deleteE2eMember(api, adminToken, memberB?.uuid ?? ''),
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
 * 회원 목록에 들어가 첫 목록 응답과 필터 카드를 기다린다.
 *
 * @param page 관리자 페이지
 * @param path 이동 경로
 * @returns 첫 목록 응답 본문
 */
async function gotoUsers(page: Page, path = '/admin/users'): Promise<any> {
  const first = page.waitForResponse(
    (r) => r.url().includes('/api/admin/users?') && r.request().method() === 'GET',
    { timeout: 60_000 },
  );
  await page.goto(path);
  const body = await (await first).json();
  await page.waitForSelector('#filter_card', { timeout: 30_000 });
  await page.waitForTimeout(800);

  return body;
}

/**
 * 조작 뒤 나가는 회원 목록 요청(디코드한 주소)과 응답 본문을 받는다.
 *
 * @param page 관리자 페이지
 * @param act 요청을 일으키는 조작
 * @returns 요청 주소와 응답 본문
 */
async function usersRequest(page: Page, act: () => Promise<void>): Promise<{ url: string; body: any }> {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/admin/users?') && r.request().method() === 'GET'),
    act(),
  ]);
  expect(response.status()).toBe(200);

  return { url: decodeURIComponent(response.url()), body: await response.json() };
}

/** 표의 본문 행 */
function rows(page: Page) {
  return page.locator('#users_data_grid__body tbody tr');
}

/**
 * @scenario case=admin_list_member_fields
 * @effects admin_list_shows_member_field_column_and_value
 */
test('⑤ 「회원 목록 열」을 켠 항목이 열로 나오고 회원의 값이 그 칸에 보인다', async ({ page }) => {
  await authenticatePage(page, adminToken);
  const first = await gotoUsers(page);
  const columns = (first?.data?.member_fields?.columns ?? []) as Array<{ field: string; header: string }>;
  expect(columns.map((c) => c.field)).toContain(`custom_fields.${fieldKey}`);

  // 처음 보는 동적 열은 열 선택기가 스스로 켠다(새 문맥이라 저장된 열 선택이 없다).
  await expect(page.locator('#users_data_grid th', { hasText: fieldLabel }).first()).toBeVisible({ timeout: 20_000 });

  // 꼬리표로 두 회원만 남기고 A 의 행에서 값을 본다.
  await page.locator('#search_input').fill(tag);
  await usersRequest(page, () => page.locator('#search_input').press('Enter'));
  await expect(rows(page)).toHaveCount(2, { timeout: 20_000 });
  await expect(rows(page).filter({ hasText: memberA!.email })).toContainText(valueA);
  await expect(rows(page).filter({ hasText: memberB!.email })).not.toContainText(valueA);
});

/**
 * @scenario case=admin_list_member_fields
 * @effects admin_list_searches_by_member_field
 */
test('⑤ 검색 대상에서 그 항목을 고르고 값으로 찾으면 그 회원만 남는다', async ({ page }) => {
  await authenticatePage(page, adminToken);
  const first = await gotoUsers(page);
  const searchFields = (first?.data?.member_fields?.search_fields ?? []) as Array<{ value: string; label: string }>;
  const target = searchFields.find((f) => f.value === `custom:${fieldKey}`);
  expect(target, '검색 대상 선택지에 항목이 실린다').toBeTruthy();

  // 검색 대상 선택칸(관리자 목록형 선택칸 — 단추 + 목록)에서 항목을 고른다.
  const select = page.locator('[id^="search_field_select"]').filter({ visible: true }).first();
  await select.locator('button[aria-haspopup="listbox"]').click();
  // 선택지 목록은 문서 끝(portal)에 그려진다 — 선택 칸 안이 아니라 열린 목록에서 고른다
  await page.getByRole('listbox').getByRole('option', { name: target!.label, exact: true }).click();

  await page.locator('#search_input').fill(valueA);
  const req = await usersRequest(page, () => page.locator('#search_input').press('Enter'));
  expect(req.url).toContain(`filters[0][field]=custom:${fieldKey}`);
  expect(req.url).toContain(`filters[0][value]=${valueA}`);
  expect((req.body?.data?.data ?? []).map((row: { email: string }) => row.email)).toEqual([memberA!.email]);
  await expect(rows(page)).toHaveCount(1, { timeout: 20_000 });
  await expect(rows(page).first()).toContainText(memberA!.email);
});

/**
 * @scenario case=admin_list_member_fields
 * @effects admin_list_required_missing_filter_narrows_with_pending_badge
 */
test('⑤ 「미입력 회원만」을 켜면 값이 빈 회원만 남고 「미입력」 배지가 붙는다', async ({ page }) => {
  await authenticatePage(page, adminToken);
  await gotoUsers(page);

  await page.locator('#search_input').fill(tag);
  await page.click('#detail_search_toggle');
  const missing = page.locator('input[name="requiredMissing"]').first();
  await expect(missing).toBeVisible({ timeout: 10_000 });
  // 제어형 체크 칸 — 누른 직후가 아니라 켜질 때까지 기다린다
  await missing.click();
  await expect(missing).toBeChecked({ timeout: 5_000 });

  const req = await usersRequest(page, () => page.click('#search_button'));
  expect(req.url).toContain('required_missing=1');
  const emails = (req.body?.data?.data ?? []).map((row: { email: string }) => row.email);
  expect(emails).toContain(memberB!.email);
  expect(emails).not.toContain(memberA!.email);

  const rowB = rows(page).filter({ hasText: memberB!.email });
  await expect(rowB).toHaveCount(1, { timeout: 20_000 });
  // 「미입력」 열은 기본 꺼짐이다 — 툴바 열 선택기에서 켠 뒤 배지를 잰다(열 선택은 이 브라우저 문맥에만 남는다)
  const pendingHeader = page.locator('#users_data_grid th', { hasText: '미입력' });
  if ((await pendingHeader.count()) === 0) {
    await page.locator('#admin_user_list_columns button[aria-haspopup="true"]').first().click();
    const toggle = page.locator('#admin_user_list_columns').getByRole('checkbox', { name: '미입력', exact: true });
    await toggle.click();
    await expect(toggle).toBeChecked({ timeout: 5_000 });
    await page.keyboard.press('Escape');
  }
  await expect(rowB.locator('.pending-required-badge')).toBeVisible({ timeout: 10_000 });
  await expect(rowB.locator('.pending-required-badge')).toHaveAttribute('title', new RegExp(fieldLabel));
  await expect(rows(page).filter({ hasText: memberA!.email })).toHaveCount(0);

  // 주소에 조건이 실려 새로고침 뒤에도 남는다.
  expect(decodeURIComponent(page.url())).toContain('required_missing=1');
  await page.reload();
  await page.waitForSelector('#filter_card', { timeout: 30_000 });
  await expect(page.locator('input[name="requiredMissing"]').first()).toBeChecked({ timeout: 15_000 });
});
