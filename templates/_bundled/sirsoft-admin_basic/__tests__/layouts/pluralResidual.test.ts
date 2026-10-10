// e2e:allow 사전·레이아웃 소스 단언 — 화면 검증은 en 화면 수 1·2 실측(R-A 결과 문서)으로 한다.
/**
 * en 사전의 단수·복수 잔여 정리 (7.1.0 R-A — P20 후속)
 *
 * 번역 엔진은 `count` 인자가 수이면 en 사전의 `키_one` 을 고른다(engine-v1.78.7). 그래서
 * - 「user(s)」 같은 `(s)` 표기는 기본 키 = 복수형, `_one` = 단수형으로 나눈다.
 * - `{{count}}` 뒤에 복수 명사가 오는 문구는 `_one` 을 둔다(늘 2 이상이거나 「or more」 뒤처럼 복수가 맞는 자리만 예외).
 * - 인자 이름이 `count` 가 아닌 문구(`{{days}} days`·`{updated} users`)는 레이아웃·부품이 같은 값을 `count` 로도 넘긴다.
 * - 수 두 개가 한 문장에 든 문구(방문·행동 수 등)는 수와 명사가 맞물리지 않는 꼴로 바꾼다.
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const ROOT = path.resolve(__dirname, '../..');
const read = (rel: string): string => fs.readFileSync(path.resolve(ROOT, rel), 'utf-8');

function flat(o: Record<string, unknown>, p = '', out: Record<string, string> = {}): Record<string, string> {
  for (const [k, v] of Object.entries(o)) {
    const kp = p ? `${p}.${k}` : k;
    if (v && typeof v === 'object') flat(v as Record<string, unknown>, kp, out);
    else if (typeof v === 'string') out[kp] = v;
  }
  return out;
}

const EN_DIR = path.resolve(ROOT, 'lang/partial/en');
const enFiles = fs.readdirSync(EN_DIR).filter((f) => f.endsWith('.json'));
const en: Record<string, string> = {};
for (const f of enFiles) Object.assign(en, flat(JSON.parse(fs.readFileSync(path.join(EN_DIR, f), 'utf-8')), f.replace(/\.json$/, '')));

const NOT_NOUN = /^(is|was|has|does|this|its|us|as|yes|plus|status|always|across|less|address|access|success|process|progress|news|thus|previous|ago|remains|exceeds|matches|requires|contains|includes|uses|selected)$/i;
const COUNT_THEN_PLURAL = /\{\{?\s*count\s*\}?\}\s+(?:[A-Za-z-]+\s+){0,2}?([A-Za-z]+s)\b/g;

/** 수가 늘 2 이상이거나 복수가 문법상 맞는 자리 — 이유와 함께 둔다 */
const ALLOW_PLURAL_ONLY: Record<string, string> = {
  'auth.password_rules.repeated': '「{{count}} or more times」 — or more 뒤는 복수',
  'auth.password_rules.sequential': '「{{count}} or more characters」 — or more 뒤는 복수',
};

