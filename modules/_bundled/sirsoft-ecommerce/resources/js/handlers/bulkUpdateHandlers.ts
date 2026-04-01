/**
 * 통합 일괄 업데이트 핸들러
 *
 * 상품/옵션의 일괄 변경 + 개별 인라인 수정을 동시 처리합니다.
 * - 상품 체크 시: 상품 API 호출 (해당 상품의 옵션 자동 포함)
 * - 상품 미체크 + 옵션만 체크: 옵션 API 호출
 *
 * "일괄 적용" 버튼 → 확인 모달 → 이 핸들러 호출
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:BulkUpdate')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:BulkUpdate]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:BulkUpdate]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:BulkUpdate]', ...args),
};

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 */
interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    target?: string;
    onSuccess?: any;
    onError?: any;
    [key: string]: any;
}

/**
 * 일괄 변경 조건 인터페이스
 */
interface BulkChangeCondition {
    method: 'set' | 'add' | 'subtract' | 'percent';
    value: number;
}

/**
 * 상품 일괄 변경 조건
 */
interface ProductBulkChanges {
    sales_status?: string;
    display_status?: string;
}

/**
 * 옵션 일괄 변경 조건
 */
interface OptionBulkChanges {
    price_adjustment?: BulkChangeCondition;
    stock_quantity?: BulkChangeCondition;
}

/**
 * 상품 개별 수정 아이템
 */
interface ProductItem {
    id: number;
    name?: Record<string, string>;
    list_price?: number;
    selling_price?: number;
    sales_status?: string;
    display_status?: string;
}

/**
 * 옵션 개별 수정 아이템
 */
interface OptionItem {
    product_id: number;
    option_id: number;
    option_name?: string | Record<string, string>;
    sku?: string;
    list_price?: number;
    price_adjustment?: number;
    stock_quantity?: number;
    safe_stock_quantity?: number;
    is_default?: boolean;
    is_active?: boolean;
}

/**
 * 확인 데이터 인터페이스
 */
interface BulkConfirmData {
    products: Array<{
        id: number;
        name: string;
        changes: string;
    }>;
    options: Array<{
        productId: number;
        optionId: number;
        productName: string;
        optionName: string;
        changes: string;
    }>;
    summary: {
        productCount: number;
        optionCount: number;
        hasBulkChanges: boolean;
        hasInlineChanges: boolean;
    };
}

/**
 * G7Core 인터페이스 (타입 안전성)
 */
interface G7CoreInterface {
    dataSource: {
        get: (id: string) => any;
        set: (id: string, data: any) => void;
        refetch: (id: string) => Promise<any>;
    };
    state: {
        get: () => Record<string, any>;
        set: (updates: Record<string, any>) => void;
        getLocal: () => Record<string, any>;
        setLocal: (updates: Record<string, any>) => void;
    };
    api: {
        patch: (url: string, data: any) => Promise<any>;
    };
    toast: {
        success: (message: string) => void;
        warning: (message: string) => void;
        error: (message: string) => void;
    };
    t: (key: string, params?: Record<string, any>) => string;
    modal: {
        close: (id?: string) => void;
    };
}

/**
 * 다국어 객체에서 로컬라이즈된 문자열 추출
 * option_name, product name 등 {en: "...", ko: "..."} 형태의 객체 처리
 */
function localizeValue(value: any): string {
    if (typeof value === 'string') return value;
    if (value && typeof value === 'object') {
        return value.ko || value.en || JSON.stringify(value);
    }
    return String(value ?? '');
}

/**
 * G7Core 가져오기
 */
function getG7Core(): G7CoreInterface | null {
    const G7Core = (window as any).G7Core;
    if (!G7Core) {
        logger.error('[bulkUpdate] G7Core is not available');
        return null;
    }
    return G7Core;
}

/**
 * 통합 일괄 업데이트 핸들러
 *
 * 상품 체크 여부에 따라 API 호출 결정:
 * - selectedItems.length > 0 → 상품 API 호출 (옵션 자동 포함)
 * - selectedItems.length === 0 && selectedOptionIds.length > 0 → 옵션 API 호출
 */
