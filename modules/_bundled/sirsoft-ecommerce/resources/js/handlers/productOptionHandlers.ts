/**
 * 상품옵션 추가 핸들러
 *
 * 상품 등록/수정 화면에서 다중 통화 자동 입력, 기본 옵션 설정, 옵션 행 추가 기능을 처리합니다.
 * (07-시안분석-상품옵션-통화자동입력.md 참조)
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:ProductOption')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ProductOption]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ProductOption]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ProductOption]', ...args),
};

/**
 * 지원 로케일 목록을 반환합니다.
 */
function getSupportedLocales(): string[] {
    const G7Core = (window as any).G7Core;
    return G7Core?.config?.('app.supported_locales') ?? ['ko', 'en'];
}

/**
 * 빈 다국어 필드를 생성합니다.
 */
function createEmptyLocalizedField(): Record<string, string> {
    const locales = getSupportedLocales();
    return locales.reduce((acc, locale) => ({ ...acc, [locale]: '' }), {} as Record<string, string>);
}

interface ProductOption {
    id?: number | null;
    option_code: string;
    option_name?: Record<string, string>;
    option_values: Array<{ key: Record<string, string>; value: Record<string, string> }> | Record<string, string>;
    is_default: boolean;
    regular_price: number;
    sale_price: number;
    list_price?: number;
    selling_price?: number;
    price_adjustment?: number;
    multi_currency_selling_price?: Record<string, { price: number } | number>;
    sku: string;
    stock_quantity: number;
    safe_stock_quantity: number;
    weight: number;
    volume: number;
    mileage_value: number;
    mileage_type: 'percent' | 'fixed';
    is_active: boolean;
    [key: string]: any;
}

interface Currency {
    code: string;
    name: Record<string, string> | string;
    is_default: boolean;
    exchange_rate: number;
    rounding_unit?: string;
    rounding_method?: string;
    decimal_places?: number;
}

/**
 * 절사/반올림/올림을 적용합니다.
 *
 * @param price 가격
 * @param unit 절사 단위 (예: '0.01', '1', '10')
 * @param method 방법 (floor, round, ceil)
 * @returns 처리된 가격
 */
function applyRounding(price: number, unit: string, method: string): number {
    const unitValue = parseFloat(unit) || 1;
    if (unitValue <= 0) {
        return price;
    }

    const divided = price / unitValue;
    let rounded: number;

    switch (method) {
        case 'ceil':
            rounded = Math.ceil(divided);
            break;
        case 'floor':
            rounded = Math.floor(divided);
            break;
        default:
            rounded = Math.round(divided);
    }

    return rounded * unitValue;
}

/**
 * 기본통화 가격을 외화로 변환합니다.
 *
 * 계산: (basePrice / 1000) * exchange_rate → applyRounding → decimal_places 적용
 *
 * @param basePrice 기본통화 가격
 * @param currency 통화 설정
 * @returns { price: 변환된 가격 } 객체
 */
export function convertCurrencyPrice(basePrice: number, currency: Currency): { price: number } {
    const exchangeRate = currency.exchange_rate || 0;
    if (exchangeRate <= 0) {
        return { price: 0 };
    }

    const convertedPrice = (basePrice / 1000) * exchangeRate;
    const roundedPrice = applyRounding(
        convertedPrice,
        currency.rounding_unit || '0.01',
        currency.rounding_method || 'round'
    );

    // 소수 자릿수 제한 (환경설정 decimal_places 기준)
    const decimalPlaces = currency.decimal_places ?? 2;
    const finalPrice = parseFloat(roundedPrice.toFixed(decimalPlaces));

    return { price: finalPrice };
}

interface OptionGroup {
    name: Record<string, string>;
    values: string[];
}

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 다중 통화 가격 자동 입력을 토글합니다.
 *
 * - ON: 현재 판매가 기준으로 다른 통화 가격 자동 계산
 * - OFF: 수동 입력 모드
 *
 * @param action 액션 객체 (params.enabled 필요)
 * @param _context 액션 컨텍스트
 */
export function toggleAutoMultiCurrencyHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const enabled = params.enabled as boolean;

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[toggleAutoMultiCurrency] G7Core.state API is not available');
        return;
    }

    const localState = G7Core.state.getLocal() || {};
    const globalState = G7Core.state.get() || {};

    G7Core.state.setLocal({
        ui: {
            ...localState.ui,
            autoMultiCurrency: enabled,
        },
    });

    // 토글 ON 시 현재 판매가 기준으로 다른 통화 가격 자동 계산
    if (enabled) {
        const options: ProductOption[] = localState.form?.options ?? [];
        // 동적으로 통화 목록 가져오기 (기본 통화 제외, 환율이 설정된 통화만)
        const currencies: Currency[] = (
            globalState.modules?.['sirsoft-ecommerce']?.language_currency?.currencies || []
        ).filter((c: Currency) => !c.is_default && c.exchange_rate);

        if (currencies.length === 0) {
            logger.warn('[toggleAutoMultiCurrency] No non-default currencies with exchange rates found');
            return;
        }

        const updatedOptions = options.map((opt) => {
            const basePrice = opt.selling_price ?? opt.sale_price ?? 0;
            const multiCurrencyPrices: Record<string, { price: number }> = {};

            currencies.forEach((currency) => {
                multiCurrencyPrices[currency.code] = convertCurrencyPrice(basePrice, currency);
            });

            return {
                ...opt,
                multi_currency_selling_price: multiCurrencyPrices,
            };
        });

        G7Core.state.setLocal({
            form: { ...localState.form, options: updatedOptions },
            hasChanges: true,
        });

        logger.log('[toggleAutoMultiCurrency] Auto-calculated multi-currency prices for currencies:', currencies.map(c => c.code));
    }

    logger.log(`[toggleAutoMultiCurrency] Set to ${enabled}`);
}

/**
 * 기본 옵션을 선택합니다. (Radio 버튼)
 *
 * - 모든 옵션의 is_default를 false로 설정
 * - 선택된 옵션만 is_default를 true로 설정
 *
 * @param action 액션 객체 (params.optionCode 필요)
 * @param _context 액션 컨텍스트
 */
