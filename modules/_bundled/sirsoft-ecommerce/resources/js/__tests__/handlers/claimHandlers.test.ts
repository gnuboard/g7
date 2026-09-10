/**
 * 관리자 클레임 목록 핸들러 테스트 (S8b · D26)
 *
 * @description
 * 화면에서는 확인할 수 없고 요청 본문에서만 드러나는 축을 고정한다:
 *  - 완료 전이는 곧바로 실행되지 않고 완료 처리 모달을 연다 (자동 접수 금지 — D26)
 *  - 미리보기와 실행의 페이로드 모양이 다르다 (신청서별 맵 vs 한 맵으로 병합)
 *  - `create_refund_request=false` 를 고르면 그 값이 그대로 실행 본문에 실린다
 *  - 부분 성공 계약: 건너뛴 건이 있으면 성공 토스트와 별개로 알린다
 *  - 취소 유형은 일괄 전이 대상이 아니다
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import {
    buildClaimBulkConfirmDataHandler,
    executeClaimBulkActionHandler,
    openClaimCompleteModalHandler,
    toggleClaimCompleteStockHandler,
    toggleClaimCompleteStockAllHandler,
    setClaimCompleteRefundRequestHandler,
    executeClaimCompletionHandler,
    clearClaimCompleteTimersHandler,
} from '../../handlers/claimHandlers';

const PREVIEW_URL = '/api/modules/sirsoft-ecommerce/admin/claims/completion-preview';
const BULK_STATUS_URL = '/api/modules/sirsoft-ecommerce/admin/claims/bulk-status';

let mockGlobalState: Record<string, any> = {};

/** 완료 미리보기 기본 응답 (반품 1건 · 상품 행 55) */
const PREVIEW_RESPONSE = {
    success: true,
    data: {
        claims: [
            {
                id: 31,
                claim_number: 'RT20260826000031',
                currency_code: 'KRW',
                completable_item_ids: [55],
                completable_item_count: 1,
                has_stock_restore_target: true,
                stock_restore_count: 1,
                stock_discard_count: 0,
                earn_recovery: true,
                coupon_restore: false,
                refund: {
                    applicable: true,
                    already_filed: false,
                    blocked_reason: null,
                    refund_amount: 55000,
                    refund_amount_formatted: '55,000원',
                    refund_points_amount: 0,
                    refund_points_amount_formatted: '0원',
                },
            },
        ],
        skipped: [],
        totals: {
            claim_count: 1,
            completable_item_count: 1,
            refund_amount_total: 55000,
            currency_code: 'KRW',
            refund_amount_total_formatted: '55,000원',
        },
    },
};

