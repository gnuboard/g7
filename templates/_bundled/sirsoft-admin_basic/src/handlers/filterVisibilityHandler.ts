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

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:FilterVisibility')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:FilterVisibility]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:FilterVisibility]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:FilterVisibility]', ...args),
};

/**
 * localStorage 키 접두사
 */
const STORAGE_PREFIX = 'g7_filter_visibility_';

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
export async function initFilterVisibilityHandler(
  action: {
    params?: {
      /** localStorage 키 (페이지별로 구분) */
      storageKey?: string;
      /** 기본 표시 필터 목록 */
      defaultFilters?: string[];
      /** 상태 저장 키 (_local.xxx 형태) */
      stateKey?: string;
    };
  },
  context?: {
    setState?: (updates: Record<string, any>) => void;
  }
): Promise<void> {
  const { storageKey, defaultFilters = [], stateKey = 'visibleFilters' } = action?.params || {};

  if (!storageKey) {
    logger.warn('[initFilterVisibility] storageKey is required');
    return;
  }

  const fullKey = `${STORAGE_PREFIX}${storageKey}`;

  try {
    // localStorage에서 저장된 상태 조회
    const savedData = localStorage.getItem(fullKey);

    let visibleFilters: string[];

    if (savedData) {
      // 저장된 데이터가 있으면 파싱
      const parsed = JSON.parse(savedData);
      if (Array.isArray(parsed)) {
        visibleFilters = parsed;
        logger.log(`[initFilterVisibility] Loaded from localStorage: ${visibleFilters.join(', ')}`);
      } else {
        visibleFilters = defaultFilters;
        logger.warn('[initFilterVisibility] Invalid saved data format, using defaults');
      }
    } else {
      // 저장된 데이터가 없으면 기본값 사용
      visibleFilters = defaultFilters;
      logger.log(`[initFilterVisibility] No saved data, using defaults: ${defaultFilters.join(', ')}`);
    }

    // 로컬 상태에 설정
    if (context?.setState) {
      context.setState({
        [stateKey]: visibleFilters,
      });
    }
  } catch (error) {
    logger.error('[initFilterVisibility] Failed to load filter visibility:', error);
    // 오류 발생 시 기본값 사용
    if (context?.setState) {
      context.setState({
        [stateKey]: defaultFilters,
      });
    }
  }
}

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
export async function saveFilterVisibilityHandler(
  action: {
    params?: {
      /** localStorage 키 (페이지별로 구분) */
      storageKey?: string;
      /** 저장할 필터 목록 */
      filters?: string[];
    };
  },
  _context?: any
): Promise<void> {
  const { storageKey, filters } = action?.params || {};

  if (!storageKey) {
    logger.warn('[saveFilterVisibility] storageKey is required');
    return;
  }

  if (!Array.isArray(filters)) {
    logger.warn('[saveFilterVisibility] filters must be an array');
    return;
  }

  const fullKey = `${STORAGE_PREFIX}${storageKey}`;

  try {
    localStorage.setItem(fullKey, JSON.stringify(filters));
    logger.log(`[saveFilterVisibility] Saved to localStorage: ${filters.join(', ')}`);
  } catch (error) {
    logger.error('[saveFilterVisibility] Failed to save filter visibility:', error);
  }
}

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
export async function toggleFilterVisibilityHandler(
  action: {
    params?: {
      /** localStorage 키 */
      storageKey?: string;
      /** 토글할 필터 ID */
      filterId?: string;
      /** 상태 저장 키 */
      stateKey?: string;
    };
  },
  context?: {
    state?: Record<string, any>;
    setState?: (updates: Record<string, any>) => void;
  }
): Promise<void> {
  const { storageKey, filterId, stateKey = 'visibleFilters' } = action?.params || {};

  if (!storageKey || !filterId) {
    logger.warn('[toggleFilterVisibility] storageKey and filterId are required');
    return;
  }

  const currentFilters: string[] = context?.state?.[stateKey] || [];
  let newFilters: string[];

  if (currentFilters.includes(filterId)) {
    // 필터 제거
    newFilters = currentFilters.filter(f => f !== filterId);
  } else {
    // 필터 추가
    newFilters = [...currentFilters, filterId];
  }

  // 상태 업데이트
  if (context?.setState) {
    context.setState({
      [stateKey]: newFilters,
    });
  }

  // localStorage 저장
  const fullKey = `${STORAGE_PREFIX}${storageKey}`;
  try {
    localStorage.setItem(fullKey, JSON.stringify(newFilters));
    logger.log(`[toggleFilterVisibility] Toggled ${filterId}: ${newFilters.join(', ')}`);
  } catch (error) {
    logger.error('[toggleFilterVisibility] Failed to save:', error);
  }
}

/**
 * 필터 가시성 초기화 핸들러
 *
 * 저장된 필터 가시성을 기본값으로 리셋합니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 */
export async function resetFilterVisibilityHandler(
  action: {
    params?: {
      /** localStorage 키 */
      storageKey?: string;
      /** 기본 필터 목록 */
      defaultFilters?: string[];
      /** 상태 저장 키 */
      stateKey?: string;
    };
  },
  context?: {
    setState?: (updates: Record<string, any>) => void;
  }
): Promise<void> {
  const { storageKey, defaultFilters = [], stateKey = 'visibleFilters' } = action?.params || {};

  if (!storageKey) {
    logger.warn('[resetFilterVisibility] storageKey is required');
    return;
  }

  const fullKey = `${STORAGE_PREFIX}${storageKey}`;

  try {
    // localStorage에 기본값 저장
    localStorage.setItem(fullKey, JSON.stringify(defaultFilters));

    // 상태 업데이트
    if (context?.setState) {
      context.setState({
        [stateKey]: defaultFilters,
      });
    }

    logger.log(`[resetFilterVisibility] Reset to defaults: ${defaultFilters.join(', ')}`);
  } catch (error) {
    logger.error('[resetFilterVisibility] Failed to reset:', error);
  }
}
