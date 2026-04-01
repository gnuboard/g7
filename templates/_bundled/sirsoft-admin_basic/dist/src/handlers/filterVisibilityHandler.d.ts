/**
 * filterVisibilityHandler.ts
 *
 * 검색 필터 가시성 상태를 localStorage에 저장하고 복원하는 핸들러입니다.
 * 사용자가 검색 편집 모드에서 설정한 필터 표시/숨김 상태를 유지합니다.
 *
 * @module filterVisibilityHandler
 * @since v1.10.0
 *
 * @example
 * ```json
 * {
 *   "init_actions": [
 *     {
 *       "handler": "initFilterVisibility",
 *       "params": {
 *         "storageKey": "product_index_filters",
 *         "defaultFilters": ["category", "date", "sales_status", "display_status"],
 *         "stateKey": "visibleFilters"
 *       }
 *     }
 *   ]
 * }
 * ```
 */
/**
 * 필터 가시성 초기화 핸들러
 *
 * 페이지 로드 시 localStorage에서 저장된 필터 가시성 상태를 복원합니다.
 * 저장된 상태가 없으면 defaultFilters를 사용합니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 *
 * @example
 * ```json
 * {
 *   "handler": "initFilterVisibility",
 *   "params": {
 *     "storageKey": "product_index_filters",
 *     "defaultFilters": ["category", "date"],
 *     "stateKey": "visibleFilters"
 *   }
 * }
 * ```
 */
export declare function initFilterVisibilityHandler(action: {
    params?: {
        /** localStorage 키 (페이지별로 구분) */
        storageKey?: string;
        /** 기본 표시 필터 목록 */
        defaultFilters?: string[];
        /** 상태 저장 키 (_local.xxx 형태) */
        stateKey?: string;
    };
}, context?: {
    setState?: (updates: Record<string, any>) => void;
}): Promise<void>;
/**
 * 필터 가시성 저장 핸들러
 *
 * 필터 가시성 상태가 변경될 때 localStorage에 저장합니다.
 * 체크박스 토글 시 호출됩니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 *
 * @example
 * ```json
 * {
 *   "handler": "saveFilterVisibility",
 *   "params": {
 *     "storageKey": "product_index_filters",
 *     "filters": "{{_local.visibleFilters}}"
 *   }
 * }
 * ```
 */
export declare function saveFilterVisibilityHandler(action: {
    params?: {
        /** localStorage 키 (페이지별로 구분) */
        storageKey?: string;
        /** 저장할 필터 목록 */
        filters?: string[];
    };
}, _context?: any): Promise<void>;
/**
 * 필터 가시성 토글 핸들러
 *
 * 특정 필터의 가시성을 토글하고 즉시 localStorage에 저장합니다.
 * 검색 편집 모드에서 체크박스 클릭 시 사용합니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 *
 * @example
 * ```json
 * {
 *   "handler": "toggleFilterVisibility",
 *   "params": {
 *     "storageKey": "product_index_filters",
 *     "filterId": "category",
 *     "stateKey": "visibleFilters"
 *   }
 * }
 * ```
 */
export declare function toggleFilterVisibilityHandler(action: {
    params?: {
        /** localStorage 키 */
        storageKey?: string;
        /** 토글할 필터 ID */
        filterId?: string;
        /** 상태 저장 키 */
        stateKey?: string;
    };
}, context?: {
    state?: Record<string, any>;
    setState?: (updates: Record<string, any>) => void;
}): Promise<void>;
/**
 * 필터 가시성 초기화 핸들러
 *
 * 저장된 필터 가시성을 기본값으로 리셋합니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 */
export declare function resetFilterVisibilityHandler(action: {
    params?: {
        /** localStorage 키 */
        storageKey?: string;
        /** 기본 필터 목록 */
        defaultFilters?: string[];
        /** 상태 저장 키 */
        stateKey?: string;
    };
}, context?: {
    setState?: (updates: Record<string, any>) => void;
}): Promise<void>;
