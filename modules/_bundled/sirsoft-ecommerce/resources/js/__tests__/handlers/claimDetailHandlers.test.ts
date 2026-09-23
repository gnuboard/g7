/**
 * 관리자 클레임 상세 핸들러 테스트 (S9a · D24·D25·D26)
 *
 * @description
 * 화면에서는 확인할 수 없고 요청 URL·본문에서만 드러나는 축을 고정한다:
 *  - 유형 스코프 경로 세그먼트는 **서버가 준 값**만 쓴다 (`claim_type + 's'` 조립 금지)
 *  - 상품 단위 완료 전이는 곧바로 실행되지 않고 완료 처리 모달을 연다 (D26 진입점 ②)
 *    — 그때 체크한 상품만 `item_ids`/`items` 로 실린다
 *  - 상세 [완료 처리]는 신청서 단위라 `item_ids` 를 싣지 않는다 (D26 진입점 ③)
 *  - 완료 성공 후 다시 읽는 데이터소스는 **여는 쪽**이 정한다 (목록 `claims` / 상세 `claim`)
 *  - 송장은 택배사와 번호를 함께 입력해야 한다 — 한쪽만이면 왕복 전에 화면이 먼저 막는다
 *  - 저장 실패에도 버튼이 되살아난다 (한 번 실패한 뒤로 저장 자체를 못 하는 상태 금지)
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import {
    openClaimDetailCompleteHandler,
    executeClaimItemBulkStatusHandler,
    openClaimItemQuantityConfirmHandler,
    syncClaimItemSelectionHandler,
    updateClaimItemQuantityHandler,
    saveClaimTrackingHandler,
    saveClaimAdminMemoHandler,
    saveClaimAmountsHandler,
    openClaimRefundExecuteHandler,
    executeClaimRefundHandler,
    executeClaimCompletionHandler,
} from '../../handlers/claimHandlers';

const BASE = '/api/modules/sirsoft-ecommerce/admin';

let mockGlobalState: Record<string, any> = {};

/** 완료 미리보기 응답 (교환 1건 · 상품 행 55·56) */
const PREVIEW_RESPONSE = {
    success: true,
    data: {
        claims: [
            {
                id: 31,
                claim_number: 'EX20260827000031',
                currency_code: 'KRW',
                completable_item_ids: [55, 56],
                completable_item_count: 2,
                stock_restore_count: 2,
                stock_discard_count: 0,
                earn_recovery: false,
                coupon_restore: false,
                refund: {
                    applicable: false,
                    already_filed: false,
                    blocked_reason: null,
                    refund_amount: 0,
                    refund_amount_formatted: '0원',
                    refund_points_amount: 0,
                    refund_points_amount_formatted: '0원',
                },
            },
        ],
        skipped: [],
        totals: {
            claim_count: 1,
            completable_item_count: 2,
            refund_amount_total: 0,
            currency_code: 'KRW',
            refund_amount_total_formatted: '0원',
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
    // `info` 까지 둔다 — 핸들러가 `toast?.info?.()` 로 부르므로 목에 없으면 분할 안내가
    // **한 번도 실행되지 않은 채** 테스트가 조용히 통과한다.
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    t: vi.fn((key: string) => key),
    api: {
        post: vi.fn(),
        patch: vi.fn(),
        put: vi.fn(),
    },
    dispatch: vi.fn(),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const mockContext = {} as any;

/** 상세 화면의 신청 상품 두 행 */
const ITEM_ROWS = [
    {
        id: 55,
        product_name: '프리미엄 코튼 티셔츠',
        option_name: '차콜 / M',
        quantity: 3,
        is_splittable: true,
        transition_options: [
            { value: 'approved', label: '교환승인' },
            { value: 'rejected', label: '교환반려' },
        ],
    },
    {
        id: 56,
        product_name: '소프트 터치 니트',
        option_name: '아이보리 / L',
        quantity: 1,
        is_splittable: true,
        transition_options: [
            { value: 'rejected', label: '교환반려' },
            { value: 'collection_ready', label: '교환회수준비중' },
        ],
    },
];

/** 상세 화면이 넘기는 공통 식별 축 (세그먼트는 서버가 준 값) */
const DETAIL_TARGET = {
    pathSegment: 'exchanges',
    claimType: 'exchange',
    claimId: 31,
    claimNumber: 'EX20260827000031',
    requesterName: '김지은',
};

beforeEach(() => {
    mockGlobalState = {};
    vi.clearAllMocks();
    mockG7Core.api.post.mockResolvedValue(PREVIEW_RESPONSE);
    mockG7Core.api.patch.mockResolvedValue({ success: true, data: { succeeded: [55], skipped: [] } });
    mockG7Core.api.put.mockResolvedValue({ success: true, data: {} });
    (window as any).G7Core = mockG7Core;
});

/**
 * 특정 메서드의 마지막 호출 [url, body] 를 돌려준다.
 *
 * @param method HTTP 메서드
 * @returns 마지막 호출 인수
 */
function lastCall(method: 'post' | 'patch' | 'put'): [string, any] {
    const calls = (mockG7Core.api[method] as any).mock.calls;

    expect(calls.length).toBeGreaterThan(0);

    return calls[calls.length - 1];
}

describe('상품 단위 일괄 전이 (D25)', () => {
    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_item_bulk_status_uses_server_path_segment
     */
    it('경로 세그먼트는 서버가 준 값을 그대로 쓴다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'collecting', rows: ITEM_ROWS },
            },
            mockContext,
        );

        const [url, body] = lastCall('patch');
        expect(url).toBe(`${BASE}/exchanges/31/options/bulk-status`);
        expect(body).toEqual({ items: [{ id: 55 }], status: 'collecting' });
    });

    /**
     * 세그먼트가 비면 URL 이 `admin//31/...` 로 깨진 채 나간다 — 요청 전에 멈춘다.
     *
     * @effects claim_item_bulk_status_requires_identity
     */
    it('세그먼트가 없으면 요청을 보내지 않는다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, pathSegment: '', itemIds: [55], status: 'collecting', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
    });

    /**
     * @effects claim_item_bulk_status_guards_empty_selection
     */
    it('선택이 없으면 안내만 하고 요청하지 않는다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [], status: 'collecting', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /**
     * @effects claim_item_bulk_status_guards_missing_status
     */
    it('상태를 고르지 않으면 요청하지 않는다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [55], status: '', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /**
     * 환불에도 상품 단위 전이 축이 열렸다 (§4) — 예전에는 화면이 이 유형을 거절했다.
     *
     * @scenario claim_type=refund, claim_stage=in_progress
     * @effects claim_item_bulk_status_accepts_refund
     */
    it('환불 유형도 상품 단위 전이를 보낸다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: {
                    pathSegment: 'refunds',
                    claimType: 'refund',
                    claimId: 44,
                    itemIds: [70],
                    status: 'approved',
                    rows: [],
                },
            },
            mockContext,
        );

        const [url, body] = lastCall('patch');
        expect(url).toBe(`${BASE}/refunds/44/options/bulk-status`);
        expect(body).toEqual({ items: [{ id: 70 }], status: 'approved' });
    });

    /**
     * 취소신청서만 이 축이 없다 — 생성이 곧 확정이라 옮길 상태가 없다.
     *
     * @scenario claim_type=cancel, claim_stage=final
     * @effects claim_item_bulk_status_rejects_cancel
     */
    it('취소 유형에서는 상품 단위 전이를 시도하지 않는다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: {
                    pathSegment: 'cancels',
                    claimType: 'cancel',
                    claimId: 44,
                    itemIds: [70],
                    status: 'approved',
                    rows: [],
                },
            },
            mockContext,
        );

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /**
     * 수량을 실으면 그 행만 갈린다 — 싣지 않은 행은 전량이다.
     *
     * @effects claim_item_bulk_status_sends_per_row_quantities
     */
    it('행별 수량이 본문에 실린다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: {
                    ...DETAIL_TARGET,
                    itemIds: [55, 56],
                    itemQuantities: { 55: 2 },
                    status: 'rejected',
                    rows: ITEM_ROWS,
                },
            },
            mockContext,
        );

        const [, body] = lastCall('patch');
        expect(body).toEqual({ items: [{ id: 55, quantity: 2 }, { id: 56 }], status: 'rejected' });
    });

    /**
     * 분할이 일어나면 **남은 수량을 말로 알린다.**
     *
     * 표가 한 줄 늘어난 것을 운영자가 스스로 해석하게 두면 "내가 뭘 잘못 눌렀나" 가 된다.
     * 서버는 `split` 로 몇 개를 처리하고 몇 개를 남겼는지 답하고, 화면은 그 숫자를 그대로 읽는다.
     *
     * @effects claim_item_split_reports_remainder
     */
    it('분할이 일어나면 남은 수량을 안내한다', async () => {
        mockG7Core.api.patch.mockResolvedValueOnce({
            success: true,
            data: {
                succeeded: [55],
                skipped: [],
                split: [{ id: 55, quantity: 2, remainder_id: 91, remainder_quantity: 1 }],
            },
        });

        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: {
                    ...DETAIL_TARGET,
                    itemIds: [55],
                    itemQuantities: { 55: 2 },
                    status: 'approved',
                    rows: ITEM_ROWS,
                },
            },
            mockContext,
        );

        const notice = mockG7Core.toast.info.mock.calls.at(-1)?.[0] ?? '';
        expect(notice).toContain('2');
        expect(notice).toContain('1');
    });

    /**
     * 네트워크가 끊겼을 때 운영자가 읽는 것은 **번역된 안내**여야 한다.
     *
     * 실측(§9 T4)에서 전이 요청만 끊었더니 토스트에 영문 원문 `Network Error` 가 그대로 떴다.
     * 그 문자열은 axios 가 전송 실패에 붙이는 내부 값이라 번역도 사이트 문구도 아니다 —
     * 코어가 로그인 경로에서 같은 결함을 이미 한 번 고쳤고(7.0.11), 같은 부류가 클레임 전이
     * 경로에 남아 있었다.
     *
     * @scenario claim_type=exchange, network=offline
     * @effects claim_item_transition_network_error_shows_localized_message
     */
    it('네트워크 오류는 영문 원문 대신 번역된 안내로 뜬다', async () => {
        // axios 전송 실패의 모양 그대로 — `response` 가 없고 `message` 가 내부 문자열이다.
        const transportError: any = new Error('Network Error');
        transportError.isAxiosError = true;
        transportError.code = 'ERR_NETWORK';
        mockG7Core.api.patch.mockRejectedValueOnce(transportError);

        await expect(
            executeClaimItemBulkStatusHandler(
                {
                    handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                    params: { ...DETAIL_TARGET, itemIds: [55], status: 'approved', rows: ITEM_ROWS },
                },
                mockContext,
            ),
        ).rejects.toBeTruthy();

        expect(mockG7Core.toast.error).toHaveBeenCalled();
        const shown = mockG7Core.toast.error.mock.calls.at(-1)?.[0] ?? '';
        expect(shown).not.toBe('Network Error');
        expect(shown).toContain('신청 상품 상태 변경에 실패했습니다');
    });

    /**
     * 완료는 곧바로 실행하지 않는다 (D26) — 재고 복구·환불신청서 접수를 운영자가 골라야 한다.
     *
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_item_completion_opens_shared_modal
     */
    it('완료 전이는 실행 대신 완료 처리 모달을 연다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'completed', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_complete');
        expect(mockGlobalState.claimCompleteContext.execute).toMatchObject({
            url: `${BASE}/exchanges/31/options/bulk-status`,
            method: 'PATCH',
            body: { items: [{ id: 55 }] },
            refetch: ['claim', 'claim_logs'],
        });
    });

    /**
     * 체크한 상품만 이번 라운드 대상이다 — `item_ids` 를 빼면 서버가 완료 가능 상품 전체를 본다.
     *
     * @effects claim_item_completion_scopes_to_checked_items
     */
    it('완료 모달 대상에 체크한 상품 행 ID 가 실린다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [56], status: 'completed', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockGlobalState.claimCompleteContext.targets).toEqual([
            expect.objectContaining({ id: 31, item_ids: [56], item_label: '소프트 터치 니트' }),
        ]);
    });
});

