/**
 * 주문 배송지 변경 핸들러
 *
 * 마이페이지 주문 상세(회원) / 비회원 주문 상세에서 배송지 변경 모달의 적용을 처리합니다.
 * - 회원: 저장된 배송지(address_id) 또는 직접 입력(editingShippingAddress) 전송
 * - 비회원: 직접 입력만 (저장된 주소 없음), guest endpoint + X-Guest-Order-Token 헤더
 * - 성공 시 모달 닫기 + order 데이터소스 리패치 + 토스트
 * - 실패 시 토스트 에러 + 처리중 상태 복구
 *
 * 토큰 분기는 cancel/estimate-refund/confirm-option 과 동일한 hasGuestOrderContext 패턴.
 */

import type { ActionContext } from '../types';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:ChangeShippingAddress')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ChangeShippingAddress]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ChangeShippingAddress]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ChangeShippingAddress]', ...args),
};

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 비회원 컨텍스트 식별자 확보 여부 — _global.guestOrderToken 과 dataSource('order').data.order_number 가 모두 있으면 true.
 * 비회원 응답(GuestOrderResource)은 id 미노출이므로 회원 식별자(orderId) 가 비어 있어도 본 값으로 비회원 endpoint 호출 가능.
 *
 * @param G7Core G7Core 인스턴스
 * @return bool 비회원 컨텍스트면 true
 */
function hasGuestOrderContext(G7Core: any): boolean {
    const token = G7Core?.state?.get?.('_global')?.guestOrderToken;
    const orderNumber = G7Core?.dataSource?.get?.('order')?.data?.order_number;
    return Boolean(token && orderNumber);
}

/**
 * 배송지 변경 적용 핸들러
 *
 * 회원/비회원 단일 진입점. 모달의 _global 상태(changeAddressMode, selectedAddressId,
 * editingShippingAddress)를 읽어 endpoint/body 를 분기한다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.changeShippingAddress",
 *   "params": { "orderId": 123 }
 * }
 *
 * @param action 액션 객체 (params.orderId: 회원 주문 ID — 비회원은 불필요)
 * @param _context 액션 컨텍스트
 */
export async function changeShippingAddressHandler(
    action: ActionWithParams<{ orderId: number | string }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId } = action.params || {};
    const isGuest = hasGuestOrderContext(G7Core);

    // 회원: orderId 필수. 비회원: 토큰+order_number 로 분기.
    if (!orderId && !isGuest) {
        logger.error('[changeShippingAddress] orderId 또는 비회원 식별자 누락');
        return;
    }

    const globalState = G7Core.state.get?.('_global') ?? {};
    // 비회원은 탭이 없어 항상 직접 입력(manual) 취급. 회원만 saved/manual 분기.
    const mode = isGuest ? 'manual' : (globalState.changeAddressMode ?? 'saved');

    // body 구성: 저장된 배송지 선택(saved) 이면 address_id, 직접 입력(manual)/비회원이면 editingShippingAddress 전체
    const body =
        mode === 'saved'
            ? { address_id: globalState.selectedAddressId }
            : globalState.editingShippingAddress;

    G7Core.state.setLocal({ isSubmittingAddress: true });

    try {
        const guestToken = globalState.guestOrderToken;
        const orderNumber = G7Core.dataSource?.get?.('order')?.data?.order_number;
        const url = isGuest
            ? `/api/modules/sirsoft-ecommerce/guest/orders/${orderNumber}/shipping-address`
            : `/api/modules/sirsoft-ecommerce/user/orders/${orderId}/shipping-address`;
        // ApiClient(axios) 인터셉터는 globalHeaders 를 적용하지 않으므로 비회원 endpoint 호출 시 토큰 헤더를 명시적으로 첨부.
        const config = isGuest ? { headers: { 'X-Guest-Order-Token': guestToken } } : undefined;

        const response = await G7Core.api.put(url, body, config);

        if (response?.success) {
            G7Core.state.setLocal({ isSubmittingAddress: false });
            G7Core.modal?.close?.('changeAddressModal');
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
            G7Core.toast?.success?.(
                response.message
                ?? G7Core.t?.('mypage.order_detail.shipping_address_changed')
                ?? '배송지가 변경되었습니다.'
            );
        } else {
            throw new Error(response?.message || 'Change shipping address failed');
        }
    } catch (error: any) {
        logger.error('[changeShippingAddress] 실패:', error);
        G7Core.state.setLocal({ isSubmittingAddress: false });

        const errorMessage = error?.response?.data?.message
            || error?.data?.message
            || error?.message
            || '배송지 변경에 실패했습니다.';
        G7Core.toast?.error?.(errorMessage);
    }
}
