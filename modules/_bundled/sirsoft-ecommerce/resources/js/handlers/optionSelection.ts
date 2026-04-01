/**
 * 옵션 선택 관련 핸들러
 *
 * 상품 목록 DataGrid에서 옵션 체크박스 선택을 처리합니다.
 * 부모-자식 체크박스 연동 기능을 제공합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:OptionSelect')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:OptionSelect]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:OptionSelect]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:OptionSelect]', ...args),
};

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 * ActionDispatcher는 (action, context) 형태로 핸들러를 호출합니다.
 */
interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 개별 옵션 토글 파라미터
 */
interface ToggleOptionParams {
    productId: number | string;
    optionId: number | string;
    checked: boolean;
}

/**
 * 상품 옵션 전체 토글 파라미터
 */
interface ToggleProductOptionsParams {
    productId: number | string;
    options: Array<{ id: number | string; [key: string]: any }>;
    checked: boolean;
}

/**
 * 옵션 ID 생성 헬퍼
 *
 * @param productId 상품 ID
 * @param optionId 옵션 ID
 * @returns "productId-optionId" 형식의 문자열
 */
const makeOptionKey = (productId: number | string, optionId: number | string): string => {
    return `${productId}-${optionId}`;
};

/**
 * 옵션 키에서 상품 ID 추출
 *
 * @param optionKey "productId-optionId" 형식의 문자열
 * @returns 상품 ID
 */
const getProductIdFromKey = (optionKey: string): string => {
    return optionKey.split('-')[0];
};

/**
 * 개별 옵션 체크박스 토글 핸들러
 *
 * 옵션 체크박스 클릭 시 selectedOptionIds 상태를 업데이트합니다.
 *
 * expandChildren 내에서 호출되지만, DataGrid가 renderItemChildren 호출 시
 * componentContext를 전달하므로 G7Core.state.setLocal()이 부모 DataGrid의
 * dynamicState를 업데이트합니다.
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "basic",
 *   "name": "Checkbox",
 *   "props": {
 *     "checked": "{{(_local.selectedOptionIds || []).includes(row.id + '-' + option.id)}}"
 *   },
 *   "actions": [{
 *     "type": "change",
 *     "handler": "sirsoft-ecommerce.toggleOption",
 *     "params": {
 *       "productId": "{{row.id}}",
 *       "optionId": "{{option.id}}",
 *       "checked": "{{$event.target.checked}}"
 *     }
 *   }]
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음 - G7Core.state.setLocal() 사용)
 */
export function toggleOptionHandler(
    action: ActionWithParams<ToggleOptionParams>,
    _context: ActionContext
): void {
    const params = action.params || ({} as ToggleOptionParams);
    const { productId, optionId, checked } = params;
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        logger.warn('[toggleOption] G7Core.state를 사용할 수 없습니다.');
        return;
    }

    // G7Core.state.getLocal()로 현재 로컬 상태 가져오기
    // DataGrid가 componentContext를 전달하므로 부모의 상태를 가져옴
    const currentLocal = G7Core.state.getLocal();
    const currentSelectedOptions: string[] = currentLocal.selectedOptionIds || [];
    const currentSelectedItems: number[] = currentLocal.selectedItems || [];
    const optionKey = makeOptionKey(productId, optionId);

    let newSelectedOptions: string[];
    if (checked) {
        // 추가
        if (!currentSelectedOptions.includes(optionKey)) {
            newSelectedOptions = [...currentSelectedOptions, optionKey];
        } else {
            newSelectedOptions = currentSelectedOptions;
        }
    } else {
        // 제거
        newSelectedOptions = currentSelectedOptions.filter((key) => key !== optionKey);
    }

    // 상품의 옵션 전체 선택 여부 확인하여 selectedItems 업데이트
    // 데이터소스에서 해당 상품의 옵션 목록 가져오기
    const productsData = G7Core.dataSource?.get('products');
    const products = productsData?.data?.data || [];
    const product = products.find((p: any) => String(p.id) === String(productId));
    const productOptions = product?.options || [];

    let newSelectedItems = [...currentSelectedItems];
    const numericProductId = Number(productId);

    if (productOptions.length > 0) {
        // 해당 상품의 모든 옵션 키
        const allOptionKeys = productOptions.map((opt: any) => makeOptionKey(productId, opt.id));
        // 선택된 옵션 수
        const selectedCount = allOptionKeys.filter((key: string) => newSelectedOptions.includes(key)).length;

        if (selectedCount === productOptions.length) {
            // 모든 옵션 선택됨 → 상품도 선택
            if (!newSelectedItems.includes(numericProductId)) {
                newSelectedItems.push(numericProductId);
            }
        } else {
            // 일부 또는 전체 미선택 → 상품 선택 해제
            newSelectedItems = newSelectedItems.filter((id) => id !== numericProductId);
        }
    }

    // G7Core.state.setLocal()로 부모 컴포넌트 로컬 상태 업데이트
    // DataGrid가 componentContext를 전달하므로 부모의 dynamicState가 업데이트됨
    G7Core.state.setLocal({
        selectedOptionIds: newSelectedOptions,
        selectedItems: newSelectedItems,
    });
}