const mockG7Core = {
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
    api: {
        post: vi.fn(),
        patch: vi.fn(),
    },
    dispatch: vi.fn(),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const mockContext = {} as any;

/** 목록 행 두 벌 */
const ROWS = [
    {
        id: 31,
        claim_number: 'RT20260826000031',
        requester_name: '박서준',
        item_summary: { product_name: '헤비 후드 XL', item_count: 1, total_quantity: 1 },
    },
    {
        id: 34,
        claim_number: 'RT20260826000034',
        requester_name: '김지은',
        item_summary: { product_name: '소프트 터치 니트', item_count: 3, total_quantity: 4 },
    },
];

beforeEach(() => {
    mockGlobalState = {};
    vi.clearAllMocks();
    mockG7Core.api.post.mockResolvedValue(PREVIEW_RESPONSE);
    mockG7Core.api.patch.mockResolvedValue({ success: true });
    (window as any).G7Core = mockG7Core;
});

/**
 * `api.post` 호출 중 특정 URL 로 간 마지막 본문을 돌려준다.
 *
 * @param url 대상 URL
 * @returns 요청 본문
 */
function lastBodyTo(url: string): any {
    const calls = mockG7Core.api.post.mock.calls.filter((call) => call[0] === url);

    return calls.length > 0 ? calls[calls.length - 1][1] : null;
}

describe('일괄 상태 변경 — 입력 가드', () => {
    /** @effects bulk_confirmation_requires_selection_and_status */
    it('선택이 없으면 확인 모달을 열지 않는다', async () => {
        await buildClaimBulkConfirmDataHandler(
            { handler: 'x', params: { claimType: 'return', status: 'approved', ids: [], rows: ROWS } },
            mockContext,
        );

        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /** @effects bulk_confirmation_requires_selection_and_status */
    it('상태를 고르지 않으면 확인 모달을 열지 않는다', async () => {
        await buildClaimBulkConfirmDataHandler(
            { handler: 'x', params: { claimType: 'return', status: '', ids: [31], rows: ROWS } },
            mockContext,
        );

        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /**
     * 취소는 게이트의 `type` 어휘에 없다 — 요청이 나가면 422 다.
     *
     * @scenario claim_type=cancel
     * @effects bulk_bar_absent_for_cancel
     */
    it('취소 유형은 요청을 만들지 않는다', async () => {
        await buildClaimBulkConfirmDataHandler(
            { handler: 'x', params: { claimType: 'cancel', status: 'approved', ids: [31], rows: ROWS } },
            mockContext,
        );

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
    });

    /** @effects noncomplete_transition_uses_confirmation */
    it('완료가 아닌 전이는 확인 모달에 유형·상태·라벨을 실어 연다', async () => {
        await buildClaimBulkConfirmDataHandler(
            { handler: 'x', params: { claimType: 'return', status: 'approved', ids: [31, 34], rows: ROWS } },
            mockContext,
        );

        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_bulk_confirm');
        expect(mockGlobalState.claimBulkConfirmData).toMatchObject({
            claimType: 'return',
            status: 'approved',
            ids: [31, 34],
        });
        // 라벨은 유형 접두 Enum 키에서 해석한다 (라벨 표 복제 금지).
        expect(mockG7Core.t).toHaveBeenCalledWith(
            'sirsoft-ecommerce.enums.return_status.approved',
            undefined,
        );
    });
});

describe('완료 전이 — 곧바로 실행하지 않고 모달을 연다 (D26)', () => {
    /**
     * 자동 접수 금지가 D26 의 핵심이다 — 완료를 고른 것만으로 상태가 바뀌면 재고 복구·환불
     * 접수 여부를 운영자가 고를 기회 자체가 사라진다.
     *
     * @effects completion_opens_modal_never_executes_directly
     */
    it('완료를 고르면 전이 요청 대신 미리보기만 나간다', async () => {
        await buildClaimBulkConfirmDataHandler(
            { handler: 'x', params: { claimType: 'return', status: 'completed', ids: [31], rows: ROWS } },
            mockContext,
        );

        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_complete');
        // 나간 요청은 전부 미리보기다 — 전이 요청은 운영자가 모달에서 누른 뒤에만 나간다.
        expect(mockG7Core.api.post.mock.calls.every((call) => call[0] === PREVIEW_URL)).toBe(true);
        expect(lastBodyTo(BULK_STATUS_URL)).toBeNull();
    });

    /**
     * 설정은 초기 선택값일 뿐이다 — 값이 어느 쪽이든 실행은 운영자가 누른 뒤에만 일어난다.
     *
     * @effects settings_seed_initial_choice_only
     */
    it('설정 기본값이 모달의 초기 선택으로 시드된다', async () => {
        await buildClaimBulkConfirmDataHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    status: 'completed',
                    ids: [31],
                    rows: ROWS,
                    completeDefaults: { create_refund_request: false, restore_stock: false },
                },
            },
            mockContext,
        );

        expect(mockGlobalState.claimCompleteCreateRefundRequest).toBe(false);
        expect(mockGlobalState.claimCompleteRestore).toEqual({ '31': false });
        expect(lastBodyTo(PREVIEW_URL).create_refund_request).toBe(false);
    });

    /** @effects settings_seed_initial_choice_only */
    it('설정이 없으면 계약 기본값(둘 다 접수·복구)으로 시드된다', async () => {
        await buildClaimBulkConfirmDataHandler(
            { handler: 'x', params: { claimType: 'return', status: 'completed', ids: [31], rows: ROWS } },
            mockContext,
        );

        expect(mockGlobalState.claimCompleteCreateRefundRequest).toBe(true);
        expect(mockGlobalState.claimCompleteRestore).toEqual({ '31': true });
    });

    /**
     * 서버가 받는 재고 맵의 키는 상품 행 ID 인데 그 목록은 미리보기 응답에만 있다 — 첫
     * 호출은 빈 맵으로 나가고 서버는 그것을 '체크 없음'(전건 폐기)으로 계산한다. 그대로
     * 두면 체크박스는 켜져 있는데 '함께 실행되는 처리' 요약만 "재고 0복구 / N폐기" 로
     * 나온다. 값은 실행 시점에 다시 만들어져 결과는 옳지만, 미리보기와 실행이 같은 식을
     * 쓰는 것이 D26 의 계약이므로 응답이 상품 행을 알려 주면 한 번 더 부른다.
     *
     * @effects completion_preview_reflects_seeded_stock_choice
     */
    it('모달을 열면 시드된 재고 선택이 미리보기에 반영된다', async () => {
        await buildClaimBulkConfirmDataHandler(
            { handler: 'x', params: { claimType: 'return', status: 'completed', ids: [31], rows: ROWS } },
            mockContext,
        );

        const previewCalls = mockG7Core.api.post.mock.calls.filter((call) => call[0] === PREVIEW_URL);

        expect(previewCalls).toHaveLength(2);
        // 첫 호출은 상품 행을 모른다.
        expect(previewCalls[0][1].claims[0].restore_stock_by_item).toEqual({});
        // 응답이 알려 준 상품 행에 시드값(복구)을 펴서 다시 부른다.
        expect(previewCalls[1][1].claims[0].restore_stock_by_item).toEqual({ '55': true });
    });

    /**
     * 재고 기본값이 꺼진 상점에서는 빈 맵과 전건 false 맵이 서버에게 같은 요청이다 —
     * 그때는 추가 요청이 발생하지 않는다.
     *
     * @effects completion_preview_reflects_seeded_stock_choice
     */
    it('재고 기본값이 꺼져 있으면 추가 요청이 없다', async () => {
        await buildClaimBulkConfirmDataHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    status: 'completed',
                    ids: [31],
                    rows: ROWS,
                    completeDefaults: { restore_stock: false },
                },
            },
            mockContext,
        );

        expect(mockG7Core.api.post.mock.calls.filter((call) => call[0] === PREVIEW_URL)).toHaveLength(1);
    });

    /** @effects completion_rows_use_item_summary */
    it('상품 요약이 모달 행의 표기가 된다', async () => {
        await buildClaimBulkConfirmDataHandler(
            { handler: 'x', params: { claimType: 'return', status: 'completed', ids: [31], rows: ROWS } },
            mockContext,
        );

        expect(mockGlobalState.claimCompleteRows[0]).toMatchObject({
            claim_number: 'RT20260826000031',
            requester_name: '박서준',
            item_label: '헤비 후드 XL',
            refund_applicable: true,
            refund_amount_formatted: '55,000원',
        });
    });
});

