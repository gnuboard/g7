/**
 * 통화 포맷팅 핸들러
 *
 * 숫자 값을 통화 형식 문자열로 변환합니다.
 *
 * 통화 목록은 운영자가 관리자에서 추가/삭제하므로, 이 핸들러는 통화표를 고정해 두지
 * 않고 쇼핑몰 설정(`language_currency.currencies`)의 기호(symbol)와 소수 자릿수
 * (decimal_places)를 따릅니다. 고정 통화표를 두면 설정에 없는 통화가 전부 특정 통화로
 * 폴백되어(예: GBP 를 ₩ + 0자리로) 값은 맞고 단위만 틀린 금액이 화면에 나갑니다.
 */

import { HandlerContext } from '../types';

interface FormatCurrencyParams {
  value: number;
  currencyCode?: string;
  locale?: string;
}

interface CurrencyConfig {
  code: string;
  symbol?: string;
  decimal_places?: number;
  is_default?: boolean;
}

/**
 * 설정에 symbol 이 비어 있을 때만 쓰는 기호 폴백.
 * 위안화(CNY)는 엔화(¥)와 구분되도록 元 을 사용합니다 (이커머스 모듈과 동일 규칙).
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
 * 설정에 decimal_places 가 없을 때만 쓰는 소수 자릿수 폴백.
 */
const DECIMAL_PLACES_FALLBACK: Record<string, number> = {
  KRW: 0,
  JPY: 0,
};

/**
 * 쇼핑몰 설정에 등록된 통화 목록을 전역 상태에서 읽습니다.
 *
 * @returns currencies 배열 (읽지 못하면 빈 배열)
 */
function getConfiguredCurrencies(): CurrencyConfig[] {
  try {
    const state = (window as any).G7Core?.state?.get?.() || {};
    const lc = state?.modules?.['sirsoft-ecommerce']?.language_currency;

    return Array.isArray(lc?.currencies) ? lc.currencies : [];
  } catch {
    return [];
  }
}

/**
 * 숫자 값을 통화 형식으로 포맷팅합니다.
 *
 * 원화 계열(₩/원)은 금액 뒤에 "원", 그 외는 기호를 앞에 붙입니다
 * (백엔드 messages.currency.prefix/suffix 와 동일한 표기 규칙).
 *
 * @param params.value - 포맷팅할 숫자 값
 * @param params.currencyCode - 통화 코드 (미지정 시 표시 통화 → 기본 통화 순)
 * @param params.locale - 로케일 (숫자 구분자용, 미지정 시 브라우저 로케일)
 * @param context - 핸들러 컨텍스트
 * @returns 포맷팅된 통화 문자열
 *
 * @example
 * formatCurrencyHandler({ value: 10000, currencyCode: 'KRW' }, context) // => "10,000원"
 * formatCurrencyHandler({ value: 99.99, currencyCode: 'USD' }, context) // => "$99.99"
 */
export function formatCurrencyHandler(
  params: FormatCurrencyParams,
  context: HandlerContext
): string {
  const { value, currencyCode, locale: customLocale } = params;
  const currencies = getConfiguredCurrencies();

  const code = currencyCode
    || context.getState('_global.preferredCurrency')
    || context.getState('_global.defaultCurrency')
    || currencies.find((c) => c.is_default)?.code;

  if (!code) {
    // 통화를 판정할 수 없으면 단위를 임의로 붙이지 않는다 — 그것이 곧 하드코딩이다.
    return value.toLocaleString(customLocale);
  }

  const config = currencies.find((c) => c.code === code);
  const symbol = (config?.symbol && config.symbol.length > 0)
    ? config.symbol
    : (SYMBOL_FALLBACK[code] ?? '');
  const decimals = config?.decimal_places ?? DECIMAL_PLACES_FALLBACK[code] ?? 2;

  const formatted = value.toLocaleString(customLocale, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });

  // 기호를 모르면 코드 접미 (백엔드 동일 규칙)
  if (!symbol) {
    return `${formatted} ${code}`;
  }

  if (code === 'KRW' || symbol === '₩' || symbol === '원') {
    return `${formatted}원`;
  }

  return `${symbol}${formatted}`;
}

/**
 * 통화 심볼만 반환합니다.
 *
 * 설정의 symbol 을 우선하고, 없으면 폴백 표, 그것도 없으면 통화 코드를 그대로 돌려줍니다.
 *
 * @param currencyCode - 통화 코드
 * @returns 통화 심볼
 */
export function getCurrencySymbol(currencyCode: string): string {
  const config = getConfiguredCurrencies().find((c) => c.code === currencyCode);

  return (config?.symbol && config.symbol.length > 0)
    ? config.symbol
    : (SYMBOL_FALLBACK[currencyCode] || currencyCode);
}
