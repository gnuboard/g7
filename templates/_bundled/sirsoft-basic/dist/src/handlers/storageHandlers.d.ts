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
