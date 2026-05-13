/**
 * 상품 목록 관련 핸들러
 *
 * 상품 목록 화면에서 사용하는 커스텀 핸들러들을 정의합니다.
 * - 행 액션 처리 (수정, 복사, 삭제)
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:ProductList')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ProductList]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ProductList]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ProductList]', ...args),
};

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 */
interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 행 액션 파라미터
 */
interface HandleProductRowActionParams {
    actionId: 'edit' | 'copy' | 'delete';
    row: {
        id: number;
        product_code: string;
        name?: string;
        [key: string]: any;
    };
}

/**
 * 행 액션 결과
 */
interface HandleProductRowActionResult {
    success: boolean;
    action?: string;
    targetId?: number;
}

/**
 * 삭제 가능 여부 API 응답
 */
interface CanDeleteResponse {
    data: {
        canDelete: boolean;
        reason: string | null;
        relatedData: {
            orders: number;
            images: number;
            options: number;
            additionalOptions?: number;
            labelAssignments?: number;
            logs?: number;
        };
    };
}

/**
 * 상품 목록 DataGrid의 행 액션 처리 핸들러
 *
 * rowActions에서 호출되며, 각 액션 유형에 따라 처리합니다.
 * - edit: 상품 수정 페이지로 이동
 * - copy: 복사 모달 열기
 * - delete: 삭제 가능 여부 확인 후 삭제 모달 열기
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "handler": "sirsoft-ecommerce.handleProductRowAction",
 *   "params": {
 *     "actionId": "{{actionId}}",
 *     "row": "{{row}}"
 *   }
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트 (미사용)
 * @returns 처리 결과
 */
export async function handleProductRowActionHandler(
    action: ActionWithParams<HandleProductRowActionParams>,
    _context: ActionContext
): Promise<HandleProductRowActionResult> {
    const params = action.params || ({} as HandleProductRowActionParams);
    const { actionId, row } = params;
    const G7Core = (window as any).G7Core;

    if (!actionId || !row?.id) {
        logger.warn('[handleProductRowAction] actionId 또는 row.id가 없습니다.');
        return { success: false };
    }

    // 액션에 따른 처리
    switch (actionId) {
        case 'edit':
            // 상품 수정 페이지로 이동 (product_code 사용)
            G7Core?.navigate?.(`/admin/ecommerce/products/${row.product_code}/edit`);
            break;

        case 'copy':
            // 복사 모달 열기
            G7Core?.state?.setLocal({
                productList: { copyTargetProduct: row },
                ui: { showCopyModal: true },
            });
            break;

        case 'delete':
            try {
                // 삭제 가능 여부 API 호출
                const response = await G7Core?.api?.get<CanDeleteResponse>(
                    `/api/modules/sirsoft-ecommerce/admin/products/${row.id}/can-delete`
                );

                const data = response?.data;

                // 삭제 모달 열기 (삭제 가능 여부와 관계없이 모달 표시)
                G7Core?.state?.setLocal({
                    productList: {
                        deleteTargetProduct: row,
                        canDelete: data?.canDelete ?? true,
                        deleteBlockReason: data?.reason ?? null,
                        relatedData: data?.relatedData ?? null,
                    },
                    ui: { showDeleteModal: true },
                });
            } catch (error) {
                logger.error('[handleProductRowAction] 삭제 가능 여부 확인 실패:', error);
                // 에러 발생 시에도 모달은 열되, 삭제 불가로 표시
                G7Core?.state?.setLocal({
                    productList: {
                        deleteTargetProduct: row,
                        canDelete: false,
                        deleteBlockReason: '삭제 가능 여부를 확인할 수 없습니다.',
                        relatedData: null,
                    },
                    ui: { showDeleteModal: true },
                });
            }
            break;

        default:
            logger.warn(`[handleProductRowAction] 알 수 없는 액션: ${actionId}`);
            return { success: false };
    }

    return {
        success: true,
        action: actionId,
        targetId: row.id,
    };
}
