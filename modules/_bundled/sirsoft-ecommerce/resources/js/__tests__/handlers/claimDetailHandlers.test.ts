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

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import {
    selectClaimItemBulkStatusHandler,
    selectClaimItemTrackingDirectionHandler,
    openClaimExchangeModeConfirmHandler,
    setClaimExchangeModeHandler,
    openClaimWithdrawOnBehalfHandler,
    withdrawClaimOnBehalfHandler,
    openClaimRequestEditHandler,
    updateClaimRequestOnBehalfHandler,
    restoreClaimStockHandler,
    toggleClaimCompleteUnreturnedHandler,
    setClaimCompleteUnreturnedReasonHandler,
    openClaimDetailCompleteHandler,
    executeClaimItemBulkStatusHandler,
    openClaimItemQuantityConfirmHandler,
    syncClaimItemSelectionHandler,
    updateClaimItemQuantityHandler,
    openClaimItemTrackingOnlyHandler,
    deliverClaimTrackingHandler,
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

/**
 * 엔진 `G7Core.state.set` 과 같은 병합으로 전역 상태를 갱신합니다. (기본 deep — 객체는 재귀 병합, 배열·원시값은 교체)
 *
 * 얕은 병합 목으로는 「맵에서 키를 빼고 다시 넣기」 가 실제 엔진에서 키를 지우지 못하는 결함이 보이지 않는다
 * (Chrome 실측 V-M6M7 — 미회수 체크 해제가 풀리지 않음).
 *
 * @param target 현재 상태
 * @param source 갱신 값
 * @returns 병합 결과
 */
function engineDeepMerge(target: Record<string, any>, source: Record<string, any>): Record<string, any> {
    const out: Record<string, any> = { ...target };

    for (const [key, value] of Object.entries(source)) {
        const prev = out[key];
        out[key] = value && typeof value === 'object' && !Array.isArray(value) && prev && typeof prev === 'object' && !Array.isArray(prev)
            ? engineDeepMerge(prev, value)
            : value;
    }

    return out;
}

const mockG7Core = {
    state: {
        get: () => mockGlobalState,
        getLocal: () => ({}),
        set: vi.fn((updates: Record<string, any>, options?: { merge?: 'replace' | 'shallow' | 'deep' }) => {
            const mode = options?.merge ?? 'deep';
            mockGlobalState = mode === 'replace'
                ? { ...updates }
                : mode === 'shallow' ? { ...mockGlobalState, ...updates } : engineDeepMerge(mockGlobalState, updates);
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

/**
 * 다시 읽은 데이터소스 id 를 순서대로 돌려준다.
 *
 * @returns 데이터소스 id 목록
 */
function refetchedSources(): string[] {
    return mockG7Core.dispatch.mock.calls
        .filter(([action]: any[]) => action?.handler === 'refetchDataSource')
        .map(([action]: any[]) => action.params.dataSourceId);
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
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'collected', rows: ITEM_ROWS },
            },
            mockContext,
        );

        const [url, body] = lastCall('patch');
        expect(url).toBe(`${BASE}/exchanges/31/options/bulk-status`);
        expect(body).toEqual({ items: [{ id: 55 }], status: 'collected' });
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
                    rejectReason: '개봉 상품입니다.',
                    rows: ITEM_ROWS,
                },
            },
            mockContext,
        );

        const [, body] = lastCall('patch');
        expect(body).toEqual({
            items: [{ id: 55, quantity: 2 }, { id: 56 }],
            status: 'rejected',
            reject_reason: '개봉 상품입니다.',
        });
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
     * 남은 수량은 **분할 전 상태에 그대로** 남는다 — 심사 단계면 신청 상태지만, 발송중 상품을 1개만
     * 발송도착으로 옮기면 남은 1개는 발송중이다. 안내가 늘 「신청 상태로 남겼습니다」 라고 하면 표의
     * 「교환발송중」 과 어긋난다(S1-D10 실측: EX…1431076762 발송중 2개 중 1개 발송도착).
     *
     * @scenario claim_type=exchange
     * @effects claim_item_split_notice_keeps_the_current_state_wording
     */
    it('뒷 단계에서 분할해도 남은 수량을 「신청 상태」 로 안내하지 않는다', async () => {
        mockG7Core.api.patch.mockResolvedValueOnce({
            success: true,
            data: {
                succeeded: [55],
                skipped: [],
                split: [{ id: 55, quantity: 1, remainder_id: 92, remainder_quantity: 1 }],
            },
        });

        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: {
                    ...DETAIL_TARGET,
                    itemIds: [55],
                    itemQuantities: { 55: 1 },
                    status: 'redelivered',
                    rows: ITEM_ROWS,
                },
            },
            mockContext,
        );

        const notice = mockG7Core.toast.info.mock.calls.at(-1)?.[0] ?? '';
        expect(notice).not.toContain('신청 상태');
        expect(notice).toContain('그대로');
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
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'collected', rows: ITEM_ROWS },
            },
            mockContext,
        )],
        ['송장 저장', async () => executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [55], trackingOnly: true, direction: 'collection', carrierId: 3, trackingNumber: '1' },
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

