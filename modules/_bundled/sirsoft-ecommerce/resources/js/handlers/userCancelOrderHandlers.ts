/**
 * 사용자 주문 취소 관련 핸들러
 *
 * 사용자 마이페이지 주문상세에서 주문 취소 모달의 커스텀 핸들러들을 정의합니다.
 * - 취소 항목 초기화
 * - 항목 선택/해제
 * - 취소 수량 변경
 * - 환불 예상금액 계산 (debounce)
 * - 주문 취소 실행
 */

import type { ActionContext } from '../types';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:UserCancelOrder')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:UserCancelOrder]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:UserCancelOrder]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:UserCancelOrder]', ...args),
};

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

interface CancelItem {
    id: number;
    product_name: string;
    product_option_name: string;
    thumbnail_url: string;
    unit_price: number;
    quantity: number;
    cancel_quantity: number;
    option_status: string;
    selected: boolean;
}

/** Debounce 타이머 */
let userEstimateDebounceTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * 비회원 컨텍스트 식별자 확보 여부 — _global.guestOrderToken 과 dataSource('order').data.order_number 가 모두 있으면 true.
 * 비회원 응답(GuestOrderResource)은 id 미노출이므로 회원 식별자(orderId) 가 비어 있어도 본 값으로 비회원 endpoint 호출 가능.
 */
function hasGuestOrderContext(G7Core: any): boolean {
    const token = G7Core?.state?.get?.('_global')?.guestOrderToken;
    const orderNumber = G7Core?.dataSource?.get?.('order')?.data?.order_number;
    return Boolean(token && orderNumber);
}

/**
 * Debounce 타이머 정리 (사용자 취소 모달)
 *
 * 모달 닫기/페이지 이동 시 pending debounce를 취소합니다.
 */
export function clearUserCancelOrderTimers(): void {
    if (userEstimateDebounceTimer) {
        clearTimeout(userEstimateDebounceTimer);
        userEstimateDebounceTimer = null;
    }
}

/**
 * 주문 상품 목록에서 개별 아이템 선택/해제 핸들러
 *
 * 주문상세 상품 목록의 체크박스를 토글합니다.
 * selectedItemIds 배열에서 optionId를 추가/제거합니다.
 *
 * @param action 액션 객체 (params.optionId: 토글할 옵션 ID)
 * @param _context 액션 컨텍스트
 */
export function toggleItemSelectionHandler(
    action: ActionWithParams<{ optionId: number }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { optionId } = action.params || {};
    if (!optionId) return;

    const local = G7Core.state.getLocal();
    const selectedIds: number[] = [...(local.selectedItemIds || [])];
    const idx = selectedIds.indexOf(optionId);

    if (idx >= 0) {
        selectedIds.splice(idx, 1);
    } else {
        selectedIds.push(optionId);
    }

    // 취소 가능한 모든 아이템이 선택되었는지 확인
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const cancellableIds = (orderData?.options || [])
        .filter((opt: any) => opt.option_status !== 'cancelled')
        .map((opt: any) => opt.id);
    const allSelected = cancellableIds.length > 0 &&
        cancellableIds.every((id: number) => selectedIds.includes(id));

    G7Core.state.setLocal({
        selectedItemIds: selectedIds,
        selectAllItems: allSelected,
    });
}

