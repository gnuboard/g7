/**
 * @file admin-user-list-ext-columns.test.ts
 * @description 회원 목록 확장 열 주입 계약 테스트 (sirsoft-admin_basic, 7.2.0 A6t)
 *
 * 확장은 오버레이의 `inject_props` `_append` 로 표 `users_data_grid` 의 `columns` 와 열 선택기 노드(id `column_selector`)의
 * `columns`·`defaultColumns` 를 늘린다. 선택기의 prop `id="admin_user_list_columns"` 는 보이는 열 저장 키라 주입 대상이 아니다.
 * 서버 병합(`LayoutExtensionService::injectProps`)은 PHPUnit 영역이고, 이 시험은 그 병합이 기대는 레이아웃 JSON 계약을 잠근다.
 *  - 두 노드가 그 id 로 있다(선택기는 굽힌 조각 `_partial_filter_section` 안, 목록 레이아웃이 그 조각을 include 한다).
 *  - `columns`·`defaultColumns` 가 리터럴 배열이다 — 표현식 문자열이면 서버 병합이 `_append` 를 건너뛴다.
 *  - 열 항목 모양이 `{field, header, …cellChildren}`, 선택기 선택지의 field 집합 == 표 열의 field 집합.
 *  - 선례 오버레이(sirsoft-social_login `admin_user_list_social_column.g7.html`)의 `_append` 를 서버와 같은 규칙(기존 배열 끝에 이어 붙이기)으로
 *    적용하면 두 곳 모두 열이 1개 늘고, 집합이 여전히 같다.
 *
 * @since 1.2.0
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

import userListLayout from '../../layouts/admin_user_list.json';
import filterSection from '../../layouts/partials/admin_user_list/_partial_filter_section.json';

type Json = Record<string, any>;

/**
 * 저장소 루트를 위로 훑어 찾는다 (`_bundled` 여부로 깊이가 달라지므로 상대경로 고정 금지).
 *
 * @returns 저장소 루트 절대경로
 */
function repositoryRoot(): string {
  let current = path.dirname(fileURLToPath(import.meta.url));
  for (let depth = 0; depth < 10; depth++) {
    if (fs.existsSync(path.join(current, 'config/core.php'))) return current;
    current = path.dirname(current);
  }
  throw new Error('config/core.php 를 가진 저장소 루트를 찾지 못했습니다.');
}

/**
 * 객체 트리 전체를 훑어 id 가 같은 노드를 찾습니다(자식·슬롯·모달 구분 없이).
 *
 * @param root 탐색 시작
 * @param id 찾을 id
 * @returns 노드 목록
 */
function findAllById(root: unknown, id: string): Json[] {
  const found: Json[] = [];
  const visit = (value: unknown): void => {
    if (!value || typeof value !== 'object') return;
    if (Array.isArray(value)) {
      value.forEach(visit);
      return;
    }
    const node = value as Json;
    if (node.id === id) found.push(node);
    for (const [key, child] of Object.entries(node)) {
      if (key !== 'props') visit(child);
    }
  };
  visit(root);
  return found;
}

/**
 * 서버 `injectProps` 의 `_append` 규칙을 그대로 옮깁니다 — 기존 값이 문자열(표현식)이면 건너뛰고, 배열이면 끝에 이어 붙인다.
 *
 * @param node 대상 노드(복제본)
 * @param props 주입 props
 * @returns 주입 뒤 노드
 */
function injectProps(node: Json, props: Json): Json {
  const next = { ...node, props: { ...(node.props ?? {}) } };
  for (const [key, value] of Object.entries(props)) {
    if (value && typeof value === 'object' && !Array.isArray(value) && '_append' in value) {
      const existing = next.props[key] ?? [];
      if (typeof existing === 'string') continue;
      next.props[key] = [...(Array.isArray(existing) ? existing : []), ...(Array.isArray(value._append) ? value._append : [])];
    } else {
      next.props[key] = value;
    }
  }
  return next;
}

const root = repositoryRoot();
const overlay = JSON.parse(
  fs.readFileSync(path.join(root, 'plugins/_bundled/sirsoft-social_login/resources/extensions/admin_user_list_social_column.json'), 'utf-8'),
);
const [grid] = findAllById(userListLayout, 'users_data_grid');
const [selector] = findAllById(filterSection, 'column_selector');
const fields = (columns: Json[]): string[] => columns.map((c) => c.field);

