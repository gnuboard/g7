// e2e:allow base_unit 환산 공식(÷base_unit) 정확성은 단위 테스트(calculateCurrencyPrices.test.ts: USD base ¥0 회귀 + KRW base 등가)로 전수 검증. 다통화 표시 회귀는 product-option-multicurrency-readonly.spec.ts 가 구조적으로 차단.
/**
 * 실시간 환율 계산 핸들러
 *
 * 기본 통화 가격을 입력받아 환율 기반으로 다중 통화 가격을 계산합니다.
 * 옵션 가격 인라인 편집 시 외화 가격 실시간 업데이트에 사용됩니다.
 */

import type { ActionContext } from '../types';

interface Currency {
    code: string;
    name?: Record<string, string>;
    exchange_rate?: number | null;
    is_default?: boolean;
    rounding_unit?: string;
    rounding_method?: string;
    base_unit?: number;
    symbol?: string;
    decimal_places?: number;
}

/**
 * 통화 코드별 소수 자릿수 폴백 (설정에 decimal_places 가 없을 때만 사용).
 */
const DECIMAL_PLACES_FALLBACK: Record<string, number> = {
    KRW: 0,
    JPY: 0,
};

/**
 * 통화 코드별 기본 기호 폴백 (설정 symbol 이 비어 있을 때만 사용).
 * 위안화(CNY)는 엔화(¥)와 구분되도록 元 사용.
 */
const SYMBOL_FALLBACK: Record<string, string> = {
    KRW: '₩',
    USD: '$',
    JPY: '¥',
    CNY: '元',
    EUR: '€',
    GBP: '£',
};

/**
 * base_unit 미설정 통화의 폴백 (소액 통화만 묶음 단위). 백엔드 CurrencyConversionService 와 동일.
 */
const BASE_UNIT_FALLBACK: Record<string, number> = {
    KRW: 1000,
    JPY: 100,
};

/**
 * 통화의 base_unit(기본 통화일 때 환율 분모가 되는 1단위 금액)을 반환합니다.
 *
 * @param currency 통화 설정
 * @returns base_unit (최소 1)
 */
function resolveBaseUnit(currency: Currency | undefined): number {
    if (!currency) {
        return 1;
    }
    const unit = currency.base_unit ?? BASE_UNIT_FALLBACK[currency.code] ?? 1;
    return Math.max(1, unit);
}

interface CurrencyPrice {
    price: number;
    formatted: string;
    is_default: boolean;
    editable: boolean;
    exchange_rate?: number;
}

interface CalculateCurrencyPricesParams {
    basePrice: number | string;
    currencies?: Currency[];
}

/**
 * 절사/반올림/올림을 적용합니다.
 *
 * @param price 가격
 * @param unit 절사 단위
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
 * 통화별 가격을 포맷팅합니다.
 *
 * 통화 기호를 하드코딩하지 않고 설정(symbol)·소수 자릿수(decimal_places)를 따릅니다.
 * 원화(KRW)는 "3,000원"처럼 기호를 금액 뒤에 붙이고, 그 외 통화는 기호를 앞에 붙입니다
 * (백엔드 messages.currency.prefix/suffix 와 동일한 표기 규칙). 위안화(CNY)는 엔화(¥)와
 * 구분되도록 元 기호를 사용합니다. currency 객체가 없으면 코드만으로 폴백 기호를 적용합니다.
 *
 * @param price 가격
 * @param currency 통화 설정 객체 (또는 통화 코드 문자열)
 * @returns 포맷팅된 가격
 */
export function formatCurrency(
    price: number,
    currency: { code: string; symbol?: string; decimal_places?: number } | string
): string {
    const c = typeof currency === 'string' ? { code: currency } : currency;
    const code = c.code;
    const symbol = (c.symbol && c.symbol.length > 0)
        ? c.symbol
        : (SYMBOL_FALLBACK[code] ?? '');
    const decimalPlaces = c.decimal_places ?? DECIMAL_PLACES_FALLBACK[code] ?? 2;
    const formattedNumber = price.toLocaleString(undefined, {
        minimumFractionDigits: decimalPlaces,
        maximumFractionDigits: decimalPlaces,
    });

    // 기호가 없으면 코드 접미 (백엔드 동일 규칙)
    if (!symbol) {
        return formattedNumber + ' ' + code;
    }

    // 원화 계열(₩/원)은 금액 뒤에 "원", 그 외는 기호를 앞에 표기
    if (code === 'KRW' || symbol === '₩' || symbol === '원') {
        return formattedNumber + '원';
    }

    return symbol + formattedNumber;
}

/**
 * 실시간 환율 계산 핸들러
 *
 * 기본 통화 가격을 기준으로 모든 통화의 가격을 계산합니다.
 *
 * @param params 핸들러 파라미터
 * @param _context 액션 컨텍스트 (미사용)
 * @returns 통화별 가격 정보 객체
 */
export function calculateCurrencyPricesHandler(
    params: CalculateCurrencyPricesParams,
    _context: ActionContext
): Record<string, CurrencyPrice> {
    const { basePrice, currencies } = params;
    const price = parseFloat(String(basePrice)) || 0;

    if (!currencies || !Array.isArray(currencies) || currencies.length === 0) {
        return {};
    }

    // 기본 통화의 base_unit = 환율 분모 (백엔드 getDefaultBaseUnit 와 동일)
    const defaultCurrency = currencies.find((c) => c.is_default);
    const baseUnit = resolveBaseUnit(defaultCurrency);

    const result: Record<string, CurrencyPrice> = {};

    for (const currency of currencies) {
        const code = currency.code;
        const isDefault = currency.is_default ?? false;

        if (isDefault) {
            // 기본 통화
            result[code] = {
                price,
                formatted: formatCurrency(price, currency),
                is_default: true,
                editable: true,
            };
        } else if (currency.exchange_rate && currency.exchange_rate > 0) {
            // 외화: 환율 기반 계산
            // 계산: (기본통화가격 / 기본통화.base_unit) * exchange_rate
            const convertedPrice = (price / baseUnit) * currency.exchange_rate;
            const roundedPrice = applyRounding(
                convertedPrice,
                currency.rounding_unit || '0.01',
                currency.rounding_method || 'round'
            );

            result[code] = {
                price: roundedPrice,
                formatted: formatCurrency(roundedPrice, currency),
                is_default: false,
                editable: false,
                exchange_rate: currency.exchange_rate,
            };
        }
    }

    return result;
}
