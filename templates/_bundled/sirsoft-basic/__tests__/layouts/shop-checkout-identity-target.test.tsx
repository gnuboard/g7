/**
 * @file shop-checkout-identity-target.test.tsx
 * @description 체크아웃 결제 apiCall 의 identity_target 선언 정합 검증.
 *
 * 회귀 차단: 비회원 주문 시 본인인증 정책이 켜져 있으면 주문 POST 가 428 을 받고
 * IDV launcher 가 인증 대상(email/phone)을 challenge 로 보내야 하는데, 결제 apiCall 이
 * identity_target 을 선언하지 않으면 비로그인 흐름에서 target 이 비어 422 missing_target 으로 깨졌다.
 *
 * 결제 apiCall 은 identity_target.{email,phone} 을 표현식으로 선언해야 하며,
 * 주문자 정보 우선 → 회원 세션 → 수취인 연락처 폴백 순서를 따른다.
 *
 * @since engine-v1.51.0
 */

import { describe, it, expect } from 'vitest';
import checkoutSummaryJson from '../../layouts/partials/shop/_checkout_summary.json';

/** 객체 트리에서 조건을 만족하는 첫 노드를 깊이우선 탐색 */
function findNode(node: any, predicate: (n: any) => boolean): any {
  if (node == null || typeof node !== 'object') return undefined;
  if (predicate(node)) return node;
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        const found = findNode(item, predicate);
        if (found !== undefined) return found;
      }
    } else if (value && typeof value === 'object') {
      const found = findNode(value, predicate);
      if (found !== undefined) return found;
    }
  }
  return undefined;
}

/** 주문 생성 POST apiCall 노드 (결제하기 버튼). */
const orderCall = findNode(
  checkoutSummaryJson,
  (n) =>
    n.handler === 'apiCall' &&
    typeof n.target === 'string' &&
    n.target.includes('/user/orders') &&
    n.params?.method === 'POST'
);

describe('체크아웃 결제 identity_target 선언 (_checkout_summary)', () => {
  it('주문 생성 POST apiCall 노드가 존재해야 한다', () => {
    expect(orderCall, '주문 생성 POST apiCall 노드').toBeDefined();
  });

  it('결제 apiCall 은 identity_target.email/phone 을 선언해야 한다 (회귀 차단)', () => {
    expect(orderCall.identity_target).toBeDefined();
    expect(typeof orderCall.identity_target.email).toBe('string');
    expect(typeof orderCall.identity_target.phone).toBe('string');
  });

  it('email 선언은 주문자 정보를 우선 사용한다', () => {
    // ordererDefaults(주문자→회원 폴백 내장) 또는 _local.orderer.email 참조
    expect(orderCall.identity_target.email).toContain('ordererDefaults');
    expect(orderCall.identity_target.email).toContain('orderer');
  });

  it('phone 선언은 주문자 → 회원 → 수취인 연락처 폴백 순서를 따른다', () => {
    const phone = orderCall.identity_target.phone as string;
    expect(phone).toContain('_local.orderer?.phone');
    expect(phone).toContain('currentUser?.phone');
    // 수취인 연락처 폴백
    expect(phone).toContain('shipping?.recipient_phone');
  });

  it('표현식은 fallback(|| \'\')으로 빈 문자열을 보장해야 한다 (undefined 방지)', () => {
    expect(orderCall.identity_target.email).toMatch(/\|\|\s*''/);
    expect(orderCall.identity_target.phone).toMatch(/\|\|\s*''/);
  });
});