describe('상세 [완료 처리] 진입점 (D26 ③)', () => {
    /**
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_detail_completion_opens_shared_modal
     */
    it('신청서 단위라 item_ids 를 싣지 않는다', async () => {
        await openClaimDetailCompleteHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimDetailComplete',
                params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: { restore_stock: false, create_refund_request: false } },
            },
            mockContext,
        );

        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_complete');

        const target = mockGlobalState.claimCompleteContext.targets[0];
        expect(target.item_ids).toBeUndefined();
        expect(mockGlobalState.claimCompleteContext.execute).toMatchObject({
            url: `${BASE}/exchanges/31/status`,
            method: 'PATCH',
            refetch: ['claim', 'claim_logs'],
        });
    });

    /**
     * 초기 선택값은 상세 응답이 나른다 — 설정 응답에서 읽으면 그 게이트가 화면보다 좁아
     * 클레임 권한만 가진 운영자에게만 계약 기본값으로 되돌아간다.
     *
     * @effects claim_detail_completion_seeds_settings_defaults
     */
    it('상점이 꺼 둔 기본값이 모달 초기 선택으로 도달한다', async () => {
        await openClaimDetailCompleteHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimDetailComplete',
                params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: { restore_stock: false, create_refund_request: false } },
            },
            mockContext,
        );

        expect(mockGlobalState.claimCompleteRestore).toEqual({ '31': false });
        expect(mockGlobalState.claimCompleteCreateRefundRequest).toBe(false);
    });
});

