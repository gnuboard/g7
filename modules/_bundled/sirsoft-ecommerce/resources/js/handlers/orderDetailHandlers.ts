/**
 * 주문 상세 관련 핸들러
 *
 * 주문 상세 화면에서 사용하는 커스텀 핸들러들을 정의합니다.
 * - 폼 초기화 (데이터 로드 후 수취인 정보 바인딩)
 * - 상품 선택 토글
 * - 일괄 상태 변경 API 호출
 * - 관리자 메모 저장
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:OrderDetail')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:OrderDetail]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:OrderDetail]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:OrderDetail]', ...args),
};

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 */
interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 주문 상세 폼 초기화 핸들러
 *
 * order 데이터소스 로드 완료 후 수취인 정보를 _local.form에 바인딩합니다.
 *
 * @example
 * // data_sources.onLoaded에서 사용
 * {
 *   "handler": "sirsoft-ecommerce.initOrderDetailForm"
 * }
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function initOrderDetailFormHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        logger.warn('[initOrderDetailForm] G7Core.state를 사용할 수 없습니다.');
        return;
    }

    const local = G7Core.state.getLocal();
    // onLoaded 콜백 context에서 직접 데이터 우선 접근
    const contextData = (_context as any)?.data?.data;
    const orderData = contextData
        ?? G7Core.dataSource?.get?.('order')?.data;

    if (!orderData) {
        logger.warn('[initOrderDetailForm] order 데이터가 없습니다.');
        return;
    }

    // 수취인 정보를 폼에 바인딩
    G7Core.state.setLocal({
        form: {
            recipient_name: orderData.recipient_name || '',
            recipient_phone: orderData.recipient_phone || '',
            recipient_tel: orderData.recipient_tel || '',
            recipient_zipcode: orderData.recipient_zipcode || '',
            recipient_address: orderData.recipient_address || '',
            recipient_detail_address: orderData.recipient_detail_address || '',
            delivery_memo: orderData.delivery_memo || '',
            admin_memo: orderData.admin_memo || '',
        },
    });

    logger.log('[initOrderDetailForm] 폼 초기화 완료');
}

/**
 * 상품 선택 토글 핸들러
 *
 * 개별 상품의 체크박스를 토글합니다.
 *
 * @example
 * {
 *   "type": "change",
 *   "handler": "sirsoft-ecommerce.toggleProductSelection",
 *   "params": { "optionId": "{{option.id}}" }
 * }
 *
 * @param action 액션 객체 (params.optionId 필수)
 * @param _context 액션 컨텍스트
 */
export function toggleProductSelectionHandler(
    action: ActionWithParams<{ optionId: number }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    const { optionId } = action.params || {};

    if (!G7Core?.state || !optionId) {
        return;
    }

    const local = G7Core.state.getLocal();
    const selected: number[] = local.selectedProducts || [];

    let newSelected: number[];
    if (selected.includes(optionId)) {
        newSelected = selected.filter((id: number) => id !== optionId);
    } else {
        newSelected = [...selected, optionId];
    }

    // 전체 선택 상태 업데이트
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const totalOptions = (orderData?.options || []).length;
    const selectAll = newSelected.length === totalOptions && totalOptions > 0;

    G7Core.state.setLocal({
        selectedProducts: newSelected,
        selectAll,
    });
}

