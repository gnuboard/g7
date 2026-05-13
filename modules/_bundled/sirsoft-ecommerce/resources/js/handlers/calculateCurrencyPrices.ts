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
 * @param price 가격
 * @param code 통화 코드
 * @returns 포맷팅된 가격
 */
function formatCurrency(price: number, code: string): string {
    switch (code) {
        case 'KRW':
            return price.toLocaleString() + '원';
        case 'USD':
            return '$' + price.toFixed(2);
        case 'JPY':
            return '¥' + Math.floor(price).toLocaleString();
        case 'CNY':
            return '¥' + price.toFixed(2);
        case 'EUR':
            return '€' + price.toFixed(2);
        default:
            return price.toFixed(2) + ' ' + code;
    }
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

    const result: Record<string, CurrencyPrice> = {};

    for (const currency of currencies) {
        const code = currency.code;
        const isDefault = currency.is_default ?? false;

        if (isDefault) {
            // 기본 통화
            result[code] = {
                price,
                formatted: formatCurrency(price, code),
                is_default: true,
                editable: true,
            };
        } else if (currency.exchange_rate && currency.exchange_rate > 0) {
            // 외화: 환율 기반 계산
            // 계산: (기본통화가격 / 1000) * exchange_rate
            const convertedPrice = (price / 1000) * currency.exchange_rate;
            const roundedPrice = applyRounding(
                convertedPrice,
                currency.rounding_unit || '0.01',
                currency.rounding_method || 'round'
            );

            result[code] = {
                price: roundedPrice,
                formatted: formatCurrency(roundedPrice, code),
                is_default: false,
                editable: false,
                exchange_rate: currency.exchange_rate,
            };
        }
    }

    return result;
}
