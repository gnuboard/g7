/**
 * E2E: 표시 이름을 닉네임으로 바꾸기와 닉네임 변경 주기 안내 (계획서 10절 ④⑥, T5-a, T5-b).
 *
 * ④ 운영자가 환경설정 > 회원 > 표시 이름 구획에서 사이트 기본 영역(`core:site`)의 종류를 닉네임으로 고정하고 저장한다 →
 *    새로고침 없이 가입 항목 표의 닉네임 행에 잠금(「표시 이름 설정 보기」 단추)이 붙고 사용 스위치가 잠긴다 →
 *    회원 화면 헤더의 사용자 이름이 실명이 아니라 닉네임이다. 게시글 작성자 쪽은 게시판 모듈 스펙
 *    `modules/_bundled/sirsoft-board/tests/Playwright/specs/user/board-author-display-name.spec.ts` 가 잰다.
 * ⑥ 운영자가 닉네임 정책 구획에서 변경 주기를 정해 저장한다 → 회원이 내 정보 수정에서 닉네임을 한 번 바꾸면 헤더가 새로고침
 *    없이 새 닉네임이 된다 → 다시 수정 화면을 열면 닉네임 칸이 읽기 전용이고 다시 바꿀 수 있는 날짜 안내가 칸 아래에 있다.
 *
 * 사이트 전역을 바꾼다: 회원 탭 설정(표시 이름 영역, 닉네임 변경 주기)과 시험 회원 하나. beforeAll 이 회원 탭 전체 값을
 * 떠 두고 사이트 기본 영역을 「이름 고정」으로 맞춘 뒤 시작하고, afterAll 이 떠 둔 값을 그대로 다시 저장한다.
 *
 * 축 요약(마커 아님 — 평문): case=display_name_nickname, nickname_interval_notice
 */
import type { APIRequestContext, Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import {
  SETTINGS_PATH,
  bearer,
  createE2eMember,
  deleteE2eMember,
  dismissCookieBanner,
  gotoMemberSettingsTab,
  listMemberFields,
  loginMemberApi,
  openApiContext,
  openProfileEdit,
  patchSettingsTab,
  purgeE2eMembers,
  uniqueTag,
  withDisplayNameAreas,
  writeSettingsTab,
  type E2eMember,
} from '../../fixtures/member-fields';

test.describe.configure({ mode: 'serial', timeout: 120_000 });

const tag = uniqueTag();
const realName = `E2E실명${tag}`;
const nickname = `e2e닉${tag}`;
const changedNickname = `e2e새닉${tag}`;

/** 사이트 기본 영역 */
const SITE_AREA = 'core:site';

/** 변경 주기(일) */
const INTERVAL_DAYS = 30;

let api: APIRequestContext;
let adminToken: string;
let memberSnapshot: Record<string, unknown> | null = null;
let member: E2eMember | null = null;
let memberToken = '';

test.beforeAll(async ({ playwright }, testInfo) => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  api = await openApiContext(playwright, testInfo);
  adminToken = issueToken('core.settings.read', 'core.settings.update', 'core.users.read', 'core.users.create', 'core.users.delete');
  await purgeE2eMembers(api, adminToken);

  // 출발점을 정한다 — 사이트 기본은 「이름 고정」, 변경 주기는 없음. 바꾸기 전 탭 전체 값을 원복용으로 떠 둔다.
  const current = await readMember();
  memberSnapshot = await patchSettingsTab(api, adminToken, 'member', {
    display_name_areas: withDisplayNameAreas(current, { [SITE_AREA]: { mode: 'fixed', kind: 'name' } }),
    nickname_change_interval_days: 0,
  });

  member = await createE2eMember(api, adminToken, { tag, role: 'display', name: realName, nickname });
  memberToken = (await loginMemberApi(api, member.email, member.password)).token;
});

