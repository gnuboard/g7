// e2e:allow 레이아웃 표현식 계약 검증([결제하기] 주문 권한 잠금 조건). 결제 흐름은 기존 주문서 E2E 가 다룬다.
/**
 * @file checkout-pay-button-order-permission.test.ts
 * @description 주문서 [결제하기] — 주문 권한이 확인되지 않으면 잠근다(`can_order !== true`, 장바구니·상품과 같은 기준).
 *
 * 쿠폰·배송지·마일리지를 바꿀 때 받는 결제 정보 갱신(PUT) 응답이 checkoutData 를 통째로 갈아끼우므로, 조회와
 * 갱신 응답이 모두 `abilities.can_order` 를 싣는다(서버 쪽은 이커머스 OrderCreateAbilityTest 가 고정).
 */

import { describe, it, expect } from 'vitest';

import checkoutSummary from '../../../layouts/partials/shop/_checkout_summary.json';

/** 조건에 맞는 노드를 트리에서 모두 모은다 */
function collect(node: unknown, match: (value: Record<string, unknown>) => boolean, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, out));

    return out;
  }

  if (node === null || typeof node !== 'object') {
    return out;
  }

  const value = node as Record<string, unknown>;

  if (match(value)) {
    out.push(value);
  }

  Object.values(value).forEach((child) => collect(child, match, out));

  return out;
}

/** `{{ … }}` 식을 주어진 컨텍스트로 평가한다 */
function evaluate(expr: string, ctx: Record<string, unknown>): unknown {
  const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  const names = Object.keys(ctx);

  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${body});`)(...names.map((n) => ctx[n]));
}

const payButton = collect(checkoutSummary, (n) => n.id === 'checkout_pay_button')[0] as { props?: { disabled?: string } } | undefined;

/** 배송지·결제수단이 모두 채워져 다른 잠금 사유가 없는 상태 */
const readyContext = (data: Record<string, unknown>) => ({
  _local: { shipping: { country_code: 'KR', zipcode: '06000', recipient_name: '홍길동', recipient_phone: '01000000000' }, isSubmittingOrder: false },
  _computed: { selectablePaymentMethods: [{ id: 'card' }] },
  checkoutData: { data: { has_unshippable_items: false, ...data } },
});

describe('주문서 [결제하기] — 주문 권한 잠금', () => {
  /** @effects pay_button_locked_when_order_denied */
  it('결제 버튼의 잠금식이 있다', () => {
    expect(typeof payButton?.props?.disabled).toBe('string');
  });

  /**
   * @scenario can_order=denied
   * @effects pay_button_locked_when_order_denied
   */
  it('주문 권한이 false 면 잠근다', () => {
    expect(evaluate(payButton!.props!.disabled!, readyContext({ abilities: { can_order: false } }))).toBe(true);
  });

  /**
   * @scenario can_order=granted
   * @effects pay_button_open_when_order_granted
   */
  it('주문 권한이 true 면 잠그지 않는다', () => {
    expect(evaluate(payButton!.props!.disabled!, readyContext({ abilities: { can_order: true } }))).toBe(false);
  });

  /**
   * @scenario can_order=absent
   * @effects pay_button_locked_when_abilities_absent
   */
  it('abilities 가 없으면 잠근다(fail-closed)', () => {
    expect(evaluate(payButton!.props!.disabled!, readyContext({}))).toBe(true);
  });
});
