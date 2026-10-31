/**
 * @file order-history-delivered-at.test.ts
 * @description 주문 상세 「주문 이력」의 배송완료일시가 실제 배송 완료 시각으로 채워지는지 (레이아웃 구조 + 표현식 평가).
 *
 * 결함: 배송완료일시 줄이 `order.data.delivered_at` 을 봤는데, 주문 응답의 이 값은 주문 테이블에 칼럼이 없어
 *   늘 null 이다(배송 완료 시각은 배송 행 `shippings[].delivered_at` 에 있다). 그래서 배송을 마친 주문에도 이 줄이
 *   한 번도 나오지 않았다. (이커머스 전수 실측 S4 — S4-D11, 주문 290: 배송 행 delivered_at 있음·주문 delivered_at null)
 */

import { describe, it, expect } from 'vitest';

import orderHistory from '../../../layouts/partials/mypage/orders/_history.json';

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

/** `{{ … }}` 식을 order 컨텍스트로 평가한다 */
function evaluate(expr: string, order: unknown): unknown {
  const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');

  // eslint-disable-next-line no-new-func
  return new Function('order', `return (${body});`)(order);
}

describe('주문 이력 — 배송완료일시', () => {
  const row = collect(orderHistory, (value) => JSON.stringify(value.children ?? []).includes('$t:mypage.order_detail.delivered_at') && typeof value.if === 'string')[0];
  const valueText = collect(row, (value) => typeof value.text === 'string' && value.text.includes('delivered_at') && !value.text.startsWith('$t:'))[0];

  const delivered = { data: { delivered_at: null, shippings: [
    { shipping_status: 'delivered', delivered_at: '2026-10-07T02:10:00+00:00', delivered_at_formatted: '2026-10-07 11:10:00' },
    { shipping_status: 'delivered', delivered_at: '2026-10-07T02:12:37+00:00', delivered_at_formatted: '2026-10-07 11:12:37' },
  ] } };
  const notDelivered = { data: { delivered_at: null, shippings: [{ shipping_status: 'shipping', delivered_at: null, delivered_at_formatted: null }] } };

  /**
   * @scenario shipping_state=delivered
   * @effects delivered_row_shows_latest_shipping_delivery
   */
  it('배송을 마친 주문은 배송 행의 가장 늦은 완료 시각을 보인다', () => {
    expect(row).toBeDefined();
    expect(evaluate(row.if as string, delivered)).toBeTruthy();
    expect(evaluate(valueText.text as string, delivered)).toBe('2026-10-07 11:12:37');
  });

  /**
   * @scenario shipping_state=not_delivered
   * @effects delivered_row_hidden_until_delivery
   */
  it('배송 전 주문은 줄을 숨긴다', () => {
    expect(evaluate(row.if as string, notDelivered)).toBeFalsy();
  });
});
