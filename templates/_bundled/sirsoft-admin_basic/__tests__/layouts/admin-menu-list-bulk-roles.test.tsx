/**
 * @file admin-menu-list-bulk-roles.test.tsx
 * @description 메뉴 관리 — 「여러 개 선택」 일괄 역할 + 행마다 부여 역할 배지 (#708)
 *
 * 실제 파셜 JSON 을 읽어, 화면이 서버에 무엇을 보내고 무엇을 보이는지를 그 식 그대로 평가한다.
 * - 목록: 선택 모드·선택 ID·역할 이름표(roleOptions)가 페이지 상태·데이터소스에 묶여 있는가
 * - 일괄 판: 제거 모드에서는 관리자 역할을 고를 수 없고, 이미 고른 것에서도 빠지는가
 * - 확인 창: 본문·디바운스·성공 후 목록과 사이드바 재조회, 하위만 고른 부모의 자동 포함 안내
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const read = (relative: string) => JSON.parse(fs.readFileSync(path.resolve(__dirname, relative), 'utf-8'));

const layout = read('../../layouts/admin_menu_list.json');
const listPartial = read('../../layouts/partials/admin_menu_list/_panel_menu_list.json');
const detailPartial = read('../../layouts/partials/admin_menu_list/_panel_detail.json');
const panelPartial = read('../../layouts/partials/admin_menu_list/_panel_bulk_roles.json');
const confirmPartial = read('../../layouts/partials/admin_menu_list/_modal_bulk_roles_confirm.json');
const viewPartial = read('../../layouts/partials/admin_menu_list/_panel_view.json');

/** 부분 레이아웃 트리에서 조건에 맞는 노드를 모두 모은다. */
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

const ROLES = { data: { data: [
  { id: 1, identifier: 'admin', name: { ko: '관리자' } },
  { id: 7, identifier: 'editor', name: { ko: '편집자' } },
] } };

const MENUS = { data: { data: [
  { id: 10, children: [{ id: 11 }, { id: 12 }] },
  { id: 20, children: [] },
] } };

