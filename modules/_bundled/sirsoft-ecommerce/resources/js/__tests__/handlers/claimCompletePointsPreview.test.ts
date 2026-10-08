/**
 * 반품·교환 [완료 처리] 창의 환불 예정에 사용 마일리지 반환액도 보인다 (EC-694, 2026-10-07 실측)
 *
 * @description
 * 마일리지를 일부 쓴 주문(무통장 60,000 + 마일리지 3,000)의 반품을 완료하면 환불신청서는 현금 50,000 +
 * 마일리지 3,000 으로 만들어지는데, 완료 창은 「환불 예정 50,000원 · 완료와 동시에 1건(합계 50,000원) 생성」만
 * 그려 마일리지 반환을 말하지 않았다. 미리보기 응답은 건별(`refund.refund_points_amount_formatted`)·합계
 * (`totals.refund_points_amount_total_formatted`) 마일리지를 이미 싣는다 — 창의 행 데이터와 레이아웃이 그것을
 * 버리고 있었다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import { buildClaimBulkConfirmDataHandler } from '../../handlers/claimHandlers';
import modal from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_modal_claim_complete.json';

const PREVIEW_URL = '/api/modules/sirsoft-ecommerce/admin/claims/completion-preview';

let mockGlobalState: Record<string, any> = {};

/** 마일리지 3,000 을 함께 돌려주는 반품 1건의 완료 미리보기 */
const PREVIEW_RESPONSE = {
    success: true,
    data: {
        claims: [
            {
                id: 31,
                claim_number: 'RT20261006-1441377874',
                currency_code: 'KRW',
                completable_item_ids: [55],
                completable_item_count: 1,
                stock_restore_count: 1,
                stock_discard_count: 0,
                refund: {
                    applicable: true,
                    already_filed: false,
                    blocked_reason: null,
                    refund_amount: 50000,
                    refund_amount_formatted: '50,000원',
                    refund_points_amount: 3000,
                    refund_points_amount_formatted: '3,000원',
                },
            },
        ],
        skipped: [],
        totals: {
            claim_count: 1,
            completable_item_count: 1,
            refund_amount_total: 50000,
            refund_points_amount_total: 3000,
            currency_code: 'KRW',
            refund_amount_total_formatted: '50,000원',
            refund_points_amount_total_formatted: '3,000원',
        },
    },
};

beforeEach(() => {
    mockGlobalState = {};
    (window as any).G7Core = {
        state: {
            get: () => mockGlobalState,
            getLocal: () => ({}),
            set: vi.fn((updates: Record<string, any>) => {
                mockGlobalState = { ...mockGlobalState, ...updates };
            }),
            setLocal: vi.fn(),
        },
        toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() },
        modal: { open: vi.fn(), close: vi.fn() },
        t: vi.fn((key: string) => key),
        api: { post: vi.fn().mockResolvedValue(PREVIEW_RESPONSE), patch: vi.fn() },
        dispatch: vi.fn(),
        createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
    };
});

/**
 * 레이아웃 노드 트리에서 조건을 만족하는 노드를 모두 찾는다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @returns 찾은 노드 목록
 */
function findNodes(node: any, match: (n: any) => boolean): any[] {
    if (!node || typeof node !== 'object') return [];
    const self = match(node) ? [node] : [];
    const children = Array.isArray(node) ? node : Object.values(node);

    return self.concat(...children.map((child) => findNodes(child, match)));
}

describe('완료 처리 창 — 마일리지 반환 표기 (EC-694)', () => {
    /**
     * @scenario claim_type=return, completion_choice=file_now
     *
     * @effects completion_modal_shows_points_refund
     */
    it('행 데이터에 건별 마일리지 반환액이 실린다', async () => {
        await buildClaimBulkConfirmDataHandler(
            { handler: 'x', params: { claimType: 'return', status: 'completed', ids: [31], rows: [{ id: 31, claim_number: 'RT20261006-1441377874' }] } } as any,
            {} as any,
        );

        expect((window as any).G7Core.api.post).toHaveBeenCalledWith(PREVIEW_URL, expect.anything());
        expect(mockGlobalState.claimCompleteRows[0]).toMatchObject({
            refund_amount_formatted: '50,000원',
            refund_points_amount: 3000,
            refund_points_amount_formatted: '3,000원',
        });
    });

    /**
     * @scenario claim_type=return, completion_choice=file_now
     *
     * @effects completion_modal_shows_points_refund
     */
    it('행은 마일리지 반환액이 있을 때만 그 줄을 그린다', () => {
        const rowPoints = findNodes(modal, (n) => typeof n?.text === 'string' && n.text.includes('admin.claim.complete.row_points'));

        expect(rowPoints).toHaveLength(1);
        expect(rowPoints[0].text).toContain('claimRow.refund_points_amount_formatted');
        expect(String(rowPoints[0].if)).toContain('claimRow.refund_points_amount');
    });

    /**
     * @scenario claim_type=return, completion_choice=file_now
     *
     * @effects completion_modal_shows_points_refund
     */
    it('함께 만들 환불신청서 안내에 마일리지 반환 합계가 따라 붙는다', () => {
        const summary = findNodes(modal, (n) => typeof n?.text === 'string' && n.text.includes('admin.claim.complete.refund_now_desc_points'));

        expect(summary).toHaveLength(1);
        expect(summary[0].text).toContain('totals?.refund_points_amount_total_formatted');
        expect(String(summary[0].if)).toContain('refund_points_amount_total');
        expect(String(summary[0].if)).toContain('claimCompleteCreateRefundRequest !== false');
    });
});