describe('상품 행 송장 저장 (「송장만 저장」 — R3·R4·R7)', () => {
    /** 송장이 붙은 두 상품 행 (같은 회수 운송장) */
    const TRACKED_ROWS = [
        { ...ITEM_ROWS[0], collection_carrier_id: 3, collection_tracking_number: 'CJ-1', return_address_snapshot: { zipcode: '06236' } },
        { ...ITEM_ROWS[1], collection_carrier_id: 3, collection_tracking_number: 'CJ-1', return_address_snapshot: { zipcode: '06236' } },
    ];

    /**
     * 처음 누르면 입력칸을 열고 고른 행들의 **공통 송장**을 미리 채운다(P1). 요청은 나가지 않는다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_collection_tracking_saved
     */
    it('처음 누르면 입력칸을 열고 공통 송장을 미리 채운다', async () => {
        await openClaimItemTrackingOnlyHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemTrackingOnly',
                params: { ...DETAIL_TARGET, itemIds: [55, 56], trackingMode: false, direction: 'collection', rows: TRACKED_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(expect.objectContaining({
            itemTrackingMode: true,
            itemCarrierId: '3',
            itemTrackingNumber: 'CJ-1',
        }));
        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
    });

    /**
     * 칸이 열린 뒤 누르면 수량 확인 모달을 「송장만 저장」 모드로 열고, 확인하면 행 송장 엔드포인트로 간다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_redelivery_tracking_saved
     */
    it('확인하면 방향·송장·수량을 실어 행 송장 엔드포인트로 나간다', async () => {
        await openClaimItemTrackingOnlyHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemTrackingOnly',
                params: { ...DETAIL_TARGET, itemIds: [55], trackingMode: true, direction: 'redelivery', carrierId: '5', trackingNumber: ' RD-9 ', rows: TRACKED_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_item_quantity_confirm');
        expect(mockGlobalState.claimItemQuantityContext).toEqual(expect.objectContaining({ trackingOnly: true, direction: 'redelivery', trackingNumber: 'RD-9' }));

        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [55], itemQuantities: { 55: 1 }, trackingOnly: true, direction: 'redelivery', carrierId: '5', trackingNumber: 'RD-9' },
            },
            mockContext,
        );

        const [url, body] = lastCall('patch');
        expect(url).toBe(`${BASE}/exchanges/31/options/tracking`);
        expect(body).toEqual({ direction: 'redelivery', carrier_id: 5, tracking_number: 'RD-9', items: [{ id: 55, quantity: 1 }] });
    });

    /**
     * 한쪽만 보내면 서버가 `claim_tracking_incomplete` 로 거절한다(R2) — 모달을 열기 전에 막는다.
     * 회수지가 다른 행을 한 회수 운송장으로 묶는 것도 막는다(R8).
     *
     * @effects claim_tracking_requires_both_fields
     */
    it.each([
        ['택배사만', { carrierId: '3', trackingNumber: '' }, TRACKED_ROWS],
        ['송장번호만', { carrierId: '', trackingNumber: '123' }, TRACKED_ROWS],
        ['회수지가 다른 행', { carrierId: '3', trackingNumber: '123' }, [TRACKED_ROWS[0], { ...TRACKED_ROWS[1], return_address_snapshot: { zipcode: '99999' } }]],
    ])('%s 이면 모달을 열지 않는다', async (_label, partial, rows) => {
        await openClaimItemTrackingOnlyHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemTrackingOnly',
                params: { ...DETAIL_TARGET, itemIds: [55, 56], trackingMode: true, direction: 'collection', rows, ...partial },
            },
            mockContext,
        );

        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /**
     * 둘 다 비우는 것은 그 행의 송장 삭제다 — 서버가 받는 조합이라 막지 않는다(R7).
     *
     * @effects claim_tracking_allows_clearing_both
     */
    it('둘 다 비우면 삭제 요청으로 나간다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, pathSegment: 'returns', claimId: 12, itemIds: [55], trackingOnly: true, direction: 'collection', carrierId: '', trackingNumber: '' },
            },
            mockContext,
        );

        expect(lastCall('patch')[1]).toEqual({ direction: 'collection', carrier_id: null, tracking_number: null, items: [{ id: 55 }] });
    });

    /**
     * 실패해도 버튼은 되살아나야 한다 — 그러지 않으면 한 번 실패한 뒤로 저장 자체를 못 한다.
     *
     * @effects claim_tracking_restores_button_on_failure
     */
    it('저장이 실패해도 진행 플래그가 내려간다', async () => {
        mockG7Core.api.patch.mockRejectedValueOnce(new Error('boom'));

        await expect(
            executeClaimItemBulkStatusHandler(
                {
                    handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                    params: { ...DETAIL_TARGET, itemIds: [55], trackingOnly: true, direction: 'collection', carrierId: '3', trackingNumber: '1' },
                },
                mockContext,
            ),
        ).rejects.toThrow();

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isUpdatingClaimItems: false, claimItemsUpdateKind: null });
    });

    /**
     * 운송장 단위 도착 처리(R9)는 방향·택배사·운송장을 실어 도착 엔드포인트로 간다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     * @effects claim_tracking_deliver_posts_the_waybill
     */
    it('도착 처리는 운송장 단위 엔드포인트로 나간다', async () => {
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, data: { succeeded: [55], skipped: [] } });

        await deliverClaimTrackingHandler(
            {
                handler: 'sirsoft-ecommerce.deliverClaimTracking',
                params: { pathSegment: 'returns', claimId: 12, direction: 'collection', carrierId: 3, trackingNumber: 'CJ-1' },
            },
            mockContext,
        );

        const [url, body] = lastCall('post');
        expect(url).toBe(`${BASE}/returns/12/tracking/deliver`);
        expect(body).toEqual({ direction: 'collection', carrier_id: 3, tracking_number: 'CJ-1' });
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
            // 표시용 합계 — 핸들러가 부동소수 오차를 걷어 넘긴다(P7 #14). 교환 차액 환급이 있으면 더한다.
            refundTotal: 25000,
            // 창이 그리는 표기 — 숫자에 통화 코드를 붙인 `24000 KRW` 가 아니다(S3-D1).
            refundAmountFormatted: '24,000원',
            refundPointsAmountFormatted: '1,000원',
            refundTotalFormatted: '25,000원',
            exchangeDifferenceTotal: 0,
            exchangeDifferenceFormatted: '',
            refundMethod: 'pg',
            refundMethodLabel: 'PG사 환불',
            refundBankName: '',
            refundBankAccount: '',
            refundBankHolder: '',
            // 계좌가 필요한지는 서버가 답한다 — 수단별 판정도 저장 수단 판정도 없으면 「필요 없음」이다.
            refundAccountRequired: false,
            // 수단별 판정이 없는 응답이면 계좌 줄은 값이 있을 때 보인다(종전 동작).
            refundAccountUsed: true,
            pointsNotDeducted: false,
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
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'rejected', rejectReason: '개봉 상품입니다.', rows: ITEM_ROWS },
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
                params: { ...DETAIL_TARGET, itemIds: [56], status: 'rejected', rejectReason: '개봉 상품입니다.', rows: ITEM_ROWS },
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
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'rejected', rejectReason: '개봉 상품입니다.', rows: ITEM_ROWS },
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

