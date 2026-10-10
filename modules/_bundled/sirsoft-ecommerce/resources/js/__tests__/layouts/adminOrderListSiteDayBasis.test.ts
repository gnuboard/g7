/**
 * @file adminOrderListSiteDayBasis.test.ts
 * @description 대시보드 「오늘」 배지로 연 주문 목록의 하루 기준 배선 (7.1.0 전수 실측 · 결정 2026-10-08)
 *
 * 배지 숫자는 사이트 표준시의 하루로 센다. 배지로 연 주문 목록이 목록 원칙(보는 사람의 하루)으로 거르면 관리자 계정
 * 시간대가 사이트와 다를 때 배지 1건 → 목록 0건이 된다. 배지로 연 목록만 `date_basis=site` 로 사이트 하루를 쓰고,
 * 사용자가 날짜를 직접 다루면(날짜 종류·시작일·종료일·빠른 기간·프리셋·초기화) 그 인자를 떼어 보는 사람의 하루로 돌아간다.
 *
 * 배선 네 지점: 배지 링크 → URL(date_basis) → 필터 상태(dateBasis) → 검색 navigate(date_basis) / 데이터소스 파라미터.
 * 하나라도 빠지면 배지와 목록이 다시 어긋나거나, 날짜를 바꿨는데도 사이트 하루가 남는다.
 *
 * 시나리오 SSoT: modules/_bundled/sirsoft-ecommerce/tests/scenarios/admin-order-list-site-day-basis.yaml
 *
 * @vitest-environment node
 */

import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const ROOT = path.resolve(__dirname, '../../..');
const read = (rel: string): any => JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf-8'));

const extension = read('extensions/admin_dashboard_commerce.json');
const shell = read('layouts/admin/admin_ecommerce_order_list.json');
const filterSection = read('layouts/admin/partials/admin_ecommerce_order_list/_partial_filter_section.json');

/**
 * 트리에서 조건에 맞는 노드를 모두 모은다.
 *
 * @param node 시작 노드
 * @param match 판정 함수
 * @param found 누적 배열
 * @returns 일치한 노드 배열
 */
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

/**
 * `{{식}}` 하나를 주어진 문맥으로 평가한다.
 *
 * @param template `{{...}}` 로 감싼 식
 * @param ctx 평가 문맥
 * @returns 평가 결과
 */
function evaluate(template: string, ctx: Record<string, any>): any {
  const expr = template.replace(/^\{\{([\s\S]+)\}\}$/, '$1');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(ctx), `return (${expr});`)(...Object.values(ctx));
}

/** 노드 id(data-ref) 로 노드를 찾는다. */
const byId = (tree: any, id: string): any => collect(tree, (n) => n.id === id)[0];

/** 노드에 걸린 setState 액션 params 를 모두 모은다(sequence 안쪽 포함). */
const setStates = (node: any): any[] =>
  collect(node?.actions ?? [], (n) => n.handler === 'setState').map((n) => n.params);

