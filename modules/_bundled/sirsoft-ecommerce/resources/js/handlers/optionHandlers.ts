/**
 * 옵션 관련 핸들러
 *
 * 상품 등록/수정 화면에서 상품 옵션, 추가옵션, 필수목록 관리 기능을 처리합니다.
 */

import type { ActionContext } from '../types';
import { convertCurrencyPrice } from './productOptionHandlers';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:Option')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Option]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Option]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Option]', ...args),
};

/**
 * 설정에서 지원되는 로케일 목록을 가져옵니다.
 *
 * @returns 지원되는 로케일 배열
 */
function getSupportedLocales(): string[] {
    const G7Core = (window as any).G7Core;
    return G7Core?.config?.('app.supported_locales') ?? ['ko', 'en'];
}

/**
 * 기본 로케일을 가져옵니다.
 *
 * @returns 기본 로케일 코드
 */
function getDefaultLocale(): string {
    const G7Core = (window as any).G7Core;
    return G7Core?.config?.('app.locale') ?? getSupportedLocales()[0] ?? 'ko';
}

/**
 * 빈 다국어 필드 객체를 생성합니다.
 *
 * @returns 모든 지원 로케일에 대해 빈 문자열을 가진 객체
 */
function createEmptyLocalizedField(): Record<string, string> {
    const locales = getSupportedLocales();
    return locales.reduce((acc, locale) => ({ ...acc, [locale]: '' }), {});
}

/**
 * 옵션 값 항목 (다국어 지원)
 */
type OptionValueMultilingual = Record<string, string>;

interface OptionInput {
    name: Record<string, string>;
    values: string[] | OptionValueMultilingual[];
}

/**
 * 옵션 값 항목 (배열 형식 - 다국어 지원)
 */
interface OptionValueItem {
    key: Record<string, string>;
    value: Record<string, string>;
}

interface ProductOption {
    id?: number | null;
    option_code: string;
    /** 배열 형식 (신규 다국어) 또는 객체 형식 (레거시) */
    option_values: OptionValueItem[] | Record<string, string>;
    /** 다국어 객체 (신규) 또는 문자열 (레거시) */
    option_name: Record<string, string> | string;
    price_adjustment: number;
    stock_quantity: number;
    safe_stock_quantity: number;
    is_default: boolean;
    is_active: boolean;
    sku: string;
    multi_currency_prices?: Record<string, number>;
    [key: string]: any;
}

interface OptionGroup {
    name: Record<string, string>;
    /** 다국어 배열 (신규) 또는 문자열 배열 (레거시) */
    values: OptionValueMultilingual[] | string[];
}

interface AdditionalOption {
    id?: number | null;
    name: Record<string, string>;
    is_required: boolean;
    sort_order: number;
}

interface RequiredItem {
    id?: number | null;
    sort_order: number;
    option_name: Record<string, string>;
    description: Record<string, string>;
}

interface Currency {
    code: string;
    exchange_rate: number;
}

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 옵션 값에서 특정 로케일의 문자열을 추출합니다.
 *
 * @param value 옵션 값 (문자열 또는 다국어 객체)
 * @param locale 로케일 코드
 * @returns 해당 로케일의 문자열
 */
function getValueForLocale(value: string | OptionValueMultilingual, locale: string): string {
    if (typeof value === 'string') return value;
    return value[locale] ?? value['ko'] ?? Object.values(value)[0] ?? '';
}

/**
 * 옵션 값이 다국어 객체인지 확인합니다.
 *
 * @param value 옵션 값
 * @returns 다국어 객체 여부
 */
function isMultilingualValue(value: any): value is OptionValueMultilingual {
    return value && typeof value === 'object' && !Array.isArray(value);
}

/**
 * values를 안전하게 배열로 변환합니다.
 * 배열이면 그대로 반환, 객체면 Object.values()로 변환합니다.
 *
 * @param values 옵션 값 (배열 또는 인덱스 객체)
 * @returns 배열 형태의 values
 */
function ensureValuesArray(values: any): (string | OptionValueMultilingual)[] {
    if (Array.isArray(values)) {
        return values;
    }
    if (values && typeof values === 'object') {
        // { "0": {...}, "1": {...} } 형태의 객체를 배열로 변환
        return Object.values(values);
    }
    return [];
}

/**
 * 카테시안 곱을 계산합니다. (다국어 값 지원)
 *
 * @param arrays 입력 배열들 (문자열 또는 다국어 객체)
 * @returns 모든 조합의 배열
 */
