/**
 * 사용자 주문 취소 핸들러 — 7.0.x 기본 템플릿 복사본 호환 전용
 *
 * 7.1 은 마이페이지 주문 취소를 클레임 신청 흐름(claimApplyHandlers)으로 바꾸며 이 핸들러들의 등록을 뺐다.
 * 그 뒤 7.0.x 기본 사용자 템플릿을 복사한 화면은 주문 상세 「주문 취소」 창의 버튼(품목 선택·수량·환불
 * 우선순위·취소 실행)이 눌러도 반응하지 않았다(템플릿 호환 대장 handler:sirsoft-ecommerce.*UserCancel* —
 * 결정 「호환 처리」, 2026-11-08). 그래서 7.0.11 구현을 그대로 되살려 예전 이름으로만 등록한다.
 *
 * - 부르는 API(회원 /user/orders/{id}/cancel·estimate-refund, 비회원 /guest/orders/{번호}/…)와 그 요청 규칙은
 *   7.0.11 과 같다(현재 기본 템플릿은 쓰지 않는 구 취소 API). 주문 옵션 응답 필드도 그대로다.
 * - 현재 기본 템플릿은 이 이름을 부르지 않는다 — 새 화면은 claimApplyHandlers 를 쓴다. 새 기능을 여기에 더하지 않는다.
 * - 원본: 7.0.11 handlers/userCancelOrderHandlers.ts (동작 같음 — 엔진 비공개 전역 대신 받은 컨텍스트로 debounce 결과를 모달에 반영하도록만 바꿈).
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

/**
 * 엔진이 커스텀 핸들러에 넘기는 액션 컨텍스트 중 이 파일이 쓰는 부분.
 * `setState` 는 핸들러를 부른 컴포넌트(취소 모달)의 로컬 상태 writer 다.
 */
type HandlerContext = ActionContext & {
    setState?: (updates: Record<string, any>) => void;
};

/** 로컬 상태 쓰기 함수 */
type LocalWriter = (updates: Record<string, any>) => void;

/**
 * debounce 콜백에서 쓸 로컬 상태 writer 를 만듭니다.
 *
 * 7.0.11 원본은 엔진 내부 전역(액션 컨텍스트)을 잡아 두었다 바꿔 끼웠다. 그 전역은 엔진이 언제든
 * 바꿀 수 있는 비공개 내부라, 현재 cancelOrderHandlers 와 같이 핸들러가 받은 컨텍스트의 `setState` 로
 * 모달에 반영한다(동작은 같다 — 계산 결과가 정본 `_local` 과 모달 양쪽에 들어간다).
 *
 * @param G7Core G7Core 인스턴스
 * @param context 핸들러가 받은 액션 컨텍스트
 * @returns 로컬 상태 writer
 */
function deferredLocalWriter(G7Core: any, context?: HandlerContext): LocalWriter {
    const componentSetState = typeof context?.setState === 'function' ? context.setState : null;

    return (updates) => {
        G7Core.state.setLocal(updates);
        componentSetState?.(updates);
    };
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
    context: HandlerContext
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

    // Debounce로 환불 예상금액 재계산 — 콜백 시점에는 액션이 끝나 엔진이 모달을 모른다.
    // 핸들러가 받은 컨텍스트로 writer 를 만들어 두어야 계산 결과가 모달에 반영된다.
    const writeLocal = deferredLocalWriter(G7Core, context);
    if (userEstimateDebounceTimer) {
        clearTimeout(userEstimateDebounceTimer);
    }
    userEstimateDebounceTimer = setTimeout(() => {
        void estimateUserRefundInternal(G7Core, orderId, writeLocal);
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
 * @param writeLocal 로컬 상태 writer (debounce 콜백은 핸들러 컨텍스트로 만든 writer 를 넘긴다)
 */
async function estimateUserRefundInternal(
    G7Core: any,
    orderId: number | string,
    writeLocal: LocalWriter = (updates) => G7Core.state.setLocal(updates),
): Promise<void> {
    const local = G7Core.state.getLocal();
    const cancelItems: CancelItem[] = local.cancelItems || [];
    const refundPriority = local.refundPriority || 'pg_first';

    const selectedItems = cancelItems.filter((item: CancelItem) => item.selected);

    if (selectedItems.length === 0) {
        writeLocal({ refundEstimate: null, refundLoading: false });
        return;
    }

    const items = selectedItems.map((item: CancelItem) => ({
        order_option_id: item.id,
        cancel_quantity: item.cancel_quantity,
    }));

    writeLocal({ refundLoading: true });

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
            writeLocal({
                refundEstimate: response.data,
                refundLoading: false,
                cancelError: null,
            });
        } else {
            throw new Error(response?.message || 'Estimate failed');
        }
    } catch (error: any) {
        logger.error('[estimateUserRefund] 실패:', error);
        writeLocal({
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
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { priority, orderId } = action.params || {};
    // 회원: orderId 필수. 비회원: orderId 없어도 토큰+order_number 로 비회원 endpoint 분기.
    if (!priority || (!orderId && !hasGuestOrderContext(G7Core))) return;

    G7Core.state.setLocal({ refundPriority: priority });

    // Debounce로 환불 예상금액 재계산 — 수량 변경과 같은 이유로 받은 컨텍스트의 writer 를 쓴다
    const writeLocal = deferredLocalWriter(G7Core, context);
    if (userEstimateDebounceTimer) {
        clearTimeout(userEstimateDebounceTimer);
    }
    userEstimateDebounceTimer = setTimeout(() => {
        void estimateUserRefundInternal(G7Core, orderId, writeLocal);
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
