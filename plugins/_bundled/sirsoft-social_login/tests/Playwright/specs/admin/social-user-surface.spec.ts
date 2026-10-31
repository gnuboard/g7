/**
 * 관리자 회원 표면 종단 검증 — 회원 목록 가입경로(소셜) 필터가 `ext_filters[]` 로 나가 연결된 회원만 남기고 새로고침 뒤 복원되며,
 * 회원 상세 「소셜 로그인」 탭에서 비밀번호 없는 회원의 마지막 연결도 강제로 해제된다. 연결 열람 권한이 없는 관리자에게는 필터 행이 없고
 * 상세 탭은 권한 안내만 보인다.
 *
 * 꺼진 제공자(페이스북)에 연결된 회원을 쓴다 — 필터 선택지가 켜진 제공자가 아니라 연결이 있는 제공자에서 나와야 고를 수 있다.
 */
import { test, expect, type Page } from '@playwright/test';
import { authenticatePage, issueToken, issueScopedToken } from '../../../../../../../tests/Playwright/fixtures/auth';
import { members, runId } from '../../fixtures/mock-provider';

test.describe.configure({ mode: 'serial' });

const id = runId();
const email = `e2e40.admin.${id}@example.test`;
let admin: string;
let uuid: string;

test.beforeAll(() => {
  members('purge');
  members('member', email);
  members('link', email, 'facebook', `e2e-admin-fb-${id}`);
  uuid = members('uuid', email);
  admin = issueToken();
});

test.afterAll(() => {
  members('purge');
});

/**
 * 토큰으로 로그인한 상태로 회원 목록을 열고 첫 목록 응답을 기다립니다.
 *
 * @param page 페이지
 * @param token 토큰
 * @param path 경로
 */
async function gotoUsers(page: Page, token: string, path = '/admin/users'): Promise<void> {
  await authenticatePage(page, token);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  const firstList = page.waitForResponse((r) => r.url().includes('/api/admin/users?') && r.request().method() === 'GET', { timeout: 60_000 });
  await page.goto(path);
  await firstList;
  await page.waitForSelector('#filter_card', { timeout: 30_000 });
}

/**
 * 회원 상세를 열고 「소셜 로그인」 탭을 누릅니다.
 *
 * @param page 페이지
 * @param token 토큰
 */
async function openSocialTab(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  const accounts = page.waitForResponse((r) => r.url().includes(`/admin/users/${uuid}/accounts`) && r.request().method() === 'GET', { timeout: 60_000 });
  await page.goto(`/admin/users/${uuid}`);
  await accounts;
  await page.getByRole('tab', { name: '소셜 로그인' }).click();
}

/**
 * 가입경로에서 페이스북을 골라 검색하면 요청에 확장 조건이 실리고 측정 회원이 남는다. 새로고침 뒤 선택이 되살아난다.
 *
 * @scenario surface=admin_user_list, filter=disabled_linked, actor=accounts_viewer
 * @effects user_list_filter_by_provider_sent, user_list_filter_restored_after_reload
 */
test('signup source filter narrows the list and survives reload', async ({ page }) => {
  await gotoUsers(page, admin);
  await page.click('#detail_search_toggle');
  const select = page.locator('#social_login_provider_filter');
  await expect(select).toBeVisible();
  await expect(select.locator('option[value="facebook"]')).toHaveCount(1);
  await select.selectOption('facebook');

  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/admin/users?') && r.request().method() === 'GET'),
    page.click('#search_button'),
  ]);
  expect(response.status()).toBe(200);
  expect(decodeURIComponent(response.url())).toContain('ext_filters[]=sirsoft-social_login.provider=facebook');
  await expect(page.locator('#users_data_grid__body tbody tr', { hasText: email })).toHaveCount(1, { timeout: 20_000 });

  await page.reload();
  await page.waitForSelector('#filter_card');
  await expect(page.locator('#social_login_provider_filter')).toHaveValue('facebook', { timeout: 15_000 });
});

/**
 * 휴대폰 폭에서는 연결 표 대신 세로 카드가 보이고, [강제 해제]가 가로 스크롤 없이 화면 안에 있다.
 *
 * @scenario surface=admin_user_detail, viewport=mobile, actor=accounts_unlinker
 * @effects mobile_cards_replace_table, card_unlink_in_viewport
 */