export function bulkUpdateHandler(action: ActionWithParams, context: ActionContext): void {
    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal() || {};
    const globalState = G7Core.state.get() || {};

    // 모달에서 호출 시 _local 대신 _global.bulkSelectedItems 사용
    // (일괄 적용 버튼 클릭 시 setState로 _global에 복사됨)
    const selectedItems: number[] = globalState.bulkSelectedItems || localState.selectedItems || [];
    const selectedOptionIds: string[] = globalState.bulkSelectedOptionIds || localState.selectedOptionIds || [];

    logger.log('[bulkUpdate] globalState.bulkSelectedItems:', globalState.bulkSelectedItems);
    logger.log('[bulkUpdate] localState.selectedItems:', localState.selectedItems);
    logger.log('[bulkUpdate] Final selectedItems:', selectedItems);

    // API 호출 결정: 상품 체크가 우선
    if (selectedItems.length > 0) {
        // 상품이 체크된 경우 → 상품 API 호출 (옵션 자동 포함)
        bulkUpdateProductsInternal(G7Core, globalState, localState, selectedItems);
    } else if (selectedOptionIds.length > 0) {
        // 상품 미체크 + 옵션만 체크된 경우 → 옵션 API 호출
        bulkUpdateOptionsInternal(G7Core, globalState, localState, selectedOptionIds);
    } else {
        // 아무것도 체크 안 됨
        G7Core.toast.warning(G7Core.t('sirsoft-ecommerce.admin.product.bulk.no_selection'));
    }
}

/**
 * 상품 일괄 업데이트 내부 함수
 * 상품과 해당 옵션을 함께 처리
 */
