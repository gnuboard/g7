/**
 * 관리자 알림 탭 종단 검증 — 이 플러그인이 등록한 알림 5종 표시, 정의 켜기/끄기, 발송 수단 저장·취소, 템플릿 편집·미리보기·저장,
 * 기본값 되돌리기, 알림 권한 없는 관리자의 안내, 정책 탭 기준 칸에서 알림 탭으로 가는 링크.
 *
 * 쓰기는 「소셜 가입 급증」(social_login_signup_surge) 한 정의에만 한다. 시작 전에 그 정의의 사용 여부·발송 수단을 받아 두고,
 * 끝나면 코어 알림 API 로 같은 값으로 되돌린 뒤 템플릿을 기본값으로 복원한다.
 */
import { test, expect, type Page, type APIRequestContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { authenticatePage, issueToken, issueScopedToken } from '../../../../../../../tests/Playwright/fixtures/auth';

test.describe.configure({ mode: 'serial' });

const SETTINGS = '/admin/plugins/sirsoft-social_login/settings';
const LIST = '/api/admin/notification-definitions?extension_type=plugin&extension_identifier=sirsoft-social_login&template_channel=all&per_page=50';
const SURGE = 'social_login_signup_surge';
const SURGE_NAME = '소셜 가입 급증';
const DEFAULT_SUBJECT = '[{app_name}] {provider_label} 소셜 가입이 급증했습니다';
/** 사이트내 알림만 선언된 정의 — 메일을 켜면 코어가 빈 메일 템플릿을 만든다 */
const WEBHOOK = 'social_login_webhook_failed';
const WEBHOOK_NAME = '소셜 로그인 웹훅 처리 실패';
const CORE_ROOT = fileURLToPath(new URL('../../../../../../../', import.meta.url));
let admin: string;
let api: APIRequestContext;
let webhookOriginal: string[] = [];
let surgeOverrides: string[] | null = null;
let original: { id: number; is_active: boolean; channels: string[] };

/**
 * 가입 급증 정의를 API 로 받습니다.
 *
 * @returns 정의
 */
async function surgeDefinition(): Promise<{ id: number; is_active: boolean; channels: string[]; templates: { channel: string; subject: Record<string, string> }[] }> {
  const response = await api.get(LIST);
  expect(response.status()).toBe(200);
  const definitions = (await response.json()).data.data as { type: string }[];
  const found = definitions.find((d) => d.type === SURGE);
  expect(found).toBeTruthy();

  return found as never;
}

test.beforeAll(async ({ playwright }) => {
  admin = issueToken();
  api = await playwright.request.newContext({
    baseURL: test.info().project.use.baseURL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { Authorization: `Bearer ${admin}`, Accept: 'application/json' },
  });
  const surge = await surgeDefinition();
  original = { id: surge.id, is_active: surge.is_active, channels: [...surge.channels] };
  // 운영자가 다른 수단을 켜 둔 개발 사이트에서도 같은 출발점에서 재도록 선언된 수단(메일, 사이트내 알림)으로 맞춘다 — 끝나면 되돌린다
  expect((await api.put(`/api/admin/notification-definitions/${original.id}`, { data: { channels: ['mail', 'database'] } })).status()).toBe(200);
  webhookOriginal = webhookState('read');
  surgeOverrides = overrides('read');
});

test.afterAll(async () => {
  const surge = await surgeDefinition();
  if (surge.is_active !== original.is_active) {
    expect((await api.patch(`/api/admin/notification-definitions/${original.id}/toggle-active`)).status()).toBe(200);
  }
  expect((await api.put(`/api/admin/notification-definitions/${original.id}`, { data: { channels: original.channels } })).status()).toBe(200);
  expect((await api.post(`/api/admin/notification-definitions/${original.id}/reset`)).status()).toBe(200);
  webhookState('restore', webhookOriginal);
  // 「이 알림 사용」 끄고 켜기는 값을 되돌려도 수정 표식(is_active)을 남긴다 — 시작 전 표식으로 되돌린다
  overrides('restore', surgeOverrides);
  await api.dispose();
});

/**
 * 가입 급증 정의의 수정 표식(user_overrides)을 읽거나 되돌립니다(동기화 문맥).
 *
 * @param action read | restore
 * @param value restore 때 되돌릴 표식
 * @returns 표식
 */
function overrides(action: 'read' | 'restore', value: string[] | null = null): string[] | null {
  const find = `App\\Models\\NotificationDefinition::where('type', '${SURGE}')`;
  const code = action === 'read'
    ? `echo json_encode(${find}->value('user_overrides'));`
    : [
      `app()->instance('user_overrides.seeding', true);`,
      `${find}->first()->update(['user_overrides' => ${value === null ? 'null' : JSON.stringify(value).replace(/"/g, "'")}]);`,
      `app()->forgetInstance('user_overrides.seeding');`,
      `echo json_encode(${find}->value('user_overrides'));`,
    ].join(' ');
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: CORE_ROOT, encoding: 'utf8' });
  const parsed = JSON.parse(out.trim().split(/\r?\n/).pop() ?? 'null');

  return typeof parsed === 'string' ? JSON.parse(parsed) : parsed;
}