test.afterAll(async () => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  const errors: unknown[] = [];
  for (const step of [
    async () => {
      if (memberSnapshot) await writeSettingsTab(api, adminToken, 'member', memberSnapshot);
    },
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
 * 지금 회원 탭 값을 읽는다.
 *
 * @returns 회원 탭 값
 */
async function readMember(): Promise<Record<string, unknown>> {
  const res = await api.get(`${SETTINGS_PATH}?tab=member`, { headers: bearer(adminToken) });
  expect(res.status()).toBe(200);

  return ((await res.json())?.data?.member ?? {}) as Record<string, unknown>;
}

/**
 * 회원 탭 「저장」을 눌러 저장 응답을 받는다.
 *
 * @param page 관리자 페이지
 * @returns 저장 응답
 */
async function saveSettings(page: Page) {
  const [response] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === SETTINGS_PATH && r.request().method() === 'POST'),
    page.locator('#save_button').click(),
  ]);

  return response;
}

/**
 * 회원 화면 헤더의 사용자 이름 노드.
 *
 * @param page 회원 페이지
 * @returns 로케이터
 */
function headerUserName(page: Page) {
  return page.getByTestId('header-user-name').first();
}

/**
 * @scenario case=display_name_nickname
 * @effects header_shows_real_name_before_display_name_change
 */
test('④ 출발점 — 사이트 기본이 「이름」이면 헤더는 실명이다', async ({ page }) => {
  await authenticatePage(page, memberToken);
  await page.goto('/');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await dismissCookieBanner(page);

  await expect(headerUserName(page)).toHaveAttribute('title', realName, { timeout: 30_000 });
});

/**
 * @scenario case=display_name_nickname
 * @effects display_name_saved_from_member_tab, nickname_field_locked_in_member_table_without_reload
 */
test('④ 운영자가 사이트 기본을 닉네임으로 고정해 저장하면 표의 닉네임 행이 새로고침 없이 잠긴다', async ({ page }) => {
  const options = await api.get('/api/admin/member-fields/display-name-options', { headers: bearer(adminToken) });
  expect(options.status()).toBe(200);
  const kinds = ((await options.json())?.data?.kinds ?? []) as Array<{ key: string; label: string }>;
  const nicknameLabel = kinds.find((k) => k.key === 'nickname')?.label;
  expect(nicknameLabel, '닉네임 종류가 등록돼 있다').toBeTruthy();

  const nicknameRow = (await listMemberFields(api, adminToken)).fields.find((f) => f.key === 'nickname');
  expect(nicknameRow, '닉네임 항목이 있다').toBeTruthy();
  expect(nicknameRow?.enabled, '출발점에서 닉네임 항목은 사용 중이다').toBe(true);

  await authenticatePage(page, adminToken);
  await gotoMemberSettingsTab(page);

  // 출발점에서는 닉네임 행에 표시 이름 잠금이 없다.
  await expect(page.getByTestId('member-field-table-lock-link-nickname')).toHaveCount(0);

  // 사이트 기본 영역의 「종류」 목록에서 닉네임을 고른다(관리자 목록형 선택칸은 단추 + 목록이다).
  const kindSelect = page.locator(`[data-testid="member-display-name-area-kind-${SITE_AREA}"]`);
  await kindSelect.scrollIntoViewIfNeeded();
  await kindSelect.locator('button[aria-haspopup="listbox"]').click();
  // 선택지 목록은 문서 끝(portal)에 그려진다 — 선택 칸 안이 아니라 열린 목록에서 고른다
  await page.getByRole('listbox').getByRole('option', { name: nicknameLabel!, exact: true }).click();

  const response = await saveSettings(page);
  expect(response.status(), (await response.text()).slice(0, 400)).toBe(200);
  const sent = response.request().postDataJSON();
  expect(sent?._tab).toBe('member');
  expect(sent?.member?.display_name_areas?.[SITE_AREA]).toEqual(expect.objectContaining({ mode: 'fixed', kind: 'nickname' }));

  // 새로고침 없이 닉네임 행에 잠금 단추가 붙고 사용 스위치가 잠긴다.
  await expect(page.getByTestId('member-field-table-lock-link-nickname')).toBeVisible({ timeout: 20_000 });
  const label = String(nicknameRow?.label ?? '닉네임');
  await expect(page.getByRole('switch', { name: `${label} 사용`, exact: true }).first()).toHaveAttribute('aria-disabled', 'true');

  const locked = (await listMemberFields(api, adminToken)).fields.find((f) => f.key === 'nickname');
  expect(Object.values(locked?.lock_reasons ?? {})).toContain('nickname_display');
});

