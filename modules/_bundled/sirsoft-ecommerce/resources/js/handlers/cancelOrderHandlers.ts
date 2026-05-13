/**
 * 주문 취소 관련 핸들러
 *
 * 관리자 주문상세에서 주문 취소 모달의 커스텀 핸들러들을 정의합니다.
 * - 환불 예상금액 계산 (debounce)
 * - 취소 수량 변경
 * - 주문 취소 실행
 */

import type { ActionContext } from '../types';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:CancelOrder')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:CancelOrder]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:CancelOrder]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:CancelOrder]', ...args),
};

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/** Debounce 타이머 */
let estimateDebounceTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * Debounce 타이머 정리
 *
 * 모달 닫기/페이지 이동 시 pending debounce를 취소하여
 * 잘못된 컨텍스트에서 setLocal() 호출되는 것을 방지합니다.
 */
export function clearCancelOrderTimers(): void {
    if (estimateDebounceTimer) {
        clearTimeout(estimateDebounceTimer);
        estimateDebounceTimer = null;
        logger.log('[clearCancelOrderTimers] debounce 타이머 정리 완료');
    }
}

/**
 * 취소 수량 변경 핸들러
 *
 * 개별 취소 항목의 수량을 변경하고, debounce로 환불 예상금액을 재계산합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.updateCancelQuantity",
 *   "params": { "optionId": 1, "maxQuantity": 5, "orderId": "ORD-001" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function updateCancelQuantityHandler(
    action: ActionWithParams<{ optionId: number; maxQuantity: number; orderId: string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { optionId, maxQuantity, orderId } = action.params || {};
    if (!optionId || !maxQuantity || !orderId) return;

    const rawValue = action.params?.value ?? (action as any).$event?.target?.value;
    const parsed = parseInt(String(rawValue), 10);
    const clamped = Math.max(1, Math.min(isNaN(parsed) ? maxQuantity : parsed, maxQuantity));

    // cancelItems 배열에서 해당 옵션의 수량 업데이트
    const local = G7Core.state.getLocal();
    const cancelItems = (local.cancelItems || []).map((item: any) => {
        if (item.id === optionId) {
            return { ...item, cancel_quantity: clamped };
        }
        return item;
    });

    G7Core.state.setLocal({ cancelItems });

    // Debounce로 환불 예상금액 재계산
    // engine-v1.24.7: ActionDispatcher의 try/finally가 debounce 콜백 전에 __g7ActionContext를
    // 복원하므로, 콜백 실행 시점에는 모달의 actionContext가 사라짐.
    // 캡처하여 복원해야 setLocal()이 모달의 actionContext.setState()를 호출할 수 있음.
    const savedActionContext = (window as any).__g7ActionContext;
    if (estimateDebounceTimer) {
        clearTimeout(estimateDebounceTimer);
    }
    estimateDebounceTimer = setTimeout(() => {
        const previousContext = (window as any).__g7ActionContext;
        (window as any).__g7ActionContext = savedActionContext;
        try {
            estimateRefundAmountInternal(G7Core, orderId);
        } finally {
            (window as any).__g7ActionContext = previousContext;
        }
    }, 500);
}

/**
 * 환불 예상금액 계산 핸들러
 *
 * estimate-refund API를 호출하여 환불 예상금액을 조회합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.estimateRefundAmount",
 *   "params": { "orderId": "ORD-001" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function estimateRefundAmountHandler(
    action: ActionWithParams<{ orderId: string; cancelItems?: any[] }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId, cancelItems } = action.params || {};
    if (!orderId) return;

    await estimateRefundAmountInternal(G7Core, orderId, cancelItems);
}

/**
 * 환불 예상금액 내부 계산 함수
 *
 * @param G7Core G7Core 인스턴스
 * @param orderId 주문번호
 */
