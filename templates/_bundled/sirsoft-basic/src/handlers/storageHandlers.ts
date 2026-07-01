/**
 * 로컬 스토리지 관련 핸들러
 *
 * 비로그인 사용자의 장바구니 키 등 클라이언트 스토리지 관리를 처리합니다.
 *
 * 핸들러 시그니처: ActionDispatcher의 ActionHandler 형식을 따름
 * (action: ActionDefinition, context: ActionContext) => void | Promise<void>
 *
 * G7Core API 사용:
 * - G7Core.state.set() - 전역 상태 설정
 * - G7Core.state.get()._global?.currentUser - 현재 사용자 확인
 */

const CART_KEY_STORAGE_NAME = 'g7_cart_key';
const CART_KEY_API_ENDPOINT = '/api/modules/sirsoft-ecommerce/cart/key';
const GUEST_ORDER_TOKEN_STORAGE_NAME = 'g7_guest_order_token';
const GUEST_ORDER_NUMBER_STORAGE_NAME = 'g7_guest_order_number';
const GUEST_ORDER_EXPIRES_AT_STORAGE_NAME = 'g7_guest_order_expires_at';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:Storage')) ?? {
  log: (...args: unknown[]) => console.log('[Handler:Storage]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:Storage]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:Storage]', ...args),
};

/**
 * 백엔드 API를 통해 Cart Key 발급
 *
 * @returns 발급된 cart_key 또는 null (실패 시)
 */
async function issueCartKeyFromApi(): Promise<string | null> {
  try {
    const response = await fetch(CART_KEY_API_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
    });

    if (!response.ok) {
      logger.error('Failed to issue cartKey from API:', response.status);
      return null;
    }

    const data = await response.json();
    return data.data?.cart_key || null;
  } catch (error) {
    logger.error('Error issuing cartKey from API:', error);
    return null;
  }
}

/**
 * 전역 상태 설정 헬퍼
 */
function setGlobalState(updates: Record<string, any>): void {
  const G7Core = (window as any).G7Core;
  if (G7Core?.state?.set) {
    G7Core.state.set(updates);
    logger.log('Global state updated:', updates);
  } else {
    logger.warn('G7Core.state.set not available');
  }
}

/**
 * 현재 로그인 사용자 확인 헬퍼
 *
 * G7Config.user 또는 전역 상태에서 currentUser를 확인합니다.
 */
function getCurrentUser(): any {
  try {
    // G7Config에서 사용자 정보 확인 (서버에서 주입)
    const g7Config = (window as any).G7Config;
    if (g7Config?.user) {
      return g7Config.user;
    }

    // 전역 상태에서 확인
    const G7Core = (window as any).G7Core;
    const globalState = G7Core?.state?.get?.() || {};
    return globalState.currentUser || null;
  } catch {
    return null;
  }
}

