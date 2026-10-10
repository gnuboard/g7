/**
 * 환불 실행 확인 창 — 사용 마일리지를 차감하지 않은 주문은 반환액 0 과 그 이유를 보인다 (Q15)
 *
 * @description
 * 상세 응답의 `points_not_deducted` 를 여는 핸들러가 창 문맥에 싣고, 창은 「사용 마일리지 반환액 0」 아래에
 * 이유(차감되지 않은 마일리지)를 보인다. 이유가 없으면 마일리지를 썼는데 왜 0 인지 운영자가 알 수 없다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import { openClaimRefundExecuteHandler } from '../../handlers/claimHandlers';
import refundModal from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_refund_execute.json';
import paymentPartial from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_payment.json';

let mockGlobalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        get: () => mockGlobalState,
        getLocal: () => ({}),
        set: vi.fn((updates: Record<string, any>) => {
            mockGlobalState = { ...mockGlobalState, ...updates };
        }),
        setLocal: vi.fn(),
    },
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    t: vi.fn((key: string) => key),
    api: { post: vi.fn(), patch: vi.fn(), put: vi.fn() },
    dispatch: vi.fn(),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

beforeEach(() => {
    mockGlobalState = {};
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
});

/**
 * JSON 트리의 모든 노드를 평탄화한다.
 *
 * @param node 시작 노드
 * @param acc 누적 배열
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
 * 노드의 `if` 를 `_global` 문맥으로 평가한다.
 *
 * @param node 노드
 * @param global `_global` 상태
 * @returns 표시 여부
 */
function visible(node: any, global: any): boolean {
    const body = String(node?.if ?? 'true').replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return Boolean(new Function('_global', `return (${body});`)(global));
}

const TARGET = {
    pathSegment: 'refunds',
    claimId: 24,
    claimNumber: 'RF20261006-0107299975',
    currencyCode: 'KRW',
    refundAmount: '21000',
    refundPointsAmount: '0',
    refundMethod: 'pg',
    refundMethods: [{ value: 'pg', label: 'PG사 환불' }],
};

describe('사용 마일리지 미차감 주문의 반환액 줄', () => {
    const reason = flatten(refundModal).find((n) => typeof n.text === 'string' && n.text.includes('refund_execute.points_not_deducted'));

    /**
     * @effects undeducted_points_row_shows_zero_with_reason
     */
    it('미차감 주문이면 창 문맥에 싣고 반환액 아래 이유를 보인다', async () => {
        await openClaimRefundExecuteHandler({ handler: 'x', params: { ...TARGET, pointsNotDeducted: true } } as any, {} as any);
        const ctx = mockGlobalState.claimRefundExecuteContext;

        expect(ctx.pointsNotDeducted).toBe(true);
        expect(reason, '미차감 이유 문구 노드가 없다').toBeDefined();
        expect(visible(reason, { claimRefundExecuteContext: ctx })).toBe(true);
    });

    /**
     * @effects undeducted_points_row_shows_zero_with_reason
     */
    it('차감된 주문이면 이유를 보이지 않는다', async () => {
        await openClaimRefundExecuteHandler({ handler: 'x', params: { ...TARGET, refundPointsAmount: '3000' } } as any, {} as any);
        const ctx = mockGlobalState.claimRefundExecuteContext;

        expect(ctx.pointsNotDeducted).toBe(false);
        expect(visible(reason, { claimRefundExecuteContext: ctx })).toBe(false);
    });

    /**
     * @effects undeducted_points_row_shows_zero_with_reason
     */
    it('[환불하기] 버튼이 상세 응답의 points_not_deducted 를 넘긴다', () => {
        const opener = flatten(paymentPartial).find((n) => n.handler === 'sirsoft-ecommerce.openClaimRefundExecute');

        expect(opener, '[환불하기] 액션이 없다').toBeDefined();
        expect(String(opener.params?.pointsNotDeducted ?? '')).toContain('points_not_deducted');
    });
});
