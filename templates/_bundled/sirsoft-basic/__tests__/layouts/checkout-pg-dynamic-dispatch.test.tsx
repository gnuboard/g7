/**
 * @file checkout-pg-dynamic-dispatch.test.tsx
 * @description 체크아웃 결제 진입 provider-agnostic 동적 dispatch 검증.
 *
 * checkout onSuccess 는 특정 PG(sirsoft-tosspayments)에 하드코딩되지 않고, 백엔드 응답이
 * 결제 진입 핸들러 풀네임(pg_payment_handler)을 내려준 경우에만 그 핸들러를 dispatch 한다.
 * 핸들러명 미선언 PG(예: 응답에 pg_payment_handler 없음)는 PG 분기 미발화 → non-PG
 * fallback(비회원 verify → saveGuestOrderToken → 완료 navigate)으로 안전 강하한다.
 *
 * 검증 축:
 *  - 구조: tosspayments 하드코딩 부재, 동적 핸들러 바인딩, pgPaymentData 유지, non-PG fallback 보존
 *  - 조건 평가: if 가 (PG+handler) → true, (PG+handler 부재) → false, (non-PG) → false
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { evaluateStringCondition } from '@core/template-engine/helpers/ConditionEvaluator';
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

/** 주문 생성 POST apiCall 의 onSuccess conditions 노드. */
const orderCall = findNode(
  checkoutSummaryJson,
  (n) =>
    n.handler === 'apiCall' &&
    typeof n.target === 'string' &&
    n.target.includes('/user/orders') &&
    n.params?.method === 'POST'
);

const conditionsNode = findNode(
  orderCall?.onSuccess,
  (n) => n.handler === 'conditions' && Array.isArray(n.conditions)
);

/** PG 결제 진입 분기 = then.params.pgPaymentData 를 가진 conditions 항목. */
const pgBranch = conditionsNode?.conditions?.find(
  (c: any) => c?.then?.params && 'pgPaymentData' in c.then.params
);

/** non-PG fallback 분기 = if 없는 (else) 분기. */
const fallbackBranch = conditionsNode?.conditions?.find((c: any) => c?.if === undefined);

const engine = new DataBindingEngine();

describe('체크아웃 결제 진입 provider-agnostic 동적 dispatch', () => {
  it('주문 생성 POST apiCall + onSuccess conditions 노드가 존재해야 한다', () => {
    expect(orderCall, '주문 생성 POST apiCall 노드').toBeDefined();
    expect(conditionsNode, 'onSuccess conditions 노드').toBeDefined();
  });

  describe('하드코딩 제거 (구조)', () => {
    it('checkout 전체에 특정 PG(sirsoft-tosspayments) 하드코딩이 없어야 한다', () => {
      const raw = JSON.stringify(checkoutSummaryJson);
      expect(raw).not.toContain('sirsoft-tosspayments');
    });

    it('PG 분기의 handler 는 응답값 바인딩이어야 한다 (리터럴 핸들러명 금지)', () => {
      expect(pgBranch, 'PG 결제 진입 분기').toBeDefined();
      expect(pgBranch.then.handler).toBe('{{response.data.pg_payment_handler}}');
    });

    it('PG 분기는 pgPaymentData 를 응답값으로 전달해야 한다', () => {
      expect(pgBranch.then.params.pgPaymentData).toBe('{{response.data.pg_payment_data}}');
    });

    it('PG 분기 if 가드는 requires_pg_payment + pg_payment_handler 존재를 함께 검사해야 한다', () => {
      expect(pgBranch.if).toContain('requires_pg_payment');
      expect(pgBranch.if).toContain('pg_payment_handler');
      // 특정 provider 문자열 비교가 없어야 한다(provider-agnostic).
      expect(pgBranch.if).not.toContain('pg_provider');
    });
  });

  describe('non-PG fallback 보존 (구조)', () => {
    it('if 없는 fallback 분기가 존재해야 한다', () => {
      expect(fallbackBranch, 'non-PG fallback 분기').toBeDefined();
    });

    it('fallback 은 sequence 로 비회원 verify → 완료 navigate 흐름을 유지해야 한다', () => {
      const seq = fallbackBranch.then;
      expect(seq.handler).toBe('sequence');
      const inner = JSON.stringify(seq);
      expect(inner).toContain('saveGuestOrderToken');
      expect(inner).toContain('navigate');
      expect(inner).toContain('/guest/orders/verify');
    });
  });

  describe('if 조건 평가 (런타임 동작)', () => {
    const evalIf = (responseData: Record<string, unknown>) =>
      evaluateStringCondition(pgBranch.if, { response: { data: responseData } }, engine);

    it('PG 결제 + pg_payment_handler 선언 → PG 분기 발화(true)', () => {
      expect(
        evalIf({
          requires_pg_payment: true,
          pg_payment_handler: 'sirsoft-pay_kginicis.requestPayment',
          pg_payment_data: { order_number: 'ORD-1' },
        })
      ).toBe(true);
    });

    it('PG 결제이지만 pg_payment_handler 미선언 → PG 분기 미발화(false) → non-PG fallback', () => {
      expect(
        evalIf({
          requires_pg_payment: true,
          pg_payment_data: { order_number: 'ORD-2' },
          // pg_payment_handler 없음 (provider 가 핸들러 미선언)
        })
      ).toBe(false);
    });

    it('non-PG 결제(requires_pg_payment=false) → PG 분기 미발화(false)', () => {
      expect(
        evalIf({
          requires_pg_payment: false,
        })
      ).toBe(false);
    });

    it('다른 PG provider 라도 pg_payment_handler 만 있으면 발화 (provider-agnostic)', () => {
      expect(
        evalIf({
          requires_pg_payment: true,
          pg_payment_handler: 'vendor-x.checkout',
        })
      ).toBe(true);
    });
  });
});
