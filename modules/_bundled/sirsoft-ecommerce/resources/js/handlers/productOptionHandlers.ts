// e2e:allow base_unit 환산 공식(÷base_unit) 변경. 옵션 다통화 환산 정확성은 단위 테스트로 검증, 표시 회귀는 product-option-multicurrency-readonly.spec.ts 가 구조적으로 차단.
/**
 * 상품옵션 추가 핸들러
 *
 * 상품 등록/수정 화면에서 다중 통화 자동 입력, 기본 옵션 설정, 옵션 행 추가 기능을 처리합니다.
 * (07-시안분석-상품옵션-통화자동입력.md 참조)
 */

import type { ActionContext } from '../types';
import { formatCurrency } from './calculateCurrencyPrices';

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

/**
 * 활성 옵션들의 재고 합계를 계산합니다.
 *
 * 옵션 보유 상품의 원 상품 재고는 활성(`is_active !== false`) 옵션 재고의 합으로 정해진다.
 * 옵션 생성/행 추가/삭제/수정 등 옵션 목록이 바뀌는 모든 지점에서 이 합계로 상품 재고를
 * 즉시 동기화한다 (백엔드 syncProductStock 과 동일 규칙의 프론트 미러).
 *
 * @param options 옵션 목록
 * @returns 활성 옵션 재고 합계
 */
export function sumActiveOptionStock(options: Array<{ stock_quantity?: any; is_active?: boolean }>): number {
    return (options ?? [])
        .filter((opt) => opt.is_active !== false)
        .reduce((sum, opt) => sum + (parseInt(String(opt.stock_quantity), 10) || 0), 0);
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
    multi_currency_selling_price?: Record<string, { price: number; formatted?: string } | number>;
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
    base_unit?: number;
    symbol?: string;
}

/**
 * base_unit 미설정 통화의 폴백 (소액 통화만 묶음 단위). 백엔드 CurrencyConversionService 와 동일.
 */
const BASE_UNIT_FALLBACK: Record<string, number> = {
    KRW: 1000,
    JPY: 100,
};

/**
 * 전역 통화 설정에서 기본 통화의 base_unit(환율 분모)을 해석합니다.
 *
 * @returns base_unit (최소 1)
 */
