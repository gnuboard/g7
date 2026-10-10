/**
 * E2E: 마케팅 동의 설정 — 채널 목록 이름, 채널 추가 창 항목명 안내 시점, 시스템 채널 삭제 거절
 *
 * 검증 대상(브라우저에서만 잡히는 축):
 *  1. 채널 추가 창을 막 열었을 때는 빨간 항목명 안내가 없고, 항목명 칸을 떠나거나 이름 없이 확정을 누를 때만 뜬다
 *     (Vitest pluginSettingsChannelSave 는 레이아웃 식과 액션을 엔진에 태워 보고, 여기서는 실제 포커스 이동과 클릭을 본다)
 *  2. 설정 화면 채널 목록의 시스템 채널 이름이 저장된 맵이 아니라 화면 언어 번역으로 나온다
 *     (pluginSettingsChannelLabelLocale 의 대상. 새로 추가되는 언어(ja 언어팩) 축은 언어팩을 켜야 해 사이트 전역 상태를
 *      바꾸므로 spec 에서 다루지 않는다 — ko 화면에서 번역 키 경로가 실제로 그려지는지만 확인한다)
 *  3. 시스템 채널을 뺀 채널 저장 요청은 시스템 채널 보호 사유로만 422 이고 `blocked_channel_keys` 가 없다
 *
 * 이 spec 은 설정을 저장하지 않는다(창은 취소로 닫고, 3번 요청은 422 로 거절된다).
 */
import { test, expect, authenticatePage } from '../../fixtures/marketing-auth';

const SETTINGS_URL = '/admin/plugins/sirsoft-marketing/settings';
const LABEL_REQUIRED = '사이트 기본 언어의 항목명을 입력하세요.';

test.describe('마케팅 동의 설정 — 채널', () => {
  // @scenario case=add_channel
  // @effects add_modal_label_hint_after_touch, add_modal_blocks_invalid_input
  test('채널 추가 창은 입력 전에는 항목명 안내가 없고, 칸을 떠나거나 확정을 누를 때만 안내한다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const rowKeys = async () => page.locator('div.grid.grid-cols-12 span.font-mono').allInnerTexts();
    const before = await rowKeys();

    await page.getByRole('button', { name: '채널 추가' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    const hint = dialog.locator('p.text-error-soft', { hasText: LABEL_REQUIRED });
    const confirm = dialog.getByRole('button', { name: '저장', exact: true });

    // 열자마자: 안내 없음, 키가 비어 확정은 잠김
    await expect(hint).toHaveCount(0);
    await expect(confirm).toBeDisabled();

    // 키만 입력: 아직 안내 없음, 확정은 눌린다
    const inputs = dialog.locator('input[type="text"]');
    await inputs.nth(0).fill('e2e_label_hint');
    await inputs.nth(0).press('Tab');
    await expect(hint).toHaveCount(0);
    await expect(confirm).toBeEnabled();

    // 이름 없이 확정: 목록에 붙지 않고 창이 남아 안내가 뜬다
    await confirm.click();
    await expect(hint).toHaveCount(1);
    await expect(dialog).toBeVisible();
    expect(await rowKeys()).toEqual(before);

    // 취소 뒤 다시 열면 안내가 초기화된다
    await dialog.getByRole('button', { name: '취소', exact: true }).click();
    await page.getByRole('button', { name: '채널 추가' }).click();
    await expect(page.getByRole('dialog').locator('p.text-error-soft', { hasText: LABEL_REQUIRED })).toHaveCount(0);

    // 항목명 칸에 들어갔다가 떠나면 안내가 뜨고, 이름을 넣으면 사라진다
    const reopened = page.getByRole('dialog');
    const labelInput = reopened.locator('input[type="text"]').nth(1);
    await labelInput.click();
    await expect(reopened.locator('p.text-error-soft', { hasText: LABEL_REQUIRED })).toHaveCount(0);
    await page.keyboard.press('Tab');
    await expect(reopened.locator('p.text-error-soft', { hasText: LABEL_REQUIRED })).toHaveCount(1);
    await labelInput.fill('E2E 채널');
    await expect(reopened.locator('p.text-error-soft', { hasText: LABEL_REQUIRED })).toHaveCount(0);

    // 저장하지 않고 닫는다
    await reopened.getByRole('button', { name: '취소', exact: true }).click();
    expect(await rowKeys()).toEqual(before);
  });

  // @scenario case=saved
  // @effects settings_list_uses_label_key
  test('채널 목록의 시스템 채널 이름은 화면 언어 번역으로 나온다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const systemRow = page.locator('div.grid.grid-cols-12', { has: page.locator('span.font-mono', { hasText: /^email_subscription$/ }) }).first();
    await expect(systemRow).toBeVisible({ timeout: 15_000 });
    await expect(systemRow.locator('span.text-heading').first()).toHaveText('광고성 이메일 수신');
    // 원문 키가 화면에 새지 않는다
    const listText = await page.locator('div.grid.grid-cols-12').allInnerTexts();
    expect(listText.join('|')).not.toContain('sirsoft-marketing.');
  });

  // @scenario case=field_validation
  // @effects field_errors_shown_without_deactivate_modal
  test('시스템 채널을 뺀 채널 저장 요청은 시스템 채널 보호 사유로만 422 이고 blocked_channel_keys 가 없다', async ({ page, settingsToken }) => {
    // 지금 있는 일반 채널은 그대로 싣고 시스템 채널만 뺀다 — 일반 채널을 빼면 그 채널의 동의 회원 판정이 섞인다
    const publicSettings = await (await page.request.get('/api/plugins/sirsoft-marketing/settings', { headers: { Accept: 'application/json' } })).json();
    const regular = (publicSettings.data?.channels ?? [])
      .filter((ch: { key: string }) => ch.key !== 'email_subscription')
      .map((ch: { key: string; label_i18n?: Record<string, string>; enabled?: boolean }) => ({
        key: ch.key,
        label: { ko: ch.label_i18n?.ko || ch.key, en: ch.label_i18n?.en || ch.key },
        page_slug: '',
        enabled: ch.enabled ?? true,
        is_system: false,
      }));

    const response = await page.request.put('/api/plugins/sirsoft-marketing/admin/channels', {
      headers: { Authorization: `Bearer ${settingsToken}`, Accept: 'application/json' },
      data: { channels: regular },
    });

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body.errors?.blocked_channel_keys).toBeUndefined();
    expect(body.errors?.channels).toEqual(['시스템 채널(email_subscription)은 삭제할 수 없습니다.']);
  });
});
