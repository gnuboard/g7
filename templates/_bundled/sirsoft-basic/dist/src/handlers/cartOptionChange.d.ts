/**
 * 장바구니 옵션 변경 관련 핸들러
 *
 * 장바구니 옵션 변경 모달에서 옵션 선택 및 매칭을 처리합니다.
 *
 * 핸들러 시그니처: ActionDispatcher의 ActionHandler 형식을 따름
 * (action: ActionDefinition, context: ActionContext) => void | Promise<void> | any
 *
 * ⚠️ 중요: 커스텀 핸들러에서 dispatch({ target: 'local' })를 사용하면
 * 핸들러 실행 컨텍스트의 _local에 저장됩니다. iteration 내부에서 호출되면
 * 페이지 레벨 _local과 다른 스코프에 저장됩니다.
 *
 * 해결책: 핸들러가 결과를 반환하고, 레이아웃의 onSuccess에서 setState 실행
 */
/**
 * 옵션 값 항목 (다국어 지원 배열 형식)
 */
interface OptionValueItem {
    key: string | Record<string, string>;
    value: string | Record<string, string>;
}
interface ProductOption {
    id: number;
    option_values: OptionValueItem[] | Record<string, string>;
    option_values_localized?: Record<string, string>;
    option_name: string | Record<string, string>;
    option_name_localized?: string;
    selling_price_formatted: string;
    stock_quantity: number;
}
interface InitCartOptionResult {
    selectedOptions: Record<string, string>;
    matchedOption: ProductOption | null;
}
/**
 * 선택된 옵션 조합에 맞는 product_option 찾기
 *
 * 모든 옵션 그룹이 선택되면 일치하는 옵션 조합을 찾아 반환합니다.
 * selectedOptions 구조: { "색상": "화이트", "사이즈": "S" }
 *
 * @param action 액션 정의 (params에 selectedOptions, optionGroups, options 포함)
 * @returns { matchedOption: ProductOption | null }
 */
export declare function findMatchingOptionHandler(action?: any, _context?: any): {
    matchedOption: ProductOption | null;
};
/**
 * 현재 장바구니 아이템의 옵션을 초기 선택값으로 설정
 *
 * 모달이 열릴 때 현재 아이템의 옵션을 선택 상태로 초기화합니다.
 * currentOptionValues 구조: { "색상": "블랙", "사이즈": "S" }
 *
 * @param action 액션 정의 (params에 currentOptionValues, optionGroups, options 포함)
 * @returns { selectedOptions: Record<string, string>, matchedOption: ProductOption | null }
 */
export declare function initCartOptionSelectionHandler(action?: any, _context?: any): InitCartOptionResult;
export {};
