/**
 * 주문 상품 선택 핸들러 (회원/비회원 주문상세 공용)
 *
 * 주문상세 상품 목록의 체크박스(개별 · 전체)를 토글한다. 체크한 상품은 클레임 신청 버튼
 * (교환/반품/취소·환불)이 `query.options` 로 작성 화면에 넘긴다.
 *
 * 옛 주문 취소 모달(`userCancelOrderHandlers.ts`)에서 **본문 무수정**으로 옮겨 온 두 핸들러다 —
 * 그 모달은 #599 후속으로 삭제됐지만 이 선택 축은 클레임 진입이 계속 쓴다.
 */

import type { ActionContext } from '../types';

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
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