describe('완료 처리 모달 — 선택이 미리보기에 반영된다', () => {
    /**
     * 모달의 체크는 신청서 단위인데 서버 계약은 상품 행 단위다 — 직전 미리보기가 답한
     * `completable_item_ids` 전체에 같은 값을 펴야 요약이 실제 실행과 같아진다.
     *
     * @effects stock_toggle_expands_to_item_rows
     */
    it('건별 체크를 끄면 그 신청서의 상품 행 전체가 false 로 나간다', async () => {
        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    targets: [{ id: 31, claim_number: 'RT20260826000031' }],
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'return', ids: [31] } },
                },
            },
            mockContext,
        );

        await toggleClaimCompleteStockHandler(
            { handler: 'x', params: { claimId: 31, restore: false } },
            mockContext,
        );

        expect(lastBodyTo(PREVIEW_URL).claims[0].restore_stock_by_item).toEqual({ '55': false });
    });

    /**
     * `refund.applicable` 은 이 값에 달려 있다 — 라디오를 바꾸고 미리보기를 다시 부르지
     * 않으면 모달이 예고하는 금액과 실제 접수가 갈린다.
     *
     * @effects refund_radio_refreshes_preview
     */
    it('접수 라디오를 바꾸면 그 값으로 미리보기를 다시 부른다', async () => {
        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    targets: [{ id: 31 }],
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'return', ids: [31] } },
                },
            },
            mockContext,
        );

        await setClaimCompleteRefundRequestHandler({ handler: 'x', params: { value: false } }, mockContext);

        expect(lastBodyTo(PREVIEW_URL).create_refund_request).toBe(false);
    });
});