describe('반려 사유 — 상품 칸 (#599 후속 운영 결정)', () => {
    /**
     * 사유 없이 반려를 고르면 수량 확인 모달을 열지 않는다 — 모달까지 연 뒤 서버에서야 422 가 되지 않게.
     *
     * @effects claim_item_rejection_requires_reason_before_modal
     */
    it('사유가 비면 모달을 열지 않고 안내한다', async () => {
        await openClaimItemQuantityConfirmHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemQuantityConfirm',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'rejected', rejectReason: '   ', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
        expect(mockG7Core.t).toHaveBeenCalledWith('sirsoft-ecommerce.admin.claim.detail.transition.reject_reason_required', undefined);
        expect(mockG7Core.toast.warning).toHaveBeenCalledWith('반려 사유를 입력해주세요.');
    });

    /**
     * 모달 문맥이 사유를 나르고, 실행 본문이 그 사유를 `reject_reason` 으로 싣는다.
     *
     * @effects claim_item_rejection_reason_travels_to_the_request
     */
    it('사유가 모달 문맥을 거쳐 본문에 실린다', async () => {
        await openClaimItemQuantityConfirmHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemQuantityConfirm',
                params: { ...DETAIL_TARGET, itemIds: [56], status: 'rejected', rejectReason: ' 개봉 상품입니다. ', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockGlobalState.claimItemQuantityContext.rejectReason).toBe('개봉 상품입니다.');

        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: {
                    ...DETAIL_TARGET,
                    itemIds: [56],
                    status: 'rejected',
                    rejectReason: mockGlobalState.claimItemQuantityContext.rejectReason,
                    rows: ITEM_ROWS,
                },
            },
            mockContext,
        );

        const [, body] = lastCall('patch');
        expect(body.reject_reason).toBe('개봉 상품입니다.');
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(expect.objectContaining({ itemRejectReason: '' }));
    });

    /**
     * 반려가 아니면 사유를 싣지 않는다 — 승인에 남은 사유가 신청서 반려 사유로 덮어쓰이지 않게.
     *
     * @effects claim_item_non_rejection_omits_reason
     */
    it('반려가 아니면 reject_reason 이 없다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'approved', rejectReason: '남은 입력', rows: ITEM_ROWS },
            },
            mockContext,
        );

        const [, body] = lastCall('patch');
        expect(body).not.toHaveProperty('reject_reason');
    });
});

describe('회수중·발송중 송장 — 상품 칸 (#599 후속 운영 결정)', () => {
    /**
     * 송장이 비면 수량 확인 모달을 열지 않는다 — 모달까지 연 뒤 서버에서야 422 가 되지 않게.
     *
     * @effects claim_item_logistics_requires_tracking_before_modal
     */
    it('회수중인데 송장이 비면 모달을 열지 않고 안내한다', async () => {
        await openClaimItemQuantityConfirmHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemQuantityConfirm',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'collecting', carrierId: '3', trackingNumber: '  ', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
        expect(mockG7Core.t).toHaveBeenCalledWith('sirsoft-ecommerce.admin.claim.detail.shipping.tracking_incomplete', undefined);
        expect(mockG7Core.toast.warning).toHaveBeenCalledWith('택배사와 송장번호를 함께 입력해주세요.');
    });

    /**
     * 모달 문맥이 송장을 나르고(확인 문구용 택배사 이름 포함), 실행 본문이 그 송장을 싣는다.
     *
     * @effects claim_item_logistics_tracking_travels_to_the_request
     */
    it('송장이 모달 문맥을 거쳐 본문에 실린다', async () => {
        await openClaimItemQuantityConfirmHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemQuantityConfirm',
                params: {
                    ...DETAIL_TARGET,
                    itemIds: [56],
                    status: 'redelivering',
                    carrierId: 3,
                    trackingNumber: ' RD-1 ',
                    carriers: [{ value: 3, label: 'CJ대한통운' }],
                    rows: ITEM_ROWS,
                },
            },
            mockContext,
        );

        const context = mockGlobalState.claimItemQuantityContext;
        expect(context.carrierId).toBe('3');
        expect(context.trackingNumber).toBe('RD-1');
        expect(context.carrierLabel).toBe('CJ대한통운');

        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [56], status: 'redelivering', carrierId: context.carrierId, trackingNumber: context.trackingNumber, rows: ITEM_ROWS },
            },
            mockContext,
        );

        const [, body] = lastCall('patch');
        expect(body.carrier_id).toBe(3);
        expect(body.tracking_number).toBe('RD-1');
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(expect.objectContaining({ itemCarrierId: '', itemTrackingNumber: '' }));
    });

    /**
     * 회수중·발송중이 아니면 송장을 싣지 않는다 — 회수도착(고객 직접 발송)은 송장 없이 간다.
     *
     * @effects claim_item_non_logistics_omits_tracking
     */
    it('회수도착은 송장 없이 간다', async () => {
        await executeClaimItemBulkStatusHandler(
            {
                handler: 'sirsoft-ecommerce.executeClaimItemBulkStatus',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'collected', carrierId: '', trackingNumber: '', rows: ITEM_ROWS },
            },
            mockContext,
        );

        const [, body] = lastCall('patch');
        expect(body).not.toHaveProperty('carrier_id');
        expect(body).not.toHaveProperty('tracking_number');
        expect(mockG7Core.toast.warning).not.toHaveBeenCalled();
    });
});

