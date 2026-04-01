/**
 * initMenuFromUrl 핸들러
 *
 * URL 쿼리 파라미터(menu, mode)를 읽어서 메뉴 상태를 초기화합니다.
 * 메뉴 관리 페이지에서 URL로 직접 접근할 때 해당 메뉴를 선택 상태로 표시합니다.
 */
/**
 * initMenuFromUrl 핸들러
 *
 * URL의 menu slug와 mode 파라미터를 읽어서 메뉴 상태를 초기화합니다.
 *
 * @param _action 액션 정의
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export declare function initMenuFromUrlHandler(_action: any, _context?: any): Promise<void>;
