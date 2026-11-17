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
 * 옵션 값 항목 (다국어 지원 배열 형식)
 */
interface OptionValueItem {
    key: string | Record<string, string>;
    value: string | Record<string, string>;
}
/**
 * ProductOption 레코드
 * option_values: 배열 형식 (신규) 또는 객체 형식 (레거시)
 * option_values_localized: 현재 로케일 값 객체 (신규)
 */
interface ProductOptionRecord {
    id: number;
    option_code: string;
    option_values: OptionValueItem[] | Record<string, string>;
    option_values_localized?: Record<string, string>;
    option_name: string | Record<string, string>;
    option_name_localized?: string;
    price_adjustment: number;
    selling_price: number;
    selling_price_formatted: string;
    list_price: number;
    list_price_formatted: string;
    multi_currency_selling_price?: Record<string, {
        value: number;
        formatted: string;
    }>;
    multi_currency_list_price?: Record<string, {
        value: number;
        formatted: string;
    }>;
    stock_quantity: number;
    is_active: boolean;
}
/**
 * 추가옵션 선택지 (PublicProductResource.additional_options[].values[])
 */
interface AdditionalOptionValue {
    id: number;
    name: string;
    /** 추가금 (쇼핑몰 **기본 통화** 기준 — KRW 고정이 아니다) */
    price_adjustment: number;
    /**
     * 통화별 추가금 (서버 `multi_currency_price_adjustment`).
     *
     * 표시통화가 기본통화와 다르면 `price_adjustment` 를 그대로 쓰면 안 된다.
     * 상품가는 환산되어 오는데 추가금만 기준통화로 남으면 합계에서 통화가 섞인다.
     */
    multi_currency_price_adjustment?: Record<string, {
        price?: number;
        value?: number;
        formatted: string;
    }>;
    is_default: boolean;
    /** 직접입력 허용 — 이 선택지 선택 시 자유 텍스트 입력칸 노출(입력 필수) */
    allow_custom_text?: boolean;
}
/**
 * 추가옵션 그룹 (PublicProductResource.additional_options[])
 */
interface AdditionalOptionGroup {
    id: number;
    name: string;
    is_required: boolean;
    values: AdditionalOptionValue[];
}
/**
 * 블럭별 추가옵션 선택 상태 (additional_option_id → value_id)
 */
type AdditionalOptionSelections = Record<number, number>;
interface SelectedItem {
    id: string;
    optionId: number;
    options: Record<string, string>;
    optionValues: Record<string, string>;
    quantity: number;
    stock: number;
    unitPrice: number;
    unitPriceFormatted: string;
    totalPrice: number;
    totalPriceFormatted: string;
    multiCurrencyUnitPrice?: Record<string, {
        value: number;
        formatted: string;
    }>;
    multiCurrencyTotalPrice?: Record<string, {
        value: number;
        formatted: string;
    }>;
    /** 블럭별 추가옵션 선택 (그룹ID → 선택지ID) */
    additionalOptionSelections?: AdditionalOptionSelections;
    /** 블럭별 추가옵션 직접입력 텍스트 (그룹ID → custom_text). allow_custom_text 선택지 한정 */
    additionalOptionCustomTexts?: Record<number, string>;
    /** 블럭별 추가옵션 추가금 합계 (기본 통화 기준, 단위당) */
    additionalOptionsTotal?: number;
    /** 블럭별 추가옵션 추가금 합계의 통화별 값 (단위당). 표시통화 합계 계산에 쓴다 */
    additionalOptionsMultiCurrencyTotal?: Record<string, {
        value: number;
    }>;
}
/**
 * 상태 쓰기 대상 선택 인자 — 모든 옵션 핸들러가 함께 받는다.
 * 주지 않으면 화면 상태(`_local`)에 쓴다.
 */
