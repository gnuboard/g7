/**
 * @file cashReceiptLookup.test.ts
 * @description 주문 상세(회원·비회원)·신청서 상세의 나이스페이먼츠 [현금영수증 조회] 계약 (NP-67)
 *
 * 현금영수증을 신청한 결제(payment.cash_receipt_type)에만 버튼이 보이고, 누르면 주문번호로 영수증 조회 API 를
 * 비회원 조회 토큰 헤더와 함께 부르며, 응답의 cash_receipt_url 이 있을 때만 링크로 바뀐다.
 * 이커머스 전수 실측 S4 에서 이 항목을 브라우저로 잴 주문(나이스 결제완료 + 현금영수증 발급)을 만들 수 없어
 * (나이스 입금은 PG 거래 조회로 검증 — 로컬 재현 불가) 계약 테스트로 둔다.
 */

import { describe, it, expect } from 'vitest';
import extensionLayout from '../../../extensions/user_mypage_order_receipt.json';

type Node = Record<string, unknown>;

/** 조건에 맞는 첫 노드를 찾는다 */
function find(node: unknown, match: (n: Node) => boolean): Node | undefined {
  if (!node || typeof node !== 'object') {
    return undefined;
  }
  const value = node as Node;
  if (match(value)) {
    return value;
  }
  for (const child of Object.values(value)) {
    const found = find(child, match);
    if (found) {
      return found;
    }
  }

  return undefined;
}

/** `{{ … }}` 표현식을 화면 컨텍스트로 평가한다 */
function evaluate(text: string, ctx: Record<string, unknown>): unknown {
  const expr = text.replace(/^\{\{([\s\S]+)\}\}$/, '$1');
  const names = Object.keys(ctx);
  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${expr});`)(...names.map((name) => ctx[name]));
}

const button = find(extensionLayout, (n) => n.name === 'Button' && JSON.stringify(n).includes('view_cash_receipt')) as Node;
const link = find(extensionLayout, (n) => n.name === 'A' && JSON.stringify(n.props ?? {}).includes('_npMpCashReceiptUrl')) as Node;
const apiCall = find(button, (n) => n.handler === 'apiCall') as Node;

const order = (payment: Record<string, unknown>) => ({ data: { order_number: '20261007-0000000001', payment } });

describe('나이스페이먼츠 현금영수증 조회', () => {
  /**
   * @scenario case=receipt_requested
   * @effects button_only_for_requested_receipt
   */
  it('현금영수증을 신청한 결제에만 [현금영수증 조회] 버튼을 보인다', () => {
    expect(evaluate(String(button.if), { order: order({ cash_receipt_type: 'income' }), _local: {} })).toBeTruthy();
    expect(evaluate(String(button.if), { order: order({ cash_receipt_type: null }), _local: {} })).toBeFalsy();
  });

  /**
   * @scenario case=guest_lookup
   * @effects lookup_by_order_number_with_guest_token
   */
  it('누르면 주문번호로 영수증 조회를 부르고 비회원 조회 토큰을 헤더에 싣는다', () => {
    expect(String(apiCall.target)).toBe('/api/plugins/sirsoft-pay_nicepayments/user/orders/{{order.data.order_number}}/receipt');
    const headers = (apiCall.params as Node).headers as Record<string, string>;
    expect(evaluate(headers['X-Guest-Order-Token'], { _global: { guestOrderToken: 'guest-token' } })).toBe('guest-token');
    expect(apiCall.auth_mode).toBe('optional');
  });

  /**
   * @scenario case=receipt_issued
   * @effects link_only_when_url_returned
   */
  it('조회 응답에 현금영수증 주소가 있을 때만 링크로 바뀐다', () => {
    const onSuccess = (apiCall.onSuccess as Node).params as Node;
    expect(onSuccess._npMpCashReceiptUrl).toBe('{{response.data.cash_receipt_url}}');
    expect(evaluate(String(link.if), { _local: { _npMpReceiptLoaded: true, _npMpCashReceiptUrl: 'https://npg.nicepay.co.kr/x' } })).toBeTruthy();
    expect(evaluate(String(link.if), { _local: { _npMpReceiptLoaded: true, _npMpCashReceiptUrl: null } })).toBeFalsy();
  });
});
