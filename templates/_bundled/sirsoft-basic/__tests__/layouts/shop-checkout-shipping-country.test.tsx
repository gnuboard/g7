/**
 * @file shop-checkout-shipping-country.test.tsx
 * @description 체크아웃 배송국가 → 배송비 계산 연동 정합 검증 (구조 회귀 차단)
 *
 * 회귀 배경: 헤더/계정 배송국가가 US 여도 주문서 배송비가 KR(기본) 로 계산되던 버그.
 *   원인 3중 단절 —
 *     A1) checkout.json initLocal 의 country_code 가 "KR" 하드코딩이라 헤더값 무시
 *     A2) checkoutData GET 이 country_code 를 서버에 전달하지 않아 서버가 KR 폴백
 *     A3) 배송국가 Select 변경 시 PUT /checkout(재계산) 호출이 없어 국가만 바뀌고 배송비 그대로
 *
 * 설계 원칙(확정): 주문서에서 선택한 배송국가가 SSoT.
 *   헤더는 "기본값" 만 제공(_global.preferredShippingCountry), 진입 후엔 주문서 Select 가 우선.
 *   우편번호는 국가 결정과 무관 — 도서산간/주별 세부 차등에만 관여.
 */

import { describe, it, expect } from 'vitest';
import checkoutJson from '../../layouts/shop/checkout.json';
import shippingJson from '../../layouts/partials/shop/_checkout_shipping.json';

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

const checkoutSerialized = JSON.stringify(checkoutJson);

describe('A1: 배송국가 초기 시드 (헤더/계정값 → 주문서)', () => {
  it('initLocal.checkout 은 country_code 를 "KR" 로 하드코딩하지 않는다 (회귀 차단)', () => {
    const initCheckout = (checkoutJson as any).initLocal?.checkout ?? {};
    // 정적 KR 하드코딩이 제거되어 있어야 한다 (init_actions 가 동적으로 시드)
    expect(initCheckout.country_code).toBeUndefined();
  });

  it('init_actions 가 _local.checkout.country_code 를 _global.preferredShippingCountry 로 시드한다', () => {
    const seed = findNode(
      checkoutJson,
      (n) =>
        n.handler === 'setState' &&
        n.params?.target === 'local' &&
        typeof n.params?.['checkout.country_code'] === 'string' &&
        n.params['checkout.country_code'].includes('preferredShippingCountry')
    );
    expect(seed, 'preferredShippingCountry 시드 setState 가 init_actions 에 있어야 함').toBeDefined();
  });
});

describe('A2: checkoutData GET 이 배송국가를 서버로 전달', () => {
  it('checkoutData 데이터소스가 country_code 쿼리 파라미터를 보낸다', () => {
    const ds = ((checkoutJson as any).data_sources ?? []).find(
      (d: any) => d.id === 'checkoutData'
    );
    expect(ds, 'checkoutData 데이터소스가 존재해야 함').toBeDefined();
    expect(ds.method).toBe('GET');
    expect(ds.params?.country_code, 'country_code 쿼리 파라미터가 정의되어야 함').toBeDefined();
    // 주문서 선택값 우선, 미설정 시 헤더/기본국가 fallback
    expect(ds.params.country_code).toContain('checkout?.country_code');
    expect(ds.params.country_code).toContain('preferredShippingCountry');
  });
});

describe('A3: 배송국가 Select 변경 → PUT /checkout 재계산 (옵션1 핵심)', () => {
  it('배송국가 Select(name=country_code) change 액션에 PUT /checkout 호출이 있다', () => {
    const select = findNode(
      shippingJson,
      (n) => n.name === 'Select' && n.props?.name === 'country_code'
    );
    expect(select, '배송국가 Select 노드가 존재해야 함').toBeDefined();

    const changeAction = (select.actions ?? []).find((a: any) => a.type === 'change');
    expect(changeAction, 'Select change 액션이 존재해야 함').toBeDefined();

    const putCall = findNode(
      changeAction,
      (n) =>
        n.handler === 'apiCall' &&
        typeof n.target === 'string' &&
        n.target.includes('/checkout') &&
        n.params?.method === 'PUT'
    );
    expect(
      putCall,
      '배송국가 변경 시 PUT /checkout 재계산 호출이 있어야 함 (국가만 바뀌고 배송비 그대로인 회귀 차단)'
    ).toBeDefined();
    // 다른 체크아웃 PUT 과 동일하게 회원 토큰 첨부
    expect(putCall.auth_mode).toBe('optional');
    // 변경된 country_code 가 포함된 _local.checkout 을 body 로 전송
    expect(putCall.params.body).toContain('_local.checkout');

    // 재계산 결과를 checkoutData 에 반영
    const onSuccess = putCall.onSuccess ?? [];
    const updateDs = onSuccess.find(
      (a: any) => a.handler === 'updateDataSource' && a.params?.dataSourceId === 'checkoutData'
    );
    expect(updateDs, '재계산 응답을 checkoutData 에 반영해야 함').toBeDefined();
  });

  it('Select change 가 _local.checkout.country_code 를 갱신한다 (PUT body 에 반영되도록)', () => {
    const select = findNode(
      shippingJson,
      (n) => n.name === 'Select' && n.props?.name === 'country_code'
    );
    const setCountry = findNode(
      select,
      (n) =>
        n.handler === 'setState' &&
        n.params?.['checkout.country_code'] === '{{$event.target.value}}'
    );
    expect(setCountry, '선택값으로 checkout.country_code 를 갱신해야 함').toBeDefined();
  });
});