/**
 * @scenario case=display_name_nickname
 * @effects header_shows_nickname_after_display_name_change
 */
test('④ 회원 화면 헤더가 실명 대신 닉네임을 보인다', async ({ page }) => {
  await authenticatePage(page, memberToken);
  await page.goto('/');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await dismissCookieBanner(page);

  await expect(headerUserName(page)).toHaveAttribute('title', nickname, { timeout: 30_000 });
  await expect(headerUserName(page)).not.toContainText(realName);
});

/**
 * 회원 기본 종류 아래의 「이 값을 쓰는 영역」 안내. 사이트 기본과 관리자 화면을 「이름 고정」으로 맞춘 출발점에서는 「회원이 고른 값」
 * 영역이 (게시판마다 같은 인스턴스 설정 말고는) 없다 → 사이트 기본을 「회원이 고른 값」으로 바꾸면 저장 전에 안내 줄에 「사이트 기본」이
 * 들어온다 → 저장하고 다시 열어도 그대로다. 출발점의 탭 값은 afterAll 이 떠 둔 값으로 되돌린다.
 *
 * @scenario case=display_name_member_default_usage
 * @effects member_default_kind_usage_follows_unsaved_mode, member_default_kind_usage_persists_after_save
 */
test('회원 기본 종류 아래에 이 값을 쓰는 영역을 보이고, 사이트 기본을 「회원이 고른 값」으로 바꾸면 바로 반영된다', async ({ page }) => {
  const current = await readMember();
  await patchSettingsTab(api, adminToken, 'member', {
    display_name_areas: withDisplayNameAreas(current, {
      [SITE_AREA]: { mode: 'fixed', kind: 'name' },
      'core:admin': { mode: 'fixed', kind: 'name' },
    }),
  });

  const options = await api.get('/api/admin/member-fields/display-name-options', { headers: bearer(adminToken) });
  expect(options.status()).toBe(200);
  const areas = ((await options.json())?.data?.areas ?? []) as Array<{ key: string; label: string; mode: string; member_instances?: string[] }>;
  const siteLabel = areas.find((a) => a.key === SITE_AREA)?.label ?? '';
  expect(siteLabel, '사이트 기본 영역 이름').toBeTruthy();
  const memberAreasBefore = areas.filter((a) => a.mode === 'member').length + areas.reduce((n, a) => n + (a.member_instances ?? []).length, 0);

  await authenticatePage(page, adminToken);
  await gotoMemberSettingsTab(page);

  const usage = page.getByTestId('member-display-name-default-kind-usage');
  const unused = page.getByTestId('member-display-name-default-kind-unused');
  if (memberAreasBefore === 0) {
    // 출발점에 「회원이 고른 값」인 곳이 없으면 경고 줄만 보인다
    await expect(unused).toBeVisible({ timeout: 30_000 });
    await expect(usage).toHaveCount(0);
  } else {
    await expect(usage).toBeVisible({ timeout: 30_000 });
    await expect(usage).not.toContainText(siteLabel);
  }

  // 사이트 기본의 방식을 「회원이 고른 값」으로 바꾼다(저장 전)
  const modeSelect = page.locator(`[data-testid="member-display-name-area-mode-${SITE_AREA}"]`);
  await modeSelect.scrollIntoViewIfNeeded();
  await modeSelect.locator('button[aria-haspopup="listbox"]').click();
  await page.getByRole('listbox').getByRole('option', { name: '회원이 고른 값', exact: true }).click();

  await expect(usage).toBeVisible();
  await expect(usage).toContainText(siteLabel);
  await expect(unused).toHaveCount(0);

  const response = await saveSettings(page);
  expect(response.status(), (await response.text()).slice(0, 400)).toBe(200);
  expect(response.request().postDataJSON()?.member?.display_name_areas?.[SITE_AREA]).toEqual(expect.objectContaining({ mode: 'member' }));

  // 다시 열어도 그대로다
  await gotoMemberSettingsTab(page);
  await expect(page.getByTestId('member-display-name-default-kind-usage')).toContainText(siteLabel, { timeout: 30_000 });
});

