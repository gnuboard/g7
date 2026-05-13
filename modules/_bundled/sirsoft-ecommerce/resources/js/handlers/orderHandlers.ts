/**
 * 주문 관리 관련 핸들러
 *
 * 주문 목록 화면에서 사용하는 커스텀 핸들러들을 정의합니다.
 * - 동적 컬럼 생성
 * - 배열 값 토글 (다중 선택 필터)
 * - 선택 상태 동기화
 * - 일괄 처리
 * - 엑셀 다운로드
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:Order')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Order]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Order]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Order]', ...args),
};

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 * ActionDispatcher는 (action, context) 형태로 핸들러를 호출합니다.
 */
interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 통화 정보 인터페이스
 */
interface Currency {
    code: string;
    symbol: string;
    name: string;
    is_default: boolean;
}

/**
 * 컬럼 정의 인터페이스
 */
interface ColumnDef {
    field: string;
    header: string;
    width?: string;
    sortable?: boolean;
    required?: boolean;
}

/**
 * 동적 컬럼 생성 파라미터
 */
interface BuildOrderColumnsParams {
    currencies?: Currency[];
}

/**
 * 주문 목록 DataGrid의 동적 컬럼 정의 생성 핸들러
 *
 * 통화 설정에 따라 금액 컬럼을 동적으로 추가합니다.
 *
 * @example
 * // init_actions에서 사용
 * {
 *   "handler": "sirsoft-ecommerce.buildOrderColumns",
 *   "params": {
 *     "currencies": "{{_global.modules['sirsoft-ecommerce']?.language_currency?.currencies || []}}"
 *   },
 *   "resultTo": {
 *     "target": "_local",
 *     "key": "orderColumns"
 *   }
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @returns 컬럼 정의 배열
 */
export function buildOrderColumnsHandler(
    action: ActionWithParams<BuildOrderColumnsParams>
): ColumnDef[] {
    const { currencies = [] } = action.params || {};

    const baseColumns: ColumnDef[] = [
        { field: 'no', header: 'NO', width: '60px', sortable: false, required: true },
        { field: 'ordered_at', header: '$t:sirsoft-ecommerce.admin.order.column.order_date', width: '140px', sortable: true, required: true },
        { field: 'order_number', header: '$t:sirsoft-ecommerce.admin.order.column.order_number', width: '140px', sortable: true, required: true },
        { field: 'product', header: '$t:sirsoft-ecommerce.admin.order.column.product', width: 'auto', sortable: false, required: true },
        { field: 'order_amount', header: '$t:sirsoft-ecommerce.admin.order.column.order_amount', width: '120px', sortable: true, required: false },
        { field: 'order_status', header: '$t:sirsoft-ecommerce.admin.order.column.order_status', width: '100px', sortable: false, required: false },
        { field: 'orderer', header: '$t:sirsoft-ecommerce.admin.order.column.orderer', width: '120px', sortable: false, required: false },
        { field: 'payment_method', header: '$t:sirsoft-ecommerce.admin.order.column.payment_method', width: '100px', sortable: false, required: false },
        { field: 'delivery_method', header: '$t:sirsoft-ecommerce.admin.order.column.delivery_method', width: '100px', sortable: false, required: false },
        { field: 'country', header: '$t:sirsoft-ecommerce.admin.order.column.country', width: '80px', sortable: false, required: false },
    ];

    // 통화별 금액 컬럼 추가 (기본 통화 제외)
    const currencyColumns: ColumnDef[] = currencies
        .filter(c => !c.is_default)
        .map(currency => ({
            field: `order_amount_${currency.code.toLowerCase()}`,
            header: `${currency.name} (${currency.symbol})`,
            width: '100px',
            sortable: true,
            required: false,
        }));

    return [...baseColumns, ...currencyColumns];
}

/**
 * 배열 값 토글 파라미터
 */
interface ToggleArrayValueParams {
    target?: '_local' | '_global';
    path: string;
    value: string;
}

/**
 * 배열 값 토글 결과
 */
interface ToggleArrayValueResult {
    success: boolean;
    newValue: string[];
}

