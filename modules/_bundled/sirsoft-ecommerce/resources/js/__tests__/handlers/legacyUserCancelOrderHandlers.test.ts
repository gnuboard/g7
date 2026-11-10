/**
 * legacyUserCancelOrderHandlers 테스트 — 7.0.x 기본 템플릿 복사본의 주문 취소 창 호환
 *
 * @description
 * 7.1 이 사용자 주문 취소를 클레임 신청 흐름으로 바꾸며 예전 취소 핸들러 이름을 등록에서 뺐다.
 * 7.0.x 기본 사용자 템플릿을 복사한 화면은 마이페이지 주문 상세의 「주문 취소」 창 버튼이 반응하지 않았다
 * (템플릿 호환 대장 handler:sirsoft-ecommerce.*UserCancel* — 결정 「호환 처리」, 2026-11-08).
 * - 예전 이름 8개가 handlerMap 에 다시 등록되어 예전 구현으로 이어진다
 * - executeUserCancelOrder: 주문 취소 API endpoint 분기 (회원/비회원) · 항상 items 전송 (7.0.11 시험 승계)
 * - estimateUserRefund: 환불 예상 API endpoint 분기 (회원/비회원) (7.0.11 시험 승계)
 * - 수량·우선순위 변경의 지연 계산 결과가 핸들러가 받은 컨텍스트(모달)에도 들어간다 — 엔진 비공개 전역을 바꿔 끼우지 않는다
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    executeUserCancelOrderHandler,
    estimateUserRefundHandler,
    updateUserCancelQuantityHandler,
    changeUserRefundPriorityHandler,
} from '../../handlers/legacyUserCancelOrderHandlers';

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

    /**
     * @scenario surface=cancel_endpoint
     * @effects legacy_cancel_calls_member_or_guest_endpoint
     */
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

    /**
     * @scenario surface=cancel_endpoint
     * @effects legacy_cancel_calls_member_or_guest_endpoint
     */
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

    /**
     * @scenario surface=cancel_items
     * @effects legacy_cancel_always_sends_selected_items
     */
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

    /**
     * @scenario surface=cancel_items
     * @effects legacy_cancel_always_sends_selected_items
     */
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

    /**
     * @scenario surface=estimate_endpoint
     * @effects legacy_estimate_calls_member_or_guest_endpoint
     */
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

    /**
     * @scenario surface=estimate_endpoint
     * @effects legacy_estimate_calls_member_or_guest_endpoint
     */
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

describe('지연 환불 계산 — 받은 컨텍스트의 모달에 반영', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
        mockGlobalState = {};
        mockOrderDataSource = { data: { order_number: 'ORD-1' } };
        mockLocalState = {
            cancelItems: [
                { id: 10, product_name: 'p', product_option_name: '', thumbnail_url: '', unit_price: 1000,
                  quantity: 3, cancel_quantity: 3, option_status: 'paid', selected: true },
            ],
            refundPriority: 'pg_first',
        };
        mockG7Core.api.post.mockResolvedValue({ success: true, data: { refund_amount: 1000 } });
    });

    const cases: Array<[string, (ctx: any) => void, number]> = [
        ['수량 변경', (ctx) => updateUserCancelQuantityHandler(
            { handler: 'updateUserCancelQuantity', params: { optionId: 10, maxQuantity: 3, orderId: 1, value: 2 } }, ctx), 500],
        ['환불 우선순위 변경', (ctx) => changeUserRefundPriorityHandler(
            { handler: 'changeUserRefundPriority', params: { priority: 'points_first', orderId: 1 } }, ctx), 300],
    ];

    for (const [label, run, delay] of cases) {
        /**
         * @scenario surface=estimate_endpoint
         * @effects legacy_debounced_estimate_reaches_modal_context
         */
        it(`${label} 뒤 계산 결과가 context.setState 로 모달에 들어간다`, async () => {
            const setState = vi.fn();
            run({ setState });
            expect(setState).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(delay);

            expect(mockG7Core.api.post).toHaveBeenCalledTimes(1);
            expect(setState).toHaveBeenCalledWith(expect.objectContaining({ refundLoading: true }));
            expect(setState).toHaveBeenCalledWith(expect.objectContaining({ refundEstimate: { refund_amount: 1000 }, refundLoading: false }));
            vi.useRealTimers();
        });
    }
});

describe('7.0.x 화면이 부르던 예전 취소 핸들러 이름 — handlerMap 재등록', () => {
    /**
     * @scenario surface=handler_registry
     * @effects legacy_user_cancel_handler_names_registered
     */
    it('예전 이름 8개가 모두 등록되어 예전 구현으로 이어진다', async () => {
        const { handlerMap } = await import('../../handlers');
        const legacy = await import('../../handlers/legacyUserCancelOrderHandlers');
        const expected: Record<string, unknown> = {
            initUserCancelItems: legacy.initUserCancelItemsHandler,
            toggleUserCancelItem: legacy.toggleUserCancelItemHandler,
            toggleUserCancelSelectAll: legacy.toggleUserCancelSelectAllHandler,
            updateUserCancelQuantity: legacy.updateUserCancelQuantityHandler,
            estimateUserRefund: legacy.estimateUserRefundHandler,
            changeUserRefundPriority: legacy.changeUserRefundPriorityHandler,
            executeUserCancelOrder: legacy.executeUserCancelOrderHandler,
            clearUserCancelOrderTimers: legacy.clearUserCancelOrderTimers,
        };
        for (const [name, fn] of Object.entries(expected)) {
            expect(typeof fn, name).toBe('function');
            expect((handlerMap as Record<string, unknown>)[name], name).toBe(fn);
        }
    });
});