describe('완료 실행 — 페이로드 계약 (D26)', () => {
    /**
     * 모달을 열고 실행까지 가는 한 벌.
     *
     * @param createRefundRequest 접수 여부
     */
    async function openAndExecute(createRefundRequest: boolean): Promise<void> {
        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    targets: [{ id: 31, claim_number: 'RT20260826000031' }],
                    defaults: { create_refund_request: createRefundRequest },
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'return', ids: [31] } },
                },
            },
            mockContext,
        );

        mockG7Core.api.post.mockResolvedValue({
            success: true,
            data: { succeeded: [31], payment_requested: [], skipped: [] },
        });

        await executeClaimCompletionHandler({ handler: 'x' }, mockContext);
    }

    /**
     * 미리보기는 신청서마다 맵을 나눠 싣고, 실행은 전 신청서의 상품 행 ID 를 한 맵에 모은다.
     *
     * @scenario claim_type=return, completion_choice=file_now
     * @effects completion_payload_merges_item_maps
     */
    it('실행 본문은 completion 래퍼에 병합된 재고 맵을 싣는다', async () => {
        await openAndExecute(true);

        const body = lastBodyTo(BULK_STATUS_URL);
        expect(body).toMatchObject({
            type: 'return',
            ids: [31],
            status: 'completed',
            completion: { restore_stock_by_item: { '55': true }, create_refund_request: true },
        });
        // 미리보기 페이로드의 모양을 그대로 재사용하지 않는다.
        expect(body.claims).toBeUndefined();
        expect(body.claim_type).toBeUndefined();
    });

    /**
     * '접수하지 않음' 을 고르면 그 값이 그대로 실려야 한다 — true 로 굳으면 운영자가
     * 고르지 않은 환불신청서가 생긴다.
     *
     * @scenario claim_type=return, completion_choice=file_later
     * @effects refund_request_choice_reaches_server
     */
    it('접수하지 않음을 고르면 create_refund_request=false 로 나간다', async () => {
        await openAndExecute(false);

        expect(lastBodyTo(BULK_STATUS_URL).completion.create_refund_request).toBe(false);
    });

    /**
     * 교환 완료도 같은 모달·같은 실행 경로를 쓴다 — 저가 교환의 차액 환불신청서가 이 선택에
     * 달려 있다.
     *
     * @scenario claim_type=exchange, completion_choice=file_now
     * @effects refund_request_choice_reaches_server, completion_payload_merges_item_maps
     */
    it('교환 완료도 접수 선택을 그대로 실어 보낸다', async () => {
        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'exchange',
                    targets: [{ id: 31, claim_number: 'EX20260826000031' }],
                    defaults: { create_refund_request: true },
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'exchange', ids: [31] } },
                },
            },
            mockContext,
        );

        mockG7Core.api.post.mockResolvedValue({ success: true, data: { succeeded: [31], skipped: [] } });
        await executeClaimCompletionHandler({ handler: 'x' }, mockContext);

        const body = lastBodyTo(BULK_STATUS_URL);
        expect(body.type).toBe('exchange');
        expect(body.completion.create_refund_request).toBe(true);
        expect(body.completion.restore_stock_by_item).toEqual({ '55': true });
    });

    /**
     * @scenario claim_type=exchange, completion_choice=file_later
     * @effects refund_request_choice_reaches_server
     */
    it('교환에서 접수하지 않음을 고르면 그 값이 실린다', async () => {
        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'exchange',
                    targets: [{ id: 31 }],
                    defaults: { create_refund_request: false },
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'exchange', ids: [31] } },
                },
            },
            mockContext,
        );

        mockG7Core.api.post.mockResolvedValue({ success: true, data: { succeeded: [31], skipped: [] } });
        await executeClaimCompletionHandler({ handler: 'x' }, mockContext);

        expect(lastBodyTo(BULK_STATUS_URL).completion.create_refund_request).toBe(false);
    });

    /**
     * 조회되지 않은 신청서(skipped)까지 대상에 실으면 서버가 다시 걸러 내는 왕복이 는다.
     *
     * @effects execution_targets_only_previewed_claims
     */
    it('미리보기에서 빠진 신청서는 실행 대상에서 제외된다', async () => {
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            data: {
                ...PREVIEW_RESPONSE.data,
                skipped: [{ id: 999999, message: '해당 신청서를 찾을 수 없습니다.' }],
            },
        });

        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    targets: [{ id: 31 }, { id: 999999 }],
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'return', ids: [31, 999999] } },
                },
            },
            mockContext,
        );

        mockG7Core.api.post.mockResolvedValue({ success: true, data: { succeeded: [31], skipped: [] } });
        await executeClaimCompletionHandler({ handler: 'x' }, mockContext);

        expect(lastBodyTo(BULK_STATUS_URL).ids).toEqual([31]);
    });

    /** @effects completion_success_clears_state_and_refetches */
    it('실행 후 모달 상태를 비우고 목록을 다시 부른다', async () => {
        await openAndExecute(true);

        expect(mockGlobalState.claimCompleteContext).toBeNull();
        expect(mockGlobalState.claimCompleteRows).toEqual([]);
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'refetchDataSource',
            params: { dataSourceId: 'claims' },
        });
    });

    /**
     * 대상이 없는데 실행이 나가면 빈 요청이 서버에 도달한다.
     *
     * @effects submit_disabled_without_targets
     */
    it('대상이 없으면 요청을 만들지 않는다', async () => {
        mockGlobalState = { claimCompleteContext: { execute: { url: BULK_STATUS_URL, method: 'POST' } }, claimCompleteRows: [] };

        await executeClaimCompletionHandler({ handler: 'x' }, mockContext);

        expect(lastBodyTo(BULK_STATUS_URL)).toBeNull();
        expect(mockG7Core.toast.error).toHaveBeenCalled();
    });
});

