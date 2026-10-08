/**
 * @file userOrderShowVbankDedupe.test.ts
 * @description 내 주문 상세 결제 정보 카드에 「가상계좌 입금 안내」가 한 번만 나오는지 (확장 조각 ↔ 주입기 id 계약).
 *
 * 결함: 확장 조각(user_order_show)의 가상계좌 블록에 id 가 없어, 같은 블록을 그리는 주입기
 *   (mypageOrderShowInjector)가 `kcp-mp-vbank-row` 가 없다고 보고 하나를 더 붙였다. 영수증 행은 조각이
 *   주입기와 같은 id(`kcp-mp-receipt-row`)를 달아 이미 한 번만 나온다. (이커머스 전수 실측 S4 — S4-D10)
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest';

import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';
import userOrderShow from '../../extensions/user_order_show.json';

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

/** 주입기 소스에서 상수 id 를 읽는다 */
function injectorConst(name: string): string | null {
  const src = readFileSync(resolve(__dirname, '../mypageOrderShowInjector.ts'), 'utf8');
  const m = src.match(new RegExp(`const ${name} = '([^']+)'`));

  return m ? m[1] : null;
}

describe('KCP 내 주문 상세 — 확장 조각과 주입기가 같은 블록을 두 번 그리지 않는다', () => {
  /**
   * @scenario block=vbank_info
   * @effects vbank_block_rendered_once
   */
  it('가상계좌 블록은 주입기가 찾는 id 를 단다', () => {
    const vbankBlock = collect(
      userOrderShow,
      (value) => typeof value.if === 'string' && value.if.includes('payment?.vbank_name') && value.if.includes("'nhnkcp'"),
    )[0];

    expect(vbankBlock).toBeDefined();
    expect((vbankBlock.props as Record<string, unknown> | undefined)?.id).toBe(injectorConst('VBANK_ID'));
  });

  /**
   * @scenario block=receipt_row
   * @effects receipt_row_rendered_once
   */
  it('영수증 행은 주입기가 찾는 id 를 단다(대조군)', () => {
    const receiptRow = collect(
      userOrderShow,
      (value) => typeof value.if === 'string' && value.if.includes('payment?.paid_at') && value.if.includes("'nhnkcp'"),
    )[0];

    expect((receiptRow?.props as Record<string, unknown> | undefined)?.id).toBe(injectorConst('ROW_ID'));
  });
});

/**
 * 주입기 쪽 — 조각이 주문을 받은 뒤 한 박자 늦게 그려져도 안내가 한 번만 남는다.
 *
 * 주입기는 화면 상태에서 주문을 먼저 읽고, 같은 순간 조각은 아직 그려지지 않았을 수 있다. 첫 확인에서
 * 바로 붙이면 곧이어 조각이 같은 블록을 그려 둘이 된다(실측: 주문 290 상세에 2개, 둘 다 같은 id).
 */
describe('KCP 내 주문 상세 주입기 — 가상계좌 안내는 조각이 그릴 틈을 준 뒤에만 붙인다', () => {
  const FLAG = '__kcpMpShowInjectorInstalled';

  const seedVbankOrder = () => {
    (window as any).G7Core = {
      getState: () => ({
        currentDataContext: {
          order: { data: { order_number: 'ORD-V', total_amount_formatted: '15,000원', payment: { pg_provider: 'nhnkcp', payment_method: 'vbank', vbank_name: '기업은행', vbank_number: 'T0309', vbank_holder: 'NHN KCP' } } },
        },
      }),
    };
  };

  const extensionBlock = () => {
    const el = document.createElement('div');
    el.id = 'kcp-mp-vbank-row';
    el.dataset.from = 'extension';

    return el;
  };

  beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<div id="order_payment_info_panel"><div class="space-y-3"></div></div>';
    seedVbankOrder();
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
   * @scenario block=vbank_info_late_extension
   * @effects vbank_block_rendered_once
   */
  it('주입기가 주문을 본 직후 조각이 그려지면 주입기는 붙이지 않는다', async () => {
    installMypageOrderShowInjector();
    // 설치 지연(1500ms) + 첫 폴링(400ms) 직후 — 주입기가 주문을 본 다음 박자에 조각이 그려진다
    await vi.advanceTimersByTimeAsync(2000);
    document.querySelector('#order_payment_info_panel .space-y-3')!.appendChild(extensionBlock());
    await vi.advanceTimersByTimeAsync(3000);

    expect(document.querySelectorAll('#kcp-mp-vbank-row')).toHaveLength(1);
    expect((document.getElementById('kcp-mp-vbank-row') as HTMLElement).dataset.from).toBe('extension');
  });

  /**
   * @scenario block=vbank_info_no_extension
   * @effects vbank_block_rendered_once
   */
  it('조각이 없는 템플릿에서는 주입기가 한 번 붙인다', async () => {
    installMypageOrderShowInjector();
    await vi.advanceTimersByTimeAsync(5000);

    expect(document.querySelectorAll('#kcp-mp-vbank-row')).toHaveLength(1);
  });
});
