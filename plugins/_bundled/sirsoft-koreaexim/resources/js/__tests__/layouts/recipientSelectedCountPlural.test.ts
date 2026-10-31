// e2e:allow 레이아웃 소스 단언 — 화면 검증은 en 관리자 화면 실측(R-A 결과 문서)으로 한다.
/**
 * 알림 수신자 고르기 「{count} roles selected」가 고른 수를 count 로 넘긴다 (7.1.0 R-A — P20 후속)
 *
 * 드롭다운은 번역된 문구의 `{count}` 를 스스로 채우므로, 번역 엔진(engine-v1.78.7)이 1 일 때
 * 「1 role selected」를 고르려면 레이아웃이 같은 수를 `count` 인자로 넘겨야 한다.
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const src = fs.readFileSync(
  path.resolve(__dirname, '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.g7.html'),
  'utf-8',
);

describe('수출입은행 알림 템플릿 편집 — 수신자 고른 수 문구', () => {
  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it.each(['roles_selected_count', 'users_selected_count'])('%s 에 count 를 넘긴다', (key) => {
    expect(src).toContain(`$t:admin.settings.notification_definitions.recipients.${key}|count={{(rcpt.value ?? []).length}}`);
  });
});
