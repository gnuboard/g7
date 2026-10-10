/**
 * E2E: 환경설정 > 보안 — IP 접근 제한 카드와 차단 화면 (#636 트랙 2)
 *
 * 검증:
 *  1. 보안 탭에 접근 제한 카드가 번역문으로 마운트되고 현재 접속 IP 뱃지가 서버 진단값과 같다
 *  2. 규칙 편집 창이 빈 칸을 막고, 채운 규칙이 표에 서고 저장 요청까지 실려 서버에 남는다
 *  3. 지금 이 화면을 연 IP 를 뺀 허용 목록은 **저장되지 않고** 칸 아래에 이유가 뜬다(자기 잠금 422)
 *  4. 막힌 경로는 사람이 읽는 403 화면(접속 IP·홈 링크)으로, API 는 403 JSON 으로 답한다
 *
 * **사이트 상태 안전**: 이 spec 은 관리자 허용 목록을 절대 건드리지 않는다. 막는 것은 **존재하지
 * 않는 고유 접두사**(`__ip-probe-e2e*`) 뿐이며, 시작 시 보안 설정을 통째로 떠 두고 매 테스트 뒤·
 * 전체 종료 시 그대로 되돌린다. 되돌리기가 실패할 경우를 대비해 종료 시 서버 구제 커맨드
 * (`security:ip-restriction --disable`)도 함께 돌린다 — 잠긴 관리자를 푸는 것과 같은 경로다.
 *
 * 이미 IP 제한을 쓰고 있는 사이트(허용 목록이 비어 있지 않음)에서는 **건드리지 않고 건너뛴다** —
 * 남의 운영 설정을 시험 삼아 덮어쓰지 않는다.
 *
 * @scenario master_toggle=on, master_toggle=off, surface=admin_shell, surface=public_path, admin_list=empty, admin_list=excludes_client, rule=blocks_client, ip_source=direct
 *
 * @effects settings_card_mounts_with_current_ip, rule_modal_requires_pattern_then_ips, rule_modal_commits_once_to_parent_form, applied_rule_survives_save, self_lockout_by_admin_list_is_rejected, self_lockout_message_is_shown_in_place, web_block_renders_403_page_with_client_ip, rule_blocks_outside_ip, blocked_json_request_answers_with_translated_403
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const CARD = '#card_ip_restriction';
const PROBE_PATH = '__ip-probe-e2e';
/** 이 사이트일 리 없는 문서용 예약 IP — 규칙이 실제로 막는지 보려면 내 IP 가 아니어야 한다. */
const FOREIGN_IP = '198.51.100.7';

const __dirname = dirname(fileURLToPath(import.meta.url));
const CORE_ROOT = process.env.G7_ROOT || resolve(__dirname, '../../../../');

type Security = Record<string, unknown>;

let token: string;
/** 시작 시점의 보안 설정 — 매 테스트 뒤 이 값으로 되돌린다. */
let snapshot: Security = {};
/** 서버 진단이 보는 이 브라우저의 IP */
let clientIp = '';

test.describe.configure({ mode: 'serial' });

/**
 * 관리자 설정 API 를 브라우저에서 호출한다(화면과 같은 인증·같은 IP).
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
 * 보안 설정을 통째로 저장한다.
 *
 * @param page 페이지
 * @param security 저장할 security 값
 * @returns 상태 코드와 본문
 */
async function saveSecurity(page: Page, security: Security) {
  return api(page, '/api/admin/settings', { method: 'POST', body: { _tab: 'security', security } });
}

/** 떠 둔 보안 설정으로 되돌린다(실패해도 테스트를 깨지 않고 다음 안전망에 맡긴다). */
async function restore(page: Page): Promise<void> {
  if (Object.keys(snapshot).length === 0) return;
  await saveSecurity(page, snapshot).catch(() => undefined);
}

test.beforeAll(() => {
  token = issueToken('core.settings.read', 'core.settings.update');
});

test.beforeEach(async ({ page }) => {
  await authenticatePage(page, token);
  await page.goto('/admin/settings?tab=security');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(CARD)).toBeAttached({ timeout: 20_000 });
  // 데이터소스가 다 도착하기 전에 입력하면 뒤늦게 도착한 설정이 폼을 다시 채워 방금 친 값을
  // 지운다(설정 화면 공통). 마지막으로 도착하는 진단(현재 접속 IP 뱃지)을 기다린 뒤 만진다.
  await expect(page.locator('#ip_restriction_current_ip')).toBeVisible({ timeout: 20_000 });
  await page.waitForTimeout(1000);

  if (Object.keys(snapshot).length === 0) {
    const settings = await api(page, '/api/admin/settings');
    snapshot = (settings.body?.data?.security ?? {}) as Security;

    const diagnostic = await api(page, '/api/admin/settings/trusted-proxy');
    clientIp = String(diagnostic.body?.data?.client_ip ?? '');
  }

  test.skip(
    ((snapshot.admin_allowed_ips as string[] | undefined) ?? []).length > 0,
    '이 사이트는 이미 관리자 허용 IP 목록을 쓰고 있습니다 — 운영 설정을 덮어쓰지 않습니다.'
  );
});