/**
 * 장바구니 키 초기화
 *
 * 비로그인 사용자의 장바구니를 식별하기 위한 키를 생성/로드합니다.
 * 이미 저장된 키가 있으면 로드하고, 없으면 백엔드 API를 통해 새로 발급합니다.
 *
 * ActionDispatcher 핸들러 형식: (action, context) => void | Promise<void>
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export async function initCartKeyHandler(
  _action?: any,
  _context?: any
): Promise<void> {
  // 로그인/비로그인 사용자 모두 cartKey 필요 (API 헤더에 포함)
  // localStorage에서 기존 cartKey 확인
  let cartKey: string | null = null;
  try {
    cartKey = localStorage.getItem(CART_KEY_STORAGE_NAME);
  } catch {
    // localStorage 접근 불가 시 무시
  }

  // 없으면 백엔드 API를 통해 새로 발급
  if (!cartKey) {
    cartKey = await issueCartKeyFromApi();

    if (cartKey) {
      try {
        localStorage.setItem(CART_KEY_STORAGE_NAME, cartKey);
        logger.log('New cartKey issued from API:', cartKey);
      } catch {
        // localStorage 저장 실패 시 무시
      }
    } else {
      logger.error('Failed to issue cartKey from API');
    }
  } else {
    logger.log('Existing cartKey loaded:', cartKey);
  }

  setGlobalState({ cartKey });
}

/**
 * 장바구니 키 가져오기
 *
 * 현재 저장된 장바구니 키를 반환합니다.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function getCartKeyHandler(
  _action?: any,
  _context?: any
): string | null {
  try {
    return localStorage.getItem(CART_KEY_STORAGE_NAME);
  } catch {
    return null;
  }
}

/**
 * 장바구니 키 삭제
 *
 * 로그인 시 게스트 장바구니 키를 삭제합니다 (서버에서 병합 후).
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function clearCartKeyHandler(
  _action?: any,
  _context?: any
): void {
  try {
    localStorage.removeItem(CART_KEY_STORAGE_NAME);
    logger.log('cartKey removed from localStorage');
  } catch {
    // localStorage 접근 불가 시 무시
  }

  setGlobalState({ cartKey: null });
}

/**
 * 장바구니 키 새로 생성
 *
 * 기존 키를 삭제하고 백엔드 API를 통해 새로운 키를 발급합니다.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export async function regenerateCartKeyHandler(
  _action?: any,
  _context?: any
): Promise<void> {
  // 로그인된 사용자는 cartKey 불필요
  const currentUser = getCurrentUser();
  if (currentUser) {
    clearCartKeyHandler();
    return;
  }

  const newCartKey = await issueCartKeyFromApi();

  if (newCartKey) {
    try {
      localStorage.setItem(CART_KEY_STORAGE_NAME, newCartKey);
      logger.log('New cartKey regenerated from API:', newCartKey);
    } catch {
      // localStorage 저장 실패 시 무시
    }

    setGlobalState({ cartKey: newCartKey });
  } else {
    logger.error('Failed to regenerate cartKey from API');
  }
}

/**
 * 선호 통화 저장 (기존 loadPreferredCurrency.ts와 통합)
 *
 * @param action 액션 정의 (params에 key, value 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function saveToStorageHandler(
  action?: any,
  _context?: any
): void {
  const { key, value } = action?.params || {};
  if (!key) {
    logger.warn('saveToStorage: key is required');
    return;
  }
  try {
    localStorage.setItem(key, value);
    logger.log('Saved to storage:', key, value);
  } catch {
    // localStorage 저장 실패 시 무시
  }
}

/**
 * 스토리지에서 값 로드
 *
 * @param action 액션 정의 (params에 key, defaultValue 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function loadFromStorageHandler(
  action?: any,
  _context?: any
): string | null {
  const { key, defaultValue } = action?.params || {};
  if (!key) {
    logger.warn('loadFromStorage: key is required');
    return defaultValue || null;
  }
  try {
    return localStorage.getItem(key) || defaultValue || null;
  } catch {
    return defaultValue || null;
  }
}

/**
 * sessionStorage 의 비회원 주문 토큰을 모두 삭제합니다 (내부 헬퍼).
 *
 * 토큰/주문번호/만료시각 3개 키를 함께 비웁니다.
 */
function clearGuestOrderTokenInternal(): void {
  try {
    sessionStorage.removeItem(GUEST_ORDER_TOKEN_STORAGE_NAME);
    sessionStorage.removeItem(GUEST_ORDER_NUMBER_STORAGE_NAME);
    sessionStorage.removeItem(GUEST_ORDER_EXPIRES_AT_STORAGE_NAME);
  } catch {
    // sessionStorage 접근 불가 시 무시
  }
}