function cartesianProduct<T>(arrays: T[][]): T[][] {
    return arrays.reduce(
        (acc: T[][], curr: T[]) => {
            const result: T[][] = [];
            acc.forEach((a) => {
                curr.forEach((c) => {
                    result.push([...a, c]);
                });
            });
            return result;
        },
        [[]]
    );
}

/**
 * 다국어 옵션명을 생성합니다.
 *
 * @param combo 옵션 값 조합 배열
 * @returns 다국어 옵션명 객체
 */
function generateMultilingualOptionName(combo: (string | OptionValueMultilingual)[]): Record<string, string> {
    const locales = getSupportedLocales();
    const result: Record<string, string> = {};

    for (const locale of locales) {
        const parts = combo.map(v => getValueForLocale(v, locale));
        result[locale] = parts.join('/');
    }

    return result;
}

/**
 * 다국어 option_values 배열을 생성합니다.
 *
 * @param combo 옵션 값 조합 배열
 * @param inputs 옵션 입력 배열 (name 포함)
 * @returns option_values 배열 형식
 */
function generateMultilingualOptionValues(
    combo: (string | OptionValueMultilingual)[],
    inputs: OptionInput[]
): OptionValueItem[] {
    return inputs.map((inp, i) => ({
        key: typeof inp.name === 'string'
            ? { ...createEmptyLocalizedField(), [getDefaultLocale()]: inp.name }
            : inp.name,
        value: isMultilingualValue(combo[i])
            ? combo[i] as OptionValueMultilingual
            : { ...createEmptyLocalizedField(), [getDefaultLocale()]: combo[i] as string },
    }));
}

/**
 * 옵션 입력 행을 추가합니다. (최대 3개)
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function addOptionInputHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[addOptionInput] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const inputs = state.ui?.optionInputs ?? [];

    if (inputs.length >= 3) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.options.messages.input_max_3')
            ?? 'You can add up to 3 option inputs.'
        );
        return;
    }

    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            optionInputs: [...inputs, { name: createEmptyLocalizedField(), values: [] }],
        },
    });

    logger.log(`[addOptionInput] Added option input. Total: ${inputs.length + 1}`);
}

/**
 * 옵션 입력 행을 제거합니다.
 *
 * @param action 액션 객체 (params.index 필요)
 * @param _context 액션 컨텍스트
 */
