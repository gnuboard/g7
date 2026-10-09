/**
 * @file mypage-withdraw-warning-anchor.test.ts
 * @description 탈퇴 확인 창 경고 목록의 확장 앵커 구조 계약 (Q20)
 *
 * 이커머스 모듈이 「환불로 받은 마일리지도 소멸된다」 항목을 덧붙이려면 경고 목록이 data-ref(노드 id)를 가져야
 * 한다. 앵커가 사라지면 확장이 주입할 자리를 잃고 경고 없이 탈퇴가 진행된다.
 */

import { describe, expect, it } from 'vitest';

import withdrawModal from '../../../layouts/partials/mypage/profile/_modal_withdraw.json';

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

describe('탈퇴 확인 창 — 경고 목록 확장 앵커 (Q20)', () => {
  /**
   * @scenario refund_credit=present
   * @effects withdraw_modal_warns_refund_credit_forfeiture
   */
  it('경고 목록이 withdraw_warning_list id 를 갖는다', () => {
    const anchors: Node[] = [];
    walk(withdrawModal, (n) => {
      if (n.id === 'withdraw_warning_list') {
        anchors.push(n);
      }
    });

    expect(anchors).toHaveLength(1);
    expect(anchors[0].name).toBe('Ul');
  });

  /**
   * 경고 목록 안에 확장점(`withdraw_warnings`)이 있어야 한다 — 서버 오버레이 주입은 모달 안 노드를 찾지 못하고,
   * 확장점 주입만 모달 트리까지 순회한다(#747 C12 W1).
   *
   * @scenario refund_credit=present
   * @effects withdraw_modal_warns_refund_credit_forfeiture
   */
  it('경고 목록 안에 withdraw_warnings 확장점이 있다', () => {
    const points: Node[] = [];
    walk(withdrawModal, (n) => {
      if (n.id === 'withdraw_warning_list') {
        walk(n.children ?? [], (c) => {
          if (c.type === 'extension_point') {
            points.push(c);
          }
        });
      }
    });

    expect(points.map((p) => p.name)).toEqual(['withdraw_warnings']);
  });
});
