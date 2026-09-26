/**
 * E2E: 게시판 환경설정 > 「개인정보 보유기간」 탭 (이슈 #636 S5)
 *
 * 검증:
 *  1. 카드가 **꺼진 채** 번역문과 함께 마운트되고, 꺼져 있으면 기간·방식 칸이 없다
 *  2. 켜면 두 기간 칸과 방식이 나타나고, 삭제 경고는 **삭제를 골랐을 때만** 보인다
 *  3. 저장하면 요청 본문에 `privacy_retention` 이 실리고, 대상 건수 뱃지가 미리보기 API 가 돌려준
 *     **바로 그 숫자**를 그린다(저장된 설정 기준)
 *
 * **사이트 상태 안전**: 설정 API 로 시작 값을 떠 두고 매 테스트 뒤 되돌린다. 보유기간 커맨드는
 * 실행하지 않으므로 어떤 글도 처리되지 않는다. 이미 보유기간 기능을 켜 둔 사이트는 건드리지 않고
 * 건너뛴다. 설정을 바꾸는 spec 이라 직렬로 돈다(#42 — 확장 config 는 로컬 병렬).
 *
 * @scenario axis=guest, toggle=on, guest_mode=delete
 *
 * @effects board_settings_retention_section_binds_saved_keys, board_settings_delete_warning_is_mode_conditional, board_retention_preview_api_counts_while_off
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SETTINGS_URL = '/admin/boards/settings?tab=privacy_retention';
const SECTION = '#privacy_retention_section';
const TOGGLE_FIELD = '#privacy_retention_enabled_field';
const AXES = '#privacy_retention_axes';
const IP_DAYS = 'input[name="privacy_retention.ip_retention_days"]';
const GUEST_DAYS = 'input[name="privacy_retention.guest_retention_days"]';
const DELETE_WARNING = '#privacy_guest_delete_warning';

/** 코어 루트 — 시드 커맨드(artisan)는 코어에 있다. */
const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../../../../');

/**
 * 실측 시드 커맨드를 실행한다(3중 가드 — CLI · G7_PLAYWRIGHT_BYPASS · 시드 전용 표지).
 *
 * @param command artisan 커맨드와 인자
 * @returns 표준 출력
 */
