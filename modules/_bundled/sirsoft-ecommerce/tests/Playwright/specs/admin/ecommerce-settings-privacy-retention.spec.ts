/**
 * E2E: 쇼핑몰 환경설정 > 개인정보 보유기간 탭 (이슈 #636 S5)
 *
 * 검증:
 *  1. 탭이 **꺼진 채** 번역문(법령 안내 포함)과 함께 마운트되고, 꺼져 있으면 보유기간·방식 칸이 없다
 *  2. 켜면 보유기간·방식이 나타나고, 익명화 안내와 삭제 경고가 **각자 그 방식을 골랐을 때만** 보인다
 *  3. 저장하면 요청 본문에 `privacy_retention` 이 실리고, 대상 건수 뱃지가 미리보기 API 가 돌려준
 *     **바로 그 숫자**를 그린다(저장된 설정 기준)
 *
 * **사이트 상태 안전**: 설정 API 로 시작 값을 떠 두고 매 테스트 뒤 되돌린다. 보유기간 커맨드는
 * 실행하지 않으므로 어떤 주문도 처리되지 않는다. 이미 보유기간 기능을 켜 둔 사이트는 건드리지 않고
 * 건너뛴다. 설정을 바꾸는 spec 이라 직렬로 돈다(#42 — 확장 config 는 로컬 병렬).
 *
 * @scenario order_state=confirmed, toggle=on, mode=delete
 *
 * @effects order_retention_settings_tab_binds_saved_keys, order_retention_settings_warn_per_mode, order_retention_preview_api_counts_while_off
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Locator, Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const SETTINGS_URL = '/admin/ecommerce/settings?tab=privacy_retention';
const TAB = '#tab_content_privacy_retention';
const TOGGLE_FIELD = '#privacy_retention_enabled_field';
const FIELDS = '#privacy_retention_fields';
const YEARS = 'input[name="privacy_retention.retention_years"]';
const ANONYMIZE_NOTICE = '#privacy_retention_anonymize_notice';
const DELETE_WARNING = '#privacy_retention_delete_warning';
const SAVE = '[data-testid="ecommerce-settings-save"]';

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

const API = '/api/modules/sirsoft-ecommerce/admin/settings';

type Retention = Record<string, unknown>;

/** 시작 시점 설정 — 매 테스트 뒤 되돌린다. */
let snapshot: Retention | null = null;

test.describe.configure({ mode: 'serial' });

/**
 * 쇼핑몰 설정 API 를 화면과 같은 인증으로 호출한다.
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
 * 하단 고정 저장 막대에 가리지 않도록 요소를 화면 가운데로 올린 뒤 누른다.
 *
 * @param locator 누를 요소
 * @returns 없음
 */
async function clickCentered(locator: Locator): Promise<void> {
  await locator.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  await locator.click();
}

test.beforeEach(async ({ page, settingsToken }) => {
  await authenticatePage(page, settingsToken);
  await page.goto(SETTINGS_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(TAB)).toBeAttached({ timeout: 20_000 });
  // 설정·미리보기 데이터소스가 폼을 채우기 전에 누르면 뒤늦게 도착한 응답이 폼을 저장값으로
  // 되덮는다 — 네트워크가 잦아든 뒤에 조작한다.
  await page.waitForLoadState('networkidle', { timeout: 30_000 });

  if (snapshot === null) {
    const { body } = await api(page, settingsToken, API);
    snapshot = (body?.data?.privacy_retention ?? {}) as Retention;
  }
});

test.afterEach(async ({ page, settingsToken }) => {
  if (snapshot !== null) {
    await api(page, settingsToken, API, { _tab: 'privacy_retention', privacy_retention: snapshot }).catch(() => undefined);
  }
});

// @scenario order_state=confirmed, toggle=off, mode=anonymize
// @effects order_retention_settings_tab_binds_saved_keys
test('탭은 꺼진 채 번역문과 함께 열리고, 꺼져 있으면 보유기간·방식 칸이 없다', async ({ page }) => {
  test.skip(Boolean(snapshot?.enabled), '이 사이트는 이미 보유기간 기능을 쓰고 있어 설정을 건드리지 않는다');

  const text = (await page.locator(TAB).innerText()).trim();
  expect(text).not.toContain('$t:');
  expect(text).not.toContain('privacy_retention.');

  const toggle = page.locator(TOGGLE_FIELD).locator('input[type="checkbox"]').first();
  expect(await toggle.isChecked()).toBe(false);
  await expect(page.locator(FIELDS)).toHaveCount(0);
});

