/**
 * 다중 통화 가격 표시 핸들러
 *
 * 사용자가 선택한 통화에 맞는 가격 문자열을 반환합니다.
 */

import { HandlerContext, TemplateActionDefinition } from '../types';

/**
 * 전역 상태의 한 경로를 읽는다.
 *
 * 엔진 `ActionContext` 에는 `getState` 가 없다 — 상태 조회 공개 통로는 `G7Core.state.get()` 이다.
 *
 * @param key 전역 상태 키 (예: 'preferredCurrency')
 * @return 값 (없으면 undefined)
 */
function readGlobal(key: string): string | undefined {
  const value = (window as any).G7Core?.state?.get?.()?.[key];

  return typeof value === 'string' && value.length > 0 ? value : undefined;
}


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
 * @param action - 액션 정의 (`params.product` / `params.priceField` / `params.currencyCode`)
 * @param _context - 핸들러 컨텍스트 (미사용 — 표시 통화는 G7Core 전역에서 읽는다)
 * @returns 포맷팅된 가격 문자열
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "text": "{{handler('getDisplayPrice', { product: product.data, priceField: 'selling_price' })}}"
 * }
 */
export function getDisplayPriceHandler(
  action: TemplateActionDefinition,
  _context?: HandlerContext
): string {
  const params = (action?.params ?? {}) as GetDisplayPriceParams;
  const { product, priceField, currencyCode } = params;
  // 통화를 못 박지 않는다 — 판정 실패 시 아래 *_formatted(기준 통화 표기)로 폴백한다.
  const preferredCurrency = currencyCode
    || readGlobal('preferredCurrency')
    || readGlobal('defaultCurrency')
    || '';

  const multiCurrencyField = `multi_currency_${priceField}` as keyof Product;
  const multiCurrencyData = product[multiCurrencyField] as Record<string, CurrencyPrice> | undefined;

  if (multiCurrencyData && multiCurrencyData[preferredCurrency]) {
    return multiCurrencyData[preferredCurrency].formatted;
  }

  // 폴백: 기본 통화
  const formattedField = `${priceField}_formatted` as keyof Product;
  return (product[formattedField] as string) || String(product[priceField]);
}
