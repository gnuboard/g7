/**
 * 표시 통화(preferredCurrency) 초기화 핸들러 (이커머스 모듈 소유)
 *
 * "통화 = 커머스 책임" 원칙에 따라, 표시 통화 초기화 핸들러는 템플릿이 아닌 이커머스 모듈이
 * 소유한다. 유저(_user_base)·관리자(_admin_base) 양 템플릿의 init_actions 에서
 * `sirsoft-ecommerce.initPreferredCurrency` 로 호출되어, 모듈 활성 시에만 동작한다.
 *
 * 두 시점에서 호출되어 이벤트 기반으로 계정 통화를 적용한다(폴링 없음):
 *   1. init_actions — 페이지 진입 즉시 세션/기본 통화로 초기화 (accountCurrency 미전달)
 *   2. current_user data_source 의 onSuccess — 로그인 유저 응답 도착 시 계정 영속 통화로
 *      덮어쓰기. onSuccess 핸들러에 `accountCurrency: {{response.data.data.ecommerce_preferred_currency}}`
 *      를 전달하므로, 응답 페이로드에서 직접 통화를 읽어 적용한다(전역 상태 반영 타이밍 비의존).
 *
 * 표준 `loadFromLocalStorage` 는 `context.setState`(React 컴포넌트 state)만 갱신해
 * init_actions(컴포넌트 컨텍스트 부재)에서는 _global 승격이 실패한다. 따라서 `G7Core.state.set`
 * 직접 호출로 _global 에 동기 주입한다(initCartKey/initGuestOrderToken 동일 패턴).
 *
 * 우선순위(§D-USERCUR-3 / D-LOGIN-CUR / D-SIGNUP 게스트 확장):
 *   1. 로그인 유저의 계정 영속 통화(onSuccess 가 전달한 params.accountCurrency) — 1순위
 *   2. localStorage 세션 선택값(g7_preferred_currency, ISO 4217 형식만) — 수동 선택
 *   3. 게스트 사이트 언어(g7_locale) 기반 통화 추정 — currencies[].locales 매핑
 *      (회원가입 통화 부여 SignupCurrencyResolver 와 동일 입력·동일 우선순위)
 *   4. params.defaultCurrency(관리자 설정 default_currency)
 *   5. 'KRW' 최종 폴백
 */

const PREFERRED_CURRENCY_STORAGE_NAME = 'g7_preferred_currency';
const LOCALE_STORAGE_NAME = 'g7_locale';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:InitPreferredCurrency')) ?? {
  log: (...args: unknown[]) => console.log('[Handler:InitPreferredCurrency]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:InitPreferredCurrency]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:InitPreferredCurrency]', ...args),
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
 * 게스트의 현재 사이트 언어(g7_locale)를 반환합니다.
 *
 * 회원가입 통화 부여(SignupCurrencyResolver)의 입력인 `$user->language` 는 가입 폼 언어
 * 기본값 `$locale`(= g7_locale)에서 비롯되므로, 게스트 추정도 동일 입력을 사용한다.
 *
 * @return 사이트 언어 코드(예: 'ko', 'en') 또는 null
 */
function getSiteLocale(): string | null {
  try {
    return localStorage.getItem(LOCALE_STORAGE_NAME);
  } catch {
    return null;
  }
}

/**
 * 환경설정의 통화 목록을 _global 에서 읽습니다.
 *
 * @return currencies 배열(각 항목에 code/is_default/locales 포함) 또는 빈 배열
 */
function getCurrencies(): any[] {
  try {
    const G7Core = (window as any).G7Core;
    const state = G7Core?.state?.get?.() || {};
    const lc = state?.modules?.['sirsoft-ecommerce']?.language_currency;
    return Array.isArray(lc?.currencies) ? lc.currencies : [];
  } catch {
    return [];
  }
}

/**
 * 사이트 언어(locale)를 통화별 사용 언어(currencies[].locales) 매핑과 대조해 통화를 추정합니다.
 *
 * 백엔드 SignupCurrencyResolver 와 동일한 우선순위:
 *   1) 단일 매칭 → 그 통화
 *   2) 중복 매칭 → 매칭된 통화 중 is_default 우선, 없으면 추정 실패(모호 회피)
 *   3) 매칭 없음 → 추정 실패(상위 폴백에 위임)
 *
 * @param locale 사이트 언어 코드(ko-KR 등 region suffix 정규화)
 * @return 추정된 통화 코드 또는 null(추정 불가)
 */
function resolveCurrencyByLocale(locale: string | null): string | null {
  if (!locale) {
    return null;
  }
  // locale 정규화 (ko-KR / ko_KR → ko)
  const normalized = locale.toLowerCase().split('-')[0].split('_')[0];
  if (!normalized) {
    return null;
  }

  const currencies = getCurrencies();
  const matched = currencies.filter((c) => {
    const locales = Array.isArray(c?.locales) ? c.locales : [];
    return locales.some((l: unknown) => String(l).toLowerCase() === normalized);
  });

  // 1) 단일 매칭
  if (matched.length === 1) {
    return matched[0]?.code ?? null;
  }
  // 2) 중복 매칭 → is_default 우선, 없으면 모호 → 실패
  if (matched.length > 1) {
    const def = matched.find((c) => c?.is_default === true);
    return def?.code ?? null;
  }
  // 3) 매칭 없음 → 실패(상위 폴백)
  return null;
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
 * 표시 통화(preferredCurrency)를 _global 에 동기 주입합니다.
 *
 * @param action params.defaultCurrency — 관리자 설정 기본 통화(미지정 시 KRW),
 *               params.accountCurrency — onSuccess 가 전달하는 계정 영속 통화(init_actions 에선 미전달)
 * @param _context 액션 컨텍스트 (사용하지 않음 — _global 직접 set)
 */
export function initPreferredCurrencyHandler(
  action?: any,
  _context?: any
): void {
  const defaultCurrency = action?.params?.defaultCurrency || 'KRW';
  const isValid = (code: unknown): code is string =>
    typeof code === 'string' && /^[A-Z]{3}$/.test(code);

  // 1순위: 로그인 유저 계정 영속 통화 (onSuccess 가 전달한 값을 우선, 폴백으로 현재 유저)
  const user = getCurrentUser();
  const persisted = action?.params?.accountCurrency ?? user?.ecommerce_preferred_currency;

  // 2순위: localStorage 세션 선택값
  let stored: string | null = null;
  try {
    stored = localStorage.getItem(PREFERRED_CURRENCY_STORAGE_NAME);
  } catch {
    // localStorage 접근 불가 시 무시
  }

  // 3순위: 게스트 사이트 언어(g7_locale) 기반 통화 추정 (회원가입과 동일 매핑)
  // 로그인 유저(persisted 존재)는 이미 1순위로 결정되므로 추정을 건너뛴다.
  const localeGuess = persisted ? null : resolveCurrencyByLocale(getSiteLocale());

  const resolved = isValid(persisted)
    ? persisted
    : isValid(stored)
      ? stored
      : isValid(localeGuess)
        ? localeGuess
        : isValid(defaultCurrency)
          ? defaultCurrency
          : 'KRW';

  setGlobalState({ preferredCurrency: resolved });
  logger.log('Preferred currency initialized:', resolved);
}
