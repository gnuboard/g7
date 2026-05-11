/**
 * 상세설명 다국어 관련 핸들러
 *
 * 상품 등록/수정 화면에서 상세설명의 언어 탭 추가/제거 기능을 처리합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:DescriptionLocale')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:DescriptionLocale]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:DescriptionLocale]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:DescriptionLocale]', ...args),
};

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 설정에서 지원되는 로케일 목록을 가져옵니다.
 *
 * @returns 지원되는 로케일 배열
 */
function getSupportedLocales(): string[] {
    // G7Core 또는 window.config에서 가져오기
    const G7Core = (window as any).G7Core;
    return G7Core?.config?.('app.supported_locales') ?? ['ko', 'en'];
}

/**
 * 상세설명 언어 탭을 제거합니다.
 *
 * - 기본 언어(ko)는 삭제 불가
 * - PC 및 모바일 상세설명 모두에서 제거
 *
 * @param action 액션 객체 (params.locale 필요)
 * @param _context 액션 컨텍스트
 */
export function removeDescriptionLocaleHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const locale = params.locale as string;

    if (!locale) {
        logger.warn('[removeDescriptionLocale] Missing locale param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[removeDescriptionLocale] G7Core.state API is not available');
        return;
    }

    // 기본 언어 삭제 불가 — 사이트 설정의 기본 언어 또는 fallback 'ko' 사용
    const defaultLocale: string = G7Core?.state?.get?.('_global.settings.general.language')
        ?? G7Core?.config?.('app.fallback_locale')
        ?? 'ko';
    if (locale === defaultLocale) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.description_editor.messages.default_locale_cannot_delete')
            ?? 'Default language cannot be deleted.'
        );
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const supportedLocales = getSupportedLocales();

    // 해당 언어의 상세설명 삭제
    const newDescription = { ...state.form?.description };
    delete newDescription[locale];

    const newDescriptionMobile = { ...state.form?.description_mobile };
    delete newDescriptionMobile[locale];

    // 활성 로케일 목록에서 제거
    const activeLocales = (state.ui?.descriptionActiveLocales ?? supportedLocales).filter(
        (l: string) => l !== locale
    );

    G7Core.state.setLocal({
        form: {
            ...state.form,
            description: newDescription,
            description_mobile: newDescriptionMobile,
        },
        ui: {
            ...state.ui,
            descriptionActiveLocales: activeLocales,
            descriptionLocale: activeLocales[0] ?? 'ko',
        },
        hasChanges: true,
    });

    G7Core.toast?.success?.(
        G7Core.t?.('sirsoft-ecommerce.admin.product.description_editor.messages.locale_removed', { locale: locale.toUpperCase() })
        ?? `${locale.toUpperCase()} language has been removed.`
    );
    logger.log(`[removeDescriptionLocale] Removed locale: ${locale}`);
}

/**
 * 상세설명 언어 추가 모달을 표시합니다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function showAddLocaleModalHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[showAddLocaleModal] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            showAddLocaleModal: true,
        },
    });
}

/**
 * 상세설명에 새 언어를 추가합니다.
 *
 * - 이미 추가된 언어는 추가 불가
 * - 지원되지 않는 언어는 추가 불가
 *
 * @param action 액션 객체 (params.locale 필요)
 * @param _context 액션 컨텍스트
 */
export function addDescriptionLocaleHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const locale = params.locale as string;

    if (!locale) {
        logger.warn('[addDescriptionLocale] Missing locale param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[addDescriptionLocale] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const supportedLocales = getSupportedLocales();
    const activeLocales: string[] = state.ui?.descriptionActiveLocales ?? ['ko'];

    // 이미 추가된 언어인지 확인
    if (activeLocales.includes(locale)) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.description_editor.messages.locale_already_added')
            ?? 'This language is already added.'
        );
        return;
    }

    // 지원되는 언어인지 확인
    if (!supportedLocales.includes(locale)) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.description_editor.messages.locale_unsupported')
            ?? 'Unsupported language.'
        );
        return;
    }

    G7Core.state.setLocal({
        form: {
            ...state.form,
            description: {
                ...state.form?.description,
                [locale]: '',
            },
            description_mobile: {
                ...state.form?.description_mobile,
                [locale]: '',
            },
        },
        ui: {
            ...state.ui,
            descriptionActiveLocales: [...activeLocales, locale],
            descriptionLocale: locale,
            showAddLocaleModal: false,
        },
        hasChanges: true,
    });

    G7Core.toast?.success?.(
        G7Core.t?.('sirsoft-ecommerce.admin.product.description_editor.messages.locale_added', { locale: locale.toUpperCase() })
        ?? `${locale.toUpperCase()} language has been added.`
    );
    logger.log(`[addDescriptionLocale] Added locale: ${locale}`);
}
