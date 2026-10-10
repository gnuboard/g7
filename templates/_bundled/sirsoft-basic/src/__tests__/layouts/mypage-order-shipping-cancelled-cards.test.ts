/**
 * @file mypage-order-shipping-cancelled-cards.test.ts
 * @description 회원 주문 상세 배송 현황 — 취소된 상품의 배송 카드 제외 구조 계약 (S-Q26-b)
 *
 * 부분취소·재활성 재계산은 배송 행마다 그 상품 행이 지금 부담하는 배송비 몫을 쓰고, 취소된 행의 몫은 0 이다.
 * 취소 상품 카드가 남으면 「무료배송」 으로 잘못 읽히므로 반복 소스에서 취소된 행의 배송 카드를 뺀다.
 *
 * @vitest-environment jsdom
 */

import { describe, expect, it } from 'vitest';

import shippingJson from '../../../layouts/partials/mypage/orders/_shipping.json';

type Node = Record<string, any>;

/**
 * 서브트리의 모든 노드를 훑는다.
 *
 * @param node 시작 노드
 * @param visit 방문 함수
 */
function walk(node: unknown, visit: (n: Node) => void): void {
  if (Array.isArray(node)) {
    node.forEach((child) => walk(child, visit));

    return;
  }

  if (node && typeof node === 'object') {
    visit(node as Node);
    Object.values(node as Node).forEach((value) => walk(value, visit));
  }
}

describe('회원 주문 상세 배송 현황 — 취소 상품 카드 제외 (S-Q26-b)', () => {
  /**
   * 시나리오 매니페스트: `modules/_bundled/sirsoft-ecommerce/tests/scenarios/partial-cancel-row-allocation.yaml`
   * @effects cancelled_lines_hide_shipping_fee_on_admin_and_member_screens
   */
  it('배송 목록 반복 소스가 취소된 상품 행의 배송 카드를 거른다', () => {
    const sources: string[] = [];
    walk(shippingJson, (n) => {
      if (n.iteration && typeof n.iteration.source === 'string' && n.iteration.source.includes('order.data.shippings')) {
        sources.push(n.iteration.source);
      }
    });

    expect(sources).toHaveLength(1);
    expect(sources[0]).toContain("option_status !== 'cancelled'");
    expect(sources[0]).toContain('s.order_option_id');
  });
});
