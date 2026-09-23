/**
 * E2E: 환경설정 > 개인정보 — 보유기간 파기·휴면 계정 카드 (#636 트랙 4 / S4)
 *
 * 검증:
 *  1. 개인정보 탭이 **전 기능 꺼진 채** 마운트되고, 켜기 전 처리방침 고지가 번역문으로 보인다
 *  2. 기간 입력칸은 그 항목의 토글이 켜져 있을 때만 나타난다 — 꺼진 채 보이면 값을 고쳐도
 *     아무 일이 없어 "설정이 먹지 않는다" 로 읽힌다
 *  3. 사전 안내 기간을 전환 기간과 같게 저장하면 **저장되지 않고**(422) 그 칸 아래에 이유가 뜬다.
 *     이 교차 검증이 없으면 사전 안내 없이 전환·통지가 같은 날 일어난다(법정 사전 안내 소실)
 *  4. 화면의 dry-run 건수는 커맨드와 **같은 판정**을 지난 값이다 — 미리보기 API 와 화면이 어긋나면
 *     운영자가 규모를 오판한 채 되돌릴 수 없는 파기를 켠다
 *
 * **사이트 상태 안전**: 시작 시 privacy 설정을 통째로 떠 두고 매 테스트 뒤·전체 종료 시 그대로
 * 되돌린다. 이 spec 은 회원 데이터를 건드리지 않는다 — 켜는 것은 설정 토글뿐이고 파기 커맨드를
 * 실행하지 않으므로 어떤 회원도 처리되지 않는다.
 *
 * 이미 개인정보 기능을 쓰고 있는 사이트(둘 중 하나라도 켜져 있음)에서는 **건드리지 않고
 * 건너뛴다** — 남의 운영 설정을 시험 삼아 덮어쓰지 않는다.
 *
 * @scenario toggle=off, toggle=on, row_age=beyond_retention, run_mode=dry_run
 *
 * @effects settings_tab_opens_with_every_feature_off, period_field_appears_only_when_its_toggle_is_on, disabled_handler_is_reported_separately_from_zero_targets, core_settings_mirror_exposes_the_privacy_category
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const WITHDRAWN_TOGGLE = 'input[name="privacy.withdrawn_retention_enabled"]';
const DORMANT_TOGGLE = 'input[name="privacy.dormant_enabled"]';
const WITHDRAWN_DAYS = 'input[name="privacy.withdrawn_retention_days"]';
const DORMANT_DAYS = 'input[name="privacy.dormant_days"]';
const NOTICE_DAYS = 'input[name="privacy.dormant_notice_days"]';
/** 토글의 실제 input 은 `sr-only` 라 보이는 track 이 클릭을 가로챈다 — 선례대로 wrapper 를 누른다. */
const WITHDRAWN_ROW = '#toggle_withdrawn_retention';
const DORMANT_ROW = '#toggle_dormant';

type Privacy = Record<string, unknown>;

let token: string;
/** 시작 시점의 privacy 설정 — 매 테스트 뒤 이 값으로 되돌린다. */
let snapshot: Privacy = {};
/** 이 사이트가 이미 기능을 쓰고 있으면 건너뛴다. */
let alreadyInUse = false;

test.describe.configure({ mode: 'serial' });

/**
 * 관리자 설정 API 를 브라우저에서 호출한다(화면과 같은 인증).
 *
 * @param page 페이지
 * @param path API 경로
 * @param init fetch 옵션
 * @returns 상태 코드와 본문
 */
async function api(
  page: Page,
  path: string,
  init: { method?: string; body?: unknown } = {}
): Promise<{ status: number; body: any }> {
  return page.evaluate(
    async ([bearer, url, options]: [string, string, { method?: string; body?: unknown }]) => {
      const response = await fetch(url, {
        method: options.method ?? 'GET',
        headers: {
          Authorization: `Bearer ${bearer}`,
          Accept: 'application/json',
          ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
      });

      return { status: response.status, body: await response.json().catch(() => null) };
    },
    [token, path, init] as [string, string, { method?: string; body?: unknown }]
  );
}

/**
 * privacy 설정을 통째로 저장한다.
 *
 * @param page 페이지
 * @param privacy 저장할 privacy 값
 * @returns 상태 코드와 본문
 */
async function savePrivacy(page: Page, privacy: Privacy) {
  return api(page, '/api/admin/settings', { method: 'POST', body: { _tab: 'privacy', privacy } });
}

/** 떠 둔 privacy 설정으로 되돌린다(실패해도 테스트를 깨지 않는다). */
async function restore(page: Page): Promise<void> {
  if (Object.keys(snapshot).length === 0) return;
  await savePrivacy(page, snapshot).catch(() => undefined);
}

test.beforeAll(() => {
  token = issueToken('core.settings.read', 'core.settings.update');
});

test.beforeEach(async ({ page }) => {
  await authenticatePage(page, token);
  await page.goto('/admin/settings?tab=privacy');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(WITHDRAWN_TOGGLE)).toBeAttached({ timeout: 20_000 });

  if (Object.keys(snapshot).length === 0) {
    const { body } = await api(page, '/api/admin/settings');
    snapshot = (body?.data?.privacy ?? {}) as Privacy;
    alreadyInUse =
      Boolean(snapshot.withdrawn_retention_enabled) || Boolean(snapshot.dormant_enabled);
  }
});

