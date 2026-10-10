/**
 * orderItemSelectionHandlers 테스트 (#599 후속 — 옛 취소 모달 삭제 뒤 남은 선택 축)
 *
 * @description
 * 주문상세 상품 체크박스는 클레임 신청 버튼이 작성 화면으로 넘길 상품을 정한다. 옛 주문 취소
 * 모달과 함께 삭제되면 안 되는 두 핸들러(개별 토글 · 전체 토글)가 여기 남는다.
 *  - 개별 토글은 selectedItemIds 를 넣고 빼며, 취소되지 않은 상품이 전부 골라지면 selectAllItems 가 켜진다
 *  - 전체 토글은 취소된 상품을 빼고 전부 고르거나 전부 푼다
 *  - optionId 가 없으면 아무것도 바꾸지 않는다
 *
 * @scenario claim_type=exchange, entry=none, screen=order_detail
 * @effects order_detail_item_selection_feeds_claim_entry
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { toggleItemSelectionHandler, toggleSelectAllItemsHandler } from '../../handlers/orderItemSelectionHandlers';

let mockLocalState: Record<string, any> = {};

const ORDER = {
    data: {
        id: 120,
        options: [
            { id: 310, option_status: 'delivered' },
            { id: 311, option_status: 'delivered' },
            { id: 312, option_status: 'cancelled' },
        ],
    },
};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
    },
    dataSource: { get: vi.fn((id: string) => (id === 'order' ? ORDER : null)) },
};

describe('orderItemSelectionHandlers', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {};
        (window as any).G7Core = mockG7Core;
    });

    /**
     * @scenario claim_type=exchange, entry=none, screen=order_detail
     * @effects order_detail_item_selection_feeds_claim_entry
     */
    it('개별 토글 — 넣고 빼며, 취소되지 않은 상품이 전부 골라지면 전체 선택이 켜진다', () => {
        toggleItemSelectionHandler({ handler: 'toggleItemSelection', params: { optionId: 310 } }, {} as any);
        expect(mockLocalState.selectedItemIds).toEqual([310]);
        expect(mockLocalState.selectAllItems).toBe(false);

        toggleItemSelectionHandler({ handler: 'toggleItemSelection', params: { optionId: 311 } }, {} as any);
        expect(mockLocalState.selectedItemIds).toEqual([310, 311]);
        // 취소된 312 는 세지 않는다 — 살아 있는 두 상품이 전부 골라졌으므로 켜진다.
        expect(mockLocalState.selectAllItems).toBe(true);

        toggleItemSelectionHandler({ handler: 'toggleItemSelection', params: { optionId: 310 } }, {} as any);
        expect(mockLocalState.selectedItemIds).toEqual([311]);
        expect(mockLocalState.selectAllItems).toBe(false);
    });

    /**
     * @scenario claim_type=exchange, entry=none, screen=order_detail
     * @effects order_detail_item_selection_feeds_claim_entry
     */
    it('전체 토글 — 취소된 상품을 빼고 전부 고르거나 전부 푼다', () => {
        toggleSelectAllItemsHandler({ handler: 'toggleSelectAllItems' }, {} as any);
        expect(mockLocalState.selectedItemIds).toEqual([310, 311]);
        expect(mockLocalState.selectAllItems).toBe(true);

        toggleSelectAllItemsHandler({ handler: 'toggleSelectAllItems' }, {} as any);
        expect(mockLocalState.selectedItemIds).toEqual([]);
        expect(mockLocalState.selectAllItems).toBe(false);
    });

    /**
     * @scenario claim_type=exchange, entry=none, screen=order_detail
     * @effects order_detail_item_selection_feeds_claim_entry
     */
    it('optionId 가 없으면 상태를 바꾸지 않는다', () => {
        toggleItemSelectionHandler({ handler: 'toggleItemSelection', params: {} as any }, {} as any);
        expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
    });
});
