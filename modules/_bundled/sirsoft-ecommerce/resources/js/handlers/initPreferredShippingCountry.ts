/**
 * 선호 배송국가(preferredShippingCountry) 초기화 핸들러 (이커머스 모듈 소유)
 *
 * "배송국가 = 커머스 책임" 원칙에 따라, 선호 배송국가 초기화 핸들러는 템플릿이 아닌 이커머스
 * 모듈이 소유한다(initPreferredCurrency 미러). 유저(_user_base) 템플릿의 init_actions 에서
 * `sirsoft-ecommerce.initPreferredShippingCountry` 로 호출되어, 모듈 활성 시에만 동작한다.
 *
 * 두 시점에서 호출되어 이벤트 기반으로 계정 배송국가를 적용한다(폴링 없음):
 *   1. init_actions — 페이지 진입 즉시 세션/기본 국가로 초기화 (accountCountry 미전달)
 *   2. current_user data_source 의 onSuccess — 로그인 유저 응답 도착 시 계정 영속 배송국가로
 *      덮어쓰기. onSuccess 핸들러에 `accountCountry: {{response.data.data.ecommerce_preferred_shipping_country}}`
 *      를 전달하므로, 응답 페이로드에서 직접 국가를 읽어 적용한다.
 *
 * 우선순위:
 *   1. 로그인 유저의 계정 영속 배송국가(onSuccess 가 전달한 params.accountCountry) — 1순위
 *   2. localStorage 세션 선택값(g7_preferred_shipping_country, ISO 3166-1 alpha-2 형식만)
 *   3. params.defaultCountry(관리자 설정 default_country)
 *   4. 'KR' 최종 폴백
 */

const PREFERRED_SHIPPING_COUNTRY_STORAGE_NAME = 'g7_preferred_shipping_country';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:InitPreferredShippingCountry')) ?? {
  log: (...args: unknown[]) => console.log('[Handler:InitPreferredShippingCountry]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:InitPreferredShippingCountry]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:InitPreferredShippingCountry]', ...args),
};

/**
 * 전역 상태 설정 헬퍼
 *
 * @param updates 전역 상태에 병합할 키/값
 */
function setGlobalState(updates: Record<string, any>): void {
  const G7Core = (window as any).G7Core;
  if (G7Core?.state?.set) {
    G7Core.state.set(updates);
  } else {
    logger.warn('G7Core.state.set not available');
  }
}

/**
 * 현재 로그인 사용자 확인 헬퍼
 *
 * @return 현재 사용자 객체 또는 null
 */
function getCurrentUser(): any {
  try {
    const g7Config = (window as any).G7Config;
    if (g7Config?.user) {
      return g7Config.user;
    }
    const G7Core = (window as any).G7Core;
    const globalState = G7Core?.state?.get?.() || {};
    return globalState.currentUser || null;
  } catch {
    return null;
  }
}

/**
 * 선호 배송국가(preferredShippingCountry)를 _global 에 동기 주입합니다.
 *
 * @param action params.defaultCountry — 관리자 설정 기본 국가(미지정 시 KR),
 *               params.accountCountry — onSuccess 가 전달하는 계정 영속 배송국가(init_actions 에선 미전달)
 * @param _context 액션 컨텍스트 (사용하지 않음 — _global 직접 set)
 */
export function initPreferredShippingCountryHandler(
  action?: any,
  _context?: any
): void {
  const defaultCountry = action?.params?.defaultCountry || 'KR';
  const isValid = (code: unknown): code is string =>
    typeof code === 'string' && /^[A-Z]{2}$/.test(code);

  // 1순위: 로그인 유저 계정 영속 배송국가 (onSuccess 가 전달한 값을 우선, 폴백으로 현재 유저)
  const user = getCurrentUser();
  const persisted = action?.params?.accountCountry ?? user?.ecommerce_preferred_shipping_country;

  // 2순위: localStorage 세션 선택값
  let stored: string | null = null;
  try {
    stored = localStorage.getItem(PREFERRED_SHIPPING_COUNTRY_STORAGE_NAME);
  } catch {
    // localStorage 접근 불가 시 무시
  }

  const resolved = isValid(persisted)
    ? persisted
    : isValid(stored)
      ? stored
      : isValid(defaultCountry)
        ? defaultCountry
        : 'KR';

  setGlobalState({ preferredShippingCountry: resolved });
  logger.log('Preferred shipping country initialized:', resolved);
}