function bulkUpdateProductsInternal(
    G7Core: G7CoreInterface,
    globalState: Record<string, any>,
    localState: Record<string, any>,
    selectedIds: number[]
): void {
    const dataSource = G7Core.dataSource.get('products');
    const products = dataSource?.data?.data || [];

    // 1. 상품 일괄 변경 조건 수집
    const productBulkChanges: ProductBulkChanges = {};
    if (globalState.bulkSalesStatus) {
        productBulkChanges.sales_status = globalState.bulkSalesStatus;
    }
    if (globalState.bulkDisplayStatus) {
        productBulkChanges.display_status = globalState.bulkDisplayStatus;
    }

    // 2. 옵션 일괄 변경 조건 수집
    const optionBulkChanges: OptionBulkChanges = {};
    // bulkPriceCondition은 표시용 문자열("+100원")이므로, bulkPriceMethod/Value/Unit로 객체 생성
    if (globalState.bulkPriceMethod && globalState.bulkPriceValue !== undefined && globalState.bulkPriceValue !== null && globalState.bulkPriceValue !== '') {
        const priceMethodMap: Record<string, 'set' | 'add' | 'subtract' | 'percent'> = {
            'fixed': 'set',
            'increase': 'add',
            'decrease': 'subtract',
        };
        // percent 단위인 경우 method를 'percent'로, 감소 시 음수 값 사용
        if (globalState.bulkPriceUnit === 'percent') {
            optionBulkChanges.price_adjustment = {
                method: 'percent',
                value: globalState.bulkPriceMethod === 'decrease'
                    ? -(Number(globalState.bulkPriceValue) || 0)
                    : Number(globalState.bulkPriceValue) || 0,
            };
        } else {
            optionBulkChanges.price_adjustment = {
                method: priceMethodMap[globalState.bulkPriceMethod] || 'add',
                value: Number(globalState.bulkPriceValue) || 0,
            };
        }
    }
    // bulkStockCondition은 표시용 문자열("+10개")이므로, bulkStockMethod와 bulkStockValue로 객체 생성
    if (globalState.bulkStockMethod && globalState.bulkStockValue !== undefined && globalState.bulkStockValue !== null && globalState.bulkStockValue !== '') {
        const stockMethodMap: Record<string, 'set' | 'add' | 'subtract'> = {
            'set': 'set',
            'increase': 'add',
            'decrease': 'subtract',
        };
        optionBulkChanges.stock_quantity = {
            method: stockMethodMap[globalState.bulkStockMethod] || 'add',
            value: Number(globalState.bulkStockValue) || 0,
        };
    }

    // 3. 개별 인라인 수정 수집 (상품 + 옵션 모두)
    // modifiedProductIds, modifiedOptionIds, modifiedFields를 함께 확인
    const modifiedProductIds: number[] = localState.modifiedProductIds || [];
    const modifiedOptionIds: string[] = localState.modifiedOptionIds || [];
    const modifiedProductFields: Record<string, string[]> = localState.modifiedProductFields || {};
    const modifiedOptionFieldsMap: Record<string, string[]> = localState.modifiedOptionFields || {};

    // 개별 체크된 옵션 (상품이 체크되지 않은 옵션들)
    const selectedOptionIds: string[] = globalState.bulkSelectedOptionIds || localState.selectedOptionIds || [];
    const selectedProductIdSet = new Set(selectedIds);

    const productItems: ProductItem[] = [];
    const optionItems: OptionItem[] = [];

    /**
     * 옵션 인라인 수정 데이터 수집 헬퍼 (실제 수정된 필드만 포함)
     */
    function collectOptionItem(p: any, opt: any, optionKey: string): void {
        const isOptionModified = opt._modified || modifiedOptionIds.includes(optionKey);
        if (isOptionModified) {
            const item: OptionItem = { product_id: p.id, option_id: opt.id };
            const optModifiedFields = modifiedOptionFieldsMap[optionKey] || [];

            // 실제 수정된 필드만 적용 (bulk_changes에 없는 것만)
            if (!optionBulkChanges.price_adjustment && optModifiedFields.includes('price_adjustment')) {
                item.price_adjustment = opt.price_adjustment;
            }
            if (!optionBulkChanges.price_adjustment && optModifiedFields.includes('selling_price')) {
                // selling_price 변경 시 price_adjustment도 재계산되어 있으므로 함께 전송
                item.price_adjustment = opt.price_adjustment;
            }
            if (!optionBulkChanges.stock_quantity && optModifiedFields.includes('stock_quantity')) {
                item.stock_quantity = opt.stock_quantity;
            }
            if (optModifiedFields.includes('option_name')) item.option_name = opt.option_name;
            if (optModifiedFields.includes('sku')) item.sku = opt.sku;
            if (optModifiedFields.includes('list_price')) item.list_price = opt.list_price;
            if (optModifiedFields.includes('safe_stock_quantity')) item.safe_stock_quantity = opt.safe_stock_quantity;
            if (optModifiedFields.includes('is_default')) item.is_default = opt.is_default;
            if (optModifiedFields.includes('is_active')) item.is_active = opt.is_active;

            // 실제 변경 사항이 있는 경우에만 추가
            if (Object.keys(item).length > 2) {
                optionItems.push(item);
            }
        }
    }

    products
        .filter((p: any) => selectedIds.includes(p.id))
        .forEach((p: any) => {
            // 상품 인라인 수정 (_modified 플래그 또는 modifiedProductIds 배열 확인)
            const isProductModified = p._modified || modifiedProductIds.includes(p.id);
            if (isProductModified) {
                const item: ProductItem = { id: p.id };
                const productKey = String(p.id);
                const pModifiedFields = modifiedProductFields[productKey] || [];

                // 실제 수정된 필드만 적용 (bulk_changes에 없는 것만)
                if (!productBulkChanges.sales_status && pModifiedFields.includes('sales_status')) {
                    item.sales_status = p.sales_status;
                }
                if (!productBulkChanges.display_status && pModifiedFields.includes('display_status')) {
                    item.display_status = p.display_status;
                }
                if (pModifiedFields.includes('name')) item.name = p.name;
                if (pModifiedFields.includes('list_price')) item.list_price = p.list_price;
                if (pModifiedFields.includes('selling_price')) item.selling_price = p.selling_price;

                // 실제 변경 사항이 있는 경우에만 추가
                if (Object.keys(item).length > 1) {
                    productItems.push(item);
                }
            }

            // 해당 상품의 모든 옵션 인라인 수정 (상품 체크 시 옵션 자동 포함)
            (p.options || []).forEach((opt: any) => {
                const optionKey = `${p.id}-${opt.id}`;
                collectOptionItem(p, opt, optionKey);
            });
        });

    // 4. 체크된 상품에 속하지 않은 개별 체크된 옵션 처리
    if (selectedOptionIds.length > 0) {
        products.forEach((p: any) => {
            // 이미 체크된 상품의 옵션은 위에서 처리됨
            if (selectedProductIdSet.has(p.id)) {
                return;
            }

            (p.options || []).forEach((opt: any) => {
                const optionKey = `${p.id}-${opt.id}`;
                // 개별 체크된 옵션만 처리
                if (selectedOptionIds.includes(optionKey)) {
                    collectOptionItem(p, opt, optionKey);
                }
            });
        });
    }

    // 4. 상품 API 호출 (옵션 데이터 포함)
    const payload: Record<string, any> = {
        ids: selectedIds,
    };

    if (Object.keys(productBulkChanges).length > 0) {
        payload.bulk_changes = productBulkChanges;
    }
    if (productItems.length > 0) {
        payload.items = productItems;
    }
    if (Object.keys(optionBulkChanges).length > 0) {
        payload.option_bulk_changes = optionBulkChanges;
    }
    if (optionItems.length > 0) {
        payload.option_items = optionItems;
    }

    // 처리 중 상태 표시
    G7Core.state.set({ isProcessing: true });

    G7Core.api.patch('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', payload)
        .then((response: any) => {
            G7Core.toast.success(
                G7Core.t('sirsoft-ecommerce.admin.product.bulk.update_success', {
                    products: response?.data?.products_updated || 0,
                    options: response?.data?.options_updated || 0,
                })
            );
            // 상태 초기화
            resetBulkState(G7Core);
            // DataSource 리프레시
            G7Core.dataSource.refetch('products');
            // 모달 닫기
            G7Core.modal.close();
        })
        .catch((error: any) => {
            logger.error('[bulkUpdateProducts] Error:', error);
            G7Core.toast.error(G7Core.t('sirsoft-ecommerce.admin.product.bulk.update_error'));
        })
        .finally(() => {
            G7Core.state.set({ isProcessing: false });
        });
}