describe('완료 실행의 사후 처리 — 진입점마다 다른 화면', () => {
    /**
     * 여는 쪽이 재조회 대상을 정한다 — 모달이 화면 이름을 알고 있으면 나머지 진입점의
     * 화면이 옛 값을 그린 채 남는다.
     *
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_completion_refetches_opener_datasource
     */
    it('상세에서 연 완료 처리는 상세 데이터소스를 다시 읽는다', async () => {
        await openClaimDetailCompleteHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimDetailComplete',
                params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: {} },
            },
            mockContext,
        );

        await executeClaimCompletionHandler({ handler: 'sirsoft-ecommerce.executeClaimCompletion' }, mockContext);

        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'refetchDataSource',
            params: { dataSourceId: 'claim' },
        });
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ selectedClaimItems: [], itemBulkStatus: '' });
    });

    /**
     * 상세 진입점의 실행 본문은 신청서 단위 전이다 — `ids` 를 싣지 않는다.
     *
     * @effects claim_detail_completion_patches_single_claim
     */
    it('상세 진입점의 실행은 단건 전이 URL 로 나간다', async () => {
        await openClaimDetailCompleteHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimDetailComplete',
                params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: {} },
            },
            mockContext,
        );

        await executeClaimCompletionHandler({ handler: 'sirsoft-ecommerce.executeClaimCompletion' }, mockContext);

        const [url, body] = lastCall('patch');
        expect(url).toBe(`${BASE}/exchanges/31/status`);
        expect(body.status).toBe('completed');
        expect(body.ids).toBeUndefined();
        expect(body.completion.restore_stock_by_item).toEqual({ '55': true, '56': true });
    });
});

describe('상세 처리 후 재조회 — 신청서와 활동 로그 둘 다', () => {
    /**
     * 상태 전이·송장·메모는 신청서만 바꾸는 것이 아니라 **활동 로그도 남긴다**. 신청서만
     * 다시 읽으면 헤더·진행단계는 갱신되는데 활동 로그 섹션만 옛 목록으로 남아, 운영자는
     * 방금 자기가 한 처리가 이력에 없는 것으로 본다 — 오류도 경고도 남지 않는다(실측).
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_detail_refetches_claim_and_logs
     */
    it.each([
        ['상품 단위 전이', async () => executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'collecting', rows: ITEM_ROWS },
            },
            mockContext,
        )],
        ['송장 저장', async () => saveClaimTrackingHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimTracking',
                params: { pathSegment: 'exchanges', claimId: 31, kind: 'collection', carrierId: 3, trackingNumber: '1' },
            },
            mockContext,
        )],
        ['메모 저장', async () => saveClaimAdminMemoHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimAdminMemo',
                params: { pathSegment: 'exchanges', claimId: 31, adminMemo: 'x' },
            },
            mockContext,
        )],
    ])('%s 후 신청서와 활동 로그를 함께 다시 읽는다', async (_label, run) => {
        await run();

        const refetched = mockG7Core.dispatch.mock.calls
            .filter(([action]: any[]) => action?.handler === 'refetchDataSource')
            .map(([action]: any[]) => action.params.dataSourceId);

        expect(refetched).toEqual(['claim', 'claim_logs']);
    });

    /**
     * 완료 처리도 같은 축이다 — 상세에서 열면 두 소스를 다시 읽는다.
     *
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_detail_refetches_claim_and_logs
     */
    it('상세에서 연 완료 처리도 두 소스를 다시 읽는다', async () => {
        await openClaimDetailCompleteHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimDetailComplete',
                params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: {} },
            },
            mockContext,
        );

        await executeClaimCompletionHandler({ handler: 'sirsoft-ecommerce.executeClaimCompletion' }, mockContext);

        const refetched = mockG7Core.dispatch.mock.calls
            .filter(([action]: any[]) => action?.handler === 'refetchDataSource')
            .map(([action]: any[]) => action.params.dataSourceId);

        expect(refetched).toEqual(['claim', 'claim_logs']);
    });

    /**
     * 목록 진입점은 그대로 목록만 다시 읽는다 — 상세 축을 기본값으로 밀어 넣으면 목록
     * 화면에서 있지도 않은 데이터소스를 부르게 된다.
     *
     * @scenario claim_type=return, claim_stage=final
     * @effects claim_completion_refetches_opener_datasource
     */
    it('목록에서 연 완료 처리는 목록만 다시 읽는다', async () => {
        mockGlobalState.claimCompleteContext = {
            claimType: 'return',
            targets: [{ id: 31 }],
            execute: { url: '/api/modules/sirsoft-ecommerce/admin/claims/bulk-status', method: 'POST', body: { type: 'return', ids: [31] } },
        };
        mockGlobalState.claimCompleteRows = [{ id: 31 }];
        mockGlobalState.claimCompletePreview = { claims: [{ id: 31, completable_item_ids: [55] }] };
        mockGlobalState.claimCompleteRestore = { '31': true };
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, data: { succeeded: [31], skipped: [] } });

        await executeClaimCompletionHandler({ handler: 'sirsoft-ecommerce.executeClaimCompletion' }, mockContext);

        const refetched = mockG7Core.dispatch.mock.calls
            .filter(([action]: any[]) => action?.handler === 'refetchDataSource')
            .map(([action]: any[]) => action.params.dataSourceId);

        expect(refetched).toEqual(['claims']);
    });
});

