/**
 * 환불 실행 확인 창 — 계좌 줄·입력 칸은 지금 고른 환불 수단의 판정을, 합계는 교환 차액 환급까지 따른다
 *
 * @description
 * ① 상세 응답의 `refund_account_by_method`(수단별 used·required)에서 **지금 고른 수단**의 값을 골라 창에 넘긴다.
 *    저장 수단 하나의 판정을 쓰면 「마일리지로 환불」로 바꿔도 계좌 줄이 남고, 계좌 없는 신청서는 버튼이 잠긴다.
 * ③ 교환 차액 환급은 별도 칼럼이라 창 합계에서 빠졌다 — 차액 줄을 보이고 「최종 환불 합계」에 더한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import { openClaimRefundExecuteHandler } from '../../handlers/claimHandlers';
import refundModal from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_refund_execute.json';

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

/** 입금된 가상계좌 주문의 수단별 판정(계좌 미기재) */
const VBANK_BY_METHOD = {
    pg: { used: true, required: true },
    bank: { used: true, required: true },
    points: { used: false, required: false },
};

/** 입금된 가상계좌 주문, 계좌 미기재, 저장 현금 있음 — 현금 축(required_if_cash)까지 내는 응답 */
const VBANK_BY_METHOD_WITH_CASH_AXIS = {
    pg: { used: true, required: true, required_if_cash: true },
    bank: { used: true, required: true, required_if_cash: true },
    points: { used: false, required: false, required_if_cash: false },
};

/** 카드 주문, 계좌가 이미 적힌 신청서 */
const CARD_STORED_BY_METHOD = {
    pg: { used: false, required: false },
    bank: { used: true, required: false },
    points: { used: false, required: false },
};

const TARGET = {
    pathSegment: 'refunds',
    claimId: 12,
    claimNumber: 'RF20261006000012',
    currencyCode: 'KRW',
    refundAmount: '19000',
    refundPointsAmount: '0',
    refundMethods: [
        { value: 'pg', label: 'PG사 환불' },
        { value: 'bank', label: '무통장 환불' },
        { value: 'points', label: '마일리지로 환불' },
    ],
    refundBankName: '국민은행',
    refundBankAccount: '110234567890',
    refundBankHolder: '홍길동',
};

/**
 * 창을 연 뒤 모달 문맥을 돌려준다.
 *
 * @param params 핸들러 파라미터 덮어쓰기
 * @returns `_global.claimRefundExecuteContext`
 */
async function open(params: Record<string, any>): Promise<any> {
    await openClaimRefundExecuteHandler({ handler: 'sirsoft-ecommerce.openClaimRefundExecute', params: { ...TARGET, ...params } } as any, {} as any);

    return mockGlobalState.claimRefundExecuteContext;
}

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

const nodes = flatten(refundModal);
const byRef = (ref: string) => nodes.find((n) => n.id === ref || n.props?.['data-ref'] === ref);
const bankRow = nodes.find((n) => typeof n.if === 'string' && n.if.includes('refundBankName') && !n.if.includes('refundBankAccount'));
const accountInput = nodes.find((n) => typeof n.if === 'string' && n.if.includes('refundAccountRequired'));

