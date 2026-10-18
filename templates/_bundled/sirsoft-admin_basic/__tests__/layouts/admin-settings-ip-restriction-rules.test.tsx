/**
 * @file admin-settings-ip-restriction-rules.test.tsx
 * @description 환경설정 > 보안 > IP 접근 제한 규칙 — 스위치 이름과 지난 저장 오류 정리 (7.1.0 실측 O-B6·O-B7)
 *
 * - 규칙 표의 「사용」 스위치는 라벨이 열 머리글에만 있어 스위치 자체에는 이름이 없었다(이름 없는 switch).
 * - 잘못된 IP 로 저장해 422 가 나면 규칙 표 아래 오류가 뜨는데, 규칙을 고치거나(창 열기·사용 토글·삭제) 창을
 *   다시 열어도 다음 저장 전까지 그대로 남았다. 규칙 창 안의 빈 칸 안내도 칸을 고쳐도 남았다.
 */

import { describe, it, expect } from 'vitest';

const layout = require('../../layouts/admin_settings.json');
const card = require('../../layouts/partials/admin_settings/_tab_security_ip_restriction.json');
const modal = require('../../layouts/partials/admin_settings/_modal_ip_restriction_rule_form.json');

interface AnyJson { [k: string]: any }

function nodes(root: any): AnyJson[] {
  const out: AnyJson[] = [];
  const walk = (n: any) => {
    if (!n || typeof n !== 'object') return;
    if (Array.isArray(n)) { n.forEach(walk); return; }
    out.push(n);
    Object.values(n).forEach(walk);
  };
  walk(root);
  return out;
}

const CLEAR = '{{_computed.errorsWithoutIpRules}}';

/** `{{...}}` 계산식을 주어진 _local 로 평가한다. */
function evalComputed(expr: string, local: AnyJson): any {
  const body = expr.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function('_local', `return (${body});`)(local);
}

describe('IP 접근 제한 규칙 — 스위치 이름', () => {
  /**
   * @scenario surface=rules_table
   * @effects rules_table_switch_named
   */
  it('규칙 표의 사용 스위치는 보이는 라벨 없이 「사용」 이름을 갖고, 카드 판은 종전대로 라벨을 단다', () => {
    const toggles = nodes(card).filter((n) => n.name === 'Toggle' && String(n.props?.checked ?? '').includes('rule.enabled'));
    expect(toggles).toHaveLength(2);

    const table = toggles.find((t) => t.props?.ariaLabel);
    expect(table?.props?.ariaLabel).toBe('$t:admin.settings.security.ip_restriction.col_enabled');
    expect(table?.props?.label).toBeUndefined();

    const mobile = toggles.find((t) => !t.props?.ariaLabel);
    expect(mobile?.props?.label).toBe('$t:admin.settings.security.ip_restriction.col_enabled');
  });
});

describe('IP 접근 제한 규칙 — 지난 저장 오류 정리', () => {
  /**
   * @scenario surface=rules_table
   * @effects stale_rule_errors_cleared_on_rule_change
   */
  it('규칙 몫만 뺀 오류 계산값 — 규칙 오류만 있으면 null, 다른 칸 오류는 남긴다', () => {
    const expr = layout.computed.errorsWithoutIpRules as string;
    expect(expr).toBeTruthy();

    expect(evalComputed(expr, { errors: null })).toBeNull();
    expect(evalComputed(expr, { errors: { 'security.ip_restriction_rules.0.ips.0': ['bad'] } })).toBeNull();
    expect(
      evalComputed(expr, {
        errors: { 'security.ip_restriction_rules.0.ips.0': ['bad'], 'security.admin_allowed_ips': ['lock'] },
      })
    ).toEqual({ 'security.admin_allowed_ips': ['lock'] });
    expect(evalComputed(expr, { errors: '문자열 오류' })).toBe('문자열 오류');
  });

  /**
   * @scenario surface=rules_table
   * @effects stale_rule_errors_cleared_on_rule_change
   */
  it('사용 토글·삭제(표·카드)는 규칙 배열을 고치면서 규칙 오류를 함께 지운다', () => {
    const writes = nodes(card).filter(
      (n) => n.handler === 'setState' && n.params?.target === 'local' && 'form.security.ip_restriction_rules' in (n.params ?? {})
    );
    expect(writes).toHaveLength(4);
    for (const w of writes) expect(w.params.errors).toBe(CLEAR);
  });

  /**
   * @scenario surface=rules_table
   * @effects stale_rule_errors_cleared_on_rule_change
   */
  it('규칙 창을 여는 세 자리(추가·표 편집·카드 편집)는 먼저 규칙 오류를 지운다', () => {
    const openers = nodes(card).filter(
      (n) => n.handler === 'sequence' && JSON.stringify(n).includes('"ip_restriction_rule_form_modal"') && JSON.stringify(n).includes('openModal')
    );
    expect(openers).toHaveLength(3);
    for (const seq of openers) {
      const acts: AnyJson[] = seq.actions ?? seq.params?.actions ?? [];
      expect(acts[0]?.handler).toBe('setState');
      expect(acts[0]?.params?.target).toBe('local');
      expect(acts[0]?.params?.errors).toBe(CLEAR);
    }
  });

  /**
   * @scenario surface=rule_modal
   * @effects rule_modal_error_cleared_on_input
   */
  it('규칙 창의 패턴·허용 IP 를 고치면 지난 「적용」의 빈 칸 안내를 지운다', () => {
    const writes = nodes(modal).filter((n) => n.handler === 'setState' && n.params?.target === 'global');
    const pattern = writes.find((w) => 'ip_restriction_rule_form_modal.form.pattern' in w.params);
    const ips = writes.find((w) => 'ip_restriction_rule_form_modal.form.ips' in w.params);
    expect(pattern?.params['ip_restriction_rule_form_modal.error']).toBeNull();
    expect(ips?.params['ip_restriction_rule_form_modal.error']).toBeNull();
  });
});