describe('회수·재발송 송장 저장', () => {
    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_collection_tracking_saved
     */
    it('회수 송장은 회수 엔드포인트로 나간다', async () => {
        await saveClaimTrackingHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimTracking',
                params: { pathSegment: 'returns', claimId: 12, kind: 'collection', carrierId: '3', trackingNumber: '1234567890' },
            },
            mockContext,
        );

        const [url, body] = lastCall('put');
        expect(url).toBe(`${BASE}/returns/12/collection-tracking`);
        expect(body).toEqual({ carrier_id: 3, tracking_number: '1234567890' });
    });

    /**
     * 재발송은 교환 전용 라우트다 — 유형 세그먼트가 아니라 고정 경로를 쓴다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_redelivery_tracking_saved
     */
    it('재발송 송장은 재발송 엔드포인트로 나간다', async () => {
        await saveClaimTrackingHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimTracking',
                params: { pathSegment: 'exchanges', claimId: 31, kind: 'redelivery', carrierId: 5, trackingNumber: '999' },
            },
            mockContext,
        );

        expect(lastCall('put')[0]).toBe(`${BASE}/exchanges/31/redelivery-tracking`);
    });

    /**
     * 한쪽만 보내면 서버가 `claim_tracking_incomplete` 로 거절한다 — 왕복 전에 막는다.
     *
     * @effects claim_tracking_requires_both_fields
     */
    it.each([
        ['택배사만', { carrierId: '3', trackingNumber: '' }],
        ['송장번호만', { carrierId: '', trackingNumber: '123' }],
    ])('%s 입력하면 요청하지 않는다', async (_label, partial) => {
        await saveClaimTrackingHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimTracking',
                params: { pathSegment: 'returns', claimId: 12, kind: 'collection', ...partial },
            },
            mockContext,
        );

        expect(mockG7Core.api.put).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /**
     * 둘 다 비우는 것은 등록 해제다 — 서버가 받는 조합이라 막지 않는다.
     *
     * @effects claim_tracking_allows_clearing_both
     */
    it('둘 다 비우면 해제 요청으로 나간다', async () => {
        await saveClaimTrackingHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimTracking',
                params: { pathSegment: 'returns', claimId: 12, kind: 'collection', carrierId: '', trackingNumber: '' },
            },
            mockContext,
        );

        expect(lastCall('put')[1]).toEqual({ carrier_id: null, tracking_number: null });
    });

    /**
     * 실패해도 버튼은 되살아나야 한다 — 그러지 않으면 한 번 실패한 뒤로 저장 자체를 못 한다.
     *
     * @effects claim_tracking_restores_button_on_failure
     */
    it('저장이 실패해도 진행 플래그가 내려간다', async () => {
        mockG7Core.api.put.mockRejectedValueOnce(new Error('boom'));

        await expect(
            saveClaimTrackingHandler(
                {
                    handler: 'sirsoft-ecommerce.saveClaimTracking',
                    params: { pathSegment: 'returns', claimId: 12, kind: 'collection', carrierId: '3', trackingNumber: '1' },
                },
                mockContext,
            ),
        ).rejects.toThrow();

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isSavingCollection: false });
    });
});

describe('관리자 메모 저장', () => {
    /**
     * 메모는 금액 수정 엔드포인트(D18)가 함께 받는 축이다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     * @effects claim_admin_memo_saved
     */
    it('메모는 금액 수정 엔드포인트로 나간다', async () => {
        await saveClaimAdminMemoHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimAdminMemo',
                params: { pathSegment: 'returns', claimId: 12, adminMemo: '회수 후 재검수 필요' },
            },
            mockContext,
        );

        const [url, body] = lastCall('put');
        expect(url).toBe(`${BASE}/returns/12/amounts`);
        expect(body).toEqual({ admin_memo: '회수 후 재검수 필요' });
    });

    /**
     * @effects claim_admin_memo_restores_button_on_failure
     */
    it('저장이 실패해도 진행 플래그가 내려간다', async () => {
        mockG7Core.api.put.mockRejectedValueOnce(new Error('boom'));

        await expect(
            saveClaimAdminMemoHandler(
                {
                    handler: 'sirsoft-ecommerce.saveClaimAdminMemo',
                    params: { pathSegment: 'returns', claimId: 12, adminMemo: 'x' },
                },
                mockContext,
            ),
        ).rejects.toThrow();

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isSavingMemo: false });
    });
});

