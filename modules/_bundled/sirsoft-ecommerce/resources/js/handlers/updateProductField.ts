/**
 * 상품 필드 업데이트 핸들러
 *
 * 상품 목록에서 인라인 편집 시 데이터소스를 업데이트합니다.
 * selling_price 변경 시 다중통화 가격을 자동 재계산합니다.
 * 실제 API 저장은 "일괄 변경" 버튼 클릭 시 별도로 처리됩니다.
 */

import type { ActionContext } from '../types';
import { calculateCurrencyPricesHandler } from './calculateCurrencyPrices';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:UpdateProduct')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:UpdateProduct]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:UpdateProduct]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:UpdateProduct]', ...args),
};

interface UpdateProductFieldParams {
    productId: number | string;
    field: string;
    value: any;
    dataSourceId?: string;
}

interface ProductFieldError {
    productId: string;
    field: string;
    message: string;
}

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 * ActionDispatcher는 (action, context) 형태로 핸들러를 호출합니다.
 */
interface ActionWithParams {
    handler: string;
    params?: UpdateProductFieldParams;
    [key: string]: any;
}

/**
 * 상품 필드 업데이트 핸들러
 *
 * G7Core.dataSource API를 사용하여 products 데이터소스를 직접 업데이트합니다.
 * selling_price 변경 시 다중통화 가격을 자동 재계산합니다.
 *
 * @param action 액션 객체 (params 포함)
 * @param context 액션 컨텍스트
 */
export function updateProductFieldHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    // 커스텀 핸들러는 (action, context) 형태로 호출되므로 action.params에서 추출
    const params = (action.params || {}) as UpdateProductFieldParams;
    const { productId, field, dataSourceId = 'products' } = params;
    let { value } = params;

    // value가 이벤트 객체인 경우 target.value 추출
    // MultilingualInput은 { target: { name, value: { ko: '...', en: '...' } } } 형태로 전달
    // 일반 Input은 { target: { value: '...' } } 형태로 전달
    if (value && typeof value === 'object' && value.target !== undefined) {
        value = value.target?.value;
        logger.log('[updateProductField] Extracted value from event:', value);
    }

    if (!productId || !field) {
        logger.warn('[updateProductField] Missing required params:', { productId, field });
        return;
    }

    if (value === undefined) {
        logger.warn('[updateProductField] Value is undefined, skipping update');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.dataSource?.get || !G7Core?.dataSource?.set) {
        logger.warn('[updateProductField] G7Core.dataSource API is not available');
        return;
    }

    // 데이터소스에서 현재 데이터 가져오기
    const currentData = G7Core.dataSource.get(dataSourceId);
    if (!currentData) {
        console.warn(`[updateProductField] DataSource '${dataSourceId}' not found`);
        return;
    }

    // API 응답 구조: { success: true, data: { data: [...products], pagination: {...} } }
    const productsArray = currentData.data?.data || [];
    if (!Array.isArray(productsArray) || productsArray.length === 0) {
        logger.warn('[updateProductField] Products data is empty or invalid');
        return;
    }

    // currencies는 _global 상태에서 가져옴
    const globalState = G7Core.state.get() || {};
    const currencies = globalState.modules?.['sirsoft-ecommerce']?.language_currency?.currencies;

    // 현재 에러 상태 가져오기
    const currentErrors: ProductFieldError[] = globalState._local?.productFieldErrors || [];
    let newErrors = [...currentErrors];

    // 상품 목록에서 해당 상품 찾아서 필드 업데이트
    const updatedProducts = productsArray.map((product: any) => {
        if (String(product.id) === String(productId)) {
            // 값이 변경되지 않았으면 업데이트하지 않음 (성능 최적화)
            const currentFieldValue = product[field];

            // 객체 비교 (다국어 필드 등)를 위해 JSON 문자열로 비교
            const isEqual = typeof value === 'object' && value !== null
                ? JSON.stringify(currentFieldValue) === JSON.stringify(value)
                : String(currentFieldValue) === String(value);

            if (isEqual) {
                logger.log(`[updateProductField] No change for product ${productId}.${field}, skipping`);
                return product;
            }

            let finalValue = value;
            const numericValue = parseFloat(value) || 0;

            // selling_price 검증: 정가를 초과할 수 없음
            if (field === 'selling_price') {
                const listPrice = parseFloat(product.list_price) || 0;
                if (listPrice > 0 && numericValue > listPrice) {
                    // 에러 추가
                    const errorExists = newErrors.some(
                        (e) => e.productId === String(productId) && e.field === field
                    );
                    if (!errorExists) {
                        newErrors.push({
                            productId: String(productId),
                            field: 'selling_price',
                            message: '판매가는 정가를 초과할 수 없습니다.',
                        });
                    }
                    // 값을 정가로 제한
                    finalValue = listPrice;
                } else {
                    // 에러 제거
                    newErrors = newErrors.filter(
                        (e) => !(e.productId === String(productId) && e.field === field)
                    );
                }
            } else {
                // 다른 필드 변경 시 해당 필드 에러 제거
                newErrors = newErrors.filter(
                    (e) => !(e.productId === String(productId) && e.field === field)
                );
            }

            const updatedProduct: any = {
                ...product,
                [field]: finalValue,
                _modified: true,
            };

            // selling_price 변경 시 다중통화 가격 자동 재계산
            if (field === 'selling_price' && currencies && Array.isArray(currencies)) {
                updatedProduct.multi_currency_selling_price = calculateCurrencyPricesHandler(
                    { basePrice: finalValue, currencies },
                    context
                );
                logger.log(`[updateProductField] Recalculated multi_currency_selling_price for product ${productId}`);
            }

            return updatedProduct;
        }
        return product;
    });

    // 데이터소스 업데이트 (UI 자동 리렌더링)
    // 구조: { success, data: { data: [...], pagination, statistics } }
    G7Core.dataSource.set(dataSourceId, {
        ...currentData,
        data: {
            ...currentData.data,
            data: updatedProducts,
        },
    });

    // 변경된 상품 ID와 필드명을 _local 상태에서 추적 + 에러 상태 업데이트
    // G7Core.state.setLocal을 사용하여 컴포넌트 로컬 상태 직접 업데이트
    const currentLocal = G7Core.state.getLocal() || {};
    const modifiedProductIds = new Set(currentLocal.modifiedProductIds || []);
    modifiedProductIds.add(productId);

    // 수정된 필드명 추적 (상품별로 어떤 필드가 수정되었는지 기록)
    const modifiedProductFields: Record<string, string[]> = { ...(currentLocal.modifiedProductFields || {}) };
    const productKey = String(productId);
    const existingFields = new Set(modifiedProductFields[productKey] || []);
    existingFields.add(field);
    modifiedProductFields[productKey] = Array.from(existingFields);

    G7Core.state.setLocal({
        modifiedProductIds: Array.from(modifiedProductIds),
        modifiedProductFields,
        productFieldErrors: newErrors,
    });

    logger.log(`[updateProductField] Updated product ${productId}.${field} =`, value);
}
