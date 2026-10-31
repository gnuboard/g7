/**
 * @file mypage-order-applied-shipping-policy.test.ts
 * @description 회원·비회원 주문 상세 상품 목록의 「적용 배송정책」 줄 — 취소된 행에서는 서지 않는다
 *
 * 부분취소·재활성 뒤 주문의 배송정책 스냅샷 표시 칸(「그 상품만 샀을 때 배송비」)은 남은 수량 기준으로 갱신된다.
 * 취소된 행과 남은 행은 같은 상품 옵션을 공유하므로, 취소 행에도 그 줄을 세우면 이미 취소한 상품에 「2,500원」이
 * 붙어 낼 배송비처럼 읽힌다. 취소 행에서는 줄을 세우지 않는다.
 */

import { describe, expect, it } from 'vitest';

import items from '../../../layouts/partials/mypage/orders/_items.json';

type Node = Record<string, any>;

/**
 * JSON 트리의 모든 노드를 평탄화한다.
 *
 * @param node 시작 노드
 * @param acc 누적 배열
 * @returns 노드 목록
 */
function flatten(node: unknown, acc: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => flatten(child, acc));

    return acc;
  }
  if (node && typeof node === 'object') {
    acc.push(node as Node);
    Object.values(node as Node).forEach((value) => flatten(value, acc));
  }

  return acc;
}

/**
 * 노드의 `if` 를 행 문맥으로 평가한다.
 *
 * @param node 노드
 * @param item 주문 행
 * @param order 주문 응답
 * @returns 표시 여부
 */
function visible(node: Node, item: any, order: any): boolean {
  const body = String(node.if ?? 'true').replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return Boolean(new Function('item', 'order', `return (${body});`)(item, order));
}

const order = {
  data: {
    shipping_policy_applied_snapshot: {
      items: [{ product_option_id: 291, policy: { policy_name: '조건부 무료', standalone_shipping_amount: 2500, standalone_shipping_amount_formatted: '2,500원' } }],
    },
  },
};

const policyLine = flatten(items).find(
  (n) => typeof n.if === 'string' && n.if.includes('shipping_policy_applied_snapshot') && JSON.stringify(n).includes('policy_name'),
);

describe('「적용 배송정책」 줄', () => {
  /**
   * @effects cancelled_lines_hide_applied_shipping_policy
   */
  it('남은 행에는 갱신된 배송비와 함께 선다', () => {
    expect(policyLine, '적용 배송정책 줄 노드가 없다').toBeDefined();
    expect(visible(policyLine!, { product_option_id: 291, option_status: 'delivered' }, order)).toBe(true);
  });

  /**
   * @effects cancelled_lines_hide_applied_shipping_policy
   */
  it('취소된 행에는 서지 않는다 — 같은 상품 옵션의 갱신된 배송비가 취소 상품에 붙지 않게', () => {
    expect(visible(policyLine!, { product_option_id: 291, option_status: 'cancelled' }, order)).toBe(false);
  });
});