describe('금액 저장 (D18)', () => {
    /**
     * 유형마다 받는 축이 다르고 다른 유형의 축을 보내면 서버가 422 로 명시 거절한다.
     *
     * @scenario claim_type=exchange, cancellable=absent
     * @effects claim_amounts_send_only_the_axes_of_this_type
     */
    it('교환은 배송비·청구방식만 싣는다', async () => {
        await saveClaimAmountsHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimAmounts',
                params: {
                    ...DETAIL_TARGET,
                    claimShippingFee: '6000',
                    chargeMethod: 'pay_on_request',
                    // 환불 축을 함께 넘겨도 교환 요청에는 실리지 않아야 한다.
                    refundAmount: '24000',
                    refundMethod: 'pg',
                },
            },
            mockContext,
        );

        const [url, body] = lastCall('put');
        expect(url).toBe(`${BASE}/exchanges/31/amounts`);
        expect(body).toEqual({ claim_shipping_fee: 6000, charge_method: 'pay_on_request' });
    });

    /**
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_amounts_send_only_the_axes_of_this_type
     */
    it('환불신청서는 환불액·마일리지·수단만 싣는다', async () => {
        await saveClaimAmountsHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimAmounts',
                params: {
                    pathSegment: 'refunds',
                    claimType: 'refund',
                    claimId: 12,
                    refundAmount: '24000',
                    refundPointsAmount: '0',
                    refundMethod: 'pg',
                    claimShippingFee: '6000',
                },
            },
            mockContext,
        );

        const [url, body] = lastCall('put');
        expect(url).toBe(`${BASE}/refunds/12/amounts`);
        expect(body).toEqual({ refund_amount: 24000, refund_points_amount: 0, refund_method: 'pg' });
    });

    /**
     * 빈 칸은 "보내지 않음" 이고 `0` 은 "0원으로 고침" 이다 — 둘을 뭉뚱그리면 운영자가
     * 비워 둔 칸이 0 저장으로 나가 저장된 금액이 조용히 지워진다.
     *
     * @effects claim_amounts_blank_input_is_not_zero
     */
    it('비어 있는 칸은 본문에 실리지 않는다', async () => {
        await saveClaimAmountsHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimAmounts',
                params: { ...DETAIL_TARGET, claimShippingFee: '', chargeMethod: 'deduct_from_refund' },
            },
            mockContext,
        );

        const [, body] = lastCall('put');
        expect(body).toEqual({ charge_method: 'deduct_from_refund' });
    });

    /**
     * 보낼 것이 없으면 왕복을 돌지 않는다 — 빈 본문은 서버가 "아무것도 갱신하지 않음" 으로
     * 처리해 성공 토스트만 뜨고 아무 일도 일어나지 않는다.
     *
     * @effects claim_amounts_no_request_when_nothing_changed
     */
    it('보낼 값이 하나도 없으면 요청하지 않는다', async () => {
        await saveClaimAmountsHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimAmounts',
                params: { ...DETAIL_TARGET, claimShippingFee: '', chargeMethod: '' },
            },
            mockContext,
        );

        expect(mockG7Core.api.put).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /**
     * 금액 수정은 총 청구액·환불 예정액·취소 가능 금액을 함께 바꾸고 활동 로그도 남긴다 —
     * 상세만 다시 읽으면 활동 로그 섹션이 옛 목록으로 남는다.
     *
     * @effects claim_amounts_refetch_detail_and_logs
     */
    it('저장 후 신청서와 활동 로그를 함께 다시 읽는다', async () => {
        await saveClaimAmountsHandler(
            {
                handler: 'sirsoft-ecommerce.saveClaimAmounts',
                params: { ...DETAIL_TARGET, claimShippingFee: '6000' },
            },
            mockContext,
        );

        const refetched = mockG7Core.dispatch.mock.calls
            .filter(([action]: any[]) => action.handler === 'refetchDataSource')
            .map(([action]: any[]) => action.params.dataSourceId);

        expect(refetched).toEqual(['claim', 'claim_logs']);
    });

    /**
     * @effects claim_amounts_restore_button_on_failure
     */
    it('저장이 실패해도 진행 플래그가 내려간다', async () => {
        mockG7Core.api.put.mockRejectedValueOnce(new Error('boom'));

        await expect(
            saveClaimAmountsHandler(
                {
                    handler: 'sirsoft-ecommerce.saveClaimAmounts',
                    params: { ...DETAIL_TARGET, claimShippingFee: '6000' },
                },
                mockContext,
            ),
        ).rejects.toThrow();

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isSavingAmounts: false });
    });
});