/**
 * 주문 상품 목록에서 전체 선택/해제 핸들러
 *
 * 취소 가능한 모든 아이템을 선택하거나 전체 해제합니다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function toggleSelectAllItemsHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const local = G7Core.state.getLocal();
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const cancellableIds: number[] = (orderData?.options || [])
        .filter((opt: any) => opt.option_status !== 'cancelled')
        .map((opt: any) => opt.id);

    const newSelectAll = !local.selectAllItems;

    G7Core.state.setLocal({
        selectedItemIds: newSelectAll ? cancellableIds : [],
        selectAllItems: newSelectAll,
    });
}

/**
 * 사용자 취소 항목 초기화 핸들러
 *
 * 주문 상품 목록에서 선택된 아이템(selectedItemIds)을 기반으로
 * cancelItems를 구성하고, 취소 모달을 엽니다.
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function initUserCancelItemsHandler(
    action: ActionWithParams<{ orderId: number | string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const orderData = G7Core.dataSource?.get?.('order')?.data;
    if (!orderData) return;

    const local = G7Core.state.getLocal();
    const selectedItemIds: number[] = local.selectedItemIds || [];

    // 선택된 아이템이 없으면 경고
    if (selectedItemIds.length === 0) {
        G7Core.toast?.warning?.(
            G7Core.t?.('mypage.order_detail.cancel_modal.no_selected_items')
            ?? '취소할 상품을 선택해주세요.'
        );
        return;
    }

    const options = orderData.options || [];
    const cancelItems: CancelItem[] = options
        .filter((opt: any) => selectedItemIds.includes(opt.id) && opt.option_status !== 'cancelled')
        .map((opt: any) => ({
            id: opt.id,
            product_name: opt.product_name ?? '',
            product_option_name: opt.product_option_name ?? '',
            thumbnail_url: opt.product_snapshot?.thumbnail_url || '',
            unit_price: opt.unit_price ?? 0,
            quantity: opt.quantity ?? 1,
            cancel_quantity: opt.quantity ?? 1,
            option_status: opt.option_status ?? '',
            selected: true,
        }));

    if (cancelItems.length === 0) {
        G7Core.toast?.warning?.(
            G7Core.t?.('mypage.order_detail.cancel_modal.no_cancellable_items')
            ?? '취소 가능한 상품이 없습니다.'
        );
        return;
    }

    G7Core.state.setLocal({
        cancelItems,
        cancelSelectAll: true,
        cancelReason: '',
        refundEstimate: null,
        refundLoading: false,
        isCancelling: false,
        cancelError: null,
    });

    // modals 섹션 모달 열기
    G7Core.modal?.open?.('modal_cancel_order');

    // 초기 환불 예상금액 계산
    // 비회원 응답(GuestOrderResource)은 id 미노출 — order_number 만 있어도 estimateUserRefundInternal 내부에서 비회원 URL 분기
    const orderId = action.params?.orderId || orderData.id;
    if (orderId || orderData.order_number) {
        estimateUserRefundInternal(G7Core, orderId);
    }
}

/**
 * 사용자 취소 항목 선택/해제 핸들러
 *
 * @param action 액션 객체 (params.optionId: 토글할 옵션 ID, params.orderId: 주문 ID)
 * @param _context 액션 컨텍스트
 */
