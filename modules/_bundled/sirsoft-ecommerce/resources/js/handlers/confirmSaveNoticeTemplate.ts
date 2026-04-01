/**
 * 고시정보 템플릿 저장 확인 핸들러
 *
 * 현재 상품의 고시정보(product_notice)를 템플릿으로 저장합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:SaveNotice')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:SaveNotice]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:SaveNotice]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:SaveNotice]', ...args),
};

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 */
interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 템플릿 저장 데이터 인터페이스
 */
interface SaveTemplateData {
    name?: string;
    category_id?: number | string | null;
    is_default?: boolean;
}

/**
 * 고시정보 템플릿 저장 확인 핸들러
 *
 * 1. 템플릿 데이터 검증
 * 2. API 호출로 템플릿 저장
 * 3. 성공 시 모달 닫기 및 토스트 표시
 * 4. 실패 시 에러 토스트 표시
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트
 */
export async function confirmSaveNoticeTemplateHandler(
    action: ActionWithParams,
    context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state || !G7Core?.api) {
        logger.warn('[confirmSaveNoticeTemplate] G7Core API is not available');
        return;
    }

    const localState = G7Core.state.getLocal() || {};
    const saveTemplateData: SaveTemplateData = localState.ui?.saveTemplateData || {};
    const productNotice = localState.form?.product_notice || {};

    // 템플릿명 검증
    if (!saveTemplateData.name?.trim()) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.template_name_required')
            ?? 'Please enter template name.'
        );
        return;
    }

    // 고시정보 데이터 검증
    if (Object.keys(productNotice).length === 0) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.info_empty')
            ?? 'No notice information to save.'
        );
        return;
    }

    // 저장 중 상태 설정
    G7Core.state.setLocal({
        ui: {
            ...localState.ui,
            isSavingTemplate: true,
        },
    });

    try {
        // API 호출로 템플릿 저장
        const response = await G7Core.api.post(
            '/api/modules/sirsoft-ecommerce/admin/product-notice-templates',
            {
                name: saveTemplateData.name.trim(),
                category_id: saveTemplateData.category_id || null,
                is_default: saveTemplateData.is_default || false,
                fields: productNotice,
            }
        );

        if (response?.success || response?.data) {
            // 성공: 모달 닫기 및 상태 초기화
            G7Core.state.setLocal({
                ui: {
                    ...localState.ui,
                    showSaveTemplateModal: false,
                    isSavingTemplate: false,
                    saveTemplateData: {
                        name: '',
                        category_id: null,
                        is_default: false,
                    },
                },
            });

            G7Core.toast?.success?.(
                G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.template_saved')
                ?? 'Template has been saved.'
            );

            // 템플릿 목록 데이터소스 갱신 (있는 경우)
            if (G7Core.dataSource?.refetch) {
                G7Core.dataSource.refetch('notice_templates');
            }

            logger.log('[confirmSaveNoticeTemplate] Template saved successfully');
        } else {
            throw new Error(
                response?.message
                || G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.template_save_error')
                || 'Failed to save template.'
            );
        }
    } catch (error: any) {
        logger.error('[confirmSaveNoticeTemplate] Error:', error);

        // 저장 중 상태 해제
        G7Core.state.setLocal({
            ui: {
                ...localState.ui,
                isSavingTemplate: false,
            },
        });

        // 에러 토스트 표시
        const errorMessage = error?.response?.data?.message
            || error?.message
            || G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.template_save_error')
            || 'Failed to save template.';
        G7Core.toast?.error?.(errorMessage);
    }
}
