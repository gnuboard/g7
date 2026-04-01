/**
 * 상세설명 관련 핸들러
 *
 * 상품 등록/수정 화면에서 상세설명 업데이트 기능을 처리합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:Description')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Description]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Description]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Description]', ...args),
};

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 상세설명을 업데이트합니다.
 *
 * @param action 액션 객체 (params.locale, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateDescriptionHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const locale = params.locale as string;
    const value = params.value as string;

    if (!locale) {
        logger.warn('[updateDescription] Missing locale param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateDescription] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    G7Core.state.setLocal({
        form: {
            ...state.form,
            description: {
                ...state.form?.description,
                [locale]: value,
            },
        },
        hasChanges: true,
    });
}