describe('환불 실행 (D18·D19)', () => {
    /** 상세 화면의 환불 실행 진입 파라미터 */
    const REFUND_TARGET = {
        pathSegment: 'refunds',
        claimId: 12,
        claimNumber: 'RF20260827000012',
        currencyCode: 'KRW',
        refundAmount: '24000',
        refundPointsAmount: '1000',
        refundMethod: 'pg',
        refundMethods: [
            { value: 'pg', label: 'PG사 환불' },
            { value: 'bank', label: '무통장 환불' },
        ],
    };

    /**
     * 실행 중에는 페이지의 [환불하기] 가 잠긴다.
     *
     * 결제 partial 의 버튼이 `_local.isExecutingRefund` 로 잠금을 선언해 두었으므로 그 값을
     * 세우는 쪽이 있어야 선언이 사실이 된다 — 형제 `saveClaimAmounts` 와 같은 형태(시작 시
     * true, finally 에서 false)다. 세우는 곳이 없으면 화면은 잠금을 약속해 놓고 실제로는
     * 잠그지 않으며, 그 사실이 어느 테스트에도 걸리지 않는다.
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_locks_page_button_while_running
     */
    it('실행 중 페이지 버튼 잠금 플래그를 세우고 끝나면 내린다', async () => {
        mockGlobalState.claimRefundExecuteContext = {
            claimId: 12,
            pathSegment: 'refunds',
            refundAmount: 24000,
            refundPointsAmount: 1000,
            refundMethod: 'pg',
        };

        await executeClaimRefundHandler(
            { handler: 'sirsoft-ecommerce.executeClaimRefund', params: {} },
            mockContext,
        );

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isExecutingRefund: true });
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isExecutingRefund: false });
    });

    /**
     * @effects claim_refund_execute_locks_page_button_while_running
     */
    it('실행이 실패해도 페이지 버튼 잠금이 풀린다', async () => {
        mockGlobalState.claimRefundExecuteContext = {
            claimId: 12,
            pathSegment: 'refunds',
            refundAmount: 24000,
            refundPointsAmount: 0,
            refundMethod: 'pg',
        };
        mockG7Core.api.post.mockRejectedValueOnce(new Error('boom'));

        await expect(
            executeClaimRefundHandler(
                { handler: 'sirsoft-ecommerce.executeClaimRefund', params: {} },
                mockContext,
            ),
        ).rejects.toThrow();

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isExecutingRefund: false });
    });

    /**
     * 모달은 부모 화면과 다른 컨텍스트라 `_local` 을 해석하지 못한다 — 여는 핸들러가 지금
     * 입력된 값을 전역 문맥으로 넘겨야 모달이 예고하는 값과 실행 본문이 같아진다.
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_seeds_modal_context
     */
    it('확인 모달에 지금 입력된 값을 그대로 넘긴다', async () => {
        await openClaimRefundExecuteHandler(
            { handler: 'sirsoft-ecommerce.openClaimRefundExecute', params: REFUND_TARGET },
            mockContext,
        );

        expect(mockGlobalState.claimRefundExecuteContext).toEqual({
            claimId: 12,
            claimNumber: 'RF20260827000012',
            currencyCode: 'KRW',
            refundAmount: 24000,
            refundPointsAmount: 1000,
            // 표시용 합계 — 핸들러가 부동소수 오차를 걷어 넘긴다(P7 #14).
            refundTotal: 25000,
            refundMethod: 'pg',
            refundMethodLabel: 'PG사 환불',
            refundBankName: '',
            refundBankAccount: '',
            refundBankHolder: '',
            // 계좌가 필요한지는 서버가 답한다 — 파라미터가 없으면 「필요 없음」이다.
            refundAccountRequired: false,
        });
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_refund_execute');
    });

    /**
     * 계좌 이체 환불은 운영자가 손으로 보낸다 — 어느 계좌로 보낼지 이 창에 없으면 다른 화면을
     * 뒤져야 하고, 못 찾으면 구매자에게 다시 묻게 된다. 은행명은 서버가 해석해 실어 준 값을
     * 그대로 쓴다(화면이 은행 코드표를 읽으면 그 설정 데이터소스만 403 이 되는 운영자가 생긴다).
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_seeds_modal_context
     */
    it('계좌 이체 환불이면 보낼 계좌를 모달 문맥에 함께 넘긴다', async () => {
        await openClaimRefundExecuteHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimRefundExecute',
                params: {
                    ...REFUND_TARGET,
                    refundMethod: 'bank',
                    refundBankName: '국민은행',
                    refundBankAccount: '110234567890',
                    refundBankHolder: '홍길동',
                },
            },
            mockContext,
        );

        expect(mockGlobalState.claimRefundExecuteContext).toMatchObject({
            refundMethodLabel: '무통장 환불',
            refundBankName: '국민은행',
            refundBankAccount: '110234567890',
            refundBankHolder: '홍길동',
        });
    });

    /**
     * 창을 열 때마다 계좌 칸과 동의를 **비운다**. (T2-D6)
     *
     * 전역 상태라 앞 신청서에서 적은 값이 그대로 남는다 — 비우지 않으면 운영자가 손대지 않고
     * [환불하기]를 눌렀을 때 **엉뚱한 계좌로 송금 기록이 남고**, 켜 둔 동의가 다음 건에서
     * 그대로 살아 있어 계좌를 적지 않은 건이 조용히 직권 완료된다.
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_modal_clears_the_bank_inputs_on_open
     */
    it('창을 열 때 앞 건의 계좌 입력과 동의를 비운다', async () => {
        mockGlobalState.claimRefundExecuteBankCode = '004';
        mockGlobalState.claimRefundExecuteBankAccount = '110234567890';
        mockGlobalState.claimRefundExecuteBankHolder = '홍길동';
        mockGlobalState.claimRefundExecuteForce = true;

        await openClaimRefundExecuteHandler(
            { handler: 'sirsoft-ecommerce.openClaimRefundExecute', params: REFUND_TARGET },
            mockContext,
        );

        expect(mockGlobalState.claimRefundExecuteBankCode).toBe('');
        expect(mockGlobalState.claimRefundExecuteBankAccount).toBe('');
        expect(mockGlobalState.claimRefundExecuteBankHolder).toBe('');
        expect(mockGlobalState.claimRefundExecuteForce).toBe(false);
    });

    /**
     * 서버가 「계좌가 필요하다」고 답하면 그 사실을 모달 문맥에 싣는다. (T2-D6)
     *
     * 화면이 결제수단 코드로 재현하면 확장 결제수단에서 갈린다 — 판정은 서버 것 하나다.
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_seeds_modal_context
     */
    it('서버가 계좌를 요구하면 그 사실을 모달 문맥에 싣는다', async () => {
        await openClaimRefundExecuteHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimRefundExecute',
                params: { ...REFUND_TARGET, refundMethod: 'bank', refundAccountRequired: true },
            },
            mockContext,
        );

        expect(mockGlobalState.claimRefundExecuteContext).toMatchObject({ refundAccountRequired: true });
    });

    /**
     * 창에서 적은 계좌가 실행 본문에 실린다. (T2-D6)
     *
     * 따로 저장 왕복을 시키면 「계좌는 저장됐는데 실행은 실패한」 반쪽 상태가 생긴다 —
     * 한 번의 실행 본문으로 보내 서버가 같은 트랜잭션에 넣는다.
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_body_carries_the_typed_bank_account
     */
    it('창에서 적은 계좌를 실행 본문에 싣는다', async () => {
        mockGlobalState.claimRefundExecuteContext = {
            claimId: 12,
            refundAmount: 10000,
            refundPointsAmount: 0,
            refundMethod: 'bank',
            refundAccountRequired: true,
        };
        mockGlobalState.claimRefundExecuteBankCode = '004';
        mockGlobalState.claimRefundExecuteBankAccount = '110234567890';
        mockGlobalState.claimRefundExecuteBankHolder = '홍길동';

        await executeClaimRefundHandler(
            { handler: 'sirsoft-ecommerce.executeClaimRefund', params: {} },
            mockContext,
        );

        const [, body] = mockG7Core.api.post.mock.calls.at(-1) ?? [];
        expect(body).toMatchObject({
            refund_bank: { bank_code: '004', account_number: '110234567890', holder: '홍길동' },
        });
        expect(body).not.toHaveProperty('force_without_refund_bank');
    });

    /**
     * 동의를 켜면 계좌 대신 **동의만** 실린다. (T2-D6 · 운영 결정 2026-09-23)
     *
     * 둘을 함께 보내면 무엇이 실행됐는지 기록이 두 가지로 읽힌다 — 계좌를 적어 송금한 건인지
     * 계좌 없이 장부만 닫은 건인지 뒤에 가려낼 수 없다.
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_body_carries_the_force_consent
     */
    it('동의를 켜면 계좌 대신 동의만 싣는다', async () => {
        mockGlobalState.claimRefundExecuteContext = {
            claimId: 12,
            refundAmount: 10000,
            refundPointsAmount: 0,
            refundMethod: 'bank',
            refundAccountRequired: true,
        };
        mockGlobalState.claimRefundExecuteBankCode = '004';
        mockGlobalState.claimRefundExecuteBankAccount = '110234567890';
        mockGlobalState.claimRefundExecuteBankHolder = '홍길동';
        mockGlobalState.claimRefundExecuteForce = true;

        await executeClaimRefundHandler(
            { handler: 'sirsoft-ecommerce.executeClaimRefund', params: {} },
            mockContext,
        );

        const [, body] = mockG7Core.api.post.mock.calls.at(-1) ?? [];
        expect(body).toMatchObject({ force_without_refund_bank: true });
        expect(body).not.toHaveProperty('refund_bank');
    });

    /**
     * 환불은 되돌릴 수 없는 처리다 — 식별 축이 없으면 모달을 열지 않는다.
     *
     * @effects claim_refund_execute_requires_identity
     */
    it('환불신청서 ID 가 없으면 모달을 열지 않는다', async () => {
        await openClaimRefundExecuteHandler(
            { handler: 'sirsoft-ecommerce.openClaimRefundExecute', params: { ...REFUND_TARGET, claimId: null } },
            mockContext,
        );

        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
    });

    /**
     * 화면에 입력된 값으로 실행하는 것이 이 엔드포인트의 계약이다 (저장 없이 실행 가능) —
     * 본문이 저장값으로 나가면 운영자가 방금 고친 금액이 조용히 무시된다.
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_body_carries_edited_amounts
     */
    it('실행 본문이 모달이 예고한 값 그대로 나간다', async () => {
        mockGlobalState.claimRefundExecuteContext = {
            claimId: 12,
            claimNumber: 'RF20260827000012',
            currencyCode: 'KRW',
            refundAmount: 24000,
            refundPointsAmount: 1000,
            refundMethod: 'pg',
            refundMethodLabel: 'PG사 환불',
        };
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, data: { claim: {} } });

        await executeClaimRefundHandler({ handler: 'sirsoft-ecommerce.executeClaimRefund' }, mockContext);

        const [url, body] = lastCall('post');
        // 실행은 유형 스코프가 아니라 환불신청서 ID 축이다.
        expect(url).toBe(`${BASE}/refunds/12/execute`);
        expect(body).toEqual({ refund_amount: 24000, refund_points_amount: 1000, refund_method: 'pg' });
    });

    /**
     * 재실행(더블클릭)은 충돌이지 입력 오류가 아니다 — 확정된 상태를 보여 주는 것이 조치다.
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_conflict_refetches_detail
     */
    it('409 는 경고와 함께 상세를 다시 읽는다', async () => {
        mockGlobalState.claimRefundExecuteContext = {
            claimId: 12,
            claimNumber: 'RF20260827000012',
            currencyCode: 'KRW',
            refundAmount: 24000,
            refundPointsAmount: 0,
            refundMethod: 'pg',
            refundMethodLabel: 'PG사 환불',
        };
        mockG7Core.api.post.mockRejectedValueOnce({
            response: { status: 409, data: { message: '이미 환불이 실행되었습니다.' } },
        });

        await expect(
            executeClaimRefundHandler({ handler: 'sirsoft-ecommerce.executeClaimRefund' }, mockContext),
        ).rejects.toBeTruthy();

        expect(mockG7Core.toast.warning).toHaveBeenCalledWith('이미 환불이 실행되었습니다.');
        expect(mockG7Core.toast.error).not.toHaveBeenCalled();

        const refetched = mockG7Core.dispatch.mock.calls
            .filter(([action]: any[]) => action.handler === 'refetchDataSource')
            .map(([action]: any[]) => action.params.dataSourceId);

        expect(refetched).toEqual(['claim', 'claim_logs']);
    });

    /**
     * 상한 초과는 서버가 실행 직전에 다시 계산해 막는다 — 사유는 서버 문구를 그대로 전한다
     * (다국어 키는 유한해서 PG 가 돌려준 사유를 담지 못한다).
     *
     * @scenario claim_type=refund, cancellable=present
     * @effects claim_refund_execute_surfaces_server_reason
     */
    it('422 는 서버 사유를 그대로 알린다', async () => {
        mockGlobalState.claimRefundExecuteContext = {
            claimId: 12,
            claimNumber: 'RF20260827000012',
            currencyCode: 'KRW',
            refundAmount: 99000,
            refundPointsAmount: 0,
            refundMethod: 'pg',
            refundMethodLabel: 'PG사 환불',
        };
        mockG7Core.api.post.mockRejectedValueOnce({
            response: { status: 422, data: { message: '취소 가능 금액을 초과했습니다.' } },
        });

        await expect(
            executeClaimRefundHandler({ handler: 'sirsoft-ecommerce.executeClaimRefund' }, mockContext),
        ).rejects.toBeTruthy();

        expect(mockG7Core.toast.error).toHaveBeenCalledWith('취소 가능 금액을 초과했습니다.');
    });

    /**
     * 실행 성공은 신청서와 활동 로그를 함께 바꾼다 — 하나만 갱신하면 나머지가 옛 값으로 남고
     * 오류가 없어 운영자는 그것을 사실로 읽는다.
     *
     * @effects claim_refund_execute_refetches_detail_and_logs
     */
    it('실행 후 신청서와 활동 로그를 함께 다시 읽고 문맥을 비운다', async () => {
        mockGlobalState.claimRefundExecuteContext = {
            claimId: 12,
            claimNumber: 'RF20260827000012',
            currencyCode: 'KRW',
            refundAmount: 24000,
            refundPointsAmount: 0,
            refundMethod: 'pg',
            refundMethodLabel: 'PG사 환불',
        };
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, data: { claim: {} } });

        await executeClaimRefundHandler({ handler: 'sirsoft-ecommerce.executeClaimRefund' }, mockContext);

        const refetched = mockG7Core.dispatch.mock.calls
            .filter(([action]: any[]) => action.handler === 'refetchDataSource')
            .map(([action]: any[]) => action.params.dataSourceId);

        expect(refetched).toEqual(['claim', 'claim_logs']);
        expect(mockGlobalState.claimRefundExecuteContext).toBeNull();
    });

    /**
     * 문맥 없이 확인 버튼이 눌리는 경로(모달 재진입 등)에서 요청을 보내지 않는다 — 되돌릴
     * 수 없는 처리라 "무엇을 실행하는지" 가 확정되지 않으면 실행하지 않는다.
     *
     * @effects claim_refund_execute_requires_seeded_context
     */
    it('문맥이 없으면 요청하지 않는다', async () => {
        await executeClaimRefundHandler({ handler: 'sirsoft-ecommerce.executeClaimRefund' }, mockContext);

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
        expect(mockG7Core.toast.error).toHaveBeenCalled();
    });
});