test('detail tab shows cards instead of the table on a phone-width screen', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  // 휴대폰 폭의 탭 목록은 드롭다운이고 탭 본문이 한 페이지에 이어 그려진다(스크롤 스파이) — 탭을 누르지 않고 본문으로 스크롤한다
  await authenticatePage(page, admin);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  const accounts = page.waitForResponse((r) => r.url().includes(`/admin/users/${uuid}/accounts`) && r.request().method() === 'GET', { timeout: 60_000 });
  await page.goto(`/admin/users/${uuid}`);
  await accounts;
  await page.locator('#admin_user_social_cards').scrollIntoViewIfNeeded();
  await expect(page.locator('#admin_user_social_cards')).toBeVisible();
  await expect(page.locator('#admin_user_social_table')).toHaveCount(0);
  const button = page.locator('#admin_user_social_card_unlink_facebook');
  await expect(button).toBeVisible();
  const box = await button.boundingBox();
  expect(box).not.toBeNull();
  expect(box!.x + box!.width).toBeLessThanOrEqual(390);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});

/**
 * 상세 탭이 연결 행과 비밀번호 없는 회원 안내를 보이고, 강제 해제를 확인하면 마지막 연결도 끊겨 빈 안내로 바뀐다.
 *
 * @scenario surface=admin_user_detail, member=passwordless_single_link, actor=accounts_unlinker
 * @effects admin_detail_tab_lists_links, admin_force_unlink_last_link
 */
test('detail tab lists the link and force unlink removes the last one', async ({ page }) => {
  await openSocialTab(page, admin);
  await expect(page.locator('#admin_user_social_table tbody tr')).toHaveCount(1);
  await expect(page.locator('#admin_user_social_passwordless')).toBeVisible();

  page.once('dialog', (dialog) => dialog.accept());
  const [removed] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/admin/users/${uuid}/accounts/facebook`) && r.request().method() === 'DELETE'),
    page.locator('#admin_user_social_unlink_facebook').click(),
  ]);
  expect(removed.status()).toBe(200);
  await expect(page.locator('#admin_user_social_empty')).toBeVisible();
  expect(members('links', email)).toBe('0');
});

/**
 * 회원 주소가 잘못된 상세(uuid 꼴이 아닌 `/admin/users/1`, 없는 회원 uuid) — 화면은 404 화면으로 바뀌고, 소셜 연결 목록은
 * uuid 꼴이 아니면 요청하지 않으며 없는 회원의 404 도 오류 토스트로 겹쳐 띄우지 않는다.
 *
 * @scenario surface=admin_user_detail, actor=accounts_unlinker, route_id=non_uuid|missing_uuid
 * @effects admin_detail_bad_route_without_toast
 */
test('a detail address that is not a member uuid shows the not-found screen without a social toast', async ({ page }) => {
  await authenticatePage(page, admin);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  const socialCalls: string[] = [];
  page.on('request', (r) => {
    if (r.url().includes('/api/plugins/sirsoft-social_login/admin/users/')) socialCalls.push(r.url());
  });
  const toast = page.getByRole('alert').filter({ hasText: /could not be found|사용자를 찾을 수 없습니다/ });

  const numeric = page.waitForResponse((r) => r.url().endsWith('/api/admin/users/1') && r.request().method() === 'GET', { timeout: 60_000 });
  await page.goto('/admin/users/1');
  expect((await numeric).status()).toBe(404);
  await page.waitForTimeout(1500);
  expect(socialCalls).toEqual([]);
  await expect(toast).toHaveCount(0);

  const missing = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
  const [missingUser, missingAccounts] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith(`/api/admin/users/${missing}`) && r.request().method() === 'GET', { timeout: 60_000 }),
    page.waitForResponse((r) => r.url().includes(`/admin/users/${missing}/accounts`) && r.request().method() === 'GET', { timeout: 60_000 }),
    page.goto(`/admin/users/${missing}`),
  ]);
  expect(missingUser.status()).toBe(404);
  expect(missingAccounts.status()).toBe(404);
  await page.waitForTimeout(1500);
  await expect(toast).toHaveCount(0);
});

/**
 * 회원 열람 권한만 있고 연결 열람 권한이 없는 관리자 — 목록에 가입경로 행이 없고, 상세 탭은 권한 안내만 보인다.
 *
 * @scenario surface=admin_user_list|admin_user_detail, actor=users_reader_only
 * @effects user_surface_hidden_without_accounts_view
 */
test('admins without account view permission see no filter and a notice', async ({ page }) => {
  members('link', email, 'facebook', `e2e-admin-fb2-${id}`);
  const reader = issueScopedToken('core.users.read');

  await gotoUsers(page, reader);
  await page.click('#detail_search_toggle');
  await expect(page.locator('#detail_filters_section')).toBeVisible();
  await expect(page.locator('#social_login_provider_filter')).toHaveCount(0);

  await openSocialTab(page, reader);
  await expect(page.locator('#admin_user_social_forbidden')).toBeVisible();
  await expect(page.locator('[id^="admin_user_social_unlink_"]')).toHaveCount(0);
});
