/**
 * @file adminClaimExchangeDifferenceRefund.test.ts
 * @description 관리자 환불 상세 — 교환 차액 환급 줄 구조 계약 (S2 · Q9)
 *
 * 교환으로 받은 상품을 환불하면 그 교환 때 낸 상위 옵션 차액을 낸 수단 그대로 돌려준다. 원 주문 환불액과 돈이
 * 나가는 결제 건이 달라 정산 표에 따로 한 줄로 보이고, 서버 합계 필드만 읽는다.
 */

import { describe, expect, it } from 'vitest';

import payment from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_payment.json';

type Node = Record<string, any>;

/**
 * 서브트리에서 id 로 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param id 찾을 id
 * @returns 찾은 노드
 */
function findById(node: unknown, id: string): Node | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = findById(child, id);
      if (hit) return hit;
    }

    return undefined;
  }
  if (node && typeof node === 'object') {
    if ((node as Node).id === id) return node as Node;
    for (const value of Object.values(node as Node)) {
      const hit = findById(value, id);
      if (hit) return hit;
    }
  }

  return undefined;
}

describe('관리자 환불 상세 — 교환 차액 환급 줄 (S2)', () => {
  /**
   * @scenario difference_method=card
   * @effects exchange_difference_refund_is_shown_separately
   */
  it('합계가 있을 때만 서버 표기로 한 줄을 그린다', () => {
    const row = findById(payment, 'cst_011b_exchange_difference_refund');
    expect(row?.if).toBe('{{(claim?.data?.claim?.exchange_difference_refund_total ?? 0) > 0}}');
    const json = JSON.stringify(row);
    expect(json).toContain('$t:sirsoft-ecommerce.admin.claim.detail.settlement.exchange_difference_refund');
    expect(json).toContain('exchange_difference_refund_total_formatted');
    // 낸 수단(카드·마일리지)까지 말하는 서버 표기를 먼저 읽는다 — 05 S2-3 「X원(카드/마일리지)」.
    expect(json).toContain('exchange_difference_refunds_formatted');
  });
});
