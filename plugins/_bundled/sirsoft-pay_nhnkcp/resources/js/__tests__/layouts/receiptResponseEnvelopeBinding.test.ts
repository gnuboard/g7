/**
 * @file receiptResponseEnvelopeBinding.test.ts
 * @description NHN KCP 영수증 조회 응답의 공통 형식(data) 소비 — 레이아웃 조각 바인딩 테스트
 *
 * 영수증 조회 API 는 공통 형식({ success, message, data })으로 응답한다. 조각이 최상위(response.receipt_url ·
 * $prev.receipt_url)를 읽으면 버튼은 그려져도 빈 주소를 넘긴다(오류 없음). 마이페이지 주문 상세의 「매출전표 보기」는
 * 직전 apiCall 결과($prev = 응답 본문)에서, 주문 완료 조각은 onSuccess 의 response(응답 본문)에서 data 를 거쳐 읽는다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/receipt-response-envelope.yaml
 */

import { describe, it, expect } from 'vitest';
import orderShowLayout from '../../../extensions/user_order_show.json';
import orderCompleteLayout from '../../../extensions/user_order_complete_receipt.json';

type Node = Record<string, unknown>;

/**
 * 레이아웃 트리를 깊이 우선으로 훑어 조건에 맞는 첫 노드를 찾습니다.
 *
 * @param node 시작 노드
 * @param match 판정 함수
 * @return 찾은 노드(없으면 undefined)
 */
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

describe('NHN KCP 영수증 조회 응답 — 조각이 data 를 읽는다', () => {
  /**
   * 마이페이지 주문 상세의 매출전표 보기는 직전 apiCall 결과의 data 에서 주소를 꺼내 팝업에 넘긴다.
   *
   * @scenario surface=mypage_fragment
   * @effects fragment_reads_envelope_data
   */
  it('마이페이지 주문 상세: 팝업 인자가 $prev.data 의 주소다', () => {
    const popupCall = find(orderShowLayout, (n) => n.handler === 'callExternal'
      && (n.params as Node | undefined)?.constructor === 'KcpReceiptPopup');
    expect(popupCall, 'KcpReceiptPopup 호출을 찾지 못함').toBeDefined();
    const serialized = JSON.stringify(popupCall);
    expect(serialized).toContain('{{$prev.data.receipt_url}}');
    expect(serialized).toContain('{{$prev.data.cash_receipt_url}}');
    expect(JSON.stringify(orderShowLayout)).not.toContain('$prev.receipt_url');
  });

  /**
   * 주문 완료 조각은 onSuccess 응답의 data 에서 영수증·현금영수증 주소를 로컬 상태에 담는다.
   *
   * @scenario surface=order_complete_fragment
   * @effects fragment_reads_envelope_data
   */
  it('주문 완료: 로컬 상태에 response.data 의 주소를 담는다', () => {
    const serialized = JSON.stringify(orderCompleteLayout);
    expect(serialized).toContain('"_kcpOcReceiptUrl":"{{response.data.receipt_url}}"');
    expect(serialized).toContain('"_kcpOcCashReceiptUrl":"{{response.data.cash_receipt_url}}"');
    expect(serialized).not.toContain('{{response.receipt_url}}');
  });
});