describe('일괄 전이 실행 — 부분 성공 계약', () => {
    beforeEach(() => {
        mockGlobalState = {
            claimBulkConfirmData: { claimType: 'return', ids: [31, 34], status: 'approved' },
        };
    });

    /** @effects bulk_transition_payload_and_error_contract */
    it('요청 본문은 유형·대상·상태 셋만 싣는다', async () => {
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            data: { succeeded: [31, 34], payment_requested: [], skipped: [] },
        });

        await executeClaimBulkActionHandler({ handler: 'x' }, mockContext);

        expect(lastBodyTo(BULK_STATUS_URL)).toEqual({ type: 'return', ids: [31, 34], status: 'approved' });
    });

    /**
     * 성공 토스트만 띄우면 막힌 건이 처리된 것으로 보인다 — 게이트에 막힌 건은 오류가 아니라
     * 정상 200 응답 안에 실려 오므로 화면에 아무 표시가 없으면 알아챌 방법이 없다.
     *
     * @effects skipped_claims_reported_separately
     */
    it('건너뛴 건이 있으면 성공과 별개로 알린다', async () => {
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            data: {
                succeeded: [31],
                payment_requested: [],
                skipped: [{ id: 34, claim_number: 'RT...034', message: '반품반려 에서 반품승인 로는 상태를 변경할 수 없습니다.' }],
            },
        });

        await executeClaimBulkActionHandler({ handler: 'x' }, mockContext);

        expect(mockG7Core.toast.success).toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /**
     * 승인이 결제 요청으로 우회된 건은 운영자에게 다른 결과다.
     *
     * @effects payment_requested_counted_separately
     */
    it('결과 요약에 성공·결제요청·건너뜀 건수가 각각 실린다', async () => {
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            data: { succeeded: [31], payment_requested: [34], skipped: [] },
        });

        await executeClaimBulkActionHandler({ handler: 'x' }, mockContext);

        expect(mockG7Core.t).toHaveBeenCalledWith(
            'sirsoft-ecommerce.admin.claim.bulk.result_summary',
            { succeeded: 1, requested: 1, skipped: 0 },
        );
    });

    /** @effects bulk_transition_payload_and_error_contract */
    it('실패 시 서버 사유를 그대로 보여준다', async () => {
        mockG7Core.api.post.mockRejectedValue({
            response: { data: { message: '해당 신청서를 찾을 수 없습니다.' } },
        });

        await expect(executeClaimBulkActionHandler({ handler: 'x' }, mockContext)).rejects.toBeDefined();
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('해당 신청서를 찾을 수 없습니다.');
    });

    /** @effects bulk_confirmation_requires_selection_and_status */
    it('확인 데이터가 없으면 요청을 만들지 않는다', async () => {
        mockGlobalState = {};

        await executeClaimBulkActionHandler({ handler: 'x' }, mockContext);

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
        expect(mockG7Core.toast.error).toHaveBeenCalled();
    });
});

describe('미리보기 실패 — 화면이 예고하는 것과 실행하는 것의 일치 (D26)', () => {
    /**
     * 모달을 열어 첫 미리보기까지 성공시킨 상태를 만듭니다.
     *
     * @returns 없음
     */
    async function openModalWithPreview(): Promise<void> {
        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    targets: [{ id: 31, claim_number: 'RT20260826000031', item_label: '헤비 후드 XL' }],
                    defaults: { create_refund_request: true, restore_stock: true },
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'return', ids: [31] } },
                },
            },
            mockContext,
        );
    }

    /** @effects preview_failure_restores_displayed_choice */
    it('재고 체크를 끈 뒤 미리보기가 실패하면 선택이 화면 값으로 되돌아온다', async () => {
        await openModalWithPreview();
        expect(mockGlobalState.claimCompleteRows[0].restore).toBe(true);

        mockG7Core.api.post.mockRejectedValue({ response: { data: { message: '일시 오류' } } });

        await toggleClaimCompleteStockHandler(
            { handler: 'x', params: { claimId: 31, restore: false } },
            mockContext,
        );

        // 체크박스는 직전 성공 응답(rows)을 읽으므로 화면은 켜진 채로 남는다 —
        // 실행이 쓰는 값만 꺼진 채면 예고와 실행이 갈린다.
        expect(mockGlobalState.claimCompleteRows[0].restore).toBe(true);
        expect(mockGlobalState.claimCompleteRestore['31']).toBe(true);
    });

    /** @effects preview_failure_restores_displayed_choice */
    it('헤더 일괄 체크도 미리보기가 실패하면 되돌아온다', async () => {
        await openModalWithPreview();

        mockG7Core.api.post.mockRejectedValue(new Error('boom'));

        await toggleClaimCompleteStockAllHandler(
            { handler: 'x', params: { restore: false } },
            mockContext,
        );

        expect(mockGlobalState.claimCompleteRestore['31']).toBe(true);
    });

    /** @effects preview_failure_restores_displayed_choice */
    it('환불신청서 선택을 바꾼 뒤 미리보기가 실패하면 그 선택도 되돌아온다', async () => {
        await openModalWithPreview();
        expect(mockGlobalState.claimCompleteCreateRefundRequest).toBe(true);

        mockG7Core.api.post.mockRejectedValue(new Error('boom'));

        await setClaimCompleteRefundRequestHandler(
            { handler: 'x', params: { value: false } },
            mockContext,
        );

        // 요약('완료와 동시에 N건 생성')은 직전 미리보기 총계를 그리므로, 라디오만 바뀌면
        // 예고 건수와 실제 접수가 갈린다.
        expect(mockGlobalState.claimCompleteCreateRefundRequest).toBe(true);
    });

    /** @effects preview_success_updates_displayed_choice */
    it('미리보기가 성공하면 화면과 실행값이 새 선택으로 함께 바뀐다', async () => {
        await openModalWithPreview();

        await toggleClaimCompleteStockHandler(
            { handler: 'x', params: { claimId: 31, restore: false } },
            mockContext,
        );

        // 되돌리기는 실패 경로 전용이다 — 성공한 토글까지 되돌리면 체크가 먹지 않는다.
        expect(mockGlobalState.claimCompleteRestore['31']).toBe(false);
        expect(mockGlobalState.claimCompleteRows[0].restore).toBe(false);
    });
});

