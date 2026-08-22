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
export declare function initCartKeyHandler(_action?: any, _context?: any): Promise<void>;
/**
 * 장바구니 키 가져오기
 *
 * 현재 저장된 장바구니 키를 반환합니다.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function getCartKeyHandler(_action?: any, _context?: any): string | null;
/**
 * 장바구니 키 삭제
 *
 * 로그인 시 게스트 장바구니 키를 삭제합니다 (서버에서 병합 후).
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function clearCartKeyHandler(_action?: any, _context?: any): void;
/**
 * 장바구니 키 새로 생성
 *
 * 기존 키를 삭제하고 백엔드 API를 통해 새로운 키를 발급합니다.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function regenerateCartKeyHandler(_action?: any, _context?: any): Promise<void>;
/**
 * 선호 통화 저장 (기존 loadPreferredCurrency.ts와 통합)
 *
 * @param action 액션 정의 (params에 key, value 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function saveToStorageHandler(action?: any, _context?: any): void;
/**
 * 스토리지에서 값 로드
 *
 * @param action 액션 정의 (params에 key, defaultValue 포함)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function loadFromStorageHandler(action?: any, _context?: any): string | null;
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
export declare function initGuestOrderTokenHandler(_action?: any, _context?: any): void;
/**
 * 비회원 주문 토큰을 sessionStorage 에 저장하고 _global 에 동기 set 합니다.
 *
 * 토큰/주문번호/만료시각 3개 키를 함께 저장하며, _global.guestOrderToken 도 즉시 갱신해
 * 직후 호출되는 navigate 의 globalHeaders 자동 주입이 동작하도록 한다.
 *
 * @param action params.token, params.orderNumber, params.expiresAt 필수
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function saveGuestOrderTokenHandler(action?: any, _context?: any): void;
/**
 * 비회원 주문 토큰을 sessionStorage 에서 삭제하고 _global 도 비웁니다.
 *
 * 비회원 주문 취소/구매확정/명시적 로그아웃 등 토큰 무효화 시점에서 호출.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function clearGuestOrderTokenHandler(_action?: any, _context?: any): void;
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
export declare function clearGuestTokenOnEntryHandler(_action?: any, _context?: any): void;
