/**
 * 구매확정 관련 핸들러
 *
 * 사용자 마이페이지 주문상세에서 구매확정 처리를 담당합니다.
 * - 구매확정 API 호출
 * - 성공 시 모달 닫기 + 데이터 리패치 + 토스트
 * - 실패 시 토스트 에러
 */

import type { ActionContext } from '../types';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:ConfirmOrder')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ConfirmOrder]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ConfirmOrder]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ConfirmOrder]', ...args),
};

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

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
 * 구매확정 핸들러
 *
 * 주문 옵션을 구매확정 처리합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.confirmOrderOption",
 *   "params": { "orderId": 1, "optionId": 2 }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function confirmOrderOptionHandler(
    action: ActionWithParams<{ orderId: number; optionId: number }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId, optionId } = action.params || {};
    // 회원: orderId+optionId 필수. 비회원: orderId 없어도 토큰+order_number 로 비회원 endpoint 분기, optionId 는 항상 필수.
    if (!optionId || (!orderId && !hasGuestOrderContext(G7Core))) {
        logger.error('[confirmOrderOption] optionId 또는 식별자 누락');
        return;
    }

    G7Core.state.setLocal({ isConfirming: true });

    try {
        const guestToken = G7Core.state.get?.('_global')?.guestOrderToken;
        const orderNumber = G7Core.dataSource?.get?.('order')?.data?.order_number;
        const isGuest = Boolean(guestToken && orderNumber);
        const url = isGuest
            ? `/api/modules/sirsoft-ecommerce/guest/orders/${orderNumber}/options/${optionId}/confirm`
            : `/api/modules/sirsoft-ecommerce/user/orders/${orderId}/options/${optionId}/confirm`;
        // ApiClient(axios) 인터셉터는 globalHeaders 를 적용하지 않으므로 비회원 endpoint 호출 시 토큰 헤더를 명시적으로 첨부.
        const config = isGuest ? { headers: { 'X-Guest-Order-Token': guestToken } } : undefined;

        const response = await G7Core.api.post(url, undefined, config);

        if (response?.success) {
            G7Core.state.setLocal({ isConfirming: false, confirmTarget: null });
            G7Core.modal?.close?.('modal_confirm_purchase');
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
            G7Core.toast?.success?.(
                response.message
                ?? G7Core.t?.('sirsoft-ecommerce::messages.order.confirmed')
                ?? '구매확정이 완료되었습니다.'
            );
        } else {
            throw new Error(response?.message || 'Confirm failed');
        }
    } catch (error: any) {
        logger.error('[confirmOrderOption] 실패:', error);

        const errorMessage = error?.response?.data?.message
            || error?.data?.message
            || error?.message
            || G7Core.t?.('sirsoft-ecommerce::messages.order.cannot_confirm')
            || '구매확정에 실패했습니다.';

        G7Core.state.setLocal({ isConfirming: false });
        G7Core.toast?.error?.(errorMessage);
    }
}
