/**
 * @file cancelOrderModalPartialUnsupported.test.ts
 * @description 관리자 취소 창 — 부분취소 불가 안내의 사유별 문구 (P6 · Q17)
 *
 * 입금 전 가상계좌는 발급된 입금액을 바꿀 수 없어 전액취소만 된다. 그 사유일 때는 그 이유를 말하고, 그 밖의
 * 사유(수단·PG 제약)는 종전 문구를 낸다. 판정은 서버가 결제 리소스(`partial_cancel_block_reason`)에 싣는다.
 */

import { describe, expect, it } from 'vitest';

import modalCancelOrder from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_cancel_order.json';

type Node = Record<string, any>;

/**
 * 서브트리의 모든 노드를 훑는다.
 *
 * @param node 시작 노드
 * @param visit 방문 함수
 */
function walk(node: unknown, visit: (n: Node) => void): void {
  if (Array.isArray(node)) {
    node.forEach((child) => walk(child, visit));

    return;
  }

  if (node && typeof node === 'object') {
    visit(node as Node);
    Object.values(node as Node).forEach((value) => walk(value, visit));
  }
}

describe('관리자 취소 모달 — 부분취소 불가 안내 사유 (P6 · Q17)', () => {
  /**
   * @scenario payment_method=vbank, cancel_scope=partial
   * @effects payment_resource_reports_vbank_partial_cancel_block_reason
   */
  it('사유에 따라 두 문구 중 하나만 나온다', () => {
    let notice: Node | undefined;
    walk(modalCancelOrder, (n) => {
      if (!notice && n.id === 'cancel_partial_unsupported_notice') {
        notice = n;
      }
    });
    expect(notice?.if).toBe('{{order.data?.payment?.supports_partial_cancel === false}}');

    const spans: Node[] = [];
    walk(notice, (n) => {
      if (typeof n.text === 'string' && n.text.includes('partial_unsupported')) {
        spans.push(n);
      }
    });
    expect(spans.map((n) => [n.if, n.text])).toEqual([
      [
        "{{order.data?.payment?.partial_cancel_block_reason === 'vbank_awaiting_deposit'}}",
        '$t:sirsoft-ecommerce.admin.order.detail.modal.cancel.partial_unsupported_vbank_awaiting',
      ],
      [
        "{{order.data?.payment?.partial_cancel_block_reason !== 'vbank_awaiting_deposit'}}",
        '$t:sirsoft-ecommerce.admin.order.detail.modal.cancel.partial_unsupported',
      ],
    ]);
  });
});