export interface OptionStateTargetParams {
    /** 'global' 이면 전역 상태에 쓴다 (기본 'local') */
    stateTarget?: 'local' | 'global';
    /** 전역에 쓸 때의 이름 공간 (예: 'quickOptionModal') */
    statePath?: string;
}
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
 * 옵션 한 개로 「선택한 상품」 한 줄을 만든다.
 *
 * 필수 추가옵션 그룹에 한해 기본 선택지(is_default)를 미리 적용한다. 비필수 그룹은 고르지 않은 상태로 둔다 —
 * 추가옵션 선택지는 그룹마다 하나가 늘 기본으로 표시돼 있어(관리자 폼이 첫 선택지를 기본으로 채운다),
 * 비필수까지 자동 적용하면 원하지 않은 추가금이 붙는다.
 *
 * @param input.id 줄 식별값 (옵션 조합 키)
 * @param input.option 고른 옵션 레코드
 * @param input.optionLabels 옵션 그룹명 → 고른 값
 * @param input.preferredCurrency 표시 통화
 * @param input.additionalOptionGroups 상품 추가옵션 목록
 * @param input.quantity 처음 수량 (기본 1)
 * @returns 선택 줄
 */
export declare function buildSelectedItemFromOption(input: {
    id: string;
    option: ProductOptionRecord;
    optionLabels: Record<string, string>;
    preferredCurrency?: string;
    additionalOptionGroups?: AdditionalOptionGroup[];
    quantity?: number;
}): SelectedItem;
/**
 * 선택 줄들의 통화별 합계를 만든다 (창을 여는 쪽이 미리 채운 줄의 합계용).
 *
 * @param items 선택 줄 목록
 * @returns 통화코드 => { value, formatted }
 */
export declare function buildSelectedItemsTotal(items: SelectedItem[]): Record<string, {
    value: number;
    formatted: string;
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
/**
 * 추가옵션 한 그룹의 선택·직접 입력 값을 바꾼 새 맵을 돌려줍니다(입력 맵은 바꾸지 않음).
 *
 * - 직접 입력 글자(`customText` 가 주어짐): 글자만 바꾼다. 빈 글자면 그 그룹의 글자를 지운다.
 * - 선택 해제(`valueId` 가 null): 선택과 글자를 모두 지운다.
 * - 값 선택: 고른 값이 직접 입력을 허용하지 않으면 남아 있던 글자를 지운다.
 *
 * @param currentSelections 지금 선택 (그룹 id → 값 id)
 * @param currentCustomTexts 지금 직접 입력 글자 (그룹 id → 글자)
 * @param additionalOptionId 바꿀 그룹 id
 * @param valueId 고른 값 id (선택 해제면 null)
 * @param customText 직접 입력 글자 (값 선택 변경이면 undefined)
 * @param groups 상품의 추가옵션 그룹
 * @returns 새 선택·글자 맵
 */
export declare function applyAdditionalOptionChange(currentSelections: AdditionalOptionSelections | undefined, currentCustomTexts: Record<number, string> | undefined, additionalOptionId: number, valueId: number | null, customText: string | undefined, groups: AdditionalOptionGroup[] | undefined): {
    selections: AdditionalOptionSelections;
    customTexts: Record<number, string>;
};
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
/**
 * 옵션 하나 상품(수량 선택기로 사는 상품)의 추가옵션을 고르거나 직접 입력 글자를 넣습니다.
 *
 * 상세 구매 카드는 추가옵션 칸을 「고른 옵션 줄」 안에만 그려, 옵션 하나 상품은 추가옵션을 고를 수 없었다(필수
 * 추가옵션이 있으면 담기 422). 이 상품의 선택은 `noOptionAdditionalSelections`·`noOptionAdditionalCustomTexts` 에
 * 두고, 합계 `noOptionTotalMultiCurrency` 에 추가 금액을 더한다.
 *
 * @param action.params.additionalOptionId 바꿀 그룹 id
 * @param action.params.valueId 고른 값 id ('' 이면 선택 해제)
 * @param action.params.customText 직접 입력 글자 (이 키가 있으면 글자만 바꿈)
 * @param action.params.selections 지금 선택
 * @param action.params.customTexts 지금 직접 입력 글자
 * @param action.params.additionalOptionGroups 상품의 추가옵션 그룹
 * @param action.params.quantity 지금 수량
 * @param action.params.multiCurrencyUnitPrice 단가 통화맵
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export declare function setNoOptionAdditionalOptionHandler(action: ActionDefinition, context: ActionContext): void;
export {};