/** 핸들러의 debounce 창(200ms)을 확실히 넘기는 대기 시간. */
const PREVIEW_DEBOUNCE_WINDOW_MS = 300;

describe('완료 처리 모달 — 미리보기 응답 경합 (4차 F1)', () => {
    /**
     * 모달을 열고 첫 미리보기를 성공시킨다. (재고 체크 켜짐)
     */
    async function openModalWithPreview(): Promise<void> {
        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    targets: [{ id: 31, claim_number: 'RT20260826000031', item_label: '헤비 후드 XL' }],
                    defaults: { create_refund_request: true, restore_stock: true },
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'return', ids: [31] } },
                },
            },
            mockContext,
        );
    }

    /**
     * 재고 복구 여부만 다른 미리보기 응답을 만든다.
     *
     * @param restore 재고 복구 여부
     * @returns 미리보기 응답
     */
    function previewFor(restore: boolean): Record<string, any> {
        const base = JSON.parse(JSON.stringify(PREVIEW_RESPONSE));
        base.data.claims[0].stock_restore_count = restore ? 1 : 0;
        base.data.claims[0].stock_discard_count = restore ? 0 : 1;

        return base;
    }

    /**
     * @scenario claim_type=return, completion_choice=file_now
     *
     * @effects stale_preview_response_never_overwrites_current_selection
     */
    it('늦게 도착한 옛 응답이 최신 선택을 덮지 않는다', async () => {
        await openModalWithPreview();

        // P1(체크 해제)은 느리고, P2(다시 체크)가 먼저 돌아온다.
        // debounce 창을 넘겨 두 요청이 실제로 겹치게 만든다 — 창 안에서 연달아 누르면
        // 요청이 하나로 합쳐지므로(그것이 debounce 의 몫) 이 축은 창 밖에서만 성립한다.
        let resolveP1: (value: unknown) => void = () => {};
        const p1 = new Promise((resolve) => {
            resolveP1 = resolve;
        });

        mockG7Core.api.post.mockReturnValueOnce(p1 as any);
        const pending = toggleClaimCompleteStockHandler(
            { handler: 'x', params: { claimId: 31, restore: false } },
            mockContext,
        );

        await new Promise((resolve) => setTimeout(resolve, PREVIEW_DEBOUNCE_WINDOW_MS));

        mockG7Core.api.post.mockResolvedValue(previewFor(true));
        await toggleClaimCompleteStockHandler(
            { handler: 'x', params: { claimId: 31, restore: true } },
            mockContext,
        );

        resolveP1(previewFor(false));
        await pending;

        // 체크박스는 rows 를, 실행은 claimCompleteRestore 를 읽는다 — 갈리면 화면이
        // 예고한 것과 다른 재고 처리가 실행된다 (D26 이 모달을 둔 이유가 그 예고다).
        expect(mockGlobalState.claimCompleteRestore['31']).toBe(true);
        expect(mockGlobalState.claimCompleteRows[0].restore).toBe(true);
        expect(mockGlobalState.claimCompletePreview.claims[0].stock_restore_count).toBe(1);
    });

    /**
     * 다음 요청 예약 중에는 이전 요청의 성공/실패가 실행 잠금을 풀지 못한다.
     *
     * @effects pending_preview_reservation_keeps_execution_blocked
     */
    it.each(['item', 'all', 'refund'].flatMap(kind => [false, true].flatMap(oldFails =>
        [false, true].map(latestFails => ({ kind, oldFails, latestFails })),
    )))('예약 경합 $kind (이전 실패=$oldFails, 최신 실패=$latestFails)', async ({ kind, oldFails, latestFails }) => {
        vi.useFakeTimers();
        try {
            await openModalWithPreview();
            mockG7Core.api.post.mockClear();
            let resolveOld!: (value: any) => void;
            let rejectOld!: (error: any) => void;
            mockG7Core.api.post.mockReturnValueOnce(new Promise((resolve, reject) => {
                resolveOld = resolve;
                rejectOld = reject;
            }));
            const select = (value: boolean) => kind === 'refund'
                ? setClaimCompleteRefundRequestHandler({ handler: 'x', params: { value } }, mockContext)
                : kind === 'all'
                    ? toggleClaimCompleteStockAllHandler({ handler: 'x', params: { restore: value } }, mockContext)
                    : toggleClaimCompleteStockHandler({ handler: 'x', params: { claimId: 31, restore: value } }, mockContext);
            const old = select(false);
            await vi.advanceTimersByTimeAsync(200);
            const latest = select(true);
            if (oldFails) rejectOld(new Error('이전 요청 실패'));
            else resolveOld(previewFor(false));
            await old;
            expect(mockGlobalState.claimCompletePreviewLoading).toBe(true);
            expect(mockGlobalState.claimCompleteRows[0].restore).toBe(true);
            expect(mockGlobalState.claimCompleteCreateRefundRequest).toBe(true);
            await executeClaimCompletionHandler({ handler: 'x' }, mockContext);
            expect(mockG7Core.api.post).toHaveBeenCalledTimes(1);
            if (latestFails) mockG7Core.api.post.mockRejectedValueOnce(new Error('최신 요청 실패'));
            else mockG7Core.api.post.mockResolvedValueOnce(previewFor(true));
            await vi.advanceTimersByTimeAsync(200);
            await latest;
            expect(mockGlobalState.claimCompletePreviewLoading).toBe(false);
            expect(mockGlobalState.claimCompleteRestore['31']).toBe(true);
            expect(mockGlobalState.claimCompleteRows[0].restore).toBe(true);
        } finally {
            clearClaimCompleteTimersHandler();
            vi.useRealTimers();
        }
    });

    /**
     * @effects reopened_modal_ignores_previous_session_preview
     */
    it('모달을 다시 열면 앞 세션의 늦은 응답이 새 대상을 덮지 않는다', async () => {
        let resolveStale: (value: unknown) => void = () => {};
        const stale = new Promise((resolve) => {
            resolveStale = resolve;
        });

        mockG7Core.api.post.mockReturnValueOnce(stale as any);
        const pending = openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    targets: [{ id: 31, claim_number: 'RT20260826000031', item_label: '헤비 후드 XL' }],
                    // 재고 기본값이 꺼진 상점이라 이 세션은 재요청(restoreMapChanged)이 없다 —
                    // 재요청이 붙으면 그 요청이 늦은 응답을 덮어 경합이 가려진다.
                    defaults: { create_refund_request: true, restore_stock: false },
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'return', ids: [31] } },
                },
            },
            mockContext,
        );

        // 운영자가 취소하고 다른 신청서로 다시 연다.
        const fresh = JSON.parse(JSON.stringify(PREVIEW_RESPONSE));
        fresh.data.claims[0].id = 34;
        fresh.data.claims[0].claim_number = 'RT20260826000034';
        mockG7Core.api.post.mockResolvedValue(fresh);

        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    targets: [{ id: 34, claim_number: 'RT20260826000034', item_label: '니트 L' }],
                    defaults: { create_refund_request: true, restore_stock: true },
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'return', ids: [34] } },
                },
            },
            mockContext,
        );

        resolveStale(PREVIEW_RESPONSE);
        await pending;

        // 실행 본문의 대상은 미리보기가 답한 신청서에서 나온다 — 앞 세션 응답이 남으면
        // 운영자가 고르지 않은 신청서가 완료 처리된다.
        expect(mockGlobalState.claimCompleteRows.map((row: any) => row.id)).toEqual([34]);
        expect(mockGlobalState.claimCompletePreview.claims[0].id).toBe(34);
    });

    /**
     * @effects rapid_toggles_collapse_into_one_preview_request
     */
    it('창 안의 연속 토글은 미리보기를 한 번만 부른다', async () => {
        await openModalWithPreview();
        mockG7Core.api.post.mockClear();
        mockG7Core.api.post.mockResolvedValue(previewFor(false));

        // 헤더 일괄 해제 → 건별 재체크 → 다시 해제. 운영자에게는 자연스러운 조작이다.
        const first = toggleClaimCompleteStockAllHandler(
            { handler: 'x', params: { restore: false } },
            mockContext,
        );
        const second = toggleClaimCompleteStockHandler(
            { handler: 'x', params: { claimId: 31, restore: true } },
            mockContext,
        );
        const third = toggleClaimCompleteStockHandler(
            { handler: 'x', params: { claimId: 31, restore: false } },
            mockContext,
        );

        await Promise.all([first, second, third]);

        const previewCalls = mockG7Core.api.post.mock.calls.filter(
            (call) => call[0] === PREVIEW_URL,
        );

        expect(previewCalls).toHaveLength(1);
    });

    /**
     * @effects closing_the_modal_cancels_pending_preview
     */
    it('모달을 닫으면 대기 중이던 미리보기가 발화하지 않는다', async () => {
        await openModalWithPreview();
        mockG7Core.api.post.mockClear();

        const pending = toggleClaimCompleteStockHandler(
            { handler: 'x', params: { claimId: 31, restore: false } },
            mockContext,
        );

        clearClaimCompleteTimersHandler();
        await pending;
        await new Promise((resolve) => setTimeout(resolve, PREVIEW_DEBOUNCE_WINDOW_MS));

        expect(
            mockG7Core.api.post.mock.calls.filter((call) => call[0] === PREVIEW_URL),
        ).toHaveLength(0);
    });
});