test.afterEach(async ({ page }) => {
  await restore(page);
});

test.afterAll(() => {
  // 되돌리기가 실패해도 잠긴 채로 끝나지 않게 한다 — 잠긴 관리자를 푸는 것과 같은 경로.
  try {
    execSync('php artisan security:ip-restriction --disable', { cwd: CORE_ROOT, stdio: 'pipe' });
  } catch {
    // 커맨드가 없거나 이미 꺼져 있으면 그만이다.
  }
});

// @scenario master_toggle=off, surface=admin_shell, admin_list=empty
// @effects settings_card_mounts_with_current_ip
test('@smoke #636 - 보안 탭에 IP 접근 제한 카드가 번역문·현재 접속 IP 와 함께 뜬다', async ({ page }) => {
  const card = page.locator(CARD);
  const text = (await card.innerText()).trim();

  expect(text, '다국어 키가 그대로 노출됐습니다').not.toContain('$t:');
  expect(text).not.toContain('admin.settings.security.ip_restriction');

  await expect(page.locator('#toggle_ip_restriction_enabled input[type="checkbox"]').first()).toBeAttached();
  await expect(page.locator('#ip_restriction_admin_allowed_ips')).toBeAttached();

  // 현재 접속 IP 는 저장 검증이 자기 잠금을 판정할 때 쓰는 값과 같아야 뜻이 있다.
  expect(clientIp, '서버 진단이 접속 IP 를 주지 않았습니다').not.toBe('');
  await expect(page.locator('#ip_restriction_current_ip')).toContainText(clientIp);

  // 서버 해제 안내는 잠겼을 때 유일한 구제 경로라 늘 보여야 한다.
  await expect(page.locator('#ip_restriction_emergency_hint')).toContainText('security:ip-restriction --disable');
});

// @scenario master_toggle=on, surface=admin_shell, rule=blocks_client
// @effects rule_modal_requires_pattern_then_ips, rule_modal_commits_once_to_parent_form, applied_rule_survives_save
test('#636 - 규칙 편집 창이 빈 칸을 막고, 채운 규칙은 표에 서고 저장까지 살아남는다', async ({ page }) => {
  await page.locator('#toggle_ip_restriction_enabled .toggle-switch-wrapper').first().click();
  await expect(page.locator('#ip_restriction_add_rule')).toBeEnabled({ timeout: 10_000 });
  await page.locator('#ip_restriction_add_rule').click();

  // 창은 레이아웃 id 가 아니라 dialog 역할로 뜬다(Modal 컴포넌트가 자기 틀을 그린다).
  const modal = page.getByRole('dialog');
  await expect(modal).toBeVisible({ timeout: 10_000 });

  await modal.locator('#ip_rule_apply_button').click();
  await expect(modal.locator('#ip_rule_form_error')).toBeVisible();
  await expect(modal, '빈 칸인데 창이 닫혔습니다').toBeVisible();

  await modal.locator('#ip_rule_pattern_input').fill(`/${PROBE_PATH}*`);
  await modal.locator('#ip_rule_apply_button').click();
  await expect(modal.locator('#ip_rule_form_error')).toBeVisible();
  await expect(modal, 'IP 가 비었는데 창이 닫혔습니다').toBeVisible();

  // 태그 입력은 쉼표를 만나면 그 자리에서 태그를 만든다(구분자 기본값) — Enter 보다 덜 흔들린다.
  await modal.locator('#ip_rule_ips_input input').first().fill(`${FOREIGN_IP},`);
  await expect(modal.getByText(FOREIGN_IP, { exact: false }).first()).toBeVisible({ timeout: 5_000 });
  await modal.locator('#ip_rule_apply_button').click();

  await expect(modal).toBeHidden({ timeout: 10_000 });
  await expect(page.locator(`${CARD} tbody tr`).first()).toContainText(`/${PROBE_PATH}*`);
  await expect(page.locator(`${CARD} tbody tr`).first()).toContainText(FOREIGN_IP);
  // 규칙은 폼에만 담겼다 — 저장은 하단 버튼이 한다.
  await expect(page.getByRole('button', { name: /^저장$|^Save$/ }).first()).toBeEnabled({ timeout: 10_000 });

  // 창에서 적용한 규칙이 **저장까지 따라가는지**가 핵심이다. 화면 표에만 보이고 저장 본문에서
  // 빠지면 「저장했는데 규칙이 사라지는」 조용한 손실이 된다(실측으로 한 번 겪은 자리).
  const save = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' && /\/api\/admin\/settings\/?$/.test(new URL(response.url()).pathname),
    { timeout: 20_000 }
  );
  await page.getByRole('button', { name: /^저장$|^Save$/ }).first().click();
  expect((await save).status()).toBe(200);

  const stored = await api(page, '/api/admin/settings');
  const savedRules = (stored.body?.data?.security?.ip_restriction_rules ?? []) as Array<{ pattern?: string }>;
  expect(
    savedRules.map((rule) => rule.pattern),
    '창에서 적용한 규칙이 저장되지 않았습니다',
  ).toContain(`/${PROBE_PATH}*`);
});