/**
 * 웹훅 처리 실패 정의의 발송 수단을 읽거나, 시험이 만든 운영자 템플릿을 지우고 발송 수단을 되돌립니다(동기화 문맥이라 수정 표식을 남기지 않는다).
 *
 * @param action read | restore
 * @param channels restore 때 되돌릴 발송 수단
 * @returns 발송 수단 목록
 */
function webhookState(action: 'read' | 'restore', channels: string[] = []): string[] {
  const find = `App\\Models\\NotificationDefinition::where('type', '${WEBHOOK}')`;
  const code = action === 'read'
    ? `echo json_encode(${find}->value('channels'));`
    : [
      `$d = ${find}->first();`,
      `App\\Models\\NotificationTemplate::where('definition_id', $d->id)->where('is_user_created', true)->get()->each->delete();`,
      `app()->instance('user_overrides.seeding', true);`,
      `$d->update(['channels' => ${JSON.stringify(channels).replace(/"/g, "'")}, 'user_overrides' => null]);`,
      `app()->forgetInstance('user_overrides.seeding');`,
      'echo json_encode($d->fresh()->channels);',
    ].join(' ');
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: CORE_ROOT, encoding: 'utf8' });

  return JSON.parse(out.trim().split(/\r?\n/).pop() ?? '[]');
}

/**
 * 토큰으로 알림 탭을 열고 정의 목록 응답을 기다립니다.
 *
 * @param page 페이지
 * @param token 토큰
 * @returns 정의 목록 응답 상태 코드
 */
async function openNotifications(page: Page, token: string): Promise<number> {
  await authenticatePage(page, token);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  const list = page.waitForResponse((r) => r.url().includes('/api/admin/notification-definitions?') && r.request().method() === 'GET');
  await page.goto(`${SETTINGS}?tab=notifications`);

  return (await list).status();
}

/**
 * 가입 급증 정의 카드.
 *
 * @param page 페이지
 * @returns 카드 로케이터
 */
function surgeCard(page: Page) {
  return page.locator('[id^="sl_notification_definition_"]').filter({ hasText: SURGE_NAME });
}

/**
 * 조작 뒤 나가는 코어 알림 API 응답을 받습니다.
 *
 * @param page 페이지
 * @param method HTTP 메서드
 * @param path `/api/admin/` 뒤 경로 일부
 * @param act 요청을 일으키는 조작
 * @returns 응답 상태 코드
 */