export function removeOptionInputHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;

    if (index === undefined) {
        logger.warn('[removeOptionInput] Missing index param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[removeOptionInput] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const inputs = [...(state.ui?.optionInputs ?? [])];
    inputs.splice(index, 1);

    G7Core.state.setLocal({
        ui: { ...state.ui, optionInputs: inputs },
    });

    logger.log(`[removeOptionInput] Removed option input at index ${index}`);
}

/**
 * 옵션 입력 값을 업데이트합니다.
 *
 * @param action 액션 객체 (params.index, params.field, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateOptionInputHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;
    const field = params.field as string;
    const value = params.value;

    if (index === undefined || !field) {
        logger.warn('[updateOptionInput] Missing required params');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateOptionInput] G7Core.state API is not available');
        return;
    }

    // 디버깅: field 값과 타입 로깅
    logger.log(`[updateOptionInput] Called with index=${index}, field="${field}" (type: ${typeof field}), value=`, value);

    const state = G7Core.state.getLocal() || {};
    const inputs = [...(state.ui?.optionInputs ?? [])];

    // 디버깅: 현재 inputs 상태 로깅
    logger.log(`[updateOptionInput] Current inputs[${index}]:`, inputs[index]);

    // Race condition 방어: 해당 인덱스에 항목이 없으면 기본 구조로 초기화
    if (!inputs[index]) {
        logger.log(`[updateOptionInput] inputs[${index}] not found, initializing with empty structure`);
        inputs[index] = { name: createEmptyLocalizedField(), values: [] };
    }

    // name 필드는 다국어 객체 구조를 유지
    if (field === 'name') {
        const defaultLocale = getDefaultLocale();
        const existingName = inputs[index].name;
        logger.log(`[updateOptionInput] Processing 'name' field - defaultLocale: ${defaultLocale}, existingName:`, existingName, `type: ${typeof existingName}`);

        let newName: Record<string, string>;

        // value가 이미 다국어 객체인 경우 (MultilingualInput에서 전달)
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            // MultilingualInput: 전체 다국어 객체를 그대로 사용
            newName = value as Record<string, string>;
            logger.log(`[updateOptionInput] Using multilingual value directly:`, newName);
        } else {
            // 기본 Input (폴백): 현재 로케일에만 값 설정
            const baseName = (existingName && typeof existingName === 'object' && !Array.isArray(existingName))
                ? existingName
                : createEmptyLocalizedField();

            newName = {
                ...baseName,
                [defaultLocale]: value,
            };
            logger.log(`[updateOptionInput] Setting single locale value:`, newName);
        }

        inputs[index] = {
            ...inputs[index],
            name: newName,
        };
    } else {
        logger.log(`[updateOptionInput] Processing non-name field '${field}'`);
        inputs[index] = { ...inputs[index], [field]: value };
    }

    logger.log(`[updateOptionInput] Final inputs[${index}]:`, inputs[index]);

    G7Core.state.setLocal({
        ui: { ...state.ui, optionInputs: inputs },
    });
}

/**
 * 카테시안 곱으로 옵션 조합을 생성합니다.
 *
 * @param _action 액션 객체
 * @param context 액션 컨텍스트 (datasources.currencies 사용)
 */
export function generateOptionsHandler(
    _action: ActionWithParams,
    context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[generateOptions] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const globalState = G7Core.state.get() || {};
    const inputs: OptionInput[] = state.ui?.optionInputs ?? [];

    // 유효성 검사 (동적 로케일 사용, 문자열 호환성 지원)
    const defaultLocale = getDefaultLocale();

    // 옵션명 추출 헬퍼 함수 (객체 또는 문자열 모두 지원)
    const getOptionName = (inp: OptionInput): string => {
        if (!inp.name) return '';
        if (typeof inp.name === 'string') return inp.name;
        return inp.name[defaultLocale] ?? '';
    };

    // values를 배열로 정규화 (객체 형태일 경우 변환)
    const normalizedInputs = inputs.map(inp => ({
        ...inp,
        values: ensureValuesArray(inp.values),
    }));

    const validInputs = normalizedInputs.filter(
        (inp) => getOptionName(inp) && inp.values && inp.values.length > 0
    );

    logger.log(`[generateOptions] Validating inputs - defaultLocale: ${defaultLocale}, normalizedInputs:`, normalizedInputs.map(inp => ({
        name: inp.name,
        nameType: typeof inp.name,
        extractedName: getOptionName(inp as OptionInput),
        values: inp.values,
        valuesIsArray: Array.isArray(inp.values),
    })));
    logger.log(`[generateOptions] Valid inputs count: ${validInputs.length}`);

    if (validInputs.length === 0) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.options.messages.name_value_required')
            ?? 'Please enter option name and values.'
        );
        return;
    }

    // 유효성 통과 후: 기존 옵션이 존재하고 skipConfirm이 아닌 경우 → 확인 모달 표시
    const existingOptions: ProductOption[] = state.form?.options ?? [];
    const skipConfirm = _action.params?.skipConfirm === true;
    if (existingOptions.length > 0 && !skipConfirm) {
        G7Core.modal?.open?.('modal_confirm_regenerate');
        return;
    }

    // 상품의 정가/판매가 읽기
    const productListPrice = parseFloat(String(state.form?.list_price)) || 0;
    const productSellingPrice = parseFloat(String(state.form?.selling_price)) || 0;
    const productMultiCurrencySP = state.form?.multi_currency_selling_price;

    // 다중통화 환율 정보 (기본 통화 제외, 환율이 설정된 통화만)
    const fullCurrencies: any[] = (
        globalState.modules?.['sirsoft-ecommerce']?.language_currency?.currencies || []
    ).filter((c: any) => !c.is_default && c.exchange_rate);

    try {
        logger.log('[generateOptions] Step 1: Creating optionGroups...');

        // 옵션 그룹 저장 (name과 values를 다국어 형식으로 저장)
        const optionGroups: OptionGroup[] = validInputs.map((inp, i) => {
            logger.log(`[generateOptions] Processing input[${i}]:`, {
                name: inp.name,
                values: inp.values,
                valuesType: typeof inp.values,
                valuesIsArray: Array.isArray(inp.values),
            });
            return {
                name: typeof inp.name === 'string'
                    ? { ...createEmptyLocalizedField(), [defaultLocale]: inp.name }
                    : inp.name,
                // values가 다국어 배열이면 그대로, 문자열 배열이면 다국어 객체로 변환
                values: inp.values.map(v =>
                    isMultilingualValue(v) ? v : { ...createEmptyLocalizedField(), [defaultLocale]: v as string }
                ),
            };
        });

    // 카테시안 곱으로 모든 조합 생성 (다국어 값 지원)
    const combinations = cartesianProduct(validInputs.map((inp) => inp.values));

    // 옵션 객체 생성 (다국어 option_name, option_values 지원)
    const options: ProductOption[] = combinations.map((combo, idx) => {
        // 다국어 option_values 배열 형식으로 생성
        const optionValues = generateMultilingualOptionValues(combo, validInputs);

        // 다국어 option_name 생성
        const optionName = generateMultilingualOptionName(combo);
        const optionCode = `OPT-${String(idx + 1).padStart(3, '0')}`;

        // 다중 통화 판매가 계산
        let multiCurrencySellingPrice: Record<string, { price: number }> = {};
        if (productMultiCurrencySP && typeof productMultiCurrencySP === 'object' && Object.keys(productMultiCurrencySP).length > 0) {
            // 상품에 이미 다중통화 판매가가 설정되어 있으면 복사
            multiCurrencySellingPrice = JSON.parse(JSON.stringify(productMultiCurrencySP));
        } else if (productSellingPrice > 0 && fullCurrencies.length > 0) {
            // 상품 판매가 기반으로 환율 자동 계산
            fullCurrencies.forEach((currency: any) => {
                multiCurrencySellingPrice[currency.code] = convertCurrencyPrice(productSellingPrice, currency);
            });
        }

        return {
            id: null,
            option_code: optionCode,
            option_values: optionValues,
            option_name: optionName,
            list_price: productListPrice,
            selling_price: productSellingPrice,
            price_adjustment: 0,
            stock_quantity: 0,
            safe_stock_quantity: 0,
            is_default: idx === 0,
            is_active: true,
            sku: '',
            multi_currency_selling_price: multiCurrencySellingPrice,
        };
    });

    // 기존 옵션과 병합 (기존 옵션 값 유지)
    // option_name이 다국어 객체 또는 문자열일 수 있음
    const existingOptions: ProductOption[] = state.form?.options ?? [];
    const getOptionNameKey = (opt: ProductOption): string => {
        if (!opt.option_name) return '';
        if (typeof opt.option_name === 'string') return opt.option_name;
        return opt.option_name[defaultLocale] ?? opt.option_name['ko'] ?? '';
    };
    const mergedOptions = options.map((newOpt) => {
        const newKey = getOptionNameKey(newOpt);
        const existing = existingOptions.find((e) => getOptionNameKey(e) === newKey);
        return existing ? { ...newOpt, ...existing, id: existing.id } : newOpt;
    });

    G7Core.state.setLocal({
        form: {
            ...state.form,
            option_groups: optionGroups,
            options: mergedOptions,
            has_options: mergedOptions.length > 0,
        },
        hasChanges: true,
    });

    G7Core.toast?.success?.(
        G7Core.t?.('sirsoft-ecommerce.admin.product.options.messages.generated', { count: mergedOptions.length })
        ?? `${mergedOptions.length} options have been generated.`
    );
    logger.log(`[generateOptions] Generated ${mergedOptions.length} options`);
    } catch (error) {
        logger.error('[generateOptions] Error occurred:', error);
        logger.error('[generateOptions] Error message:', (error as Error).message);
        logger.error('[generateOptions] Error stack:', (error as Error).stack);
        throw error; // 에러를 다시 던져서 ActionDispatcher가 처리하도록 함
    }
}