describe('① 계좌 줄·입력 칸은 지금 고른 환불 수단의 판정을 따른다', () => {
    /**
     * @scenario payment=card, stored_account=present
     * @effects refund_modal_account_rows_follow_selected_method
     */
    it('「마일리지로 환불」을 고르면 저장 계좌가 있어도 계좌 줄이 서지 않는다', async () => {
        const ctx = await open({ refundMethod: 'points', refundAccountByMethod: CARD_STORED_BY_METHOD });

        expect(ctx.refundAccountUsed).toBe(false);
        expect(bankRow, '은행 줄 노드가 없다').toBeDefined();
        expect(visible(bankRow, { claimRefundExecuteContext: ctx })).toBe(false);
    });

    /**
     * @scenario payment=card, stored_account=present
     * @effects refund_modal_account_rows_follow_selected_method
     */
    it('카드 주문의 PG 환불은 계좌 줄을 보이지 않고, 계좌 환불로 바꾸면 보인다', async () => {
        const pg = await open({ refundMethod: 'pg', refundAccountByMethod: CARD_STORED_BY_METHOD });
        expect(visible(bankRow, { claimRefundExecuteContext: pg })).toBe(false);

        const bank = await open({ refundMethod: 'bank', refundAccountByMethod: CARD_STORED_BY_METHOD });
        expect(visible(bankRow, { claimRefundExecuteContext: bank })).toBe(true);
    });

    /**
     * @scenario payment=vbank_deposited, stored_account=none
     * @effects refund_modal_account_rows_follow_selected_method
     */
    it('계좌가 필요한지는 지금 고른 수단으로 판정한다 — 저장 수단의 판정이 아니다', async () => {
        // 저장 수단 판정(refundAccountRequired)은 「필요」여도, 마일리지로 바꾸면 입력 칸·잠금이 풀린다.
        const points = await open({ refundMethod: 'points', refundAccountRequired: true, refundAccountByMethod: VBANK_BY_METHOD, refundBankName: '', refundBankAccount: '', refundBankHolder: '' });
        expect(points.refundAccountRequired).toBe(false);
        expect(visible(accountInput, { claimRefundExecuteContext: points })).toBe(false);

        // 입금된 가상계좌의 PG 환불은 PG 가 고객 계좌로 송금하므로 계좌 칸이 선다.
        const pg = await open({ refundMethod: 'pg', refundAccountRequired: false, refundAccountByMethod: VBANK_BY_METHOD, refundBankName: '', refundBankAccount: '', refundBankHolder: '' });
        expect(pg.refundAccountRequired).toBe(true);
        expect(visible(accountInput, { claimRefundExecuteContext: pg })).toBe(true);
    });

    /**
     * @scenario payment=vbank_deposited, stored_account=none
     * @effects refund_modal_account_rows_follow_selected_method
     */
    it('계좌가 필요한지는 저장값이 아니라 지금 입력한 현금액으로 판정한다 — 실행 게이트가 본문 금액을 본다', async () => {
        // 저장 현금 0(마일리지 몫만)인 신청서: 서버 저장값 판정(required)은 「불필요」지만, 현금을 입력하고 계좌 환불로 실행하면
        // 실행 게이트는 계좌를 요구한다 — 창에 계좌 칸·동의가 서야 그 창에서 진행할 수 있다.
        const zeroCash = {
            pg: { used: true, required: false, required_if_cash: true },
            bank: { used: true, required: false, required_if_cash: true },
            points: { used: false, required: false, required_if_cash: false },
        };
        const typed = await open({ refundMethod: 'bank', refundAmount: '5000', refundAccountByMethod: zeroCash, refundBankName: '', refundBankAccount: '', refundBankHolder: '' });
        expect(typed.refundAccountRequired).toBe(true);
        expect(visible(accountInput, { claimRefundExecuteContext: typed })).toBe(true);

        // 반대로 저장 현금이 있어도 입력 현금을 0 으로 두면 계좌로 나갈 돈이 없다 — 칸·잠금이 서지 않는다.
        const cleared = await open({ refundMethod: 'bank', refundAmount: '0', refundAccountByMethod: VBANK_BY_METHOD_WITH_CASH_AXIS, refundBankName: '', refundBankAccount: '', refundBankHolder: '' });
        expect(cleared.refundAccountRequired).toBe(false);
    });
});

describe('③ 교환 차액 환급 줄과 합계', () => {
    /**
     * @effects refund_modal_account_rows_follow_selected_method
     */
    it('차액 환급이 있으면 줄을 보이고 최종 합계에 더한다', async () => {
        const ctx = await open({ refundMethod: 'bank', refundAccountByMethod: CARD_STORED_BY_METHOD, exchangeDifferenceTotal: '4000', exchangeDifferenceFormatted: '4,000원 (마일리지)' });

        expect(ctx.exchangeDifferenceTotal).toBe(4000);
        expect(ctx.exchangeDifferenceFormatted).toBe('4,000원 (마일리지)');
        expect(ctx.refundTotal).toBe(23000);

        const row = byRef('crm_011_exchange_difference');
        expect(row, '교환 차액 줄 노드가 없다').toBeDefined();
        expect(JSON.stringify(row)).toContain('exchangeDifferenceFormatted');
        const rowWithIf = nodes.find((n) => typeof n.if === 'string' && n.if.includes('exchangeDifferenceTotal'));
        expect(visible(rowWithIf, { claimRefundExecuteContext: ctx })).toBe(true);
    });

    /**
     * @effects refund_modal_account_rows_follow_selected_method
     */
    it('차액 환급이 없으면 줄이 서지 않고 합계는 현금 + 마일리지다', async () => {
        const ctx = await open({ refundMethod: 'bank', refundAccountByMethod: CARD_STORED_BY_METHOD });

        expect(ctx.exchangeDifferenceTotal).toBe(0);
        expect(ctx.refundTotal).toBe(19000);
        const rowWithIf = nodes.find((n) => typeof n.if === 'string' && n.if.includes('exchangeDifferenceTotal'));
        expect(visible(rowWithIf, { claimRefundExecuteContext: ctx })).toBe(false);
    });
});
