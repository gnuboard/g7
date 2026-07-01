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
import { buildOrderBulkConfirmDataHandler, executeOrderBulkActionHandler } from '../../handlers/orderHandlers';
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

// ========== 송장번호 fallback 회귀 테스트 (송장 input 버그) ==========
//
// 송장번호 input 은 dataKey="form" 폼 컨텍스트 안의 basic Input 이라, 폼 자동바인딩이
// 값을 _local.form.batchTrackingNumber 로 쓴다. 핸들러는 params(top-level) 를 받으므로
// 경로가 어긋나 빈 값이 도달할 수 있다(Select 인 상태/택배사는 무관). 이를 DOM 직접읽기
// (readBatchTrackingFromDom) + getLocal fallback 으로 보정했다. 이 회귀를 고정한다.

describe('processOrderDetailBulkChangeHandler - 송장번호 fallback (송장 input 버그)', () => {
    it('params.batchTrackingNumber 가 비면 DOM input[name=batchTrackingNumber] 값을 읽어 전송한다', async () => {
        // 화면에 실제 표시된 송장 input (폼 컨텍스트 자동바인딩으로 top-level params 와 어긋난 상황 재현)
        const input = document.createElement('input');
        input.setAttribute('name', 'batchTrackingNumber');
        input.value = '  DOM-TRACK-777  '; // 앞뒤 공백 — trim 되어야 함
        document.body.appendChild(input);

        mockG7Core.dataSource.get.mockReturnValue({
            data: { options: [{ id: 10, quantity: 1 }] },
        });

        await processOrderDetailBulkChangeHandler(
            {
                handler: 'sirsoft-ecommerce.processOrderDetailBulkChange',
                params: {
                    orderId: 'ORD-101',
                    selectedProducts: [10],
                    batchOrderStatus: 'shipping',
                    batchCarrierId: '3',
                    // batchTrackingNumber 누락 — DOM 에서 보정되어야 함
                },
            } as any,
            mockContext
        );

        expect(mockG7Core.api.patch).toHaveBeenCalled();
        const [, body] = mockG7Core.api.patch.mock.calls[0];
        expect(body.carrier_id).toBe('3');
        expect(body.tracking_number).toBe('DOM-TRACK-777');

        document.body.removeChild(input);
    });

    it('params 가 비고 DOM 도 없으면 getLocal(페이지 _local) 의 batchTrackingNumber 로 보정한다', async () => {
        const getLocalSpy = vi
            .spyOn(mockG7Core.state, 'getLocal')
            .mockReturnValue({ batchTrackingNumber: 'LOCAL-TRACK-888' });

        mockG7Core.dataSource.get.mockReturnValue({
            data: { options: [{ id: 11, quantity: 1 }] },
        });

        await processOrderDetailBulkChangeHandler(
            {
                handler: 'sirsoft-ecommerce.processOrderDetailBulkChange',
                params: {
                    orderId: 'ORD-102',
                    selectedProducts: [11],
                    batchOrderStatus: 'shipping',
                    batchCarrierId: '4',
                },
            } as any,
            mockContext
        );

        expect(mockG7Core.api.patch).toHaveBeenCalled();
        const [, body] = mockG7Core.api.patch.mock.calls[0];
        expect(body.tracking_number).toBe('LOCAL-TRACK-888');

        getLocalSpy.mockRestore();
    });

    it('params.batchTrackingNumber 가 있으면 그대로 사용한다 (fallback 우선순위 — params 최우선)', async () => {
        // DOM 값이 있어도 params 가 우선해야 함
        const input = document.createElement('input');
        input.setAttribute('name', 'batchTrackingNumber');
        input.value = 'DOM-SHOULD-NOT-WIN';
        document.body.appendChild(input);

        mockG7Core.dataSource.get.mockReturnValue({
            data: { options: [{ id: 12, quantity: 1 }] },
        });

        await processOrderDetailBulkChangeHandler(
            {
                handler: 'sirsoft-ecommerce.processOrderDetailBulkChange',
                params: {
                    orderId: 'ORD-103',
                    selectedProducts: [12],
                    batchOrderStatus: 'shipping',
                    batchCarrierId: '5',
                    batchTrackingNumber: 'PARAM-TRACK-999',
                },
            } as any,
            mockContext
        );

        const [, body] = mockG7Core.api.patch.mock.calls[0];
        expect(body.tracking_number).toBe('PARAM-TRACK-999');

        document.body.removeChild(input);
    });
});

