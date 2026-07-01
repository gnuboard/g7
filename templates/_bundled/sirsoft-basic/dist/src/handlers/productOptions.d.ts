/**
 * 상품 옵션 선택 관련 핸들러
 *
 * 상품 상세 페이지에서 옵션 선택 및 수량 변경을 처리합니다.
 * DB의 option_groups (문자열 값 배열)과 options (ProductOption 레코드)를 사용합니다.
 *
 * G7Core ActionDispatcher는 커스텀 핸들러를 (action, context) 시그니처로 호출합니다.
 * - action.params: 레이아웃 JSON에서 정의한 params (resolveParams로 이미 해석됨)
 * - context.setState: 컴포넌트 상태 업데이트 함수
 *
 * ⚠️ sequence 내에서 setState 후 다음 액션의 context.data._local은 갱신되지 않음
 *    (ActionDispatcher.handleSequence는 state만 동기화, data._local은 미갱신)
 *    따라서 이 핸들러는 newGroupName+newValue를 직접 받아서 currentSelection을 자체 구성합니다.
 */
/**
 * 블럭별 추가옵션 선택 상태 (additional_option_id → value_id)
 */
type AdditionalOptionSelections = Record<number, number>;
/**
 * G7Core ActionContext (ActionDispatcher에서 전달)
 */
interface ActionContext {
    data?: any;
    event?: Event;
    state?: any;
    setState?: (updates: any) => void;
}
/**
 * G7Core ActionDefinition (커스텀 핸들러 첫 번째 인자)
 */
interface ActionDefinition {
    handler: string;
    params?: Record<string, any>;
    target?: string;
    [key: string]: any;
}
/**
 * 추가옵션 선택을 백엔드 입력 형식으로 변환한다.
 * additional_option_selections: [{ additional_option_id, value_id }]
 *
 * @param selections 블럭별 추가옵션 선택 (그룹ID → 선택지ID)
 * @param customTexts 블럭별 직접입력 텍스트 (그룹ID → custom_text)
 * @returns 백엔드 입력 배열
 */
export declare function toAdditionalOptionSelectionsPayload(selections: AdditionalOptionSelections | undefined, customTexts?: Record<number, string>): Array<{
    additional_option_id: number;
    value_id: number;
    custom_text?: string;
}>;
/**
 * 옵션 선택 완료 시 selectedItems에 추가
 *
 * 모든 옵션 그룹이 선택되면 매칭되는 ProductOption을 찾아 선택 목록에 추가합니다.
 * 동일한 옵션 조합이 이미 있으면 토스트 알림 + 선택 초기화합니다.
 *
 * ⚠️ sequence 내 setState 후 context.data._local이 갱신되지 않는 G7Core 한계 때문에
 *    newGroupName + newValue를 직접 받아서 currentSelection을 자체 병합합니다.
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export declare function addSelectedItemIfCompleteHandler(action: ActionDefinition, context: ActionContext): void;
/**
 * 선택된 상품의 수량 변경 및 가격 재계산
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export declare function updateSelectedItemQuantityHandler(action: ActionDefinition, context: ActionContext): void;
/**
 * 블럭별 추가옵션 선택 변경 및 가격 재계산
 *
 * 특정 옵션 블럭의 추가옵션 그룹 선택을 갱신하고, 추가금 합계·소계·다통화를 재계산한다.
 * 가격 표시는 클라이언트 계산이며 결제금액 SSoT 는 서버(plan D13).
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export declare function setBlockAdditionalOptionHandler(action: ActionDefinition, context: ActionContext): void;
/**
 * 선택된 옵션 항목을 제거하고 통화별 합계를 재계산합니다.
 *
 * 레이아웃에서 filter 로 직접 제거하면 selectedTotalMultiCurrency(통화별 합계 포맷)를
 * 갱신할 수 없어(표현식은 핸들러 호출 불가) stale 합계가 남는다. 제거도 핸들러로 처리해
 * 합계 맵을 함께 재계산한다(D2).
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export declare function removeSelectedItemHandler(action: ActionDefinition, context: ActionContext): void;
/**
 * 옵션 없는 상품의 수량을 변경하고 통화별 총액 포맷 맵을 재계산합니다.
 *
 * 옵션 없는 상품 총액 = 단가 × 수량. 레이아웃 표현식은 핸들러를 호출할 수 없어
 * 통화별 포맷(소수점/기호)을 만들 수 없으므로, 수량 변경 시 통화별 총액 formatted 를
 * noOptionTotalMultiCurrency 에 미리 계산해 노출한다(KRW 고정 결함 D3 해소).
 *
 * @param action.params.newQuantity 변경 수량
 * @param action.params.multiCurrencyUnitPrice 단가 통화맵(product.multi_currency_selling_price)
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export declare function updateNoOptionQuantityHandler(action: ActionDefinition, context: ActionContext): void;
export {};
