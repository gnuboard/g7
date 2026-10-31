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
import { manualPaidRequestFields, refreshManualPaidPreview, resetManualPaidState, SALES_STATUSES } from './manualPaidEffectsHandlers';

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
 * 배선 현황: **현재 이 핸들러를 호출하는 레이아웃은 없다.** 주문 목록 DataGrid 는 컬럼을
 * `_partial_order_datagrid.json` 에 직접 열거하며, 그 정의만이 셀 내용(`cellChildren`)을
 * 갖는다. (형제 `saveVisibleColumnsHandler`·`loadVisibleColumnsHandler` 는 #639 부터 주문 목록이 쓴다.)
 * 나중에 이 핸들러를 배선하려면 레이아웃 컬럼 정의의
 * `cellChildren` 을 함께 가져오는 경로부터 만들어야 한다 — 아래 claim 컬럼 주석 참조.
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
        // 형제 `loadVisibleColumnsHandler` 의 폴백은 device·claim 을 포함한 11키다. 여기만
        // 10키로 남으면 두 핸들러를 함께 쓰는 화면에서 표시 목록이 정의 없는 field 를 가리키고,
        // DataGrid 는 정의가 없는 컬럼을 그리지 않으므로 그 두 컬럼이 조용히 사라진다.
        { field: 'device', header: '$t:sirsoft-ecommerce.admin.order.column.device', width: '80px', sortable: false, required: false },
        // claim 컬럼에는 여기서 `cellChildren` 을 주지 않는다 — `ColumnDef` 는 field/header/
        // width/sortable/required 만 갖는 타입이고, 이 핸들러는 그 다섯 축만 다룬다.
        // 셀 내용(클레임 유형별 최신 상태 배지 + 2건 이상일 때 건수 병기)은 레이아웃
        // `_partial_order_datagrid.json` 의 claim 컬럼 정의에만 있으며, 그것이 유일한 정본이다.
        // 이 핸들러를 실제로 배선하게 되면(아래 "미배선" 주석 참조) 그 `cellChildren` 을
        // 여기로 함께 옮기거나, 레이아웃 컬럼 정의와 병합하는 경로를 먼저 만들어야 한다.
        // 그렇지 않으면 claim 컬럼이 헤더만 있고 전 행이 빈 셀이 된다.
        { field: 'claim', header: '$t:sirsoft-ecommerce.admin.order.column.claim', width: '150px', sortable: false, required: false },
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
 * 컬럼 표시/숨김 설정 저장 파라미터
 */
interface SaveVisibleColumnsParams {
    columns: string[];
}

/** 회원 구분 없이 쓰던 옛 저장 키 (#639 ⑩ 이전) */
const LEGACY_VISIBLE_COLUMNS_KEY = 'g7_order_visible_columns';

/**
 * 주문 목록 표시 컬럼의 저장 키를 구합니다 (#639 ⑩).
 *
 * 표시 컬럼은 관리자의 업무 설정이라 회원마다 갈린다 — 같은 브라우저의 다른 관리자에게 앞 사람의 설정이 실리면 안 된다.
 * 형식은 코어 `ColumnSelector` 와 같이 키 끝에 회원 uuid 를 붙인다. 회원을 모르면 null(저장하지 않음).
 *
 * @returns 저장 키 또는 null
 */
function visibleColumnsKey(): string | null {
    try {
        const uuid = (window as any).G7Core?.AuthManager?.getInstance?.()?.getUser?.()?.uuid;
        return typeof uuid === 'string' && uuid !== '' ? `${LEGACY_VISIBLE_COLUMNS_KEY}_${uuid}` : null;
    } catch {
        return null;
    }
}

/**
 * 회원 구분 없는 옛 값을 지웁니다 — 누구 것인지 가를 수 없으므로 읽지 않는다.
 */
function discardLegacyVisibleColumns(): void {
    try {
        localStorage.removeItem(LEGACY_VISIBLE_COLUMNS_KEY);
    } catch {
        // 지우지 못해도 읽지 않으므로 다른 관리자에게 실리지 않는다.
    }
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
    const key = visibleColumnsKey();

    discardLegacyVisibleColumns();
    if (key === null) {
        return { success: false };
    }

    try {
        localStorage.setItem(key, JSON.stringify(columns));
    } catch (e) {
        logger.warn('[saveVisibleColumns] LocalStorage 저장 실패:', e);
        return { success: false };
    }

    return { success: true };
}

/**
 * 컬럼 표시/숨김 설정 로드 핸들러
 *
 * LocalStorage에서 지금 관리자의 표시 컬럼 설정을 로드합니다 (#639 ⑩ — 회원 uuid 키).
 *
 * 주문 목록 초기화(`admin_ecommerce_order_list` 의 init)가 이 핸들러로 읽는다. 예전처럼 레이아웃이
 * `loadFromLocalStorage` 로 공용 키를 직접 읽으면 다른 관리자의 설정이 실린다. 회원 구분 없는 옛 값은 지운다.
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
    // 주문 목록 DataGrid 의 실제 컬럼 field 와 같은 묶음이어야 한다. `order_date` 는 존재하지
    // 않는 field 였고(실제 키는 `ordered_at`) `device`·`claim` 이 빠져 있었다 — 이 폴백을 쓰는
    // 레이아웃이 생기는 순간 그 컬럼들이 조용히 사라진다(DataGrid 는 visibleColumns 에 없는
    // 컬럼을 required 여부와 무관하게 걸러낸다). 레이아웃 3출처(state.visibleColumns /
    // loadFromLocalStorage.defaultValue / ColumnSelector.defaultColumns)와 같은 11키로 맞춘다.
    const defaultColumns = params.defaultColumns || [
        'ordered_at',
        'order_number',
        'product',
        'order_amount',
        'order_status',
        'orderer',
        'payment_method',
        'delivery_method',
        'country',
        'device',
        'claim',
    ];

    discardLegacyVisibleColumns();
    const key = visibleColumnsKey();
    if (key === null) {
        return defaultColumns;
    }

    try {
        const saved = localStorage.getItem(key);
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
export async function buildOrderBulkConfirmDataHandler(
    _action: ActionWithParams,
    _context: ActionContext
): Promise<void> {
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

    // 수동 결제 처리 항목(Q13) — 창을 열 때마다 세 체크를 모두 켠 채 다시 시작한다.
    resetManualPaidState();

    // 모달 열기
    G7Core.modal?.open?.('modal_bulk_confirm');

    // 창을 먼저 띄우고 그 안에서 미리보기를 불러온다(클레임 완료 모달과 같은 순서).
    // 판매 상태로 가는 변경만 대상이다 — 결제 전·취소 주문이 섞여 있는지는 서버가 판정한다.
    if (SALES_STATUSES.includes(bulkOrderStatus)) {
        await refreshManualPaidPreview({
            kind: 'orders',
            body: {
                ids: selectedItems,
                order_status: bulkOrderStatus,
                ...(bulkCourier ? { carrier_id: bulkCourier } : {}),
                ...(bulkTrackingNumber ? { tracking_number: bulkTrackingNumber } : {}),
            },
        });
    }
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
    // 확인 창에서 고른 세 항목 — 판매 상태로 가는 요청에만 싣는다(서버는 미지정을 「켬」으로 본다).
    Object.assign(body, manualPaidRequestFields(confirmData.orderStatus));

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
            manualPaidPreview: null,
        });

        // DataSource 리프레시
        G7Core.dataSource?.refetch?.('orders');

        // 모달 닫기
        G7Core.modal?.close?.();
    } catch (error: any) {
        logger.error('[executeOrderBulkAction] Error:', error);
        // 서버 422 검증 메시지(상태 전이 차단 사유 등)를 우선 노출한다.
        // axios 는 error.message 에 "Request failed with status code 422" 같은 raw 문구만 담으므로
        // 실제 사유가 들어있는 error.response.data.message 를 먼저 추출한다(confirmDeposit 동일 패턴).
        // 5xx 본문(`Server Error`)과 전송 실패·본문 없는 응답의 axios 원문(`Request failed with status code 500`·
        // `Network Error`)은 사유가 아니다 — 띄우면 운영자가 영문 내부 문구를 읽는다(S1-D12). 번역된 폴백으로 넘긴다.
        const errorData = error?.response?.data || error?.data || {};
        const status = Number(error?.response?.status ?? error?.status ?? 0);
        const serverMessage = status >= 500 ? null : (typeof errorData?.message === 'string' && errorData.message !== '' ? errorData.message : null);
        G7Core.toast?.error?.(
            serverMessage
            || G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.error')
            || '일괄 처리에 실패했습니다.'
        );
        // 실패를 삼키면 확인 창의 성공 분기(`<close-modal/>`)가 돌아 창이 닫히고 고른 처리 항목이 사라진다.
        // 다시 던져 레이아웃 `<fail>` 분기(창 유지)로 넘긴다 — 사유 토스트는 여기서 한 번만 띄운다.
        throw error;
    }
}