describe('선택 교집합과 수량 확인 모달 (§4-4·§4-6)', () => {
    /**
     * 어휘는 서버가 행마다 실어 준다 — 화면이 상태 목록을 리터럴로 들면 전이표가 바뀔 때
     * 화면과 서버가 갈린다.
     *
     * @effects claim_item_selection_intersects_server_vocabulary
     */
    it('선택된 행들의 전이 어휘 교집합을 깐다', () => {
        syncClaimItemSelectionHandler(
            {
                handler: 'sirsoft-ecommerce.syncClaimItemSelection',
                params: { selectedIds: [55, 56], rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({
                selectedClaimItems: [55, 56],
                itemTransitionOptions: [{ value: 'rejected', label: '교환반려' }],
            }),
        );
    });

    /**
     * 선택이 바뀌어 이미 고른 상태가 교집합 밖이면 비운다 — 남겨 두면 화면엔 값이 있는데
     * [적용] 이 422 다.
     *
     * @effects claim_item_selection_drops_a_status_outside_the_intersection
     */
    it('교집합 밖이 된 상태는 비운다', () => {
        mockG7Core.state.getLocal = () => ({ itemBulkStatus: 'approved' });

        syncClaimItemSelectionHandler(
            {
                handler: 'sirsoft-ecommerce.syncClaimItemSelection',
                params: { selectedIds: [55, 56], rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({ itemBulkStatus: '' }),
        );

        mockG7Core.state.getLocal = () => ({});
    });

    /**
     * @effects claim_item_apply_opens_the_quantity_confirm_modal
     */
    it('[적용] 은 수량 확인 모달을 연다', async () => {
        await openClaimItemQuantityConfirmHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemQuantityConfirm',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'rejected', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_item_quantity_confirm');
        expect(mockGlobalState.claimItemQuantityContext).toMatchObject({
            status: 'rejected',
            itemIds: [55],
            quantities: { 55: 3 },
        });
        expect(mockGlobalState.claimItemQuantityContext.rows[0]).toMatchObject({ id: 55, quantity: 3, splittable: true });
    });

    /**
     * 완료는 이 모달을 거치지 않는다 — 완료 처리 모달이 재고 복구까지 함께 묻는다 (D26).
     *
     * @effects claim_item_apply_skips_the_quantity_modal_for_completion
     */
    it('완료 전이는 완료 처리 모달로 곧장 간다', async () => {
        await openClaimItemQuantityConfirmHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemQuantityConfirm',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'completed', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_complete');
    });

    /**
     * 수량 1 인 행은 나눌 것이 없다 — 입력을 열어 두면 0 이나 2 를 넣고 422 를 본다.
     *
     * @effects claim_item_quantity_modal_locks_unsplittable_rows
     */
    it('나눌 수 없는 행은 잠긴 것으로 표시한다', async () => {
        await openClaimItemQuantityConfirmHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemQuantityConfirm',
                params: { ...DETAIL_TARGET, itemIds: [56], status: 'rejected', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockGlobalState.claimItemQuantityContext.rows[0]).toMatchObject({ id: 56, splittable: false });
    });

    /**
     * @effects claim_item_quantity_input_is_clamped_to_the_row
     */
    it('수량 입력을 1~현재수량으로 클램프한다', async () => {
        await openClaimItemQuantityConfirmHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemQuantityConfirm',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'rejected', rows: ITEM_ROWS },
            },
            mockContext,
        );

        const update = (value: unknown) => updateClaimItemQuantityHandler(
            { handler: 'sirsoft-ecommerce.updateClaimItemQuantity', params: { itemId: 55, value: value as any } },
            mockContext,
        );

        update(9);
        expect(mockGlobalState.claimItemQuantityContext.quantities['55']).toBe(3);

        update(0);
        expect(mockGlobalState.claimItemQuantityContext.quantities['55']).toBe(1);

        update('');
        expect(mockGlobalState.claimItemQuantityContext.quantities['55']).toBe(1);

        update(2);
        expect(mockGlobalState.claimItemQuantityContext.quantities['55']).toBe(2);
    });
});
