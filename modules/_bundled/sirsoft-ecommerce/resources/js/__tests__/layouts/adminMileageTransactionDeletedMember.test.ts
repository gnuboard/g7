/**
 * @file adminMileageTransactionDeletedMember.test.ts
 * @description 관리자 마일리지 내역 — 회원 연결이 끊긴 거래(삭제된 회원) 표시 구조 계약 (E3)
 *
 * 회원을 완전 삭제하면 거래 기록은 남기고 회원 연결만 끊는다(user_id NULL). 회원 칸이 「#」 만 그리지 않도록
 * 「삭제된 회원」 을 적고, 수정 창의 회원 이름도 같은 말을 쓴다.
 */

import { describe, expect, it } from 'vitest';

import table from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_transactions_table.json';

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

describe('관리자 마일리지 내역 — 삭제된 회원 (E3)', () => {
  /**
   * @scenario member_state=deleted
   * @effects admin_ledger_shows_deleted_member_for_detached_rows
   */
  it('회원 연결이 없는 행은 회원 칸과 수정 창에 「삭제된 회원」 을 쓴다', () => {
    const texts: Node[] = [];
    const memberNames: string[] = [];
    walk(table, (n) => {
      if (typeof n.text === 'string' && n.text.includes('cell.deleted_member')) {
        texts.push(n);
      }
      if (n.name === 'memberName' && typeof n.value === 'string') {
        memberNames.push(n.value);
      }
      if (n.memberName && typeof n.memberName === 'string') {
        memberNames.push(n.memberName);
      }
    });

    expect(texts).toHaveLength(1);
    expect(texts[0].if).toBe('{{!row.user_id}}');
    expect(memberNames.some((v) => v.includes("$t('sirsoft-ecommerce.admin.mileage_transactions.cell.deleted_member')"))).toBe(true);
  });
});
