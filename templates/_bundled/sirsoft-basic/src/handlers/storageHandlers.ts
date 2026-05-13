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
