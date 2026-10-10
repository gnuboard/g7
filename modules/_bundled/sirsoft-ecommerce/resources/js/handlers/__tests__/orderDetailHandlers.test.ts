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
        /**
         * @scenario handler=init_form
         * @effects init_form_binds_recipient_fields_into_local_form
         */
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

        /**
         * @scenario handler=init_form
         * @effects init_form_writes_nothing_without_order_data
         */
        it('G7Core.state가 없으면 아무것도 하지 않아야 한다', () => {
            (window as any).G7Core = {};

            initOrderDetailFormHandler({ handler: 'test' }, mockContext);

            expect(mockSetLocal).not.toHaveBeenCalled();
        });

        /**
         * @scenario handler=init_form
         * @effects init_form_writes_nothing_without_order_data
         */
        it('order 데이터가 없으면 아무것도 하지 않아야 한다', () => {
            mockGetLocal.mockReturnValue({});
            mockDataSourceGet.mockReturnValue(undefined);

            initOrderDetailFormHandler({ handler: 'test' }, mockContext);

            expect(mockSetLocal).not.toHaveBeenCalled();
        });

        /**
         * @scenario handler=init_form
         * @effects init_form_prefers_on_loaded_context_over_data_source
         */
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
        /**
         * @scenario handler=toggle_selection
         * @effects toggle_selection_adds_and_removes_option_id
         */
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

        /**
         * @scenario handler=toggle_selection
         * @effects toggle_selection_adds_and_removes_option_id
         */
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

        /**
         * @scenario handler=toggle_selection
         * @effects toggle_selection_syncs_select_all_flag
         */
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

        /**
         * @scenario handler=toggle_selection
         * @effects toggle_selection_ignores_call_without_option_id
         */
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
        /**
         * @scenario handler=toggle_selection
         * @effects toggle_all_selects_every_option_or_clears_them
         */
        it('전체 선택이 아닌 상태에서 호출하면 모든 옵션을 선택해야 한다', () => {
            mockGetLocal.mockReturnValue({ selectAll: false });

            toggleAllProductsHandler({ handler: 'test' }, mockContext);

            expect(mockSetLocal).toHaveBeenCalledWith({
                selectedProducts: [1, 2, 3],
                selectAll: true,
            });
        });

        /**
         * @scenario handler=toggle_selection
         * @effects toggle_all_selects_every_option_or_clears_them
         */
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

        /**
         * @scenario handler=build_bulk_confirm
         * @effects bulk_confirm_warns_and_stays_closed_without_status_or_selection
         */
        it('상태 미선택 시 경고 토스트를 표시해야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1, 2], batchOrderStatus: '' } },
                mockContext
            );

            expect(mockToastWarning).toHaveBeenCalled();
            expect(mockModalOpen).not.toHaveBeenCalled();
        });

        /**
         * @scenario handler=build_bulk_confirm
         * @effects bulk_confirm_warns_and_stays_closed_without_status_or_selection
         */
        it('선택된 상품이 없으면 경고 토스트를 표시해야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [], batchOrderStatus: 'shipping' } },
                mockContext
            );

            expect(mockToastWarning).toHaveBeenCalled();
            expect(mockModalOpen).not.toHaveBeenCalled();
        });

        /**
         * @scenario handler=build_bulk_confirm
         * @effects bulk_confirm_opens_modal_with_items_quantities_and_status
         */
        it('상태와 선택 항목이 모두 있으면 확인 모달을 열어야 한다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1, 2], batchOrderStatus: 'shipping', batchCarrierId: 'cj', batchTrackingNumber: '123456' } },
                mockContext
            );

            expect(mockToastWarning).not.toHaveBeenCalled();
            expect(mockModalOpen).toHaveBeenCalledWith('modal_batch_change_confirm');
        });

        /**
         * @scenario handler=build_bulk_confirm
         * @effects bulk_confirm_opens_modal_with_items_quantities_and_status
         */
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

        // 취소는 상태 변경이 아니라 장부 행위다 — `cancelPg` 로 "PG 없이 주문 상태만 변경" 경로가
        // 갈라지던 시절에는 환불신청서도 취소신청서도 없이 상품만 cancelled 로 옮겨져, 재고는
        // 돌아오는데 돈·결제행·주문 합계는 그대로인 취소가 원장에 근거 없이 남았다.
        /**
         * @scenario handler=build_bulk_confirm
         * @effects cancel_always_opens_cancel_order_modal_regardless_of_cancel_pg
         */
        it('cancelled 는 cancelPg 값과 무관하게 언제나 cancel_order 모달을 연다', () => {
            for (const params of [
                { selectedProducts: [1], batchOrderStatus: 'cancelled' },
                { selectedProducts: [1], batchOrderStatus: 'cancelled', cancelPg: false },
                { selectedProducts: [1], batchOrderStatus: 'cancelled', cancelPg: true },
            ]) {
                mockModalOpen.mockClear();

                buildOrderDetailBulkConfirmDataHandler({ handler: 'test', params }, mockContext);

                expect(mockModalOpen).toHaveBeenCalledWith('modal_cancel_order');
                expect(mockModalOpen).not.toHaveBeenCalledWith('modal_batch_change_confirm');
            }
        });

        /**
         * @scenario handler=build_bulk_confirm
         * @effects cancel_state_omits_cancel_pg_flag
         */
        it('cancel_order 모달을 열 때 cancelPg 를 로컬 상태에 싣지 않는다', () => {
            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1], batchOrderStatus: 'cancelled' } },
                mockContext
            );

            const setLocalCall = mockSetLocal.mock.calls[0][0];
            expect(setLocalCall.cancelItems).toEqual(
                expect.arrayContaining([expect.objectContaining({ id: 1 })])
            );
            expect(setLocalCall).not.toHaveProperty('cancelPg');
        });

        /**
         * @scenario handler=build_bulk_confirm
         * @effects cancel_quantity_capped_at_claim_free_available_quantity
         */
        it('S1-D16: 수량 상한을 진행 중인 클레임을 민 가용 수량으로 잡는다', () => {
            // 고객이 3개 중 2개를 먼저 접수해 선점한 상태
            mockDataSourceGet.mockReturnValue({
                data: {
                    ...mockOrderData,
                    options: [
                        { ...mockOrderData.options[0], available_quantity: 1, locked_quantity: 2 },
                        ...mockOrderData.options.slice(1),
                    ],
                },
            });

            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1], batchOrderStatus: 'cancelled' } },
                mockContext
            );

            const setLocalCall = mockSetLocal.mock.calls[0][0];
            // 원 수량 3 이 아니라 가용 1 이어야 한다 — 서버가 422 로 거절할 수를 미리 막는다
            expect(setLocalCall.cancelItems[0].quantity).toBe(1);
            expect(setLocalCall.cancelItems[0].cancel_quantity).toBe(1);
        });

        /**
         * @scenario handler=build_bulk_confirm
         * @effects cancel_list_excludes_options_without_available_quantity
         */
        it('S1-D16: 가용 수량이 0 이면 목록에서 빠진다', () => {
            mockDataSourceGet.mockReturnValue({
                data: {
                    ...mockOrderData,
                    options: [
                        { ...mockOrderData.options[0], available_quantity: 0, locked_quantity: 3 },
                        ...mockOrderData.options.slice(1),
                    ],
                },
            });

            buildOrderDetailBulkConfirmDataHandler(
                { handler: 'test', params: { selectedProducts: [1, 3], batchOrderStatus: 'cancelled' } },
                mockContext
            );

            const setLocalCall = mockSetLocal.mock.calls[0][0];
            expect(setLocalCall.cancelItems).toHaveLength(1);
            expect(setLocalCall.cancelItems[0].id).toBe(3);
        });

        /**
         * @scenario handler=build_bulk_confirm
         * @effects cancel_list_excludes_already_cancelled_options
         */
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
                { handler: 'test', params: { selectedProducts: [1, 3], batchOrderStatus: 'cancelled' } },
                mockContext
            );

            const setLocalCall = mockSetLocal.mock.calls[0][0];
            expect(setLocalCall.cancelItems).toHaveLength(1);
            expect(setLocalCall.cancelItems[0].id).toBe(1);
        });

        /**
         * @scenario handler=build_bulk_confirm
         * @effects bulk_confirm_items_carry_thumbnail_and_original_price
         */
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
        /**
         * @scenario handler=process_bulk_change
         * @effects bulk_change_skips_api_without_status_or_selection
         */
        it('선택된 상품이 없으면 API를 호출하지 않아야 한다', async () => {
            await processOrderDetailBulkChangeHandler(
                { handler: 'test', params: { orderId: 1, selectedProducts: [], batchOrderStatus: 'shipped' } },
                mockContext
            );

            expect(mockApiPatch).not.toHaveBeenCalled();
        });

        /**
         * @scenario handler=process_bulk_change
         * @effects bulk_change_skips_api_without_status_or_selection
         */
        it('변경할 상태가 없으면 API를 호출하지 않아야 한다', async () => {
            await processOrderDetailBulkChangeHandler(
                { handler: 'test', params: { orderId: 1, selectedProducts: [1], batchOrderStatus: '' } },
                mockContext
            );

            expect(mockApiPatch).not.toHaveBeenCalled();
        });

        /**
         * @scenario handler=process_bulk_change
         * @effects bulk_change_sends_option_items_with_carrier_and_tracking, bulk_change_success_refetches_order_and_logs_and_clears_selection
         */
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

        /**
         * @scenario handler=process_bulk_change
         * @effects bulk_change_failure_shows_error_toast
         */
        it('실패 시 에러 토스트를 표시해야 한다', async () => {
            mockApiPatch.mockRejectedValue(new Error('Server Error'));

            await processOrderDetailBulkChangeHandler(
                { handler: 'test', params: { orderId: 1, selectedProducts: [1], batchOrderStatus: 'shipped', changeQuantities: {} } },
                mockContext
            ).catch(() => {});

            expect(mockToastError).toHaveBeenCalled();
        });

        /**
         * 실패를 삼키면 레이아웃의 성공 분기(`<close-modal/>`)가 돌아 확인 창이 닫히고 운영자가 고른 세 항목이 사라진다(#747 C12 M3).
         * 사유 토스트는 한 번만 띄우고, 오류는 다시 던져 레이아웃 `<fail>` 분기(창 유지)로 넘긴다.
         *
         * @scenario handler=process_bulk_change
         * @effects bulk_change_failure_keeps_confirm_modal_open
         */
        it('422 실패를 다시 던져 확인 창이 닫히지 않게 한다', async () => {
            const error: any = new Error('Request failed');
            error.response = { status: 422, data: { message: '회원 마일리지 잔액이 부족해 주문(A)의 상태를 바꿀 수 없습니다.' } };
            mockApiPatch.mockRejectedValue(error);

            await expect(processOrderDetailBulkChangeHandler(
                { handler: 'test', params: { orderId: 1, selectedProducts: [1], batchOrderStatus: 'payment_complete', changeQuantities: {} } },
                mockContext
            )).rejects.toBeTruthy();

            expect(mockToastError).toHaveBeenCalledTimes(1);
            expect(mockToastError).toHaveBeenCalledWith('회원 마일리지 잔액이 부족해 주문(A)의 상태를 바꿀 수 없습니다.');
        });
    });

    // ========== saveAdminMemoHandler ==========

    describe('saveAdminMemoHandler', () => {
        /**
         * @scenario handler=save_admin_memo
         * @effects admin_memo_patches_order_and_confirms
         */
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

        /**
         * @scenario handler=save_admin_memo
         * @effects admin_memo_skips_api_without_order_id
         */
        it('orderId가 없으면 아무것도 하지 않아야 한다', async () => {
            await saveAdminMemoHandler(
                { handler: 'test', params: {} as any },
                mockContext
            );

            expect(mockApiPatch).not.toHaveBeenCalled();
        });

        /**
         * @scenario handler=save_admin_memo
         * @effects admin_memo_failure_shows_error_toast
         */
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
        /**
         * @scenario handler=update_change_quantity
         * @effects change_quantity_stores_value_in_map
         */
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

        /**
         * @scenario handler=update_change_quantity
         * @effects change_quantity_clamps_between_one_and_max
         */
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

        /**
         * @scenario handler=update_change_quantity
         * @effects change_quantity_clamps_between_one_and_max
         */
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

        /**
         * @scenario handler=update_change_quantity
         * @effects change_quantity_falls_back_to_max_on_non_numeric_input
         */
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

        /**
         * @scenario handler=update_change_quantity
         * @effects change_quantity_keeps_other_options_values
         */
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

        /**
         * @scenario handler=update_change_quantity
         * @effects change_quantity_ignores_call_without_option_id_or_max
         */
        it('optionId가 없으면 아무것도 하지 않아야 한다', () => {
            updateChangeQuantityHandler(
                { handler: 'test', params: { maxQuantity: 5 } as any },
                mockContext
            );

            expect(mockSetLocal).not.toHaveBeenCalled();
        });

        /**
         * @scenario handler=update_change_quantity
         * @effects change_quantity_ignores_call_without_option_id_or_max
         */
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
        /**
         * @scenario handler=process_bulk_change
         * @effects bulk_change_applies_changed_quantities_per_option
         */
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

        /**
         * @scenario handler=process_bulk_change
         * @effects bulk_change_falls_back_to_original_quantity_when_unspecified
         */
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