describe('admin_basic en 사전 — 단수·복수', () => {
  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('`(s)`·`(es)`·`(ies)` 표기가 없다', () => {
    const left = Object.entries(en).filter(([, v]) => /[A-Za-z]\((?:s|es)\)|\(ies\)/.test(v)).map(([k]) => k);
    expect(left).toEqual([]);
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('`{{count}}` 뒤에 복수 명사가 오는 문구는 `_one` 이 있다', () => {
    const missing: string[] = [];
    for (const [k, v] of Object.entries(en)) {
      if (/_(one|two|few|many|zero)$/.test(k) || k in ALLOW_PLURAL_ONLY) continue;
      const nouns = [...v.matchAll(COUNT_THEN_PLURAL)].map((m) => m[1]).filter((n) => !NOT_NOUN.test(n));
      if (nouns.length > 0 && !(`${k}_one` in en)) missing.push(k);
    }
    expect(missing).toEqual([]);
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it.each([
    ['admin.users.modals.bulk_activate_confirm', 'Activate {{count}} user.'],
    ['admin.modules.check_updates_success', 'Update check complete ({{count}} update available)'],
    ['admin.templates.layout_edit.draft.leave_confirm', 'You have {{count}} unsaved edit. Leaving this screen discards it. Continue?'],
    ['admin.users.modals.bulk_status_partial', 'Changed the status of {updated} user. {failed} could not be changed — {reason}'],
    ['admin.analytics.alerts.archive_stale', 'Archiving has been stalled for {{hours}} hour (last {{at}}). Check the scheduler (cron) or archive now.'],
    ['admin.analytics.visitor_detail.visit_summary', '{{pages}} page · {{minutes}}m {{seconds}}s'],
    ['admin.analytics.visitor_detail.card.ip_masked', 'Masked {octets} octet'],
    ['attachment.upload_limit', 'Max {{maxFiles}} file, {{maxSize}}MB'],
    ['auth.login.password_change.reason_remind', 'Your password has expired (on {{date}}). Change it now, or choose "Change later" to be reminded again in {{days}} day.'],
    ['admin.settings.notification_definitions.recipients.roles_selected_count', '{count} role selected'],
  ])('%s_one = 단수형', (key, one) => {
    expect(en[`${key}_one`]).toBe(one);
  });

  // @scenario count_form=numeric
  // @effects number_free_wording
  it('수 두 개가 맞물리는 문구는 수와 명사가 엇갈리지 않는 꼴이다', () => {
    expect(en['admin.analytics.privacy_requests.results_summary']).toBe('Visits {{visits}} · Actions {{actions}}');
    expect(en['admin.templates.layout_edit.search.result_summary']).toBe('Matches: {{matches}} · Files: {{files}}');
    expect(en['admin.settings.security.password_expiry.run.count_value']).toBe('Members in this run: {{count}} of {{total}}');
    for (const k of ['admin.users.pagination_info', 'admin.modules.pagination_info', 'admin.templates.pagination_info']) {
      expect(en[k]).toBe('Showing {{from}}-{{to}} of {{total}}');
    }
  });

  // @scenario count_form=numeric
  // @effects number_free_wording
  it('O-Q2-2: 업데이트 호환 경고가 유형 이름을 문장 가운데 대문자로 넣지 않는다', () => {
    expect(en['extensions.update_modal.compat_warning_message']).toBe('This update requires core version {{required}} (current: {{installed}})');
  });
});

describe('admin_basic 호출부 — count 가 아닌 수 인자도 count 로 넘긴다', () => {
  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it('회원 일괄 처리 부분 성공 토스트 4곳이 updated 값을 count 로도 넘긴다', () => {
    const src = read('layouts/admin_user_list.g7.html');
    const hits = src.match(/_partial\|updated=' \+ \(response\.data\?\.updated_count \?\? 0\) \+ '\|count=' \+ \(response\.data\?\.updated_count \?\? 0\)/g) ?? [];
    expect(hits.length).toBe(4);
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it.each([
    ['layouts/partials/admin_analytics/_partial_status_alerts.g7.html', '$t:admin.analytics.alerts.archive_stale|count={{Math.floor((_computed.archiveFreshness?.lag_minutes ?? 0) / 60)}}'],
    ['layouts/admin_analytics_visitor_detail.g7.html', '$t:admin.analytics.visitor_detail.card.ip_masked|count={{analytics_visitor?.data?.ip_mask_octets ?? 0}}'],
    ['layouts/admin_analytics_visitor_detail.g7.html', '$t:defer:admin.analytics.visitor_detail.visit_summary|count={{visit.pageviews ?? 0}}'],
    ['layouts/admin_login.g7.html', '$t:auth.login.password_change.reason_remind|count={{_local.passwordChange?.postpone_days ?? 0}}'],
    ['layouts/partials/admin_settings/_modal_notification_template_form.g7.html', '$t:admin.settings.notification_definitions.recipients.roles_selected_count|count={{(rcpt.value ?? []).length}}'],
    ['layouts/partials/admin_settings/_modal_notification_template_form.g7.html', '$t:admin.settings.notification_definitions.recipients.users_selected_count|count={{(rcpt.value ?? []).length}}'],
  ])('%s — %s', (file, token) => {
    expect(read(file)).toContain(token);
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it('첨부 안내·초과 알림 부품이 최대 개수를 count 로도 넘긴다', () => {
    expect(read('src/components/composite/FileUploader/FileDropZone.tsx')).toContain("t('attachment.upload_limit', { maxFiles, maxSize, count: maxFiles })");
    expect(read('src/components/composite/FileUploader/useFileUploader.ts')).toContain("t('attachment.upload_limit_exceeded', { max: maxFiles, count: maxFiles })");
  });
});