// ========== 상태 전이 차단(422) 시 서버 검증 메시지 노출 (A30 후속) ==========
//
// 결함: 두 일괄 핸들러의 catch 가 서버 422 응답 body(error.response.data.message)를
//       추출하지 않고 axios raw 메시지("Request failed with status code 422") 또는
//       고정 문구("상태 변경에 실패했습니다.")만 토스트로 표시 → 관리자가 어떤 전이가
//       왜 막혔는지 알 수 없음. 서버는 정확한 메시지를 내려주지만 클라이언트가 버림.

describe('executeOrderBulkActionHandler - 422 서버 검증 메시지 노출 (주문일괄)', () => {
    it('상태 전이 차단(422) 시 axios raw 메시지가 아니라 서버 검증 메시지를 토스트로 표시', async () => {
        mockGlobalState = {
            bulkConfirmData: {
                selectedItems: [101, 102],
                orderStatus: 'payment_complete',
            },
        };

        // axios 가 던지는 422 에러 형태: message 는 raw, 실제 사유는 response.data.message
        const axiosError: any = new Error('Request failed with status code 422');
        axiosError.response = {
            status: 422,
            data: {
                message: '구매확정 상태에서 결제완료 상태로는 변경할 수 없습니다.',
                errors: {
                    order_status: ['구매확정 상태에서 결제완료 상태로는 변경할 수 없습니다.'],
                },
            },
        };
        mockG7Core.api.patch.mockRejectedValueOnce(axiosError);

        await executeOrderBulkActionHandler(
            { handler: 'sirsoft-ecommerce.executeOrderBulkAction', params: {} } as any,
            mockContext
        );

        expect(mockG7Core.toast.error).toHaveBeenCalledWith(
            '구매확정 상태에서 결제완료 상태로는 변경할 수 없습니다.'
        );
        // axios raw 메시지는 노출되지 않아야 한다
        expect(mockG7Core.toast.error).not.toHaveBeenCalledWith(
            'Request failed with status code 422'
        );
    });
});

describe('processOrderDetailBulkChangeHandler - 422 서버 검증 메시지 노출 (옵션일괄)', () => {
    it('상태 전이 차단(422) 시 고정 문구가 아니라 서버 검증 메시지를 토스트로 표시', async () => {
        const axiosError: any = new Error('Request failed with status code 422');
        axiosError.response = {
            status: 422,
            data: {
                message: '구매확정 상태에서 결제완료 상태로는 변경할 수 없습니다.',
                errors: {
                    'items.0.status': ['구매확정 상태에서 결제완료 상태로는 변경할 수 없습니다.'],
                },
            },
        };
        mockG7Core.api.patch.mockRejectedValueOnce(axiosError);

        await processOrderDetailBulkChangeHandler(
            {
                handler: 'sirsoft-ecommerce.processOrderDetailBulkChange',
                params: {
                    orderId: 'ORD-422',
                    selectedProducts: [21],
                    batchOrderStatus: 'payment_complete',
                },
            } as any,
            mockContext
        );

        expect(mockG7Core.toast.error).toHaveBeenCalledWith(
            '구매확정 상태에서 결제완료 상태로는 변경할 수 없습니다.'
        );
        // 고정 문구로 사유가 가려지면 안 된다
        expect(mockG7Core.toast.error).not.toHaveBeenCalledWith(
            'sirsoft-ecommerce.admin.order.detail.handler.bulk_change_failed'
        );
    });
});
