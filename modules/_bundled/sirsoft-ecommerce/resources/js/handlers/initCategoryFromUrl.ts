/**
 * initCategoryFromUrl 핸들러
 *
 * URL 쿼리 파라미터(id, mode)를 읽어서 카테고리 상태를 초기화합니다.
 * 카테고리 관리 페이지에서 URL로 직접 접근할 때 해당 카테고리를 선택 상태로 표시합니다.
 */

const logger = ((window as any).G7Core?.createLogger?.('Handler:InitCategory')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:InitCategory]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:InitCategory]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:InitCategory]', ...args),
};

interface CategoryItem {
    id: number;
    children?: CategoryItem[];
    [key: string]: any;
}

/**
 * 계층형 카테고리 목록에서 ID로 카테고리를 찾습니다.
 */
function findCategoryById(items: CategoryItem[], id: number): CategoryItem | null {
    for (const item of items) {
        if (item.id === id) {
            return item;
        }
        if (item.children && item.children.length > 0) {
            const found = findCategoryById(item.children, id);
            if (found) {
                return found;
            }
        }
    }
    return null;
}

function getQueryParam(paramName: string): string | null {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(paramName);
}

function getDataSource(dataSourceId: string): any {
    const g7Core = (window as any).G7Core;
    return g7Core?.state?.getDataSource?.(dataSourceId);
}

async function waitForDataSource(
    dataSourceId: string,
    dataPath: (ds: any) => any[] | undefined,
    maxAttempts: number = 30,
    interval: number = 100
): Promise<any[] | null> {
    for (let i = 0; i < maxAttempts; i++) {
        const dataSource = getDataSource(dataSourceId);
        const data = dataPath(dataSource);
        if (Array.isArray(data) && data.length > 0) {
            return data;
        }
        await new Promise((resolve) => setTimeout(resolve, interval));
    }
    return null;
}

export async function initCategoryFromUrlHandler(
    _action: any,
    _context?: any
): Promise<void> {
    const g7Core = (window as any).G7Core;

    const idParam = getQueryParam('id');
    const mode = getQueryParam('mode');

    if (!idParam) {
        logger.log('[initCategoryFromUrl] No id parameter in URL');
        return;
    }

    if (!g7Core?.state?.set) {
        logger.warn('[initCategoryFromUrl] G7Core.state.set not available');
        return;
    }

    const data = await waitForDataSource('categories', (ds) => ds?.data?.data);

    if (!data) {
        logger.warn('[initCategoryFromUrl] categories data not available after waiting');
        return;
    }

    const categoryId = parseInt(idParam, 10);
    const found = findCategoryById(data, categoryId);

    if (!found) {
        logger.warn('[initCategoryFromUrl] Category not found with id:', categoryId);
        return;
    }

    const panelMode = mode === 'edit' ? 'edit' : 'view';

    g7Core.state.set({
        selectedCategoryId: found.id,
        selectedCategory: found,
        panelMode: panelMode,
    });

    logger.log('[initCategoryFromUrl] Category initialized from URL:', {
        id: categoryId,
        mode: panelMode,
    });
}