/**
 * 비회원 주문 조회 토큰 초기화 (init_actions 전용)
 *
 * sessionStorage 의 g7_guest_order_token 을 읽어 `_global.guestOrderToken` 에 설정한다.
 * 표준 loadFromSessionStorage 핸들러는 `context.setState`(React 컴포넌트 state)를 호출해
 * `_local` 에만 영향을 주므로, `_global` 에 토큰을 설정하려면 `G7Core.state.set` 직접 호출이 필요하다.
 * `initCartKey` 와 동일한 패턴.
 *
 * 동작:
 * 1. sessionStorage 에서 토큰/만료시각 로드 (탭 종료 시 자동 소실 — 공유 PC 안전)
 * 2. 만료된 토큰은 자동 폐기 (3개 키 모두 제거)
 * 3. `_global.guestOrderToken` 에 토큰 또는 null 설정
 * 4. `_user_base.globalHeaders` 의 `X-Guest-Order-Token: {{_global.guestOrderToken}}` 패턴이
 *    토큰 있을 때만 `guest/orders/*` 호출에 헤더 자동 주입 (엔진이 null/빈값 자동 제외)
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function initGuestOrderTokenHandler(
  _action?: any,
  _context?: any
): void {
  let token: string | null = null;
  let expiresAt: string | null = null;
  try {
    token = sessionStorage.getItem(GUEST_ORDER_TOKEN_STORAGE_NAME);
    expiresAt = sessionStorage.getItem(GUEST_ORDER_EXPIRES_AT_STORAGE_NAME);
  } catch {
    // sessionStorage 접근 불가 시 무시
  }

  // 만료된 토큰 자동 폐기 (백엔드도 동일 시각 기준으로 거부하지만 로컬에서 미리 정리)
  // expiresAt 이 invalid 포맷이면 getTime() 이 NaN → NaN <= now 비교가 false 라
  // 만료 인식이 안 되는 회귀를 방어한다. invalid 토큰도 명시적으로 폐기.
  let expired = false;
  if (token) {
    const expiryTs = expiresAt ? new Date(expiresAt).getTime() : NaN;
    if (isNaN(expiryTs) || expiryTs <= Date.now()) {
      clearGuestOrderTokenInternal();
      token = null;
      expired = true;
    }
  }

  if (token) {
    // sessionStorage 에 유효 토큰이 있으면 그 값으로 _global 동기화 (새로고침/재방문 경로)
    setGlobalState({ guestOrderToken: token });
    logger.log('Guest order token loaded from sessionStorage');
    return;
  }

  // sessionStorage 에 토큰이 없을 때, 직전 단계(verify onSuccess saveGuestOrderToken)가
  // 이미 _global.guestOrderToken 에 동기 set 해 둔 in-memory 토큰을 보존한다.
  // 조회 폼 verify→상세 SPA 전이에서 sessionStorage 쓰기가 제때 반영되지 않거나
  // (브라우저/프라이버시 모드) 비활성인 환경에서도, navigate 로 살아남은 _global 토큰으로
  // 상세 데이터소스가 X-Guest-Order-Token 을 주입할 수 있게 한다.
  // 단, 만료로 명시 폐기된 경우(expired)는 in-memory 토큰도 비운다.
  if (!expired) {
    const G7Core = (window as any).G7Core;
    const existing = G7Core?.state?.get?.('_global')?.guestOrderToken;
    if (typeof existing === 'string' && existing !== '') {
      logger.log('Guest order token preserved from in-memory _global (sessionStorage empty)');
      return;
    }
  }

  setGlobalState({ guestOrderToken: null });
  logger.log('No guest order token (visitor not authenticated)');
}

// 표시 통화(preferredCurrency) 초기화 핸들러는 이커머스 모듈로 이전되었다
// (modules/_bundled/sirsoft-ecommerce/resources/js/handlers/initPreferredCurrency.ts).
// "통화 = 커머스 책임" 원칙 — 레이아웃은 `sirsoft-ecommerce.initPreferredCurrency` 로 호출한다.

/**
 * 비회원 주문 토큰을 sessionStorage 에 저장하고 _global 에 동기 set 합니다.
 *
 * 토큰/주문번호/만료시각 3개 키를 함께 저장하며, _global.guestOrderToken 도 즉시 갱신해
 * 직후 호출되는 navigate 의 globalHeaders 자동 주입이 동작하도록 한다.
 *
 * @param action params.token, params.orderNumber, params.expiresAt 필수
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function saveGuestOrderTokenHandler(
  action?: any,
  _context?: any
): void {
  const { token, orderNumber, expiresAt } = action?.params || {};
  if (!token || !orderNumber || !expiresAt) {
    logger.warn('saveGuestOrderToken: token, orderNumber, expiresAt are required');
    return;
  }
  try {
    sessionStorage.setItem(GUEST_ORDER_TOKEN_STORAGE_NAME, token);
    sessionStorage.setItem(GUEST_ORDER_NUMBER_STORAGE_NAME, orderNumber);
    sessionStorage.setItem(GUEST_ORDER_EXPIRES_AT_STORAGE_NAME, expiresAt);
  } catch {
    // sessionStorage 접근 불가 시 무시 (private 모드 등) — _global 만 set 되어 현재 페이지는 동작
  }
  setGlobalState({ guestOrderToken: token });
}

/**
 * 비회원 주문 토큰을 sessionStorage 에서 삭제하고 _global 도 비웁니다.
 *
 * 비회원 주문 취소/구매확정/명시적 로그아웃 등 토큰 무효화 시점에서 호출.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function clearGuestOrderTokenHandler(
  _action?: any,
  _context?: any
): void {
  clearGuestOrderTokenInternal();
  setGlobalState({ guestOrderToken: null });
}

/**
 * 비회원 주문 조회 폼(guest_order_form) 진입 시 sessionStorage 의 비회원 주문 토큰을 초기화한다.
 *
 * 비회원 주문 조회 폼은 매번 새로 본인 확인을 거치는 게 표준 패턴(eBay/Best Buy/카페24/
 * 11번가/G마켓 등). 결제 직후 자동 verify 로 받은 토큰은 완료/상세 화면에서는 유효하지만,
 * 사용자가 헤더 '비회원 주문 조회' 메뉴 등을 통해 조회 폼에 도달했을 때는 의도가
 * "다시 조회" 이므로 토큰을 비워 폼이 정상 노출되도록 한다.
 *
 * 만료된 토큰도 같은 경로로 자연스럽게 정리된다.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export function clearGuestTokenOnEntryHandler(
  _action?: any,
  _context?: any
): void {
  clearGuestOrderTokenInternal();
  setGlobalState({ guestOrderToken: null });
}
