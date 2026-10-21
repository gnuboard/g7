/**
 * @file admin-role-form-guest-scope.test.ts
 * @description 역할 폼 — 비회원 역할의 권한 범위 숨김·저장 정규화·안내 구조 회귀 테스트 (#789)
 *
 * 비회원은 글의 주인을 가릴 수 없어 범위(본인만·소유역할)가 적용되지 않는다. 그래서 역할 응답이
 * `supports_permission_scope === false` 를 실어 주는 수정 화면에서는
 * ① 트리가 범위 드롭다운을 그리지 않고(`scopeDisabled`) ② 저장 본문이 scope_type 을 null 로 비우며
 * ③ 그 이유를 안내한다. 세 곳 모두 `!!route?.id` 를 함께 걸어, 비회원 역할을 복제(`clone_from`)해
 * 만드는 새 역할(일반 역할)에서는 범위를 고를 수 있게 둔다.
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import roleForm from '../../layouts/admin_role_form.json';

const GUEST_EDIT = '!!route?.id && _local.form?.supports_permission_scope === false';

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

describe('역할 폼 — 비회원 역할 권한 범위 (#789)', () => {
  // @scenario role=guest, support=scope_supported
  // @effects scope_dropdown_hidden_for_guest_role
  it('권한 트리에 scopeDisabled 가 수정 모드 + supports_permission_scope === false 로 걸린다', () => {
    const tree = nodes.find((n) => n.name === 'PermissionTree');

    expect(tree, 'PermissionTree 노드를 찾지 못했습니다.').toBeDefined();
    expect(tree.props?.scopeDisabled).toBe(`{{${GUEST_EDIT}}}`);
  });

  // @scenario role=guest, support=scope_supported
  // @effects guest_role_save_nulls_scope_type
  it('저장 본문은 같은 조건에서 권한마다 scope_type 을 null 로 비운다', () => {
    const form = nodes.find((n) => n.id === 'role_form');
    const api = flattenActions(form?.actions).find((a) => a.handler === 'apiCall');
    const permissions = String(api?.params?.body?.permissions ?? '');

    expect(permissions).toContain(GUEST_EDIT);
    expect(permissions).toContain('scope_type: null');
    // 일반 역할은 고른 값을 그대로 보낸다
    expect(permissions).toContain(': (_local.form.permission_values ?? [])');
  });

  // @scenario role=general, support=scope_supported
  // @effects guest_scope_hint_shown_only_in_edit, general_role_scope_dropdown_unchanged
  it('비회원 안내는 같은 조건(수정 모드)에서만 보인다 — 복제로 만드는 새 역할에는 뜨지 않는다', () => {
    const hint = nodes.find((n) => n.id === 'guest_scope_unsupported_hint');

    expect(hint, '비회원 범위 안내 노드를 찾지 못했습니다.').toBeDefined();
    expect(hint.if).toBe(`{{${GUEST_EDIT}}}`);
    expect(JSON.stringify(hint)).toContain('$t:admin.roles.form.guest_scope_unsupported_hint');
  });
});
