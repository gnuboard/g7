/**
 * 기본 옵션 설정 핸들러
 *
 * 상품의 기본 옵션을 설정하고, 해당 옵션의 가격을 상품 가격에 동기화합니다.
 * 기본 옵션의 list_price와 selling_price가 원 상품의 가격으로 설정됩니다.
 */

import type { ActionContext } from '../types';
import { calculateCurrencyPricesHandler } from './calculateCurrencyPrices';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:DefaultOption')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:DefaultOption]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:DefaultOption]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:DefaultOption]', ...args),
};

interface SetDefaultOptionParams {
    productId: number | string;
    optionId: number | string;
    dataSourceId?: string;
}

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 */
interface ActionWithParams {
    handler: string;
    params?: SetDefaultOptionParams;
    [key: string]: any;
}

/**
 * 기본 옵션 설정 핸들러
 *
 * 1. 선택된 옵션을 기본 옵션으로 설정 (is_default: true)
 * 2. 다른 옵션들의 is_default를 false로 설정
 * 3. 기본 옵션의 list_price와 selling_price를 상품의 가격으로 동기화
 * 4. 다중통화 가격도 재계산
 *
 * @param action 액션 객체 (params 포함)
 * @param context 액션 컨텍스트
 */
export function setDefaultOptionHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = (action.params || {}) as SetDefaultOptionParams;
    const { productId, optionId, dataSourceId = 'products' } = params;

    if (!productId || !optionId) {
        logger.warn('[setDefaultOption] Missing required params:', { productId, optionId });
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.dataSource?.get || !G7Core?.dataSource?.set) {
        logger.warn('[setDefaultOption] G7Core.dataSource API is not available');
        return;
    }

    // 데이터소스에서 현재 데이터 가져오기
    const currentData = G7Core.dataSource.get(dataSourceId);
    if (!currentData) {
        console.warn(`[setDefaultOption] DataSource '${dataSourceId}' not found`);
        return;
    }

    // API 응답 구조: { success: true, data: { data: [...products], pagination: {...} } }
    const productsArray = currentData.data?.data || [];
    if (!Array.isArray(productsArray) || productsArray.length === 0) {
        logger.warn('[setDefaultOption] Products data is empty or invalid');
        return;
    }

    // currencies는 _global 상태에서 가져옴
    const globalState = G7Core.state.get() || {};
    const currencies = globalState.modules?.['sirsoft-ecommerce']?.language_currency?.currencies;

    // 상품 목록에서 해당 상품 찾아서 업데이트
    const updatedProducts = productsArray.map((product: any) => {
        if (String(product.id) !== String(productId)) {
            return product;
        }

        const options = product.options || [];
        if (options.length === 0) {
            return product;
        }

        // 선택된 옵션 찾기
        const selectedOption = options.find(
            (o: any) => String(o.id) === String(optionId)
        );

        if (!selectedOption) {
            logger.warn('[setDefaultOption] Option not found:', optionId);
            return product;
        }

        // 모든 옵션 업데이트: 선택된 옵션만 is_default = true
        const updatedOptions = options.map((option: any) => {
            const isSelected = String(option.id) === String(optionId);
            return {
                ...option,
                is_default: isSelected,
                _modified: isSelected ? true : option._modified,
            };
        });

        // 기본 옵션의 가격을 상품 가격으로 동기화
        const newListPrice = selectedOption.list_price;
        const newSellingPrice = selectedOption.selling_price;

        // 다중통화 가격 재계산
        let multiCurrencySellingPrice = product.multi_currency_selling_price;
        if (currencies && Array.isArray(currencies)) {
            multiCurrencySellingPrice = calculateCurrencyPricesHandler(
                { basePrice: newSellingPrice, currencies },
                context
            );
        }

        logger.log(
            `[setDefaultOption] Set option ${optionId} as default for product ${productId}. ` +
            `Syncing prices: list_price=${newListPrice}, selling_price=${newSellingPrice}`
        );

        return {
            ...product,
            options: updatedOptions,
            list_price: newListPrice,
            selling_price: newSellingPrice,
            multi_currency_selling_price: multiCurrencySellingPrice,
            _modified: true,
        };
    });

    // 데이터소스 업데이트 (UI 자동 리렌더링)
    G7Core.dataSource.set(dataSourceId, {
        ...currentData,
        data: {
            ...currentData.data,
            data: updatedProducts,
        },
    });

    // 변경된 상품 ID를 _local 상태에서 추적
    const currentLocal = G7Core.state.getLocal() || {};
    const modifiedProductIds = new Set(currentLocal.modifiedProductIds || []);
    modifiedProductIds.add(String(productId));

    const modifiedOptionIds = new Set(currentLocal.modifiedOptionIds || []);
    modifiedOptionIds.add(`${productId}-${optionId}`);

    G7Core.state.setLocal({
        modifiedProductIds: Array.from(modifiedProductIds),
        modifiedOptionIds: Array.from(modifiedOptionIds),
    });

    logger.log(`[setDefaultOption] Option ${optionId} set as default for product ${productId}`);
}