export function toggleUserCancelItemHandler(
    action: ActionWithParams<{ optionId: number; orderId: number | string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { optionId, orderId } = action.params || {};
    if (!optionId) return;

    const local = G7Core.state.getLocal();
    const cancelItems: CancelItem[] = (local.cancelItems || []).map((item: CancelItem) => {
        if (item.id === optionId) {
            return { ...item, selected: !item.selected };
        }
        return item;
    });

    const allSelected = cancelItems.every((item: CancelItem) => item.selected);

    G7Core.state.setLocal({
        cancelItems,
        cancelSelectAll: allSelected,
    });

    // 환불 예상금액 재계산
    if (userEstimateDebounceTimer) {
        clearTimeout(userEstimateDebounceTimer);
    }
    userEstimateDebounceTimer = setTimeout(() => {
        estimateUserRefundInternal(G7Core, orderId);
    }, 500);
}

/**
 * 사용자 취소 전체 선택/해제 핸들러
 *
 * @param action 액션 객체 (params.orderId: 주문 ID)
 * @param _context 액션 컨텍스트
 */
export function toggleUserCancelSelectAllHandler(
    action: ActionWithParams<{ orderId: number | string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId } = action.params || {};
    const local = G7Core.state.getLocal();
    const newSelectAll = !local.cancelSelectAll;

    const cancelItems: CancelItem[] = (local.cancelItems || []).map((item: CancelItem) => ({
        ...item,
        selected: newSelectAll,
    }));

    G7Core.state.setLocal({
        cancelItems,
        cancelSelectAll: newSelectAll,
    });

    // 환불 예상금액 재계산
    if (userEstimateDebounceTimer) {
        clearTimeout(userEstimateDebounceTimer);
    }
    userEstimateDebounceTimer = setTimeout(() => {
        estimateUserRefundInternal(G7Core, orderId);
    }, 500);
}

/**
 * 사용자 취소 수량 변경 핸들러
 *
 * @param action 액션 객체 (params.optionId, params.maxQuantity, params.orderId)
 * @param _context 액션 컨텍스트
 */
export function updateUserCancelQuantityHandler(
    action: ActionWithParams<{ optionId: number; maxQuantity: number; orderId: number | string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { optionId, maxQuantity, orderId } = action.params || {};
    // 회원: orderId 필수. 비회원: orderId 없어도 토큰+order_number 로 비회원 endpoint 분기.
    if (!optionId || !maxQuantity || (!orderId && !hasGuestOrderContext(G7Core))) return;

    const rawValue = action.params?.value ?? (action as any).$event?.target?.value;
    const parsed = parseInt(String(rawValue), 10);
    const clamped = Math.max(1, Math.min(isNaN(parsed) ? maxQuantity : parsed, maxQuantity));

    const local = G7Core.state.getLocal();
    const cancelItems: CancelItem[] = (local.cancelItems || []).map((item: CancelItem) => {
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
    if (userEstimateDebounceTimer) {
        clearTimeout(userEstimateDebounceTimer);
    }
    userEstimateDebounceTimer = setTimeout(() => {
        const previousContext = (window as any).__g7ActionContext;
        (window as any).__g7ActionContext = savedActionContext;
        try {
            estimateUserRefundInternal(G7Core, orderId);
        } finally {
            (window as any).__g7ActionContext = previousContext;
        }
    }, 500);
}

/**
 * 사용자 환불 예상금액 계산 핸들러
 *
 * @param action 액션 객체 (params.orderId: 주문 ID)
 * @param _context 액션 컨텍스트
 */
export async function estimateUserRefundHandler(
    action: ActionWithParams<{ orderId: number | string }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId } = action.params || {};
    // 회원: orderId 필수. 비회원: orderId 없어도 토큰+order_number 로 estimateUserRefundInternal 가 비회원 URL 분기.
    if (!orderId && !hasGuestOrderContext(G7Core)) return;

    await estimateUserRefundInternal(G7Core, orderId);
}

/**
 * 사용자 환불 예상금액 내부 계산 함수
 *
 * @param G7Core G7Core 인스턴스
 * @param orderId 주문 ID
 */
async function estimateUserRefundInternal(G7Core: any, orderId: number | string): Promise<void> {
    const local = G7Core.state.getLocal();
    const cancelItems: CancelItem[] = local.cancelItems || [];
    const refundPriority = local.refundPriority || 'pg_first';

    const selectedItems = cancelItems.filter((item: CancelItem) => item.selected);

    if (selectedItems.length === 0) {
        G7Core.state.setLocal({ refundEstimate: null, refundLoading: false });
        return;
    }

    const items = selectedItems.map((item: CancelItem) => ({
        order_option_id: item.id,
        cancel_quantity: item.cancel_quantity,
    }));

    G7Core.state.setLocal({ refundLoading: true });

    try {
        const guestToken = G7Core.state.get?.('_global')?.guestOrderToken;
        const orderNumber = G7Core.dataSource?.get?.('order')?.data?.order_number;
        const isGuest = Boolean(guestToken && orderNumber);
        const url = isGuest
            ? `/api/modules/sirsoft-ecommerce/guest/orders/${orderNumber}/estimate-refund`
            : `/api/modules/sirsoft-ecommerce/user/orders/${orderId}/estimate-refund`;
        // ApiClient(axios) 인터셉터는 globalHeaders 를 적용하지 않으므로 비회원 endpoint 호출 시 토큰 헤더를 명시적으로 첨부.
        const config = isGuest ? { headers: { 'X-Guest-Order-Token': guestToken } } : undefined;

        const response = await G7Core.api.post(
            url,
            { items, refund_priority: refundPriority },
            config
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
        logger.error('[estimateUserRefund] 실패:', error);
        G7Core.state.setLocal({
            refundLoading: false,
            refundEstimate: null,
        });
        G7Core.toast?.error?.(
            G7Core.t?.('mypage.order_detail.cancel_modal.estimate_failed')
            ?? '환불 예상금액 계산에 실패했습니다.'
        );
    }
}

/**
 * 사용자 환불 우선순위 변경 핸들러
 *
 * 환불 우선순위를 변경하고, debounce로 환불 예상금액을 재계산합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.changeUserRefundPriority",
 *   "params": { "priority": "points_first", "orderId": 123 }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function changeUserRefundPriorityHandler(
    action: ActionWithParams<{ priority: string; orderId: number | string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { priority, orderId } = action.params || {};
    // 회원: orderId 필수. 비회원: orderId 없어도 토큰+order_number 로 비회원 endpoint 분기.
    if (!priority || (!orderId && !hasGuestOrderContext(G7Core))) return;

    G7Core.state.setLocal({ refundPriority: priority });

    // Debounce로 환불 예상금액 재계산
    // engine-v1.24.7: debounce 콜백에서 actionContext 캡처/복원
    const savedActionContext = (window as any).__g7ActionContext;
    if (userEstimateDebounceTimer) {
        clearTimeout(userEstimateDebounceTimer);
    }
    userEstimateDebounceTimer = setTimeout(() => {
        const previousContext = (window as any).__g7ActionContext;
        (window as any).__g7ActionContext = savedActionContext;
        try {
            estimateUserRefundInternal(G7Core, orderId);
        } finally {
            (window as any).__g7ActionContext = previousContext;
        }
    }, 300);
}

/**
 * 사용자 주문 취소 실행 핸들러
 *
 * @param action 액션 객체 (params.orderId: 주문 ID)
 * @param _context 액션 컨텍스트
 */
export async function executeUserCancelOrderHandler(
    action: ActionWithParams<{ orderId: number | string; cancelReason?: string; cancelReasonDetail?: string; refundPriority?: string }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId } = action.params || {};
    // 회원: orderId 필수. 비회원: orderId 없어도 토큰+order_number 로 비회원 endpoint 분기.
    if (!orderId && !hasGuestOrderContext(G7Core)) return;

    const local = G7Core.state.getLocal();
    const cancelItems: CancelItem[] = local.cancelItems || [];
    const selectedItems = cancelItems.filter((item: CancelItem) => item.selected);
    // cancelReason/refundPriority: params 우선, fallback으로 local (모달 스코프 호환)
    const cancelReason = action.params?.cancelReason || local.cancelReason || '';
    const cancelReasonDetail = action.params?.cancelReasonDetail ?? local.cancelReasonDetail ?? '';
    const refundPriority = action.params?.refundPriority || local.refundPriority || 'pg_first';

    if (selectedItems.length === 0) {
        G7Core.toast?.warning?.(
            G7Core.t?.('mypage.order_detail.cancel_modal.no_selected_items')
            ?? '취소할 상품을 선택해주세요.'
        );
        return;
    }

    if (!cancelReason) {
        G7Core.toast?.warning?.(
            G7Core.t?.('mypage.order_detail.cancel_modal.reason_required')
            ?? '취소 사유를 선택해주세요.'
        );
        return;
    }

    // 선택한 항목 부분집합을 항상 items 로 전송한다. 프론트에서 전체취소를 휴리스틱으로 판단하지 않는다.
    // 진짜 전체취소(모든 활성 옵션 전량)도 items 전량으로 보내면 백엔드 shouldConvertToFullCancel 이
    // FULL 로 승격해 동작·환불 결과가 동일하다. 종전 isFullCancel 휴리스틱은 다중항목 중 일부 전량 선택을
    // 전체취소로 오판해 items 를 누락시켜 주문 전체가 취소되는 결함이 있었다.
    const body: Record<string, any> = {
        reason: cancelReason,
        refund_priority: refundPriority,
        items: selectedItems.map((item: CancelItem) => ({
            order_option_id: item.id,
            cancel_quantity: item.cancel_quantity,
        })),
    };

    // 기타 사유 선택 시 상세 사유 전송 (선택 입력 — 관리자 패리티)
    if (cancelReasonDetail) {
        body.reason_detail = cancelReasonDetail;
    }

    G7Core.state.setLocal({ isCancelling: true, cancelError: null, cancelValidationErrors: null });

    try {
        const guestToken = G7Core.state.get?.('_global')?.guestOrderToken;
        const orderNumber = G7Core.dataSource?.get?.('order')?.data?.order_number;
        const isGuest = Boolean(guestToken && orderNumber);
        const url = isGuest
            ? `/api/modules/sirsoft-ecommerce/guest/orders/${orderNumber}/cancel`
            : `/api/modules/sirsoft-ecommerce/user/orders/${orderId}/cancel`;
        // ApiClient(axios) 인터셉터는 globalHeaders 를 적용하지 않으므로 비회원 endpoint 호출 시 토큰 헤더를 명시적으로 첨부.
        const config = isGuest ? { headers: { 'X-Guest-Order-Token': guestToken } } : undefined;

        const response = await G7Core.api.post(
            url,
            body,
            config
        );

        if (response?.success) {
            G7Core.state.setLocal({ isCancelling: false });
            G7Core.toast?.success?.(
                G7Core.t?.('mypage.order_detail.cancel_success')
                ?? '주문이 취소되었습니다.'
            );

            G7Core.modal?.close?.('modal_cancel_order');
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
        } else {
            throw new Error(response?.message || 'Cancel failed');
        }
    } catch (error: any) {
        logger.error('[executeUserCancelOrder] 실패:', error);

        const errorData = (error as any)?.response?.data || (error as any)?.data;
        const detailMessage = errorData?.message || error?.message || '';
        const validationErrors = errorData?.errors ?? null;
        const fallbackMessage = G7Core.t?.('mypage.order_detail.cancel_modal.cancel_failed')
            ?? '주문 취소에 실패했습니다.';

        G7Core.state.setLocal({
            isCancelling: false,
            cancelError: detailMessage || fallbackMessage,
            cancelValidationErrors: validationErrors,
        });

        G7Core.toast?.error?.(detailMessage || fallbackMessage);
    }
}
