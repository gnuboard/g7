/**
 * @file checkout-payment-method-selectable.test.ts
 * @description 주문서 결제수단 — 고객이 고를 수 없는 수단(포인트결제·예치금결제·무료)은 목록·기본 선택·잠금 판정에서 빠지는지.
 *
 * 결함: 결제수단 칸·「사용 가능한 결제 방법 없음」 안내·[결제하기] 잠금·기본 선택 계산이 모두 `is_active` 만 봐서,
 *   포인트결제가 켜진 상점(옛 설정·API 저장)에선 주문서에 「포인트결제」 가 나왔다. 서버는 그 수단을 고른 주문을
 *   422(payment_method_not_selectable)로 거절하므로, 고객은 고를 수 있는 것처럼 보이는 수단으로 결제할 수 없었다.
 *   공개 결제 설정은 수단마다 `customer_selectable` 을 싣는다. (이커머스 전수 실측 S4 — S4-D14, EC-206)
 */

import { describe, it, expect } from 'vitest';

import checkout from '../../../layouts/shop/checkout.json';
import checkoutPayment from '../../../layouts/partials/shop/_checkout_payment.json';
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

const methods = [
  { id: 'point', is_active: true, customer_selectable: false },
  { id: 'card', is_active: true, customer_selectable: true },
  { id: 'dbank', is_active: false, customer_selectable: true },
  { id: 'kginicis_samsung_pay', is_active: true, customer_selectable: true, requires_ios: true },
];
const paymentSettings = { data: { order_settings: { payment_methods: methods } } };
const _global = { appConfig: { isIos: false } };

describe('주문서 결제수단 — 고객 선택 가능 수단만', () => {
  const computedDefs = (checkout as Record<string, unknown>).computed as Record<string, string>;

  /**
   * @scenario method_set=point_active
   * @effects non_selectable_hidden
   */
  it('고를 수 있는 결제수단 계산값은 비선택 수단·비활성·iOS 전용(비 iOS)을 뺀다', () => {
    expect(typeof computedDefs.selectablePaymentMethods).toBe('string');
    const list = evaluate(computedDefs.selectablePaymentMethods, { paymentSettings, _global, _local: {}, checkoutData: undefined }) as { id: string }[];

    expect(list.map((m) => m.id)).toEqual(['card']);

    // 결제 통화 허용 목록(주문서 계산 응답 allowed_payment_methods)이 오면 그 밖의 수단도 뺀다 — 같은 계산값이 거른다
    const blocked = evaluate(computedDefs.selectablePaymentMethods, {
      paymentSettings,
      _global,
      _local: {},
      checkoutData: { data: { allowed_payment_methods: ['dbank'] } },
    }) as { id: string }[];

    expect(blocked).toEqual([]);
  });

  /**
   * @scenario method_set=point_active
   * @effects default_selection_skips_non_selectable
   */
  it('기본 선택은 첫 고객 선택 가능 수단이다(포인트결제 아님)', () => {
    const _computed = { selectablePaymentMethods: [{ id: 'card' }] };

    expect(evaluate(computedDefs.selectedPaymentMethod, { paymentSettings, _global, _local: {}, _computed, checkoutData: undefined })).toBe('card');
  });

  /**
   * @scenario method_set=only_non_selectable
   * @effects empty_notice_and_pay_locked
   */
  it('결제수단 칸·빈 목록 안내·결제하기 잠금이 같은 계산값을 본다', () => {
    const list = collect(checkoutPayment, (v) => typeof v.iteration === 'object' && JSON.stringify(v.iteration).includes('method'))[0]
      ?? collect(checkoutPayment, (v) => JSON.stringify(v).includes('data-payment-method') && typeof v.iteration === 'object')[0];
    const raw = JSON.stringify(checkoutPayment);
    const summaryRaw = JSON.stringify(checkoutSummary);

    expect(list).toBeDefined();
    expect(JSON.stringify(list.iteration)).toContain('_computed.selectablePaymentMethods');
    expect(raw).toContain('(_computed.selectablePaymentMethods ?? []).length === 0');
    expect(summaryRaw).toContain('!(_computed.selectablePaymentMethods ?? []).length');
    expect(raw).not.toContain('payment_methods ?? []).filter(m => m.is_active');
    expect(summaryRaw).not.toContain('payment_methods ?? []).some(m => m.is_active');
  });
});