/**
 * 전체 선택/해제 토글 핸들러
 *
 * 모든 상품을 선택하거나 해제합니다.
 *
 * @example
 * {
 *   "type": "change",
 *   "handler": "sirsoft-ecommerce.toggleAllProducts"
 * }
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function toggleAllProductsHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        return;
    }

    const local = G7Core.state.getLocal();
    const currentSelectAll = local.selectAll;
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const options = orderData?.options || [];

    if (currentSelectAll) {
        // 전체 해제
        G7Core.state.setLocal({
            selectedProducts: [],
            selectAll: false,
        });
    } else {
        // 전체 선택
        const allIds = options.map((opt: { id: number }) => opt.id);
        G7Core.state.setLocal({
            selectedProducts: allIds,
            selectAll: true,
        });
    }
}

/**
 * 주문 상세 일괄변경 확인 데이터 빌드 핸들러
 *
 * 주문관리의 buildOrderBulkConfirmData와 동일한 패턴.
 * 선택 항목과 상태를 검증 후 확인 모달을 엽니다.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.buildOrderDetailBulkConfirmData"
 * }
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function buildOrderDetailBulkConfirmDataHandler(
    action: ActionWithParams<{
        selectedProducts?: number[];
        batchOrderStatus?: string;
        batchCarrierId?: string;
        batchTrackingNumber?: string;
    }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core) return;

    const params = action.params || {};
    const selectedProducts: number[] = params.selectedProducts || [];
    const batchOrderStatus: string = params.batchOrderStatus || '';
    const batchCarrierId: string = params.batchCarrierId || '';
    const batchTrackingNumber: string = params.batchTrackingNumber || '';

    // 검증: 상태 미선택 시 경고
    if (!batchOrderStatus) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.no_status_selected')
            || '변경할 주문상태를 선택해주세요.'
        );
        return;
    }

    // 검증: 선택된 상품 없음
    if (selectedProducts.length === 0) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.no_items_selected')
            || '변경할 상품을 선택해주세요.'
        );
        return;
    }

    // 취소 상태 선택 시 → cancelPg 여부에 따라 모달 분기
    if (batchOrderStatus === 'cancelled') {
        const cancelPg = params.cancelPg ?? true;
        const orderData = G7Core.dataSource?.get?.('order')?.data;
        const options = orderData?.options || [];

        // 취소 가능한 옵션만 필터 (이미 취소된 옵션 제외)
        const cancelItems = selectedProducts
            .map((optionId: number) => {
                const opt = options.find((o: { id: number }) => o.id === optionId);
                if (!opt || opt.option_status === 'cancelled') return null;
                return {
                    id: opt.id,
                    product_name: opt.product_name,
                    product_option_name: opt.product_option_name,
                    thumbnail_url: opt.product_snapshot?.thumbnail_url || '',
                    unit_price: opt.unit_price,
                    quantity: opt.quantity,
                    cancel_quantity: opt.quantity,
                    option_status: opt.option_status,
                    option_status_label: opt.option_status_label || opt.option_status,
                };
            })
            .filter(Boolean);

        if (cancelItems.length === 0) {
            G7Core.toast?.warning?.(
                G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.no_cancellable_items')
                || '취소할 수 있는 상품이 없습니다. 이미 취소된 상품은 제외됩니다.'
            );
            return;
        }

        if (cancelPg) {
            // PG 결제 취소 포함 → 취소/환불 모달 (환불예정금액 실시간 표시)
            // 모달 열기 전에 페이지 _local에 모든 취소 관련 상태 초기화
            // (사용자 취소 모달과 동일 패턴 — onMount setState 미사용)
            // 참고: modals 섹션의 isolated scope에서 onMount setState는
            // 모달 자체 scope에 복사하여 getLocal()/setLocal()과 단절됨
            G7Core.state.setLocal({
                cancelItems,
                cancelReason: '',
                cancelReasonDetail: '',
                cancelPg: true,
                refundPriority: 'pg_first',
                refundEstimate: null,
                refundLoading: false,
                isCancelling: false,
                cancelError: null,
                cancelValidationErrors: null,
            });
            G7Core.modal?.open?.('modal_cancel_order');

            // 모달 열린 후 초기 환불 예상금액 계산 (사용자 모달 initUserCancelItemsHandler와 동일)
            const orderId = orderData?.order_number || '';
            if (orderId) {
                G7Core.dispatch?.({
                    handler: 'sirsoft-ecommerce.estimateRefundAmount',
                    params: { orderId, cancelItems },
                });
            }
        } else {
            // PG 결제 취소 미포함 → 단순 상태 변경 확인 모달
            const changeQuantities: Record<number, number> = {};
            for (const item of cancelItems) {
                changeQuantities[item.id] = item.quantity;
            }
            G7Core.state.setLocal({
                bulkConfirmItems: cancelItems,
                changeQuantities,
                batchOrderStatus,
            });
            G7Core.modal?.open?.('modal_batch_change_confirm');
        }
        return;
    }

    // 검증: 배송 관련 상태 선택 시 택배사/송장번호 필수
    const shippingStatuses = ['shipping_ready', 'shipping', 'delivered'];
    if (shippingStatuses.includes(batchOrderStatus)) {
        if (!batchCarrierId || !batchTrackingNumber) {
            G7Core.toast?.warning?.(
                G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.carrier_required')
                || '해당 상태로 변경하려면 택배사와 송장번호를 모두 입력해주세요.'
            );
            return;
        }
    }

    // 선택된 상품의 상세 정보 수집 (데이터소스는 G7Core.dataSource API로 접근)
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const options = orderData?.options || [];
    const bulkConfirmItems: Record<string, unknown>[] = [];
    const changeQuantities: Record<number, number> = {};

    for (const optionId of selectedProducts) {
        const opt = options.find((o: { id: number }) => o.id === optionId);
        if (!opt) continue;

        bulkConfirmItems.push({
            id: opt.id,
            product_name: opt.product_name,
            product_option_name: opt.product_option_name,
            sku: opt.sku,
            thumbnail_url: opt.product_snapshot?.thumbnail_url || '',
            unit_price: opt.unit_price,
            original_price: opt.product_snapshot?.original_price || opt.unit_price,
            quantity: opt.quantity,
            option_status: opt.option_status,
        });
        changeQuantities[opt.id] = opt.quantity;
    }

    // _local에 확인 데이터 저장 후 모달 열기
    G7Core.state.setLocal({
        bulkConfirmItems,
        changeQuantities,
        batchOrderStatus,
        batchCarrierId,
        batchTrackingNumber,
    });

    G7Core.modal?.open?.('modal_batch_change_confirm');
}

/**
 * 일괄 상태 변경 처리 핸들러
 *
 * 선택된 주문 옵션들의 상태를 일괄 변경합니다.
 * 수량 분할을 지원합니다.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.processOrderDetailBulkChange",
 *   "params": { "orderId": "{{route.orderNumber}}" }
 * }
 *
 * @param action 액션 객체 (params.orderId 필수)
 * @param _context 액션 컨텍스트
 */
