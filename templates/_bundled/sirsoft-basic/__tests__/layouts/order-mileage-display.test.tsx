/**
 * @file order-mileage-display.test.tsx
 * @description 주문 관련 화면(체크아웃 요약/주문완료/마이페이지 목록)의 마일리지 표시 정합 검증
 *
 * 이슈 #44 전수조사: 마일리지 시스템 도입 전 완성된 주문 화면들이
 * 마일리지 사용/적립 행을 누락하거나, 백엔드가 노출하지 않는 키를 바인딩하던 회귀를 차단한다.
 *
 * 백엔드 정합 SSoT:
 *  - 체크아웃 요약은 summary.points_used_formatted (Summary::toArray 가 노출)
 *  - 주문완료/목록은 order.total_points_used_amount(_formatted) / total_earned_points_amount(_formatted)
 *    (OrderResource / GuestOrderResource / UserOrderListResource 가 노출)
 */

import { describe, it, expect } from 'vitest';
import checkoutSummaryJson from '../../layouts/partials/shop/_checkout_summary.json';
import orderCompleteJson from '../../layouts/shop/order_complete.json';
import mypageOrderListJson from '../../layouts/partials/mypage/orders/_list.json';

/** 트리 전체를 문자열로 직렬화 (바인딩 표현식 검증용) */
function serialize(node: unknown): string {
  return JSON.stringify(node);
}

/** 객체 트리에서 조건을 만족하는 노드를 모두 깊이우선 수집 */
function collectNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (node == null || typeof node !== 'object') return acc;
  if (predicate(node)) acc.push(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) {
      for (const item of value) collectNodes(item, predicate, acc);
    } else if (value && typeof value === 'object') {
      collectNodes(value, predicate, acc);
    }
  }
  return acc;
}

describe('체크아웃 요약 마일리지 표시 정합 (_checkout_summary)', () => {
  const serialized = serialize(checkoutSummaryJson);

  /**
   * @scenario points=used
   * @effects checkout_points_used_row_binds_exposed_key
   */
  it('마일리지 사용 행은 summary.points_used_formatted 를 바인딩한다 (백엔드 노출 키)', () => {
    // Summary::toArray() 가 points_used_formatted 를 노출하므로 이 바인딩이 정합.
    expect(serialized).toContain('points_used_formatted');
    // 사용 행은 points_used > 0 조건으로 노출
    const usedRow = collectNodes(
      checkoutSummaryJson,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('summary?.points_used') &&
        n.if.includes('> 0')
    );
    expect(usedRow.length, '마일리지 사용 행이 points_used > 0 조건으로 노출되어야 함').toBeGreaterThan(0);
  });
});

/**
 * 「결제 예정금액」 행은 마일리지 차감 전 금액(summary.payment_amount)인데 마일리지 사용 행 아래에 있어
 * 차감 후 금액처럼 읽혔고, 관리자 화면의 「결제예정금액」(차감 후)과 같은 말이 두 뜻으로 쓰였다.
 * 라벨을 「마일리지 차감 전 금액」으로 바꾸고 마일리지 사용 행 위로 옮기며, 마일리지를 쓸 때만 보인다.
 * 장바구니 요약(shop.cart.payment_amount)은 그대로 둔다.
 */
