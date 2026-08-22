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
 * 데이터 소스 값에서 행 배열을 꺼냅니다.
 *
 * 목록 데이터소스는 응답 envelope 를 그대로 담는다 —
 * `{ success, message, data: { data: [...], abilities } }`. 예전에는 `dataSource.data` 가
 * 배열이라고 가정했는데 그 형태는 실제로 존재하지 않아, 이 핸들러가 화면에서 한 번도
 * 동작하지 않았다(대기만 하다 조용히 종료). 중첩 깊이가 다른 형태도 함께 받아들인다.
 *
 * @param dataSource 데이터 소스 값
 * @returns 행 배열 (찾지 못하면 null)
 */
function extractRows(dataSource: any): any[] | null {
  const candidates = [dataSource, dataSource?.data, dataSource?.data?.data];

  for (const candidate of candidates) {
    if (Array.isArray(candidate) && candidate.length > 0) {
      return candidate;
    }
  }

  return null;
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
    const rows = extractRows(getDataSource(dataSourceId));
    if (rows) {
      return rows;
    }
    await new Promise((resolve) => setTimeout(resolve, interval));
  }
  return null;
}

/**
 * 메뉴 단건을 조회해 목록 응답에 없는 필드(역할 등)까지 채운 메뉴를 반환합니다.
 *
 * 목록 응답은 하위 메뉴의 역할을 싣지 않는다(#76 목록 하위 컬렉션 프루닝). 목록 행을
 * 그대로 편집 폼 초기값으로 쓰면 하위 메뉴 저장 시 역할 제한이 전부 해제되므로,
 * 선택 시점에 단건을 조회해 보강한다. 조회에 실패하면 목록 행을 그대로 돌려준다.
 *
 * @param menu 목록에서 찾은 메뉴
 * @returns 단건 조회로 보강된 메뉴 (실패 시 입력 그대로)
 */
async function hydrateMenuDetail(menu: MenuItem): Promise<MenuItem> {
  const g7Core = (window as any).G7Core;

  try {
    const response = await g7Core?.api?.get?.(`/api/admin/menus/${menu.id}`);
    const detail = response?.data;

    if (detail && typeof detail === 'object' && detail.id === menu.id) {
      return detail as MenuItem;
    }
  } catch (error) {
    logger.warn('[initMenuFromUrl] Failed to hydrate menu detail:', error);
  }

  return menu;
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

  // 5. 단건 조회로 보강 (목록 응답에 없는 역할 등)
  const hydratedMenu = await hydrateMenuDetail(foundMenu);

  // 6. 상태 업데이트
  const panelMode = mode === 'edit' ? 'edit' : 'view';

  // 전역 상태 업데이트 (G7Core.state.set 사용)
  g7Core.state.set({
    selectedMenuId: hydratedMenu.id,
    selectedMenu: hydratedMenu,
    panelMode: panelMode,
  });

  // edit 모드인 경우 formData도 설정
  if (panelMode === 'edit') {
    g7Core.state.set({
      formData: {
        name: hydratedMenu.name,
        slug: hydratedMenu.slug,
        url: hydratedMenu.url,
        icon: hydratedMenu.icon,
        parent_id: hydratedMenu.parent_id,
        extension_type: hydratedMenu.extension_type,
        extension_identifier: hydratedMenu.extension_identifier,
        is_active: hydratedMenu.is_active,
        // 역할을 빠뜨리면 저장 시 폼이 빈 배열을 실어 보내 역할 제한이 전부 해제된다.
        roles: (hydratedMenu.roles ?? []).map((role: { id: number }) => role.id),
      },
    });
  }

  logger.log('[initMenuFromUrl] Menu initialized from URL:', {
    slug: menuSlug,
    mode: panelMode,
    menuId: foundMenu.id,
  });
}
