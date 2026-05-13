/**
 * 가격 관련 핸들러
 *
 * 상품 등록/수정 화면에서 가격 업데이트 및 재고 계산 기능을 처리합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:Price')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Price]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Price]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Price]', ...args),
};

interface Currency {
    code: string;
    exchange_rate: number;
    symbol?: string;
}

interface ProductOption {
    id?: number | null;
    option_code: string;
    stock_quantity: number;
    [key: string]: any;
}

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 환율 기반 가격을 계산합니다.
 *
 * @param basePrice 기준 가격 (KRW)
 * @param exchangeRate 환율
 * @param roundingMethod 반올림 방법 ('round', 'ceil', 'floor')
 * @returns 변환된 가격
 */
function calculateCurrencyPrice(
    basePrice: number,
    exchangeRate: number,
    roundingMethod: string
): number {
    const converted = (basePrice / 1000) * exchangeRate;

    switch (roundingMethod) {
        case 'ceil':
            return Math.ceil(converted);
        case 'floor':
            return Math.floor(converted);
        case 'round':
        default:
            return Math.round(converted);
    }
}

/**
 * 다중 통화 가격을 업데이트합니다.
 *
 * - 기준 통화 변경 시 다른 통화 가격을 자동으로 환율 기반 계산
 * - 메인 가격 필드(list_price, selling_price)도 함께 업데이트
 *
 * @param action 액션 객체 (params.field, params.currency, params.value, params.autoFill 필요)
 * @param context 액션 컨텍스트 (datasources.currencies 사용)
 */
export function updatePriceHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const field = params.field as 'list' | 'selling';
    const currency = params.currency as string;
    const value = params.value as number;
    const autoFill = params.autoFill as boolean;

    if (!field || !currency || value === undefined) {
        logger.warn('[updatePrice] Missing required params: field, currency, or value');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updatePrice] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const currencyData = context.datasources?.currencies?.data || {};
    const currencies = currencyData?.list || [];
    const defaultCurrency = currencyData?.default_currency || 'KRW';
    const roundingMethod = currencyData?.rounding_method || 'round';

    const newPrices = { ...(state.form?.multi_currency_prices || {}) };
    newPrices[currency] = {
        ...(newPrices[currency] || {}),
        [field]: value,
    };

    // 기준 통화 변경 시 다른 통화 자동 계산
    if (autoFill && currency === defaultCurrency) {
        currencies.forEach((curr: Currency) => {
            if (curr.code !== defaultCurrency) {
                const exchangeRate = curr.exchange_rate ?? 1;
                const convertedValue = calculateCurrencyPrice(value, exchangeRate, roundingMethod);

                newPrices[curr.code] = {
                    ...(newPrices[curr.code] || {}),
                    [field]: convertedValue,
                };
            }
        });
    }

    // 메인 가격 필드 매핑
    const mainFieldMap: Record<string, string> = {
        list: 'list_price',
        selling: 'selling_price',
    };

    const mainFieldValue =
        currency === defaultCurrency ? value : state.form?.[mainFieldMap[field]];

    G7Core.state.setLocal({
        form: {
            ...state.form,
            multi_currency_prices: newPrices,
            [mainFieldMap[field]]: mainFieldValue,
        },
        hasChanges: true,
    });

    logger.log(`[updatePrice] Updated ${field} price for ${currency}: ${value}`);
}

/**
 * 전체 옵션 재고를 계산합니다.
 *
 * @param action 액션 객체 (params.options 필요)
 * @param _context 액션 컨텍스트
 * @returns 총 재고 수량
 */
export function calculateTotalOptionStockHandler(
    action: ActionWithParams,
    _context: ActionContext
): number {
    const params = action.params || {};
    const options = params.options as ProductOption[];

    if (!options || !Array.isArray(options) || options.length === 0) {
        return 0;
    }

    const totalStock = options.reduce((sum, opt) => {
        if (opt.is_active === false) return sum;
        return sum + (opt.stock_quantity || 0);
    }, 0);

    logger.log(`[calculateTotalOptionStock] Total stock: ${totalStock}`);
    return totalStock;
}

/**
 * 판매가/정가 관계를 검증합니다.
 *
 * - 판매가가 정가보다 클 경우 경고 표시
 * - 검증 결과를 _local.validation.price_error와 _local.errors.selling_price에 저장
 * - 토스트 경고 + 필드 에러 강조 + 하단 에러 메시지 표시
 *
 * 참고: 자동 바인딩 + debounce 사용 시, 상태 업데이트 완료 후 이 핸들러가 호출됩니다.
 * 따라서 state.form에서 최신 값을 읽을 수 있습니다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 *
 * @example
 * { "handler": "sirsoft-ecommerce.validatePriceRelation" }
 */
export function validatePriceRelationHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[validatePriceRelation] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    // 자동 바인딩 debounce 완료 후 호출되므로 상태에서 최신 값을 읽음
    const listPrice = parseFloat(String(state.form?.list_price)) || 0;
    const sellingPrice = parseFloat(String(state.form?.selling_price)) || 0;

    const hasError = sellingPrice > listPrice && listPrice > 0;

    const errorMessage = G7Core.t?.('sirsoft-ecommerce.admin.product.validation.selling_price_exceeds_list_price')
        ?? '판매가는 정가보다 클 수 없습니다.';

    // errors 객체 업데이트 - null 대신 삭제하여 상단 에러 박스가 올바르게 표시되도록
    const newErrors = { ...(state.errors || {}) };
    if (hasError) {
        newErrors.selling_price = [errorMessage];
    } else {
        delete newErrors.selling_price;
    }

    // errors 객체가 비어있으면 null로 설정 (상단 에러 박스 숨김)
    const hasAnyError = Object.keys(newErrors).length > 0;

    G7Core.state.setLocal({
        validation: {
            ...state.validation,
            price_error: hasError,
        },
        errors: hasAnyError ? newErrors : null,
    });

    if (hasError) {
        G7Core.toast?.warning?.(errorMessage);
        logger.warn(`[validatePriceRelation] Selling price (${sellingPrice}) exceeds list price (${listPrice})`);
    } else {
        logger.log('[validatePriceRelation] Price validation passed');
    }
}