test.afterEach(async ({ page }) => {
  await restore(page);
});

// @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
// @effects settings_tab_opens_with_every_feature_off
test('개인정보 탭은 전 기능 꺼진 채 열리고 켜기 전 고지를 보여 준다', async ({ page }) => {
  test.skip(alreadyInUse, '이 사이트는 이미 개인정보 기능을 쓰고 있어 설정을 건드리지 않는다');

  await expect(page.locator(WITHDRAWN_TOGGLE)).not.toBeChecked();
  await expect(page.locator(DORMANT_TOGGLE)).not.toBeChecked();

  // 꺼져 있으면 기간칸이 없다 — 있으면 고쳐도 아무 일이 없어 오해를 만든다.
  await expect(page.locator(WITHDRAWN_DAYS)).toHaveCount(0);
  await expect(page.locator(DORMANT_DAYS)).toHaveCount(0);

  // 번역 키가 아니라 사람이 읽는 문구여야 한다.
  const notice = page.locator('#card_privacy_notice');
  await expect(notice).toBeVisible();
  await expect(notice).not.toContainText('admin.settings.privacy');
});

// @scenario toggle=on, row_age=beyond_retention, run_mode=dry_run
// @effects period_field_appears_only_when_its_toggle_is_on
test('토글을 켜면 그 항목의 기간칸이 나타난다', async ({ page }) => {
  test.skip(alreadyInUse, '이 사이트는 이미 개인정보 기능을 쓰고 있어 설정을 건드리지 않는다');

  await page.locator(`${WITHDRAWN_ROW} .toggle-switch-wrapper`).first().click();
  await expect(page.locator(WITHDRAWN_DAYS)).toBeVisible();

  await page.locator(`${DORMANT_ROW} .toggle-switch-wrapper`).first().click();
  await expect(page.locator(DORMANT_DAYS)).toBeVisible();
  await expect(page.locator(NOTICE_DAYS)).toBeVisible();
});

// @scenario toggle=on, row_age=beyond_retention, run_mode=real_run
// @effects core_settings_mirror_exposes_the_privacy_category
test('사전 안내 기간이 전환 기간보다 짧지 않으면 저장되지 않는다', async ({ page }) => {
  test.skip(alreadyInUse, '이 사이트는 이미 개인정보 기능을 쓰고 있어 설정을 건드리지 않는다');

  const rejected = await savePrivacy(page, {
    ...snapshot,
    dormant_enabled: true,
    dormant_days: 100,
    dormant_notice_days: 100,
  });

  expect(rejected.status).toBe(422);
  expect(JSON.stringify(rejected.body)).toContain('privacy.dormant_notice_days');

  // 같은 값이라도 통지일이 짧으면 통과한다 — 막는 것이 "값" 이 아니라 "순서" 임을 고정한다.
  const accepted = await savePrivacy(page, {
    ...snapshot,
    dormant_enabled: true,
    dormant_days: 100,
    dormant_notice_days: 30,
  });

  expect(accepted.status).toBe(200);
});

