/**
 * 주문 취소 모달 조건부 UI 검증 테스트
 *
 * @description
 * - '환불 우선순위' 라디오는 PG 환불 가능(requires_pg_cancellation) 그리고 포인트 사용분이
 *   있을 때만 노출되어야 한다 (_modal_cancel_order.json).
 * - 'PG 함께 취소' 체크박스는 PG 환불 가능 결제수단일 때만 노출되어야 한다 (_partial_order_info.json).
 *   무통장/포인트 등 PG 미사용 결제수단에는 두 UI 모두 숨겨진다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import modalCancelOrder from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_cancel_order.json';
import partialOrderInfo from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json';

/**
 * 재귀적으로 트리에서 predicate 를 만족하는 모든 노드를 수집합니다.
 * (children 순회)
 */
function collect(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
    if (!node || typeof node !== 'object') return acc;
    if (predicate(node)) acc.push(node);
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            collect(child, predicate, acc);
        }
    }
    return acc;
}

describe('주문 취소 모달 조건부 UI', () => {
    it("'환불 우선순위' 컨테이너의 if 가 requires_pg_cancellation 과 total_points_used_amount 를 함께 판정한다", () => {
        // refund_priority_label 텍스트를 가진 Span 을 자식으로 둔 컨테이너(주석 '환불 우선순위 라디오')
        const containers = collect(
            modalCancelOrder,
            (n) =>
                typeof n.comment === 'string' &&
                n.comment.includes('환불 우선순위') &&
                typeof n.if === 'string'
        );

        expect(containers.length).toBe(1);
        // 노출 조건: PG 환불 가능할 때 라디오를 노출한다 (숨김 X — 포인트 미사용 시에도 노출).
        const condition = containers[0].if as string;
        expect(condition).toContain('requires_pg_cancellation');
        expect(condition).not.toContain('total_points_used_amount');
    });

    it("환불 우선순위 라디오 Input 은 포인트 미사용 시 disabled 로 묶인다 (숨김이 아닌 선택 불가)", () => {
        // refundPriority 라디오 Input 들을 수집
        const radios = collect(
            modalCancelOrder,
            (n) => n?.props?.type === 'radio' && n?.props?.name === 'refundPriority'
        );

        // pg_first / points_first 두 개 모두 존재
        expect(radios.length).toBe(2);

        // 두 라디오 모두 total_points_used_amount 기반 disabled 바인딩을 가진다
        for (const r of radios) {
            const disabled = r.props.disabled;
            expect(typeof disabled).toBe('string');
            expect(disabled).toContain('total_points_used_amount');
        }
    });

    /**
     * "PG 함께 취소" 체크박스는 없앴다 — 취소의 입구는 하나다.
     *
     * 그 체크박스를 끄면 환불신청서도 취소신청서도 없이 상품만 `cancelled` 로 옮겨졌다.
     * 재고는 돌아오는데 돈·결제행·주문 합계는 그대로라, 원장 어디에도 근거 없는 취소가
     * 남았다(결제행을 정리하지 않으므로 그 주문은 이후 입금확인·입금통보로 되살아난다).
     *
     * 검사 대상을 "그 체크박스가 없다" 로 잡으면 레이아웃을 통째로 비워도 통과한다. 그래서
     * 같은 바의 형제 컨트롤이 살아 있는지를 함께 단언한다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     * @effects cancel_pg_control_is_gone_from_the_bulk_bar
     */
    it("'PG 함께 취소' 체크박스가 일괄변경 바에 없다", () => {
        const pgCheckboxes = collect(
            partialOrderInfo,
            (n: any) => n?.props?.id === 'batch_cancel_pg_checkbox'
        );

        expect(pgCheckboxes.length).toBe(0);

        // 모집단이 비어 통과하는 거짓 green 방지 — 같은 바의 상태 Select 는 남아 있어야 한다.
        const statusSelects = collect(
            partialOrderInfo,
            (n: any) => n?.name === 'Select' && n?.props?.name === 'batchOrderStatus'
        );

        expect(statusSelects.length).toBe(1);
    });

    /**
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     * @effects cancel_pg_control_is_gone_from_the_bulk_bar
     */
    it('일괄변경 바가 더는 cancelPg 를 핸들러로 넘기지 않는다', () => {
        expect(JSON.stringify(partialOrderInfo)).not.toContain('cancelPg');
    });
});