describe('상품 칸 상태·방향 고르기 — 공통 송장 미리 채움 (P1)', () => {
    /** 회수·재발송 송장이 붙은 두 행 (재발송은 행마다 다르다) */
    const TRACKED_ROWS = [
        { ...ITEM_ROWS[0], collection_carrier_id: 3, collection_tracking_number: 'CJ-1', redelivery_carrier_id: 5, redelivery_tracking_number: 'RD-1' },
        { ...ITEM_ROWS[1], collection_carrier_id: 3, collection_tracking_number: 'CJ-1', redelivery_carrier_id: 5, redelivery_tracking_number: 'RD-2' },
    ];

    let getLocalSpy: { mockRestore: () => void } | null = null;

    /**
     * 고른 상품 행을 `_local.selectedClaimItems` 로 둔다.
     *
     * @param ids 고른 행 ID
     */
    function selectItems(ids: Array<number | string>): void {
        getLocalSpy = vi.spyOn(mockG7Core.state, 'getLocal').mockReturnValue({ selectedClaimItems: ids } as any);
    }

    afterEach(() => {
        getLocalSpy?.mockRestore();
        getLocalSpy = null;
    });

    /**
     * 방향을 바꾸면 그 방향의 공통 송장으로 칸을 다시 채운다 — 행마다 송장이 다르면 비운다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_item_tracking_direction_prefills_common_waybill
     */
    it('방향을 고르면 그 방향의 공통 송장으로 다시 채운다', () => {
        selectItems(['55', 56]);

        selectClaimItemTrackingDirectionHandler(
            { handler: 'sirsoft-ecommerce.selectClaimItemTrackingDirection', params: { direction: 'collection', rows: TRACKED_ROWS } },
            mockContext,
        );
        expect(mockG7Core.state.setLocal).toHaveBeenLastCalledWith({
            itemTrackingDirection: 'collection',
            itemCarrierId: '3',
            itemTrackingNumber: 'CJ-1',
        });

        selectClaimItemTrackingDirectionHandler(
            { handler: 'sirsoft-ecommerce.selectClaimItemTrackingDirection', params: { direction: 'redelivery', rows: TRACKED_ROWS } },
            mockContext,
        );
        expect(mockG7Core.state.setLocal).toHaveBeenLastCalledWith({
            itemTrackingDirection: 'redelivery',
            itemCarrierId: '',
            itemTrackingNumber: '',
        });
    });

    /**
     * 상태를 고른 **뒤에** 송장 없는 행을 더 고르면 미리 채운 송장을 비운다 — 남겨 두면 그대로
     * [일괄 적용] 했을 때 송장 없는 상품에 다른 상품의 송장이 붙는다(2026-10-07 실측: 1행 선택 →
     * 회수중 → 송장 없는 3행 추가 선택 → 칸에 1행 송장이 그대로). 운영자가 손으로 고친 값은 두고,
     * 자동으로 채운 값만 새 선택 기준으로 다시 정한다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     * @effects claim_item_selection_change_recomputes_prefilled_waybill
     */
    it('상태를 고른 뒤 선택이 바뀌면 자동으로 채운 송장만 새 선택 기준으로 다시 정한다', () => {
        const rows = [
            { ...ITEM_ROWS[0], transition_options: [{ value: 'collecting', label: '반품회수중' }], collection_carrier_id: 3, collection_tracking_number: 'CJ-1' },
            { ...ITEM_ROWS[1], transition_options: [{ value: 'collecting', label: '반품회수중' }], collection_carrier_id: null, collection_tracking_number: null },
        ];

        // 자동으로 채운 값 그대로 → 섞인 선택에서는 비운다
        getLocalSpy = vi.spyOn(mockG7Core.state, 'getLocal').mockReturnValue({
            selectedClaimItems: [55], itemBulkStatus: 'collecting', itemCarrierId: '3', itemTrackingNumber: 'CJ-1',
        } as any);
        syncClaimItemSelectionHandler(
            { handler: 'sirsoft-ecommerce.syncClaimItemSelection', params: { selectedIds: [55, 56], rows } },
            mockContext,
        );
        expect(mockG7Core.state.setLocal).toHaveBeenLastCalledWith(expect.objectContaining({
            itemBulkStatus: 'collecting', itemCarrierId: '', itemTrackingNumber: '',
        }));

        // 운영자가 고쳐 적은 값 → 그대로 둔다
        getLocalSpy.mockRestore();
        getLocalSpy = vi.spyOn(mockG7Core.state, 'getLocal').mockReturnValue({
            selectedClaimItems: [55], itemBulkStatus: 'collecting', itemCarrierId: '3', itemTrackingNumber: 'NEW-9',
        } as any);
        syncClaimItemSelectionHandler(
            { handler: 'sirsoft-ecommerce.syncClaimItemSelection', params: { selectedIds: [55, 56], rows } },
            mockContext,
        );
        const last = mockG7Core.state.setLocal.mock.calls.at(-1)?.[0] ?? {};
        expect(last).not.toHaveProperty('itemTrackingNumber');
        expect(last).not.toHaveProperty('itemCarrierId');
    });

    /**
     * 모르는 방향 값은 회수로 읽는다 — 빈 값이 그대로 저장 요청의 `direction` 이 되지 않게.
     *
     * @effects claim_item_tracking_direction_prefills_common_waybill
     */
    it('모르는 방향 값은 회수로 읽는다', () => {
        selectItems([55]);

        selectClaimItemTrackingDirectionHandler(
            { handler: 'sirsoft-ecommerce.selectClaimItemTrackingDirection', params: { direction: '', rows: TRACKED_ROWS } },
            mockContext,
        );

        expect(mockG7Core.state.setLocal).toHaveBeenLastCalledWith(expect.objectContaining({ itemTrackingDirection: 'collection', itemTrackingNumber: 'CJ-1' }));
    });

    /**
     * 회수중·발송중을 고르면 그 방향의 공통 송장을 채우고 「송장만 저장」 칸은 닫는다. 다른 상태는 송장 칸을 비운다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_item_bulk_status_select_prefills_common_waybill
     */
    it('회수중을 고르면 공통 송장을 채우고 다른 상태는 비운다', () => {
        selectItems([55, 56]);

        selectClaimItemBulkStatusHandler(
            { handler: 'sirsoft-ecommerce.selectClaimItemBulkStatus', params: { status: 'collecting', rows: TRACKED_ROWS } },
            mockContext,
        );
        expect(mockG7Core.state.setLocal).toHaveBeenLastCalledWith({
            itemBulkStatus: 'collecting',
            itemTrackingMode: false,
            itemCarrierId: '3',
            itemTrackingNumber: 'CJ-1',
        });

        selectClaimItemBulkStatusHandler(
            { handler: 'sirsoft-ecommerce.selectClaimItemBulkStatus', params: { status: 'approved', rows: TRACKED_ROWS } },
            mockContext,
        );
        expect(mockG7Core.state.setLocal).toHaveBeenLastCalledWith({
            itemBulkStatus: 'approved',
            itemTrackingMode: false,
            itemCarrierId: '',
            itemTrackingNumber: '',
        });
    });
});

