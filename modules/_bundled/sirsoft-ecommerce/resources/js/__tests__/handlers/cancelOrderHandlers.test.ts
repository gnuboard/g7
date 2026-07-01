/**
 * cancelOrderHandlers (관리자 주문 취소) 테스트
 *
 * @description
 * executeCancelOrderHandler 가 프론트에서 전체취소를 휴리스틱으로 판단하지 않고
 * 항상 선택 항목을 items(type:partial)로 전송하는지 검증한다.
 * 전체취소 승격은 백엔드 shouldConvertToFullCancel 이 담당하므로, 프론트는 선택 항목만
 * 그대로 보낸다. 종전 라인수+수량 휴리스틱은 단일 항목 주문을 항상 full 로 처리해
 * 수량 축소를 무시하던 결함(MP03 §9 발견#2)이 있었고 유저 핸들러와 판정 기준이 어긋났다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { executeCancelOrderHandler } from '../../handlers/cancelOrderHandlers';

let mockLocalState: Record<string, any> = {};
let mockOrderDataSource: { data?: { options?: any[] } } | null = null;

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
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
    },
    dispatch: vi.fn(),
    t: vi.fn((key: string) => key),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

/**
 * 취소 실행 후 API 에 전달된 body 를 반환합니다.
 */
async function runCancel(): Promise<Record<string, any>> {
    mockG7Core.api.post.mockResolvedValue({ success: true });

    await executeCancelOrderHandler(
        { handler: 'executeCancelOrder', params: { orderId: 'ORD-1' } },
        {} as any
    );

    expect(mockG7Core.api.post).toHaveBeenCalledTimes(1);

    return mockG7Core.api.post.mock.calls[0][1] as Record<string, any>;
}

describe('executeCancelOrderHandler — 항상 items 전송 (백엔드 FULL 승격 위임)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {
            cancelReason: 'change_mind',
            refundPriority: 'pg_first',
        };
        mockOrderDataSource = null;
        (window as any).G7Core = mockG7Core;
    });

    it('단일 항목 주문 — 수량 축소 시 type:partial + items 전송 (결함 #2 회귀)', async () => {
        mockOrderDataSource = {
            data: { options: [{ id: 100, quantity: 9, option_status: 'payment_complete' }] },
        };
        mockLocalState.cancelItems = [{ id: 100, quantity: 9, cancel_quantity: 3 }];

        const body = await runCancel();

        expect(body.type).toBe('partial');
        expect(body.items).toEqual([{ order_option_id: 100, cancel_quantity: 3 }]);
    });

    it('단일 항목 주문 — 전량 취소도 type:partial + items 전송 (백엔드가 FULL 승격)', async () => {
        mockOrderDataSource = {
            data: { options: [{ id: 100, quantity: 9, option_status: 'payment_complete' }] },
        };
        mockLocalState.cancelItems = [{ id: 100, quantity: 9, cancel_quantity: 9 }];

        const body = await runCancel();

        expect(body.type).toBe('partial');
        expect(body.items).toEqual([{ order_option_id: 100, cancel_quantity: 9 }]);
    });

    it('다중 항목 — 모든 항목 전량 취소도 items 전량 전송 (프론트 full 판정 없음)', async () => {
        mockOrderDataSource = {
            data: {
                options: [
                    { id: 100, quantity: 2, option_status: 'payment_complete' },
                    { id: 101, quantity: 4, option_status: 'payment_complete' },
                ],
            },
        };
        mockLocalState.cancelItems = [
            { id: 100, quantity: 2, cancel_quantity: 2 },
            { id: 101, quantity: 4, cancel_quantity: 4 },
        ];

        const body = await runCancel();

        expect(body.type).toBe('partial');
        expect(body.items).toEqual([
            { order_option_id: 100, cancel_quantity: 2 },
            { order_option_id: 101, cancel_quantity: 4 },
        ]);
    });

    it('다중 항목 중 일부 라인만 선택 — 선택 항목만 items 로 전송', async () => {
        mockOrderDataSource = {
            data: {
                options: [
                    { id: 100, quantity: 2, option_status: 'payment_complete' },
                    { id: 101, quantity: 4, option_status: 'payment_complete' },
                    { id: 102, quantity: 1, option_status: 'payment_complete' },
                ],
            },
        };
        // 3항목 중 2항목만 선택 (전량) — 종전엔 부분집합 전량을 full 로 오판해 items 누락 → 주문 전체 취소
        mockLocalState.cancelItems = [
            { id: 100, quantity: 2, cancel_quantity: 2 },
            { id: 101, quantity: 4, cancel_quantity: 4 },
        ];

        const body = await runCancel();

        expect(body.type).toBe('partial');
        expect(body.items).toEqual([
            { order_option_id: 100, cancel_quantity: 2 },
            { order_option_id: 101, cancel_quantity: 4 },
        ]);
        // 선택하지 않은 102 는 items 에 포함되면 안 된다
        expect(body.items.find((i: any) => i.order_option_id === 102)).toBeUndefined();
    });

    it('cancel_pg / refund_priority 는 body 에 그대로 포함된다', async () => {
        mockOrderDataSource = {
            data: { options: [{ id: 100, quantity: 2, option_status: 'payment_complete' }] },
        };
        mockLocalState.cancelItems = [{ id: 100, quantity: 2, cancel_quantity: 1 }];
        mockLocalState.cancelPg = false;
        mockLocalState.refundPriority = 'points_first';

        const body = await runCancel();

        expect(body.cancel_pg).toBe(false);
        expect(body.refund_priority).toBe('points_first');
    });
});