function artisanSeed(command: string): string {
  return execSync(`php artisan ${command}`, {
    cwd: CORE_ROOT,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
}

const API = '/api/modules/sirsoft-board/admin/settings';

type Retention = Record<string, unknown>;

/** 시작 시점 설정 — 매 테스트 뒤 되돌린다. */
let snapshot: Retention | null = null;

test.describe.configure({ mode: 'serial' });

/**
 * 게시판 설정 API 를 화면과 같은 인증으로 호출한다.
 *
 * @param page 페이지
 * @param token 관리자 토큰
 * @param path API 경로
 * @param body PUT 본문 (없으면 GET)
 * @returns 상태 코드와 본문
 */
async function api(page: Page, token: string, path: string, body?: unknown): Promise<{ status: number; body: any }> {
  return page.evaluate(
    async ([bearer, url, payload]: [string, string, unknown]) => {
      const response = await fetch(url, {
        method: payload ? 'PUT' : 'GET',
        headers: {
          Authorization: `Bearer ${bearer}`,
          Accept: 'application/json',
          ...(payload ? { 'Content-Type': 'application/json' } : {}),
        },
        body: payload ? JSON.stringify(payload) : undefined,
      });

      return { status: response.status, body: await response.json().catch(() => null) };
    },
    [token, path, body] as [string, string, unknown],
  );
}

/**
 * 보유기간 설정을 저장한다.
 *
 * @param page 페이지
 * @param token 관리자 토큰
 * @param retention 저장할 값
 * @returns 상태 코드와 본문
 */
async function saveRetention(page: Page, token: string, retention: Retention) {
  return api(page, token, API, { _tab: 'privacy_retention', privacy_retention: retention });
}

/**
 * 하단 고정 저장 막대에 가리지 않도록 요소를 화면 가운데로 올린 뒤 누른다.
 *
 * @param locator 누를 요소
 * @returns 없음
 */
async function clickCentered(locator: import('@playwright/test').Locator): Promise<void> {
  await locator.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  await locator.click();
}

test.beforeEach(async ({ page, settingsToken }) => {
  await authenticatePage(page, settingsToken);
  await page.goto(SETTINGS_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(SECTION)).toBeAttached({ timeout: 20_000 });
  // 설정·미리보기 데이터소스가 폼을 채우기 전에 누르면, 뒤늦게 도착한 응답이 폼을 저장값으로
  // 되덮어 방금 켠 토글이 꺼진다 — 네트워크가 잦아든 뒤에 조작한다.
  await page.waitForLoadState('networkidle', { timeout: 30_000 });

  if (snapshot === null) {
    const { body } = await api(page, settingsToken, API);
    snapshot = (body?.data?.privacy_retention ?? {}) as Retention;
  }
});

test.afterEach(async ({ page, settingsToken }) => {
  if (snapshot !== null) {
    await saveRetention(page, settingsToken, snapshot).catch(() => undefined);
  }
});

// @scenario axis=ip, toggle=off, guest_mode=anonymize
// @effects board_settings_retention_section_binds_saved_keys
test('카드는 꺼진 채 번역문과 함께 열리고, 꺼져 있으면 기간·방식 칸이 없다', async ({ page }) => {
  test.skip(Boolean(snapshot?.enabled), '이 사이트는 이미 보유기간 기능을 쓰고 있어 설정을 건드리지 않는다');

  const text = (await page.locator(SECTION).innerText()).trim();
  expect(text).not.toContain('$t:');
  expect(text).not.toContain('privacy_retention.');

  const toggle = page.locator(TOGGLE_FIELD).locator('input[type="checkbox"]').first();
  expect(await toggle.isChecked()).toBe(false);
  await expect(page.locator(AXES)).toHaveCount(0);
});

// @scenario axis=ip, toggle=off, guest_mode=anonymize
// @effects board_settings_retention_section_binds_saved_keys
test('카드는 자기 탭에만 있고, 두 탭의 켜기 스위치가 이름·설명 줄의 오른쪽 끝에 놓인다', async ({ page }) => {
  // 2026-09-26 결정 — 코어·쇼핑몰·gdpr 처럼 개인정보 조치를 자기 탭에 두고, 스위치는 설명 아래가 아니라 오른쪽 끝.
  const placement = async (field: string) => page.locator(field).evaluate((row) => {
    const sw = row.querySelector('.toggle-switch-wrapper') as HTMLElement;
    const label = row.querySelector('label') as HTMLElement;
    const r = row.getBoundingClientRect();
    const s = sw.getBoundingClientRect();
    const l = label.getBoundingClientRect();
    return { rightGap: Math.round(r.right - s.right), switchLeftOfLabelEnd: s.left < l.right, belowLabel: s.top >= l.bottom };
  });

  const privacy = await placement(TOGGLE_FIELD);
  test.info().annotations.push({ type: 'placement:privacy_retention', description: JSON.stringify(privacy) });
  await test.info().attach('privacy-retention-tab', { body: await page.locator(SECTION).screenshot(), contentType: 'image/png' });
  expect(privacy.rightGap).toBeLessThanOrEqual(2);
  expect(privacy.belowLabel).toBe(false);
  expect(privacy.switchLeftOfLabelEnd).toBe(false);

  // 탭 머리를 눌러 기본 설정으로 옮기면 카드가 사라지고, 그 탭의 첨부 정리 스위치도 같은 줄 양식이다.
  await clickCentered(page.locator('#tab_navigation').getByText(/^기본 설정$|^General$/).first());
  await expect(page).toHaveURL(/tab=general/);
  await expect(page.locator('#attachment_purge_enabled_field')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator(SECTION)).toHaveCount(0);
  const attachment = await placement('#attachment_purge_enabled_field');
  test.info().annotations.push({ type: 'placement:general', description: JSON.stringify(attachment) });
  await test.info().attach('general-tab-attachment-cleanup', { body: await page.locator('#attachment_cleanup_section').screenshot(), contentType: 'image/png' });
  expect(attachment.rightGap).toBeLessThanOrEqual(2);
  expect(attachment.belowLabel).toBe(false);

  await clickCentered(page.locator('#tab_navigation').getByText(/^개인정보 보유기간$|^Privacy Retention$/).first());
  await expect(page).toHaveURL(/tab=privacy_retention/);
  await expect(page.locator(SECTION)).toBeVisible({ timeout: 20_000 });
});

// @scenario axis=guest, toggle=on, guest_mode=delete
// @effects board_settings_delete_warning_is_mode_conditional
test('켜면 두 기간 칸과 방식이 나타나고, 삭제 경고는 삭제를 골랐을 때만 보인다', async ({ page }) => {
  test.skip(Boolean(snapshot?.enabled), '이 사이트는 이미 보유기간 기능을 쓰고 있어 설정을 건드리지 않는다');

  await clickCentered(page.locator(TOGGLE_FIELD).locator('.toggle-switch-wrapper').first());
  await expect(page.locator(IP_DAYS)).toBeVisible({ timeout: 10_000 });
  await expect(page.locator(GUEST_DAYS)).toBeVisible();
  await expect(page.locator(DELETE_WARNING)).toHaveCount(0);

  await clickCentered(page.locator(AXES).getByText(/^삭제$|^Delete$/).first());
  await expect(page.locator(DELETE_WARNING)).toBeVisible();

  await clickCentered(page.locator(AXES).getByText(/익명화|Anonymize/).first());
  await expect(page.locator(DELETE_WARNING)).toHaveCount(0);
});

// @scenario axis=guest, toggle=on, guest_mode=anonymize
// @effects board_retention_preview_api_counts_while_off
test('저장하면 본문에 privacy_retention 이 실리고 뱃지가 미리보기 API 의 숫자를 그린다', async ({ page, settingsToken }) => {
  test.skip(Boolean(snapshot?.enabled), '이 사이트는 이미 보유기간 기능을 쓰고 있어 설정을 건드리지 않는다');

  await clickCentered(page.locator(TOGGLE_FIELD).locator('.toggle-switch-wrapper').first());
  await page.locator(GUEST_DAYS).fill('30');

  const request = page.waitForRequest((req) => req.url().endsWith(API) && req.method() === 'PUT', { timeout: 20_000 });
  const response = page.waitForResponse((res) => res.url().endsWith(API) && res.request().method() === 'PUT', { timeout: 20_000 });
  await page.locator('#footer_save_button').click();

  const payload = (await request).postDataJSON() as Record<string, any>;
  expect((await response).status()).toBe(200);
  expect(payload).toHaveProperty('privacy_retention');
  expect(payload._tab).toBe('privacy_retention');
  expect(Boolean(payload.privacy_retention.enabled)).toBe(true);

  // 화면이 읽는 것과 같은 자료를 API 에서 받아 둔다 — 켰으니 두 축 모두 숫자다.
  const preview = await api(page, settingsToken, `${API}/privacy-retention-preview`);
  expect(typeof preview.body?.data?.ip).toBe('number');
  expect(typeof preview.body?.data?.guest).toBe('number');

  await page.reload();
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // 두 축의 건수는 토글 아래 줄에 있다 — 기간 칸 묶음(켰을 때만 보임) 안에 두지 않는다(#636 §1-E).
  const badges = page.locator('#privacy_retention_targets .badge');
  await expect(badges).toHaveCount(2, { timeout: 20_000 });
  await expect(badges.nth(0)).toContainText(String(preview.body.data.ip));
  await expect(badges.nth(1)).toContainText(String(preview.body.data.guest));
});

// @scenario axis=ip, toggle=off, guest_mode=anonymize
// @effects board_retention_targets_match_the_population
test('기능이 꺼져 있어도 두 축의 건수가 보이고 「IP 대상 보기」 창이 그 글·댓글을 보인다', async ({ page, settingsToken }) => {
  test.skip(Boolean(snapshot?.enabled), '이 사이트는 이미 보유기간 기능을 쓰고 있어 설정을 건드리지 않는다');

  test.setTimeout(90_000); // artisan 시드 + reload(networkidle) + 창 열기·목록 대기 합산 — 기본 30s 부족(실측 타임아웃 1회)
  artisanSeed('playwright:seed-board-retention');

  try {
    const preview = await api(page, settingsToken, `${API}/privacy-retention-preview`);
    expect(preview.body?.data?.ip_enabled).toBe(false);
    expect(preview.body?.data?.ip).toBeGreaterThan(0);

    await page.reload();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    const badges = page.locator('#privacy_retention_targets .badge');
    await expect(badges.nth(0)).toContainText(String(preview.body.data.ip), { timeout: 20_000 });

    await page.locator('#view_ip_targets').click();
    const rows = page.locator('#privacy_targets_list tbody tr');
    await expect(rows).toHaveCount(Math.min(preview.body.data.ip, 20), { timeout: 20_000 });
    // 행 링크는 관리자 상세다 — 대상의 휴지통 글은 사용자 화면에서 403 이라 열 수 없다
    await expect(rows.first().locator('a').first()).toHaveAttribute('href', /^\/admin\/board\/[^/]+\/post\/\d+$/);

    await page.locator('#privacy_targets_footer button').click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
  } finally {
    artisanSeed('playwright:seed-board-retention --teardown');
  }
});