describe('체크아웃 요약 마일리지 차감 전 금액 행 (_checkout_summary)', () => {
  /** 요약 행 목록(마일리지 사용 행을 담은 부모의 children) */
  function summaryRows(): any[] {
    const parents = collectNodes(
      checkoutSummaryJson,
      (n) =>
        Array.isArray(n.children) &&
        n.children.some((c: any) =>
          (c?.children ?? []).some((s: any) => s?.text === '$t:shop.checkout.points_used')
        )
    );
    expect(parents.length, '마일리지 사용 행의 부모가 있어야 함').toBe(1);
    return parents[0].children;
  }

  const rowIndex = (rows: any[], labelKey: string) =>
    rows.findIndex((r) => (r.children ?? []).some((c: any) => c.text === labelKey));

  /**
   * @scenario points=used
   * @effects amount_before_points_row_above_points_used
   */
  it('차감 전 금액 행이 마일리지 사용 행보다 앞에 있다', () => {
    const rows = summaryRows();
    const before = rowIndex(rows, '$t:shop.checkout.amount_before_points');
    const used = rowIndex(rows, '$t:shop.checkout.points_used');
    expect(before, '차감 전 금액 행이 요약 행 목록에 있어야 함').toBeGreaterThanOrEqual(0);
    expect(before).toBeLessThan(used);
  });

  /**
   * @scenario points=used
   * @effects amount_before_points_label_distinct_from_payment_due
   */
  it('라벨 키는 shop.checkout.amount_before_points 이고 값은 summary.payment_amount 를 바인딩한다', () => {
    const rows = summaryRows();
    const row = rows[rowIndex(rows, '$t:shop.checkout.amount_before_points')];
    expect(row).toBeDefined();
    expect(serialize(row)).toContain('payment_amount_formatted');
    // 주문서 요약은 장바구니 라벨(결제 예정금액)을 더 이상 쓰지 않는다
    expect(serialize(checkoutSummaryJson)).not.toContain('$t:shop.cart.payment_amount"');
  });

  /**
   * @scenario points=none
   * @effects amount_before_points_row_hidden_without_points
   */
  it('차감 전 금액 행은 points_used > 0 일 때만 보인다', () => {
    const rows = summaryRows();
    const row = rows[rowIndex(rows, '$t:shop.checkout.amount_before_points')];
    expect(row).toBeDefined();
    expect(row.if).toBe('{{(checkoutData.data.calculation?.summary?.points_used ?? 0) > 0}}');
  });
});

describe('주문완료 화면 마일리지 표시 (order_complete)', () => {
  /**
   * @scenario points=used
   * @effects order_complete_shows_points_used_and_earned
   */
  it('결제 요약에 마일리지 사용 행(total_points_used_amount)이 존재한다', () => {
    const usedRow = collectNodes(
      orderCompleteJson,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('total_points_used_amount') &&
        n.if.includes('> 0')
    );
    expect(usedRow.length, '마일리지 사용 행이 누락되면 안 됨').toBeGreaterThan(0);
    // 값은 total_points_used_amount_formatted 를 바인딩
    expect(serialize(orderCompleteJson)).toContain('total_points_used_amount_formatted');
  });

  /**
   * @scenario points=used
   * @effects order_complete_shows_points_used_and_earned
   */
  it('결제 요약에 적립 예정 행(total_earned_points_amount)이 존재한다', () => {
    const earnRow = collectNodes(
      orderCompleteJson,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('total_earned_points_amount') &&
        n.if.includes('> 0')
    );
    expect(earnRow.length, '적립 예정 행이 누락되면 안 됨').toBeGreaterThan(0);
    expect(serialize(orderCompleteJson)).toContain('total_earned_points_amount_formatted');
  });
});

describe('마이페이지 주문목록 마일리지 표시 (_list)', () => {
  const serialized = serialize(mypageOrderListJson);

  /**
   * @scenario points=used
   * @effects mypage_order_card_shows_points_used_and_earned
   */
  it('주문 카드에 마일리지 사용 표시(total_points_used_amount)가 존재한다', () => {
    const usedRow = collectNodes(
      mypageOrderListJson,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('order.total_points_used_amount') &&
        n.if.includes('> 0')
    );
    expect(usedRow.length).toBeGreaterThan(0);
    expect(serialized).toContain('total_points_used_amount_formatted');
  });

  /**
   * @scenario points=used
   * @effects mypage_order_card_shows_points_used_and_earned
   */
  it('주문 카드에 적립 예정 표시(total_earned_points_amount)가 존재한다', () => {
    const earnRow = collectNodes(
      mypageOrderListJson,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('order.total_earned_points_amount') &&
        n.if.includes('> 0')
    );
    expect(earnRow.length).toBeGreaterThan(0);
    expect(serialized).toContain('total_earned_points_amount_formatted');
  });
});
