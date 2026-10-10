/**
 * @file mypage-refund-mileage-credit.test.ts
 * @description 「마일리지로 환불」 현금분 표시 — 회원 주문 결제정보·클레임 상세 구조 계약 (D1·Q3)
 *
 * 현금 환불을 회원 마일리지로 지급하면 결제 상태는 결제완료로 남는다(Q16). 그래서 화면이 따로 말하지
 * 않으면 구매자는 환불이 어디로 갔는지 알 수 없다. 두 화면이 서버 필드를 그대로 읽는지 못박는다
 * (화면이 금액을 조립하면 통화 표기가 갈린다).
 *
 * @vitest-environment jsdom
 */

import { describe, expect, it } from 'vitest';

import paymentJson from '../../../layouts/partials/mypage/orders/_payment.json';
import claimInfoJson from '../../../layouts/partials/mypage/claims/_detail_claim_info.json';
import koClaim from '../../../lang/partial/ko/claim.json';
import enClaim from '../../../lang/partial/en/claim.json';
import koMypage from '../../../lang/partial/ko/mypage.json';
import enMypage from '../../../lang/partial/en/mypage.json';

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

/**
 * if 식에 주어진 문자열을 담은 노드를 찾는다.
 *
 * @param root 레이아웃 JSON
 * @param needle if 식 조각
 * @returns 찾은 노드 (없으면 undefined)
 */
function nodeWithIf(root: unknown, needle: string): Node | undefined {
  let found: Node | undefined;
  walk(root, (n) => {
    if (!found && typeof n.if === 'string' && n.if.includes(needle)) {
      found = n;
    }
  });

  return found;
}

describe('회원 주문 결제정보 — 마일리지로 환불 행 (Q3)', () => {
  /**
   * @effects member_order_payment_shows_mileage_credited_refund
   */
  it('행이 서버 집계를 읽고 0원이면 숨는다', () => {
    const row = nodeWithIf(paymentJson, 'total_refunded_mileage_credit_amount');

    expect(row?.if).toBe('{{(order.data.total_refunded_mileage_credit_amount ?? 0) > 0}}');
    expect(JSON.stringify(row)).toContain('order.data.total_refunded_mileage_credit_amount_formatted');
    expect(JSON.stringify(row)).toContain('$t:mypage.order_detail.refund_mileage_credit');
  });

  /**
   * @effects member_order_payment_label_exists_in_ko_en
   */
  it('다국어 키가 ko/en 에 있다', () => {
    expect((koMypage as Node).order_detail.refund_mileage_credit).toBeTruthy();
    expect((enMypage as Node).order_detail.refund_mileage_credit).toBeTruthy();
  });
});

describe('회원 클레임 상세 — 마일리지로 지급 줄 (D1)', () => {
  /**
   * @effects member_claim_detail_shows_mileage_credit_with_hint
   */
  it('줄이 서버 값을 읽고 유효기간 안내를 함께 낸다', () => {
    const row = nodeWithIf(claimInfoJson, 'refund_mileage_credit_amount');

    expect(row?.if).toBe('{{(claim.data?.claim?.refund_mileage_credit_amount ?? 0) > 0}}');
    const json = JSON.stringify(row);
    expect(json).toContain('claim.data?.claim?.refund_mileage_credit_amount_formatted');
    expect(json).toContain('$t:claim.detail.refund_mileage_credit_hint');
  });

  /**
   * @effects member_claim_detail_labels_exist_in_ko_en
   */
  it('다국어 키가 ko/en 에 있다', () => {
    expect((koClaim as Node).detail.refund_mileage_credit_amount).toBeTruthy();
    expect((enClaim as Node).detail.refund_mileage_credit_hint).toBeTruthy();
  });
});
