/**
 * sirsoft-message_bizppurio 플러그인 프론트 타입 정의.
 */

/**
 * 액션 컨텍스트 인터페이스.
 *
 * ActionDispatcher 가 커스텀 핸들러 실행 시 전달하는 컨텍스트다.
 */
export interface ActionContext {
    /** 현재 로컬 상태 가져오기 */
    getLocalState?: () => Record<string, any>;
    /** 로컬 상태 업데이트 */
    setLocalState?: (updates: Record<string, any>) => void;
    /** 이벤트 객체 */
    event?: Event;
    /** 데이터 컨텍스트 */
    dataContext?: Record<string, any>;
}

/**
 * 커스텀 핸들러 액션 객체(공통).
 */
export interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}