describe('진행 방식 전환 — 일반 교환 · 선교환 (D1)', () => {
    /**
     * 확인 모달의 문맥은 `_global` 에 둔다 — 모달은 부모 화면의 `_local` 을 읽지 못한다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_exchange_mode_confirm_opens_with_context
     */
    it('확인 모달을 열며 신청서·방식·회수 기한을 문맥에 싣는다', () => {
        openClaimExchangeModeConfirmHandler(
            { handler: 'sirsoft-ecommerce.openClaimExchangeModeConfirm', params: { claimId: '31', mode: 'advance', dueDays: 7 } },
            mockContext,
        );

        expect(mockGlobalState.claimExchangeModeContext).toEqual({ claimId: 31, mode: 'advance', dueDays: 7 });
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_exchange_mode_confirm');

        // 모르는 방식은 일반 교환으로 읽는다
        openClaimExchangeModeConfirmHandler(
            { handler: 'sirsoft-ecommerce.openClaimExchangeModeConfirm', params: { claimId: 31, mode: 'weird' } },
            mockContext,
        );
        expect(mockGlobalState.claimExchangeModeContext).toEqual({ claimId: 31, mode: 'standard', dueDays: '' });
    });

    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_exchange_mode_puts_mode_and_refetches_detail
     */
    it('교환 전용 엔드포인트로 방식을 PUT 하고 상세를 다시 읽는다', async () => {
        mockGlobalState.claimExchangeModeContext = { claimId: 31, mode: 'advance', dueDays: 7 };
        mockG7Core.api.put.mockResolvedValueOnce({ success: true, message: '진행 방식을 선교환으로 바꿨습니다.', data: {} });

        await setClaimExchangeModeHandler(
            { handler: 'sirsoft-ecommerce.setClaimExchangeMode', params: { claimId: 31, mode: 'advance' } },
            mockContext,
        );

        const [url, body] = lastCall('put');
        expect(url).toBe(`${BASE}/exchanges/31/exchange-mode`);
        expect(body).toEqual({ mode: 'advance' });
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('진행 방식을 선교환으로 바꿨습니다.');
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('modal_claim_exchange_mode_confirm');
        expect(mockGlobalState.claimExchangeModeContext).toBeNull();
        expect(mockGlobalState.isSettingExchangeMode).toBe(false);
        expect(refetchedSources()).toEqual(['claim', 'claim_logs']);
    });

    /**
     * 신청서 ID 가 없으면 `exchanges/NaN/...` 로 나가지 않는다. 진행 중이면 다시 누른 요청도 보내지 않는다.
     *
     * @effects claim_exchange_mode_requires_claim_id
     */
    it('신청서 ID 가 없거나 진행 중이면 요청하지 않는다', async () => {
        await setClaimExchangeModeHandler(
            { handler: 'sirsoft-ecommerce.setClaimExchangeMode', params: { claimId: null, mode: 'advance' } },
            mockContext,
        );
        await setClaimExchangeModeHandler(
            { handler: 'sirsoft-ecommerce.setClaimExchangeMode', params: { claimId: 'abc', mode: 'advance' } },
            mockContext,
        );

        mockGlobalState.isSettingExchangeMode = true;
        await setClaimExchangeModeHandler(
            { handler: 'sirsoft-ecommerce.setClaimExchangeMode', params: { claimId: 31, mode: 'advance' } },
            mockContext,
        );

        expect(mockG7Core.api.put).not.toHaveBeenCalled();
    });

    /**
     * 실패는 서버 사유로 한 번만 알린다(레이아웃 onError 는 suppress). 진행 플래그가 내려가고 모달은 남는다.
     *
     * @effects claim_exchange_mode_failure_reports_once_and_unlocks
     */
    it('실패하면 한 번만 알리고 진행 플래그를 내린다', async () => {
        mockG7Core.api.put.mockRejectedValueOnce({ response: { status: 422, data: { message: '회수가 시작된 교환은 방식을 바꿀 수 없습니다.' } } });

        await expect(setClaimExchangeModeHandler(
            { handler: 'sirsoft-ecommerce.setClaimExchangeMode', params: { claimId: 31, mode: 'standard' } },
            mockContext,
        )).rejects.toBeTruthy();

        expect(mockG7Core.toast.error).toHaveBeenCalledTimes(1);
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('회수가 시작된 교환은 방식을 바꿀 수 없습니다.');
        expect(mockGlobalState.isSettingExchangeMode).toBe(false);
        expect(mockG7Core.modal.close).not.toHaveBeenCalled();
        expect(mockG7Core.dispatch).not.toHaveBeenCalled();
    });
});

describe('고객 요청 대리 철회 (S14)', () => {
    /**
     * 모달을 열 때 앞 건의 사유를 비운다 — 다른 신청서의 철회 사유가 그대로 실리지 않게.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     * @effects claim_withdraw_on_behalf_modal_resets_reason
     */
    it('모달을 열며 세그먼트·신청서를 싣고 사유를 비운다', () => {
        mockGlobalState.claimWithdrawReason = '앞 건 사유';

        openClaimWithdrawOnBehalfHandler(
            { handler: 'sirsoft-ecommerce.openClaimWithdrawOnBehalf', params: { pathSegment: 'returns', claimId: '12' } },
            mockContext,
        );

        expect(mockGlobalState.claimWithdrawContext).toEqual({ pathSegment: 'returns', claimId: 12 });
        expect(mockGlobalState.claimWithdrawReason).toBe('');
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_withdraw_on_behalf');
    });

    /**
     * 경로 세그먼트는 서버가 준 값을 그대로 쓰고, 사유는 앞뒤 공백을 걷어 싣는다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     * @effects claim_withdraw_on_behalf_posts_reason
     */
    it('사유를 실어 유형 스코프 철회 엔드포인트로 나가고 상세를 다시 읽는다', async () => {
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, message: '고객 요청으로 신청을 철회했습니다.', data: {} });

        await withdrawClaimOnBehalfHandler(
            { handler: 'sirsoft-ecommerce.withdrawClaimOnBehalf', params: { pathSegment: 'returns', claimId: 12, reason: '  고객이 전화로 철회 요청  ' } },
            mockContext,
        );

        const [url, body] = lastCall('post');
        expect(url).toBe(`${BASE}/returns/12/withdraw`);
        expect(body).toEqual({ reason: '고객이 전화로 철회 요청' });
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('고객 요청으로 신청을 철회했습니다.');
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('modal_claim_withdraw_on_behalf');
        expect(mockGlobalState.claimWithdrawContext).toBeNull();
        expect(mockGlobalState.claimWithdrawReason).toBe('');
        expect(mockGlobalState.isWithdrawingClaim).toBe(false);
        expect(refetchedSources()).toEqual(['claim', 'claim_logs']);
    });

    /**
     * 사유는 필수다(서버도 422). 공백뿐인 사유·식별 축이 없는 호출·진행 중 재호출은 요청하지 않는다.
     *
     * @effects claim_withdraw_on_behalf_requires_reason
     */
    it('사유가 비었거나 식별 축이 없거나 진행 중이면 요청하지 않는다', async () => {
        await withdrawClaimOnBehalfHandler(
            { handler: 'sirsoft-ecommerce.withdrawClaimOnBehalf', params: { pathSegment: 'returns', claimId: 12, reason: '   ' } },
            mockContext,
        );
        expect(mockG7Core.toast.warning).toHaveBeenCalledWith('철회 사유를 입력해주세요.');

        await withdrawClaimOnBehalfHandler(
            { handler: 'sirsoft-ecommerce.withdrawClaimOnBehalf', params: { pathSegment: '', claimId: 12, reason: '사유' } },
            mockContext,
        );

        mockGlobalState.isWithdrawingClaim = true;
        await withdrawClaimOnBehalfHandler(
            { handler: 'sirsoft-ecommerce.withdrawClaimOnBehalf', params: { pathSegment: 'returns', claimId: 12, reason: '사유' } },
            mockContext,
        );

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
    });

    /**
     * @effects claim_withdraw_on_behalf_failure_reports_once_and_unlocks
     */
    it('실패하면 한 번만 알리고 진행 플래그를 내린다', async () => {
        mockG7Core.api.post.mockRejectedValueOnce({ response: { status: 422, data: { message: '이미 처리된 신청은 철회할 수 없습니다.' } } });

        await expect(withdrawClaimOnBehalfHandler(
            { handler: 'sirsoft-ecommerce.withdrawClaimOnBehalf', params: { pathSegment: 'exchanges', claimId: 31, reason: '고객 요청' } },
            mockContext,
        )).rejects.toBeTruthy();

        expect(mockG7Core.toast.error).toHaveBeenCalledTimes(1);
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('이미 처리된 신청은 철회할 수 없습니다.');
        expect(mockGlobalState.isWithdrawingClaim).toBe(false);
        expect(mockG7Core.modal.close).not.toHaveBeenCalled();
    });
});

