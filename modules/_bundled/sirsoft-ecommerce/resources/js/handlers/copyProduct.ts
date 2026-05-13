/**
 * 상품 복사 실행 핸들러
 *
 * 복사 옵션을 쿼리 파라미터로 변환하여 상품 등록 페이지로 이동합니다.
 * 등록 페이지의 copy_source data_source가 옵션에 따라 데이터를 사전 입력합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:CopyProduct')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:CopyProduct]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:CopyProduct]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:CopyProduct]', ...args),
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
 * 복사 옵션 인터페이스
 */
interface CopyOptions {
    images?: boolean;
    options?: boolean;
    categories?: boolean;
    sales_info?: boolean;
    description?: boolean;
    notice?: boolean;
    common_info?: boolean;
    other_info?: boolean;
    shipping?: boolean;
    seo?: boolean;
    identification?: boolean;
}

/**
 * 상품 복사 실행 핸들러
 *
 * 1. 복사 옵션과 원본 상품코드 수집
 * 2. 쿼리 파라미터로 변환하여 상품 등록 페이지로 navigate
 * 3. 등록 페이지의 copy_source data_source가 사전 입력 처리
 *
 * 상품 폼(_local.form)과 상품 목록(_local.productList.copyTargetProduct) 두 컨텍스트 모두 지원합니다.
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트
 */
export async function copyProductHandler(
    action: ActionWithParams,
    context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[copyProduct] G7Core API is not available');
        return;
    }

    const localState = G7Core.state.getLocal() || {};

    // 모달 컨텍스트: 부모 상태에서 읽기 (getParent 지원 시)
    const parentContext = G7Core.state.getParent?.() || {};
    const parentLocal = parentContext._local || {};

    // copyOptions: 부모 _local 우선, 폴백으로 현재 _local
    const copyOptions: CopyOptions =
        parentLocal.ui?.copyOptions ||
        localState.ui?.copyOptions ||
        {};

    // 상품 폼 또는 상품 목록 컨텍스트에서 원본 상품 코드 가져오기
    const originalProductCode =
        localState.form?.product_code ||
        parentLocal.form?.product_code ||
        parentLocal.productList?.copyTargetProduct?.product_code ||
        localState.productList?.copyTargetProduct?.product_code;

    if (!originalProductCode) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.messages.copy_source_not_found')
            ?? 'Could not find source product information.'
        );
        return;
    }

    // 모달 닫기
    G7Core.modal?.close?.();

    // 쿼리 파라미터 구성
    const query: Record<string, string> = {
        copy_id: originalProductCode,
        copy_images: (copyOptions.images ?? true) ? '1' : '0',
        copy_options: (copyOptions.options ?? true) ? '1' : '0',
        copy_categories: (copyOptions.categories ?? true) ? '1' : '0',
        copy_sales_info: (copyOptions.sales_info ?? true) ? '1' : '0',
        copy_description: (copyOptions.description ?? true) ? '1' : '0',
        copy_notice: (copyOptions.notice ?? true) ? '1' : '0',
        copy_common_info: (copyOptions.common_info ?? true) ? '1' : '0',
        copy_other_info: (copyOptions.other_info ?? true) ? '1' : '0',
        copy_shipping: (copyOptions.shipping ?? true) ? '1' : '0',
        copy_seo: (copyOptions.seo ?? false) ? '1' : '0',
        copy_identification: (copyOptions.identification ?? true) ? '1' : '0',
    };

    const queryString = new URLSearchParams(query).toString();
    const targetUrl = `/admin/ecommerce/products/create?${queryString}`;

    logger.log('[copyProduct] Navigating to:', targetUrl);

    // 상품 등록 페이지로 이동
    if (G7Core.navigate) {
        G7Core.navigate(targetUrl);
    } else if (context.navigate) {
        context.navigate(targetUrl);
    } else {
        window.location.href = targetUrl;
    }
}