/**
 * @scenario case=nickname_interval_notice
 * @effects nickname_interval_saved_from_member_tab
 */
test('⑥ 운영자가 닉네임 변경 주기를 정해 저장한다', async ({ page }) => {
  await authenticatePage(page, adminToken);
  await gotoMemberSettingsTab(page);

  const interval = page.locator('input[name="member.nickname_change_interval_days"]');
  await interval.scrollIntoViewIfNeeded();
  await interval.fill(String(INTERVAL_DAYS));

  const response = await saveSettings(page);
  expect(response.status(), (await response.text()).slice(0, 400)).toBe(200);
  expect(Number(response.request().postDataJSON()?.member?.nickname_change_interval_days)).toBe(INTERVAL_DAYS);
  expect(Number((await readMember()).nickname_change_interval_days)).toBe(INTERVAL_DAYS);
});

/**
 * @scenario case=nickname_interval_notice
 * @effects nickname_change_updates_header_without_reload, nickname_locked_with_next_change_date_notice
 */
test('⑥ 회원이 닉네임을 한 번 바꾸면 헤더가 바로 바뀌고, 다시 열면 칸이 잠기고 다음 변경 가능일 안내가 있다', async ({ page }) => {
  await authenticatePage(page, memberToken);
  await openProfileEdit(page, member!.password);

  const nicknameInput = page.locator('#profileEditForm input[name="nickname"]');
  await expect(nicknameInput).toBeEditable({ timeout: 20_000 });
  await expect(page.locator('#mf_locked_nickname_interval_nickname')).toHaveCount(0);
  await nicknameInput.fill(changedNickname);

  const [saved] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/me' && r.request().method() === 'PUT'),
    page.locator('#profileEditForm button[type="submit"]').last().click(),
  ]);
  expect(saved.status(), (await saved.text()).slice(0, 400)).toBe(200);
  // 사이트 기본이 닉네임이므로 헤더는 새로고침 없이 새 닉네임이 된다.
  await expect(headerUserName(page)).toHaveAttribute('title', changedNickname, { timeout: 20_000 });

  // 다시 열면 닉네임 칸이 읽기 전용이고, 다시 바꿀 수 있는 날짜가 칸 아래에 있다.
  const fields = await api.get('/api/me/fields', { headers: bearer(memberToken) });
  const contract = ((await fields.json())?.data?.fields ?? []).find((f: { key: string }) => f.key === 'nickname');
  expect(contract?.readonly).toBe(true);
  expect(contract?.locked_reason).toBe('nickname_interval');
  const until = String(contract?.locked_until ?? '');
  expect(until).toMatch(/^\d{4}-\d{2}-\d{2}/);

  await openProfileEdit(page, member!.password);
  const notice = page.locator('#mf_locked_nickname_interval_nickname');
  await expect(notice).toBeVisible({ timeout: 20_000 });
  await expect(notice).toContainText(until.slice(0, 10));
  await expect(page.locator('#profileEditForm input[name="nickname"]')).toHaveAttribute('readonly', /.*/);
});
