/**
 * @file vbankGuideVisibility.test.ts
 * @description 나이스페이먼츠 가상계좌 입금 안내 — 취소된 주문에는 입금 계좌를 보이지 않는지 (확장 조각 조건).
 *
 * 결함: 입금 전에 취소한 가상계좌 주문의 주문 상세·취소 신청서 상세에 「가상계좌 입금 안내 · 은행 · 계좌번호 ·
 *   입금 기한 · 입금 기한 내에 입금이 완료되지 않으면…」이 그대로 남아, 고객이 취소 뒤에도 입금할 수 있었다
 *   (취소 주문 입금은 운영자 수동 환불 대상). (이커머스 전수 실측 S4 — S4-D13, 결함인계대장 S2 01:36Z)
 */

import { describe, it, expect } from 'vitest';

import receipt from '../../extensions/user_mypage_order_receipt.json';

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

/** `{{ … }}` 조건식을 order 컨텍스트로 평가한다 */
function evalIf(expr: string, order: unknown): boolean {
  const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');

  // eslint-disable-next-line no-new-func
  return Boolean(new Function('order', `return (${body});`)(order));
}

const order = (payment: Record<string, unknown>, orderStatus: string) => ({ data: { order_status: orderStatus, payment: { pg_provider: 'nicepayments', payment_method: 'vbank', vbank_number: '04797015497084', ...payment } } });

describe('나이스페이먼츠 가상계좌 안내 — 결제 상태별 표시', () => {
  const block = collect(receipt, (v) => typeof v.if === 'string' && v.if.includes("'nicepayments'") && v.if.includes('vbank_number'))[0];
  const notice = collect(block, (v) => typeof v.if === 'string' && JSON.stringify(v).includes('vbank_notice')).pop() as Record<string, unknown>;

  /**
   * @scenario payment_state=waiting_deposit
   * @effects guide_and_notice_while_waiting
   */
  it('입금 대기 주문은 안내와 자동 취소 경고를 보인다', () => {
    const o = order({ payment_status: 'waiting_deposit', paid_at: null }, 'pending_payment');

    expect(evalIf(block.if as string, o)).toBe(true);
    expect(evalIf(notice.if as string, o)).toBe(true);
  });

  /**
   * @scenario payment_state=paid
   * @effects notice_hidden_after_payment
   */
  it('입금을 마친 주문은 경고를 감춘다(기존 동작 유지)', () => {
    const o = order({ payment_status: 'paid', paid_at: '2026-10-07 12:00:00' }, 'payment_complete');

    expect(evalIf(block.if as string, o)).toBe(true);
    expect(evalIf(notice.if as string, o)).toBe(false);
  });

  /**
   * @scenario payment_state=cancelled
   * @effects guide_hidden_when_cancelled
   */
  it('취소된 주문은 입금 안내 블록을 감춘다', () => {
    expect(evalIf(block.if as string, order({ payment_status: 'cancelled', paid_at: null }, 'cancelled'))).toBe(false);
  });
});