function resolveDefaultBaseUnit(): number {
    const globalState = (window as any).G7Core?.state?.get?.() || {};
    const list: Currency[] = globalState.modules?.['sirsoft-ecommerce']?.language_currency?.currencies || [];
    const base = list.find((c) => c.is_default);
    if (!base) {
        return 1;
    }
    const unit = base.base_unit ?? BASE_UNIT_FALLBACK[base.code] ?? 1;
    return Math.max(1, unit);
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
 * 계산: (basePrice / baseUnit) * exchange_rate → applyRounding → decimal_places 적용
 *
 * 관리자 상품폼·데이터그리드는 base 판매가만 입력받고 다통화는 읽기전용 환산값을
 * 표시한다. 표시용 `formatted` 를 함께 생성해 백엔드 Resource(`HasMultiCurrencyPrices`)
 * 응답이 없는 신규 등록 화면에서도 장바구니와 동일한 통화 표기를 노출한다.
 *
 * baseUnit 미전달 시 전역 통화 설정에서 기본 통화 base_unit 을 해석한다(폴백 KRW=1000 등).
 *
 * @param basePrice 기본통화 가격
 * @param currency 통화 설정
 * @param baseUnit 기본 통화 base_unit (환율 분모). 생략 시 전역 설정에서 해석.
 * @returns { price: 변환된 가격, formatted: 표시용 문자열 } 객체
 */
export function convertCurrencyPrice(basePrice: number, currency: Currency, baseUnit?: number): { price: number; formatted: string } {
    const exchangeRate = currency.exchange_rate || 0;
    if (exchangeRate <= 0) {
        return { price: 0, formatted: formatCurrency(0, currency) };
    }

    const divisor = baseUnit ?? resolveDefaultBaseUnit();
    const convertedPrice = (basePrice / divisor) * exchangeRate;
    const roundedPrice = applyRounding(
        convertedPrice,
        currency.rounding_unit || '0.01',
        currency.rounding_method || 'round'
    );

    // 소수 자릿수 제한 (환경설정 decimal_places 기준)
    const decimalPlaces = currency.decimal_places ?? 2;
    const finalPrice = parseFloat(roundedPrice.toFixed(decimalPlaces));

    return { price: finalPrice, formatted: formatCurrency(finalPrice, currency) };
}

interface OptionGroup {
    name: Record<string, string>;
    values: string[];
}

/**
 * 상품 판매가를 기준으로 모든 옵션의 selling_price 와 다중통화 가격을 재계산합니다.
 *
 * 옵션 selling_price = 상품 판매가 + 옵션 price_adjustment (가산액 유지·재계산, B1)
 * 정방향(상품가→옵션) / 역방향(기본옵션가→상품가) / 기본옵션 변경 모두 이 함수를 재사용합니다.
 *
 * @param options 옵션 배열
 * @param newProductPrice 새 상품 판매가
 * @param currencies 다중통화 자동 계산 대상 통화 목록 (빈 배열이면 다중통화 미계산)
 * @returns 재계산된 옵션 배열
 */
export function recalcOptionsFromProductPrice(
    options: ProductOption[],
    newProductPrice: number,
    currencies: Currency[],
): ProductOption[] {
    return options.map((opt) => {
        const priceAdjustment = parseFloat(String(opt.price_adjustment)) || 0;
        const newOptionSellingPrice = newProductPrice + priceAdjustment;

        const updatedOpt: ProductOption = {
            ...opt,
            selling_price: newOptionSellingPrice,
        };

        if (currencies.length > 0) {
            const baseUnit = resolveDefaultBaseUnit();
            const multiCurrencyPrices: Record<string, { price: number }> = {};
            currencies.forEach((currency) => {
                multiCurrencyPrices[currency.code] = convertCurrencyPrice(newOptionSellingPrice, currency, baseUnit);
            });
            updatedOpt.multi_currency_selling_price = multiCurrencyPrices;
        }

        return updatedOpt;
    });
}

/**
 * 다중통화 자동 계산 대상 통화 목록을 반환합니다. (기본 통화 제외, 환율 설정된 통화)
 *
 * 다통화 가격은 base 판매가에서 환율로 항상 자동 계산되어 읽기전용으로 표시되므로
 * 별도 토글 없이 상시 환율 통화를 반환한다.
 *
 * @returns 대상 통화 목록 (환율 설정된 비-기본 통화)
 */
function resolveAutoCurrencies(): Currency[] {
    const globalState = (window as any).G7Core?.state?.get?.() || {};
    return (globalState.modules?.['sirsoft-ecommerce']?.language_currency?.currencies || [])
        .filter((c: Currency) => !c.is_default && c.exchange_rate);
}

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
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

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[setDefaultOption] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const options: ProductOption[] = [...(state.form?.options ?? [])];

    // 레이아웃은 옵션 라디오에서 params.index 를 전달한다(쿠폰/검수 대조 확인).
    // 하위 호환을 위해 optionCode 도 수용한다.
    const rawIndex = params.index;
    const index = rawIndex !== undefined && rawIndex !== null
        ? (typeof rawIndex === 'string' ? parseInt(rawIndex, 10) : rawIndex as number)
        : -1;
    const optionCode = (params.optionCode as string) ?? options[index]?.option_code;

    if (!optionCode) {
        logger.warn('[setDefaultOption] Missing optionCode/index param', { index: rawIndex });
        return;
    }

    // 모든 옵션의 is_default를 false로 설정하고, 선택된 옵션만 true
    let updatedOptions = options.map((opt) => ({
        ...opt,
        is_default: opt.option_code === optionCode,
    }));

    // 새 기본 옵션의 판매가를 상품 판매가로 동기화 + 기본옵션 adj=0 + 전체 재계산
    const newDefault = updatedOptions.find((opt) => opt.is_default === true);
    const newProductPrice = newDefault
        ? parseFloat(String(newDefault.selling_price)) || (parseFloat(String(state.form?.selling_price)) || 0)
        : parseFloat(String(state.form?.selling_price)) || 0;

    if (newDefault) {
        newDefault.price_adjustment = 0;
        const currencies = resolveAutoCurrencies();
        updatedOptions = recalcOptionsFromProductPrice(updatedOptions, newProductPrice, currencies);
    }

    G7Core.state.setLocal({
        form: { ...state.form, options: updatedOptions, selling_price: newProductPrice },
        hasChanges: true,
    });

    logger.log(`[setDefaultOption] Set default option to ${optionCode}, synced product selling_price to ${newProductPrice}`);
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

    // selling_price 변경 시 처리 (역방향 동기화 포함)
    if (field === 'selling_price') {
        const optionSellingPrice = parseFloat(String(finalValue)) || 0;
        const currencies = resolveAutoCurrencies();

        // 역방향 동기화: 기본 옵션 판매가 변경 → 상품 판매가 동기화 + 전체 옵션 재계산
        // (기본 옵션은 정의상 상품 판매가 = 기본 옵션 판매가, price_adjustment 항상 0)
        if (options[index].is_default === true) {
            options[index].price_adjustment = 0;
            const recalculated = recalcOptionsFromProductPrice(options, optionSellingPrice, currencies);

            G7Core.state.setLocal({
                form: { ...state.form, options: recalculated, selling_price: optionSellingPrice },
                hasChanges: true,
            });

            logger.log(`[updateFormOptionField] Reverse-synced product selling_price to ${optionSellingPrice} from default option`);
            return;
        }

        // 비기본 옵션: price_adjustment = 옵션 판매가 - 상품 판매가 (상품 판매가 불변)
        const productSellingPrice = parseFloat(String(state.form?.selling_price)) || 0;
        options[index].price_adjustment = optionSellingPrice - productSellingPrice;

        // 자기 옵션 다중통화만 재계산
        if (currencies.length > 0) {
            const baseUnit = resolveDefaultBaseUnit();
            const multiCurrencyPrices: Record<string, { price: number }> = {};
            currencies.forEach((currency) => {
                multiCurrencyPrices[currency.code] = convertCurrencyPrice(optionSellingPrice, currency, baseUnit);
            });
            options[index].multi_currency_selling_price = multiCurrencyPrices;
            logger.log(`[updateFormOptionField] Recalculated multi_currency_selling_price for option at index ${index}:`, currencies.map(c => c.code));
        }
    }

    // stock_quantity 또는 is_active 변경 시 상품 재고 자동 합산
    if (field === 'stock_quantity' || field === 'is_active') {
        if (options.length > 0) {
            const totalStock = sumActiveOptionStock(options);

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
 * 옵션 행을 수동으로 추가합니다. (+ 행 추가 버튼)
 *
 * - 기존 옵션 그룹의 구조를 따름
 * - 첫 번째 옵션이면 기본으로 설정
 * - 신규 등록 모드(params.isCreate === true)에서는 재고 기본값을 1로 채워 입력 수고를 던다.
 *   수정 모드에서는 기존 동작(0)을 유지한다. 등록/수정 구분은 레이아웃이 route.itemCode 로
 *   판정해 params.isCreate 로 전달한다.
 *
 * @param action 액션 객체 (params.isCreate: 신규 등록 여부)
 * @param _context 액션 컨텍스트
 */
export function addOptionRowHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[addOptionRow] G7Core.state API is not available');
        return;
    }

    const isCreate = action.params?.isCreate === true || action.params?.isCreate === 'true';
    const defaultStockQuantity = isCreate ? 1 : 0;

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
        stock_quantity: defaultStockQuantity,
        safe_stock_quantity: 0,
        weight: 0,
        volume: 0,
        mileage_value: 0,
        mileage_type: 'percent',
        is_active: true,
    };

    const nextOptions = [...options, newOption];
    const totalStock = sumActiveOptionStock(nextOptions);

    G7Core.state.setLocal({
        form: { ...state.form, options: nextOptions, stock_quantity: totalStock, has_options: nextOptions.length > 0 },
        hasChanges: true,
    });

    logger.log(`[addOptionRow] Added new option row: ${newOptionCode}, product stock_quantity = ${totalStock}`);
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

    // 다통화는 base 판매가에서 환율로 항상 자동 계산되어 읽기전용으로 표시된다
    const currencies = resolveAutoCurrencies();

    const updatedOptions = recalcOptionsFromProductPrice(options, newProductSellingPrice, currencies);

    G7Core.state.setLocal({
        form: { ...state.form, options: updatedOptions },
    });

    logger.log(`[recalculateOptionPriceAdjustments] Recalculated selling_price for ${options.length} options based on product selling_price: ${newProductSellingPrice}`);
}
