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

import { HandlerContext, TemplateActionDefinition } from '../types';

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

/**
 * 원화 접두·접미 번역을 읽는다 — 이커머스 모듈 번역 키(`sirsoft-ecommerce.currency.krw_prefix`·`krw_suffix`).
 *
 * 빈 문자열도 유효한 번역 값이다(ko 접두·en 접미는 비어 있다). 번역이 없어 키가 그대로 돌아오면 null.
 *
 * @param part 'prefix' | 'suffix'
 * @return 번역 값 (없으면 null)
 */
function krwAffix(part: 'prefix' | 'suffix'): string | null {
  const key = `sirsoft-ecommerce.currency.krw_${part}`;
  const value = (window as any).G7Core?.t?.(key);

  return typeof value === 'string' && value !== key ? value : null;
}

/**
 * 숫자 값을 통화 형식으로 포맷팅합니다.
 *
 * 원화 계열(₩/원)은 보는 언어의 접두·접미(ko 「원」 뒤·en 「₩」 앞·ja 「ウォン」 뒤), 그 외는 기호를 앞에 붙입니다
 * (백엔드 messages.currency.prefix/suffix 와 동일한 표기 규칙).
 *
 * @param action - 액션 정의 (`params.value` / `params.currencyCode` / `params.locale`)
 * @param _context - 핸들러 컨텍스트 (미사용 — 통화 설정은 G7Core 전역에서 읽는다)
 * @returns 포맷팅된 통화 문자열
 *
 * @example
 * formatCurrencyHandler({ handler: 'formatCurrency', params: { value: 10000, currencyCode: 'KRW' } }, context) // => "10,000원"
 * formatCurrencyHandler({ handler: 'formatCurrency', params: { value: 99.99, currencyCode: 'USD' } }, context) // => "$99.99"
 */
export function formatCurrencyHandler(
  action: TemplateActionDefinition,
  _context?: HandlerContext
): string {
  const params = (action?.params ?? {}) as FormatCurrencyParams;
  const { value, currencyCode, locale: customLocale } = params;
  const currencies = getConfiguredCurrencies();

  const code = currencyCode
    || readGlobal('preferredCurrency')
    || readGlobal('defaultCurrency')
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

  // 원화 계열(₩/원)은 보는 사람의 언어 접두·접미를 따른다 — 서버(`formatPrice`)·이커머스 공용 formatCurrency 와 같은
  // 번역 키다. 고정 「원」이면 영어 화면에서 서버 금액(₩5,000)과 화면 계산 금액(5,000원)이 섞인다(S3-D23 결함군).
  // 번역을 읽지 못하면 종전 표기(금액 뒤 「원」)로 돌아간다.
  if (code === 'KRW' || symbol === '₩' || symbol === '원') {
    const prefix = krwAffix('prefix');
    const suffix = krwAffix('suffix');

    if (prefix !== null || suffix !== null) {
      return `${prefix ?? ''}${formatted}${suffix ?? ''}`;
    }

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
