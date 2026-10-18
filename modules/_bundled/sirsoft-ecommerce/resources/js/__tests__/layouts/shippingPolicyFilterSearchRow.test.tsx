// e2e:allow 표시 전용 select 제거(동작 경로 무변경) — 브라우저 확인은 관리자 배송정책 목록 수동 점검(select 부재·키워드 검색)이 맡는다
/**
 * @file shippingPolicyFilterSearchRow.test.tsx
 * @description 배송정책 목록 검색줄에 표시 전용 검색대상 select 가 없다 (#17)
 *
 * 선택지가 '정책명' 하나뿐인 검색대상 select 는 서버로 보내지도 않는 표시 전용 컨트롤이었다 —
 * 운영자가 다른 대상을 고를 수 있는 것처럼 보인다. 제거하고, 검색어는 엔터와 [검색] 버튼이 같은
 * 명명 액션(searchShippingPolicies)으로 보낸다.
 *
 * 시나리오 매니페스트: modules/_bundled/sirsoft-ecommerce/tests/scenarios/shipping-policy-list-filter.yaml
 */
import { describe, it, expect } from 'vitest';
import filterPartial from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_partial_filter.json';

/** 레이아웃 노드를 children 기준으로 펼친다. */
function flattenAll(node: any, acc: any[] = []): any[] {
  if (!node) return acc;
  acc.push(node);
  (node.children ?? []).forEach((c: any) => flattenAll(c, acc));
  return acc;
}

describe('배송정책 목록 검색줄', () => {
  /**
   * @scenario case=no_search_type_select
   * @effects search_keyword_submits_named_search
   */
  it('검색대상 select 가 없고, 검색어 엔터가 명명 검색 액션을 부른다', () => {
    const allNodes = flattenAll(filterPartial);
    expect(allNodes.find((n: any) => n.props?.name === 'searchType')).toBeUndefined();
    expect(JSON.stringify(filterPartial)).not.toContain('filter.search_type');

    const searchInput = allNodes.find((n: any) => n.name === 'Input' && n.props?.name === 'search');
    expect(searchInput).toBeDefined();
    const enter = (searchInput.actions ?? []).find((a: any) => a.type === 'keypress' && a.key === 'Enter');
    expect(enter?.actionRef).toBe('searchShippingPolicies');
  });
});