async function estimateRefundAmountInternal(G7Core: any, orderId: string, paramCancelItems?: any[]): Promise<void> {
    const local = G7Core.state.getLocal();
    const cancelItems = paramCancelItems || local.cancelItems || [];
    const refundPriority = local.refundPriority || 'pg_first';

    if (cancelItems.length === 0) {
        G7Core.state.setLocal({ refundEstimate: null });
        return;
    }

    const items = cancelItems.map((item: any) => ({
        order_option_id: item.id,
        cancel_quantity: item.cancel_quantity,
    }));

    G7Core.state.setLocal({ refundLoading: true });

    try {
        const response = await G7Core.api.post(
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/estimate-refund`,
            { items, refund_priority: refundPriority }
        );

        if (response?.success) {
            G7Core.state.setLocal({
                refundEstimate: response.data,
                refundLoading: false,
                cancelError: null,
            });
        } else {
            throw new Error(response?.message || 'Estimate failed');
        }
    } catch (error: any) {
        logger.error('[estimateRefundAmount] 실패:', error);
        G7Core.state.setLocal({
            refundLoading: false,
            refundEstimate: null,
        });
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.detail.modal.cancel.estimate_failed')
            ?? '환불 예상금액 계산에 실패했습니다.'
        );
    }
}

/**
 * 환불 우선순위 변경 핸들러
 *
 * 환불 우선순위를 변경하고, debounce로 환불 예상금액을 재계산합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.changeRefundPriority",
 *   "params": { "priority": "points_first", "orderId": "ORD-001" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function changeRefundPriorityHandler(
    action: ActionWithParams<{ priority: string; orderId: string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { priority, orderId } = action.params || {};
    if (!priority || !orderId) return;

    G7Core.state.setLocal({ refundPriority: priority });

    // Debounce로 환불 예상금액 재계산
    // engine-v1.24.7: debounce 콜백에서 actionContext 캡처/복원
    const savedActionContext = (window as any).__g7ActionContext;
    if (estimateDebounceTimer) {
        clearTimeout(estimateDebounceTimer);
    }
    estimateDebounceTimer = setTimeout(() => {
        const previousContext = (window as any).__g7ActionContext;
        (window as any).__g7ActionContext = savedActionContext;
        try {
            estimateRefundAmountInternal(G7Core, orderId);
        } finally {
            (window as any).__g7ActionContext = previousContext;
        }
    }, 300);
}

/**
 * 주문 취소 실행 핸들러
 *
 * cancel API를 호출하여 주문을 취소합니다.
 * PG 실패 시 cancelError를 설정하고 모달을 유지합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.executeCancelOrder",
 *   "params": { "orderId": "ORD-001" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function executeCancelOrderHandler(
    action: ActionWithParams<{
        orderId: string;
        cancelItems?: any[];
        cancelReason?: string;
        cancelReasonDetail?: string;
        cancelPg?: boolean;
        refundPriority?: string;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId } = action.params || {};
    if (!orderId) return;

    const local = G7Core.state.getLocal();
    const cancelItems = action.params?.cancelItems || local.cancelItems || [];
    const cancelReason = action.params?.cancelReason || local.cancelReason || '';
    const cancelReasonDetail = action.params?.cancelReasonDetail || local.cancelReasonDetail || '';
    const cancelPg = action.params?.cancelPg ?? local.cancelPg ?? true;
    const refundPriority = action.params?.refundPriority || local.refundPriority || 'pg_first';

    // 전체 옵션 취소인지 부분 취소인지 판단
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const allOptions = orderData?.options || [];
    const activeOptions = allOptions.filter((opt: any) => opt.option_status !== 'cancelled');
    const isFullCancel = cancelItems.length >= activeOptions.length;

    const body: Record<string, any> = {
        type: isFullCancel ? 'full' : 'partial',
        cancel_pg: cancelPg,
        refund_priority: refundPriority,
    };

    if (cancelReason) {
        body.reason = cancelReason;
    }
    if (cancelReasonDetail) {
        body.reason_detail = cancelReasonDetail;
    }

    if (!isFullCancel) {
        body.items = cancelItems.map((item: any) => ({
            order_option_id: item.id,
            cancel_quantity: item.cancel_quantity,
        }));
    }

    G7Core.state.setLocal({ isCancelling: true, cancelError: null, cancelValidationErrors: null });

    try {
        const response = await G7Core.api.post(
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/cancel`,
            body
        );

        if (response?.success) {
            G7Core.state.setLocal({ isCancelling: false });
            G7Core.toast?.success?.(
                G7Core.t?.('sirsoft-ecommerce.admin.order.detail.modal.cancel.cancel_success')
                ?? '주문이 취소되었습니다.'
            );

            G7Core.modal?.close?.('modal_cancel_order');
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order_logs' } });

            // 선택 초기화
            G7Core.state.setLocal({
                selectedProducts: [],
                selectAll: false,
                batchOrderStatus: '',
            });
        } else {
            throw new Error(response?.message || 'Cancel failed');
        }
    } catch (error: any) {
        logger.error('[executeCancelOrder] 실패:', error);

        const errorData = (error as any)?.response?.data || (error as any)?.data;
        const httpStatus = (error as any)?.response?.status || (error as any)?.status;
        const isValidationError = httpStatus === 422;
        const pgError = errorData?.errors?.detail;
        const validationErrors = errorData?.errors ?? null;
        const detailMessage = pgError || errorData?.message || error?.message || '';
        const fallbackMessage = G7Core.t?.('sirsoft-ecommerce.admin.order.detail.modal.cancel.cancel_failed')
            ?? '주문 취소에 실패했습니다.';

        if (isValidationError) {
            // 422 Validation 에러: 필드별 에러만 표시 (PG 에러 영역 미사용)
            G7Core.state.setLocal({
                isCancelling: false,
                cancelError: null,
                cancelValidationErrors: validationErrors,
            });
            G7Core.toast?.error?.(detailMessage || fallbackMessage);
        } else {
            // PG 에러 등 기타 에러: PG 에러 영역에 표시
            G7Core.state.setLocal({
                isCancelling: false,
                cancelError: detailMessage || fallbackMessage,
                cancelValidationErrors: null,
            });
            G7Core.toast?.error?.(detailMessage || fallbackMessage);
        }
    }
}
