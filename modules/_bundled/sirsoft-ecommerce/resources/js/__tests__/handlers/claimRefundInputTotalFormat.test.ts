/**
 * 환불신청서 상세 — 실행 창의 수단 이름과 [금액 수정] 칸 합계 표기 (S3-D25·D27, 2026-10-07 실측)
 *
 * @description
 * - D25: 나이스 플러그인을 끈 뒤 PG 로 접수된 신청서(RF20261007-1325339567)의 [환불하기] 창이 「환불 수단 pg」 처럼
 *   날 값을 그렸다. 창은 수단 이름을 서버가 준 선택지에서 찾는데, 플러그인이 꺼지면 선택지에서 PG 가 빠져
 *   찾지 못하면 값 자체를 그렸다. 상세가 준 저장(실효) 수단의 이름으로 대신 그린다.
 * - D27: 같은 화면 [금액 수정] 칸의 「최종 환불 합계」가 `22500 KRW` 로 숫자와 통화 코드를 이어 붙였다. 실행 창(S3-D1)과
 *   같은 신청서의 다른 금액은 `22,500원` 이다. 첫 합계는 서버 표기(`refund_total_formatted`)를, 금액을 고치면 핸들러가
 *   상점 통화 표기로 다시 적은 값을 그린다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import { openClaimRefundExecuteHandler, formatRefundInputTotalHandler } from '../../handlers/claimHandlers';
import detailLayout from '../../../layouts/admin/admin_ecommerce_claim_detail.json';
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

const CURRENCIES = [
    { code: 'KRW', symbol: '원', decimal_places: 0, is_default: true },
    { code: 'USD', symbol: '$', decimal_places: 2, is_default: false },
];

beforeEach(() => {
    mockGlobalState = { modules: { 'sirsoft-ecommerce': { language_currency: { currencies: CURRENCIES } } } };
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

describe('실행 창 — 선택지 밖 수단의 이름 (S3-D25)', () => {
    /**
     * @scenario case=pg_accepted_plugin_off
     *
     * @effects execute_dialog_shows_method_name
     */
    it('선택지에 없는 저장 수단은 상세가 준 이름으로 그린다(날 값 pg 금지)', async () => {
        await openClaimRefundExecuteHandler({
            handler: 'openClaimRefundExecute',
            params: {
                pathSegment: 'refunds',
                claimId: 110,
                claimNumber: 'RF20261007-1325339567',
                currencyCode: 'KRW',
                refundAmount: '22500',
                refundPointsAmount: '0',
                refundMethod: 'pg',
                refundMethodLabel: 'PG사 환불',
                refundMethods: [{ value: 'bank', label: '계좌이체 환불' }, { value: 'points', label: '마일리지로 환불' }],
            },
        } as any);

        expect(mockGlobalState.claimRefundExecuteContext?.refundMethodLabel).toBe('PG사 환불');
    });

    /**
     * @scenario case=pg_accepted_plugin_off
     *
     * @effects execute_dialog_shows_method_name
     */
    it('[환불하기] 는 상세가 준 수단 이름을 창에 넘긴다', () => {
        const action = flatten(paymentPartial).find((n) => n?.handler === 'sirsoft-ecommerce.openClaimRefundExecute');
        expect(action, '실행 창 여는 동작').toBeTruthy();
        expect(String(action.params?.refundMethodLabel ?? '')).toContain('refund_method_label');
    });
});

describe('[금액 수정] 칸 합계 표기 (S3-D27)', () => {
    /**
     * @scenario case=amount_edit_total
     *
     * @effects input_total_reformats_on_change
     */
    it('금액을 고치면 합계를 상점 통화 표기로 다시 적는다', () => {
        formatRefundInputTotalHandler({ handler: 'formatRefundInputTotal', params: { refundAmount: '22500', refundPointsAmount: '0', currencyCode: 'KRW' } } as any);
        expect(mockG7Core.state.setLocal).toHaveBeenLastCalledWith({ refundInputTotalFormatted: '22,500원' });

        formatRefundInputTotalHandler({ handler: 'formatRefundInputTotal', params: { refundAmount: '12.5', refundPointsAmount: '0.1', currencyCode: 'USD' } } as any);
        expect(mockG7Core.state.setLocal).toHaveBeenLastCalledWith({ refundInputTotalFormatted: '$12.60' });
    });

    /**
     * @scenario case=amount_edit_total
     *
     * @effects input_total_is_currency_formatted
     */
    it('합계 줄은 숫자와 통화 코드를 이어 붙이지 않고 표기 문자열을 그린다', () => {
        const total = flatten(paymentPartial).find((n) => n?.id === 'cre_011_total');
        expect(total, '합계 줄').toBeTruthy();
        // 표기 문자열이 먼저다 — 숫자 + 통화 코드는 표기를 아직 받지 못한 순간의 대체값일 뿐이다.
        expect(String(total.text)).toMatch(/^\{\{_local\.refundInputTotalFormatted \|\|/);
    });

    /**
     * @scenario case=amount_edit_total
     *
     * @effects input_total_is_currency_formatted
     */
    it('첫 합계는 서버 표기로 채우고, 두 입력 칸이 바뀔 때마다 다시 적는다', () => {
        expect(JSON.stringify(detailLayout)).toMatch(/refundInputTotalFormatted[^}]*refund_total_formatted/);

        const reformat = flatten(paymentPartial).filter((n) => n?.handler === 'sirsoft-ecommerce.formatRefundInputTotal');
        expect(reformat.length, '현금·마일리지 두 칸').toBeGreaterThanOrEqual(2);
    });
});
