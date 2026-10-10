/**
 * @file admin-role-form-password-expiry.test.ts
 * @description 역할 폼 「비밀번호 만료」(따름/적용/제외) 구조 회귀 테스트 (#736 D6·D10)
 *
 * 저장 본문은 칸을 명시 나열하므로 선택만 두고 본문에 빠뜨리면 화면에서 고른 값이 저장되지 않는다.
 * 등록 기본값(init-local·생성 init)은 「사이트 설정 따름」이다.
 *
 * @since engine-v1.77.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import roleForm from '../../layouts/admin_role_form.json';

function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  return [node, ...children];
}

function flattenActions(actions: any): any[] {
  if (!actions) return [];
  return asArray<any>(actions).flatMap((a) => [a, ...flattenActions(a.actions), ...flattenActions(a.onSuccess), ...flattenActions(a.onError)]);
}

const nodes = flatten((roleForm as any).slots?.content ?? (roleForm as any).components);

describe('역할 폼 — 비밀번호 만료 (#736)', () => {
  /**
   * @scenario role_policy=apply
   * @effects role_expiry_policy_saved
   */
  it('「비밀번호 만료」 선택이 기본 정보에 있고 세 값을 고른다', () => {
    const field = nodes.find((n) => n.id === 'password_expiry_policy_field');
    const select = flatten(field).find((n) => n.name === 'Select');
    expect(select?.props?.name).toBe('password_expiry_policy');
    const values = asArray<any>(select?.props?.options);
    expect(JSON.stringify(values)).toContain('inherit');
    expect(JSON.stringify(values)).toContain('apply');
    expect(JSON.stringify(values)).toContain('exempt');
  });

  /**
   * @scenario role_policy=apply
   * @effects role_expiry_policy_saved
   */
  it('저장 본문에 password_expiry_policy 가 실린다', () => {
    const form = nodes.find((n) => n.id === 'role_form');
    const api = flattenActions(form?.actions).find((a) => a.handler === 'apiCall');
    expect(api?.params?.body?.password_expiry_policy).toBe("{{_local.form.password_expiry_policy ?? 'inherit'}}");
  });

  /**
   * @scenario role_policy=inherit
   * @effects role_expiry_policy_defaults_to_inherit
   */
  it('등록 기본값은 「사이트 설정 따름」이다(init-local 과 생성 init 둘 다)', () => {
    const json = roleForm as any;
    expect(JSON.stringify(json.state ?? json.init_local ?? json.initLocal ?? json)).toContain('"password_expiry_policy":"inherit"');
    const init = asArray<any>(json.init_actions);
    const create = init.find((a) => a.handler === 'setState' && a.params?.form && 'password_expiry_policy' in a.params.form);
    expect(create?.params?.form?.password_expiry_policy).toBe('inherit');
  });
});