/**
 * 옵션을 삭제합니다.
 *
 * @param action 액션 객체 (params.rowIndex 필요)
 * @param _context 액션 컨텍스트
 */
export function deleteOptionHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const rowIndex = params.rowIndex as number;

    if (rowIndex === undefined) {
        logger.warn('[deleteOption] Missing rowIndex param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[deleteOption] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options = [...(state.form?.options ?? [])];
    options.splice(rowIndex, 1);

    G7Core.state.setLocal({
        form: { ...state.form, options },
        hasChanges: true,
    });

    logger.log(`[deleteOption] Deleted option at index ${rowIndex}`);
}

/**
 * 옵션추가도구 설정을 적용합니다.
 *
 * @param action 액션 객체 (params.optionName, params.applyToAll, params.inheritPrice 필요)
 * @param _context 액션 컨텍스트
 */
export function applyOptionAddToolHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const optionName = params.optionName as string;
    const applyToAll = params.applyToAll as boolean;
    const inheritPrice = params.inheritPrice as boolean;

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[applyOptionAddTool] G7Core.state API is not available');
        return;
    }

    if (!optionName?.trim()) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.options.messages.name_required')
            ?? 'Please enter option name.'
        );
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options = [...(state.form?.options ?? [])];

    const updatedOptions = options.map((opt) => {
        const newOpt = { ...opt };

        if (applyToAll) {
            newOpt.option_values = {
                ...opt.option_values,
                [optionName]: '',
            };
        }

        if (inheritPrice) {
            newOpt.price_adjustment = opt.price_adjustment ?? 0;
        }

        return newOpt;
    });

    G7Core.state.setLocal({
        form: { ...state.form, options: updatedOptions },
        ui: {
            ...state.ui,
            optionAddToolName: '',
            optionAddToolApplyToAll: false,
            optionAddToolInheritPrice: false,
        },
        hasChanges: true,
    });

    G7Core.toast?.success?.(
        G7Core.t?.('sirsoft-ecommerce.admin.product.options.messages.add_tool_applied')
        ?? 'Option add tool has been applied.'
    );
}

