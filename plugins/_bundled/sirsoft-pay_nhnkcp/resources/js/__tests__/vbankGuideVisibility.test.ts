/**
 * @file vbankGuideVisibility.test.ts
 * @description KCP 가상계좌 입금 안내 — 결제 상태에 맞게 보이는지 (확장 조각 + 주입기).
 *
 * 결함: 내 주문 상세·신청서 상세·주문 완료의 「가상계좌 입금 안내」가 결제 상태와 무관하게 늘 보였다.
 *   입금을 마친 주문에도 「입금 기한 내에 입금하지 않으시면 주문이 자동 취소됩니다.」가 남고, 취소된 주문에도
 *   입금 계좌가 그대로 보여 고객이 취소 뒤에 입금할 수 있었다. 나이스페이먼츠 조각은 결제 뒤 경고를 감춘다.
 *   (이커머스 전수 실측 S4 — S4-D13, 결함인계대장 S2 02:49Z·01:36Z)
 */

import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest';

import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';
import userOrderShow from '../../extensions/user_order_show.json';
import orderComplete from '../../extensions/user_order_complete_receipt.json';

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

/** `{{ … }}` 조건식을 주어진 이름의 컨텍스트로 평가한다 */
function evalIf(expr: string, name: string, ctx: unknown): boolean {
  const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');

  // eslint-disable-next-line no-new-func
  return Boolean(new Function(name, `return (${body});`)(ctx));
}

const withPayment = (payment: Record<string, unknown>, orderStatus = 'pending_payment') => ({ data: { order_status: orderStatus, payment: { pg_provider: 'nhnkcp', payment_method: 'vbank', vbank_name: '기업은행', vbank_number: 'T0309', ...payment } } });

describe('KCP 가상계좌 안내 — 확장 조각 조건', () => {
  const block = collect(userOrderShow, (v) => typeof v.if === 'string' && v.if.includes('payment?.vbank_name') && v.if.includes("'nhnkcp'"))[0];
  const notice = collect(block, (v) => typeof v.if === 'string' && JSON.stringify(v).includes('vbank_notice')).pop() as Record<string, unknown>;

  /**
   * @scenario payment_state=waiting_deposit
   * @effects guide_and_notice_while_waiting
   */
  it('입금 대기 주문은 안내와 자동 취소 경고를 모두 보인다', () => {
    const order = withPayment({ payment_status: 'waiting_deposit', paid_at: null });

    expect(evalIf(block.if as string, 'order', order)).toBe(true);
    expect(notice).toBeDefined();
    expect(evalIf(notice.if as string, 'order', order)).toBe(true);
  });

  /**
   * @scenario payment_state=paid
   * @effects notice_hidden_after_payment
   */
  it('입금을 마친 주문은 계좌 정보는 남기되 자동 취소 경고는 감춘다', () => {
    const order = withPayment({ payment_status: 'paid', paid_at: '2026-10-07 11:01:48' }, 'payment_complete');

    expect(evalIf(block.if as string, 'order', order)).toBe(true);
    expect(evalIf(notice.if as string, 'order', order)).toBe(false);
  });

  /**
   * @scenario payment_state=cancelled
   * @effects guide_hidden_when_cancelled
   */
  it('취소된 주문은 입금 안내 블록을 감춘다', () => {
    expect(evalIf(block.if as string, 'order', withPayment({ payment_status: 'cancelled', paid_at: null }, 'cancelled'))).toBe(false);
  });

  /**
   * @scenario payment_state=paid_complete_screen
   * @effects notice_hidden_after_payment
   */
  it('주문 완료 화면도 입금 뒤에는 자동 취소 경고를 감춘다', () => {
    const completeBlock = collect(orderComplete, (v) => typeof v.if === 'string' && v.if.includes('payment?.vbank_name'))[0];
    const completeNotice = collect(completeBlock, (v) => typeof v.if === 'string' && JSON.stringify(v).includes('vbank_notice')).pop() as Record<string, unknown>;
    const paid = withPayment({ payment_status: 'paid', paid_at: '2026-10-07 11:01:48' }, 'payment_complete');

    expect(completeNotice).not.toBe(completeBlock);

    expect(completeNotice).toBeDefined();
    expect(evalIf(completeNotice.if as string, 'orderData', paid)).toBe(false);
  });
});

describe('KCP 가상계좌 안내 — 주입기(조각이 없는 템플릿용)', () => {
  const FLAG = '__kcpMpShowInjectorInstalled';

  const seed = (payment: Record<string, unknown>, orderStatus: string) => {
    (window as any).G7Core = { getState: () => ({ currentDataContext: { order: { data: { order_number: 'ORD-V', order_status: orderStatus, total_amount_formatted: '15,000원', payment: { pg_provider: 'nhnkcp', payment_method: 'vbank', vbank_name: '기업은행', vbank_number: 'T0309', vbank_holder: 'NHN KCP', ...payment } } } } }) };
  };

  beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<div id="order_payment_info_panel"><div class="space-y-3"></div></div>';
    vi.spyOn(console, 'info').mockImplementation(() => {});
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ success: true, data: { available: false } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    history.replaceState({}, '', '/mypage/orders/ORD-V');
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete (window as any)[FLAG];
    delete (window as any).G7Core;
    document.body.innerHTML = '';
  });

  /**
   * @scenario payment_state=injector_paid
   * @effects notice_hidden_after_payment
   */
  it('입금을 마친 주문엔 자동 취소 경고 없이 안내를 붙인다', async () => {
    seed({ payment_status: 'paid', paid_at: '2026-10-07 11:01:48' }, 'payment_complete');
    installMypageOrderShowInjector();
    await vi.advanceTimersByTimeAsync(5000);

    const block = document.getElementById('kcp-mp-vbank-row');
    expect(block).not.toBeNull();
    expect(block!.textContent).not.toContain('자동 취소');
  });

  /**
   * @scenario payment_state=injector_cancelled
   * @effects guide_hidden_when_cancelled
   */
  it('취소된 주문엔 안내를 붙이지 않는다', async () => {
    seed({ payment_status: 'cancelled', paid_at: null }, 'cancelled');
    installMypageOrderShowInjector();
    await vi.advanceTimersByTimeAsync(5000);

    expect(document.getElementById('kcp-mp-vbank-row')).toBeNull();
  });
});