/**
 * 배열 값 토글 핸들러
 *
 * 다중 선택 필터 (ChipCheckbox, Checkbox)의 배열 값을 토글합니다.
 * 배열에 값이 존재하면 제거하고, 없으면 추가합니다.
 *
 * @example
 * // 주문상태 필터에서 사용
 * {
 *   "type": "change",
 *   "handler": "sirsoft-ecommerce.toggleArrayValue",
 *   "params": {
 *     "target": "_local",
 *     "path": "filter.orderStatus",
 *     "value": "paid"
 *   }
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 * @returns 토글 결과
 */
export function toggleArrayValueHandler(
    action: ActionWithParams<ToggleArrayValueParams>,
    _context: ActionContext
): ToggleArrayValueResult {
    const params = action.params || ({} as ToggleArrayValueParams);
    const { target = '_local', path, value } = params;
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        logger.warn('[toggleArrayValue] G7Core.state를 사용할 수 없습니다.');
        return { success: false, newValue: [] };
    }

    // 현재 배열 값 가져오기
    const currentState = target === '_global' ? G7Core.state.getGlobal() : G7Core.state.getLocal();
    const pathParts = path.split('.');
    let currentArray: string[] = currentState;

    for (const part of pathParts) {
        currentArray = currentArray?.[part];
    }

    if (!Array.isArray(currentArray)) {
        currentArray = [];
    }

    // 토글 로직: 존재하면 제거, 없으면 추가
    let newArray: string[];
    if (currentArray.includes(value)) {
        // 제거
        newArray = currentArray.filter((item: string) => item !== value);
    } else {
        // 추가
        newArray = [...currentArray, value];
    }

    // 상태 업데이트 (dot notation 사용)
    if (target === '_global') {
        G7Core.state.setGlobal({
            [path]: newArray,
        });
    } else {
        G7Core.state.setLocal({
            [path]: newArray,
        });
    }

    return { success: true, newValue: newArray };
}

/**
 * 필터 표시/숨김 토글 파라미터
 */
interface ToggleVisibleFilterParams {
    filterKey: string;
    checked: boolean;
}

/**
 * 필터 표시/숨김 토글 결과
 */
interface ToggleVisibleFilterResult {
    success: boolean;
    newValue: string[];
}

/**
 * 필터 표시/숨김 토글 핸들러
 *
 * 검색 편집 모드에서 필터 기본/상세 영역 전환 시 사용합니다.
 * LocalStorage에도 저장하여 설정을 유지합니다.
 *
 * @example
 * // 편집 모드 체크박스에서 사용
 * {
 *   "type": "change",
 *   "handler": "sirsoft-ecommerce.toggleVisibleFilter",
 *   "params": {
 *     "filterKey": "date",
 *     "checked": "{{$event.target.checked}}"
 *   }
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 * @returns 토글 결과
 */
export function toggleVisibleFilterHandler(
    action: ActionWithParams<ToggleVisibleFilterParams>,
    _context: ActionContext
): ToggleVisibleFilterResult {
    const params = action.params || ({} as ToggleVisibleFilterParams);
    const { filterKey, checked } = params;
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        logger.warn('[toggleVisibleFilter] G7Core.state를 사용할 수 없습니다.');
        return { success: false, newValue: [] };
    }

    // 현재 visibleFilters 가져오기
    const currentLocal = G7Core.state.getLocal();
    const currentFilters: string[] = currentLocal.visibleFilters || [];

    // 새 배열 계산
    let newFilters: string[];
    if (checked) {
        newFilters = currentFilters.includes(filterKey) ? currentFilters : [...currentFilters, filterKey];
    } else {
        newFilters = currentFilters.filter((f: string) => f !== filterKey);
    }

    // 상태 업데이트
    G7Core.state.setLocal({
        visibleFilters: newFilters,
    });

    // LocalStorage에 저장
    try {
        localStorage.setItem('g7_order_filter_visible_filters', JSON.stringify(newFilters));
    } catch (e) {
        logger.warn('[toggleVisibleFilter] LocalStorage 저장 실패:', e);
    }

    return { success: true, newValue: newFilters };
}

/**
 * 주문 선택 동기화 파라미터
 */
interface SyncOrderSelectionParams {
    selectedIds: number[];
}

/**
 * 주문 선택 동기화 결과
 */
interface SyncOrderSelectionResult {
    success: boolean;
}

