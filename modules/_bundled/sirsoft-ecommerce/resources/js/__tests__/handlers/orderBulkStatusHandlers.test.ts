/**
 * 주문 일괄 상태 변경 핸들러 테스트
 *
 * @description
 * - buildOrderBulkConfirmData: delivered 상태에서 운송장 필수 해제 검증
 * - processOrderDetailBulkChange: carrier_id 키 전송 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { buildOrderBulkConfirmDataHandler } from '../../handlers/orderHandlers';
import { processOrderDetailBulkChangeHandler } from '../../handlers/orderDetailHandlers';

// G7Core mock
let mockGlobalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        get: () => mockGlobalState,
        getLocal: () => ({}),
        set: vi.fn((updates: Record<string, any>) => {
            mockGlobalState = { ...mockGlobalState, ...updates };
        }),
        setLocal: vi.fn(),
    },
    toast: {
        success: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
    modal: {
        open: vi.fn(),
        close: vi.fn(),
    },
    t: vi.fn((key: string) => key),
    api: {
        patch: vi.fn().mockResolvedValue({ success: true }),
    },
    dataSource: {
        get: vi.fn(),
        refetch: vi.fn(),
    },
    createLogger: () => ({
        log: vi.fn(),
        warn: vi.fn(),
        error: vi.fn(),
    }),
};

const mockContext = {} as any;

beforeEach(() => {
    mockGlobalState = {};
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
});

// ========== buildOrderBulkConfirmData 테스트 ==========

describe('buildOrderBulkConfirmDataHandler - delivered 배송정보 필수 해제', () => {
    it('delivered 상태 선택 시 운송장 없이도 모달이 열림', () => {
        mockGlobalState = {
            bulkSelectedItems: [1, 2],
            bulkOrderStatus: 'delivered',
            bulkCourier: '',
            bulkTrackingNumber: '',
        };

        buildOrderBulkConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildOrderBulkConfirmData' } as any,
            mockContext
        );

        // 경고 toast가 호출되지 않아야 함
        expect(mockG7Core.toast.warning).not.toHaveBeenCalled();
        // 모달이 열려야 함
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_bulk_confirm');
        // bulkConfirmData가 저장되어야 함
        expect(mockGlobalState.bulkConfirmData).toBeDefined();
        expect(mockGlobalState.bulkConfirmData.orderStatus).toBe('delivered');
    });

    it('delivered 상태 + 운송장 입력 시에도 정상 동작', () => {
        mockGlobalState = {
            bulkSelectedItems: [1],
            bulkOrderStatus: 'delivered',
            bulkCourier: '1',
            bulkTrackingNumber: 'TRACK123',
        };

        buildOrderBulkConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildOrderBulkConfirmData' } as any,
            mockContext
        );

        expect(mockG7Core.toast.warning).not.toHaveBeenCalled();
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_bulk_confirm');
        expect(mockGlobalState.bulkConfirmData.courierId).toBe('1');
    });

    it('shipping 상태 선택 시 운송장 없으면 경고 toast', () => {
        mockGlobalState = {
            bulkSelectedItems: [1],
            bulkOrderStatus: 'shipping',
            bulkCourier: '',
            bulkTrackingNumber: '',
        };

        buildOrderBulkConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildOrderBulkConfirmData' } as any,
            mockContext
        );

        // 경고 toast가 호출되어야 함
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
        // 모달은 열리지 않아야 함
        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
    });

    it('shipping_ready 상태 선택 시 운송장 없으면 경고 toast', () => {
        mockGlobalState = {
            bulkSelectedItems: [1],
            bulkOrderStatus: 'shipping_ready',
            bulkCourier: '',
            bulkTrackingNumber: '',
        };

        buildOrderBulkConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildOrderBulkConfirmData' } as any,
            mockContext
        );

        expect(mockG7Core.toast.warning).toHaveBeenCalled();
        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
    });
});

// ========== processOrderDetailBulkChange 테스트 ==========

describe('processOrderDetailBulkChangeHandler - carrier_id 키 전송', () => {
    it('carrier_id 키로 API에 전송됨 (carrier 아님)', async () => {
        mockG7Core.dataSource.get.mockReturnValue({
            data: {
                options: [
                    { id: 10, quantity: 3 },
                ],
            },
        });

        await processOrderDetailBulkChangeHandler(
            {
                handler: 'sirsoft-ecommerce.processOrderDetailBulkChange',
                params: {
                    orderId: 'ORD-001',
                    selectedProducts: [10],
                    batchOrderStatus: 'shipping',
                    batchCarrierId: '5',
                    batchTrackingNumber: 'TRACK456',
                },
            } as any,
            mockContext
        );

        // API 호출 확인
        expect(mockG7Core.api.patch).toHaveBeenCalled();
        const [url, body] = mockG7Core.api.patch.mock.calls[0];

        expect(url).toContain('ORD-001');
        // carrier_id 키로 전송되어야 함 (carrier 아님)
        expect(body.carrier_id).toBe('5');
        expect(body.carrier).toBeUndefined();
        expect(body.tracking_number).toBe('TRACK456');
    });

    it('carrier 미입력 시 body에 carrier_id가 포함되지 않음', async () => {
        mockG7Core.dataSource.get.mockReturnValue({
            data: {
                options: [
                    { id: 10, quantity: 2 },
                ],
            },
        });

        await processOrderDetailBulkChangeHandler(
            {
                handler: 'sirsoft-ecommerce.processOrderDetailBulkChange',
                params: {
                    orderId: 'ORD-002',
                    selectedProducts: [10],
                    batchOrderStatus: 'delivered',
                },
            } as any,
            mockContext
        );

        expect(mockG7Core.api.patch).toHaveBeenCalled();
        const [, body] = mockG7Core.api.patch.mock.calls[0];

        expect(body.carrier_id).toBeUndefined();
        expect(body.carrier).toBeUndefined();
        expect(body.status).toBe('delivered');
    });
});
