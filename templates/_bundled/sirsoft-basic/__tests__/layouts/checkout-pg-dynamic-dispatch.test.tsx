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

/**
 * PG 결제 진입 분기 = if 가드가 pg_payment_handler 를 보는 conditions 항목.
 * then 은 sequence 다 — 결제창을 열기 전에 비회원 조회 토큰을 먼저 발급해야 하므로
 * (결제창을 닫으면 곧바로 취소 이력 기록이 호출되고 그 엔드포인트는 소유권을 대조한다),
 * 실제 dispatch 액션은 그 sequence 안에 있다.
 */
const pgBranch = conditionsNode?.conditions?.find(
  (c: any) => typeof c?.if === 'string' && c.if.includes('pg_payment_handler')
);

/** PG 분기 안의 실제 결제 진입 dispatch 액션 = params.pgPaymentData 를 가진 노드. */
const pgDispatch = findNode(
  pgBranch?.then,
  (n) => n?.params && typeof n.params === 'object' && 'pgPaymentData' in n.params
);

/** non-PG fallback 분기 = if 없는 (else) 분기. */
const fallbackBranch = conditionsNode?.conditions?.find((c: any) => c?.if === undefined);

const engine = new DataBindingEngine();

describe('체크아웃 결제 진입 provider-agnostic 동적 dispatch', () => {
  /** @effects checkout_dispatches_response_payment_handler */
  it('주문 생성 POST apiCall + onSuccess conditions 노드가 존재해야 한다', () => {
    expect(orderCall, '주문 생성 POST apiCall 노드').toBeDefined();
    expect(conditionsNode, 'onSuccess conditions 노드').toBeDefined();
  });

  describe('하드코딩 제거 (구조)', () => {
    /** @effects checkout_dispatches_response_payment_handler */
    it('checkout 전체에 특정 PG(sirsoft-tosspayments) 하드코딩이 없어야 한다', () => {
      const raw = JSON.stringify(checkoutSummaryJson);
      expect(raw).not.toContain('sirsoft-tosspayments');
    });

    /** @effects checkout_dispatches_response_payment_handler */
    it('PG 분기의 handler 는 응답값 바인딩이어야 한다 (리터럴 핸들러명 금지)', () => {
      expect(pgBranch, 'PG 결제 진입 분기').toBeDefined();
      expect(pgDispatch, 'PG 동적 dispatch 액션').toBeDefined();
      expect(pgDispatch.handler).toBe('{{response.data.pg_payment_handler}}');
    });

    /** @effects checkout_dispatches_response_payment_handler */
    it('PG 분기는 pgPaymentData 를 응답값으로 전달해야 한다', () => {
      expect(pgDispatch.params.pgPaymentData).toBe('{{response.data.pg_payment_data}}');
    });

    /** @effects checkout_pg_branch_skipped_when_no_payment_handler */
    it('PG 분기 if 가드는 requires_pg_payment + pg_payment_handler 존재를 함께 검사해야 한다', () => {
      expect(pgBranch.if).toContain('requires_pg_payment');
      expect(pgBranch.if).toContain('pg_payment_handler');
      // 특정 provider 문자열 비교가 없어야 한다(provider-agnostic).
      expect(pgBranch.if).not.toContain('pg_provider');
    });
  });

  describe('non-PG fallback 보존 (구조)', () => {
    /** @effects checkout_non_pg_fallback_preserved_guest_verify_chain */
    it('if 없는 fallback 분기가 존재해야 한다', () => {
      expect(fallbackBranch, 'non-PG fallback 분기').toBeDefined();
    });

    /** @effects checkout_non_pg_fallback_preserved_guest_verify_chain */
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

    /** @effects checkout_dispatches_response_payment_handler */
    it('PG 결제 + pg_payment_handler 선언 → PG 분기 발화(true)', () => {
      expect(
        evalIf({
          requires_pg_payment: true,
          pg_payment_handler: 'sirsoft-pay_kginicis.requestPayment',
          pg_payment_data: { order_number: 'ORD-1' },
        })
      ).toBe(true);
    });

    /** @effects checkout_pg_branch_skipped_when_no_payment_handler */
    it('PG 결제이지만 pg_payment_handler 미선언 → PG 분기 미발화(false) → non-PG fallback', () => {
      expect(
        evalIf({
          requires_pg_payment: true,
          pg_payment_data: { order_number: 'ORD-2' },
          // pg_payment_handler 없음 (provider 가 핸들러 미선언)
        })
      ).toBe(false);
    });

    /** @effects checkout_pg_branch_skipped_when_no_payment_handler */
    it('non-PG 결제(requires_pg_payment=false) → PG 분기 미발화(false)', () => {
      expect(
        evalIf({
          requires_pg_payment: false,
        })
      ).toBe(false);
    });

    /** @effects checkout_dispatches_response_payment_handler */
    it('다른 PG provider 라도 pg_payment_handler 만 있으면 발화 (provider-agnostic)', () => {
      expect(
        evalIf({
          requires_pg_payment: true,
          pg_payment_handler: 'vendor-x.checkout',
        })
      ).toBe(true);
    });
  });

  /**
   * 결제창을 열기 전 비회원 조회 토큰 확보 순서 (KVE-2026-2434).
   *
   * 결제창을 닫으면 PG 플러그인의 close-report 가 이 토큰으로 주문 소유자를 증명한다. 주문 생성 응답이
   * 토큰을 내려주면 그것을 그대로 저장하고(1순위), 없을 때만 전화번호+조회 비밀번호 verify 로 발급받는다(2순위).
   */
  describe('PG 분기 비회원 조회 토큰 확보', () => {
    const tokenConditions = findNode(
      pgBranch?.then,
      (n) => n.handler === 'conditions' && JSON.stringify(n).includes('saveGuestOrderToken')
    );
    const [first, second] = tokenConditions?.conditions ?? [];
    const evalBranch = (branch: any, ctx: Record<string, unknown>) =>
      evaluateStringCondition(branch.if, ctx, engine);

    /** @effects checkout_pg_branch_saves_order_response_guest_token_first */
    it('1순위는 주문 생성 응답의 guest_order_token 을 그대로 저장한다', () => {
      expect(first?.then?.handler).toBe('saveGuestOrderToken');
      expect(first.then.params.token).toBe('{{response.data.guest_order_token}}');
      expect(first.then.params.expiresAt).toBe('{{response.data.guest_order_token_expires_at}}');
    });

    /** @effects checkout_pg_branch_falls_back_to_guest_verify_without_response_token */
    it('2순위는 종전 verify 호출이다 (토큰을 내려주지 않는 이전 이커머스 모듈 호환)', () => {
      expect(JSON.stringify(second?.then)).toContain('/guest/orders/verify');
    });

    /** @effects checkout_pg_branch_saves_order_response_guest_token_first */
    it('비회원 + 응답 토큰 있음 → 1순위 발화', () => {
      const ctx = { _global: { currentUser: null }, response: { data: { guest_order_token: '1|a' } } };
      expect(evalBranch(first, ctx)).toBe(true);
    });

    /** @effects checkout_pg_branch_falls_back_to_guest_verify_without_response_token */
    it('비회원 + 응답 토큰 없음 → 1순위 미발화, 2순위 발화', () => {
      const ctx = { _global: { currentUser: null }, response: { data: {} } };
      expect(evalBranch(first, ctx)).toBe(false);
      expect(evalBranch(second, ctx)).toBe(true);
    });

    /** @effects checkout_pg_branch_member_skips_guest_token */
    it('회원 → 두 분기 모두 미발화 (회원은 로그인으로 소유를 증명한다)', () => {
      const ctx = { _global: { currentUser: { uuid: 'u-1' } }, response: { data: { guest_order_token: '1|a' } } };
      expect(evalBranch(first, ctx)).toBe(false);
      expect(evalBranch(second, ctx)).toBe(false);
    });
  });
});