/**
 * 주문 선택 동기화 핸들러
 *
 * DataGrid 선택 상태를 _local.selectedItems와 동기화합니다.
 *
 * @example
 * // DataGrid onSelectionChange 이벤트에서 사용
 * {
 *   "event": "onSelectionChange",
 *   "type": "change",
 *   "handler": "sirsoft-ecommerce.syncOrderSelection",
 *   "params": {
 *     "selectedIds": "{{$args[0]}}"
 *   }
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 * @returns 동기화 결과
 */
export function syncOrderSelectionHandler(
    action: ActionWithParams<SyncOrderSelectionParams>,
    _context: ActionContext
): SyncOrderSelectionResult {
    const params = action.params || ({} as SyncOrderSelectionParams);
    const { selectedIds = [] } = params;
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        logger.warn('[syncOrderSelection] G7Core.state를 사용할 수 없습니다.');
        return { success: false };
    }

    G7Core.state.setLocal({
        selectedItems: selectedIds,
        selectAll: false, // 개별 선택 시 전체 선택 해제
    });

    return { success: true };
}

/**
 * 행 액션 처리 파라미터
 */
interface HandleOrderRowActionParams {
    actionId: string;
    row: {
        id: number;
        order_number?: string;
        [key: string]: any;
    };
}

/**
 * 행 액션 처리 결과
 */
interface HandleOrderRowActionResult {
    success: boolean;
    action?: string;
    targetId?: number;
}

/**
 * 행 액션 처리 핸들러
 *
 * DataGrid 행 액션 메뉴에서 선택된 액션을 처리합니다.
 * 주로 switch 핸들러와 함께 사용됩니다.
 *
 * @example
 * // DataGrid onRowAction 이벤트에서 switch와 함께 사용
 * {
 *   "event": "onRowAction",
 *   "handler": "switch",
 *   "cases": {
 *     "view": { "handler": "navigate", "params": { "path": "/admin/ecommerce/orders/{{$args[1].order_number}}" } }
 *   }
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 * @returns 처리 결과
 */
export function handleOrderRowActionHandler(
    action: ActionWithParams<HandleOrderRowActionParams>,
    _context: ActionContext
): HandleOrderRowActionResult {
    const params = action.params || ({} as HandleOrderRowActionParams);
    const { actionId, row } = params;
    const G7Core = (window as any).G7Core;

    if (!actionId || !row?.order_number) {
        logger.warn('[handleOrderRowAction] actionId 또는 row.order_number가 없습니다.');
        return { success: false };
    }

    // 액션에 따른 처리
    switch (actionId) {
        case 'view':
            G7Core?.navigate?.(`/admin/ecommerce/orders/${row.order_number}`);
            break;

        case 'edit':
            G7Core?.navigate?.(`/admin/ecommerce/orders/${row.order_number}`);
            break;

        case 'status':
        case 'shipping':
        case 'memo':
        case 'delete':
            // 모달 열기가 필요한 액션은 상태 설정만 하고 레이아웃에서 처리
            G7Core?.state?.setGlobal({
                orderList: {
                    currentActionOrderId: row.id,
                    currentActionType: actionId,
                },
            });
            break;

        case 'print':
            // 새 창에서 배송장 출력
            window.open(`/admin/ecommerce/orders/${row.order_number}/shipping-label`, '_blank');
            break;

        default:
            console.warn(`[handleOrderRowAction] 알 수 없는 액션: ${actionId}`);
            return { success: false };
    }

    return {
        success: true,
        action: actionId,
        targetId: row.id,
    };
}

/**
 * 일괄 처리 실행 파라미터
 */
interface ProcessOrderBulkActionParams {
    actionType: 'status' | 'shipping';
    orderIds: number[];
    status?: string;
    carrierId?: number;
    trackingNumber?: string;
}

/**
 * 일괄 처리 실행 결과
 */
interface ProcessOrderBulkActionResult {
    success: boolean;
    successCount: number;
    failCount: number;
    errors: Array<{ id: number; message: string }>;
}