describe('대시보드 배지로 연 주문 목록 — 사이트 하루 기준 배선', () => {
  // @scenario entry=dashboard_badge, basis=site
  // @effects dashboard_badge_links_carry_site_basis
  it('배지 링크 7개가 모두 date_basis=site 를 싣는다', () => {
    const paths: string[] = collect(
      extension,
      (n) => n.handler === 'navigate' && typeof n.params?.path === 'string' && n.params.path.includes('start_date='),
    ).map((n) => n.params.path);

    expect(paths.length).toBe(7);
    for (const p of paths) {
      expect(p, p).toContain('date_basis=site');
    }
  });

  // @scenario entry=dashboard_badge, basis=site
  // @effects order_list_forwards_date_basis_to_api
  it('목록 데이터소스가 URL 의 date_basis 를 API 로 넘기고, 진입 시 필터 상태로 복원한다', () => {
    const orders = shell.data_sources.find((d: any) => d.id === 'orders');
    expect(evaluate(orders.params.date_basis, { query: { date_basis: 'site' } })).toBe('site');
    // 북마크·수기 편집으로 정해진 값 밖의 기준이 주소에 들어오면 API 는 422 이고 목록 전체가 오류 화면이 된다 —
    // 그 주소는 새로고침해도 그대로라 빠져나올 길이 없다. 진입 복원(아래)과 같은 판정으로 빈 값(전송 생략)으로 떨어뜨린다.
    expect(evaluate(orders.params.date_basis, { query: { date_basis: 'utc' } })).toBe('');
    expect(evaluate(orders.params.date_basis, { query: {} })).toBe('');

    expect(shell.initLocal.filter).toHaveProperty('dateBasis', '');
    const restore = collect(shell.init_actions, (n) => n.handler === 'setState' && n.params?._local?.filter)[0];
    expect(evaluate(restore.params._local.filter.dateBasis, { query: { date_basis: 'site' } })).toBe('site');
    expect(evaluate(restore.params._local.filter.dateBasis, { query: {} })).toBe('');
  });

  // @scenario entry=list_non_date_change, basis=site
  // @effects order_list_non_date_search_keeps_site_basis
  it('날짜와 무관한 조건만 바꿔 검색하면 date_basis=site 를 그대로 싣는다', () => {
    const search = shell.named_actions.searchOrders;
    expect(search.params.mergeQuery).toBe(true);
    const expr = search.params.query.date_basis;
    expect(evaluate(expr, { _local: { filter: { dateBasis: 'site', orderStatus: ['preparing'] } } })).toBe('site');
    // 날짜를 다뤄 상태가 비면 null 로 떨어뜨려 병합 중인 URL 에서도 지운다
    expect(evaluate(expr, { _local: { filter: { dateBasis: '' } } })).toBeNull();
  });

  // @scenario entry=list_date_change, basis=viewer
  // @effects order_list_date_change_drops_site_basis
  it('날짜 종류·시작일·종료일·빠른 기간 6개를 다루면 dateBasis 를 비운다', () => {
    const ids = [
      'f_011_date_type_select',
      'f_012_start_date',
      'f_013_end_date',
      'f_014_quick_today',
      'f_015_quick_week',
      'f_016_quick_month',
      'f_017_quick_3months',
      'f_018_quick_6months',
      'f_019_quick_1year',
    ];
    for (const id of ids) {
      const node = byId(filterSection, id);
      expect(node, id).toBeTruthy();
      const params = setStates(node);
      expect(params.some((p) => p['filter.dateBasis'] === ''), id).toBe(true);
    }
  });

  // @scenario entry=list_date_change, basis=viewer
  // @effects order_list_date_change_drops_site_basis
  it('프리셋 적용·초기화도 dateBasis 를 비운다', () => {
    const reset = byId(filterSection, 'f_142_reset_button');
    expect(setStates(reset).some((p) => p.filter?.dateBasis === '')).toBe(true);

    const preset = collect(filterSection, (n) => typeof n.id === 'string' && n.id.startsWith('preset_buttons_dynamic_'))[0];
    expect(setStates(preset).some((p) => p.filter?.dateBasis === '')).toBe(true);
  });

  // @scenario entry=dashboard_badge, basis=site
  // @effects order_list_shows_site_basis_notice
  it('date_basis=site 로 연 동안에만 사이트 표준시 기준 안내를 보인다', () => {
    const notice = byId(filterSection, 'date_site_basis_notice');
    expect(notice).toBeTruthy();
    const cond = notice.if ?? notice.condition;
    expect(evaluate(cond, { query: { date_basis: 'site' } })).toBe(true);
    expect(evaluate(cond, { query: {} })).toBe(false);
    expect(JSON.stringify(notice)).toContain('sirsoft-ecommerce.admin.order.filter.site_day_notice');
  });

  // @scenario entry=dashboard_badge, basis=site
  // @effects order_list_shows_site_basis_notice
  it('안내 문구가 ko·en·ja 에 있다', () => {
    const files = [
      path.join(ROOT, 'lang/partial/ko/admin/order.json'),
      path.join(ROOT, 'lang/partial/en/admin/order.json'),
      path.resolve(ROOT, '../../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/order.json'),
    ];
    for (const file of files) {
      const json = JSON.parse(fs.readFileSync(file, 'utf-8'));
      const text = json?.filter?.site_day_notice;
      expect(typeof text, file).toBe('string');
      expect(text, file).toContain('{{timezone}}');
    }
  });
});