describe('신청 내용 수정 (S15)', () => {
    /**
     * 모달은 지금 사유로 시작한다 — 빈 칸에서 시작하면 상세 사유만 고치려다 사유가 지워진다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_request_edit_modal_seeds_current_reason
     */
    it('모달을 열며 지금 사유와 선택지를 싣는다', () => {
        const reasonOptions = [{ value: 'defective', label: '상품 불량' }, { value: 'wrong_delivery', label: '오배송' }];

        openClaimRequestEditHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimRequestEdit',
                params: { pathSegment: 'exchanges', claimId: '31', reasonCode: 'defective', reasonDetail: '실밥 풀림', reasonOptions },
            },
            mockContext,
        );

        expect(mockGlobalState.claimRequestEditContext).toEqual({ pathSegment: 'exchanges', claimId: 31, reasonOptions });
        expect(mockGlobalState.claimRequestEditReasonCode).toBe('defective');
        expect(mockGlobalState.claimRequestEditReasonDetail).toBe('실밥 풀림');
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_request_edit');
    });

    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_request_edit_patches_reason
     */
    it('사유를 실어 유형 스코프 신청서 경로로 PATCH 하고 상세를 다시 읽는다', async () => {
        mockG7Core.api.patch.mockResolvedValueOnce({ success: true, message: '신청 내용을 수정했습니다.', data: {} });

        await updateClaimRequestOnBehalfHandler(
            {
                handler: 'sirsoft-ecommerce.updateClaimRequestOnBehalf',
                params: { pathSegment: 'exchanges', claimId: 31, reasonCode: 'wrong_delivery', reasonDetail: '다른 색상 배송' },
            },
            mockContext,
        );

        const [url, body] = lastCall('patch');
        expect(url).toBe(`${BASE}/exchanges/31`);
        expect(body).toEqual({ reason_code: 'wrong_delivery', reason_detail: '다른 색상 배송' });
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('신청 내용을 수정했습니다.');
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('modal_claim_request_edit');
        expect(mockGlobalState.claimRequestEditContext).toBeNull();
        expect(mockGlobalState.isEditingClaimRequest).toBe(false);
        expect(refetchedSources()).toEqual(['claim', 'claim_logs']);
    });

    /**
     * 식별 축이 없으면 `admin//31` 로 나가지 않는다. 진행 중 재호출도 막는다.
     *
     * @effects claim_request_edit_requires_identity
     */
    it('식별 축이 없거나 진행 중이면 요청하지 않는다', async () => {
        await updateClaimRequestOnBehalfHandler(
            { handler: 'sirsoft-ecommerce.updateClaimRequestOnBehalf', params: { pathSegment: 'exchanges', claimId: null, reasonCode: 'defective' } },
            mockContext,
        );

        mockGlobalState.isEditingClaimRequest = true;
        await updateClaimRequestOnBehalfHandler(
            { handler: 'sirsoft-ecommerce.updateClaimRequestOnBehalf', params: { pathSegment: 'exchanges', claimId: 31, reasonCode: 'defective' } },
            mockContext,
        );

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
    });

    /**
     * @effects claim_request_edit_failure_reports_once_and_unlocks
     */
    it('실패하면 한 번만 알리고 진행 플래그를 내린다', async () => {
        mockG7Core.api.patch.mockRejectedValueOnce({ response: { status: 422, data: { message: '선택할 수 없는 사유입니다.' } } });

        await expect(updateClaimRequestOnBehalfHandler(
            { handler: 'sirsoft-ecommerce.updateClaimRequestOnBehalf', params: { pathSegment: 'returns', claimId: 12, reasonCode: 'x' } },
            mockContext,
        )).rejects.toBeTruthy();

        expect(mockG7Core.toast.error).toHaveBeenCalledTimes(1);
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('선택할 수 없는 사유입니다.');
        expect(mockGlobalState.isEditingClaimRequest).toBe(false);
        expect(mockG7Core.modal.close).not.toHaveBeenCalled();
    });
});