/**
 * 일괄 처리 실행 핸들러
 *
 * 일괄 상태 변경, 일괄 발송처리 등 일괄 작업을 실행합니다.
 *
 * @example
 * // 일괄 상태 변경
 * {
 *   "type": "click",
 *   "handler": "sequence",
 *   "actions": [
 *     {
 *       "handler": "sirsoft-ecommerce.processOrderBulkAction",
 *       "params": {
 *         "actionType": "status",
 *         "orderIds": "{{_local.selectedItems}}",
 *         "status": "{{_local.bulkOrderStatus}}"
 *       }
 *     }
 *   ]
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 * @returns 처리 결과
 */
export async function processOrderBulkActionHandler(
    action: ActionWithParams<ProcessOrderBulkActionParams>,
    _context: ActionContext
): Promise<ProcessOrderBulkActionResult> {
    const params = action.params || ({} as ProcessOrderBulkActionParams);
    const { actionType, orderIds, status, carrierId, trackingNumber } = params;
    const G7Core = (window as any).G7Core;

    if (!orderIds || orderIds.length === 0) {
        throw new Error('선택된 주문이 없습니다.');
    }

    let endpoint = '';
    const body: Record<string, unknown> = { ids: orderIds };

    switch (actionType) {
        case 'status':
            if (!status) {
                throw new Error('변경할 상태를 선택해주세요.');
            }
            endpoint = '/api/modules/sirsoft-ecommerce/admin/orders/bulk';
            body.order_status = status;
            break;

        case 'shipping':
            if (!trackingNumber && !carrierId) {
                throw new Error('택배사 또는 운송장번호를 입력해주세요.');
            }
            endpoint = '/api/modules/sirsoft-ecommerce/admin/orders/bulk';
            if (carrierId) body.carrier_id = carrierId;
            if (trackingNumber) body.tracking_number = trackingNumber;
            break;

        default:
            throw new Error(`알 수 없는 액션 타입: ${actionType}`);
    }

    // API 호출
    const response = await G7Core?.api?.call(endpoint, {
        method: 'PATCH',
        body,
    });

    if (!response?.success) {
        throw new Error(response?.message || '일괄 처리에 실패했습니다.');
    }

    return {
        success: true,
        successCount: response.data?.updated_count ?? orderIds.length,
        failCount: response.data?.fail_count ?? 0,
        errors: response.data?.errors ?? [],
    };
}

/**
 * 엑셀 다운로드 파라미터
 */
interface DownloadOrderExcelParams {
    downloadType: 'search' | 'selected';
    orderIds?: number[];
    filters?: Record<string, unknown>;
}

/**
 * 엑셀 다운로드 결과
 */
interface DownloadOrderExcelResult {
    success: boolean;
}

/**
 * 엑셀 다운로드 핸들러
 *
 * 주문 목록을 엑셀 파일로 다운로드합니다.
 *
 * @example
 * // 엑셀 다운로드 모달에서 사용
 * {
 *   "type": "click",
 *   "handler": "sequence",
 *   "actions": [
 *     {
 *       "handler": "sirsoft-ecommerce.downloadOrderExcel",
 *       "params": {
 *         "downloadType": "{{_local.excelDownloadType}}",
 *         "orderIds": "{{_global.orderList.selectedItemsForExcel}}",
 *         "filters": "{{_local.filter}}"
 *       }
 *     },
 *     { "handler": "closeModal" }
 *   ]
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 * @returns 다운로드 결과
 */
export async function downloadOrderExcelHandler(
    action: ActionWithParams<DownloadOrderExcelParams>,
    _context: ActionContext
): Promise<DownloadOrderExcelResult> {
    const params = action.params || ({} as DownloadOrderExcelParams);
    const { downloadType, orderIds, filters } = params;
    const G7Core = (window as any).G7Core;

    const endpoint = '/api/modules/sirsoft-ecommerce/admin/orders/export';
    const body: Record<string, unknown> = {};

    if (downloadType === 'selected') {
        if (!orderIds || orderIds.length === 0) {
            throw new Error('선택된 주문이 없습니다.');
        }
        body.ids = orderIds;
    } else {
        body.filters = filters || {};
    }

    // Blob 다운로드 처리
    const token = G7Core?.auth?.getToken();
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const errorData = await response.json().catch(() => null);
        throw new Error(errorData?.message || '엑셀 다운로드에 실패했습니다.');
    }

    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;

    // 파일명 생성 (YYYY-MM-DD 형식)
    const today = new Date();
    const dateStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    a.download = `orders_${dateStr}.xlsx`;

    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);

    return { success: true };
}

