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

describe('주문완료 화면 마일리지 표시 (order_complete)', () => {
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
