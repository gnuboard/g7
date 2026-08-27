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
    saveClaimTrackingHandler,
    saveClaimAdminMemoHandler,
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
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() },
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
    { id: 55, product_name: '프리미엄 코튼 티셔츠', option_name: '차콜 / M', quantity: 1 },
    { id: 56, product_name: '소프트 터치 니트', option_name: '아이보리 / L', quantity: 1 },
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
        expect(body).toEqual({ items: [55], status: 'collecting' });
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
     * 환불에는 상품 단위 전이 축이 없다 — 라우트 자체가 교환·반품 스코프다.
     *
     * @scenario claim_type=refund, claim_stage=in_progress
     * @effects claim_item_bulk_status_rejects_refund
     */
    it('환불 유형에서는 상품 단위 전이를 시도하지 않는다', async () => {
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

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
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
            body: { items: [55] },
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