/**
 * 컬럼 표시/숨김 설정 저장 파라미터
 */
interface SaveVisibleColumnsParams {
    columns: string[];
}

/**
 * 컬럼 표시/숨김 설정 저장 핸들러
 *
 * DataGrid의 표시 컬럼 설정을 LocalStorage에 저장합니다.
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 * @returns 저장 결과
 */
export function saveVisibleColumnsHandler(
    action: ActionWithParams<SaveVisibleColumnsParams>,
    _context: ActionContext
): { success: boolean } {
    const params = action.params || ({} as SaveVisibleColumnsParams);
    const { columns = [] } = params;

    try {
        localStorage.setItem('g7_order_visible_columns', JSON.stringify(columns));
    } catch (e) {
        logger.warn('[saveVisibleColumns] LocalStorage 저장 실패:', e);
        return { success: false };
    }

    return { success: true };
}

/**
 * 컬럼 표시/숨김 설정 로드 핸들러
 *
 * LocalStorage에서 표시 컬럼 설정을 로드합니다.
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 * @returns 저장된 컬럼 목록 또는 기본값
 */
export function loadVisibleColumnsHandler(
    action: ActionWithParams<{ defaultColumns?: string[] }>,
    _context: ActionContext
): string[] {
    const params = action.params || {};
    const defaultColumns = params.defaultColumns || [
        'order_date',
        'order_number',
        'order_status',
        'order_amount',
        'product',
        'orderer',
        'payment_method',
        'delivery_method',
        'country',
    ];

    try {
        const saved = localStorage.getItem('g7_order_visible_columns');
        if (saved) {
            const parsed = JSON.parse(saved);
            if (Array.isArray(parsed)) {
                return parsed;
            }
        }
    } catch (e) {
        logger.warn('[loadVisibleColumns] LocalStorage 로드 실패:', e);
    }

    return defaultColumns;
}

/**
 * 필터 표시/숨김 설정 로드 파라미터
 */
interface LoadVisibleFiltersParams {
    /** 필터 ID (localStorage 키 생성에 사용) */
    id: string;
    /** 기본 표시 필터 목록 */
    defaultFilters?: string[];
}

/**
 * 필터 표시/숨김 설정 로드 핸들러
 *
 * LocalStorage에서 표시 필터 설정을 로드합니다.
 * FilterVisibilitySelector와 동일한 localStorage 키 패턴(g7_filters_{id}_{userId})을 사용합니다.
 *
 * @example
 * // init_actions에서 사용
 * {
 *   "handler": "sirsoft-ecommerce.loadVisibleFilters",
 *   "params": {
 *     "id": "admin_ecommerce_order_filters",
 *     "defaultFilters": ["searchField", "searchKeyword", "date", "orderStatus"]
 *   },
 *   "resultTo": {
 *     "target": "_local",
 *     "key": "visibleFilters"
 *   }
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 * @returns 저장된 필터 목록 또는 기본값
 */
export function loadVisibleFiltersHandler(
    action: ActionWithParams<LoadVisibleFiltersParams>,
    _context: ActionContext
): string[] {
    const params = action.params || {} as LoadVisibleFiltersParams;
    const { id, defaultFilters = [] } = params;
    const G7Core = (window as any).G7Core;

    if (!id) {
        logger.warn('[loadVisibleFilters] id 파라미터가 필요합니다.');
        return defaultFilters;
    }

    // 사용자 ID 가져오기 (FilterVisibilitySelector와 동일한 로직)
    let userId: string | undefined;
    try {
        const authManager = G7Core?.AuthManager?.getInstance();
        if (authManager) {
            const user = authManager.getUser();
            userId = user?.uuid;
        }
    } catch {
        // 인증 정보 없음 - 공유 설정 사용
    }

    // localStorage 키 생성 (FilterVisibilitySelector와 동일한 패턴)
    const storageKey = `g7_filters_${id}${userId !== undefined ? `_${userId}` : ''}`;

    try {
        const saved = localStorage.getItem(storageKey);
        if (saved) {
            const parsed = JSON.parse(saved);
            if (Array.isArray(parsed) && parsed.length > 0) {
                logger.log(`[loadVisibleFilters] localStorage에서 로드: ${storageKey}`, parsed);
                return parsed;
            }
        }
    } catch (e) {
        logger.warn('[loadVisibleFilters] LocalStorage 로드 실패:', e);
    }

    logger.log(`[loadVisibleFilters] 기본값 사용: ${storageKey}`, defaultFilters);
    return defaultFilters;
}

