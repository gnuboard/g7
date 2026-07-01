/**
 * @file shop-checkout-mileage-binding.test.tsx
 * @description 체크아웃 마일리지 사용 섹션(_checkout_mileage)의 응답 키 바인딩 정합 검증
 *
 * 회귀 차단: 레이아웃이 체크아웃 API 응답에 존재하지 않는 키
 * (`mileage.balance`, `mileage.balance_formatted`, `mileage.max_usable_formatted`)
 * 를 바인딩해 보유 마일리지가 항상 "0 / 부족합니다" 로 표시되던 버그.
 *
 * 실제 응답(getBalance + max_usable)이 노출하는 canonical 키는:
 *   mileage.available, mileage.max_usable, mileage.by_currency
 * 마일리지는 base_currency 기준 단일 값이므로 표시통화와 무관하게 available/max_usable 을 사용한다.
 */

import { describe, it, expect } from 'vitest';
import checkoutMileageJson from '../../layouts/partials/shop/_checkout_mileage.json';

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

/** 트리 전체를 문자열로 직렬화 (바인딩 표현식 부재 검증용) */
const serialized = JSON.stringify(checkoutMileageJson);

describe('체크아웃 마일리지 base 통화 사용 게이트 (M2)', () => {
  it('기본 통화 마일리지 사용 규칙 미설정(mileage.usable === false) 시 사용 섹션을 숨긴다', () => {
    // 최상위 게이트 if 에 usable 조건이 포함되어야 한다
    expect((checkoutMileageJson as any).if).toContain('mileage?.usable !== false');
  });
});

describe('체크아웃 마일리지 바인딩 정합 (_checkout_mileage)', () => {
  it('응답에 존재하지 않는 키(balance / *_formatted)를 바인딩하지 않아야 한다 (회귀 차단)', () => {
    expect(serialized).not.toContain('mileage?.balance_formatted');
    expect(serialized).not.toContain('mileage?.max_usable_formatted');
    // mileage.balance (available 아님) 직접 참조 금지
    expect(serialized).not.toMatch(/mileage\?\.balance(?![_a-zA-Z])/);
  });

  it('보유 마일리지 표시는 mileage.available 를 toLocaleString 으로 포맷해야 한다', () => {
    const availableSpan = findNode(
      checkoutMileageJson,
      (n) =>
        n.name === 'Span' &&
        typeof n.text === 'string' &&
        n.text.includes('mileage?.available') &&
        n.text.includes('toLocaleString')
    );
    expect(availableSpan, '보유 마일리지(available) 표시 노드가 존재해야 함').toBeDefined();
  });

  it('최대 사용 안내(if/텍스트)는 mileage.max_usable 를 사용해야 한다', () => {
    const maxHint = findNode(
      checkoutMileageJson,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('mileage?.max_usable') &&
        typeof n.text === 'string' &&
        n.text.includes('mileage?.max_usable')
    );
    expect(maxHint, '최대 사용 안내 노드가 max_usable 를 바인딩해야 함').toBeDefined();
    expect(maxHint.text).toContain('toLocaleString');
  });

  it('입력 disabled 와 "부족" 안내 조건은 mileage.available === 0 으로 판정해야 한다', () => {
    const input = findNode(
      checkoutMileageJson,
      (n) => n.name === 'Input' && n.props?.name === 'use_points'
    );
    expect(input).toBeDefined();
    expect(input.props.disabled).toBe('{{(checkoutData.data.mileage?.available ?? 0) === 0}}');
    // 입력 max 는 max_usable
    expect(input.props.max).toBe('{{checkoutData.data.mileage?.max_usable ?? 0}}');

    const insufficient = findNode(
      checkoutMileageJson,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('mileage?.available') &&
        n.text === '$t:shop.checkout.mileage_insufficient'
    );
    expect(insufficient, '"부족" 안내가 available 조건으로 판정되어야 함').toBeDefined();
  });

  it('전액 사용/적용 버튼은 max_usable 기준으로 동작해야 한다', () => {
    const useAll = findNode(
      checkoutMileageJson,
      (n) =>
        n.name === 'Button' &&
        n.text === '$t:shop.checkout.mileage_use_all'
    );
    expect(useAll).toBeDefined();
    expect(useAll.props.disabled).toBe('{{(checkoutData.data.mileage?.max_usable ?? 0) === 0}}');
  });

  it('마일리지 사용 섹션은 mileage.enabled 일 때만 노출된다 (비활성 시 숨김)', () => {
    // 최상위 if 가 로그인 + enabled 두 조건을 모두 포함해야 한다.
    expect(checkoutMileageJson.if).toContain('currentUser?.uuid');
    expect(checkoutMileageJson.if).toContain('checkoutData.data.mileage?.enabled');
  });

  it('마일리지 적용 apiCall(PUT /checkout)은 auth_mode 를 선언해 Bearer 토큰을 첨부해야 한다 (회귀 차단)', () => {
    // auth_mode 누락 시 ActionDispatcher.handleApiCall 의 기본값 'none' 으로 토큰 미첨부 →
    // 로그인 회원도 Auth::id()=null 로 해석되어 임시주문 조회 실패(500) 회귀.
    // 다른 체크아웃 PUT(_checkout_discount/_checkout_shipping)과 동일하게 'optional' 사용.
    const applyCall = findNode(
      checkoutMileageJson,
      (n) =>
        n.handler === 'apiCall' &&
        typeof n.target === 'string' &&
        n.target.includes('/checkout') &&
        n.params?.method === 'PUT'
    );
    expect(applyCall, '마일리지 적용 PUT apiCall 노드가 존재해야 함').toBeDefined();
    expect(applyCall.auth_mode).toBe('optional');
  });
});
