// e2e:allow 사전·레이아웃 소스 단언 — 화면 검증은 en 화면 실측(R-A 결과 문서)으로 한다.
/**
 * GDPR en 사전의 단수·복수 정리 (7.1.0 R-A — P20 후속)
 *
 * 「{renewed} active item(s)」 처럼 적던 문구를 기본 키 = 복수형·`_one` = 단수형으로 나누고,
 * 레이아웃이 갱신 수를 `count` 로도 넘겨 번역 엔진(engine-v1.78.7)이 단수형을 고르게 한다.
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const ROOT = path.resolve(__dirname, '../../../..');
const read = (rel: string): string => fs.readFileSync(path.resolve(ROOT, rel), 'utf-8');

function flat(o: Record<string, unknown>, p = '', out: Record<string, string> = {}): Record<string, string> {
  for (const [k, v] of Object.entries(o)) {
    const kp = p ? `${p}.${k}` : k;
    if (v && typeof v === 'object') flat(v as Record<string, unknown>, kp, out);
    else if (typeof v === 'string') out[kp] = v;
  }
  return out;
}
const en = flat(JSON.parse(read('resources/lang/en.json')));

describe('GDPR en 사전 — 단수·복수', () => {
  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('`(s)` 표기가 없다', () => {
    expect(Object.entries(en).filter(([, v]) => /[A-Za-z]\((?:s|es)\)|\(ies\)/.test(v)).map(([k]) => k)).toEqual([]);
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('갱신 완료 문구에 단수형이 있다', () => {
    expect(en['mypage.privacy.renew_all_success']).toBe('Renewed {renewed} active items to the new policy version.');
    expect(en['mypage.privacy.renew_all_success_one']).toBe('Renewed {renewed} active item to the new policy version.');
    expect(en['consent.renew_all_success_one']).toBe('{renewed} active consent item renewed to the new policy version.');
  });
});

describe('GDPR 호출부 — 갱신 수를 count 로도 넘긴다', () => {
  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it.each(['resources/extensions/cookie_banner.g7.html', 'resources/extensions/mypage_privacy_tab.g7.html'])('%s', (file) => {
    expect(read(file)).toContain('$t:sirsoft-gdpr.mypage.privacy.renew_all_success|count={{response?.data?.renewed ?? 0}}|renewed={{response?.data?.renewed ?? 0}}');
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it('알림 수신자 고르기 「{count} roles selected」가 고른 수를 count 로 넘긴다', () => {
    const src = read('resources/layouts/admin/partials/plugin_settings/_modal_notification_template_edit.g7.html');
    expect(src).toContain('$t:admin.settings.notification_definitions.recipients.roles_selected_count|count={{(rcpt.value ?? []).length}}');
    expect(src).toContain('$t:admin.settings.notification_definitions.recipients.users_selected_count|count={{(rcpt.value ?? []).length}}');
  });
});
