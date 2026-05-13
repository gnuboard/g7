/**
 * initMenuFromUrl 핸들러
 *
 * URL 쿼리 파라미터(menu, mode)를 읽어서 메뉴 상태를 초기화합니다.
 * 메뉴 관리 페이지에서 URL로 직접 접근할 때 해당 메뉴를 선택 상태로 표시합니다.
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:InitMenu')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:InitMenu]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:InitMenu]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:InitMenu]', ...args),
};

/**
 * 메뉴 아이템 인터페이스
 */
interface MenuItem {
  id: number;
  name: string | Record<string, string>;
  slug: string;
  url: string;
  icon: string;
  order: number;
  is_active: boolean;
  parent_id: number | null;
  module_id: number | null;
  children?: MenuItem[];
  [key: string]: any;
}

/**
 * 계층형 메뉴 목록에서 slug로 메뉴를 찾습니다.
 *
 * @param menus 메뉴 목록
 * @param slug 찾을 메뉴 slug
 * @returns 찾은 메뉴 또는 null
 */
function findMenuBySlug(menus: MenuItem[], slug: string): MenuItem | null {
  for (const menu of menus) {
    if (menu.slug === slug) {
      return menu;
    }
    // 자식 메뉴에서 검색
    if (menu.children && menu.children.length > 0) {
      const found = findMenuBySlug(menu.children, slug);
      if (found) {
        return found;
      }
    }
  }
  return null;
}

/**
 * URL에서 쿼리 파라미터를 가져옵니다.
 *
 * @param paramName 파라미터 이름
 * @returns 파라미터 값 또는 null
 */
function getQueryParam(paramName: string): string | null {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get(paramName);
}

/**
 * G7Core.state.getDataSource를 사용하여 데이터 소스 값을 가져옵니다.
 *
 * @param dataSourceId 데이터 소스 ID
 * @returns 데이터 소스 값 또는 undefined
 */
function getDataSource(dataSourceId: string): any {
  const g7Core = (window as any).G7Core;
  return g7Core?.state?.getDataSource?.(dataSourceId);
}

/**
 * 데이터 소스가 로드될 때까지 대기합니다.
 *
 * @param dataSourceId 데이터 소스 ID
 * @param maxAttempts 최대 시도 횟수
 * @param interval 시도 간격 (ms)
 * @returns 로드된 데이터 또는 null
 */
async function waitForDataSource(
  dataSourceId: string,
  maxAttempts: number = 30,
  interval: number = 100
): Promise<any[] | null> {
  for (let i = 0; i < maxAttempts; i++) {
    const dataSource = getDataSource(dataSourceId);
    const data = dataSource?.data;
    if (Array.isArray(data) && data.length > 0) {
      return data;
    }
    await new Promise((resolve) => setTimeout(resolve, interval));
  }
  return null;
}

/**
 * initMenuFromUrl 핸들러
 *
 * URL의 menu slug와 mode 파라미터를 읽어서 메뉴 상태를 초기화합니다.
 *
 * @param _action 액션 정의
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export async function initMenuFromUrlHandler(
  _action: any,
  _context?: any
): Promise<void> {
  const g7Core = (window as any).G7Core;

  // 1. URL에서 파라미터 읽기
  const menuSlug = getQueryParam('menu');
  const mode = getQueryParam('mode');

  // menu 파라미터가 없으면 초기화 불필요
  if (!menuSlug) {
    logger.log('[initMenuFromUrl] No menu parameter in URL');
    return;
  }

  // 2. G7Core.state.set 확인
  if (!g7Core?.state?.set) {
    logger.warn('[initMenuFromUrl] G7Core.state.set not available');
    return;
  }

  // 3. G7Core state에서 menus 데이터 소스가 로드될 때까지 대기
  const menusData = await waitForDataSource('menus');

  if (!menusData) {
    logger.warn('[initMenuFromUrl] menus data not available after waiting');
    return;
  }

  // 4. slug로 메뉴 찾기
  const foundMenu = findMenuBySlug(menusData, menuSlug);

  if (!foundMenu) {
    logger.warn('[initMenuFromUrl] Menu not found with slug:', menuSlug);
    return;
  }

  // 5. 상태 업데이트
  const panelMode = mode === 'edit' ? 'edit' : 'view';

  // 전역 상태 업데이트 (G7Core.state.set 사용)
  g7Core.state.set({
    selectedMenuId: foundMenu.id,
    selectedMenu: foundMenu,
    panelMode: panelMode,
  });

  // edit 모드인 경우 formData도 설정
  if (panelMode === 'edit') {
    g7Core.state.set({
      formData: {
        name: foundMenu.name,
        slug: foundMenu.slug,
        url: foundMenu.url,
        icon: foundMenu.icon,
        parent_id: foundMenu.parent_id,
        module_id: foundMenu.module_id,
        is_active: foundMenu.is_active,
      },
    });
  }

  logger.log('[initMenuFromUrl] Menu initialized from URL:', {
    slug: menuSlug,
    mode: panelMode,
    menuId: foundMenu.id,
  });
}
