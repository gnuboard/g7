/**
 * @file adminExportRequesterFilter.test.ts
 * @description 내보내기 관리 「이 요청자의 내보내기만 보기」가 요청자를 회원 고유ID로 정확히 거르는지 잠근다.
 *
 * 이름 포함 검색(`search_field=requester`)만으로 거르면 「홍길동」을 고를 때 「홍길동2」의 작업도 섞인다.
 * 메뉴는 필터 칸에 보일 이름과 함께 `requester_uuid` 를 주소에 싣고, 목록 데이터 소스가 그 값을 서버로 넘기며,
 * [검색]은 필터 칸의 요청자 검색어가 그대로일 때만 한정을 이어 간다(검색어를 바꾸면 푼다). 굽기 산출(`.json`)을 본다.
 *
 * @scenario-manifest tests/scenarios/export.yaml
 */

import { describe, it, expect } from 'vitest';
import layout from '../../../layouts/admin/admin_ecommerce_export_index.json';
import datagrid from '../../../layouts/admin/partials/admin_ecommerce_export_index/_partial_datagrid.json';

/**
 * 트리에서 조건에 맞는 객체를 모두 모은다.
 *
 * @param node 탐색할 값
 * @param match 판정 함수
 * @returns 맞는 객체 목록
 */
function collect(node: unknown, match: (value: any) => boolean): any[] {
  const out: any[] = [];
  const visit = (value: any): void => {
    if (!value || typeof value !== 'object') return;
    if (Array.isArray(value)) {
      value.forEach(visit);
      return;
    }
    if (match(value)) out.push(value);
    Object.values(value).forEach(visit);
  };
  visit(node);
  return out;
}

/**
 * `{{ … }}` 식 하나를 주어진 문맥으로 계산한다.
 *
 * @param template 바인딩 문자열
 * @param context 식이 읽는 이름들
 * @returns 계산 결과
 */
function evaluate(template: string, context: Record<string, unknown>): unknown {
  const body = template.replace(/^\{\{\s*/, '').replace(/\s*\}\}$/, '');
  const names = Object.keys(context);
  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${body});`)(...names.map((name) => context[name]));
}

const searchNavigate = (layout as any).named_actions.searchExports;
const menuNavigate = collect(datagrid, (value) => value.handler === 'navigate' && value.params?.query?.search_field === 'requester')[0];
const listSource = (layout as any).data_sources.find((source: any) => source.id === 'exports');

describe('내보내기 관리 — 요청자 한정', () => {
  // @scenario action=requester_menu
  // @effects requester_filter_matches_requester_exactly
  it('메뉴는 이름과 함께 요청자 회원 고유ID 를 주소에 싣는다', () => {
    expect(menuNavigate, '요청자 거르기 navigate 가 없다').toBeTruthy();
    const row = { requester: { name: '홍길동', uuid: '11111111-1111-4111-8111-111111111111' } };
    expect(evaluate(menuNavigate.params.query.requester_uuid, { row })).toBe(row.requester.uuid);
    expect(evaluate(menuNavigate.params.query.search_keyword, { row })).toBe('홍길동');
    expect(menuNavigate.params.mergeQuery).toBe(true);
  });

  // @scenario action=requester_menu
  // @effects requester_filter_matches_requester_exactly
  it('목록 데이터 소스가 요청자 한정을 서버로 넘긴다', () => {
    expect(listSource.params.requester_uuid).toBe('{{query.requester_uuid}}');
  });

  // @scenario action=requester_menu
  // @effects requester_filter_matches_requester_exactly
  it('[검색]은 요청자 검색어가 그대로일 때만 한정을 이어 가고, 바꾸면 푼다', () => {
    const uuid = '11111111-1111-4111-8111-111111111111';
    const query = { search_field: 'requester', search_keyword: '홍길동', requester_uuid: uuid };
    const expr = searchNavigate.params.query.requester_uuid;
    expect(expr, '[검색]이 요청자 한정을 다루지 않는다').toBeTypeOf('string');

    // 같은 검색어로 다른 조건(진행 상황 등)만 바꿔 검색 — 그 사람 안에서 거른다
    expect(evaluate(expr, { query, _local: { filter: { searchField: 'requester', search: '홍길동' } } })).toBe(uuid);
    // 검색어를 바꾸거나 검색 대상을 바꾸면 그 사람으로 몰래 걸러지지 않는다
    expect(evaluate(expr, { query, _local: { filter: { searchField: 'requester', search: '김철수' } } })).toBeUndefined();
    expect(evaluate(expr, { query, _local: { filter: { searchField: 'all', search: '홍길동' } } })).toBeUndefined();
    // 한정이 없던 목록에서는 아무것도 싣지 않는다
    expect(evaluate(expr, { query: { search_field: 'requester', search_keyword: '홍길동' }, _local: { filter: { searchField: 'requester', search: '홍길동' } } })).toBeFalsy();
  });
});
