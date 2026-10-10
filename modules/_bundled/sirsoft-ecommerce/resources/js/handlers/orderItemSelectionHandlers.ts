/**
 * 주문 상품 선택 핸들러 (회원/비회원 주문상세 공용)
 *
 * 주문상세 상품 목록의 체크박스(개별 · 전체)를 토글한다. 체크한 상품은 클레임 신청 버튼
 * (교환/반품/취소·환불)이 `query.options` 로 작성 화면에 넘긴다.
 *
 * 옛 주문 취소 모달(`userCancelOrderHandlers.ts`)에서 옮겨 온 두 핸들러다 — 그 모달은 #599
 * 후속으로 삭제됐지만 이 선택 축은 클레임 진입이 계속 쓴다.
 *
 * **고를 수 있는 상품의 판정은 화면의 체크박스 잠금과 같은 술어다** — 취소되지 않았고, 서버
 * (`claimable-options`)가 그 상품에 열린 클레임 유형을 하나 이상 내려 준 상품. 두 쪽이 다른
 * 술어를 쓰면 전체선택이 잠긴 칸까지 골라, 「고른 상품 전부에 그 유형이 열려 있어야」 켜지는
 * 신청 버튼이 전부 꺼진다(#599 validate 2026-09-23).
 */

import type { ActionContext } from '../types';

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/** `claimable-options` 응답의 상품 한 줄 (필요한 필드만) */
interface ClaimableOptionEntry {
    option?: { id?: number | string } | null;
    claimable_types?: string[] | null;
}

/**
 * 지금 고를 수 있는 주문 옵션 ID 목록을 만듭니다.
 *
 * 화면(`partials/mypage/orders/_items`)의 체크박스 `disabled` 판정과 같은 술어다: 취소되지 않음
 * + 열린 클레임 유형 1개 이상. 클레임 판정은 레이아웃이 넘긴 `claimableOptions` 를 먼저 보고,
 * 없으면 데이터소스 store 를 본다. 둘 다 없으면(판정 전) 종전처럼 취소 여부만 본다.
 *
 * @param G7Core G7Core 인스턴스
 * @param claimableOptions 레이아웃이 넘긴 `claimable.data.options` (없으면 undefined)
 * @returns 고를 수 있는 주문 옵션 ID
 */
export function resolveSelectableItemIds(G7Core: any, claimableOptions?: unknown): number[] {
    const orderOptions: any[] = G7Core.dataSource?.get?.('order')?.data?.options ?? [];
    const claimable: ClaimableOptionEntry[] | null = Array.isArray(claimableOptions)
        ? (claimableOptions as ClaimableOptionEntry[])
        : (Array.isArray(G7Core.dataSource?.get?.('claimable')?.data?.options)
            ? G7Core.dataSource.get('claimable').data.options
            : null);

    const openIds = claimable === null
        ? null
        : new Set(
            claimable
                .filter((entry) => (entry?.claimable_types ?? []).length > 0)
                .map((entry) => Number(entry?.option?.id ?? 0))
        );

    return orderOptions
        .filter((opt: any) => opt.option_status !== 'cancelled')
        .filter((opt: any) => openIds === null || openIds.has(Number(opt.id)))
        .map((opt: any) => opt.id);
}

/**
 * 주문 상품 목록에서 개별 아이템 선택/해제 핸들러
 *
 * selectedItemIds 배열에서 optionId를 추가/제거하고, 고를 수 있는 상품이 전부 골라졌는지를
 * selectAllItems 에 반영합니다.
 *
 * @param action 액션 객체 (params.optionId: 토글할 옵션 ID, params.claimableOptions: claimable 판정)
 * @param _context 액션 컨텍스트
 */
export function toggleItemSelectionHandler(
    action: ActionWithParams<{ optionId: number; claimableOptions?: unknown }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { optionId, claimableOptions } = action.params || ({} as { optionId?: number; claimableOptions?: unknown });
    if (!optionId) return;

    const local = G7Core.state.getLocal();
    const selectedIds: number[] = [...(local.selectedItemIds || [])];
    const idx = selectedIds.indexOf(optionId);

    if (idx >= 0) {
        selectedIds.splice(idx, 1);
    } else {
        selectedIds.push(optionId);
    }

    // 고를 수 있는 상품이 전부 선택되었는지 — 잠긴 칸은 고를 수 없으므로 셈에서 뺀다.
    const selectableIds = resolveSelectableItemIds(G7Core, claimableOptions);
    const allSelected = selectableIds.length > 0 &&
        selectableIds.every((id: number) => selectedIds.includes(id));

    G7Core.state.setLocal({
        selectedItemIds: selectedIds,
        selectAllItems: allSelected,
    });
}

/**
 * 주문 상품 목록에서 전체 선택/해제 핸들러
 *
 * 고를 수 있는 상품(취소되지 않음 + 열린 클레임 유형 있음)을 모두 선택하거나 전체 해제합니다.
 *
 * @param action 액션 객체 (params.claimableOptions: claimable 판정)
 * @param _context 액션 컨텍스트
 */
export function toggleSelectAllItemsHandler(
    action: ActionWithParams<{ claimableOptions?: unknown }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const local = G7Core.state.getLocal();
    const selectableIds = resolveSelectableItemIds(G7Core, action?.params?.claimableOptions);

    const newSelectAll = !local.selectAllItems;

    G7Core.state.setLocal({
        selectedItemIds: newSelectAll ? selectableIds : [],
        selectAllItems: newSelectAll && selectableIds.length > 0,
    });
}
