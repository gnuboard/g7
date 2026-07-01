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
 * 일괄변경 송장번호 입력칸의 실제 DOM 값을 읽습니다.
 *
 * 송장번호 input 은 주문정보 카드의 dataKey="form" 폼 컨텍스트 안에 있어, 폼 자동바인딩이
 * 값을 _local.form.batchTrackingNumber 로 보낸다. 반면 일괄변경 핸들러는 액션 params(
 * 표현식 {{_local.batchTrackingNumber}}) 로 top-level 값을 받으므로 경로가 어긋나 빈 값이
 * 전달될 수 있다(Select 인 상태/택배사는 명시 setState 가 top-level 에 정상 기록되어 무관).
 * 저장소 분기에 의존하지 않도록, params 가 비면 화면에 실제 표시된 input 값을 직접 읽는다.
 *
 * @return 송장번호 문자열 (입력칸이 없으면 빈 문자열)
 */
function readBatchTrackingFromDom(): string {
    if (typeof document === 'undefined') {
        return '';
    }
    const input = document.querySelector<HTMLInputElement>('input[name="batchTrackingNumber"]');

    return input?.value?.trim() ?? '';
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
    // 송장번호 입력칸(basic Input)은 dataKey="form" 컨텍스트의 폼 자동바인딩 영향으로
    // 명시적 setState(target:"local") 가 top-level _local 에 도달하지 않고 _local.form 에만
    // 기록되는 경우가 있다(Select 는 정상, Input 만 비대칭). params 가 비면 DOM 의 실제
    // 입력값을 직접 읽어 보정한다. (택배사/상태 Select 는 params 로 정상 전달됨)
    const batchTrackingNumber: string = params.batchTrackingNumber || readBatchTrackingFromDom();

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

    // 확인 모달(modal_batch_change_confirm)은 격리 scope 라, 모달의 $parent._local 표현식으로
    // 넘긴 params 가 build 단계에서 setLocal 한 페이지 globalLocal 값과 어긋나 빈 값으로 도달할 수
    // 있다(carrier_id/tracking_number 누락 → DB 미저장). params 가 비면 build 가 저장한 페이지
    // _local(getLocal) 을 SSoT 로 fallback 한다. 송장은 추가로 DOM 값까지 보정.
    const pageLocal = G7Core.state.getLocal?.() ?? {};
    const selectedProducts = action.params?.selectedProducts ?? pageLocal.selectedProducts;
    const batchOrderStatus = action.params?.batchOrderStatus || pageLocal.batchOrderStatus;
    const batchCarrierId = action.params?.batchCarrierId || pageLocal.batchCarrierId;
    const batchTrackingNumber = action.params?.batchTrackingNumber
        || pageLocal.batchTrackingNumber
        || readBatchTrackingFromDom();
    const changeQuantities = action.params?.changeQuantities ?? pageLocal.changeQuantities;

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
        // 서버 422 검증 메시지(상태 전이 차단 사유 등)를 우선 노출한다.
        // 고정 문구로 사유를 가리지 않도록 error.response.data.message 를 먼저 추출한다(confirmDeposit 동일 패턴).
        const errorData = error?.response?.data || error?.data || {};
        const serverMessage = errorData?.message || error?.message;
        G7Core.toast?.error?.(
            serverMessage
            ?? G7Core.t?.('sirsoft-ecommerce.admin.order.detail.handler.bulk_change_failed')
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

/**
 * 무통장 입금확인 모달 열기 핸들러
 *
 * 모달(modals 섹션)은 격리된 scope 라 모달 내부에서 setState($parent._local) 한 값이
 * 페이지 globalLocal(핸들러 getLocal()/setLocal() 대상)과 단절된다(엔진 모달 scope 제한).
 * 이 때문에 모달 onMount setState 로 입력 시드를 깔면 입력값/검증에러가 페이지 scope 와
 * 어긋나 입금액 미전송·인라인 에러 미표시가 발생한다.
 *
 * 주문취소 모달과 동일하게, 모달을 열기 전에 페이지 _local 에 입력 시드를 setLocal() 로
 * 미리 깔고 modal.open() 한다. 이렇게 하면 모달이 페이지 _local 을 그대로 상속하여
 * 모달 input(`_local.*`, setState target:"local")·인라인 에러·핸들러가 모두 같은
 * 페이지 scope 를 공유한다.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.openConfirmDepositModal"
 * }
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function openConfirmDepositModalHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[openConfirmDepositModal] G7Core.state 를 사용할 수 없습니다.');
        return;
    }

    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const depositorName = orderData?.depositor_name ?? '';
    const depositAmount = orderData?.total_due_amount ?? 0;

    // 모달 열기 전 페이지 _local 에 입력 시드 + 상태 초기화 (취소 모달과 동일 패턴)
    G7Core.state.setLocal({
        depositorName,
        depositAmount,
        depositConfirmErrors: null,
        isConfirmingDeposit: false,
        markOrderComplete: false,
    });
    G7Core.modal?.open?.('modal_confirm_deposit');
}

/**
 * 무통장 입금확인 핸들러
 *
 * 무통장(dbank) 미결제 주문의 입금자명·입금액을 확인하여 결제완료 처리한다.
 * 모달은 페이지 _local 을 상속하므로(openConfirmDepositModalHandler 가 모달 열기 전
 * setLocal() 로 시드) 입력값은 getLocal() 로 일관되게 읽을 수 있고, 모달이 표현식으로
 * 박아 넣은 action.params 도 함께 받는다(이중 안전). 선언적 apiCall + onError(sequence
 * 내부 $parent._local setState + toast) 패턴은 setState 와 toast 의 글로벌 상태 업데이트가
 * 경합하여 422 에러 토스트/인라인 에러가 소실되는 문제가 있어(입금확인 검수 결함), 주문취소
 * 모달(executeCancelOrder)과 동일하게 JS 핸들러로 422 를 직접 처리한다.
 *
 * 428(본인인증 필요)은 G7Core.api 가 IdentityGuardInterceptor 를 거치지 않으므로
 * 여기서 명시 분기하여 본인인증 모달 launcher 를 직접 호출한다(검수 결함 N3 의 입금확인 경로 대응).
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.confirmDeposit",
 *   "params": { "orderId": "{{route.orderNumber}}", "amount": "{{_local.depositAmount}}" }
 * }
 *
 * @param action 액션 객체 (params.orderId 필수)
 * @param _context 액션 컨텍스트
 */
export async function confirmDepositHandler(
    action: ActionWithParams<{
        orderId: string | number;
        amount?: string | number;
        depositorName?: string;
        markOrderComplete?: boolean | string;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    const { orderId } = action.params || {};

    if (!G7Core?.state || !orderId) {
        logger.warn('[confirmDeposit] orderId 가 없습니다.');
        return;
    }

    // 입금액·입금자명은 모달 컨텍스트에서 해석된 action.params 로 직접 전달받는다.
    // 모달(modals 섹션)은 격리된 scope 라 모달 input 이 쓰는 $parent._local 값이
    // 페이지 globalLocal(getLocal() 반환 대상)에 도달하지 않는다(엔진 모달 scope 제한).
    // 따라서 getLocal() 로 읽으면 amount=undefined → 422("입금액 필수") 가 되므로,
    // 취소 모달(executeCancelOrder)과 동일하게 모달 표현식이 박아 넣은 params 를 SSoT 로 쓴다.
    // params 미전달 시에만 페이지 _local 로 폴백(하위호환).
    const local = G7Core.state.getLocal();
    const amount = action.params?.amount ?? local.depositAmount;
    const depositorName = action.params?.depositorName ?? local.depositorName ?? '';
    // 체크박스 표현식은 boolean 또는 문자열("true"/"false")로 도착할 수 있어 정규화한다.
    const rawMarkComplete = action.params?.markOrderComplete ?? local.markOrderComplete ?? false;
    const markOrderComplete = rawMarkComplete === true || rawMarkComplete === 'true';

    G7Core.state.setLocal({ isConfirmingDeposit: true, depositConfirmErrors: null });

    try {
        const response = await G7Core.api.patch(
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/confirm-deposit`,
            { amount, depositor_name: depositorName, mark_order_complete: markOrderComplete }
        );

        if (response?.success) {
            G7Core.state.setLocal({ isConfirmingDeposit: false, depositConfirmErrors: null });
            G7Core.toast?.success?.(
                G7Core.t?.('sirsoft-ecommerce.admin.order.detail.modal.confirm_deposit.confirm_success')
                ?? '입금이 확인되어 결제완료 처리되었습니다.'
            );
            G7Core.modal?.close?.('modal_confirm_deposit');
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order_logs' } });
        } else {
            throw new Error(response?.message || 'Deposit confirm failed');
        }
    } catch (error: any) {
        logger.error('[confirmDeposit] 실패:', error);

        const errorData = error?.response?.data || error?.data || {};
        // 428(본인인증 필요)은 ApiClient(G7Core.api) 인터셉터가 중앙에서 처리한다 —
        // 본인인증 모달 → verify → confirm-deposit 자동 재실행 후 성공 응답이 위 try 의 response 로
        // 돌아오므로(아래 catch 에 도달하지 않음), 여기서 별도 428 분기는 불필요(이중 모달 방지).
        const detailMessage = errorData?.errors?.detail || errorData?.message || error?.message || '';
        const fallbackMessage = G7Core.t?.('sirsoft-ecommerce.admin.order.detail.modal.confirm_deposit.confirm_failed')
            ?? '입금 확인에 실패했습니다.';

        // 422 등 검증 에러: 인라인 에러(amount 필드) + 토스트
        G7Core.state.setLocal({
            isConfirmingDeposit: false,
            depositConfirmErrors: { amount: [detailMessage || fallbackMessage] },
        });
        G7Core.toast?.error?.(detailMessage || fallbackMessage);
    }
}
