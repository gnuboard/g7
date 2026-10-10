/**
 * 수동 결제 처리 확인 창 핸들러 테스트 (Q13·Q14·Q26)
 *
 * 축 요약(마커 아님, 평문): 확인 창 상태 초기화, 미리보기 세대 카운터, 체크 토글, 실행 본문의 세 값.
 * 테스트별 시나리오와 효과 매핑은 각 it 바로 위 주석에 둔다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    BULK_PREVIEW_URL,
    DEFAULT_MANUAL_PAID_OPTIONS,
    manualPaidRequestFields,
    refreshManualPaidPreview,
    resetManualPaidState,
    setManualPaidOptionHandler,
} from '../../handlers/manualPaidEffectsHandlers';
import { buildOrderBulkConfirmDataHandler, executeOrderBulkActionHandler } from '../../handlers/orderHandlers';
import { buildOrderDetailBulkConfirmDataHandler, processOrderDetailBulkChangeHandler, updateChangeQuantityHandler } from '../../handlers/orderDetailHandlers';

let state: Record<string, any> = {};
let local: Record<string, any> = {};

const G7Core = {
    state: {
        get: () => state,
        getLocal: () => local,
        set: vi.fn((updates: Record<string, any>) => {
            state = { ...state, ...updates };
        }),
        setLocal: vi.fn((updates: Record<string, any>) => {
            local = { ...local, ...updates };
        }),
    },
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    t: vi.fn((key: string) => key),
    api: {
        post: vi.fn(),
        patch: vi.fn().mockResolvedValue({ success: true, data: { changed_count: 1 } }),
    },
    dataSource: { get: vi.fn(), refetch: vi.fn() },
    dispatch: vi.fn(),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

beforeEach(() => {
    state = {};
    local = {};
    vi.clearAllMocks();
    (window as any).G7Core = G7Core;
});

describe('manualPaidEffectsHandlers', () => {
    /**
     * 창을 열 때마다 세 체크를 모두 켠 채 다시 시작한다 — 앞 창에서 끈 체크가 새지 않는다.
     *
     * @effects manual_paid_transition_applies_three_items_by_default
     */
    it('resetManualPaidState 가 세 체크를 모두 켜고 미리보기를 비운다', () => {
        state = { manualPaidOptions: { apply_stock_deduction: false, apply_mileage_deduction: false, record_paid_amount: false }, manualPaidPreview: { x: 1 } };

        resetManualPaidState();

        expect(state.manualPaidOptions).toEqual(DEFAULT_MANUAL_PAID_OPTIONS);
        expect(state.manualPaidPreview).toBeNull();
        expect(state.manualPaidPreviewError).toBeNull();
        expect(G7Core.state.set).toHaveBeenCalledWith(expect.any(Object), { merge: 'shallow' });
    });

    /**
     * 늦게 도착한 옛 응답은 버린다 — 화면이 옛 값을 그리면 실행과 갈린다.
     *
     * @effects preview_matches_execution
     */
    it('refreshManualPaidPreview 는 세대 카운터로 옛 응답을 무시한다', async () => {
        let resolveFirst: (v: any) => void = () => {};
        G7Core.api.post
            .mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }))
            .mockResolvedValueOnce({ data: { summary: { any_applicable: true, tag: 'new' } } });

        const first = refreshManualPaidPreview({ kind: 'orders', body: { ids: [1], order_status: 'payment_complete' } });
        const second = await refreshManualPaidPreview({ kind: 'orders', body: { ids: [1], order_status: 'payment_complete' } });
        resolveFirst({ data: { summary: { tag: 'old' } } });

        expect(second).toBe(true);
        expect(await first).toBe(false);
        expect(state.manualPaidPreview.summary.tag).toBe('new');
        expect(G7Core.api.post).toHaveBeenCalledWith(BULK_PREVIEW_URL, { ids: [1], order_status: 'payment_complete' });
        expect(state.manualPaidPreviewLoading).toBe(false);
    });

    /**
     * 실패하면 서버 메시지를 오류로 남긴다 — 확인 버튼이 잠긴다.
     *
     * @effects preview_matches_execution
     */
    it('미리보기 실패 시 서버 메시지를 manualPaidPreviewError 에 둔다', async () => {
        G7Core.api.post.mockRejectedValueOnce({ response: { data: { message: '전이할 수 없습니다.' } } });

        const ok = await refreshManualPaidPreview({ kind: 'options', orderNumber: 'ORD-1', body: { items: [], status: 'payment_complete' } });

        expect(ok).toBe(false);
        expect(state.manualPaidPreviewError).toBe('전이할 수 없습니다.');
        expect(state.manualPaidPreview).toBeNull();
        expect(G7Core.api.post).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/admin/orders/ORD-1/options/bulk-status/manual-paid-preview',
            { items: [], status: 'payment_complete' },
        );
    });

    /**
     * 체크 토글은 문자열 "false" 도 false 로 받는다.
     *
     * @effects unchecked_item_is_skipped_only
     */
    it('setManualPaidOptionHandler 가 한 항목만 바꾼다', () => {
        resetManualPaidState();

        setManualPaidOptionHandler({ handler: 'x', params: { optionKey: 'apply_mileage_deduction', value: 'false' } });
        expect(state.manualPaidOptions).toEqual({ ...DEFAULT_MANUAL_PAID_OPTIONS, apply_mileage_deduction: false });

        setManualPaidOptionHandler({ handler: 'x', params: { optionKey: 'unknown', value: false } });
        expect(state.manualPaidOptions).toEqual({ ...DEFAULT_MANUAL_PAID_OPTIONS, apply_mileage_deduction: false });
    });

    /**
     * 실행 본문에는 판매 상태로 가는 요청에만 세 값을 싣는다.
     *
     * @effects omitted_flags_default_to_true
     */
    it('manualPaidRequestFields 는 판매 상태에만 세 값을 낸다', () => {
        state = { manualPaidOptions: { ...DEFAULT_MANUAL_PAID_OPTIONS, record_paid_amount: false } };

        expect(manualPaidRequestFields('preparing')).toEqual({ apply_stock_deduction: true, apply_mileage_deduction: true, record_paid_amount: false });
        expect(manualPaidRequestFields('pending_payment')).toEqual({});
    });
});