// @scenario master_toggle=on, surface=admin_shell, admin_list=excludes_client
// @effects self_lockout_by_admin_list_is_rejected, self_lockout_message_is_shown_in_place
test('#636 - 지금 이 화면을 연 IP 를 뺀 허용 목록은 저장되지 않고 이유가 칸 아래에 뜬다', async ({ page }) => {
  await page.locator('#toggle_ip_restriction_enabled .toggle-switch-wrapper').first().click();
  // 토글이 켜져야 목록 칸이 열린다(꺼진 동안은 흐림 + 입력 잠금).
  await expect(page.locator('#ip_restriction_add_rule')).toBeEnabled({ timeout: 10_000 });

  // 자동 바인딩이 같은 칸에 숨은 입력(hidden)을 하나 더 두므로 보이는 입력만 고른다.
  const tagInput = page.locator('#ip_restriction_admin_allowed_ips input:not([type="hidden"])').first();
  await tagInput.fill(`${FOREIGN_IP},`);
  await expect(page.locator('#ip_restriction_admin_allowed_ips').getByText(FOREIGN_IP, { exact: false }).first())
    .toBeVisible({ timeout: 5_000 });

  const save = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' && /\/api\/admin\/settings\/?$/.test(new URL(response.url()).pathname),
    { timeout: 20_000 }
  );
  await page.getByRole('button', { name: /^저장$|^Save$/ }).first().click();
  expect((await save).status(), '자기 잠금이 저장돼 버렸습니다').toBe(422);

  await expect(page.locator(`${CARD} .form-error`).first()).toBeVisible({ timeout: 10_000 });
  const message = (await page.locator(`${CARD} .form-error`).first().innerText()).trim();
  expect(message).not.toContain('validation.');
  expect(message).toContain(clientIp);

  // 막힌 저장은 아무것도 바꾸지 않았다.
  const after = await api(page, '/api/admin/settings');
  expect(after.body?.data?.security?.ip_restriction_enabled ?? false).toBe(
    snapshot.ip_restriction_enabled ?? false
  );
  expect(after.body?.data?.security?.admin_allowed_ips ?? []).toEqual(snapshot.admin_allowed_ips ?? []);
});

// @scenario master_toggle=on, surface=public_path, rule=blocks_client, ip_source=direct
// @effects web_block_renders_403_page_with_client_ip, rule_blocks_outside_ip, blocked_json_request_answers_with_translated_403
test('#636 - 막힌 경로는 403 화면(접속 IP·홈 링크)으로, API 는 403 JSON 으로 답한다', async ({ page }) => {
  const saved = await saveSecurity(page, {
    ...snapshot,
    ip_restriction_enabled: true,
    ip_restriction_rules: [
      ...(((snapshot.ip_restriction_rules as unknown[]) ?? []) as unknown[]),
      // 화면용·API용 두 줄이다 — 패턴은 주소 그대로라 `api/` 로 시작하는 주소는 따로 적어야 맞는다.
      { pattern: `/${PROBE_PATH}*`, ips: [FOREIGN_IP], memo: 'e2e probe', enabled: true },
      { pattern: `/api/${PROBE_PATH}*`, ips: [FOREIGN_IP], memo: 'e2e probe api', enabled: true },
    ],
  });
  expect(saved.status, '측정용 규칙 저장이 실패했습니다').toBe(200);

  // 저장이 실제로 반영됐는지 먼저 확인한다 — 반영 전 요청을 재면 「막히지 않았다」로 오독한다.
  const persisted = await api(page, '/api/admin/settings');
  expect(persisted.body?.data?.security?.ip_restriction_enabled).toBe(true);
  expect((persisted.body?.data?.security?.ip_restriction_rules ?? []).length).toBeGreaterThanOrEqual(2);

  // 사람이 보는 화면 — 제목·접속 IP·홈 링크. 관리자 홈이 아니라 사이트 홈으로 보낸다
  // (관리자 홈은 이 사람이 열 수 없을 수 있다).
  const response = await page.goto(`/${PROBE_PATH}-page`);
  expect(response?.status()).toBe(403);
  const body = (await page.locator('body').innerText()).trim();
  expect(body, '다국어 키가 그대로 노출됐습니다').not.toContain('errors.ip_restricted');
  expect(body).toContain(clientIp);
  await expect(page.locator('a[href="/"]').first()).toBeVisible();

  // 기계가 보는 답 — JSON 403
  const json = await api(page, `/api/${PROBE_PATH}-x`);
  expect(json.status).toBe(403);
  expect(String(json.body?.message ?? ''), '다국어 키가 그대로 내려왔습니다').not.toContain('errors.ip_restricted');
  expect(String(json.body?.message ?? '').length).toBeGreaterThan(0);

  // 규칙 밖 경로는 그대로 열린다 — 이 규칙이 사이트를 통째로 막지 않았다.
  const untouched = await page.request.get('/');
  expect(untouched.status()).toBeLessThan(400);
});
