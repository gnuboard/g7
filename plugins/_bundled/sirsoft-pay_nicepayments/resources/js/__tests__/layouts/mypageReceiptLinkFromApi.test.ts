/**
 * @file mypageReceiptLinkFromApi.test.ts
 * @description 마이페이지 주문 상세 › 나이스페이먼츠 「결제 영수증」 링크가 플러그인 API 주소를 쓰는지 테스트
 *
 * 링크 주소(npg.nicepay.co.kr …)를 조각에 글자 그대로 적으면 레이아웃 편집기 저장 게이트(외부 URL 금지)가
 * 이 조각을 거절한다. 그래서 조각은 마운트 때 영수증 조회 API 를 불러 `payment_receipt_url` 을 로컬 상태에
 * 담고 그 값으로 링크를 그린다. 회원은 토큰(auth_mode=optional), 비회원은 조회 토큰 헤더로 같은 API 를 부른다.
 * 설치되는 조각 원본(JSON)과 저작본(.g7.html)을 함께 보고, 엔진 바인딩으로 링크 주소를 풀어 확인한다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/mypage-receipt-link-from-api.yaml
 */

import { readFileSync } from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
// 코어 엔진은 저장소 루트 기준 상대 경로로 부른다(설정의 `@core` 별칭은 한 단계 모자라 풀리지 않는다).
import { DataBindingEngine } from '../../../../../../../resources/js/core/template-engine/DataBindingEngine';
import extensionLayout from '../../../extensions/user_mypage_order_receipt.json';

const MARKUP_PATH = path.resolve(__dirname, '../../../extensions/user_mypage_order_receipt.g7.html');
const LOCAL_KEY = '_npMpPaymentReceiptUrl';
const RECEIPT_URL = 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=2&TID=NICE_TID_TEST';

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
 * 결제 영수증 링크 노드를 찾습니다.
 *
 * @return 링크 노드
 */
function receiptLink(): Node {
  const link = find(extensionLayout, (n) => {
    const props = n.props as Node | undefined;

    return typeof props?.href === 'string' && (props.href as string).includes(LOCAL_KEY);
  });
  expect(link, '결제 영수증 링크를 찾지 못함').toBeDefined();

  return link as Node;
}

/**
 * 마운트 때 부르는 영수증 조회 apiCall 을 찾습니다.
 *
 * @return apiCall 액션
 */
function mountCall(): Node {
  const call = find(extensionLayout, (n) => n.handler === 'apiCall'
    && typeof n.target === 'string' && (n.target as string).endsWith('/user/orders/{{order.data.order_number}}/receipt'));
  expect(call, '영수증 조회 apiCall 을 찾지 못함').toBeDefined();

  return call as Node;
}

/**
 * 링크 주소와 표시 조건을 엔진으로 풉니다.
 *
 * @param receiptUrl API 가 준 주소(없으면 undefined)
 * @return 풀린 href 와 표시 여부
 */
function renderLink(receiptUrl: string | undefined): { href: unknown; visible: boolean } {
  const link = receiptLink();
  const context = { _local: receiptUrl === undefined ? {} : { [LOCAL_KEY]: receiptUrl } };
  const engine = new DataBindingEngine();

  return {
    href: engine.resolveBindings((link.props as Node).href as string, context),
    visible: Boolean(engine.resolveBindings(link.if as string, context)),
  };
}

describe('마이페이지 나이스페이먼츠 결제 영수증 링크', () => {
  /**
   * @effects no_external_url_literal_in_fragment
   */
  it('조각 원본과 저작본 어디에도 결제사 주소를 글자 그대로 적지 않는다', () => {
    expect(JSON.stringify(extensionLayout)).not.toContain('npg.nicepay.co.kr');
    expect(readFileSync(MARKUP_PATH, 'utf8')).not.toContain('npg.nicepay.co.kr');
  });

  /**
   * @scenario viewer=member, receipt=issued
   * @effects receipt_link_uses_api_url, response_uses_common_envelope
   */
  it('회원: 토큰을 실어(선택 인증) 조회 API 를 부르고 받은 주소로 링크를 그린다', () => {
    const call = mountCall();
    expect(call.auth_required).toBe(true);
    expect(call.auth_mode).toBe('optional');
    expect((call.onSuccess as Node).params).toMatchObject({ target: 'local', [LOCAL_KEY]: '{{response.data.payment_receipt_url}}' });

    expect(renderLink(RECEIPT_URL)).toEqual({ href: RECEIPT_URL, visible: true });
  });

  /**
   * @scenario viewer=guest, receipt=issued
   * @effects receipt_link_uses_api_url, guest_token_header_sent
   */
  it('비회원: 조회 토큰 헤더를 실어 같은 API 를 부르고 받은 주소로 링크를 그린다', () => {
    const headers = ((mountCall().params as Node).headers ?? {}) as Node;
    expect(String(headers['X-Guest-Order-Token'])).toContain('_global.guestOrderToken');

    expect(renderLink(RECEIPT_URL)).toEqual({ href: RECEIPT_URL, visible: true });
  });

  /**
   * @scenario viewer=member, receipt=missing
   * @effects link_hidden_without_url
   */
  it('회원: API 가 주소를 주지 않으면 링크를 그리지 않는다', () => {
    expect(renderLink(undefined).visible).toBe(false);
  });

  /**
   * @scenario viewer=guest, receipt=missing
   * @effects link_hidden_without_url
   */
  it('비회원: 조회에 실패해 주소가 없으면 링크를 그리지 않는다', () => {
    expect(renderLink(undefined).visible).toBe(false);
  });
  /**
   * @effects order_number_from_loaded_order
   */
  it('주문번호는 불러온 주문(order.data.order_number)에서 읽는다 — 주소에 주문번호가 없는 신청서 상세에서도 같은 조각이 쓰인다', () => {
    const urls: string[] = [];
    find(extensionLayout, (n) => {
      if (typeof n.target === 'string' && (n.target as string).includes('/orders/')) urls.push(n.target as string);
      const props = n.props as Node | undefined;
      if (typeof props?.href === 'string' && (props.href as string).includes('/')) urls.push(props.href as string);

      return false;
    });
    const orderUrls = urls.filter((u) => u.includes('/orders/') || u.includes('escrow-confirm'));
    expect(orderUrls.length).toBeGreaterThan(0);
    for (const url of orderUrls) {
      expect(url, url).not.toContain('route.');
      expect(url, url).toContain('{{order.data.order_number}}');
    }
  });
});
