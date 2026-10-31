/**
 * #599 validate P7-B — 가린 등록 계좌 프리필과 환불 계좌 입력
 *
 * @description
 * `claimable-options` 의 `refund_account.prefill` 은 이제 가린 모양이다
 * (`{bank_code, account_number: "********7890", holder: "홍**", masked: true}`).
 * 서버(`RefundBankMask::restoreRegistered`)는 **세 칸이 모두 가린 모양 그대로** 올 때만 등록 원문으로
 * 되돌린다. 한 칸이라도 바꾸면 나머지 가린 칸이 별표째 저장된다. 그래서 화면은 가린 프리필을
 * 「전부 그대로 두거나 전부 새로 쓰거나」 로 다룬다. 한 칸을 고치는 순간 다른 가린 칸을 비워
 * 다시 입력하게 하고, 별표가 섞인 값은 제출하지 않는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    initClaimApplyHandler,
    setClaimRefundAccountFieldHandler,
    submitClaimHandler,
} from '../../handlers/claimApplyHandlers';

let mockLocalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
        get: vi.fn(() => ({})),
    },
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    dataSource: { get: vi.fn(() => null) },
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    dispatch: vi.fn(),
    t: vi.fn((key: string) => key),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const MASKED_PREFILL = { bank_code: '004', account_number: '********7890', holder: '홍**', masked: true };

function claimable(prefill: Record<string, any> | null) {
    return {
        base_currency: 'KRW',
        options: [{ option: { id: 310, unit_price: 10000 }, claimable_types: ['refund'], available_quantity: 1, refund_branch: 'request' }],
        reasons: { refund: [{ code: 'changed_mind', fault_type: 'customer' }] },
        settings: { allowed_charge_methods: [], charge_payment_methods: [] },
        refund_account: { required: true, prefill, banks: [] },
    };
}

function seed(prefill: Record<string, any> | null = MASKED_PREFILL): void {
    Object.defineProperty(window, 'location', {
        value: { pathname: '/mypage/orders/ORD-1/claims/apply/refund', search: '?options=310' },
        writable: true,
        configurable: true,
    });
    initClaimApplyHandler(
        { handler: 'initClaimApply', params: { claimType: 'refund', preselected: '310', claimable: claimable(prefill) } },
        {} as any
    );
}

function setField(field: string, value: string): void {
    setClaimRefundAccountFieldHandler({ handler: 'setClaimRefundAccountField', params: { field, value } }, {} as any);
}

beforeEach(() => {
    vi.clearAllMocks();
    mockLocalState = {};
    (window as any).G7Core = mockG7Core;
});

describe('가린 등록 계좌 프리필', () => {
    /**
     * 가린 프리필은 세 칸에 그대로 채우고, 가린 값이라는 사실을 로컬에 남긴다(안내·판정의 근거).
     *
     * @scenario prefill=masked, edit=untouched
     * @effects claim_refund_account_masked_prefill_is_all_or_nothing
     */
    it('가린 프리필로 세 칸을 채우고 masked 상태를 남긴다', () => {
        seed();

        expect(mockLocalState.claimRefundBankCode).toBe('004');
        expect(mockLocalState.claimRefundBankAccount).toBe('********7890');
        expect(mockLocalState.claimRefundBankHolder).toBe('홍**');
        expect(mockLocalState.claimRefundAccountMasked).toBe(true);
    });

    /**
     * 손대지 않으면 가린 값 그대로 보낸다 — 서버가 등록 원문으로 되돌린다.
     *
     * @scenario prefill=masked, edit=untouched
     * @effects claim_refund_account_masked_prefill_is_all_or_nothing
     */
    it('손대지 않고 제출하면 가린 세 값을 그대로 보낸다', async () => {
        seed();
        mockLocalState.claimReason = 'changed_mind';
        mockG7Core.api.post.mockResolvedValue({ success: true, data: {} });

        await submitClaimHandler({ handler: 'submitClaim', params: { claimApiBase: '/api/x' } }, {} as any);

        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            '/api/x/refund-requests',
            expect.objectContaining({ refund_bank: { bank_code: '004', account_number: '********7890', holder: '홍**' } }),
            undefined
        );
    });

    /**
     * 가린 계좌번호의 한 글자를 고치면 별표와 새 숫자가 섞인다. 그 값은 버리고, 나머지 가린 칸(예금주)도
     * 비워 세 칸을 처음부터 새로 쓰게 한다. 은행은 가린 값이 아니라 그대로 둔다.
     *
     * @scenario prefill=masked, edit=account_partial
     * @effects claim_refund_account_masked_prefill_is_all_or_nothing
     */
    it('가린 계좌번호를 일부만 고치면 계좌번호·예금주를 비운다', () => {
        seed();
        setField('account_number', '********78901');

        expect(mockLocalState.claimRefundBankAccount).toBe('');
        expect(mockLocalState.claimRefundBankHolder).toBe('');
        expect(mockLocalState.claimRefundBankCode).toBe('004');
        expect(mockLocalState.claimRefundAccountMasked).toBe(false);
    });

    /**
     * 예금주를 통째로 새로 쓰면(별표 없음) 그 값은 살리고, 가린 계좌번호는 비운다 — 새 예금주와
     * 등록 계좌번호가 섞여 저장되지 않게.
     *
     * @scenario prefill=masked, edit=holder_replaced
     * @effects claim_refund_account_masked_prefill_is_all_or_nothing
     */
    it('예금주를 새로 쓰면 그 값은 두고 가린 계좌번호를 비운다', () => {
        seed();
        setField('holder', '김철수');

        expect(mockLocalState.claimRefundBankHolder).toBe('김철수');
        expect(mockLocalState.claimRefundBankAccount).toBe('');
        expect(mockLocalState.claimRefundAccountMasked).toBe(false);
    });

    /**
     * 은행을 바꾸면 등록 계좌번호는 그 은행의 계좌가 아니다 — 가린 두 칸을 비운다.
     *
     * @scenario prefill=masked, edit=bank_changed
     * @effects claim_refund_account_masked_prefill_is_all_or_nothing
     */
    it('은행을 바꾸면 가린 계좌번호·예금주를 비운다', () => {
        seed();
        setField('bank_code', '088');

        expect(mockLocalState.claimRefundBankCode).toBe('088');
        expect(mockLocalState.claimRefundBankAccount).toBe('');
        expect(mockLocalState.claimRefundBankHolder).toBe('');
    });

    /**
     * 가린 상태가 풀린 뒤 별표가 섞인 값은 제출하지 않는다 — 서버가 되돌리지 않고 별표째 저장한다.
     *
     * @scenario prefill=masked, edit=mixed_submit
     * @effects claim_refund_account_masked_value_never_mixes_on_submit
     */
    it('별표가 섞인 계좌는 제출하지 않고 계좌 칸에 사유를 남긴다', async () => {
        seed();
        mockLocalState.claimReason = 'changed_mind';
        mockLocalState.claimRefundAccountMasked = false;
        mockLocalState.claimRefundBankHolder = '김철수';

        await submitClaimHandler({ handler: 'submitClaim', params: { claimApiBase: '/api/x' } }, {} as any);

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
        expect(mockLocalState.claimFieldErrors?.refund_bank).toBeTruthy();
    });

    /**
     * 가리지 않은 프리필(구버전 서버)은 종전대로 칸마다 따로 고친다.
     *
     * @scenario prefill=plain, edit=holder_replaced
     * @effects claim_refund_account_masked_prefill_is_all_or_nothing
     */
    it('가리지 않은 프리필은 한 칸을 고쳐도 다른 칸을 비우지 않는다', () => {
        seed({ bank_code: '004', account_number: '1234567890', holder: '홍길동' });
        setField('holder', '김철수');

        expect(mockLocalState.claimRefundAccountMasked).toBe(false);
        expect(mockLocalState.claimRefundBankAccount).toBe('1234567890');
        expect(mockLocalState.claimRefundBankHolder).toBe('김철수');
    });
});
