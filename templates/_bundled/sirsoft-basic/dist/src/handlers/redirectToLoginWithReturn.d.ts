/**
 * 로그인 페이지로 이동하되 현재 경로를 redirect 로 보존하는 핸들러
 *
 * ActionDispatcher 는 handler(action, context) 형태로 호출하지만(ActionDispatcher.ts),
 * 이 핸들러는 action/context 를 사용하지 않는다 — 현재 경로는 window.location 에서 직접 읽는다.
 *
 * @param _action 액션 정의 (미사용)
 * @param _context 액션 컨텍스트 (미사용)
 * @return Promise<void>
 */
export declare function redirectToLoginWithReturnHandler(_action?: any, _context?: any): Promise<void>;