/**
 * 필수 목록 항목을 추가합니다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function addRequiredItemHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[addRequiredItem] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const requiredItems: RequiredItem[] = state.form?.required_items ?? [];

    const newItem: RequiredItem = {
        id: null,
        sort_order: requiredItems.length,
        option_name: { ko: '', en: '' },
        description: { ko: '', en: '' },
    };

    G7Core.state.setLocal({
        form: {
            ...state.form,
            required_items: [...requiredItems, newItem],
        },
        hasChanges: true,
    });

    logger.log(`[addRequiredItem] Added required item. Total: ${requiredItems.length + 1}`);
}

/**
 * 필수 목록 항목을 수정합니다.
 *
 * @param action 액션 객체 (params.index, params.field, params.locale, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateRequiredItemHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;
    const field = params.field as 'option_name' | 'description';
    const locale = params.locale as string;
    const value = params.value as string;

    if (index === undefined || !field || !locale) {
        logger.warn('[updateRequiredItem] Missing required params');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateRequiredItem] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const requiredItems = [...(state.form?.required_items ?? [])];

    requiredItems[index] = {
        ...requiredItems[index],
        [field]: {
            ...requiredItems[index][field],
            [locale]: value,
        },
    };

    G7Core.state.setLocal({
        form: { ...state.form, required_items: requiredItems },
        hasChanges: true,
    });
}

/**
 * 필수 목록 항목을 삭제합니다.
 *
 * @param action 액션 객체 (params.index 필요)
 * @param _context 액션 컨텍스트
 */