/**
 * 상품의 모든 옵션 전체 선택/해제 핸들러
 *
 * 상품 체크박스 클릭 시 해당 상품의 모든 옵션을 선택하거나 해제합니다.
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "basic",
 *   "name": "Checkbox",
 *   "props": {
 *     "checked": "{{... 모든 옵션이 선택되었는지 확인 ...}}"
 *   },
 *   "actions": [{
 *     "type": "change",
 *     "handler": "sirsoft-ecommerce.toggleProductOptions",
 *     "params": {
 *       "productId": "{{row.id}}",
 *       "options": "{{row.options}}",
 *       "checked": "{{$event.target.checked}}"
 *     }
 *   }]
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음 - G7Core.state.setLocal() 사용)
 */
export function toggleProductOptionsHandler(
    action: ActionWithParams<ToggleProductOptionsParams>,
    _context: ActionContext
): void {
    const params = action.params || ({} as ToggleProductOptionsParams);
    const { productId, options, checked } = params;
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        logger.warn('[toggleProductOptions] G7Core.state를 사용할 수 없습니다.');
        return;
    }

    // G7Core.state.getLocal()로 현재 로컬 상태 가져오기
    const currentLocal = G7Core.state.getLocal();
    const currentSelectedOptions: string[] = currentLocal.selectedOptionIds || [];
    const currentSelectedItems: number[] = currentLocal.selectedItems || [];

    // 해당 상품의 모든 옵션 키 생성
    const productOptionKeys = (options || []).map((opt: any) =>
        makeOptionKey(productId, opt.id)
    );

    let newSelectedOptions: string[];
    let newSelectedItems = [...currentSelectedItems];
    const numericProductId = Number(productId);

    if (checked) {
        // 모든 옵션 추가 (중복 제거)
        const existingOtherOptions = currentSelectedOptions.filter(
            (key) => getProductIdFromKey(key) !== String(productId)
        );
        newSelectedOptions = [...existingOtherOptions, ...productOptionKeys];
        // 상품도 선택
        if (!newSelectedItems.includes(numericProductId)) {
            newSelectedItems.push(numericProductId);
        }
    } else {
        // 해당 상품의 모든 옵션 제거
        newSelectedOptions = currentSelectedOptions.filter(
            (key) => getProductIdFromKey(key) !== String(productId)
        );
        // 상품도 선택 해제
        newSelectedItems = newSelectedItems.filter((id) => id !== numericProductId);
    }

    // G7Core.state.setLocal()로 컴포넌트 로컬 상태 업데이트
    G7Core.state.setLocal({
        selectedOptionIds: newSelectedOptions,
        selectedItems: newSelectedItems,
    });
}

/**
 * 옵션 테이블 헤더의 전체 선택 체크박스 토글 핸들러
 *
 * expandChildren 내의 옵션 테이블 헤더에서 전체 선택/해제를 처리합니다.
 *
 * @param action 액션 객체 (params 포함)
 * @param context 액션 컨텍스트
 */
export function toggleAllOptionsInRowHandler(
    action: ActionWithParams<ToggleProductOptionsParams>,
    context: ActionContext
): void {
    // toggleProductOptionsHandler와 동일한 로직 사용
    return toggleProductOptionsHandler(action, context);
}

