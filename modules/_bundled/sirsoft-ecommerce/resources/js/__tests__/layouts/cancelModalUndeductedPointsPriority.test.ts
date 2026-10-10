/**
 * 관리자 [취소·환불 접수] 창 — 차감되지 않은 마일리지 주문은 환불 우선순위를 고를 수 없다 (S3-D10, 2026-10-07 실측)
 *
 * @description
 * 수동 결제완료에서 「사용 마일리지 차감」을 끈 주문은 사용 마일리지가 회원 잔액에서 빠진 적이 없어 환불 때
 * 돌려줄 마일리지가 0 이다(서버는 반환 0원으로 계산). 그런데 취소 창은 사용 마일리지 숫자만 보고 「PG 결제 우선 /
 * 마일리지 우선」을 고르게 했다(실측: 주문 20261006-1553557453 — 마일리지 6,000 미차감, 창의 최종 환불 예정액
 * 9,500·마일리지 0). 고를 수 없게 두고 그 이유(차감되지 않은 마일리지)를 곁에 보인다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import modal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_cancel_order.json';

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

/**
 * `{{…}}` 식을 order 문맥으로 평가한다.
 *
 * @param expr 식
 * @param order order 데이터소스
 * @returns 값
 */
function evaluate(expr: string, order: any): any {
    const body = String(expr).replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return new Function('order', '_local', `return (${body});`)(order, {});
}

const nodes = flatten(modal);
const radios = nodes.filter((n) => n?.name === 'Input' && n?.props?.name === 'refundPriority');

const undeducted = { data: { payment: { requires_pg_cancellation: true }, total_points_used_amount: 6000, points_not_deducted: true } };
const deducted = { data: { payment: { requires_pg_cancellation: true }, total_points_used_amount: 6000, points_not_deducted: false } };

describe('취소 창 환불 우선순위 — 차감되지 않은 마일리지', () => {
    /**
     * @scenario points=radios
     *
     * @effects undeducted_points_disable_refund_priority
     */
    it('우선순위 라디오 2개가 있다', () => {
        expect(radios).toHaveLength(2);
    });

    /**
     * @scenario points=undeducted
     *
     * @effects undeducted_points_disable_refund_priority
     */
    it('차감되지 않은 마일리지 주문은 두 라디오가 모두 꺼진다', () => {
        radios.forEach((r) => expect(evaluate(r.props.disabled, undeducted)).toBe(true));
    });

    /**
     * @scenario points=deducted
     *
     * @effects undeducted_points_disable_refund_priority
     */
    it('차감된 마일리지 주문은 고를 수 있다', () => {
        radios.forEach((r) => expect(evaluate(r.props.disabled, deducted)).toBe(false));
    });

    /**
     * @scenario points=hint
     *
     * @effects undeducted_points_hint_is_shown
     */
    it('꺼진 이유를 「차감되지 않은 마일리지」 문구로 보인다', () => {
        const hint = nodes.find((n) => String(n?.text ?? '').includes('refund_execute.points_not_deducted'));
        expect(hint, '차감되지 않은 마일리지 안내 노드').toBeTruthy();
        expect(evaluate(hint.if, undeducted)).toBe(true);
        expect(evaluate(hint.if, deducted)).toBe(false);
    });
});