describe('메뉴 관리 — 일괄 역할·행 역할 배지 (#708)', () => {
  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
  // @effects menu_list_shows_assigned_roles
  it('목록은 활성 역할 목록을 이름표로 받아 행마다 부여 역할을 보인다', () => {
    const list = collect(listPartial, (n) => n.id === 'sortable_menu_list')[0];

    expect(list).toBeDefined();
    expect(list.props.roleOptions).toBe('{{roles?.data?.data ?? []}}');
    expect(evaluate(list.props.roleOptions, { roles: ROLES })).toHaveLength(2);
    expect(evaluate(list.props.roleOptions, { roles: undefined })).toEqual([]);
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=parent_with_children
  // @effects menu_list_selection_mode_disables_drag
  it('목록의 선택 모드·선택 ID 는 일괄 모드 전역 상태를 따른다', () => {
    const list = collect(listPartial, (n) => n.id === 'sortable_menu_list')[0];

    expect(evaluate(list.props.selectionMode, { _global: { menuBulkMode: true } })).toBe(true);
    expect(evaluate(list.props.selectionMode, { _global: {} })).toBe(false);
    expect(evaluate(list.props.selectedIds, { _global: { menuBulkSelectedIds: [11] } })).toEqual([11]);
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
  // @effects bulk_mode_resets_on_page_entry
  it('화면에 들어올 때마다 선택 모드를 끄고 고른 메뉴·역할을 비운다 — 전역 상태라 다른 화면에 다녀와도 남기 때문', () => {
    const reset = actionsOf({ actions: layout.init_actions }).find(
      (a: any) => a.handler === 'setState' && a.params?.target === 'global',
    );

    expect(reset).toBeDefined();
    expect(reset.params).toMatchObject({
      menuBulkMode: false,
      menuBulkSelectedIds: [],
      menuBulkRoleIds: [],
      menuBulkAction: 'add',
      menuBulkError: null,
    });
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=parent_with_children
  // @effects bulk_panel_replaces_detail
  it('「여러 개 선택」 을 켜면 오른쪽 판이 상세 대신 일괄 판이 되고, 끄면 상세로 돌아오며 지난 선택을 비운다', () => {
    const partials = collect(layout, (n) => typeof n.partial === 'string').map((n) => n.partial);
    expect(partials).toEqual(expect.arrayContaining([
      'partials/admin_menu_list/_panel_detail.json',
      'partials/admin_menu_list/_panel_bulk_roles.json',
    ]));

    for (const mode of [true, false, undefined]) {
      const context = { _global: { menuBulkMode: mode } };
      expect(evaluate(panelPartial.if, context)).toBe(mode === true);
      expect(evaluate(detailPartial.if, context)).toBe(mode !== true);
    }

    const toggle = collect(layout, (n) => n.id === 'bulk_mode_button')[0];
    const flip = collect(actionsOf(toggle), (n) => n.handler === 'setState')[0];
    expect(evaluate(flip.params.menuBulkMode, { _global: { menuBulkMode: false } })).toBe(true);
    expect(evaluate(flip.params.menuBulkMode, { _global: { menuBulkMode: true } })).toBe(false);
    expect(flip.params).toMatchObject({ menuBulkSelectedIds: [], menuBulkRoleIds: [], selectedMenuId: null, panelMode: 'view' });
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
  // @effects bulk_apply_requires_menus_and_roles
  it('「적용」 은 고른 메뉴와 역할이 둘 다 있어야 눌리고(비활성은 흐리게), 누르면 확인 창을 연다', () => {
    const apply = collect(panelPartial, (n) => n.id === 'bulk_apply_button')[0];
    const disabled = (menuBulkSelectedIds?: number[], menuBulkRoleIds?: number[]) =>
      evaluate(apply.props.disabled, { _global: { menuBulkSelectedIds, menuBulkRoleIds } });

    expect(disabled([], [7])).toBe(true);
    expect(disabled([11], [])).toBe(true);
    expect(disabled(undefined, undefined)).toBe(true);
    expect(disabled([11], [7])).toBe(false);
    expect(apply.props.className).toContain('disabled:opacity-50');
    expect(apply.props.className).toContain('disabled:cursor-not-allowed');

    const open = collect(actionsOf(apply), (n) => n.handler === 'openModal')[0];
    expect(open.target).toBe('bulk_roles_confirm_modal');
  });

  // @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=top_level
  // @effects admin_role_locked
  it('제거 모드에서는 관리자 역할이 선택지에서 빠지고, 이미 고른 것에서도 지운다', () => {
    const input = collect(panelPartial, (n) => n.id === 'bulk_roles_input')[0];
    const label = (opts: any[]) => opts.map((o) => o.value);
    const $localized = (name: any) => name.ko;

    expect(label(evaluate(input.props.options, { roles: ROLES, _global: { menuBulkAction: 'add' }, $localized }))).toEqual([1, 7]);
    expect(label(evaluate(input.props.options, { roles: ROLES, _global: { menuBulkAction: 'remove' }, $localized }))).toEqual([7]);

    const radio = collect(panelPartial, (n) => n.name === 'RadioGroup' && n.props?.name === 'menu_bulk_action')[0];
    const change = collect(actionsOf(radio), (n) => n.handler === 'setState')[0];
    const next = evaluate(change.params.menuBulkRoleIds, {
      $event: { target: { value: 'remove' } },
      _global: { menuBulkRoleIds: [1, 7] },
      roles: ROLES,
    });
    expect(next).toEqual([7]);
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=parent_with_children
  // @effects activity_log_per_menu, sidebar_reflects_assignment
  it('확인 창은 고른 메뉴·역할·작업을 그대로 보내고, 연타를 300ms 로 모으며, 성공 후 목록과 사이드바를 다시 받는다', () => {
    const apply = collect(confirmPartial, (n) => n.id === 'confirm_apply_button')[0];
    const click = actionsOf(apply).find((a: any) => a.type === 'click');
    const call = collect(click, (n) => n.handler === 'apiCall')[0];

    expect(click.handler).toBe('sequence');
    expect(click.debounce).toBe(300);
    expect(call.target).toBe('/api/admin/menus/bulk-roles');
    expect(call.params.method).toBe('PATCH');
    expect(call.params.body).toEqual({
      menu_ids: '{{_global.menuBulkSelectedIds ?? []}}',
      role_ids: '{{_global.menuBulkRoleIds ?? []}}',
      mode: "{{_global.menuBulkAction ?? 'add'}}",
    });

    const refetched = collect(call.onSuccess, (n) => n.handler === 'refetchDataSource').map((n) => n.params.dataSourceId);
    expect(refetched).toEqual(['menus', 'admin_menu']);
    expect(collect(call.onSuccess, (n) => n.handler === 'setState')[0].params.menuBulkSelectedIds).toEqual([]);
    expect(call.errorHandling['403']).toBeDefined();
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=child_only
  // @effects parent_auto_included
  it('하위만 고른 부모가 있으면 추가 확인 창이 자동 포함을 미리 알린다', () => {
    const hint = collect(confirmPartial, (n) => n.id === 'confirm_auto_parent')[0];

    expect(evaluate(hint.if, { menus: MENUS, _global: { menuBulkAction: 'add', menuBulkSelectedIds: [11] } })).toBe(true);
    expect(evaluate(hint.if, { menus: MENUS, _global: { menuBulkAction: 'add', menuBulkSelectedIds: [10, 11] } })).toBe(false);
    expect(evaluate(hint.if, { menus: MENUS, _global: { menuBulkAction: 'remove', menuBulkSelectedIds: [11] } })).toBe(false);
  });

  // @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=parent_with_children
  // @effects children_auto_removed
  it('부모만 골라 빼면 제거 확인 창이 하위 메뉴도 함께 빠짐을 미리 알린다', () => {
    const hint = collect(confirmPartial, (n) => n.id === 'confirm_auto_child')[0];

    expect(evaluate(hint.if, { menus: MENUS, _global: { menuBulkAction: 'remove', menuBulkSelectedIds: [10] } })).toBe(true);
    expect(evaluate(hint.if, { menus: MENUS, _global: { menuBulkAction: 'remove', menuBulkSelectedIds: [10, 11, 12] } })).toBe(false);
  });

  // @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=top_level
  // @effects update_menu_keeps_admin_role
  it('상세 보기는 역할이 없는 메뉴를 「모든 역할」 이 아니라 「지정된 역할 없음」 으로 보인다', () => {
    const texts = JSON.stringify(viewPartial);

    expect(texts).toContain('admin.menus.info.no_roles');
    expect(texts).not.toContain('admin.menus.info.all_roles');
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('영문 개수 문구는 1개일 때 단수형(_one)을 쓴다 (2026-10-06 실측 「1 menus selected」 → 7.1.0 R-A: menu(s) 대신 번역 엔진 복수형 선택)', () => {
    const en = read('../../lang/partial/en/admin.json');
    const bulk = (en.admin ?? en).menus.bulk;

    for (const key of ['selected_count', 'confirm_add', 'confirm_remove', 'confirm_auto_parent', 'confirm_auto_child']) {
      // 기본 키 = 복수형(2 이상), 키_one = 단수형 — 번역 엔진이 count 로 고른다(engine-v1.78.7).
      expect(bulk[key], key).not.toMatch(/\(s\)/);
      expect(bulk[key], key).toMatch(/\{\{count\}\} (parent )?(menus|submenus)/);
      expect(bulk[`${key}_one`], `${key}_one`).toMatch(/\{\{count\}\} (parent )?(menu|submenu)\b/);
    }
  });

  // @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=top_level
  // @effects update_menu_keeps_admin_role
  it('일본어 언어팩의 역할 화면 문구는 「ロール」 로 통일한다 (보호 역할 안내만 「役割」 이던 것)', () => {
    const ja = read('../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json');
    const roles = JSON.stringify((ja.admin ?? ja).roles);

    expect(roles).not.toContain('役割');
    expect((ja.admin ?? ja).roles.protected_roles_hint).toContain('ロール');
  });
});
