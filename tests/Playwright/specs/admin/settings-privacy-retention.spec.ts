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
 *  5. (#636 §1-E) 건수는 **꺼져 있어도** 보이고, 「대상 보기」가 명단 창을 연다. 창은 한 쪽 20건을
 *     먼저 보이고 스크롤 끝에서 다음 쪽을 이어 붙인다. 닫았다 다시 열면 첫 쪽부터다.
 *     회원 읽기 권한이 없는 관리자는 건수만 보고 버튼·명단 API(403)는 막힌다.
 *
 * **사이트 상태 안전**: 시작 시 privacy 설정을 통째로 떠 두고 매 테스트 뒤·전체 종료 시 그대로
 * 되돌린다. 이 spec 은 회원 데이터를 처리하지 않는다 — 켜는 것은 설정 토글뿐이고 파기 커맨드를
 * 실행하지 않으므로 어떤 회원도 처리되지 않는다. 명단 창 실측만 시험 계정 25명을 심고
 * (`playwright:seed-dormant --stage=convertible --count=25`) 끝나면 `--purge-users` 로 지운다.
 *
 * 이미 개인정보 기능을 쓰고 있는 사이트(둘 중 하나라도 켜져 있음)에서는 **건드리지 않고
 * 건너뛴다** — 남의 운영 설정을 시험 삼아 덮어쓰지 않는다.
 *
 * @scenario toggle=off, toggle=on, row_age=beyond_retention, run_mode=dry_run
 *
 * @effects settings_tab_opens_with_every_feature_off, period_field_appears_only_when_its_toggle_is_on, disabled_handler_is_reported_separately_from_zero_targets, core_settings_mirror_exposes_the_privacy_category, target_list_modal_pages_with_infinite_scroll, target_list_requires_domain_read_permission
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, issueToken, issueScopedToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../');

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
  // 명단 창은 회원 읽기까지 있어야 열린다(명단에 이름·이메일이 나온다).
  token = issueToken('core.settings.read', 'core.settings.update', 'core.users.read');
});

/**
 * 휴면 시험 계정 시드 커맨드를 실행한다(3중 가드 — CLI · G7_PLAYWRIGHT_BYPASS · 테스트 계정 접두사).
 *
 * @param args 커맨드 인자
 * @returns 표준 출력
 */
function seedDormant(args: string): string {
  return execSync(`php artisan playwright:seed-dormant ${args}`, {
    cwd: CORE_ROOT,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
}

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

  // 전부 꺼진 상태: 건수는 **그래도 센다**(켜기 전에 규모를 본다 — #636 §1-E), 꺼짐은 enabled 로 알린다.
  const off = await api(page, '/api/admin/settings/privacy-preview');
  expect(off.status).toBe(200);
  expect(off.body?.data?.retention?.['user-withdrawn']?.enabled).toBe(false);
  expect(typeof off.body?.data?.retention?.['user-withdrawn']?.count).toBe('number');
  expect(off.body?.data?.dormant?.enabled).toBe(false);
  expect(typeof off.body?.data?.dormant?.convert).toBe('number');

  // 켜면 enabled 만 바뀐다 — "꺼짐" 과 "0건" 이 같은 0 으로 뭉개지지 않는다.
  await savePrivacy(page, { ...snapshot, withdrawn_retention_enabled: true });
  const on = await api(page, '/api/admin/settings/privacy-preview');
  expect(on.body?.data?.retention?.['user-withdrawn']?.enabled).toBe(true);
  expect(typeof on.body?.data?.retention?.['user-withdrawn']?.count).toBe('number');
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

  const withdrawnCount = preview.body?.data?.retention?.['user-withdrawn']?.count;
  const dormant = preview.body?.data?.dormant;
  expect(typeof withdrawnCount).toBe('number');
  expect(dormant?.enabled).toBe(true);

  await page.reload();
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(DORMANT_TOGGLE)).toBeChecked({ timeout: 20_000 });

  // 뱃지가 실제로 그려져야 한다 — 존재하지 않는 색 토큰으로 무색 렌더되던 전례가 있다.
  // 조치 대상은 대상마다 한 행이다 — 휴면 네 단계도 단계마다 자기 행과 배지를 가진다(2026-09-26 재배치).
  // 탈퇴 단계 행은 휴면 토글과 무관하게 보인다 — 탈퇴 토글 줄은 휴면이 꺼지면 사라지므로 그 안에 두지 않는다.
  const withdrawnBadge = page.locator('#withdrawn_target_row .badge');
  await expect(withdrawnBadge).toBeVisible();

  // 그리고 그 숫자는 API 가 돌려준 값과 같아야 한다 — 화면이 다른 모집단을 세면
  // 운영자가 규모를 오판한 채 되돌릴 수 없는 파기를 켠다.
  await expect(withdrawnBadge).toContainText(String(withdrawnCount));
  for (const stage of ['pre_notice', 'convert', 'withdraw_notice', 'withdraw'] as const) {
    const stageBadge = page.locator(`#dormant_target_row_${stage} .badge`);
    await expect(stageBadge, stage).toBeVisible();
    await expect(stageBadge, stage).toContainText(String(dormant[stage]));
  }
});

