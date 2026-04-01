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
/**
 * 장바구니 개별 상품 선택/해제
 *
 * 체크박스 상태 변경 시 selectedItems 배열을 업데이트하고,
 * 선택된 상품 기준으로 주문 계산을 다시 요청합니다.
 *
 * @param action 액션 정의 (params에 itemId, selectedItems 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function toggleCartItemSelectionHandler(action?: any, _context?: any): void;
/**
 * 장바구니 전체 선택/해제
 *
 * 전체 선택 체크박스 상태 변경 시 모든 상품을 선택하거나 해제하고,
 * 선택된 상품 기준으로 주문 계산을 다시 요청합니다.
 *
 * @param action 액션 정의 (params에 allItemIds, isSelected 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function selectAllCartItemsHandler(action?: any, _context?: any): void;
/**
 * 옵션 변경 모달에서 옵션 값 설정
 *
 * 옵션 선택 시 selectedOptions 상태를 업데이트합니다.
 *
 * @param action 액션 정의 (params에 optionName, optionValue 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function setCartOptionHandler(action?: any, _context?: any): void;
/**
 * 삭제 확인 모달 열기
 *
 * 선택된 상품 삭제 버튼 클릭 시 삭제 확인 모달을 엽니다.
 *
 * @param action 액션 정의 (params에 targetIds 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function openCartDeleteModalHandler(action?: any, _context?: any): void;
/**
 * 옵션 변경 모달 열기
 *
 * 상품 옵션 변경 버튼 클릭 시 옵션 변경 모달을 엽니다.
 *
 * @param action 액션 정의 (params에 targetItem 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function openCartOptionModalHandler(action?: any, _context?: any): void;
/**
 * 장바구니 아이템 선택 후 계산 갱신 요청
 *
 * 선택된 아이템이 변경되면 서버에 계산 요청을 보냅니다.
 *
 * @param action 액션 정의 (params에 selectedItems 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function recalculateCartHandler(action?: any, _context?: any): void;