export function removeRequiredItemHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;

    if (index === undefined) {
        logger.warn('[removeRequiredItem] Missing index param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[removeRequiredItem] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const requiredItems = [...(state.form?.required_items ?? [])];
    requiredItems.splice(index, 1);

    // sort_order 재정렬
    requiredItems.forEach((item, i) => {
        item.sort_order = i;
    });

    G7Core.state.setLocal({
        form: { ...state.form, required_items: requiredItems },
        hasChanges: true,
    });

    logger.log(`[removeRequiredItem] Removed required item at index ${index}`);
}

/**
 * 필수 목록 순서를 변경합니다.
 *
 * @param action 액션 객체 (params.oldIndex, params.newIndex 필요)
 * @param _context 액션 컨텍스트
 */
export function reorderRequiredItemsHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const oldIndex = params.oldIndex as number;
    const newIndex = params.newIndex as number;

    if (oldIndex === undefined || newIndex === undefined) {
        logger.warn('[reorderRequiredItems] Missing oldIndex or newIndex param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[reorderRequiredItems] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const requiredItems = [...(state.form?.required_items ?? [])];

    const [moved] = requiredItems.splice(oldIndex, 1);
    requiredItems.splice(newIndex, 0, moved);

    // sort_order 재설정
    requiredItems.forEach((item, i) => {
        item.sort_order = i;
    });

    G7Core.state.setLocal({
        form: { ...state.form, required_items: requiredItems },
        hasChanges: true,
    });

    logger.log(`[reorderRequiredItems] Moved item from ${oldIndex} to ${newIndex}`);
}

/**
 * 추가옵션을 추가합니다. (최대 5개)
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function addAdditionalOptionHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[addAdditionalOption] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options: AdditionalOption[] = state.form?.additional_options ?? [];

    if (options.length >= 5) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.options.messages.additional_max_5')
            ?? 'You can add up to 5 additional options.'
        );
        return;
    }

    // 새 추가옵션에 고유 임시 ID 부여 (sortable에서 아이템 구분에 필요)
    const tempId = `temp_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;

    G7Core.state.setLocal({
        form: {
            ...state.form,
            additional_options: [
                ...options,
                {
                    id: tempId,
                    name: { ko: '', en: '' },
                    is_required: false,
                    sort_order: options.length,
                },
            ],
        },
        hasChanges: true,
    });

    logger.log(`[addAdditionalOption] Added additional option. Total: ${options.length + 1}`);
}

/**
 * 추가옵션을 수정합니다.
 *
 * MultilingualInput 컴포넌트와 기본 Input 모두 지원합니다.
 * - MultilingualInput: value가 다국어 객체 { ko: '...', en: '...' }
 * - 기본 Input: value가 문자열
 *
 * @param action 액션 객체 (params.index, params.field, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateAdditionalOptionHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;
    const field = params.field as string;
    const value = params.value;

    if (index === undefined || !field) {
        logger.warn('[updateAdditionalOption] Missing required params');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateAdditionalOption] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options = [...(state.form?.additional_options ?? [])];

    if (!options[index]) {
        logger.warn('[updateAdditionalOption] Option not found at index', index);
        return;
    }

    // name 필드는 다국어 객체 구조를 유지
    if (field === 'name') {
        // MultilingualInput에서 전달된 경우: value가 이미 다국어 객체
        // 기본 Input에서 전달된 경우: value가 문자열
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            // MultilingualInput: 전체 다국어 객체를 그대로 저장
            options[index] = {
                ...options[index],
                name: value,
            };
        } else {
            // 기본 Input (폴백): 현재 로케일에만 값 설정
            const defaultLocale = getDefaultLocale();
            const existingName = options[index].name;

            const baseName = (existingName && typeof existingName === 'object' && !Array.isArray(existingName))
                ? existingName
                : createEmptyLocalizedField();

            options[index] = {
                ...options[index],
                name: {
                    ...baseName,
                    [defaultLocale]: value,
                },
            };
        }
    } else {
        // is_required, sort_order 등은 직접 할당
        options[index] = { ...options[index], [field]: value };
    }

    G7Core.state.setLocal({
        form: { ...state.form, additional_options: options },
        hasChanges: true,
    });

    logger.log(`[updateAdditionalOption] Updated options[${index}].${field}`);
}

/**
 * 추가옵션을 삭제합니다.
 *
 * @param action 액션 객체 (params.index 필요)
 * @param _context 액션 컨텍스트
 */
export function removeAdditionalOptionHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;

    if (index === undefined) {
        logger.warn('[removeAdditionalOption] Missing index param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[removeAdditionalOption] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options = [...(state.form?.additional_options ?? [])];
    options.splice(index, 1);

    // sort_order 재정렬
    options.forEach((opt, i) => {
        opt.sort_order = i;
    });

    G7Core.state.setLocal({
        form: { ...state.form, additional_options: options },
        hasChanges: true,
    });

    logger.log(`[removeAdditionalOption] Removed additional option at index ${index}`);
}

/**
 * 추가옵션 순서를 변경합니다.
 *
 * 드래그 앤 드롭으로 순서를 변경할 때 사용됩니다.
 *
 * @param action 액션 객체 (params.oldIndex, params.newIndex 필요)
 * @param _context 액션 컨텍스트
 */
export function reorderAdditionalOptionsHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const oldIndex = parseInt(String(params.oldIndex), 10);
    const newIndex = parseInt(String(params.newIndex), 10);

    if (isNaN(oldIndex) || isNaN(newIndex) || oldIndex === newIndex) {
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[reorderAdditionalOptions] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options = [...(state.form?.additional_options ?? [])];

    if (oldIndex < 0 || oldIndex >= options.length || newIndex < 0 || newIndex >= options.length) {
        return;
    }

    // 배열 재정렬
    const [moved] = options.splice(oldIndex, 1);
    options.splice(newIndex, 0, moved);

    // sort_order 재설정
    options.forEach((opt, i) => {
        opt.sort_order = i;
    });

    G7Core.state.setLocal({
        form: { ...state.form, additional_options: options },
        ui: { ...state.ui, dragIndex: null },
        hasChanges: true,
    });

    logger.log(`[reorderAdditionalOptions] Moved item from ${oldIndex} to ${newIndex}`);
}