/**
 * 주문 일괄 변경 확인 데이터 빌드 핸들러
 *
 * 상태 미선택 시 toast 경고, 정상 시 _global에 bulkConfirmData 저장 후 모달 열기.
 * 상품관리의 buildConfirmData 패턴과 일관됨.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.buildOrderBulkConfirmData"
 * }
 */
export function buildOrderBulkConfirmDataHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core) return;

    const globalState = G7Core.state.get() || {};

    const selectedItems: number[] = globalState.bulkSelectedItems || [];
    const bulkOrderStatus: string = globalState.bulkOrderStatus || '';
    const bulkCourier: string = globalState.bulkCourier || '';
    const bulkTrackingNumber: string = globalState.bulkTrackingNumber || '';

    // 검증: 상태 미선택 시 경고
    if (!bulkOrderStatus) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.no_status_selected')
            || '변경할 주문상태를 선택해주세요.'
        );
        return;
    }

    // 검증: 배송 관련 상태 선택 시 택배사/송장번호 필수
    const shippingStatuses = ['shipping_ready', 'shipping'];
    if (shippingStatuses.includes(bulkOrderStatus)) {
        if (!bulkCourier || !bulkTrackingNumber) {
            G7Core.toast?.warning?.(
                G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.carrier_required')
                || '해당 상태로 변경하려면 택배사와 송장번호를 모두 입력해주세요.'
            );
            return;
        }
    }

    // _global에 확인 데이터 저장
    G7Core.state.set({
        bulkConfirmData: {
            selectedItems,
            orderStatus: bulkOrderStatus,
            courierId: bulkCourier,
            trackingNumber: bulkTrackingNumber,
        },
    });

    // 모달 열기
    G7Core.modal?.open?.('modal_bulk_confirm');
}

/**
 * 주문 일괄 변경 실행 핸들러
 *
 * 모달 확인 버튼에서 호출. _global.bulkConfirmData를 읽어 API 호출 후
 * 상태 리셋, DataSource 리프레시, 모달 닫기를 수행합니다.
 * 상품관리의 bulkUpdate 핸들러와 동일한 패턴.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.executeOrderBulkAction"
 * }
 */
export async function executeOrderBulkActionHandler(
    _action: ActionWithParams,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core) return;

    const globalState = G7Core.state.get() || {};
    const confirmData = globalState.bulkConfirmData;

    if (!confirmData || !confirmData.selectedItems?.length) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.error')
            || '일괄 처리에 실패했습니다.'
        );
        return;
    }

    const body: Record<string, unknown> = {
        ids: confirmData.selectedItems,
        order_status: confirmData.orderStatus,
    };
    if (confirmData.courierId) {
        body.carrier_id = confirmData.courierId;
    }
    if (confirmData.trackingNumber) {
        body.tracking_number = confirmData.trackingNumber;
    }

    try {
        await G7Core.api.patch(
            '/api/modules/sirsoft-ecommerce/admin/orders/bulk',
            body
        );

        G7Core.toast?.success?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.success')
            || '일괄 변경이 완료되었습니다.'
        );

        // 상태 초기화
        G7Core.state.setLocal({
            selectedItems: [],
            selectAll: false,
            bulkOrderStatus: '',
            bulkCourier: '',
            bulkTrackingNumber: '',
        });
        G7Core.state.set({
            bulkConfirmData: null,
        });

        // DataSource 리프레시
        G7Core.dataSource?.refetch?.('orders');

        // 모달 닫기
        G7Core.modal?.close?.();
    } catch (error: any) {
        logger.error('[executeOrderBulkAction] Error:', error);
        G7Core.toast?.error?.(
            error?.message
            || G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.error')
            || '일괄 처리에 실패했습니다.'
        );
    }
}