// @scenario order_state=confirmed, toggle=on, mode=delete
// @effects order_retention_settings_warn_per_mode
test('켜면 보유기간과 방식이 나타나고, 방식별 안내가 그 방식에서만 보인다', async ({ page }) => {
  test.skip(Boolean(snapshot?.enabled), '이 사이트는 이미 보유기간 기능을 쓰고 있어 설정을 건드리지 않는다');

  await clickCentered(page.locator(TOGGLE_FIELD).locator('.toggle-switch-wrapper').first());
  await expect(page.locator(YEARS)).toBeVisible({ timeout: 10_000 });
  await expect(page.locator(ANONYMIZE_NOTICE)).toBeVisible();
  await expect(page.locator(DELETE_WARNING)).toHaveCount(0);

  await clickCentered(page.locator(FIELDS).getByText(/^삭제 \(|^Delete \(/).first());
  await expect(page.locator(DELETE_WARNING)).toBeVisible();
  await expect(page.locator(ANONYMIZE_NOTICE)).toHaveCount(0);
});

// @scenario order_state=confirmed, toggle=on, mode=anonymize
// @effects order_retention_preview_api_counts_while_off
test('저장하면 본문에 privacy_retention 이 실리고 뱃지가 미리보기 API 의 숫자를 그린다', async ({ page, settingsToken }) => {
  test.skip(Boolean(snapshot?.enabled), '이 사이트는 이미 보유기간 기능을 쓰고 있어 설정을 건드리지 않는다');

  await clickCentered(page.locator(TOGGLE_FIELD).locator('.toggle-switch-wrapper').first());
  await page.locator(YEARS).fill('6');

  const request = page.waitForRequest((req) => req.url().endsWith(API) && req.method() === 'PUT', { timeout: 20_000 });
  const response = page.waitForResponse((res) => res.url().endsWith(API) && res.request().method() === 'PUT', { timeout: 20_000 });
  await page.locator(SAVE).click();

  const payload = (await request).postDataJSON() as Record<string, any>;
  expect((await response).status()).toBe(200);
  expect(payload._tab).toBe('privacy_retention');
  expect(Boolean(payload.privacy_retention.enabled)).toBe(true);
  expect(Number(payload.privacy_retention.retention_years)).toBe(6);

  // 화면이 읽는 것과 같은 자료를 API 에서 받아 둔다 — 켰으니 숫자다.
  const preview = await api(page, settingsToken, `${API}/privacy-retention-preview`);
  expect(typeof preview.body?.data?.orders).toBe('number');

  await page.reload();
  await page.waitForLoadState('networkidle', { timeout: 30_000 });
  const badge = page.locator('#privacy_retention_target_row .badge');
  await expect(badge).toBeVisible({ timeout: 20_000 });
  await expect(badge).toContainText(String(preview.body.data.orders));
  await expect(page.locator(YEARS)).toHaveValue('6');
});

// @scenario order_state=confirmed, toggle=off, mode=anonymize
// @effects order_retention_targets_match_the_population
test('기능이 꺼져 있어도 대상 주문 건수가 보이고 「대상 주문 보기」 창이 그 주문을 보인다', async ({ page, settingsToken }) => {
  test.skip(Boolean(snapshot?.enabled), '이 사이트는 이미 보유기간 기능을 쓰고 있어 설정을 건드리지 않는다');

  test.setTimeout(90_000); // artisan 시드 + reload(networkidle) + 창 열기·목록 대기 합산 — 기본 30s 부족(실측 타임아웃 1회)
  artisanSeed('playwright:seed-order-retention');

  try {
    const preview = await api(page, settingsToken, `${API}/privacy-retention-preview`);
    expect(preview.body?.data?.enabled).toBe(false);
    expect(preview.body?.data?.orders).toBeGreaterThan(0);

    await page.reload();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    await expect(page.locator('#privacy_retention_target_row .badge')).toContainText(String(preview.body.data.orders), { timeout: 20_000 });

    await page.locator('#view_privacy_retention_targets').click();
    const rows = page.locator('#privacy_targets_list tbody tr');
    await expect(rows).toHaveCount(Math.min(preview.body.data.orders, 20), { timeout: 20_000 });

    const firstLink = rows.first().locator('a').first();
    await expect(firstLink).toHaveAttribute('target', '_blank');
    await expect(firstLink).toHaveAttribute('href', /^\/admin\/ecommerce\/orders\//);

    await page.locator('#privacy_targets_footer button').click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
  } finally {
    artisanSeed('playwright:seed-order-retention --teardown');
  }
});
