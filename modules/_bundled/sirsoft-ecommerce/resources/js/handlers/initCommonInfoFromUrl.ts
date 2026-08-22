/**
 * initCommonInfoFromUrl 핸들러
 *
 * URL 쿼리 파라미터(id, mode)를 읽어서 공통정보 상태를 초기화합니다.
 * 공통정보 관리 페이지에서 URL로 직접 접근할 때 해당 항목을 선택 상태로 표시합니다.
 */

const logger = ((window as any).G7Core?.createLogger?.('Handler:InitCommonInfo')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:InitCommonInfo]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:InitCommonInfo]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:InitCommonInfo]', ...args),
};

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

export async function initCommonInfoFromUrlHandler(
    _action: any,
    _context?: any
): Promise<void> {
    const g7Core = (window as any).G7Core;

    const idParam = getQueryParam('id');
    const mode = getQueryParam('mode');

    if (!idParam) {
        logger.log('[initCommonInfoFromUrl] No id parameter in URL');
        return;
    }

    if (!g7Core?.state?.set) {
        logger.warn('[initCommonInfoFromUrl] G7Core.state.set not available');
        return;
    }

    const data = await waitForDataSource('commonInfos', (ds) => ds?.data);

    if (!data) {
        logger.warn('[initCommonInfoFromUrl] commonInfos data not available after waiting');
        return;
    }

    const itemId = parseInt(idParam, 10);
    const found = data.find((item: any) => item.id === itemId);

    if (!found) {
        logger.warn('[initCommonInfoFromUrl] Common info not found with id:', itemId);
        return;
    }

    const panelMode = mode === 'edit' ? 'edit' : 'view';

    g7Core.state.set({
        selectedId: found.id,
        selectedItem: found,
        panelMode: panelMode,
    });

    logger.log('[initCommonInfoFromUrl] Common info initialized from URL:', {
        id: itemId,
        mode: panelMode,
    });
}
