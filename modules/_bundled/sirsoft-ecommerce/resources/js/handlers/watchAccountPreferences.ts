/**
 * 계정에 저장된 결제 통화와 배송국가를 화면 전역 값에 맞추는 구독 핸들러 (이커머스 모듈 소유)
 *
 * 내 정보 수정에서 결제 통화나 배송국가를 바꿔 저장하면 화면은 로그인 사용자(`current_user`)를 다시 받는다.
 * 그 응답은 전역 `currentUser` 로 올라오고, 이 핸들러가 걸어 둔 구독이 `currentUser` 가 새 응답으로 바뀐 것을 보고
 * 응답의 `ecommerce_preferred_currency`, `ecommerce_preferred_shipping_country` 를
 * `_global.preferredCurrency`, `_global.preferredShippingCountry` 에 옮긴다. 그래서 새로고침 없이 헤더 셀렉터와
 * 가격 표시가 바뀐 값을 따른다.
 *
 * 사용자 템플릿은 두 키를 몰라도 된다. 이커머스가 `_user_base` 대상 조각(`user-account-preference-sync`)의
 * 초기화 액션으로 이 핸들러를 한 번 부르면 끝이다. 로그인 직후(`currentUser` 를 통째로 넣는 화면)에도 같은 구독이 돈다.
 *
 * - 초기화 액션은 화면을 옮길 때마다 다시 돌지만 구독은 한 번만 건다.
 * - `currentUser` 가 다른 객체로 바뀔 때만 옮긴다. 헤더 셀렉터로 통화를 바꾼 뒤(전역 값만 바뀌고 `currentUser` 는 그대로)에는
 *   아무 일도 하지 않으므로 방금 고른 값이 옛 응답 값으로 되돌아가지 않는다.
 * - 값이 형식에 맞지 않거나(통화 3글자, 국가 2글자) 지금 배송할 수 없는 나라면 옮기지 않는다.
 */

const WATCH_FLAG = '__sirsoftEcommerceAccountPreferenceWatch';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:WatchAccountPreferences')) ?? {
  log: (...args: unknown[]) => console.log('[Handler:WatchAccountPreferences]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:WatchAccountPreferences]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:WatchAccountPreferences]', ...args),
};

/**
 * 전역 상태를 읽습니다.
 *
 * @return 전역 상태 (읽지 못하면 빈 객체)
 */
function getGlobalState(): Record<string, any> {
  try {
    return (window as any).G7Core?.state?.get?.() || {};
  } catch {
    return {};
  }
}

/**
 * 지금 배송할 수 있는 나라 코드 목록을 전역 설정에서 읽습니다.
 *
 * @param state 전역 상태
 * @return 대문자 국가 코드 목록 (설정이 없으면 빈 배열)
 */
function getAllowedShippingCountries(state: Record<string, any>): string[] {
  const shipping = state.modules?.['sirsoft-ecommerce']?.shipping;
  const countries = Array.isArray(shipping?.available_countries) ? shipping.available_countries : [];

  return countries
    .filter((country: any) => country?.is_active === true && typeof country?.code === 'string')
    .map((country: any) => String(country.code).toUpperCase());
}

/**
 * 로그인 사용자 응답의 결제 통화와 배송국가를 전역 표시 값에 옮깁니다.
 *
 * @param user 전역 `currentUser` 값 (응답 본문 또는 그 `data`)
 */
export function applyAccountPreferences(user: any): void {
  if (!user || typeof user !== 'object') {
    return;
  }

  const G7Core = (window as any).G7Core;
  const state = getGlobalState();
  const source = user.ecommerce_preferred_currency !== undefined || user.ecommerce_preferred_shipping_country !== undefined
    ? user
    : (user.data ?? {});
  const updates: Record<string, string> = {};

  const currency = source.ecommerce_preferred_currency;
  if (typeof currency === 'string' && /^[A-Z]{3}$/.test(currency) && state.preferredCurrency !== currency) {
    updates.preferredCurrency = currency;
  }

  const country = source.ecommerce_preferred_shipping_country;
  const allowed = getAllowedShippingCountries(state);
  if (
    typeof country === 'string'
    && /^[A-Z]{2}$/.test(country)
    && (allowed.length === 0 || allowed.includes(country))
    && state.preferredShippingCountry !== country
  ) {
    updates.preferredShippingCountry = country;
  }

  if (Object.keys(updates).length > 0 && G7Core?.state?.set) {
    G7Core.state.set(updates);
    logger.log('Account preferences applied:', updates);
  }
}

/**
 * 전역 `currentUser` 의 변화를 구독해 계정 값을 전역 표시 값에 맞춥니다.
 *
 * @param _action 액션 정의 (인자 없음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function watchAccountPreferencesHandler(_action?: any, _context?: any): void {
  const G7Core = (window as any).G7Core;
  if (!G7Core?.state?.subscribe || !G7Core?.state?.get) {
    logger.warn('G7Core.state.subscribe not available');
    return;
  }

  const holder = window as any;
  if (holder[WATCH_FLAG]) {
    return; // 이미 구독 중이다 (초기화 액션은 화면을 옮길 때마다 다시 돈다)
  }

  let lastUser = getGlobalState().currentUser ?? null;

  const unsubscribe = G7Core.state.subscribe((state: Record<string, any>) => {
    const user = (state ?? getGlobalState()).currentUser ?? null;
    if (user === lastUser) {
      return;
    }
    lastUser = user;
    applyAccountPreferences(user);
  });

  holder[WATCH_FLAG] = { unsubscribe };
}

/**
 * 걸어 둔 구독을 풉니다 (테스트용).
 */
export function resetAccountPreferenceWatch(): void {
  const holder = window as any;
  try {
    holder[WATCH_FLAG]?.unsubscribe?.();
  } catch {
    // 구독 해제 실패는 무시한다
  }
  delete holder[WATCH_FLAG];
}