export function setDefaultOptionHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const optionCode = params.optionCode as string;

    if (!optionCode) {
        logger.warn('[setDefaultOption] Missing optionCode param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[setDefaultOption] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options: ProductOption[] = [...(state.form?.options ?? [])];

    // 모든 옵션의 is_default를 false로 설정하고, 선택된 옵션만 true
    const updatedOptions = options.map((opt) => ({
        ...opt,
        is_default: opt.option_code === optionCode,
    }));

    G7Core.state.setLocal({
        form: { ...state.form, options: updatedOptions },
        hasChanges: true,
    });

    logger.log(`[setDefaultOption] Set default option to ${optionCode}`);
}

/**
 * 상품 폼에서 옵션 필드를 업데이트합니다.
 *
 * _local.form.options[index][field] = value
 *
 * @param action 액션 객체 (params.index, params.field, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateFormOptionFieldHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = typeof params.index === 'string' ? parseInt(params.index, 10) : params.index as number;
    const field = params.field as string;
    const value = params.value;

    if (index === undefined || index === null || !field) {
        logger.warn('[updateFormOptionField] Missing required params:', { index, field });
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateFormOptionField] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options: ProductOption[] = [...(state.form?.options ?? [])];

    if (index < 0 || index >= options.length) {
        logger.warn('[updateFormOptionField] Index out of bounds:', { index, length: options.length });
        return;
    }

    // 숫자 필드는 숫자로 변환
    const numericFields = ['regular_price', 'sale_price', 'list_price', 'selling_price', 'stock_quantity', 'safe_stock_quantity', 'weight', 'volume', 'mileage_value'];
    let finalValue = value;
    if (numericFields.includes(field)) {
        finalValue = parseFloat(value) || 0;
    }

    // 옵션 업데이트
    options[index] = {
        ...options[index],
        [field]: finalValue,
    };

    // selling_price 변경 시 price_adjustment 재계산 및 다중통화 자동 계산
    if (field === 'selling_price') {
        // price_adjustment = 옵션 판매가 - 상품 판매가
        const productSellingPrice = parseFloat(String(state.form?.selling_price)) || 0;
        const optionSellingPrice = parseFloat(String(finalValue)) || 0;
        options[index].price_adjustment = optionSellingPrice - productSellingPrice;

        // 다중통화 자동 계산이 활성화된 경우 다중통화 가격 재계산
        const localState = G7Core.state.getLocal() || {};
        const autoMultiCurrency = localState.ui?.multiCurrencyAutoFill ?? true;

        if (autoMultiCurrency) {
            const globalState = G7Core.state.get() || {};
            const currencies: Currency[] = (
                globalState.modules?.['sirsoft-ecommerce']?.language_currency?.currencies || []
            ).filter((c: Currency) => !c.is_default && c.exchange_rate);

            if (currencies.length > 0) {
                const multiCurrencyPrices: Record<string, { price: number }> = {};
                currencies.forEach((currency) => {
                    multiCurrencyPrices[currency.code] = convertCurrencyPrice(optionSellingPrice, currency);
                });
                options[index].multi_currency_selling_price = multiCurrencyPrices;
                logger.log(`[updateFormOptionField] Recalculated multi_currency_selling_price for option at index ${index}:`, currencies.map(c => c.code));
            }
        }
    }

    // stock_quantity 또는 is_active 변경 시 상품 재고 자동 합산
    if (field === 'stock_quantity' || field === 'is_active') {
        if (options.length > 0) {
            const totalStock = options
                .filter(opt => opt.is_active !== false)
                .reduce((sum, opt) => sum + (parseInt(String(opt.stock_quantity), 10) || 0), 0);

            G7Core.state.setLocal({
                form: { ...state.form, options, stock_quantity: totalStock },
                hasChanges: true,
            });

            logger.log(`[updateFormOptionField] Updated product stock_quantity to ${totalStock} (sum of active options)`);
            return;
        }
    }

    G7Core.state.setLocal({
        form: { ...state.form, options },
        hasChanges: true,
    });

    logger.log(`[updateFormOptionField] Updated options[${index}].${field} =`, finalValue);
}

/**
 * 상품 폼에서 옵션 다중통화 필드를 업데이트합니다.
 *
 * _local.form.options[index].multi_currency_selling_price[currencyCode] = value
 *
 * @param action 액션 객체 (params.index, params.currencyCode, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateFormOptionCurrencyFieldHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = typeof params.index === 'string' ? parseInt(params.index, 10) : params.index as number;
    const currencyCode = params.currencyCode as string;
    const value = params.value;

    if (index === undefined || index === null || !currencyCode) {
        logger.warn('[updateFormOptionCurrencyField] Missing required params:', { index, currencyCode });
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateFormOptionCurrencyField] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options: ProductOption[] = [...(state.form?.options ?? [])];

    if (index < 0 || index >= options.length) {
        logger.warn('[updateFormOptionCurrencyField] Index out of bounds:', { index, length: options.length });
        return;
    }

    // 숫자로 변환
    const numericValue = parseFloat(value) || 0;

    // 다중통화 가격 업데이트 (백엔드 API 응답 구조와 일치: { price: number })
    const currentMultiCurrency = options[index].multi_currency_selling_price || {};
    options[index] = {
        ...options[index],
        multi_currency_selling_price: {
            ...currentMultiCurrency,
            [currencyCode]: { price: numericValue },
        },
    };

    G7Core.state.setLocal({
        form: { ...state.form, options },
        hasChanges: true,
    });

    logger.log(`[updateFormOptionCurrencyField] Updated options[${index}].multi_currency_selling_price.${currencyCode} =`, numericValue);
}

/**
 * 옵션 행을 수동으로 추가합니다. (+ 행 추가 버튼)
 *
 * - 기존 옵션 그룹의 구조를 따름
 * - 첫 번째 옵션이면 기본으로 설정
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function addOptionRowHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[addOptionRow] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options: ProductOption[] = [...(state.form?.options ?? [])];
    const optionGroups: OptionGroup[] = state.form?.option_groups ?? [];

    // 새 옵션 행 생성
    const newOptionCode = `OPT-${Date.now()}`;

    // 각 옵션 그룹에 대해 배열 포맷으로 빈 값 설정
    const newOptionValues = optionGroups.map((group) => ({
        key: group.name,                     // {ko: "색상", en: "Color"}
        value: createEmptyLocalizedField(),  // {ko: "", en: ""}
    }));

    const newOption: ProductOption = {
        id: null,
        option_code: newOptionCode,
        option_name: createEmptyLocalizedField(),
        option_values: newOptionValues,
        is_default: options.length === 0, // 첫 번째 옵션이면 기본으로 설정
        regular_price: 0,
        sale_price: 0,
        list_price: 0,
        selling_price: 0,
        price_adjustment: 0,
        multi_currency_selling_price: {},
        sku: '',
        stock_quantity: 0,
        safe_stock_quantity: 0,
        weight: 0,
        volume: 0,
        mileage_value: 0,
        mileage_type: 'percent',
        is_active: true,
    };

    G7Core.state.setLocal({
        form: { ...state.form, options: [...options, newOption] },
        hasChanges: true,
    });

    logger.log(`[addOptionRow] Added new option row: ${newOptionCode}`);
}

/**
 * 상품 판매가 변경 시 모든 옵션의 selling_price를 재계산합니다.
 *
 * 옵션 selling_price = 상품 판매가 + 옵션 price_adjustment
 *
 * sequence 내에서 setState 다음에 실행되므로, 타이밍 이슈를 피하기 위해
 * params.newSellingPrice로 새 상품 판매가를 직접 받습니다.
 *
 * @param action 액션 객체 (params.newSellingPrice 필요)
 * @param _context 액션 컨텍스트
 */
export function recalculateOptionPriceAdjustmentsHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[recalculateOptionPriceAdjustments] G7Core.state API is not available');
        return;
    }

    const params = action.params || {};
    const state = G7Core.state.getLocal() || {};
    const globalState = G7Core.state.get() || {};

    // params에서 새 상품 판매가를 받아옴 (타이밍 이슈 해결)
    // params가 없으면 state에서 fallback
    const newProductSellingPrice = params.newSellingPrice !== undefined
        ? parseFloat(String(params.newSellingPrice)) || 0
        : parseFloat(String(state.form?.selling_price)) || 0;

    const options: ProductOption[] = [...(state.form?.options ?? [])];

    if (options.length === 0) {
        logger.log('[recalculateOptionPriceAdjustments] No options to recalculate');
        return;
    }

    // 다중통화 자동 계산 여부
    const autoMultiCurrency = state.ui?.multiCurrencyAutoFill ?? true;
    const currencies: Currency[] = autoMultiCurrency
        ? (globalState.modules?.['sirsoft-ecommerce']?.language_currency?.currencies || [])
            .filter((c: Currency) => !c.is_default && c.exchange_rate)
        : [];

    const updatedOptions = options.map((opt) => {
        // 옵션 selling_price = 상품 판매가 + 옵션 price_adjustment
        const priceAdjustment = parseFloat(String(opt.price_adjustment)) || 0;
        const newOptionSellingPrice = newProductSellingPrice + priceAdjustment;

        const updatedOpt: ProductOption = {
            ...opt,
            selling_price: newOptionSellingPrice,
        };

        // 다중통화 자동 계산이 활성화된 경우
        if (currencies.length > 0) {
            const multiCurrencyPrices: Record<string, { price: number }> = {};
            currencies.forEach((currency) => {
                multiCurrencyPrices[currency.code] = convertCurrencyPrice(newOptionSellingPrice, currency);
            });
            updatedOpt.multi_currency_selling_price = multiCurrencyPrices;
        }

        return updatedOpt;
    });

    G7Core.state.setLocal({
        form: { ...state.form, options: updatedOptions },
    });

    logger.log(`[recalculateOptionPriceAdjustments] Recalculated selling_price for ${options.length} options based on product selling_price: ${newProductSellingPrice}`);
}
