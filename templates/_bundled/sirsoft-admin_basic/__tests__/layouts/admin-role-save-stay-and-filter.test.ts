/**
 * @file admin-role-save-stay-and-filter.test.ts
 * @description 역할 화면 — 저장 후 수정 화면 유지 · 목록 소유 구분 필터 · 비회원 안내 정렬 회귀 테스트
 *
 * ① 수정 저장은 목록으로 돌아가지 않고 역할을 다시 받아(`refetchDataSource role`) 그 화면에 머문다.
 * ② 등록·복제 저장은 방금 만든 역할의 수정 화면(`/admin/roles/{id}/edit`)으로 라우트 전환해 간다 — `replace` 는
 *    같은 화면 쿼리만 바꾸는 이동이라 route.id 가 갱신되지 않는다(admin-role-form-create-then-edit-route.test.ts).
 * ③ 목록은 「구분」 선택 상자의 값을 `extension_type` 쿼리로 API 에 넘긴다(core·module·plugin·custom).
 * ④ 비회원 안내는 아이콘과 글이 한 줄 가운데로 맞는다(flex-center, 아이콘 위 여백 없음).
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import roleForm from '../../layouts/admin_role_form.json';
import roleList from '../../layouts/admin_role_list.json';

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

const formNodes = flatten((roleForm as any).slots?.content ?? (roleForm as any).components);
const listNodes = flatten((roleList as any).slots?.content ?? (roleList as any).components);

describe('역할 폼 — 저장 후 이동', () => {
  const form = formNodes.find((n) => n.id === 'role_form');
  const api = flattenActions(form?.actions).find((a) => a.handler === 'apiCall');
  const success = flattenActions(api?.onSuccess);

  // @scenario surface=form_save
  // @effects no_return_to_list
  it('저장 성공 뒤 역할 목록으로 가지 않는다', () => {
    const toList = success.filter((a) => a.handler === 'navigate' && a.params?.path === '/admin/roles');
    expect(toList).toHaveLength(0);
  });

  // @scenario surface=form_save
  // @effects update_stays_and_refetches
  it('수정 저장은 역할을 다시 받아 화면에 머문다', () => {
    const refetch = success.find((a) => a.handler === 'refetchDataSource' && a.params?.dataSourceId === 'role');
    expect(refetch, '수정 저장 뒤 role 재조회가 없습니다.').toBeDefined();
    expect(refetch.if).toBe('{{!!route?.id}}');
  });

  // @scenario surface=form_save
  // @effects create_goes_to_new_edit
  it('등록·복제 저장은 만든 역할의 수정 화면으로 라우트 전환해 간다', () => {
    const go = success.find((a) => a.handler === 'navigate');
    expect(go, '등록 저장 뒤 이동이 없습니다.').toBeDefined();
    expect(go.if).toBe('{{!route?.id}}');
    expect(go.params?.path).toBe("{{'/admin/roles/' + response.data.id + '/edit'}}");
    expect(go.params?.replace).not.toBe(true);
    expect(go.params?.mergeQuery).toBe(true);
  });
});

describe('역할 목록 — 소유 구분 필터', () => {
  // @scenario surface=list_filter
  // @effects list_passes_extension_type
  it('roles 데이터 소스가 extension_type 쿼리를 API 로 넘긴다', () => {
    const ds = asArray<any>((roleList as any).data_sources).find((d) => d.id === 'roles');
    expect(ds?.params?.extension_type).toBe('{{query.extension_type}}');
  });

  // @scenario surface=list_filter
  // @effects filter_select_resets_page
  it('「구분」 선택 상자는 전체·시스템·모듈·플러그인·직접 추가 다섯 값을 갖고 바꾸면 쪽을 1로 되돌린다', () => {
    const select = listNodes.find((n) => n.id === 'extension_type_filter');
    expect(select, '구분 선택 상자를 찾지 못했습니다.').toBeDefined();
    expect(select.props?.options.map((o: any) => o.value)).toEqual(['', 'core', 'module', 'plugin', 'custom']);
    expect(select.props?.value).toBe("{{query.extension_type || ''}}");
    const nav = flattenActions(select.actions).find((a) => a.handler === 'navigate');
    expect(nav?.params?.query?.extension_type).toBe('{{$event.target.value || undefined}}');
    expect(nav?.params?.query?.page).toBe(1);
  });
});

describe('역할 폼 — 비회원 안내 정렬', () => {
  // @scenario surface=guest_hint
  // @effects guest_hint_single_line
  it('아이콘과 글이 한 줄 가운데로 맞는다', () => {
    const hint = formNodes.find((n) => n.id === 'guest_scope_unsupported_hint');
    expect(hint?.props?.className).toContain('flex-center');
    expect(hint?.props?.className).not.toContain('items-start');
    const icon = (hint?.children ?? []).find((c: any) => c.name === 'Icon');
    expect(icon?.props?.className ?? '').not.toContain('mt-0.5');
  });
});