/**
 * 상품의 옵션 선택 상태 계산 핸들러
 *
 * computed에서 사용하여 부모 체크박스의 상태를 결정합니다.
 * - all: 모든 옵션 선택됨
 * - some: 일부 옵션 선택됨 (indeterminate)
 * - none: 선택된 옵션 없음
 *
 * @example
 * // computed에서 사용
 * "computed": {
 *   "productOptionStates": "{{sirsoft-ecommerce.getProductOptionStates(products?.data?.data, _local.selectedOptionIds)}}"
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 * @returns 상품별 선택 상태 맵
 */
export function getProductOptionStatesHandler(
    action: ActionWithParams<{ products: any[]; selectedOptionIds: string[] }>,
    _context: ActionContext
): Record<string, 'all' | 'some' | 'none'> {
    const params = action.params || { products: [], selectedOptionIds: [] };
    const { products, selectedOptionIds } = params;
    const selected: string[] = selectedOptionIds || [];

    if (!products || !Array.isArray(products)) {
        return {};
    }

    const states: Record<string, 'all' | 'some' | 'none'> = {};

    for (const product of products) {
        const productId = String(product.id);
        const options = product.options || [];

        if (options.length === 0) {
            states[productId] = 'none';
            continue;
        }

        const productOptionKeys = options.map((opt: any) =>
            makeOptionKey(productId, opt.id)
        );

        const selectedCount = productOptionKeys.filter((key: string) =>
            selected.includes(key)
        ).length;

        if (selectedCount === 0) {
            states[productId] = 'none';
        } else if (selectedCount === options.length) {
            states[productId] = 'all';
        } else {
            states[productId] = 'some';
        }
    }

    return states;
}

/**
 * 상품 선택 동기화 파라미터
 */
interface SyncProductSelectionParams {
    selectedIds: number[];
    previousSelectedIds?: number[];
}

/**
 * 상품 체크박스 선택 시 옵션도 함께 선택/해제하는 핸들러
 *
 * DataGrid의 onSelectionChange 이벤트에서 호출됩니다.
 * 새로 선택된 상품의 옵션은 모두 선택하고,
 * 선택 해제된 상품의 옵션은 모두 해제합니다.
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음 - G7Core.state.setLocal() 사용)
 */
export function syncProductSelectionHandler(
    action: ActionWithParams<SyncProductSelectionParams>,
    _context: ActionContext
): void {
    const params = action.params || ({} as SyncProductSelectionParams);
    const { selectedIds } = params;
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        logger.warn('[syncProductSelection] G7Core.state를 사용할 수 없습니다.');
        return;
    }

    // G7Core.state.getLocal()로 현재 로컬 상태 가져오기
    const currentLocal = G7Core.state.getLocal();
    const previousSelectedIds: number[] = currentLocal.selectedItems || [];
    const currentSelectedOptions: string[] = currentLocal.selectedOptionIds || [];

    // 데이터소스에서 상품 목록 가져오기
    const productsData = G7Core.dataSource?.get('products');
    const products = productsData?.data?.data || [];

    // 새로 선택된 상품 ID들
    const newlySelected = selectedIds.filter((id: number) => !previousSelectedIds.includes(id));
    // 선택 해제된 상품 ID들
    const newlyDeselected = previousSelectedIds.filter((id: number) => !selectedIds.includes(id));

    let newSelectedOptions = [...currentSelectedOptions];

    // 새로 선택된 상품의 옵션 추가
    for (const productId of newlySelected) {
        const product = products.find((p: any) => Number(p.id) === productId);
        if (product?.options) {
            for (const opt of product.options) {
                const optionKey = makeOptionKey(productId, opt.id);
                if (!newSelectedOptions.includes(optionKey)) {
                    newSelectedOptions.push(optionKey);
                }
            }
        }
    }

    // 선택 해제된 상품의 옵션 제거
    for (const productId of newlyDeselected) {
        newSelectedOptions = newSelectedOptions.filter(
            (key) => getProductIdFromKey(key) !== String(productId)
        );
    }

    // G7Core.state.setLocal()로 컴포넌트 로컬 상태 업데이트
    G7Core.state.setLocal({
        selectedItems: selectedIds,
        selectedOptionIds: newSelectedOptions,
    });
}
