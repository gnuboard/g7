/**
 * sirsoft-daum_postcode 플러그인 타입 정의
 */

/**
 * 액션 컨텍스트 인터페이스
 *
 * ActionDispatcher에서 핸들러 실행 시 전달되는 컨텍스트입니다.
 */
export interface ActionContext {
    /** 현재 로컬 상태 가져오기 */
    getLocalState?: () => Record<string, any>;
    /** 로컬 상태 업데이트 */
    setLocalState?: (updates: Record<string, any>) => void;
    /** 전역 상태 가져오기 */
    getGlobalState?: () => Record<string, any>;
    /** 전역 상태 업데이트 */
    setGlobalState?: (updates: Record<string, any>) => void;
    /** 라우트 파라미터 */
    route?: Record<string, string>;
    /** 쿼리 파라미터 */
    query?: Record<string, string>;
    /** 네비게이션 함수 */
    navigate?: (path: string) => void;
    /** 이벤트 객체 */
    event?: Event;
    /** 데이터 컨텍스트 */
    dataContext?: Record<string, any>;
}
