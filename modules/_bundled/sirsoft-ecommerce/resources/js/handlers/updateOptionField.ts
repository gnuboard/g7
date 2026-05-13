/**
 * 옵션 필드 업데이트 핸들러
 *
 * 상품 옵션의 인라인 편집 시 데이터소스를 업데이트합니다.
 * selling_price 변경 시 다중통화 가격을 자동 재계산합니다.
 */

import type { ActionContext } from '../types';
import { calculateCurrencyPricesHandler } from './calculateCurrencyPrices';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:UpdateOption')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:UpdateOption]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:UpdateOption]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:UpdateOption]', ...args),
};

interface UpdateOptionFieldParams {
    productId: number | string;
    optionId: number | string;
    field: string;
    value: any;
    dataSourceId?: string;
}

interface OptionFieldError {
    productId: string;
    optionId: string;
    field: string;
    message: string;
}

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 * ActionDispatcher는 (action, context) 형태로 핸들러를 호출합니다.
 */
interface ActionWithParams {
    handler: string;
    params?: UpdateOptionFieldParams;
    [key: string]: any;
}

/**
 * 옵션 필드 업데이트 핸들러
 *
 * G7Core.dataSource API를 사용하여 products 데이터소스를 직접 업데이트합니다.
 * selling_price 변경 시 다중통화 가격을 자동 재계산합니다.
 *
 * @param action 액션 객체 (params 포함)
 * @param context 액션 컨텍스트
 */
