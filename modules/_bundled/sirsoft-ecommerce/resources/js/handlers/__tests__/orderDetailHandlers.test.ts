/**
 * 주문 상세 핸들러 테스트
 *
 * orderDetailHandlers의 동작을 검증합니다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    initOrderDetailFormHandler,
    toggleProductSelectionHandler,
    toggleAllProductsHandler,
    buildOrderDetailBulkConfirmDataHandler,
    processOrderDetailBulkChangeHandler,
    saveAdminMemoHandler,
    updateChangeQuantityHandler,
} from '../orderDetailHandlers';
import type { ActionContext } from '../../types';

describe('orderDetailHandlers', () => {
    let mockSetLocal: ReturnType<typeof vi.fn>;
    let mockGetLocal: ReturnType<typeof vi.fn>;
    let mockGet: ReturnType<typeof vi.fn>;
    let mockDataSourceGet: ReturnType<typeof vi.fn>;
    let mockApiPatch: ReturnType<typeof vi.fn>;
    let mockToastSuccess: ReturnType<typeof vi.fn>;
    let mockToastError: ReturnType<typeof vi.fn>;
    let mockDispatch: ReturnType<typeof vi.fn>;
    let mockModalClose: ReturnType<typeof vi.fn>;
    let mockContext: ActionContext;
    let originalG7Core: any;

    const mockOrderData = {
        id: 1,
        recipient_name: '홍길동',
        recipient_phone: '010-1234-5678',
        recipient_tel: '02-1234-5678',
        recipient_zipcode: '12345',
        recipient_address: '서울특별시 강남구',
        recipient_detail_address: '역삼동 123-45',
        delivery_memo: '부재시 경비실',
        admin_memo: '테스트 메모',
        options: [
            { id: 1, quantity: 3, product_name: '상품A', product_option_name: '옵션1', sku: 'SKU-001', unit_price: 10000, option_status: 'payment_complete', product_snapshot: { thumbnail_url: '/img/a.jpg', original_price: 12000 } },
            { id: 2, quantity: 2, product_name: '상품B', product_option_name: '', sku: 'SKU-002', unit_price: 20000, option_status: 'preparing', product_snapshot: {} },
            { id: 3, quantity: 1, product_name: '상품C', product_option_name: '옵션3', sku: '', unit_price: 5000, option_status: 'payment_complete', product_snapshot: { thumbnail_url: '/img/c.jpg' } },
        ],
    };

    beforeEach(() => {
        mockSetLocal = vi.fn();
        mockGetLocal = vi.fn().mockReturnValue({});
        mockGet = vi.fn().mockReturnValue({ data: mockOrderData });
        mockDataSourceGet = vi.fn().mockReturnValue({ data: mockOrderData });
        mockApiPatch = vi.fn();
        mockToastSuccess = vi.fn();
        mockToastError = vi.fn();
        mockDispatch = vi.fn();
        mockModalClose = vi.fn();
        mockContext = {
            setLocalState: vi.fn(),
            getLocalState: vi.fn().mockReturnValue({}),
        };

        originalG7Core = (window as any).G7Core;

        (window as any).G7Core = {
            state: {
                setLocal: mockSetLocal,
                getLocal: mockGetLocal,
                get: mockGet,
            },
            dataSource: {
                get: mockDataSourceGet,
            },
            api: { patch: mockApiPatch },
            toast: { success: mockToastSuccess, error: mockToastError },
            dispatch: mockDispatch,
            modal: { close: mockModalClose },
            t: vi.fn((key: string, params?: any) => key),
        };
    });

    afterEach(() => {
        (window as any).G7Core = originalG7Core;
        vi.restoreAllMocks();
    });

    // ========== initOrderDetailFormHandler ==========

    describe('initOrderDetailFormHandler', () => {
        it('order 데이터에서 수취인 정보를 폼에 바인딩해야 한다', () => {
            mockGetLocal.mockReturnValue({ order: { data: mockOrderData } });

            initOrderDetailFormHandler({ handler: 'test' }, mockContext);

            expect(mockSetLocal).toHaveBeenCalledWith({
                form: {
                    recipient_name: '홍길동',
                    recipient_phone: '010-1234-5678',
                    recipient_tel: '02-1234-5678',
                    recipient_zipcode: '12345',
                    recipient_address: '서울특별시 강남구',
                    recipient_detail_address: '역삼동 123-45',
                    delivery_memo: '부재시 경비실',
                    admin_memo: '테스트 메모',
                },
            });
        });

        it('G7Core.state가 없으면 아무것도 하지 않아야 한다', () => {
            (window as any).G7Core = {};

            initOrderDetailFormHandler({ handler: 'test' }, mockContext);

            expect(mockSetLocal).not.toHaveBeenCalled();
        });

        it('order 데이터가 없으면 아무것도 하지 않아야 한다', () => {
            mockGetLocal.mockReturnValue({});
            mockDataSourceGet.mockReturnValue(undefined);

            initOrderDetailFormHandler({ handler: 'test' }, mockContext);

            expect(mockSetLocal).not.toHaveBeenCalled();
        });

        it('onLoaded context.data를 우선 사용해야 한다', () => {
            // Given: context.data.data에 데이터가 있고, dataSource에는 다른 데이터가 있는 경우
            const contextOrderData = { ...mockOrderData, recipient_name: '컨텍스트수령인' };
            const contextWithData = { ...mockContext, data: { data: contextOrderData } } as any;
            mockDataSourceGet.mockReturnValue({ data: { ...mockOrderData, recipient_name: '데이터소스수령인' } });

            // When
            initOrderDetailFormHandler({ handler: 'test' }, contextWithData);

            // Then: context.data.data의 값이 사용됨
            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({
                    form: expect.objectContaining({
                        recipient_name: '컨텍스트수령인',
                    }),
                })
            );
        });
    });

    // ========== toggleProductSelectionHandler ==========

    describe('toggleProductSelectionHandler', () => {
        it('선택되지 않은 옵션을 선택하면 배열에 추가해야 한다', () => {
            mockGetLocal.mockReturnValue({ selectedProducts: [1, 2] });

            toggleProductSelectionHandler(
                { handler: 'test', params: { optionId: 3 } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({ selectedProducts: [1, 2, 3] })
            );
        });

        it('이미 선택된 옵션을 토글하면 배열에서 제거해야 한다', () => {
            mockGetLocal.mockReturnValue({ selectedProducts: [1, 2, 3] });

            toggleProductSelectionHandler(
                { handler: 'test', params: { optionId: 2 } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({ selectedProducts: [1, 3] })
            );
        });

        it('전체 선택 시 selectAll이 true여야 한다', () => {
            mockGetLocal.mockReturnValue({ selectedProducts: [1, 2] });

            toggleProductSelectionHandler(
                { handler: 'test', params: { optionId: 3 } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({ selectAll: true })
            );
        });

        it('optionId가 없으면 아무것도 하지 않아야 한다', () => {
            toggleProductSelectionHandler(
                { handler: 'test', params: {} as any },
                mockContext
            );

            expect(mockSetLocal).not.toHaveBeenCalled();
        });
    });

    // ========== toggleAllProductsHandler ==========

    describe('toggleAllProductsHandler', () => {
        it('전체 선택이 아닌 상태에서 호출하면 모든 옵션을 선택해야 한다', () => {
            mockGetLocal.mockReturnValue({ selectAll: false });

            toggleAllProductsHandler({ handler: 'test' }, mockContext);

            expect(mockSetLocal).toHaveBeenCalledWith({
                selectedProducts: [1, 2, 3],
                selectAll: true,
            });
        });

        it('전체 선택 상태에서 호출하면 모든 선택을 해제해야 한다', () => {
            mockGetLocal.mockReturnValue({ selectAll: true });

            toggleAllProductsHandler({ handler: 'test' }, mockContext);

            expect(mockSetLocal).toHaveBeenCalledWith({
                selectedProducts: [],
                selectAll: false,
            });
        });
    });

    // ========== buildOrderDetailBulkConfirmDataHandler ==========

    describe('buildOrderDetailBulkConfirmDataHandler', () => {
        let mockModalOpen: ReturnType<typeof vi.fn>;
        let mockToastWarning: ReturnType<typeof vi.fn>;

        beforeEach(() => {
            mockModalOpen = vi.fn();
            mockToastWarning = vi.fn();
            (window as any).G7Core.modal = { open: mockModalOpen, close: mockModalClose };
            (window as any).G7Core.toast.warning = mockToastWarning;
        });

        it('상태 미선택 시 경고 토스트를 표시해야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1, 2], batchOrderStatus: '' } },
                mockContext
            );

            expect(mockToastWarning).toHaveBeenCalled();
            expect(mockModalOpen).not.toHaveBeenCalled();
        });

        it('선택된 상품이 없으면 경고 토스트를 표시해야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [], batchOrderStatus: 'shipping' } },
                mockContext
            );

            expect(mockToastWarning).toHaveBeenCalled();
            expect(mockModalOpen).not.toHaveBeenCalled();
        });

        it('상태와 선택 항목이 모두 있으면 확인 모달을 열어야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1, 2], batchOrderStatus: 'shipping', batchCarrierId: 'cj', batchTrackingNumber: '123456' } },
                mockContext
            );

            expect(mockToastWarning).not.toHaveBeenCalled();
            expect(mockModalOpen).toHaveBeenCalledWith('modal_batch_change_confirm');
        });

        it('bulkConfirmItems, changeQuantities, batchOrderStatus를 _local에 저장해야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1, 3], batchOrderStatus: 'preparing', batchCarrierId: '', batchTrackingNumber: '' } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({
                    bulkConfirmItems: expect.arrayContaining([
                        expect.objectContaining({ id: 1, product_name: '상품A', quantity: 3, unit_price: 10000 }),
                        expect.objectContaining({ id: 3, product_name: '상품C', quantity: 1, unit_price: 5000 }),
                    ]),
                    changeQuantities: { 1: 3, 3: 1 },
                    batchOrderStatus: 'preparing',
                    batchCarrierId: '',
                    batchTrackingNumber: '',
                })
            );
            expect(mockModalOpen).toHaveBeenCalledWith('modal_batch_change_confirm');
        });

        it('cancelled + cancelPg=false 시 bulkConfirmItems에 저장하고 batch_change_confirm 모달을 열어야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1, 3], batchOrderStatus: 'cancelled', cancelPg: false } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({
                    bulkConfirmItems: expect.arrayContaining([
                        expect.objectContaining({ id: 1, product_name: '상품A' }),
                        expect.objectContaining({ id: 3, product_name: '상품C' }),
                    ]),
                    changeQuantities: { 1: 3, 3: 1 },
                    batchOrderStatus: 'cancelled',
                })
            );
            expect(mockModalOpen).toHaveBeenCalledWith('modal_batch_change_confirm');
        });

        it('cancelled + cancelPg=true 시 cancel_order 모달을 열어야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1], batchOrderStatus: 'cancelled', cancelPg: true } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({
                    cancelItems: expect.arrayContaining([
                        expect.objectContaining({ id: 1 }),
                    ]),
                    cancelPg: true,
                })
            );
            expect(mockModalOpen).toHaveBeenCalledWith('modal_cancel_order');
        });

        it('cancelled + cancelPg 미지정(기본값 true) 시 cancel_order 모달을 열어야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1], batchOrderStatus: 'cancelled' } },
                mockContext
            );

            expect(mockModalOpen).toHaveBeenCalledWith('modal_cancel_order');
        });

        it('cancelled 시 이미 취소된 옵션은 필터링해야 한다', () => {
            // option id=3을 이미 cancelled로 변경
            const orderDataWithCancelled = {
                ...mockOrderData,
                options: [
                    ...mockOrderData.options.slice(0, 2),
                    { ...mockOrderData.options[2], option_status: 'cancelled' },
                ],
            };
            mockDataSourceGet.mockReturnValue({ data: orderDataWithCancelled });

            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1, 3], batchOrderStatus: 'cancelled', cancelPg: false } },
                mockContext
            );

            const setLocalCall = mockSetLocal.mock.calls[0][0];
            expect(setLocalCall.bulkConfirmItems).toHaveLength(1);
            expect(setLocalCall.bulkConfirmItems[0].id).toBe(1);
        });

        it('bulkConfirmItems에 썸네일 URL과 원래 가격을 포함해야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1], batchOrderStatus: 'preparing' } },
                mockContext
            );

            const setLocalCall = mockSetLocal.mock.calls[0][0];
            const item = setLocalCall.bulkConfirmItems[0];
            expect(item.thumbnail_url).toBe('/img/a.jpg');
            expect(item.original_price).toBe(12000);
            expect(item.sku).toBe('SKU-001');
            expect(item.option_status).toBe('payment_complete');
        });
    });

    // ========== processOrderDetailBulkChangeHandler ==========

    describe('processOrderDetailBulkChangeHandler', () => {
        it('선택된 상품이 없으면 API를 호출하지 않아야 한다', async () => {
            await processOrderDetailBulkChangeHandler(
                { handler: 'test', params: { orderId: 1, selectedProducts: [], batchOrderStatus: 'shipped' } },
                mockContext
            );

            expect(mockApiPatch).not.toHaveBeenCalled();
        });

        it('변경할 상태가 없으면 API를 호출하지 않아야 한다', async () => {
            await processOrderDetailBulkChangeHandler(
                { handler: 'test', params: { orderId: 1, selectedProducts: [1], batchOrderStatus: '' } },
                mockContext
            );

            expect(mockApiPatch).not.toHaveBeenCalled();
        });

        it('성공 시 토스트 메시지를 표시하고 상태를 초기화해야 한다', async () => {
            mockApiPatch.mockResolvedValue({
                success: true,
                data: { changed_count: 2 },
            });

            await processOrderDetailBulkChangeHandler(
                {
                    handler: 'test',
                    params: {
                        orderId: 1,
                        selectedProducts: [1, 2],
                        batchOrderStatus: 'shipped',
                        batchCarrierId: 'cj',
                        batchTrackingNumber: '123456',
                        changeQuantities: {},
                    },
                },
                mockContext
            );

            // 구현: body 에 carrier_id / tracking_number 키로 직렬화 (carrier 아님)
            expect(mockApiPatch).toHaveBeenCalledWith(
                '/api/modules/sirsoft-ecommerce/admin/orders/1/options/bulk-status',
                expect.objectContaining({
                    items: [
                        { option_id: 1, quantity: 3 },
                        { option_id: 2, quantity: 2 },
                    ],
                    status: 'shipped',
                    carrier_id: 'cj',
                    tracking_number: '123456',
                })
            );
            expect(mockToastSuccess).toHaveBeenCalled();
            expect(mockModalClose).toHaveBeenCalledWith('modal_batch_change_confirm');
            expect(mockDispatch).toHaveBeenCalledWith(
                expect.objectContaining({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } })
            );
            expect(mockDispatch).toHaveBeenCalledWith(
                expect.objectContaining({ handler: 'refetchDataSource', params: { dataSourceId: 'order_logs' } })
            );
            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({
                    selectedProducts: [],
                    selectAll: false,
                })
            );
        });

        it('실패 시 에러 토스트를 표시해야 한다', async () => {
            mockApiPatch.mockRejectedValue(new Error('Server Error'));

            await processOrderDetailBulkChangeHandler(
                { handler: 'test', params: { orderId: 1, selectedProducts: [1], batchOrderStatus: 'shipped', changeQuantities: {} } },
                mockContext
            );

            expect(mockToastError).toHaveBeenCalled();
        });
    });

    // ========== saveAdminMemoHandler ==========

    describe('saveAdminMemoHandler', () => {
        it('관리자 메모를 저장해야 한다', async () => {
            mockGetLocal.mockReturnValue({
                form: { admin_memo: '새 메모' },
            });
            mockApiPatch.mockResolvedValue({ success: true });

            await saveAdminMemoHandler(
                { handler: 'test', params: { orderId: 1 } },
                mockContext
            );

            expect(mockApiPatch).toHaveBeenCalledWith(
                '/api/modules/sirsoft-ecommerce/admin/orders/1',
                { admin_memo: '새 메모' }
            );
            expect(mockToastSuccess).toHaveBeenCalled();
        });

        it('orderId가 없으면 아무것도 하지 않아야 한다', async () => {
            await saveAdminMemoHandler(
                { handler: 'test', params: {} as any },
                mockContext
            );

            expect(mockApiPatch).not.toHaveBeenCalled();
        });

        it('저장 실패 시 에러 토스트를 표시해야 한다', async () => {
            mockGetLocal.mockReturnValue({
                form: { admin_memo: '메모' },
            });
            mockApiPatch.mockRejectedValue(new Error('Save failed'));

            await saveAdminMemoHandler(
                { handler: 'test', params: { orderId: 1 } },
                mockContext
            );

            expect(mockToastError).toHaveBeenCalled();
        });
    });

    // ========== updateChangeQuantityHandler ==========

    describe('updateChangeQuantityHandler', () => {
        it('changeQuantities 맵에 값을 설정해야 한다', () => {
            mockGetLocal.mockReturnValue({ changeQuantities: {} });

            updateChangeQuantityHandler(
                { handler: 'test', params: { optionId: 1, maxQuantity: 5, value: 3 } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({ changeQuantities: { 1: 3 } })
            );
        });

        it('최소값 1로 클램핑해야 한다', () => {
            mockGetLocal.mockReturnValue({ changeQuantities: {} });

            updateChangeQuantityHandler(
                { handler: 'test', params: { optionId: 1, maxQuantity: 5, value: 0 } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({ changeQuantities: { 1: 1 } })
            );
        });

        it('최대값으로 클램핑해야 한다', () => {
            mockGetLocal.mockReturnValue({ changeQuantities: {} });

            updateChangeQuantityHandler(
                { handler: 'test', params: { optionId: 1, maxQuantity: 3, value: 10 } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({ changeQuantities: { 1: 3 } })
            );
        });

        it('NaN 입력 시 maxQuantity로 폴백해야 한다', () => {
            mockGetLocal.mockReturnValue({ changeQuantities: {} });

            updateChangeQuantityHandler(
                { handler: 'test', params: { optionId: 1, maxQuantity: 5, value: NaN } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({ changeQuantities: { 1: 5 } })
            );
        });

        it('기존 changeQuantities를 유지하며 업데이트해야 한다', () => {
            mockGetLocal.mockReturnValue({ changeQuantities: { 2: 4 } });

            updateChangeQuantityHandler(
                { handler: 'test', params: { optionId: 1, maxQuantity: 5, value: 3 } },
                mockContext
            );

            expect(mockSetLocal).toHaveBeenCalledWith(
                expect.objectContaining({ changeQuantities: { 2: 4, 1: 3 } })
            );
        });

        it('optionId가 없으면 아무것도 하지 않아야 한다', () => {
            updateChangeQuantityHandler(
                { handler: 'test', params: { maxQuantity: 5 } as any },
                mockContext
            );

            expect(mockSetLocal).not.toHaveBeenCalled();
        });

        it('maxQuantity가 없으면 아무것도 하지 않아야 한다', () => {
            updateChangeQuantityHandler(
                { handler: 'test', params: { optionId: 1 } as any },
                mockContext
            );

            expect(mockSetLocal).not.toHaveBeenCalled();
        });
    });

    // ========== processOrderDetailBulkChangeHandler (changeQuantities) ==========

    describe('processOrderDetailBulkChangeHandler - changeQuantities 반영', () => {
        it('changeQuantities가 지정된 경우 해당 수량으로 API를 호출해야 한다', async () => {
            mockApiPatch.mockResolvedValue({
                success: true,
                data: { changed_count: 2 },
            });

            await processOrderDetailBulkChangeHandler(
                {
                    handler: 'test',
                    params: {
                        orderId: 1,
                        selectedProducts: [1, 2],
                        batchOrderStatus: 'shipping',
                        changeQuantities: { 1: 2, 2: 1 },
                    },
                },
                mockContext
            );

            expect(mockApiPatch).toHaveBeenCalledWith(
                '/api/modules/sirsoft-ecommerce/admin/orders/1/options/bulk-status',
                expect.objectContaining({
                    items: [
                        { option_id: 1, quantity: 2 },
                        { option_id: 2, quantity: 1 },
                    ],
                    status: 'shipping',
                })
            );
        });

        it('changeQuantities가 부분적으로 지정된 경우 미지정 옵션은 원본 수량을 사용해야 한다', async () => {
            mockApiPatch.mockResolvedValue({
                success: true,
                data: { changed_count: 2 },
            });

            await processOrderDetailBulkChangeHandler(
                {
                    handler: 'test',
                    params: {
                        orderId: 1,
                        selectedProducts: [1, 2],
                        batchOrderStatus: 'shipping',
                        changeQuantities: { 1: 2 },
                    },
                },
                mockContext
            );

            expect(mockApiPatch).toHaveBeenCalledWith(
                '/api/modules/sirsoft-ecommerce/admin/orders/1/options/bulk-status',
                expect.objectContaining({
                    items: [
                        { option_id: 1, quantity: 2 },
                        { option_id: 2, quantity: 2 },
                    ],
                })
            );
        });
    });
});
