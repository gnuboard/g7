/**
 * @file admin-role-form-menus.test.tsx
 * @description 역할 폼 — 「접근 가능 메뉴」 카드 (#708)
 *
 * 실제 레이아웃 JSON 을 읽어, 저장 본문의 menu_ids 식과 트리·안내의 조건 식을 그 식 그대로 평가한다.
 * - 메뉴 수정 권한이 없거나 관리자 역할이면 menu_ids 를 아예 싣지 않는다 (서버가 기존 할당을 그대로 둔다)
 * - 실을 때는 트리에 보이는(관리할 수 있는) 메뉴로만 거른다 — 관리 밖 메뉴의 기존 할당은 서버가 보존한다
 * - 메뉴 조회 권한이 없으면 트리 데이터소스의 403 을 삼켜 빈 트리 + 읽기 전용 안내로 둔다
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../layouts/admin_role_form.json'), 'utf-8'));

/** 레이아웃 트리에서 조건에 맞는 노드를 모두 모은다. */
function collect(node: any, match: (n: any) => boolean, found: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, found));
    return found;
  }
  if (node && typeof node === 'object') {
    if (match(node)) found.push(node);
    Object.values(node).forEach((value) => {
      if (value && typeof value === 'object') collect(value, match, found);
    });
  }
  return found;
}

/** `{{ ... }}` 식 하나를 주어진 문맥으로 평가한다 (엔진과 같은 JS 식). */
function evaluate(expression: string, context: Record<string, any>): any {
  const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(context), `return (${body});`)(...Object.values(context));
}

const tree = (canUpdate: boolean) => ({
  data: {
    data: [
      { id: 1, children: [] },
      { id: 10, children: [{ id: 11 }] },
    ],
    abilities: { can_update: canUpdate },
  },
});

const saveCall = () => collect(layout, (n) => n.handler === 'apiCall' && String(n.target ?? '').includes('/api/admin/roles'))
  .find((n) => n.params?.body && 'menu_ids' in n.params.body);
const menuIdsOf = (context: Record<string, any>) => evaluate(saveCall().params.body.menu_ids, context);

describe('역할 폼 — 접근 가능 메뉴 (#708)', () => {
  // @scenario entry=role_form_update, actor=super_admin, menu_shape=parent_with_children
  // @effects parent_auto_included
  it('메뉴 수정 권한이 있으면 고른 메뉴를 menu_ids 로 싣는다', () => {
    expect(saveCall()).toBeDefined();
    expect(menuIdsOf({ role_menus_tree: tree(true), _local: { form: { identifier: 'editor', menu_ids: [1, 10, 11] } } }))
      .toEqual([1, 10, 11]);
  });

  // @scenario entry=role_form_update, actor=scoped_admin, menu_shape=inactive_extension
  // @effects invisible_assignment_preserved
  it('트리에 없는(관리할 수 없는) 메뉴 ID 는 실어 보내지 않는다 — 복제 진입이 원본의 관리 밖 메뉴를 끌어오지 않게', () => {
    expect(menuIdsOf({ role_menus_tree: tree(true), _local: { form: { identifier: 'editor', menu_ids: [1, 77, 11] } } }))
      .toEqual([1, 11]);
  });

  // @scenario entry=role_form_update, actor=no_menu_permission, menu_shape=top_level
  // @effects omitted_field_keeps_assignment
  it('메뉴 수정 권한이 없으면 menu_ids 를 싣지 않아 서버가 기존 할당을 건드리지 않는다', () => {
    expect(menuIdsOf({ role_menus_tree: tree(false), _local: { form: { identifier: 'editor', menu_ids: [1] } } })).toBeUndefined();
    expect(menuIdsOf({ role_menus_tree: undefined, _local: { form: { identifier: 'editor', menu_ids: [1] } } })).toBeUndefined();
  });

  // @scenario entry=role_form_update, actor=super_admin, menu_shape=top_level
  // @effects admin_role_locked
  it('관리자 역할은 menu_ids 를 싣지 않고, 트리는 전부 잠그며 잠금 안내를 보인다', () => {
    const context = { role_menus_tree: tree(true), _local: { form: { identifier: 'admin', menu_ids: [] } } };
    const treeNode = collect(layout, (n) => n.id === 'menus_tree')[0];
    const lockedHint = collect(layout, (n) => n.id === 'menus_admin_locked_hint')[0];

    expect(menuIdsOf(context)).toBeUndefined();
    expect(treeNode.name).toBe('MenuAccessTree');
    expect(evaluate(treeNode.props.lockedAll, context)).toBe(true);
    expect(evaluate(lockedHint.if, context)).toBe(true);
  });

  // @scenario entry=role_form_update, actor=no_menu_permission, menu_shape=top_level
  // @effects menu_tree_readonly_without_permission
  it('메뉴 수정 권한이 없으면 트리는 읽기 전용이고 안내가 보이며, 조회 403 은 빈 트리로 삼킨다', () => {
    const context = { role_menus_tree: tree(false), _local: { form: { identifier: 'editor' } } };
    const treeNode = collect(layout, (n) => n.id === 'menus_tree')[0];
    const readonlyHint = collect(layout, (n) => n.id === 'menus_readonly_hint')[0];
    const source = (layout.data_sources ?? []).find((ds: any) => ds.id === 'role_menus_tree');

    expect(evaluate(treeNode.props.disabled, context)).toBe(true);
    expect(evaluate(readonlyHint.if, context)).toBe(true);
    expect(evaluate(readonlyHint.if, { ...context, role_menus_tree: tree(true) })).toBe(false);
    // 조회 403 은 대체값({ data: [] })으로 남는다 — 권한이 없다는 뜻이므로 안내가 보인다
    expect(evaluate(readonlyHint.if, { ...context, role_menus_tree: source.fallback })).toBe(true);
    // 아직 받기 전(값 없음)에는 권한을 모른다 — 최고관리자에게도 「권한이 없어 볼 수만 있습니다」 가 비치던 결함
    // (라이브 실측: 역할 수정 화면을 연 직후 안내가 보였다가 메뉴가 도착하면 사라졌다)
    expect(evaluate(readonlyHint.if, { ...context, role_menus_tree: undefined })).toBe(false);
    expect(source.endpoint).toBe('/api/admin/menus');
    expect(source.errorHandling['403'].handler).toBe('suppress');
    expect(source.fallback).toEqual({ data: [] });
  });

  // @scenario entry=role_form_create, actor=super_admin, menu_shape=top_level
  // @effects menu_ids_on_show_not_list
  it('트리의 체크 값은 폼의 menu_ids 를 따르고, 바꾸면 menu_ids 로 담는다', () => {
    const treeNode = collect(layout, (n) => n.id === 'menus_tree')[0];
    const change = collect(actionsOf(treeNode), (n) => n.handler === 'setState')[0];

    expect(evaluate(treeNode.props.value, { _local: { form: { menu_ids: [1, 10] } } })).toEqual([1, 10]);
    expect(evaluate(treeNode.props.value, { _local: {} })).toEqual([]);
    expect(JSON.stringify(change.params)).toContain('menu_ids');
  });
});
