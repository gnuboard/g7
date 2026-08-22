/**
 * userCancelOrderHandlers 테스트
 *
 * @description
 * - executeUserCancelOrder: 주문 취소 API endpoint 분기 (회원/비회원)
 * - estimateUserRefund: 환불 예상 API endpoint 분기 (회원/비회원)
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    executeUserCancelOrderHandler,
    estimateUserRefundHandler,
} from '../../handlers/userCancelOrderHandlers';

let mockLocalState: Record<string, any> = {};
let mockGlobalState: Record<string, any> = {};
let mockOrderDataSource: { data?: { order_number?: string } } | null = null;

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
        get: vi.fn((key: string) => (key === '_global' ? mockGlobalState : undefined)),
    },
    dataSource: {
        get: vi.fn((id: string) => (id === 'order' ? mockOrderDataSource : null)),
    },
    api: {
        post: vi.fn(),
    },
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        warning: vi.fn(),
    },
    modal: {
        close: vi.fn(),
        open: vi.fn(),
    },
    dispatch: vi.fn(),
    t: vi.fn((key: string) => key),
    createLogger: () => ({
        log: vi.fn(),
        warn: vi.fn(),
        error: vi.fn(),
    }),
};

describe('executeUserCancelOrderHandler — endpoint 분기', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {
            cancelItems: [
                {
                    id: 10,
                    product_name: 'p',
                    product_option_name: '',
                    thumbnail_url: '',
                    unit_price: 1000,
                    quantity: 1,
                    cancel_quantity: 1,
                    option_status: 'paid',
                    selected: true,
                },
            ],
            cancelReason: 'change_mind',
            refundPriority: 'pg_first',
            isCancelling: false,
            cancelError: null,
        };
        mockGlobalState = {};
        mockOrderDataSource = null;
        (window as any).G7Core = mockG7Core;
    });

    it('비회원 컨텍스트 — _global.guestOrderToken + order.data.order_number 존재 시 guest endpoint 호출 + X-Guest-Order-Token 헤더 첨부', async () => {
        mockGlobalState = { guestOrderToken: 'guest-token-abc' };
        mockOrderDataSource = { data: { order_number: '20260527-123456' } };
        mockG7Core.api.post.mockResolvedValue({ success: true });

        await executeUserCancelOrderHandler(
            { handler: 'executeUserCancelOrder', params: { orderId: 1 } },
            {} as any
        );

        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/guest/orders/20260527-123456/cancel',
            expect.any(Object),
            { headers: { 'X-Guest-Order-Token': 'guest-token-abc' } }
        );
    });

    it('회원 컨텍스트 — guestOrderToken 없으면 user endpoint 호출 + 토큰 헤더 미첨부 (회원 회귀 차단)', async () => {
        mockGlobalState = {};
        mockOrderDataSource = { data: { order_number: '20260527-123456' } };
        mockG7Core.api.post.mockResolvedValue({ success: true });

        await executeUserCancelOrderHandler(
            { handler: 'executeUserCancelOrder', params: { orderId: 1 } },
            {} as any
        );

        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/user/orders/1/cancel',
            expect.any(Object),
            undefined
        );
    });
});

describe('executeUserCancelOrderHandler — 항상 items 전송', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockGlobalState = {};
        mockOrderDataSource = { data: { order_number: '20260527-123456' } };
        mockLocalState = {
            cancelReason: 'change_mind',
            refundPriority: 'pg_first',
        };
        (window as any).G7Core = mockG7Core;
    });

    it('선택한 부분집합만 items 로 전송한다 (다중항목 중 일부 선택 — 전체취소 오처리 차단)', async () => {
        mockLocalState.cancelItems = [
            { id: 10, quantity: 2, cancel_quantity: 2, selected: true },
            { id: 11, quantity: 3, cancel_quantity: 3, selected: true },
            { id: 12, quantity: 1, cancel_quantity: 1, selected: false }, // 미선택
        ];
        mockG7Core.api.post.mockResolvedValue({ success: true });

        await executeUserCancelOrderHandler(
            { handler: 'executeUserCancelOrder', params: { orderId: 1 } },
            {} as any
        );

        const body = mockG7Core.api.post.mock.calls[0][1] as Record<string, any>;
        expect(body.items).toEqual([
            { order_option_id: 10, cancel_quantity: 2 },
            { order_option_id: 11, cancel_quantity: 3 },
        ]);
    });

    it('단일 항목 전량 선택도 items 로 전송한다 (백엔드가 FULL 승격)', async () => {
        mockLocalState.cancelItems = [
            { id: 10, quantity: 5, cancel_quantity: 5, selected: true },
        ];
        mockG7Core.api.post.mockResolvedValue({ success: true });

        await executeUserCancelOrderHandler(
            { handler: 'executeUserCancelOrder', params: { orderId: 1 } },
            {} as any
        );

        const body = mockG7Core.api.post.mock.calls[0][1] as Record<string, any>;
        expect(body.items).toEqual([{ order_option_id: 10, cancel_quantity: 5 }]);
    });
});

describe('estimateUserRefundHandler — endpoint 분기', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {
            cancelItems: [
                {
                    id: 10,
                    product_name: 'p',
                    product_option_name: '',
                    thumbnail_url: '',
                    unit_price: 1000,
                    quantity: 1,
                    cancel_quantity: 1,
                    option_status: 'paid',
                    selected: true,
                },
            ],
            refundPriority: 'pg_first',
            refundLoading: false,
            refundEstimate: null,
        };
        mockGlobalState = {};
        mockOrderDataSource = null;
        (window as any).G7Core = mockG7Core;
    });

    it('비회원 컨텍스트 — guest endpoint 호출 + X-Guest-Order-Token 헤더 첨부', async () => {
        mockGlobalState = { guestOrderToken: 'guest-token-abc' };
        mockOrderDataSource = { data: { order_number: '20260527-123456' } };
        mockG7Core.api.post.mockResolvedValue({ success: true, data: {} });

        await estimateUserRefundHandler(
            { handler: 'estimateUserRefund', params: { orderId: 1 } },
            {} as any
        );

        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/guest/orders/20260527-123456/estimate-refund',
            expect.any(Object),
            { headers: { 'X-Guest-Order-Token': 'guest-token-abc' } }
        );
    });

    it('회원 컨텍스트 — user endpoint 호출 + 토큰 헤더 미첨부', async () => {
        mockGlobalState = {};
        mockOrderDataSource = { data: { order_number: '20260527-123456' } };
        mockG7Core.api.post.mockResolvedValue({ success: true, data: {} });

        await estimateUserRefundHandler(
            { handler: 'estimateUserRefund', params: { orderId: 1 } },
            {} as any
        );

        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/user/orders/1/estimate-refund',
            expect.any(Object),
            undefined
        );
    });
});