// @scenario toggle=on, row_age=beyond_retention, run_mode=dry_run
// @effects preview_badges_render_the_same_counts_the_api_returns
test('화면에서 기간을 바꿔 저장하면 새로고침 없이 뱃지가 새 기간으로 센 숫자로 바뀐다', async ({ page }) => {
  test.skip(alreadyInUse, '이 사이트는 이미 개인정보 기능을 쓰고 있어 설정을 건드리지 않는다');
  test.setTimeout(90_000); // artisan 시드 + reload + 화면 저장 왕복 합산 — 기본 30s 부족

  // 기본 전환 기준(730일)을 넘긴 전환 대상을 심는다 — 기준을 3650일로 올리면 대상에서 빠진다.
  seedDormant('--stage=convertible --count=2');

  try {
    await page.reload();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const convertButton = page.locator('#view_convert_targets');
    const dormantBadge = page.locator('#dormant_target_row_convert .badge');
    await expect(convertButton).toBeVisible({ timeout: 20_000 });

    // 저장 버튼을 실제로 눌러 저장한다 — API 로 저장하고 새로고침하면 이 결함이 보이지 않는다(#636 브라우저 실측).
    await page.locator(`${DORMANT_ROW} .toggle-switch-wrapper`).first().click();
    await page.locator(DORMANT_DAYS).fill('3650');

    const saved = page.waitForResponse((response) => response.url().endsWith('/api/admin/settings') && response.request().method() === 'POST');
    const refetched = page.waitForRequest((request) => request.url().includes('/api/admin/settings/privacy-preview'));
    await page.locator('#save_button').click();
    expect((await saved).status()).toBe(200);
    await refetched;

    const after = await api(page, '/api/admin/settings/privacy-preview');
    const convert: number = after.body?.data?.dormant?.convert;
    // 시드 2명은 3650일 기준에서 빠진다 — 숫자가 그대로면 이 단언은 공허하다.
    expect(convert).toBe(0);

    await expect(dormantBadge).toContainText(new RegExp(`대상 ${convert}명|${convert} member`), { timeout: 20_000 });
    // 0명이어도 「대상 보기」는 남고, 창은 빈 명단 안내를 보인다 — 0명인 까닭을 창에서 확인한다.
    await expect(convertButton).toBeVisible();
    await convertButton.click();
    await expect(page.locator('#privacy_targets_empty')).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('#privacy_targets_list tbody tr')).toHaveCount(0);
    await page.locator('#privacy_targets_footer button').click();
  } finally {
    seedDormant('--purge-users');
  }
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

// @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
// @effects target_list_modal_pages_with_infinite_scroll
test('「대상 보기」 명단 창은 꺼진 상태에서도 열리고 스크롤 끝에서 다음 쪽을 이어 붙인다', async ({ page }) => {
  test.skip(alreadyInUse, '이 사이트는 이미 개인정보 기능을 쓰고 있어 설정을 건드리지 않는다');

  // 한 쪽(20건)을 넘도록 전환 대상 25명을 심는다 — 휴면 기능은 꺼진 그대로다.
  seedDormant('--stage=convertible --count=25');

  try {
    const preview = await api(page, '/api/admin/settings/privacy-preview');
    const total: number = preview.body?.data?.dormant?.convert ?? 0;
    expect(preview.body?.data?.dormant?.enabled).toBe(false);
    expect(total).toBeGreaterThanOrEqual(25);

    await page.reload();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const openButton = page.locator('#view_convert_targets');
    await expect(openButton).toBeVisible({ timeout: 20_000 });
    await openButton.click();

    // 창의 data-ref 는 대화상자 자체에 id 로 실리지 않는다 — 창 안 목록의 id 로 찾는다.
    const modal = page.getByRole('dialog');
    const list = page.locator('#privacy_targets_list');
    const rows = list.locator('tbody tr');
    await expect(rows).toHaveCount(20, { timeout: 20_000 });

    // 상세 링크는 새 탭으로 연다.
    const firstLink = rows.first().locator('a').first();
    await expect(firstLink).toHaveAttribute('target', '_blank');
    await expect(firstLink).toHaveAttribute('href', /^\/admin\/users\//);

    // 스크롤 끝 → 다음 쪽(page=2)을 부르고 이어 붙인다.
    const nextPage = page.waitForRequest((request) => request.url().includes('/api/admin/privacy/dormant-targets/convert') && request.url().includes('page=2'));
    await list.evaluate((el) => el.scrollTo(0, el.scrollHeight));
    await nextPage;
    await expect(rows).toHaveCount(Math.min(total, 40), { timeout: 20_000 });

    // 닫고 다시 열면 첫 쪽부터다(목록 상태를 되돌린다).
    await page.locator('#privacy_targets_footer button').click();
    await expect(modal).toHaveCount(0);
    await openButton.click();
    await expect(rows).toHaveCount(20, { timeout: 20_000 });
  } finally {
    seedDormant('--purge-users');
  }
});

// @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
// @effects target_list_requires_domain_read_permission
test('회원 읽기 권한이 없으면 건수는 보이되 「대상 보기」가 없고 명단 API 는 403 이다', async ({ browser }) => {
  test.skip(alreadyInUse, '이 사이트는 이미 개인정보 기능을 쓰고 있어 설정을 건드리지 않는다');

  // `issueToken` 은 전체 권한의 admin 역할을 함께 주므로 권한 경계를 잴 수 없다 — 지정 권한만 가진 토큰을 쓴다.
  const settingsOnly = issueScopedToken('core.settings.read');
  // 버튼이 「대상이 없어서」가 아니라 「권한이 없어서」 사라지는지 보려면 대상이 있어야 한다.
  seedDormant('--stage=convertible --count=1');
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    await authenticatePage(page, settingsOnly);
    await page.goto('/admin/settings?tab=privacy');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const dormantBadge = page.locator('#dormant_target_row_convert .badge');
    await expect(dormantBadge).toBeVisible({ timeout: 20_000 });
    await expect(dormantBadge).not.toContainText('대상 0명');
    await expect(page.locator('#view_convert_targets')).toHaveCount(0);
    await expect(page.locator('#view_withdrawn_targets')).toHaveCount(0);

    const status = await page.evaluate(async (bearer) => {
      const response = await fetch('/api/admin/privacy/retention-targets/user-withdrawn', {
        headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' },
      });

      return response.status;
    }, settingsOnly);
    expect(status).toBe(403);
  } finally {
    await context.close();
    seedDormant('--purge-users');
  }
});

// @scenario toggle=off, row_age=beyond_retention, run_mode=real_run
// @effects manual_run_is_queued_and_processed_by_the_job, manual_run_ignores_the_disabled_toggle, confirm_modal_recounts_before_queueing, card_shows_active_status_and_last_result
test('(#636 후속) 「지금 실행」은 꺼진 상태에서도 확인 창의 건수만큼 직권 실행하고 카드에 결과를 남긴다', async ({ browser }) => {
  // 실행은 되돌릴 수 없는 파기다 — 이 spec 이 심은 시험 계정만 대상일 때만 돌린다(사이트의 실제 탈퇴 회원 보호).
  const runner = issueToken('core.settings.read', 'core.settings.update', 'core.users.read', 'core.users.update');
  seedDormant('--purge-users');
  seedDormant('--stage=withdrawn --count=2');
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    await authenticatePage(page, runner);
    await page.goto('/admin/settings?tab=privacy');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const preview = await page.evaluate(async (bearer) => {
      const response = await fetch('/api/admin/privacy/manual-runs/retention:user-withdrawn/preview', {
        headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' },
      });

      return (await response.json())?.data;
    }, runner);
    test.skip(preview?.count !== 2, '이 사이트에는 시험 계정 밖의 파기 대상이 있어 직권 실행을 돌리지 않는다');

    const run = page.locator('#run_withdrawn');
    await expect(run).toBeVisible({ timeout: 20_000 });
    await run.click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('2건', { timeout: 10_000 });
    await expect(dialog).toContainText('익명화');
    await dialog.getByRole('button', { name: '실행', exact: true }).click();
    await expect(dialog).toHaveCount(0, { timeout: 10_000 });

    // 큐가 동기면 곧바로, 워커가 있으면 폴링으로 끝난다 — 어느 쪽이든 카드가 결과를 그린다.
    const status = page.locator('#withdrawn_manual_run_status');
    await expect(status).toContainText(/처리 대기|처리 중|마지막 직권 실행/, { timeout: 20_000 });
    await expect(status).toContainText('2건 처리', { timeout: 60_000 });
    await expect(page.locator('#withdrawn_target_row .badge')).toContainText('대상 0명', { timeout: 20_000 });
  } finally {
    await context.close();
    seedDormant('--purge-users');
  }
});