/**
 * 옵션 일괄 업데이트 내부 함수
 * 특정 옵션만 개별 처리 (상품 미체크 시)
 */
function bulkUpdateOptionsInternal(
    G7Core: G7CoreInterface,
    globalState: Record<string, any>,
    localState: Record<string, any>,
    selectedOptionIds: string[]
): void {
    const dataSource = G7Core.dataSource.get('products');
    const products = dataSource?.data?.data || [];

    // 1. 일괄 변경 조건 수집
    const bulkChanges: OptionBulkChanges = {};
    // bulkPriceCondition은 표시용 문자열("+100원")이므로, bulkPriceMethod/Value/Unit로 객체 생성
    if (globalState.bulkPriceMethod && globalState.bulkPriceValue !== undefined && globalState.bulkPriceValue !== null && globalState.bulkPriceValue !== '') {
        const priceMethodMap: Record<string, 'set' | 'add' | 'subtract' | 'percent'> = {
            'fixed': 'set',
            'increase': 'add',
            'decrease': 'subtract',
        };
        // percent 단위인 경우 method를 'percent'로, 감소 시 음수 값 사용
        if (globalState.bulkPriceUnit === 'percent') {
            bulkChanges.price_adjustment = {
                method: 'percent',
                value: globalState.bulkPriceMethod === 'decrease'
                    ? -(Number(globalState.bulkPriceValue) || 0)
                    : Number(globalState.bulkPriceValue) || 0,
            };
        } else {
            bulkChanges.price_adjustment = {
                method: priceMethodMap[globalState.bulkPriceMethod] || 'add',
                value: Number(globalState.bulkPriceValue) || 0,
            };
        }
    }
    // bulkStockCondition은 표시용 문자열("+10개")이므로, bulkStockMethod와 bulkStockValue로 객체 생성
    if (globalState.bulkStockMethod && globalState.bulkStockValue !== undefined && globalState.bulkStockValue !== null && globalState.bulkStockValue !== '') {
        const stockMethodMap: Record<string, 'set' | 'add' | 'subtract'> = {
            'set': 'set',
            'increase': 'add',
            'decrease': 'subtract',
        };
        bulkChanges.stock_quantity = {
            method: stockMethodMap[globalState.bulkStockMethod] || 'add',
            value: Number(globalState.bulkStockValue) || 0,
        };
    }

    // 2. 개별 인라인 수정 수집 (실제 수정된 필드만)
    const modifiedOptionFieldsMap: Record<string, string[]> = localState.modifiedOptionFields || {};
    const modifiedOptionIds: string[] = localState.modifiedOptionIds || [];
    const items: OptionItem[] = [];
    products.forEach((product: any) => {
        (product.options || []).forEach((opt: any) => {
            const optionKey = `${product.id}-${opt.id}`;
            const isOptionModified = opt._modified || modifiedOptionIds.includes(optionKey);
            if (selectedOptionIds.includes(optionKey) && isOptionModified) {
                const item: OptionItem = { product_id: product.id, option_id: opt.id };
                const optModifiedFields = modifiedOptionFieldsMap[optionKey] || [];

                // 실제 수정된 필드만 적용 (bulk_changes에 없는 것만)
                if (!bulkChanges.price_adjustment && optModifiedFields.includes('price_adjustment')) {
                    item.price_adjustment = opt.price_adjustment;
                }
                if (!bulkChanges.price_adjustment && optModifiedFields.includes('selling_price')) {
                    item.price_adjustment = opt.price_adjustment;
                }
                if (!bulkChanges.stock_quantity && optModifiedFields.includes('stock_quantity')) {
                    item.stock_quantity = opt.stock_quantity;
                }
                if (optModifiedFields.includes('option_name')) item.option_name = opt.option_name;
                if (optModifiedFields.includes('sku')) item.sku = opt.sku;
                if (optModifiedFields.includes('list_price')) item.list_price = opt.list_price;
                if (optModifiedFields.includes('safe_stock_quantity')) item.safe_stock_quantity = opt.safe_stock_quantity;
                if (optModifiedFields.includes('is_default')) item.is_default = opt.is_default;
                if (optModifiedFields.includes('is_active')) item.is_active = opt.is_active;

                // 실제 변경 사항이 있는 경우에만 추가
                if (Object.keys(item).length > 2) {
                    items.push(item);
                }
            }
        });
    });

    // 3. 옵션 API 호출
    const payload: Record<string, any> = {
        ids: selectedOptionIds,
    };

    if (Object.keys(bulkChanges).length > 0) {
        payload.bulk_changes = bulkChanges;
    }
    if (items.length > 0) {
        payload.items = items;
    }

    // 처리 중 상태 표시
    G7Core.state.set({ isProcessing: true });

    G7Core.api.patch('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', payload)
        .then((response: any) => {
            G7Core.toast.success(
                G7Core.t('sirsoft-ecommerce.admin.product.bulk.option_update_success', {
                    count: response?.data?.options_updated || 0,
                })
            );
            // 상태 초기화
            resetBulkState(G7Core);
            // DataSource 리프레시
            G7Core.dataSource.refetch('products');
            // 모달 닫기
            G7Core.modal.close();
        })
        .catch((error: any) => {
            logger.error('[bulkUpdateOptions] Error:', error);
            G7Core.toast.error(G7Core.t('sirsoft-ecommerce.admin.product.bulk.option_update_error'));
        })
        .finally(() => {
            G7Core.state.set({ isProcessing: false });
        });
}

