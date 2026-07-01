/**
 * 선호 통화 로드 핸들러
 *
 * localStorage에서 사용자가 선택한 통화 설정을 로드합니다.
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:Currency')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:Currency]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:Currency]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:Currency]', ...args),
};

import { HandlerContext } from '../types';

const STORAGE_KEY = 'g7_preferred_currency';
const DEFAULT_CURRENCY = 'KRW';

interface LoadPreferredCurrencyParams {
  defaultCurrency?: string;
}

/**
 * localStorage에서 선호 통화를 로드하여 전역 상태에 설정합니다.
 *
 * @param params.defaultCurrency - 기본 통화 (선택, 기본값: 'KRW')
 * @param context - 핸들러 컨텍스트
 * @returns 로드된 통화 코드
 *
 * @example
 * // _user_base.json의 init_actions에서 사용
 * {
 *   "init_actions": [
 *     {
 *       "handler": "loadPreferredCurrency",
 *       "params": { "defaultCurrency": "KRW" }
 *     }
 *   ]
 * }
 */
export function loadPreferredCurrencyHandler(
  params: LoadPreferredCurrencyParams,
  context: HandlerContext
): string {
  const { defaultCurrency = DEFAULT_CURRENCY } = params;

  let currency = defaultCurrency;

  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (stored && isValidCurrency(stored)) {
        currency = stored;
      }
    }
  } catch {
    // localStorage 접근 불가 시 기본값 사용
    logger.warn('localStorage 접근 불가, 기본 통화 사용:', defaultCurrency);
  }

  // 전역 상태에 설정
  context.setState('global', { preferredCurrency: currency });

  return currency;
}

/**
 * 선호 통화를 localStorage에 저장합니다.
 *
 * @param currencyCode - 저장할 통화 코드
 * @param context - 핸들러 컨텍스트
 *
 * @example
 * // 통화 선택 드롭다운에서 사용
 * {
 *   "actions": [
 *     {
 *       "type": "click",
 *       "handler": "savePreferredCurrency",
 *       "params": { "currencyCode": "{{currency.code}}" }
 *     }
 *   ]
 * }
 */
export function savePreferredCurrencyHandler(
  params: { currencyCode: string },
  context: HandlerContext
): void {
  const { currencyCode } = params;

  if (!isValidCurrency(currencyCode)) {
    logger.warn('유효하지 않은 통화 코드:', currencyCode);
    return;
  }

  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      localStorage.setItem(STORAGE_KEY, currencyCode);
    }
  } catch {
    logger.warn('localStorage 저장 실패');
  }

  // 전역 상태 업데이트
  context.setState('global', { preferredCurrency: currencyCode });
}

/**
 * 유효한 통화 코드 형식인지 확인합니다.
 *
 * 관리자가 추가/삭제하는 통화를 하드코딩 목록이 막지 않도록(A1-⑥), 고정 화이트리스트가 아니라
 * ISO 4217 형식(영문 대문자 3자리)으로 검증한다. 실제 노출 통화 제한은 셀렉터가 표시하는
 * _global.availableCurrencies(is_default || exchange_rate>0)가 담당한다.
 */
function isValidCurrency(code: string): boolean {
  return typeof code === 'string' && /^[A-Z]{3}$/.test(code);
}
