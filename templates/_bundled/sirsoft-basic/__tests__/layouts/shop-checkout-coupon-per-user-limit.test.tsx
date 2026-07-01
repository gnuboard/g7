/**
 * @file shop-checkout-coupon-per-user-limit.test.tsx
 * @description 상품 쿠폰 per_user_limit 중복 비활성화 + 적용불가 안내 토스트 구조 검증 (U13b/MP06)
 *
 * - 다른 라인에서 이미 선택된 per_user_limit 쿠폰을 이 라인 드롭다운에서 비활성화한다
 *   (서버가 내려준 item.disabled_coupon_ids 사용).
 * - 비활성화된 쿠폰 라벨에 '이미 적용됨' 안내($t:shop.checkout.coupon_already_used)를 표시한다.
 * - 쿠폰 선택 PUT 응답에 validation_errors 가 있으면 안내 토스트를 띄운다.
 */

import { describe, it, expect } from 'vitest';
import checkoutItemsJson from '../../layouts/partials/shop/_checkout_items.json';
import shopKo from '../../lang/partial/ko/shop.json';
import shopEn from '../../lang/partial/en/shop.json';

/** 트리 전체에서 조건을 만족하는 모든 노드 수집 */
function collectNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (node == null || typeof node !== 'object') return acc;
  if (predicate(node)) acc.push(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) value.forEach((v) => collectNodes(v, predicate, acc));
    else if (value && typeof value === 'object') collectNodes(value, predicate, acc);
  }
  return acc;
}

const serialized = JSON.stringify(checkoutItemsJson);

describe('상품 쿠폰 per_user_limit 중복 비활성화/안내 (_checkout_items)', () => {
  it('두 상품쿠폰 드롭다운(Option)의 disabled 표현식이 item.disabled_coupon_ids 를 사용해야 한다', () => {
    // pcoupon / pcoupon2 Option 노드 (iteration 기반)
    const optionNodes = collectNodes(
      checkoutItemsJson,
      (n) =>
        n.name === 'Option' &&
        typeof n.props?.value === 'string' &&
        (n.props.value.includes('pcoupon.id') || n.props.value.includes('pcoupon2.id'))
    );
    expect(optionNodes.length).toBe(2);
    optionNodes.forEach((opt) => {
      expect(opt.props.disabled, 'disabled 표현식이 없음').toBeTypeOf('string');
      expect(opt.props.disabled).toContain('disabled_coupon_ids');
    });
  });

  it('비활성화된 쿠폰 라벨에 coupon_already_used 안내를 표시해야 한다', () => {
    expect(serialized).toContain('$t:shop.checkout.coupon_already_used');
  });

  it('쿠폰 선택 PUT onSuccess 에 validation_errors 조건부 안내 토스트가 있어야 한다', () => {
    const conditionNodes = collectNodes(
      checkoutItemsJson,
      (n) =>
        n.handler === 'conditions' &&
        JSON.stringify(n.params ?? {}).includes('validation_errors')
    );
    expect(conditionNodes.length).toBeGreaterThanOrEqual(2); // 상품쿠폰 1·2 각 onSuccess
    conditionNodes.forEach((c) => {
      const cond = c.params.conditions[0];
      expect(cond.if).toContain('validation_errors');
      expect(cond.then.handler).toBe('toast');
      expect(cond.then.params.message).toBe('$t:shop.checkout.coupon_not_applied');
    });
  });

  it('신규 i18n 키가 ko/en 양쪽에 정의되어야 한다', () => {
    for (const pack of [shopKo, shopEn] as any[]) {
      expect(pack.checkout.coupon_already_used).toBeTruthy();
      expect(pack.checkout.coupon_not_applied).toBeTruthy();
    }
  });
});