describe('뒤늦은 재고 복구 (S13)', () => {
    /**
     * 진행 플래그는 `claimButtonPending` 이 잠근다 — 여기서는 URL·본문·재조회를 잠근다.
     *
     * @scenario claim_type=return, claim_stage=final
     * @effects claim_restore_stock_posts_item_ids
     */
    it('고른 행 ID 를 숫자로 실어 재고 복구 엔드포인트로 나가고 상세를 다시 읽는다', async () => {
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, message: '재고를 복구했습니다.', data: {} });

        await restoreClaimStockHandler(
            { handler: 'sirsoft-ecommerce.restoreClaimStock', params: { pathSegment: 'returns', claimId: 12, itemIds: ['55', 56] } },
            mockContext,
        );

        const [url, body] = lastCall('post');
        expect(url).toBe(`${BASE}/returns/12/items/restore-stock`);
        expect(body).toEqual({ items: [55, 56] });
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('재고를 복구했습니다.');
        expect(refetchedSources()).toEqual(['claim', 'claim_logs']);
    });

    /**
     * 두 번 복구되지 않는 것은 서버가 막는다 — 화면은 그 사유를 한 번만 알린다.
     *
     * @effects claim_restore_stock_posts_item_ids
     */
    it('실패하면 서버 사유를 한 번만 알리고 식별 축이 없으면 요청하지 않는다', async () => {
        mockG7Core.api.post.mockRejectedValueOnce({ response: { status: 409, data: { message: '이미 재고를 복구했습니다.' } } });

        await expect(restoreClaimStockHandler(
            { handler: 'sirsoft-ecommerce.restoreClaimStock', params: { pathSegment: 'returns', claimId: 12, itemIds: [55] } },
            mockContext,
        )).rejects.toBeTruthy();
        expect(mockG7Core.toast.error).toHaveBeenCalledTimes(1);
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('이미 재고를 복구했습니다.');

        mockG7Core.api.post.mockClear();
        await restoreClaimStockHandler(
            { handler: 'sirsoft-ecommerce.restoreClaimStock', params: { pathSegment: '', claimId: 12, itemIds: [55] } },
            mockContext,
        );
        expect(mockG7Core.api.post).not.toHaveBeenCalled();
    });
});

describe('완료 모달 「미회수로 완료」 (선교환 D3)', () => {
    /**
     * 체크한 행은 사유 맵의 키가 된다(빈 사유로 시작). 체크를 풀면 키가 빠진다.
     *
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_complete_unreturned_toggle_tracks_checked_rows
     */
    it('체크한 행만 사유 맵에 두고 풀면 뺀다', () => {
        const toggle = (itemId: unknown, checked: unknown) => toggleClaimCompleteUnreturnedHandler(
            { handler: 'sirsoft-ecommerce.toggleClaimCompleteUnreturned', params: { itemId, checked } as any },
            mockContext,
        );

        toggle(55, true);
        toggle('56', 'true');
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({ '55': '', '56': '' });

        // 이미 적은 사유는 다시 체크 이벤트가 와도 지워지지 않는다
        mockGlobalState.claimCompleteUnreturned = { '55': '고객 연락 두절', '56': '' };
        toggle(55, true);
        expect(mockGlobalState.claimCompleteUnreturned['55']).toBe('고객 연락 두절');

        toggle(56, false);
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({ '55': '고객 연락 두절' });

        // 행 ID 가 없으면 아무것도 바꾸지 않는다
        const before = mockG7Core.state.set.mock.calls.length;
        toggle(undefined, true);
        expect(mockG7Core.state.set.mock.calls.length).toBe(before);
    });

    /**
     * 체크를 풀면 맵에서 그 행이 실제로 빠진다 — 엔진의 전역 set 은 기본이 deep 병합이라, 키를 뺀 맵을 그대로
     * 넘기면 옛 키가 남아 해제가 풀리지 않는다(Chrome 실측 V-M6M7). 맵은 통째로 바꾼다.
     *
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_complete_unreturned_uncheck_removes_the_row_under_engine_merge
     */
    it('체크를 풀면 엔진 병합에서도 그 행이 맵에서 빠진다', () => {
        const toggle = (itemId: unknown, checked: unknown) => toggleClaimCompleteUnreturnedHandler(
            { handler: 'sirsoft-ecommerce.toggleClaimCompleteUnreturned', params: { itemId, checked } as any },
            mockContext,
        );

        toggle(98, true);
        toggle(99, true);
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({ '98': '', '99': '' });

        toggle(98, false);
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({ '99': '' });
    });

    /**
     * 사유는 체크된 행에만 쓴다 — 체크하지 않은 행에 사유가 들어가면 그 행이 미회수로 실린다.
     *
     * @effects claim_complete_unreturned_reason_only_for_checked_rows
     */
    it('사유는 체크된 행에만 쓴다', () => {
        mockGlobalState.claimCompleteUnreturned = { '55': '' };
        const setReason = (itemId: unknown, reason: unknown) => setClaimCompleteUnreturnedReasonHandler(
            { handler: 'sirsoft-ecommerce.setClaimCompleteUnreturnedReason', params: { itemId, reason } as any },
            mockContext,
        );

        setReason(55, '고객 연락 두절');
        setReason(56, '체크 안 한 행');
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({ '55': '고객 연락 두절' });

        setReason(55, null);
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({ '55': '' });

        setReason(55, '회수 불가');
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({ '55': '회수 불가' });
    });

    /**
     * 체크한 행의 사유가 완료 본문 `completion.unreturned` 로 실리고, 성공하면 맵을 비운다.
     *
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_completion_sends_unreturned_reasons
     */
    it('미회수 사유가 완료 본문에 실린다', async () => {
        await openClaimDetailCompleteHandler(
            { handler: 'sirsoft-ecommerce.openClaimDetailComplete', params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: {} } },
            mockContext,
        );
        mockGlobalState.claimCompleteUnreturned = { '55': '고객 연락 두절' };

        await executeClaimCompletionHandler({ handler: 'sirsoft-ecommerce.executeClaimCompletion' }, mockContext);

        const [, body] = lastCall('patch');
        expect(body.completion.unreturned).toEqual({ '55': '고객 연락 두절' });
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({});
    });

    /**
     * @effects claim_completion_sends_unreturned_reasons
     */
    it('체크한 행이 없으면 unreturned 키를 싣지 않는다', async () => {
        await openClaimDetailCompleteHandler(
            { handler: 'sirsoft-ecommerce.openClaimDetailComplete', params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: {} } },
            mockContext,
        );
        mockGlobalState.claimCompleteUnreturned = {};

        await executeClaimCompletionHandler({ handler: 'sirsoft-ecommerce.executeClaimCompletion' }, mockContext);

        expect(lastCall('patch')[1].completion).not.toHaveProperty('unreturned');
    });

    /**
     * 모달을 열 때 앞선 미회수 선택을 비운다 — 취소한 모달의 행 ID 가 다음 신청서 요청에 실리면 화면에
     * 없는 행의 사유를 요구하거나 서버가 `claim_unreturned_not_eligible` 로 막는다(validate branch M6).
     *
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_complete_modal_open_clears_previous_unreturned_selection
     */
    it('모달을 열면 앞선 미회수 선택이 남지 않는다', async () => {
        mockGlobalState.claimCompleteUnreturned = { '99': '앞 신청서의 사유' };

        await openClaimDetailCompleteHandler(
            { handler: 'sirsoft-ecommerce.openClaimDetailComplete', params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: {} } },
            mockContext,
        );

        expect(mockGlobalState.claimCompleteUnreturned).toEqual({});
    });

    /**
     * 미회수 행은 실행에서 완료 대상에 들고 재고를 되돌리지 않는다 — 미리보기도 같은 행을 알아야 건수·재고
     * 요약·환불 예정액이 실행과 맞는다(D26). 체크를 바꾸면 미리보기를 다시 받고 본문에 `unreturned_item_ids` 를 싣는다.
     *
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_complete_preview_carries_unreturned_rows
     */
    it('미회수 체크가 미리보기 본문에 실리고 미리보기를 다시 받는다', async () => {
        await openClaimDetailCompleteHandler(
            { handler: 'sirsoft-ecommerce.openClaimDetailComplete', params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: {} } },
            mockContext,
        );
        vi.useFakeTimers();
        try {
            mockG7Core.api.post.mockClear();

            toggleClaimCompleteUnreturnedHandler(
                { handler: 'sirsoft-ecommerce.toggleClaimCompleteUnreturned', params: { itemId: 55, checked: true } },
                mockContext,
            );
            await vi.advanceTimersByTimeAsync(250);

            const previews = mockG7Core.api.post.mock.calls.filter(([url]: any[]) => String(url).endsWith('/claims/completion-preview'));
            expect(previews).toHaveLength(1);
            expect(previews[0][1].claims[0].unreturned_item_ids).toEqual([55]);
        } finally {
            vi.useRealTimers();
        }
    });

    /**
     * 체크했는데 사유가 비면(공백 포함) 서버가 422 로 막기 전에 멈추고 실패로 끝낸다 — 모달은 남는다.
     *
     * @effects claim_completion_blocks_empty_unreturned_reason
     */
    it('사유가 빈 미회수 행이 있으면 요청하지 않고 실패로 끝난다', async () => {
        await openClaimDetailCompleteHandler(
            { handler: 'sirsoft-ecommerce.openClaimDetailComplete', params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: {} } },
            mockContext,
        );
        mockGlobalState.claimCompleteUnreturned = { '55': '고객 연락 두절', '56': '   ' };
        mockG7Core.api.patch.mockClear();

        await expect(executeClaimCompletionHandler({ handler: 'sirsoft-ecommerce.executeClaimCompletion' }, mockContext)).rejects.toThrow();

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalledWith('미회수로 완료할 상품의 사유를 입력해주세요.');
        expect(mockG7Core.toast.error).not.toHaveBeenCalled();
        expect(mockG7Core.modal.close).not.toHaveBeenCalled();
    });
});

