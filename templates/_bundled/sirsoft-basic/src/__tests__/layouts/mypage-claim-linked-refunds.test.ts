/**
 * @file mypage-claim-linked-refunds.test.ts
 * @description 회원·비회원 신청서 상세 — 후속 환불·원 신청서 연결 구조 계약 (보완 5 · Q8)
 *
 * 반품·교환 상세에는 그 신청서로 접수된 환불의 상태·수단·금액이, 환불·취소 상세에는 원 신청서가 보인다. 주소는 서버가
 * 회원/비회원을 갈라 준 `detail_url` 을 그대로 쓴다 — partial 이 회원 여부를 판단하지 않는다(회원·비회원 셸이 같은 partial).
 */

import { describe, expect, it } from 'vitest';

import claimInfo from '../../../layouts/partials/mypage/claims/_detail_claim_info.json';

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

describe('신청서 상세 — 후속 환불 (보완 5)', () => {
  /**
   * @scenario claim_type=return, screen=detail, entry=none
   * @effects user_claim_detail_shows_follow_up_refunds
   */
  it('반품·교환이거나 원 신청서가 있을 때 서고, 비면 안내한다', () => {
    const block = findById(claimInfo, 'claim_linked_refunds');
    expect(block?.if).toBe("{{['exchange', 'return'].includes(claim.data?.claim?.claim_type ?? '') || !!claim.data?.linked_claims?.source}}");
    expect(findById(block, 'claim_linked_refunds_empty')).toBeDefined();
    expect(JSON.stringify(block)).toContain('linkedRefund.refund_total_formatted');
  });

  /**
   * @scenario claim_type=refund, screen=detail, entry=none
   * @effects user_claim_detail_shows_follow_up_refunds
   */
  it('이동 경로는 서버가 준 detail_url 이고, 앞 신청서의 결제 오류를 지운다', () => {
    const json = JSON.stringify(findById(claimInfo, 'claim_linked_refunds'));
    expect(json).toContain('linkedRefund.detail_url');
    expect(json).toContain('claim.data.linked_claims.source.detail_url');
    expect(json).toContain('claimPayError');
    expect(json).not.toContain('/mypage/claims/');
  });
});
