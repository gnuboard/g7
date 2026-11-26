/**
 * @file identity-policy-custom-checkpoint.test.ts
 * @description 쇼핑몰 설정 본인인증 정책 창 — 「사용자 지정」 범위 (7.2.1)
 *
 * - 활성 확장이 확장 지점을 선언했을 때만 「사용자 지정」 을 고를 수 있는 범위 선택이 보인다(이미 사용자 지정인 정책은 늘 보인다)
 * - 사용자 지정이면 대상 칸이 글자 입력이 아니라 확장 지점 목록이 된다
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const here = path.dirname(fileURLToPath(import.meta.url));
const modal: any = JSON.parse(fs.readFileSync(path.resolve(here, '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_identity_policy_form.json'), 'utf-8'));

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

function evaluate(expression: string, scope: Record<string, any>): any {
  const body = String(expression).replace(/^\{\{/, '').replace(/\}\}$/, '');
  const names = Object.keys(scope);
  return new Function(...names, `return (${body});`)(...names.map((n) => scope[n]));
}

const ctx = (checkpoints: any[], scope?: string) => ({
  identityCheckpoints: { data: { checkpoints } },
  _global: { identity_policy_form_modal: { form: { scope, target: '' } } },
  $t: (key: string) => key,
});
const scopeSelects = collect(modal, (n) => typeof n.if === 'string' && n.if.includes('identityCheckpoints') && n.if.includes("scope"));
const checkpointSelect = collect(modal, (n) => (n?.props?.['data-ref'] ?? n?.id) === 'identity_policy_checkpoint_select')[0];
const targetInput = collect(modal, (n) => typeof n.if === 'string' && n.if.includes("form?.scope !== 'custom'") && !n.if.includes('identityCheckpoints'))[0];

describe('쇼핑몰 설정 본인인증 정책 창 — 사용자 지정', () => {
  // @scenario case=identity_policy_modal_custom_option
  // @effects identity_custom_scope_offered_only_with_checkpoints
  it('확장 지점이 있으면 사용자 지정이 든 범위 선택을, 없으면 라우트·훅만 있는 선택을 보인다', () => {
    expect(scopeSelects.length).toBe(2);
    const withCustom = scopeSelects.find((n) => JSON.stringify(n).includes('"custom"'));
    const withoutCustom = scopeSelects.find((n) => n !== withCustom);
    expect(withCustom && withoutCustom).toBeTruthy();
    expect(JSON.stringify(withoutCustom)).not.toContain('"custom"');

    expect(evaluate(withCustom.if, ctx([{ key: 'a.b' }]))).toBe(true);
    expect(evaluate(withoutCustom.if, ctx([{ key: 'a.b' }]))).toBe(false);
    expect(evaluate(withCustom.if, ctx([]))).toBe(false);
    expect(evaluate(withoutCustom.if, ctx([]))).toBe(true);
    // 이미 사용자 지정인 정책은 선언이 사라져도 그대로 보인다
    expect(evaluate(withCustom.if, ctx([], 'custom'))).toBe(true);
  });

  // @scenario case=identity_policy_modal_checkpoint_target
  // @effects identity_custom_target_is_checkpoint_select
  it('사용자 지정이면 대상 칸은 확장 지점 목록이고, 아니면 글자 입력이다', () => {
    expect(evaluate(checkpointSelect.if, ctx([], 'custom'))).toBe(true);
    expect(evaluate(checkpointSelect.if, ctx([], 'route'))).toBe(false);
    expect(evaluate(targetInput.if, ctx([], 'route'))).toBe(true);
    expect(evaluate(targetInput.if, ctx([], 'custom'))).toBe(false);

    const options = evaluate(checkpointSelect.props.options, ctx([{ key: 'vendor.withdraw', name: '탈퇴' }], 'custom'));
    expect(options.map((o: any) => o.value)).toEqual(['', 'vendor.withdraw']);
    expect(options[1].label).toContain('vendor.withdraw');
  });
  // @scenario case=identity_policy_modal_unregistered_target
  // @effects identity_custom_unregistered_target_visible
  it('저장된 대상이 지금 선언되지 않은 지점이면 목록에 「연결된 확장 지점 없음 (키)」 로 남아 창에서 현재 대상이 보인다', () => {
    const withTarget = (checkpoints: any[], target: string) => ({
      identityCheckpoints: { data: { checkpoints } },
      _global: { identity_policy_form_modal: { form: { scope: 'custom', target } } },
      $t: (key: string) => key,
    });
    const missing = evaluate(checkpointSelect.props.options, withTarget([{ key: 'vendor.withdraw', name: '탈퇴' }], 'vendor.gone'));
    expect(missing.map((o: any) => o.value)).toEqual(['', 'vendor.withdraw', 'vendor.gone']);
    expect(missing[2].label).toContain('admin.identity.policies.checkpoint_missing');
    expect(missing[2].label).toContain('vendor.gone');
    // 등록된 지점이거나 비어 있으면 덧붙이지 않는다
    expect(evaluate(checkpointSelect.props.options, withTarget([{ key: 'vendor.withdraw', name: '탈퇴' }], 'vendor.withdraw')).length).toBe(2);
    expect(evaluate(checkpointSelect.props.options, withTarget([], '')).length).toBe(1);
  });
});
