/**
 * 장바구니 관련 핸들러
 *
 * 장바구니 페이지에서 상품 선택, 옵션 변경, 삭제 등을 처리합니다.
 *
 * 핸들러 시그니처: ActionDispatcher의 ActionHandler 형식을 따름
 * (action: ActionDefinition, context: ActionContext) => void | Promise<void>
 *
 * G7Core API 사용:
 * - G7Core.state.setLocal() - 로컬 상태 설정
 * - G7Core.state.getLocal() - 로컬 상태 조회
 * - G7Core.toast?.show() - 토스트 표시
 * - G7Core.t() - 번역
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:Cart')) ?? {
  log: (...args: unknown[]) => console.log('[Handler:Cart]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:Cart]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:Cart]', ...args),
};

/**
 * 로컬 상태 설정 헬퍼
 */
function setLocalState(updates: Record<string, any>): void {
  const G7Core = (window as any).G7Core;
  if (G7Core?.state?.setLocal) {
    G7Core.state.setLocal(updates);
    logger.log('Local state updated:', updates);
  } else {
    logger.warn('G7Core.state.setLocal not available');
  }
}

/**
 * 로컬 상태 조회 헬퍼
 */
function getLocalState(): Record<string, any> {
  const G7Core = (window as any).G7Core;
  return G7Core?.state?.getLocal?.() || {};
}

/**
 * 전역 상태 조회 헬퍼
 */
function getGlobalState(): Record<string, any> {
  const G7Core = (window as any).G7Core;
  return G7Core?.state?.get?.() || {};
}

/**
 * 토스트 표시 헬퍼
 */
function showToast(message: string, type: 'success' | 'error' | 'warning' | 'info' = 'info'): void {
  const G7Core = (window as any).G7Core;
  if (G7Core?.toast?.show) {
    G7Core.toast.show(message, type);
  } else {
    logger.warn('G7Core.toast.show not available, message:', message);
  }
}

/**
 * 번역 헬퍼
 */
function t(key: string): string {
  const G7Core = (window as any).G7Core;
  if (G7Core?.t) {
    return G7Core.t(key);
  }
  return key;
}

/**
 * 장바구니 데이터소스 재조회 헬퍼
 *
 * 선택된 상품 기준으로 계산 결과를 갱신합니다.
 * selected_ids 파라미터를 전달하여 선택된 아이템만 계산합니다.
 */
function refetchCartItems(): void {
  const G7Core = (window as any).G7Core;
  if (G7Core?.dispatch) {
    const localState = getLocalState();
    const selectedItems = localState.selectedItems || [];

    G7Core.dispatch({
      handler: 'refetchDataSource',
      params: {
        dataSourceId: 'cartItems',
      },
    });
    logger.log('Refetching cartItems with selected_ids:', selectedItems);
  } else {
    logger.warn('G7Core.dispatch not available');
  }
}

/**
 * 장바구니 개별 상품 선택/해제
 *
 * 체크박스 상태 변경 시 selectedItems 배열을 업데이트하고,
 * 선택된 상품 기준으로 주문 계산을 다시 요청합니다.
 *
 * @param action 액션 정의 (params에 itemId, selectedItems 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function toggleCartItemSelectionHandler(
  action?: any,
  _context?: any
): void {
  const { itemId, selectedItems } = action?.params || {};
  const currentSelected = selectedItems || [];

  let newSelected: number[];
  if (currentSelected.includes(itemId)) {
    // 이미 선택되어 있으면 제거
    newSelected = currentSelected.filter((id: number) => id !== itemId);
  } else {
    // 선택되어 있지 않으면 추가
    newSelected = [...currentSelected, itemId];
  }

  // 전체 선택 상태도 업데이트
  const globalState = getGlobalState();
  const allItems = globalState.cartItems?.data?.items || [];
  const allSelected = allItems.length > 0 && newSelected.length === allItems.length;

  setLocalState({
    selectedItems: newSelected,
    allSelected,
    selectedCount: newSelected.length,
  });

  // 선택 변경 후 계산 결과 갱신을 위해 데이터소스 재조회
  refetchCartItems();
}

/**
 * 장바구니 전체 선택/해제
 *
 * 전체 선택 체크박스 상태 변경 시 모든 상품을 선택하거나 해제하고,
 * 선택된 상품 기준으로 주문 계산을 다시 요청합니다.
 *
 * @param action 액션 정의 (params에 allItemIds, isSelected 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function selectAllCartItemsHandler(
  action?: any,
  _context?: any
): void {
  const { allItemIds, isSelected } = action?.params || {};

  setLocalState({
    selectedItems: isSelected ? allItemIds : [],
    allSelected: isSelected,
    selectedCount: isSelected ? allItemIds.length : 0,
  });

  // 선택 변경 후 계산 결과 갱신을 위해 데이터소스 재조회
  refetchCartItems();
}

/**
 * 옵션 변경 모달에서 옵션 값 설정
 *
 * 옵션 선택 시 selectedOptions 상태를 업데이트합니다.
 *
 * @param action 액션 정의 (params에 optionName, optionValue 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function setCartOptionHandler(
  action?: any,
  _context?: any
): void {
  const { optionName, optionValue } = action?.params || {};
  const localState = getLocalState();
  const currentOptions = localState.selectedOptions || {};

  setLocalState({
    selectedOptions: {
      ...currentOptions,
      [optionName]: optionValue,
    },
  });
}

/**
 * 삭제 확인 모달 열기
 *
 * 선택된 상품 삭제 버튼 클릭 시 삭제 확인 모달을 엽니다.
 *
 * @param action 액션 정의 (params에 targetIds 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function openCartDeleteModalHandler(
  action?: any,
  _context?: any
): void {
  const { targetIds } = action?.params || {};

  if (!targetIds || targetIds.length === 0) {
    showToast(t('shop.cart.select_items_to_delete'), 'warning');
    return;
  }

  setLocalState({
    deleteModal: {
      visible: true,
      targetIds,
    },
  });
}

/**
 * 옵션 변경 모달 열기
 *
 * 상품 옵션 변경 버튼 클릭 시 옵션 변경 모달을 엽니다.
 *
 * @param action 액션 정의 (params에 targetItem 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function openCartOptionModalHandler(
  action?: any,
  _context?: any
): void {
  const { targetItem } = action?.params || {};

  setLocalState({
    optionModal: {
      visible: true,
      targetItem,
    },
    selectedOptions: targetItem?.options || {},
    selectedQuantity: targetItem?.quantity,
  });
}

/**
 * 장바구니 아이템 선택 후 계산 갱신 요청
 *
 * 선택된 아이템이 변경되면 서버에 계산 요청을 보냅니다.
 *
 * @param action 액션 정의 (params에 selectedItems 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function recalculateCartHandler(
  action?: any,
  _context?: any
): void {
  const { selectedItems } = action?.params || {};

  if (!selectedItems || selectedItems.length === 0) {
    setLocalState({ isCalculating: false });
    return;
  }

  setLocalState({ isCalculating: true });

  // 계산은 cartItems 데이터 소스 refetch로 처리됨
  // 추가 API 호출이 필요한 경우 여기서 처리
}