describe('[case:state-setstate-43] 전역 맵 교체는 shallow 여야 한다 — 엔진 기본 deep 병합이 뺀 키를 남긴다', () => {
    /**
     * 엔진과 같은 병합 목(기본 deep)에서, 체크 해제·창 재진입·완료 뒤 초기화가 맵을 실제로 비우는지 한 흐름으로 본다.
     * shallow 없이 set 하면 세 곳 모두 앞 키가 남는다(Chrome 실측 V-M6M7).
     *
     * @scenario claim_type=exchange, claim_stage=final
     * @effects claim_complete_unreturned_uncheck_removes_the_row_under_engine_merge
     */
    it('해제·재진입에서 앞 키가 남지 않는다', async () => {
        const toggle = (itemId: unknown, checked: unknown) => toggleClaimCompleteUnreturnedHandler(
            { handler: 'sirsoft-ecommerce.toggleClaimCompleteUnreturned', params: { itemId, checked } as any },
            mockContext,
        );

        toggle(55, true);
        toggle(56, true);
        toggle(55, false);
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({ '56': '' });

        await openClaimDetailCompleteHandler(
            { handler: 'sirsoft-ecommerce.openClaimDetailComplete', params: { ...DETAIL_TARGET, rows: ITEM_ROWS, completeDefaults: {} } },
            mockContext,
        );
        expect(mockGlobalState.claimCompleteUnreturned).toEqual({});
        expect(mockG7Core.state.set).toHaveBeenCalledWith(expect.objectContaining({ claimCompleteUnreturned: {} }), { merge: 'shallow' });
    });

    /**
     * 수량 확인 창은 「송장만 저장」 과 상태 전이가 한 문맥(`claimItemQuantityContext`)을 쓰고, [취소] 는 창만 닫는다.
     * 상태 전이로 다시 열 때 문맥을 통째로 바꾸지 않으면 앞 창의 `trackingOnly`·`direction` 이 남아
     * [적용] 이 상태 전이 대신 송장 저장(빈 송장이면 행 송장 삭제)으로 나간다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     * @effects claim_item_quantity_context_reopen_drops_tracking_only_mode
     */
    it('송장만 저장 창을 취소하고 상태 전이로 다시 열면 송장 저장 문맥이 남지 않는다', async () => {
        await openClaimItemTrackingOnlyHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemTrackingOnly',
                params: { ...DETAIL_TARGET, itemIds: [55], trackingMode: true, direction: 'redelivery', carrierId: '5', trackingNumber: 'RD-9', rows: ITEM_ROWS },
            },
            mockContext,
        );
        expect(mockGlobalState.claimItemQuantityContext).toMatchObject({ trackingOnly: true, direction: 'redelivery' });

        await openClaimItemQuantityConfirmHandler(
            {
                handler: 'sirsoft-ecommerce.openClaimItemQuantityConfirm',
                params: { ...DETAIL_TARGET, itemIds: [55], status: 'rejected', rejectReason: '개봉 상품입니다.', rows: ITEM_ROWS },
            },
            mockContext,
        );

        expect(mockGlobalState.claimItemQuantityContext.status).toBe('rejected');
        expect(mockGlobalState.claimItemQuantityContext.trackingOnly).not.toBe(true);
        expect(mockGlobalState.claimItemQuantityContext.direction).toBeUndefined();
        expect(mockGlobalState.claimItemQuantityContext.directionLabel).toBeUndefined();
    });
});