export async function processOrderDetailBulkChangeHandler(
    action: ActionWithParams<{
        orderId: string | number;
        selectedProducts?: number[];
        batchOrderStatus?: string;
        batchCarrierId?: string;
        batchTrackingNumber?: string;
        changeQuantities?: Record<number, number>;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    const { orderId } = action.params || {};

    if (!G7Core?.state || !orderId) {
        logger.warn('[processOrderDetailBulkChange] orderId가 없습니다.');
        return;
    }

    const selectedProducts = action.params?.selectedProducts;
    const batchOrderStatus = action.params?.batchOrderStatus;
    const batchCarrierId = action.params?.batchCarrierId;
    const batchTrackingNumber = action.params?.batchTrackingNumber;
    const changeQuantities = action.params?.changeQuantities;

    if (!selectedProducts || selectedProducts.length === 0) {
        logger.warn('[processOrderDetailBulkChange] 선택된 상품이 없습니다.');
        return;
    }

    if (!batchOrderStatus) {
        logger.warn('[processOrderDetailBulkChange] 변경할 상태가 없습니다.');
        return;
    }

    // 각 옵션의 수량 결정 (데이터소스는 G7Core.dataSource API로 접근)
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const options = orderData?.options || [];

    const items = selectedProducts.map((optionId: number) => {
        const option = options.find((opt: { id: number }) => opt.id === optionId);
        return {
            option_id: optionId,
            quantity: changeQuantities?.[optionId] ?? option?.quantity ?? 1,
        };
    });

    const body: Record<string, unknown> = {
        items,
        status: batchOrderStatus,
    };

    if (batchCarrierId) {
        body.carrier_id = batchCarrierId;
    }
    if (batchTrackingNumber) {
        body.tracking_number = batchTrackingNumber;
    }

    try {
        const response = await G7Core.api.patch(
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/options/bulk-status`,
            body
        );

        if (response?.success) {
            const t = G7Core.t;
            const changedCount = response.data?.changed_count ?? selectedProducts.length;
            G7Core.toast?.success?.(
                t?.('sirsoft-ecommerce.admin.order.detail.handler.bulk_change_success', { count: changedCount })
                ?? `${changedCount}개 옵션의 상태가 변경되었습니다.`
            );

            // 모달 닫기 + 데이터 새로고침 + 선택 초기화
            G7Core.modal?.close?.('modal_batch_change_confirm');
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order_logs' } });
            G7Core.state.setLocal({
                selectedProducts: [],
                selectAll: false,
                batchOrderStatus: '',
                batchCarrierId: '',
                batchTrackingNumber: '',
                changeQuantities: {},
            });
        } else {
            throw new Error(response?.message || '상태 변경에 실패했습니다.');
        }
    } catch (error: any) {
        logger.error('[processOrderDetailBulkChange] API 호출 실패:', error);
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.detail.handler.bulk_change_failed')
            ?? '상태 변경에 실패했습니다.'
        );
    }
}

/**
 * 관리자 메모 저장 핸들러
 *
 * 관리자 메모를 API를 통해 저장합니다.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.saveAdminMemo",
 *   "params": { "orderId": "{{route.orderNumber}}" }
 * }
 *
 * @param action 액션 객체 (params.orderId 필수)
 * @param _context 액션 컨텍스트
 */
export async function saveAdminMemoHandler(
    action: ActionWithParams<{ orderId: string | number }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    const { orderId } = action.params || {};

    if (!G7Core?.state || !orderId) {
        return;
    }

    const local = G7Core.state.getLocal();
    const adminMemo = local.form?.admin_memo ?? '';

    try {
        const response = await G7Core.api.patch(
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}`,
            { admin_memo: adminMemo }
        );

        if (response?.success) {
            G7Core.toast?.success?.(
                G7Core.t?.('sirsoft-ecommerce.admin.order.detail.handler.memo_save_success')
                ?? '관리자 메모가 저장되었습니다.'
            );
        } else {
            throw new Error(response?.message);
        }
    } catch (error: any) {
        logger.error('[saveAdminMemo] 저장 실패:', error);
        G7Core.toast?.error?.(error?.message ?? '메모 저장에 실패했습니다.');
    }
}

/**
 * 일괄변경 모달 내 수량 변경 핸들러
 *
 * 개별 항목의 변경 수량을 1~최대수량 범위로 클램핑하여 _local에 저장합니다.
 *
 * @example
 * {
 *   "type": "change",
 *   "handler": "sirsoft-ecommerce.updateChangeQuantity",
 *   "params": { "optionId": "{{confirmItem.id}}", "maxQuantity": "{{confirmItem.quantity}}" }
 * }
 *
 * @param action 액션 객체 (params.optionId, params.maxQuantity 필수)
 * @param _context 액션 컨텍스트
 */
export function updateChangeQuantityHandler(
    action: ActionWithParams<{ optionId: number; maxQuantity: number; value?: number }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { optionId, maxQuantity } = action.params || {};
    if (!optionId || !maxQuantity) return;

    // $event.target.value 또는 params.value에서 입력값 추출
    const rawValue = action.params?.value ?? (action as any).$event?.target?.value;
    const parsed = parseInt(String(rawValue), 10);
    const clamped = Math.max(1, Math.min(isNaN(parsed) ? maxQuantity : parsed, maxQuantity));

    const local = G7Core.state.getLocal();
    const currentQuantities = { ...(local.changeQuantities || {}) };
    currentQuantities[optionId] = clamped;

    G7Core.state.setLocal({ changeQuantities: currentQuantities });
}
