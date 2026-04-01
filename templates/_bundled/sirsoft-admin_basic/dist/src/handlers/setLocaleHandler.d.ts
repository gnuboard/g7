/**
 * setLocale 핸들러
 *
 * 사용자가 선택한 언어로 변경합니다.
 * localStorage에 g7_locale 값을 저장하고 페이지를 새로고침합니다.
 */
/**
 * 언어 변경 핸들러
 *
 * ActionDispatcher는 handler(action, context) 형태로 호출합니다.
 * context 객체에는 { data, event, props, state, setState, navigate } 등이 포함됩니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트 (ActionContext 타입)
 */
export declare function setLocaleHandler(action: any, context?: any): Promise<void>;
