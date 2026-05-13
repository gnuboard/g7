/**
 * 다중 통화 가격 표시 핸들러
 *
 * 사용자가 선택한 통화에 맞는 가격 문자열을 반환합니다.
 */

import { HandlerContext } from '../types';

interface CurrencyPrice {
  value: number;
  formatted: string;
}

interface Product {
  selling_price: number;
  selling_price_formatted: string;
  list_price?: number;
  list_price_formatted?: string;
  multi_currency_selling_price?: Record<string, CurrencyPrice>;
  multi_currency_list_price?: Record<string, CurrencyPrice>;
  [key: string]: unknown;
}

interface GetDisplayPriceParams {
  product: Product;
  priceField: 'selling_price' | 'list_price';
  currencyCode?: string;
}

/**
 * 선호 통화에 맞는 가격을 반환합니다.
 *
 * @param params.product - 상품 객체
 * @param params.priceField - 가격 필드 ('selling_price' | 'list_price')
 * @param params.currencyCode - 통화 코드 (선택, 미지정 시 전역 설정 사용)
 * @param context - 핸들러 컨텍스트
 * @returns 포맷팅된 가격 문자열
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "text": "{{handler('getDisplayPrice', { product: product.data, priceField: 'selling_price' })}}"
 * }
 */
export function getDisplayPriceHandler(
  params: GetDisplayPriceParams,
  context: HandlerContext
): string {
  const { product, priceField, currencyCode } = params;
  const preferredCurrency = currencyCode || context.getState('_global.preferredCurrency') || 'KRW';

  const multiCurrencyField = `multi_currency_${priceField}` as keyof Product;
  const multiCurrencyData = product[multiCurrencyField] as Record<string, CurrencyPrice> | undefined;

  if (multiCurrencyData && multiCurrencyData[preferredCurrency]) {
    return multiCurrencyData[preferredCurrency].formatted;
  }

  // 폴백: 기본 통화
  const formattedField = `${priceField}_formatted` as keyof Product;
  return (product[formattedField] as string) || String(product[priceField]);
}