describe('환불 대상이 0 건인 완료 처리 — 고를 수 없는 기본값 (S17 B1)', () => {
    /**
     * 환불 대상이 없는 미리보기 응답을 만듭니다.
     *
     * @returns 미리보기 응답
     */
    function previewWithoutRefundTarget(): any {
        return {
            success: true,
            data: {
                claims: [
                    {
                        id: 31,
                        claim_number: 'RT20260826000031',
                        currency_code: 'KRW',
                        completable_item_ids: [55],
                        completable_item_count: 1,
                        has_stock_restore_target: true,
                        stock_restore_count: 1,
                        stock_discard_count: 0,
                        earn_recovery: false,
                        coupon_restore: false,
                        refund: {
                            applicable: false,
                            already_filed: false,
                            blocked_reason: 'no_refundable_amount',
                            refund_amount: 0,
                            refund_amount_formatted: '0원',
                            refund_points_amount: 0,
                            refund_points_amount_formatted: '0원',
                        },
                    },
                ],
                skipped: [],
                totals: { claim_count: 1, completable_item_count: 1, refund_amount_total: 0 },
            },
        };
    }

    /**
     * 완료 처리 모달을 엽니다.
     *
     * @param createRefundRequest 설정이 정하는 초기 선택값
     * @returns 없음
     */
    async function openModal(createRefundRequest: boolean): Promise<void> {
        await openClaimCompleteModalHandler(
            {
                handler: 'x',
                params: {
                    claimType: 'return',
                    targets: [{ id: 31, claim_number: 'RT20260826000031', item_label: '헤비 후드 XL' }],
                    defaults: { create_refund_request: createRefundRequest, restore_stock: true },
                    execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: 'return', ids: [31] } },
                },
            },
            mockContext,
        );
    }

    /** @effects refund_request_follows_applicable_targets */
    it('환불 대상이 하나도 없으면 설정 기본값이 켜져 있어도 접수하지 않음으로 내려간다', async () => {
        mockG7Core.api.post.mockResolvedValue(previewWithoutRefundTarget());

        await openModal(true);

        expect(mockGlobalState.claimCompleteCreateRefundRequest).toBe(false);
        expect(mockGlobalState.claimCompleteAppliedRefundRequest).toBe(false);
    });

    /** @effects refund_request_follows_applicable_targets */
    it('그 상태로 제출해도 서버에는 접수하지 않음이 실린다 (422 회피)', async () => {
        mockG7Core.api.post.mockResolvedValue(previewWithoutRefundTarget());

        await openModal(true);
        await executeClaimCompletionHandler({ handler: 'x' }, mockContext);

        expect(lastBodyTo(BULK_STATUS_URL).completion.create_refund_request).toBe(false);
    });

    /** @effects refund_request_follows_applicable_targets */
    it('한 건이라도 환불 대상이면 선택을 건드리지 않는다', async () => {
        await openModal(true);

        expect(mockGlobalState.claimCompleteCreateRefundRequest).toBe(true);
    });
});
