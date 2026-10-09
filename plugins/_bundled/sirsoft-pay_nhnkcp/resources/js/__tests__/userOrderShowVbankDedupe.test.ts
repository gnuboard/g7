/**
 * @file userOrderShowVbankDedupe.test.ts
 * @description 내 주문 상세 결제 정보 카드에 「가상계좌 입금 안내」·영수증 행이 한 번만 나오는지 (확장 조각의 고정 id 계약).
 *
 * 결함: 확장 조각(user_order_show)의 가상계좌 블록에 id 가 없어, 같은 블록을 그리던 주입 스크립트가 `kcp-mp-vbank-row` 가
 *   없다고 보고 하나를 더 붙였다(주문 상세에 「가상계좌 입금 안내」 2개). (이커머스 전수 실측 S4 — S4-D10)
 * 이제 두 블록은 조각 하나만 그린다(#639 에서 주입 스크립트를 조각으로 옮김). 조각의 두 블록은 고정 id
 *   (`kcp-mp-vbank-row`·`kcp-mp-receipt-row`)를 달아 화면 점검이 블록 수를 셀 수 있게 한다.
 */

import { afterEach, describe, it, expect } from 'vitest';

import userOrderShow from '../../extensions/user_order_show.json';
import { cleanupRender, kcpVbankOrder, renderUserOrderShow, type LayoutTest } from './layouts/userOrderShowKit';

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

/** 조각 트리에서 id 를 단 노드를 모은다 */
const nodesWithId = (id: string) => collect(userOrderShow, (value) => (value.props as Record<string, unknown> | undefined)?.id === id);

let utils: LayoutTest | null = null;

afterEach(() => {
  cleanupRender(utils);
  utils = null;
});

describe('KCP 내 주문 상세 — 가상계좌 안내·영수증 행은 조각 하나가 한 번만 그린다', () => {
  /**
   * @scenario block=vbank_info
   * @effects vbank_block_rendered_once
   */
  it('가상계좌 블록은 고정 id(kcp-mp-vbank-row)를 단 하나뿐이고, 입금 대기 주문을 그리면 한 번만 나온다', async () => {
    const vbankBlocks = nodesWithId('kcp-mp-vbank-row');
    expect(vbankBlocks).toHaveLength(1);
    expect(String(vbankBlocks[0].if)).toContain('payment?.vbank_name');

    utils = await renderUserOrderShow(kcpVbankOrder());

    expect(document.querySelectorAll('#kcp-mp-vbank-row')).toHaveLength(1);
    expect(document.body.textContent?.split('가상계좌 입금 안내').length).toBe(2);
  });

  /**
   * @scenario block=receipt_row
   * @effects receipt_row_rendered_once
   */
  it('영수증 행은 고정 id(kcp-mp-receipt-row)를 단 하나뿐이고, 결제를 마친 주문을 그리면 한 번만 나온다', async () => {
    const receiptRows = nodesWithId('kcp-mp-receipt-row');
    expect(receiptRows).toHaveLength(1);
    expect(String(receiptRows[0].if)).toContain('payment?.paid_at');

    utils = await renderUserOrderShow(kcpVbankOrder({ payment_status: 'paid', paid_at: '2026-10-07 11:01:48', transaction_id: 'KCP-T-1' }, { order_status: 'payment_complete' }));

    expect(document.querySelectorAll('#kcp-mp-receipt-row')).toHaveLength(1);
  });
});
