/**
 * 사이드바 접기 핸들러
 *
 * 데스크톱(PC)에서 좌측 사이드바의 접힘/펼침 상태를 토글합니다.
 * localStorage에 g7_admin_sidebar_collapsed 값을 저장하여 새로고침 후에도 상태를 유지합니다.
 * 모바일 슬라이드 사이드바(_global.sidebarOpen)와는 독립된 상태(_global.sidebarCollapsed)를 사용합니다.
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:Sidebar')) ?? {
  log: (...args: unknown[]) => console.log('[Handler:Sidebar]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:Sidebar]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:Sidebar]', ...args),
};

/**
 * localStorage 키
 */
const STORAGE_KEY = 'g7_admin_sidebar_collapsed';

/**
 * localStorage 에서 접힘 상태를 읽어옵니다. (true = 접힘)
 */
function readCollapsed(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1';
  } catch {
    return false;
  }
}

/**
 * 접힘 상태를 localStorage 에 저장하고 _global.sidebarCollapsed 에 반영합니다.
 *
 * @param collapsed 접힘 여부 (true = 접힘)
 */
function persistCollapsed(collapsed: boolean): void {
  try {
    localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
  } catch (error) {
    logger.error('Failed to save sidebar state to localStorage:', error);
  }
  try {
    (window as any).G7Core?.state?.setGlobal?.({ sidebarCollapsed: collapsed });
  } catch (e) {
    logger.warn('Failed to set global sidebar state:', e);
  }
}

/**
 * 저장된 접힘 상태를 로드하여 _global 에 반영합니다.
 *
 * 템플릿 초기화 시 호출되어 새로고침 후에도 직전 접힘 상태를 복원합니다.
 */
export function initSidebar(): void {
  const collapsed = readCollapsed();
  try {
    (window as any).G7Core?.state?.setGlobal?.({ sidebarCollapsed: collapsed });
  } catch (e) {
    logger.warn('Failed to restore sidebar state:', e);
  }
  logger.log('Initial sidebar collapsed state restored:', collapsed);
}

/**
 * initSidebar 핸들러
 *
 * 레이아웃의 init_actions 에서 호출되어 저장된 접힘 상태를 복원합니다.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export async function initSidebarHandler(
  _action: any,
  _context?: any
): Promise<void> {
  initSidebar();
}

/**
 * toggleSidebar 핸들러
 *
 * 데스크톱 사이드바의 접힘 상태를 반전시키고 localStorage 에 저장합니다.
 * ActionDispatcher 의 커스텀 핸들러 형식을 따릅니다.
 *
 * @param _action 액션 정의 (사용하지 않음)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export async function toggleSidebarHandler(
  _action: any,
  _context?: any
): Promise<void> {
  const current = (() => {
    try {
      return Boolean((window as any).G7Core?.state?.getGlobal?.()?.sidebarCollapsed);
    } catch {
      return readCollapsed();
    }
  })();
  const next = !current;
  persistCollapsed(next);
  logger.log('Sidebar collapsed toggled to:', next);
}