describe('sirsoft-admin_basic 회원 목록 확장 열 주입 계약 (A6t)', () => {
  /**
   * @scenario target=users_data_grid|column_selector
   * @effects ext_column_targets_exist_by_id
   */
  it('표와 열 선택기가 주입 대상 id 로 하나씩 있다(선택기는 목록이 include 하는 조각 안)', () => {
    expect(findAllById(userListLayout, 'users_data_grid')).toHaveLength(1);
    expect(findAllById(filterSection, 'column_selector')).toHaveLength(1);
    expect(grid?.name).toBe('DataGrid');
    expect(selector?.name).toBe('ColumnSelector');
    expect(JSON.stringify(userListLayout)).toContain('"partial":"partials/admin_user_list/_partial_filter_section.json"');

    // 저장 키 prop `id` 는 노드 id 가 아니다 — 이 값으로 주입하면 대상이 없다
    expect(selector?.props?.id).toBe('admin_user_list_columns');
    expect(findAllById(userListLayout, 'admin_user_list_columns')).toHaveLength(0);
    expect(findAllById(filterSection, 'admin_user_list_columns')).toHaveLength(0);
  });

  /**
   * @scenario target=users_data_grid|column_selector, prop=columns|defaultColumns
   * @effects ext_column_props_are_literal_arrays
   */
  it('columns·defaultColumns 가 리터럴 배열이다(표현식이면 _append 가 무시된다)', () => {
    expect(Array.isArray(grid?.props?.columns)).toBe(true);
    expect(Array.isArray(selector?.props?.columns)).toBe(true);
    expect(Array.isArray(selector?.props?.defaultColumns)).toBe(true);

    // 규칙 확인 — 표현식 문자열 자리는 서버처럼 건너뛴다
    const asExpression = injectProps({ props: { columns: '{{_computed.columns}}' } }, { columns: { _append: [{ field: 'x' }] } });
    expect(asExpression.props.columns).toBe('{{_computed.columns}}');
  });

  /**
   * @scenario target=users_data_grid|column_selector
   * @effects ext_column_shapes_and_field_sets_match
   */
  it('열 항목 모양이 {field, header, …cellChildren} 이고 선택기 field 집합 == 표 field 집합이다', () => {
    expect((grid.props.columns as Json[]).some((c) => Array.isArray(c.cellChildren))).toBe(true);
    for (const column of grid.props.columns as Json[]) {
      expect(typeof column.field).toBe('string');
      expect(typeof column.header).toBe('string');
      // 셀 모양은 선택 — 없으면 값을 그대로 그린다(email·created_at 등). 있으면 배열이다
      if (column.cellChildren !== undefined) {
        expect(Array.isArray(column.cellChildren), `${column.field} cellChildren`).toBe(true);
      }
    }
    for (const option of selector.props.columns as Json[]) {
      expect(typeof option.field).toBe('string');
      expect(typeof option.header).toBe('string');
      expect(option.cellChildren).toBeUndefined();
    }
    expect(new Set(fields(selector.props.columns))).toEqual(new Set(fields(grid.props.columns)));
    // 기본 열은 선택지 안에 있다
    for (const field of selector.props.defaultColumns as string[]) {
      expect(fields(selector.props.columns)).toContain(field);
    }
    // 머리글도 같은 문구 키를 쓴다
    for (const option of selector.props.columns as Json[]) {
      expect((grid.props.columns as Json[]).find((c) => c.field === option.field)?.header).toBe(option.header);
    }
  });

  /**
   * @scenario overlay=sirsoft-social_login, target=users_data_grid|column_selector
   * @effects precedent_overlay_appends_one_column_each
   */
  it('선례 오버레이의 _append 를 적용하면 표와 선택기 모두 열이 1개 늘고 field 집합이 여전히 같다', () => {
    const injections: Json[] = overlay.injections;
    expect(overlay.target_layout).toBe('admin_user_list');
    expect(injections.map((i) => i.target_id).sort()).toEqual(['column_selector', 'users_data_grid']);
    expect(injections.every((i) => i.position === 'inject_props')).toBe(true);

    const gridAfter = injectProps(grid, injections.find((i) => i.target_id === 'users_data_grid')!.props);
    const selectorAfter = injectProps(selector, injections.find((i) => i.target_id === 'column_selector')!.props);

    expect(gridAfter.props.columns).toHaveLength(grid.props.columns.length + 1);
    expect(selectorAfter.props.columns).toHaveLength(selector.props.columns.length + 1);
    expect(gridAfter.props.columns.at(-1).field).toBe('social_providers');
    expect(selectorAfter.props.columns.at(-1).field).toBe('social_providers');
    // 기존 열은 그대로 앞에 남는다
    expect(fields(gridAfter.props.columns).slice(0, -1)).toEqual(fields(grid.props.columns));
    expect(new Set(fields(selectorAfter.props.columns))).toEqual(new Set(fields(gridAfter.props.columns)));
    // 선례는 기본 열에 넣지 않는다(처음에는 숨어 있다)
    expect(selectorAfter.props.defaultColumns).toEqual(selector.props.defaultColumns);
    // 원본 JSON 은 바뀌지 않는다
    expect(fields(grid.props.columns)).not.toContain('social_providers');
  });

  /**
   * @scenario overlay=synthetic_default_column, target=column_selector, prop=defaultColumns
   * @effects default_columns_appendable_for_visible_ext_column
   */
  it('처음부터 보일 확장 열은 defaultColumns 에도 _append 로 덧붙일 수 있다', () => {
    const after = injectProps(selector, {
      columns: { _append: [{ field: 'department', header: '$t:sirsoft-sso.admin.users.columns.department' }] },
      defaultColumns: { _append: ['department'] },
    });

    expect(after.props.defaultColumns).toEqual([...selector.props.defaultColumns, 'department']);
    expect(fields(after.props.columns)).toContain('department');
  });
});