async function notificationCall(page: Page, method: string, path: string, act: () => Promise<void>): Promise<number> {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/admin/${path}`) && r.request().method() === method),
    act(),
  ]);

  return response.status();
}

/**
 * 알림 탭이 이 플러그인 정의 5종을 그리고, 데스크톱과 휴대폰 폭에서 가로 넘침이 없다. 채널 스위치와 템플릿 스위치는 화면 읽기 이름이
 * 겹치지 않는다.
 *
 * @scenario surface=notifications_tab, viewport=desktop|mobile
 * @effects notifications_tab_lists_own_definitions
 */
test('notifications tab lists the five plugin definitions at desktop and mobile widths', async ({ page }) => {
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 1000 });
    expect(await openNotifications(page, admin)).toBe(200);
    await expect(page.locator('[id^="sl_notification_definition_"]')).toHaveCount(5);
    for (const name of ['소셜 연결 해제 — 로그인 수단 없음', '소셜 연결 관리자 해제', '소셜 로그인 제공자 연속 실패', SURGE_NAME, '소셜 로그인 웹훅 처리 실패']) {
      await expect(page.locator('#card_sl_notifications')).toContainText(name);
    }
    await expect(surgeCard(page).getByRole('switch', { name: `${SURGE_NAME} / 메일`, exact: true })).toHaveCount(1);
    await expect(surgeCard(page).getByRole('switch', { name: `${SURGE_NAME} / 메일 템플릿 사용`, exact: true })).toHaveCount(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  }
});

/**
 * 「이 알림 사용」을 끄면 즉시 저장되고 새로고침 뒤에도 꺼져 있다. 다시 켜면 켜진다.
 *
 * @scenario surface=notifications_tab, notification_action=definition_toggle
 * @effects definition_toggle_persists
 */
test('definition switch is saved immediately and survives reload', async ({ page }) => {
  await openNotifications(page, admin);
  const active = surgeCard(page).getByRole('switch', { name: `${SURGE_NAME} 사용` });
  await expect(active).toBeChecked();
  expect(await notificationCall(page, 'PATCH', `notification-definitions/${original.id}/toggle-active`, () => active.click())).toBe(200);
  await expect(active).not.toBeChecked();

  await page.reload();
  await expect(active).not.toBeChecked();
  expect(await notificationCall(page, 'PATCH', `notification-definitions/${original.id}/toggle-active`, () => active.click())).toBe(200);
  await expect(active).toBeChecked();
});

/**
 * 발송 수단 스위치를 바꾸면 [발송 수단 저장]과 [취소]가 나타나고, 템플릿이 없는 수단에는 발송되지 않는다는 안내가 붙는다. [취소]는
 * 저장된 값으로 돌아가고, 모두 끄면 저장이 잠기며, 하나를 끄고 저장하면 새로고침 뒤에도 남는다.
 *
 * @scenario surface=notifications_tab, notification_action=channel_save|channel_cancel
 * @effects channel_cancel_restores, channel_save_persists
 */
test('channel changes can be cancelled or saved', async ({ page }) => {
  await openNotifications(page, admin);
  const card = surgeCard(page);
  const save = card.locator('[id^="sl_save_channels_"]');
  const mail = card.getByRole('switch', { name: `${SURGE_NAME} / 메일`, exact: true });
  const site = card.getByRole('switch', { name: `${SURGE_NAME} / 사이트내 알림`, exact: true });
  // 알림톡은 이 정의에 템플릿이 없다(관리자 대상 알림이라 비즈뿌리오가 만들지 않고, 저장 전이라 코어도 아직 만들지 않았다)
  const alimtalk = card.getByRole('switch', { name: `${SURGE_NAME} / 비즈뿌리오 알림톡`, exact: true });
  await expect(save).toBeDisabled();

  if (await alimtalk.count()) {
    await alimtalk.click();
    await expect(card).toContainText('이 수단의 템플릿이 없어 발송되지 않습니다');
    await expect(save).toBeEnabled();
    await card.locator('[id^="sl_cancel_channels_"]').click();
    await expect(alimtalk).not.toBeChecked();
    await expect(save).toBeDisabled();
  }

  await mail.click();
  await site.click();
  await expect(save).toBeDisabled();
  await mail.click();

  expect(await notificationCall(page, 'PUT', `notification-definitions/${original.id}`, () => save.click())).toBe(200);
  await expect(save).toBeDisabled();
  await page.reload();
  await expect(mail).toBeChecked();
  await expect(site).not.toBeChecked();
  expect((await surgeDefinition()).channels).toEqual(['mail']);
});

/**
 * 메일 템플릿 편집 창에서 제목을 바꾸면 미리보기가 그 제목을 보여 주고, 저장하면 창이 닫히며 다시 열어도 바뀐 제목이다.
 * [기본값으로 되돌리기]를 확인하면 기본 제목으로 돌아간다.
 *
 * @scenario surface=notifications_tab, notification_action=template_edit|template_preview|reset
 * @effects template_edit_saved, template_preview_rendered, definition_reset_restores_defaults
 */
test('mail template is edited, previewed, saved and reset to default', async ({ page }) => {
  await openNotifications(page, admin);
  const card = surgeCard(page);
  const mailRow = card.locator('[id^="sl_template_row_"]').filter({ has: page.getByRole('switch', { name: `${SURGE_NAME} / 메일 템플릿 사용` }) });
  const editModal = page.getByRole('dialog', { name: '알림 템플릿 편집' });

  await mailRow.getByRole('button', { name: '템플릿 편집' }).click();
  await expect(editModal).toBeVisible();
  const subject = editModal.getByRole('textbox', { name: '제목' });
  await expect(subject).toHaveValue(DEFAULT_SUBJECT);
  await subject.fill('[{app_name}] {provider_label} 가입 급증 확인 필요');
  await subject.blur();

  expect(await notificationCall(page, 'POST', 'notification-templates/preview', () => editModal.getByRole('button', { name: '미리보기' }).click())).toBe(200);
  const preview = page.getByRole('dialog', { name: '알림 미리보기' });
  await expect(preview).toContainText('가입 급증 확인 필요');
  await preview.getByRole('button', { name: '닫기', exact: true }).click();

  expect(await notificationCall(page, 'PUT', 'notification-templates/', () => editModal.getByRole('button', { name: '저장', exact: true }).click())).toBe(200);
  await expect(editModal).toHaveCount(0);
  await mailRow.getByRole('button', { name: '템플릿 편집' }).click();
  await expect(editModal.getByRole('textbox', { name: '제목' })).toHaveValue('[{app_name}] {provider_label} 가입 급증 확인 필요');
  await editModal.locator('.modal-footer-bar') /* 고정 하단 */.getByRole('button', { name: '취소', exact: true }).click();
  await expect(editModal).toHaveCount(0);

  await card.getByRole('button', { name: '기본값으로 되돌리기' }).click();
  const resetModal = page.getByRole('dialog', { name: '기본값으로 복원' });
  await expect(resetModal).toContainText(SURGE_NAME);
  expect(await notificationCall(page, 'POST', `notification-definitions/${original.id}/reset`, () => resetModal.getByRole('button', { name: '복원' }).click())).toBe(200);
  await expect(resetModal).toHaveCount(0);
  const mail = (await surgeDefinition()).templates.find((t) => t.channel === 'mail');
  expect(mail?.subject.ko).toBe(DEFAULT_SUBJECT);
});

/**
 * 정책 탭의 두 알림 기준 칸 아래 링크가 알림 탭으로 옮긴다.
 *
 * @scenario surface=policy_tab, notification_action=policy_link
 * @effects policy_threshold_links_to_notifications_tab
 */
test('threshold fields on the policy tab link to the notifications tab', async ({ page }) => {
  await authenticatePage(page, admin);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  await page.goto(`${SETTINGS}?tab=policy`);
  await expect(page.locator('#notification_link_provider_failure_streak_threshold')).toBeVisible();
  await page.locator('#notification_link_signup_surge_threshold_per_hour').click();
  await expect(page).toHaveURL(/tab=notifications/);
  await expect(page.locator('[id^="sl_notification_definition_"]')).toHaveCount(5);
});

/**
 * 템플릿이 없는 수단(웹훅 처리 실패의 메일)을 켜고 저장하면 코어가 빈 메일 템플릿을 만든다 — 그 줄에 꺼진 스위치와 [템플릿 편집],
 * 「채우고 켜야 발송」 안내가 선다. 비어 있는 채로 켜면 서버가 422 로 거절하고 스위치는 꺼진 채로 돌아온다.
 *
 * @scenario surface=notifications_tab, notification_action=channel_save|user_template_toggle
 * @effects user_template_created_on_channel_save, empty_user_template_toggle_rejected_and_reverted
 */
test('saving a channel without template creates an empty template that cannot be turned on empty', async ({ page }) => {
  await openNotifications(page, admin);
  const card = page.locator('[id^="sl_notification_definition_"]').filter({ hasText: WEBHOOK_NAME });
  await card.getByRole('switch', { name: `${WEBHOOK_NAME} / 메일`, exact: true }).click();
  await expect(card).toContainText('이 수단의 템플릿이 없어 발송되지 않습니다');
  const save = card.locator('[id^="sl_save_channels_"]');
  const [put] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/admin/notification-definitions/') && r.request().method() === 'PUT'),
    save.click(),
  ]);
  expect(put.status()).toBe(200);

  const mailTemplate = card.getByRole('switch', { name: `${WEBHOOK_NAME} / 메일 템플릿 사용`, exact: true });
  await expect(mailTemplate).toHaveCount(1);
  await expect(mailTemplate).not.toBeChecked();
  await expect(card).toContainText('빈 템플릿이 만들어졌습니다. 내용과 받는 사람을 채운 뒤 켜야 이 수단으로 발송됩니다.');
  await expect(card).not.toContainText('이 수단의 템플릿이 없어 발송되지 않습니다');

  expect(await notificationCall(page, 'PATCH', 'notification-templates/', () => mailTemplate.click())).toBe(422);
  await expect(page.getByText('받는 사람을 먼저 정해 저장한 뒤에 켤 수 있습니다', { exact: false }).first()).toBeVisible();
  await expect(card.getByRole('switch', { name: `${WEBHOOK_NAME} / 메일 템플릿 사용`, exact: true })).not.toBeChecked();
});

/**
 * 플러그인 설정은 볼 수 있지만 알림 설정 권한이 없는 관리자는 403 을 받고, 빈 목록 대신 권한 안내를 본다.
 *
 * @scenario surface=notifications_tab, actor=viewer
 * @effects notifications_tab_forbidden_without_permission
 */
test('viewer without notification permission sees the permission notice', async ({ page }) => {
  const viewer = issueScopedToken('sirsoft-social_login.settings.view', 'core.plugins.read');
  expect(await openNotifications(page, viewer)).toBe(403);
  await expect(page.locator('#sl_notification_forbidden')).toContainText('알림을 볼 권한이 없습니다');
  await expect(page.locator('#sl_notification_empty')).toHaveCount(0);
  await expect(page.locator('[id^="sl_notification_definition_"]')).toHaveCount(0);
});