// @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
// @effects disabled_handler_is_reported_separately_from_zero_targets
test('dry-run 건수 API 는 꺼짐과 대상 0건을 구분해 돌려준다', async ({ page }) => {
  test.skip(alreadyInUse, '이 사이트는 이미 개인정보 기능을 쓰고 있어 설정을 건드리지 않는다');

  // 전부 꺼진 상태: 핸들러 건수는 null(=꺼짐), 휴면 묶음도 null.
  const off = await api(page, '/api/admin/settings/privacy-preview');
  expect(off.status).toBe(200);
  expect(off.body?.data?.retention?.['user-withdrawn']).toBeNull();
  expect(off.body?.data?.dormant).toBeNull();

  // 켜면 같은 자리가 숫자가 된다 — "꺼짐" 과 "0건" 이 같은 0 으로 뭉개지지 않는다.
  await savePrivacy(page, { ...snapshot, withdrawn_retention_enabled: true });
  const on = await api(page, '/api/admin/settings/privacy-preview');
  expect(typeof on.body?.data?.retention?.['user-withdrawn']).toBe('number');
});

// @scenario toggle=on, row_age=beyond_retention, run_mode=dry_run
// @effects preview_badges_render_the_same_counts_the_api_returns
test('카드의 dry-run 뱃지는 미리보기 API 가 돌려준 바로 그 숫자를 그린다', async ({ page }) => {
  test.skip(alreadyInUse, '이 사이트는 이미 개인정보 기능을 쓰고 있어 설정을 건드리지 않는다');

  // 세 뱃지가 모두 뜨도록 파기·휴면·자동탈퇴를 켠다(설정만 — 커맨드는 돌리지 않는다).
  await savePrivacy(page, {
    ...snapshot,
    withdrawn_retention_enabled: true,
    dormant_enabled: true,
    dormant_withdraw_enabled: true,
  });

  // 화면이 읽는 것과 같은 자료를 API 에서 받아 둔다.
  const preview = await api(page, '/api/admin/settings/privacy-preview');
  expect(preview.status).toBe(200);

  const withdrawnCount = preview.body?.data?.retention?.['user-withdrawn'];
  const dormant = preview.body?.data?.dormant;
  expect(typeof withdrawnCount).toBe('number');
  expect(dormant).not.toBeNull();

  await page.reload();
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(DORMANT_TOGGLE)).toBeChecked({ timeout: 20_000 });

  // 뱃지가 실제로 그려져야 한다 — 존재하지 않는 색 토큰으로 무색 렌더되던 전례가 있다.
  const withdrawnBadge = page.locator('#toggle_withdrawn_retention .badge');
  const dormantBadge = page.locator('#toggle_dormant .badge');
  const withdrawBadge = page.locator('#toggle_dormant_withdraw .badge');

  await expect(withdrawnBadge).toBeVisible();
  await expect(dormantBadge).toBeVisible();
  await expect(withdrawBadge).toBeVisible();

  // 그리고 그 숫자는 API 가 돌려준 값과 같아야 한다 — 화면이 다른 모집단을 세면
  // 운영자가 규모를 오판한 채 되돌릴 수 없는 파기를 켠다.
  await expect(withdrawnBadge).toContainText(String(withdrawnCount));
  await expect(dormantBadge).toContainText(String(dormant.pre_notice));
  await expect(dormantBadge).toContainText(String(dormant.convert));
  await expect(withdrawBadge).toContainText(String(dormant.withdraw_notice));
  await expect(withdrawBadge).toContainText(String(dormant.withdraw));
});

// @scenario toggle=on, row_age=beyond_retention, run_mode=dry_run
// @effects saved_privacy_settings_survive_a_browser_reload
test('새로고침해도 저장한 개인정보 설정이 화면에 그대로 복원된다', async ({ page }) => {
  test.skip(alreadyInUse, '이 사이트는 이미 개인정보 기능을 쓰고 있어 설정을 건드리지 않는다');

  const saved = await savePrivacy(page, {
    ...snapshot,
    withdrawn_retention_enabled: true,
    withdrawn_retention_days: 400,
    dormant_enabled: true,
    dormant_days: 800,
    dormant_notice_days: 45,
  });
  expect(saved.status).toBe(200);

  // API 응답이 아니라 **브라우저 새로고침을 실제로 통과한 화면**을 본다 — 저장은 됐는데
  // 화면이 초기값으로 되돌아가는 종류의 회귀는 응답만 봐서는 잡히지 않는다.
  await page.reload();
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  await expect(page.locator(WITHDRAWN_TOGGLE)).toBeChecked({ timeout: 20_000 });
  await expect(page.locator(DORMANT_TOGGLE)).toBeChecked();

  await expect(page.locator(WITHDRAWN_DAYS)).toHaveValue('400');
  await expect(page.locator(DORMANT_DAYS)).toHaveValue('800');
  await expect(page.locator(NOTICE_DAYS)).toHaveValue('45');
});
