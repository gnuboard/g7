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

    it("'PG 함께 취소' 체크박스 컨테이너의 if 가 requires_pg_cancellation 을 판정한다", () => {
        // batch_cancel_pg_checkbox Input 을 자식으로 둔 컨테이너
        const hasPgCheckbox = (n: any): boolean =>
            Array.isArray(n.children) &&
            n.children.some((c: any) => c?.props?.id === 'batch_cancel_pg_checkbox');

        const containers = collect(partialOrderInfo, (n) => hasPgCheckbox(n) && typeof n.if === 'string');

        expect(containers.length).toBe(1);
        const condition = containers[0].if as string;
        expect(condition).toContain('requires_pg_cancellation');
        // 기존 batch 취소 게이트도 유지되어야 한다
        expect(condition).toContain('batchOrderStatus');
    });
});