/**
 * 일괄 변경 상태 초기화
 */
function resetBulkState(G7Core: G7CoreInterface): void {
    // 로컬 상태 초기화
    G7Core.state.setLocal({
        modifiedProductIds: [],
        modifiedOptionIds: [],
        modifiedProductFields: {},
        modifiedOptionFields: {},
        selectedItems: [],
        selectedOptionIds: [],
    });

    // 글로벌 상태 초기화
    G7Core.state.set({
        bulkSalesStatus: null,
        bulkDisplayStatus: null,
        bulkPriceCondition: null,
        bulkStockCondition: null,
        bulkConfirmData: null,
    });
}

/**
 * 확인 모달에 표시할 변경 예정 데이터 빌드 핸들러
 *
 * DataSource에서 체크된 항목 중 변경 사항 추출,
 * 상단 일괄 변경 설정 + 인라인 수정 사항 병합하여 요약 생성
 */
export function buildConfirmDataHandler(action: ActionWithParams, context: ActionContext): void {
    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal() || {};
    const globalState = G7Core.state.get() || {};
    const dataSource = G7Core.dataSource.get('products');
    const products = dataSource?.data?.data || [];

    const selectedItems: number[] = localState.selectedItems || [];
    const selectedOptionIds: string[] = localState.selectedOptionIds || [];

    const confirmData: BulkConfirmData = {
        products: [],
        options: [],
        summary: {
            productCount: 0,
            optionCount: 0,
            hasBulkChanges: false,
            hasInlineChanges: false,
        },
    };

    // 일괄 변경 조건 확인
    const hasBulkChanges = !!(
        globalState.bulkSalesStatus ||
        globalState.bulkDisplayStatus ||
        globalState.bulkPriceCondition ||
        globalState.bulkStockCondition
    );
    confirmData.summary.hasBulkChanges = hasBulkChanges;

    // modifiedProductIds, modifiedOptionIds, modifiedFields 확인 (인라인 수정 여부)
    const modifiedProductIds: number[] = localState.modifiedProductIds || [];
    const modifiedOptionIds: string[] = localState.modifiedOptionIds || [];
    const modifiedProductFields: Record<string, string[]> = localState.modifiedProductFields || {};
    const modifiedOptionFieldsMap: Record<string, string[]> = localState.modifiedOptionFields || {};

    // 상품별 변경 사항 수집
    // 체크된 상품의 ID Set (체크된 상품의 옵션은 별도로 처리하지 않음)
    const selectedProductIdSet = new Set(selectedItems);

    /**
     * 옵션 인라인 변경사항 수집 (중복 코드 제거를 위한 헬퍼)
     */
    function collectOptionChanges(opt: any, optionKey: string, isOptionModified: boolean): string[] {
        const optChanges: string[] = [];
        const optModifiedFields = modifiedOptionFieldsMap[optionKey] || [];

        // 옵션 일괄 변경 표시
        if (globalState.bulkPriceCondition) {
            const pc = globalState.bulkPriceCondition;
            optChanges.push(
                G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_price_adjustment', { method: pc.method, value: pc.value })
                || `Price adjustment: ${pc.method} ${pc.value}`
            );
        }
        if (globalState.bulkStockCondition) {
            const sc = globalState.bulkStockCondition;
            optChanges.push(
                G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_stock', { method: sc.method, value: sc.value })
                || `Stock: ${sc.method} ${sc.value}`
            );
        }

        // 옵션 인라인 수정 표시 (실제 수정된 필드만 보고)
        if (isOptionModified) {
            if (!globalState.bulkPriceCondition && optModifiedFields.includes('price_adjustment')) {
                const priceValue = opt.price_adjustment?.toLocaleString?.() ?? opt.price_adjustment;
                optChanges.push(
                    G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_price_adjustment_inline', {
                        value: priceValue,
                    })
                    || `Price adjustment: ${priceValue}`
                );
            }
            if (!globalState.bulkPriceCondition && optModifiedFields.includes('selling_price')) {
                optChanges.push(
                    G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_selling_price', {
                        price: opt.selling_price?.toLocaleString?.() ?? opt.selling_price,
                    })
                    || `Selling price: ${opt.selling_price}`
                );
            }
            if (!globalState.bulkStockCondition && optModifiedFields.includes('stock_quantity')) {
                optChanges.push(
                    G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_stock_inline', {
                        value: opt.stock_quantity,
                    })
                    || `Stock: ${opt.stock_quantity}`
                );
            }
            if (optModifiedFields.includes('option_name')) {
                optChanges.push(
                    G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_option_name', {
                        name: localizeValue(opt.option_name),
                    })
                    || `Option name: ${localizeValue(opt.option_name)}`
                );
            }
            if (optModifiedFields.includes('sku')) {
                optChanges.push(
                    G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_sku', { sku: opt.sku })
                    || `SKU: ${opt.sku}`
                );
            }
            if (optModifiedFields.includes('list_price')) {
                optChanges.push(
                    G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_list_price', {
                        price: opt.list_price?.toLocaleString?.() ?? opt.list_price,
                    })
                    || `List price: ${opt.list_price}`
                );
            }
            if (optModifiedFields.includes('safe_stock_quantity')) {
                optChanges.push(`안전재고: ${opt.safe_stock_quantity}`);
            }
            if (optModifiedFields.includes('is_active')) {
                optChanges.push(`사용: ${opt.is_active ? 'ON' : 'OFF'}`);
            }
            confirmData.summary.hasInlineChanges = true;
        }

        return optChanges;
    }

    if (selectedItems.length > 0) {
        products
            .filter((p: any) => selectedItems.includes(p.id))
            .forEach((p: any) => {
                const changes: string[] = [];
                const isProductModified = p._modified || modifiedProductIds.includes(p.id);
                const productKey = String(p.id);
                const pModifiedFields = modifiedProductFields[productKey] || [];

                // 일괄 변경 표시
                if (globalState.bulkSalesStatus) {
                    changes.push(
                        G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_sales_status', { status: globalState.bulkSalesStatus })
                        || `Sales status: ${globalState.bulkSalesStatus}`
                    );
                }
                if (globalState.bulkDisplayStatus) {
                    changes.push(
                        G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_display_status', { status: globalState.bulkDisplayStatus })
                        || `Display status: ${globalState.bulkDisplayStatus}`
                    );
                }

                // 인라인 수정 표시 (실제 수정된 필드만 보고)
                if (isProductModified) {
                    if (!globalState.bulkSalesStatus && pModifiedFields.includes('sales_status')) {
                        changes.push(
                            G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_sales_status', { status: p.sales_status })
                            || `Sales status: ${p.sales_status}`
                        );
                    }
                    if (!globalState.bulkDisplayStatus && pModifiedFields.includes('display_status')) {
                        changes.push(
                            G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_display_status', { status: p.display_status })
                            || `Display status: ${p.display_status}`
                        );
                    }
                    if (pModifiedFields.includes('name')) {
                        changes.push(G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_name_changed') || 'Name changed');
                    }
                    if (pModifiedFields.includes('list_price')) {
                        changes.push(
                            G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_list_price', { price: p.list_price?.toLocaleString?.() || p.list_price })
                            || `List price: ${p.list_price?.toLocaleString?.() || p.list_price}`
                        );
                    }
                    if (pModifiedFields.includes('selling_price')) {
                        changes.push(
                            G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_selling_price', { price: p.selling_price?.toLocaleString?.() || p.selling_price })
                            || `Selling price: ${p.selling_price?.toLocaleString?.() || p.selling_price}`
                        );
                    }
                    confirmData.summary.hasInlineChanges = true;
                }

                if (changes.length > 0 || hasBulkChanges || isProductModified) {
                    confirmData.products.push({
                        id: p.id,
                        name: localizeValue(p.name),
                        changes: changes.length > 0 ? changes.join(', ') : (isProductModified
                            ? (G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_inline_modified') || 'Inline modified')
                            : (G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_bulk_applied') || 'Bulk changes applied')),
                    });
                }

                // 옵션 변경 사항 수집 (상품 체크 시 모든 옵션 포함)
                (p.options || []).forEach((opt: any) => {
                    const optionKey = `${p.id}-${opt.id}`;
                    const isOptionModified = opt._modified || modifiedOptionIds.includes(optionKey);

                    const optChanges = collectOptionChanges(opt, optionKey, isOptionModified);

                    if (optChanges.length > 0 || (hasBulkChanges && (globalState.bulkPriceCondition || globalState.bulkStockCondition)) || isOptionModified) {
                        confirmData.options.push({
                            productId: p.id,
                            optionId: opt.id,
                            productName: localizeValue(p.name),
                            optionName: opt.option_name_localized || localizeValue(opt.option_name) || opt.name || '',
                            changes: optChanges.length > 0 ? optChanges.join(', ') : (isOptionModified
                                ? (G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_inline_modified') || 'Inline modified')
                                : (G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_bulk_applied') || 'Bulk changes applied')),
                        });
                    }
                });
            });
    }

    // 체크된 옵션 처리 (체크된 상품에 속하지 않은 옵션만 처리)
    // 상품이 체크된 경우 해당 상품의 옵션은 위에서 이미 처리됨
    if (selectedOptionIds.length > 0) {
        products.forEach((p: any) => {
            // 이미 체크된 상품의 옵션은 건너뜀 (중복 방지)
            if (selectedProductIdSet.has(p.id)) {
                return;
            }

            (p.options || []).forEach((opt: any) => {
                const optionKey = `${p.id}-${opt.id}`;
                if (selectedOptionIds.includes(optionKey)) {
                    const isOptionModified = opt._modified || modifiedOptionIds.includes(optionKey);

                    const optChanges = collectOptionChanges(opt, optionKey, isOptionModified);

                    confirmData.options.push({
                        productId: p.id,
                        optionId: opt.id,
                        productName: localizeValue(p.name),
                        optionName: opt.option_name_localized || localizeValue(opt.option_name) || opt.name || '',
                        changes: optChanges.length > 0 ? optChanges.join(', ') : (isOptionModified
                            ? (G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_inline_modified') || 'Inline modified')
                            : (G7Core.t('sirsoft-ecommerce.admin.product.messages.bulk_summary_selected') || 'Selected')),
                    });
                }
            });
        });
    }

    confirmData.summary.productCount = confirmData.products.length;
    confirmData.summary.optionCount = confirmData.options.length;

    // 글로벌 상태에 확인 데이터 저장
    G7Core.state.set({
        bulkConfirmData: confirmData,
    });

    logger.log('[buildConfirmData] Built confirm data:', confirmData);
}
