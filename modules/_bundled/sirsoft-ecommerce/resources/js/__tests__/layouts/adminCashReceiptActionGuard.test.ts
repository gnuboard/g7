/**
 * 관리자 주문 상세 「현금영수증」 — 발급취소는 확인을 받고, 발급·재발급·발급취소는 요청 중 잠긴다 (S3-D22, 2026-10-07 실측)
 *
 * @description
 * [발급취소] 는 결제사에 취소를 거는 되돌릴 수 없는 동작인데 한 번 누르면 확인 없이 바로 `DELETE …/cash-receipt` 를
 * 보냈다(실측: 무통장 주문 20261007-0657560638 — 발급번호 730008052 가 클릭 한 번에 취소). 발급 창의 [현금영수증 발급]·
 * 카드의 [재발급]·[발급취소] 는 요청 중에도 눌려, 두 번 누르면 결제사 호출이 두 번 나간다. 같은 저장소의 일괄 삭제가 쓰는
 * `<sequence confirm>` + 진행 플래그 + 버튼 `disabled` 를 따른다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import card from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json';
import modal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_issue_cash_receipt.json';

/**
 * JSON 트리를 평탄화한다.
 *
 * @param node 시작 노드
 * @param acc 누적
 * @returns 노드 목록
 */
function flatten(node: any, acc: any[] = []): any[] {
    if (!node || typeof node !== 'object') return acc;
    acc.push(node);
    for (const value of Object.values(node)) {
        if (Array.isArray(value)) value.forEach((child) => flatten(child, acc));
        else if (value && typeof value === 'object') flatten(value, acc);
    }

    return acc;
}

const BUSY = 'isCashReceiptBusy';

/**
 * data-ref(또는 id)로 버튼 노드를 찾는다.
 *
 * @param tree 레이아웃 트리
 * @param ref 참조 이름 접두
 * @returns 버튼 노드
 */
function button(tree: any, ref: string): any {
    return flatten(tree).find((n) => n?.name === 'Button' && String(n?.props?.['data-ref'] ?? n?.id ?? '').startsWith(ref));
}

describe('현금영수증 동작 보호', () => {
    /**
     * @scenario action=cancel
     *
     * @effects cash_receipt_cancel_requires_confirmation
     */
    it('[발급취소] 는 확인을 받는 순차 동작이다', () => {
        const cancel = button(card, 'payment_cash_receipt_cancel_button');
        expect(cancel, '발급취소 버튼').toBeTruthy();
        const seq = flatten(cancel).find((n) => n?.handler === 'sequence');
        expect(seq, '순차 동작').toBeTruthy();
        expect(String(seq.confirm ?? ''), '확인 문구').toContain('cash_receipt.cancel_confirm');
        const call = flatten(seq).find((n) => n?.handler === 'apiCall' && String(n?.params?.method ?? n?.method ?? '').toUpperCase() === 'DELETE');
        expect(call, '확인 안의 DELETE 요청').toBeTruthy();
    });

    /**
     * 요청 중 잠기고 성공·실패 양쪽에서 풀리는지 확인한다.
     *
     * @param node 버튼 노드
     */
    function expectLocksWhileRequesting(node: any): void {
        expect(node).toBeTruthy();
        expect(String(node.props?.disabled ?? ''), '진행 플래그로 잠금').toContain(BUSY);
        const source = JSON.stringify(node);
        expect(source, '요청 전 잠금').toMatch(new RegExp(`"${BUSY}":true`));
        expect((source.match(new RegExp(`"${BUSY}":false`, 'g')) ?? []).length, '성공·실패 양쪽에서 해제').toBeGreaterThanOrEqual(2);
    }

    /**
     * @scenario action=cancel
     *
     * @effects cash_receipt_actions_lock_while_requesting
     */
    it('카드 [발급취소] 는 요청 중 잠기고 끝나면 풀린다', () => {
        expectLocksWhileRequesting(button(card, 'payment_cash_receipt_cancel_button'));
    });

    /**
     * @scenario action=reissue
     *
     * @effects cash_receipt_actions_lock_while_requesting
     */
    it('카드 [재발급] 는 요청 중 잠기고 끝나면 풀린다', () => {
        expectLocksWhileRequesting(button(card, 'payment_cash_receipt_reissue_button'));
    });

    /**
     * @scenario action=issue
     *
     * @effects cash_receipt_actions_lock_while_requesting
     */
    it('창 [현금영수증 발급] 는 요청 중 잠기고 끝나면 풀린다', () => {
        expectLocksWhileRequesting(button(modal, 'modal_cash_receipt_submit'));
    });
});
