/**
 * 사이드바 접기 핸들러
 *
 * 데스크톱(PC)에서 좌측 사이드바의 접힘/펼침 상태를 토글합니다.
 * localStorage에 g7_admin_sidebar_collapsed 값을 저장하여 새로고침 후에도 상태를 유지합니다.
 * 모바일 슬라이드 사이드바(_global.sidebarOpen)와는 독립된 상태(_global.sidebarCollapsed)를 사용합니다.
 */
/**
 * 저장된 접힘 상태를 로드하여 _global 에 반영합니다.
 *
 * 템플릿 초기화 시 호출되어 새로고침 후에도 직전 접힘 상태를 복원합니다.
 */
export declare function initSidebar(): void;
/**
 * initSidebar 핸들러
 *
 * 레이아웃의 init_actions 에서 호출되어 저장된 접힘 상태를 복원합니다.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function initSidebarHandler(_action: any, _context?: any): Promise<void>;
/**
 * toggleSidebar 핸들러
 *
 * 데스크톱 사이드바의 접힘 상태를 반전시키고 localStorage 에 저장합니다.
 * ActionDispatcher 의 커스텀 핸들러 형식을 따릅니다.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function toggleSidebarHandler(_action: any, _context?: any): Promise<void>;
