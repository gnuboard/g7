/**
 * @file notification-template-site-admin-email.test.ts
 * @description 개인정보 동의 플러그인 알림 템플릿 편집 창 사본 — 받는 사람 「사이트 관리자 이메일」
 *
 * 코어 알림 템플릿 편집 창에 수신자 규칙 `site_admin_email`(환경설정 > 일반의 관리자 이메일 한 곳, 메일만)이 생겼다.
 * 이 확장의 사본도 같은 코어 저장 API 를 쓰므로 같은 선택지와 안내를 가져야 한다 — 빠지면 코어에서 고른 규칙이
 * 이 창에서는 빈 선택칸으로 보이고, 정의 탭 요약에는 유형 코드가 그대로 보인다.
 */

import { describe, it, expect } from 'vitest';
import modal from '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.json';

type Json = Record<string, any>;

/** 조건에 맞는 노드를 모두 찾는다. */
function findAll(node: unknown, pred: (n: Json) => boolean, acc: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    for (const item of node) findAll(item, pred, acc);
    return acc;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (pred(obj)) acc.push(obj);
    for (const v of Object.values(obj)) findAll(v, pred, acc);
  }
  return acc;
}

describe('개인정보 동의 플러그인 알림 템플릿 편집 창 — 사이트 관리자 이메일 수신자', () => {
  /**
   * @scenario case=gdpr_modal_option
   * @effects site_admin_email_option_offered
   */
  it('수신자 유형 선택지에 사이트 관리자 이메일이 있다', () => {
    const selects = findAll(modal, (n) => typeof n.props?.options === 'string' && n.props.options.includes("value: 'specific_users'"));
    expect(selects).toHaveLength(1);
    expect(selects[0].props.options).toContain("{ value: 'site_admin_email', label: '$t:admin.settings.notification_definitions.recipients.types.site_admin_email' }");
  });

  /**
   * @scenario case=gdpr_modal_option
   * @effects site_admin_email_hint_shown
   */
  it('그 유형을 고르면 메일만 간다는 안내가 보인다', () => {
    const hints = findAll(modal, (n) => n.if === "{{rcpt.type === 'site_admin_email'}}");
    expect(hints).toHaveLength(1);
    expect(hints[0].text).toBe('$t:admin.settings.notification_definitions.recipients.site_admin_email_hint');
  });
});
