/**
 * @file adminClaimRefundBreakdown.test.ts
 * @description 관리자 환불 상세 — 수단 칸은 실제로 돈이 간 수단별 금액 줄을 보인다 (S7)
 *
 * 신청서 수단은 결제수단에서 정해져, 카드 주문을 마일리지로만 돌려준 환불도 「PG사 환불」 이다. 수단 칸은 서버가
 * 만든 내역 줄(`refund_breakdown_formatted`)을 먼저 보이고, 없을 때만 신청서 수단 라벨로 물러선다.
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

describe('관리자 환불 상세 — 환불 수단 내역 (S7)', () => {
  /**
   * @effects refund_notification_splits_cash_and_mileage
   */
  it('수단 칸은 내역 줄을 먼저, 없을 때 수단 라벨을 보인다', () => {
    const cell = findById(payment, 'cst_refund_breakdown');
    expect(cell).toBeDefined();
    expect(cell?.text).toBe("{{claim?.data?.claim?.refund_breakdown_formatted || claim?.data?.claim?.refund_method_label || '-'}}");
    // 현금 줄 · 마일리지 줄이 줄바꿈으로 나뉘어 온다
    expect(cell?.props?.className).toContain('whitespace-pre-line');
  });
});
