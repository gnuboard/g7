/**
 * 상품 복사 시 새 상품코드 자동 생성 핸들러
 *
 * API를 호출하여 새로운 고유 상품코드를 생성합니다.
 * 상품 등록 폼의 generateProductCode와 동일한 API를 사용합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:CopyCode')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:CopyCode]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:CopyCode]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:CopyCode]', ...args),
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
 * 새 상품코드 생성 핸들러 (API 호출 방식)
 *
 * API를 호출하여 새로운 상품코드를 생성하고 상태에 설정합니다.
 * 모달 컨텍스트에서는 부모 상태에 저장하고, 폼 컨텍스트에서는 로컬 상태에 저장합니다.
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트
 */
export async function generateCopyProductCodeHandler(
    action: ActionWithParams,
    context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state || !G7Core?.api) {
        logger.warn('[generateCopyProductCode] G7Core API is not available');
        return;
    }

    try {
        // API 호출로 상품코드 생성 (상품 등록 폼과 동일한 API)
        const response = await G7Core.api.post(
            '/api/modules/sirsoft-ecommerce/admin/products/generate-code'
        );

        if (!response?.data?.product_code) {
            throw new Error('Invalid API response');
        }

        const newProductCode = response.data.product_code;

        // 모달 컨텍스트 확인: __g7LayoutContextStack에 부모 컨텍스트가 있는지 확인
        const contextStack = (window as any).__g7LayoutContextStack || [];
        const isInModalContext = contextStack.length > 0;

        if (isInModalContext && G7Core.state.setParentLocal) {
            // 모달에서 호출된 경우 - 부모의 _local에 저장
            G7Core.state.setParentLocal({
                'ui.copyOptions.newProductCode': newProductCode,
            });
            logger.log(`[generateCopyProductCode] Generated (parent): ${newProductCode}`);
        } else {
            // 폼에서 호출된 경우 - 현재 _local에 저장
            const localState = G7Core.state.getLocal() || {};
            G7Core.state.setLocal({
                ui: {
                    ...localState.ui,
                    copyOptions: {
                        ...(localState.ui?.copyOptions || {}),
                        newProductCode: newProductCode,
                    },
                },
            });
            logger.log(`[generateCopyProductCode] Generated (local): ${newProductCode}`);
        }
    } catch (error: any) {
        logger.error('[generateCopyProductCode] Failed:', error);

        // 에러 토스트 표시
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.messages.code_generate_error')
            ?? 'Failed to generate product code.'
        );
    }
}
