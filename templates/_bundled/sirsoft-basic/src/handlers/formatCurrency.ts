/**
 * 통화 포맷팅 핸들러
 *
 * 숫자 값을 통화 형식 문자열로 변환합니다.
 */

import { HandlerContext } from '../types';

interface FormatCurrencyParams {
  value: number;
  currencyCode?: string;
  locale?: string;
}

interface CurrencyConfig {
  code: string;
  symbol: string;
  locale: string;
  decimals: number;
}

/**
 * 통화별 기본 설정
 */
const CURRENCY_CONFIGS: Record<string, CurrencyConfig> = {
  KRW: { code: 'KRW', symbol: '₩', locale: 'ko-KR', decimals: 0 },
  USD: { code: 'USD', symbol: '$', locale: 'en-US', decimals: 2 },
  JPY: { code: 'JPY', symbol: '¥', locale: 'ja-JP', decimals: 0 },
  CNY: { code: 'CNY', symbol: '¥', locale: 'zh-CN', decimals: 2 },
  EUR: { code: 'EUR', symbol: '€', locale: 'de-DE', decimals: 2 },
};

/**
 * 숫자 값을 통화 형식으로 포맷팅합니다.
 *
 * @param params.value - 포맷팅할 숫자 값
 * @param params.currencyCode - 통화 코드 (KRW, USD, JPY 등)
 * @param params.locale - 로케일 (선택, 미지정 시 통화 기본 로케일 사용)
 * @param context - 핸들러 컨텍스트
 * @returns 포맷팅된 통화 문자열
 *
 * @example
 * formatCurrencyHandler({ value: 10000, currencyCode: 'KRW' }, context)
 * // => "₩10,000" 또는 "10,000원"
 *
 * formatCurrencyHandler({ value: 99.99, currencyCode: 'USD' }, context)
 * // => "$99.99"
 */
export function formatCurrencyHandler(
  params: FormatCurrencyParams,
  context: HandlerContext
): string {
  const { value, currencyCode, locale: customLocale } = params;
  const currency = currencyCode || context.getState('_global.preferredCurrency') || 'KRW';

  const config = CURRENCY_CONFIGS[currency] || CURRENCY_CONFIGS.KRW;
  const locale = customLocale || config.locale;

  try {
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: config.code,
      minimumFractionDigits: config.decimals,
      maximumFractionDigits: config.decimals,
    }).format(value);
  } catch {
    // Intl 지원 안 되는 환경 폴백
    const formatted = value.toLocaleString(undefined, {
      minimumFractionDigits: config.decimals,
      maximumFractionDigits: config.decimals,
    });
    return `${config.symbol}${formatted}`;
  }
}

/**
 * 통화 심볼만 반환합니다.
 *
 * @param currencyCode - 통화 코드
 * @returns 통화 심볼
 */
export function getCurrencySymbol(currencyCode: string): string {
  return CURRENCY_CONFIGS[currencyCode]?.symbol || currencyCode;
}
