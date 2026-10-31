/**
 * E2E: 즉시 반영 알림 탭에서 템플릿이 없는 수단을 켜면 빈 템플릿이 생긴다 (7.2.0)
 *
 * 코어 「알림 설정」 탭은 수단 하위 탭마다 정의의 그 수단 템플릿 스위치를 즉시 반영한다. 그 수단 템플릿이 없는 정의에는
 * 스위치가 없어 운영자가 수단을 쓸 방법이 없었다. 이제 그 자리에 「템플릿 만들기」 스위치가 서고, 켜면 수단을 정의에 더해
 * 저장(PUT)하며 코어가 빈 템플릿(꺼짐·운영자 생성)을 만든다. 비어 있는 채로 켜면 서버가 422 로 거절하고 스위치는 저장값으로
 * 돌아온다. 끝나면 만든 행과 수단 변경을 되돌린다(동기화 문맥이라 수정 표식을 남기지 않는다).
 *
 * @effects immediate_tab_creates_template_on_switch, empty_user_template_toggle_rejected, rejected_toggle_reverts_switch
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import { execFileSync } from 'node:child_process';

test.describe.configure({ mode: 'serial' });

/** 메일 템플릿만 선언된 코어 정의 — 사이트내 알림 하위 탭에서 템플릿이 없다 */
const TYPE = 'analytics_weekly_report';
const URL = `/admin/settings?tab=notification_definitions&channel=database&search=${TYPE}`;

/**
 * 시험이 만든 운영자 템플릿을 지우고 정의 발송 수단을 시작 전 값으로 되돌립니다.
 *
 * @param channels 되돌릴 발송 수단
 */
function restore(channels: string[]): void {
  const code = [
    `$d = App\\Models\\NotificationDefinition::where('type', '${TYPE}')->first();`,
    `App\\Models\\NotificationTemplate::where('definition_id', $d->id)->where('is_user_created', true)->get()->each->delete();`,
    `app()->instance('user_overrides.seeding', true);`,
    `$d->update(['channels' => ${JSON.stringify(channels).replace(/"/g, "'")}, 'user_overrides' => null]);`,
    `app()->forgetInstance('user_overrides.seeding');`,
  ].join(' ');
  execFileSync('php', ['artisan', 'tinker', '--execute', code], { encoding: 'utf8' });
}

/**
 * 정의의 발송 수단을 읽습니다.
 *
 * @returns 발송 수단 목록
 */
function currentChannels(): string[] {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', `echo json_encode(App\\Models\\NotificationDefinition::where('type', '${TYPE}')->value('channels'));`], { encoding: 'utf8' });
  return JSON.parse(out.trim().split(/\r?\n/).pop() ?? '[]');
}

let original: string[];

test.beforeAll(() => {
  original = currentChannels();
  restore(original);
});

test.afterAll(() => {
  restore(original);
});

/**
 * 템플릿 없는 수단의 스위치를 켜면 빈 템플릿이 생기고, 비어 있는 채로 켜면 422 로 거절되며 스위치가 돌아온다.
 *
 * @scenario surface=immediate_tab, action=enable_channel|toggle_on, template=missing|user_created_empty
 * @effects immediate_tab_creates_template_on_switch, empty_user_template_toggle_rejected, rejected_toggle_reverts_switch
 */
test('switch on a channel without template creates an empty template that cannot be turned on empty', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  await page.goto(URL);

  const card = page.locator('#notif_definitions_list > *').filter({ hasText: TYPE });
  await expect(card).toContainText('이 채널에 대한 템플릿이 없습니다.');
  const sw = card.getByRole('switch');
  await expect(sw).toHaveCount(1);
  await expect(sw).not.toBeChecked();

  const [put] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/admin/notification-definitions/') && r.request().method() === 'PUT'),
    sw.click(),
  ]);
  expect(put.status()).toBe(200);
  await expect(card).toContainText('빈 템플릿이 만들어졌습니다. 내용과 받는 사람을 채운 뒤 켜야 이 수단으로 발송됩니다.');
  await expect(card.getByRole('switch')).not.toBeChecked();
  expect(currentChannels()).toEqual(expect.arrayContaining([...original, 'database']));

  const [patch] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/toggle-active') && r.request().method() === 'PATCH'),
    card.getByRole('switch').click(),
  ]);
  expect(patch.status()).toBe(422);
  await expect(page.getByText('받는 사람을 먼저 정해 저장한 뒤에 켤 수 있습니다', { exact: false }).first()).toBeVisible();
  await expect(card.getByRole('switch')).not.toBeChecked();
});