export function updateOptionFieldHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    // 커스텀 핸들러는 (action, context) 형태로 호출되므로 action.params에서 추출
    const params = (action.params || {}) as UpdateOptionFieldParams;
    const { productId, optionId, field, value, dataSourceId = 'products' } = params;

    if (!productId || !optionId || !field) {
        logger.warn('[updateOptionField] Missing required params:', { productId, optionId, field });
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.dataSource?.get || !G7Core?.dataSource?.set) {
        logger.warn('[updateOptionField] G7Core.dataSource API is not available');
        return;
    }

    // 데이터소스에서 현재 데이터 가져오기
    const currentData = G7Core.dataSource.get(dataSourceId);
    if (!currentData) {
        console.warn(`[updateOptionField] DataSource '${dataSourceId}' not found`);
        return;
    }

    // API 응답 구조: { success: true, data: { data: [...products], pagination: {...} } }
    const productsArray = currentData.data?.data || [];
    if (!Array.isArray(productsArray) || productsArray.length === 0) {
        logger.warn('[updateOptionField] Products data is empty or invalid');
        return;
    }

    // currencies는 _global 상태에서 가져옴
    const globalState = G7Core.state.get() || {};
    const currencies = globalState.modules?.['sirsoft-ecommerce']?.language_currency?.currencies;

    // 현재 에러 상태 가져오기
    const currentErrors: OptionFieldError[] = globalState._local?.optionFieldErrors || [];
    let newErrors = [...currentErrors];

    // 상품 목록에서 해당 상품과 옵션 찾아서 업데이트
    const updatedProducts = productsArray.map((product: any) => {
        if (String(product.id) === String(productId)) {
            const options = product.options || [];

            // 해당 옵션 찾기
            const optionIndex = options.findIndex(
                (o: any) => String(o.id) === String(optionId)
            );

            if (optionIndex === -1) {
                logger.warn('[updateOptionField] Option not found:', optionId);
                return product;
            }

            const currentOption = options[optionIndex];

            // 값이 변경되지 않았으면 업데이트하지 않음 (성능 최적화)
            const currentFieldValue = currentOption[field];

            // 객체 비교 (다국어 필드 등)를 위해 JSON 문자열로 비교
            const isEqual = typeof value === 'object' && value !== null
                ? JSON.stringify(currentFieldValue) === JSON.stringify(value)
                : String(currentFieldValue) === String(value);

            if (isEqual) {
                logger.log(`[updateOptionField] No change for option ${optionId}.${field}, skipping`);
                return product;
            }

            let finalValue = value;
            const numericValue = parseFloat(value) || 0;

            // selling_price 검증: 정가를 초과할 수 없음
            if (field === 'selling_price') {
                const listPrice = parseFloat(currentOption.list_price) || 0;
                if (listPrice > 0 && numericValue > listPrice) {
                    // 에러 추가
                    const errorExists = newErrors.some(
                        (e) =>
                            e.productId === String(productId) &&
                            e.optionId === String(optionId) &&
                            e.field === field
                    );
                    if (!errorExists) {
                        newErrors.push({
                            productId: String(productId),
                            optionId: String(optionId),
                            field: 'selling_price',
                            message: '판매가는 정가를 초과할 수 없습니다.',
                        });
                    }
                    // 값을 정가로 제한
                    finalValue = listPrice;
                } else {
                    // 에러 제거
                    newErrors = newErrors.filter(
                        (e) =>
                            !(
                                e.productId === String(productId) &&
                                e.optionId === String(optionId) &&
                                e.field === field
                            )
                    );
                }
            } else {
                // 다른 필드 변경 시 해당 필드 에러 제거
                newErrors = newErrors.filter(
                    (e) =>
                        !(
                            e.productId === String(productId) &&
                            e.optionId === String(optionId) &&
                            e.field === field
                        )
                );
            }

            // 옵션 업데이트
            const updatedOptions = [...options];
            const updatedOption: any = {
                ...updatedOptions[optionIndex],
                [field]: finalValue,
                _modified: true,
            };

            // selling_price 변경 시 price_adjustment 재계산 (정가 - 판매가)
            if (field === 'selling_price') {
                const listPrice = parseFloat(currentOption.list_price) || 0;
                const newSellingPrice = parseFloat(finalValue) || 0;
                // price_adjustment = 판매가 - 정가 (할인 시 음수)
                updatedOption.price_adjustment = newSellingPrice - listPrice;
                updatedOption.price_adjustment_formatted =
                    (updatedOption.price_adjustment >= 0 ? '+' : '') +
                    updatedOption.price_adjustment.toLocaleString() + '원';
            }

            // selling_price 변경 시 다중통화 가격 자동 재계산
            if (field === 'selling_price' && currencies && Array.isArray(currencies)) {
                updatedOption.multi_currency_selling_price = calculateCurrencyPricesHandler(
                    { basePrice: finalValue, currencies },
                    context
                );
                logger.log(`[updateOptionField] Recalculated multi_currency_selling_price for option ${optionId}`);
            }

            updatedOptions[optionIndex] = updatedOption;

            // stock_quantity 변경 시 상품의 총 재고를 옵션 재고 합계로 업데이트
            let updatedStockQuantity = product.stock_quantity;
            let updatedOptionStockSum = product.option_stock_sum;
            if (field === 'stock_quantity') {
                const newValue = parseInt(value, 10) || 0;
                updatedOptionStockSum = updatedOptions.reduce((sum: number, opt: any) => {
                    if (opt.is_active === false) return sum;
                    return sum + (parseInt(opt.stock_quantity, 10) || 0);
                }, 0);
                updatedStockQuantity = updatedOptionStockSum;
                logger.log(`[updateOptionField] Updated product stock to option sum: ${updatedStockQuantity}`);
            }

            return {
                ...product,
                options: updatedOptions,
                stock_quantity: updatedStockQuantity,
                option_stock_sum: updatedOptionStockSum,
                _modified: true,
            };
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

    // 변경된 옵션 ID와 필드명을 _local 상태에서 추적 + 에러 상태 업데이트
    // G7Core.state.setLocal을 사용하여 컴포넌트 로컬 상태 직접 업데이트
    const currentLocal = G7Core.state.getLocal() || {};
    const modifiedOptionIds = new Set(currentLocal.modifiedOptionIds || []);
    const optionKey = `${productId}-${optionId}`;
    modifiedOptionIds.add(optionKey);

    // 수정된 필드명 추적 (옵션별로 어떤 필드가 수정되었는지 기록)
    const modifiedOptionFields: Record<string, string[]> = { ...(currentLocal.modifiedOptionFields || {}) };
    const existingFields = new Set(modifiedOptionFields[optionKey] || []);
    existingFields.add(field);
    modifiedOptionFields[optionKey] = Array.from(existingFields);

    G7Core.state.setLocal({
        modifiedOptionIds: Array.from(modifiedOptionIds),
        modifiedOptionFields,
        optionFieldErrors: newErrors,
    });

    logger.log(`[updateOptionField] Updated option ${productId}/${optionId}.${field} =`, value);
}
