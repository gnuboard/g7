/**
 * @file mypageReceiptButtonOrderScope.test.ts
 * @description 마이페이지 주문 상세 › NHN KCP 「매출전표 보기」 버튼이 부르는 영수증 조회 요청의 대상·자격 증명 테스트
 *
 * 이 조각은 공용 결제 정보 카드 슬롯(mypage_order_payment_provider_slot)에 붙는다. 그 카드는 주문 상세뿐 아니라
 * 신청서 상세(주소에 주문번호가 없는 /mypage/claims/{신청번호} · 비회원 신청서 상세)에서도 쓰이므로, 주문번호는
 * 주소(route)가 아니라 불러온 주문(order.data.order_number)에서 읽어야 한다. 또 회원·비회원 주문 상세가 같은
 * 조각을 쓰므로 회원은 토큰(auth_mode=optional), 비회원은 조회 토큰 헤더를 실어 같은 API 를 부른다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/mypage-receipt-button-order-scope.yaml
 */

import { readFileSync } from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import extensionLayout from '../../../extensions/user_order_show.json';

const MARKUP_PATH = path.resolve(__dirname, '../../../extensions/user_order_show.g7.html');

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

/**
 * 매출전표 조회 apiCall 을 찾습니다.
 *
 * @return apiCall 액션
 */
function receiptCall(): Node {
  const call = find(extensionLayout, (n) => n.handler === 'apiCall'
    && typeof n.target === 'string' && (n.target as string).includes('/api/plugins/sirsoft-pay_nhnkcp/user/orders/'));
  expect(call, '매출전표 조회 apiCall 을 찾지 못함').toBeDefined();

  return call as Node;
}

describe('마이페이지 NHN KCP 매출전표 보기 버튼', () => {
  /**
   * @scenario viewer=member
   * @effects order_number_from_loaded_order
   */
  it('주문번호는 주소가 아니라 불러온 주문에서 읽는다 (신청서 상세에서도 같은 조각이 쓰인다)', () => {
    expect(receiptCall().target).toBe('/api/plugins/sirsoft-pay_nhnkcp/user/orders/{{order.data.order_number}}/receipt');
    // 같은 조각의 모의입금 데이터 소스는 주문 상세 주소의 주문번호를 쓴다(신청서 상세에서는 부르지 않는다) —
    // 매출전표 요청만 주소를 읽지 않는지 본다.
    const markup = readFileSync(MARKUP_PATH, 'utf8');
    const receiptMarkup = markup.slice(markup.indexOf('kcp-mp-receipt-row'), markup.indexOf('nhnkcp-mock-deposit'));
    expect(receiptMarkup).toContain('/receipt');
    expect(receiptMarkup).not.toContain('route.');
  });

  /**
   * @scenario viewer=member
   * @effects member_token_sent_optionally
   */
  it('회원: 토큰을 싣되(선택 인증) 토큰이 없어도 요청을 막지 않는다', () => {
    const call = receiptCall();
    expect(call.auth_required).toBe(true);
    expect(call.auth_mode).toBe('optional');
  });

  /**
   * @scenario viewer=guest
   * @effects guest_token_header_sent
   */
  it('비회원: 조회 토큰 헤더를 싣는다 (전역 헤더는 이 플러그인 주소에 붙지 않는다)', () => {
    const headers = ((receiptCall().params as Node).headers ?? {}) as Node;
    expect(String(headers['X-Guest-Order-Token'])).toContain('_global.guestOrderToken');
  });
});
