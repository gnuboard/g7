/**
 * setTheme 핸들러
 *
 * 사용자가 선택한 테마(auto/light/dark)로 변경합니다.
 * localStorage에 g7_color_scheme 값을 저장하고 즉시 적용합니다.
 *
 * sirsoft-admin_basic 템플릿과 동일한 키를 사용하여 테마 설정을 공유합니다.
 */
/**
 * 테마 모드 타입
 */
export type ThemeMode = 'auto' | 'light' | 'dark';
/**
 * 저장된 테마를 로드하여 적용합니다.
 *
 * 템플릿 초기화 시 호출되어 localStorage에 저장된 테마를 즉시 적용합니다.
 */
export declare function initTheme(): void;
/**
 * initTheme 핸들러
 *
 * 레이아웃의 init_actions에서 호출되어 초기 테마를 적용합니다.
 * ActionDispatcher의 커스텀 핸들러 형식을 따릅니다.
 *
 * target 파라미터가 전달되면 해당 테마로 초기화합니다.
 * target이 없거나 유효하지 않으면 localStorage 또는 'auto'를 사용합니다.
 *
 * @param action 액션 정의 (target에 테마 값 포함 가능)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function initThemeHandler(action: any, _context?: any): Promise<void>;
/**
 * 테마 변경 핸들러
 *
 * ActionDispatcher는 handler(action, context) 형태로 호출합니다.
 * context 객체에는 { data, event, props, state, setState, navigate } 등이 포함됩니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트 (ActionContext 타입)
 */
export declare function setThemeHandler(action: any, _context?: any): Promise<void>;