describe('목록 일괄변경 — 확인 창과 실행', () => {
    /**
     * 판매 상태면 창을 연 뒤 미리보기를 1회 부르고, 그 밖이면 부르지 않는다.
     *
     * @effects not_applicable_items_are_hidden_in_preview
     */
    it('buildOrderBulkConfirmData 가 판매 상태에서만 미리보기를 부른다', async () => {
        G7Core.api.post.mockResolvedValue({ data: { summary: {} } });
        state = { bulkSelectedItems: [3, 4], bulkOrderStatus: 'payment_complete' };

        await buildOrderBulkConfirmDataHandler({ handler: 'x' } as any, {} as any);

        expect(G7Core.modal.open).toHaveBeenCalledWith('modal_bulk_confirm');
        expect(G7Core.api.post).toHaveBeenCalledTimes(1);
        expect(G7Core.api.post).toHaveBeenCalledWith(BULK_PREVIEW_URL, { ids: [3, 4], order_status: 'payment_complete' });

        G7Core.api.post.mockClear();
        state = { bulkSelectedItems: [3], bulkOrderStatus: 'pending_payment' };
        await buildOrderBulkConfirmDataHandler({ handler: 'x' } as any, {} as any);
        expect(G7Core.api.post).not.toHaveBeenCalled();
    });

    /**
     * 끈 항목은 실행 본문에 false 로 실린다.
     *
     * @effects unchecked_item_is_skipped_only
     */
    it('executeOrderBulkAction 이 끈 항목을 false 로 보낸다', async () => {
        state = {
            bulkConfirmData: { selectedItems: [9], orderStatus: 'payment_complete' },
            manualPaidOptions: { ...DEFAULT_MANUAL_PAID_OPTIONS, apply_stock_deduction: false },
        };

        await executeOrderBulkActionHandler({ handler: 'x' } as any, {} as any);

        expect(G7Core.api.patch).toHaveBeenCalledWith('/api/modules/sirsoft-ecommerce/admin/orders/bulk', {
            ids: [9],
            order_status: 'payment_complete',
            apply_stock_deduction: false,
            apply_mileage_deduction: true,
            record_paid_amount: true,
        });
    });
});

describe('주문상세 상품 일괄변경 — 확인 창과 실행', () => {
    const order = { order_number: 'ORD-7', options: [{ id: 11, quantity: 2, option_status: 'pending_order' }] };

    /**
     * 수량을 줄이면 부모 파생 상태가 바뀌므로 미리보기를 다시 부른다 — 바뀐 수량으로.
     *
     * @effects option_bulk_parent_sync_is_atomic_with_effects
     */
    it('창을 열 때와 수량을 바꿀 때 미리보기를 부른다', async () => {
        G7Core.dataSource.get.mockReturnValue({ data: order });
        G7Core.api.post.mockResolvedValue({ data: { summary: {} } });

        await buildOrderDetailBulkConfirmDataHandler({ handler: 'x', params: { selectedProducts: [11], batchOrderStatus: 'payment_complete' } } as any, {} as any);
        expect(G7Core.api.post).toHaveBeenLastCalledWith(
            '/api/modules/sirsoft-ecommerce/admin/orders/ORD-7/options/bulk-status/manual-paid-preview',
            { items: [{ option_id: 11, quantity: 2 }], status: 'payment_complete' },
        );

        updateChangeQuantityHandler({ handler: 'x', params: { optionId: 11, maxQuantity: 2, value: 1 } } as any, {} as any);
        await Promise.resolve();
        expect(G7Core.api.post).toHaveBeenLastCalledWith(
            '/api/modules/sirsoft-ecommerce/admin/orders/ORD-7/options/bulk-status/manual-paid-preview',
            { items: [{ option_id: 11, quantity: 1 }], status: 'payment_complete' },
        );
    });

    /**
     * 실행 본문에 세 값이 실린다.
     *
     * @effects unchecked_item_is_skipped_only
     */
    it('processOrderDetailBulkChange 가 세 값을 보낸다', async () => {
        G7Core.dataSource.get.mockReturnValue({ data: order });
        state = { manualPaidOptions: { ...DEFAULT_MANUAL_PAID_OPTIONS, apply_mileage_deduction: false } };

        await processOrderDetailBulkChangeHandler({
            handler: 'x',
            params: { orderId: 'ORD-7', selectedProducts: [11], batchOrderStatus: 'payment_complete', changeQuantities: { 11: 2 } },
        } as any, {} as any);

        expect(G7Core.api.patch).toHaveBeenCalledWith('/api/modules/sirsoft-ecommerce/admin/orders/ORD-7/options/bulk-status', {
            items: [{ option_id: 11, quantity: 2 }],
            status: 'payment_complete',
            apply_stock_deduction: true,
            apply_mileage_deduction: false,
            record_paid_amount: true,
        });
    });
});
