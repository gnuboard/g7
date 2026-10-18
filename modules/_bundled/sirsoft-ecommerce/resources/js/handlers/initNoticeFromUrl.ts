/**
 * initNoticeFromUrl 핸들러
 *
 * URL 쿼리 파라미터(id, mode)를 읽어서 상품정보제공고시 상태를 초기화합니다.
 * 상품정보제공고시 관리 페이지에서 URL로 직접 접근할 때 해당 템플릿을 선택 상태로 표시합니다.
 */

const logger = ((window as any).G7Core?.createLogger?.('Handler:InitNotice')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:InitNotice]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:InitNotice]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:InitNotice]', ...args),
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

export async function initNoticeFromUrlHandler(
    _action: any,
    _context?: any
): Promise<void> {
    const g7Core = (window as any).G7Core;

    const idParam = getQueryParam('id');
    const mode = getQueryParam('mode');

    if (!idParam) {
        logger.log('[initNoticeFromUrl] No id parameter in URL');
        return;
    }

    if (!g7Core?.state?.set) {
        logger.warn('[initNoticeFromUrl] G7Core.state.set not available');
        return;
    }

    const data = await waitForDataSource('templates', (ds) => ds?.data);

    if (!data) {
        logger.warn('[initNoticeFromUrl] templates data not available after waiting');
        return;
    }

    const templateId = parseInt(idParam, 10);
    const found = data.find((item: any) => item.id === templateId);

    if (!found) {
        logger.warn('[initNoticeFromUrl] Template not found with id:', templateId);
        return;
    }

    const panelMode = mode === 'edit' ? 'edit' : 'view';

    g7Core.state.set({
        selectedTemplateId: found.id,
        selectedTemplate: found,
        panelMode: panelMode,
    });

    logger.log('[initNoticeFromUrl] Template initialized from URL:', {
        id: templateId,
        mode: panelMode,
    });
}
