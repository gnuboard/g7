import React, { useEffect, useMemo, useState, useCallback } from 'react';

/**
 * 그누보드7 Core 에서 이 컴포넌트가 쓰는 부분만 적은 모양.
 * 전역 Window 를 넓히지 않는다 — 코어의 Window.G7Core 선언과 합쳐지면 코어 쪽 속성(TemplateEngine 등)이 가려진다(#869).
 */
interface G7CoreLike {
  createLogger?: (name: string) => {
    log: (...args: unknown[]) => void;
    warn: (...args: unknown[]) => void;
    error: (...args: unknown[]) => void;
  };
  AuthManager?: { getInstance: () => AuthManagerLike };
}

/** 그누보드7 Core 의 AuthManager 인스턴스에서 이 컴포넌트가 쓰는 부분 */
interface AuthManagerLike {
  getUser: () => { uuid: string; [key: string]: any } | null;
}

/**
 * window.G7Core 를 이 파일의 지역 타입으로 읽는다.
 *
 * @returns 코어 전역 객체, 코어가 아직 없으면 undefined
 */
const G7Core = (): G7CoreLike | undefined => (window as unknown as { G7Core?: G7CoreLike }).G7Core;

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = G7Core()?.createLogger?.('Comp:FilterVisibility') ?? {
    log: (...args: unknown[]) => console.log('[Comp:FilterVisibility]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:FilterVisibility]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:FilterVisibility]', ...args),
};

export interface FilterVisibilitySelectorProps {
  /** 컴포넌트 고유 ID (localStorage 키 생성에 사용) */
  id: string;
  /** 현재 선택된 필터 목록 */
  visibleFilters?: string[];
  /** 기본 표시 필터 (localStorage에 값이 없을 때 사용) */
  defaultFilters?: string[];
  /** 필터 가시성 변경 시 호출되는 콜백 */
  onFilterVisibilityChange?: (visibleFilters: string[]) => void;
}

/**
 * FilterVisibilitySelector 집합 컴포넌트
 *
 * 검색 필터의 표시/숨김 설정을 관리하고 localStorage에 저장합니다.
 * 페이지 로드 시 자동으로 localStorage에서 설정을 불러와 부모 컴포넌트에 전달합니다.
 *
 * 이 컴포넌트는 UI를 렌더링하지 않고, 상태 관리 역할만 수행합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "type": "composite",
 *   "name": "FilterVisibilitySelector",
 *   "props": {
 *     "id": "admin_product_list_filters",
 *     "visibleFilters": "{{_local.visibleFilters}}",
 *     "defaultFilters": ["category", "date", "salesStatus", "displayStatus"]
 *   },
 *   "actions": [
 *     {
 *       "event": "onFilterVisibilityChange",
 *       "type": "change",
 *       "handler": "setState",
 *       "params": {
 *         "target": "_local",
 *         "visibleFilters": "{{$args[0]}}"
 *       }
 *     }
 *   ]
 * }
 */
export const FilterVisibilitySelector: React.FC<FilterVisibilitySelectorProps> = ({
  id,
  visibleFilters: externalVisibleFilters,
  defaultFilters = [],
  onFilterVisibilityChange,
}) => {
  const [isInitialized, setIsInitialized] = useState(false);
  // 초기화 완료 후 첫 번째 저장을 건너뛰기 위한 ref
  const skipNextSaveRef = React.useRef(true);

  // 그누보드7 Core의 AuthManager를 통해 현재 로그인한 사용자 ID 가져오기
  const userId = useMemo(() => {
    try {
      const authManager = G7Core()?.AuthManager?.getInstance();
      if (authManager) {
        const user = authManager.getUser();
        return user?.uuid;
      }
      return undefined;
    } catch {
      return undefined;
    }
  }, []);

  // localStorage 키 생성 (g7_filters_{id}_{userId} 형식)
  const storageKey = useMemo(
    () => `g7_filters_${id}${userId !== undefined ? `_${userId}` : ''}`,
    [id, userId]
  );

  // localStorage에서 저장된 필터 설정 불러오기
  const getStoredFilters = useCallback((): string[] | null => {
    try {
      const stored = localStorage.getItem(storageKey);
      if (stored) {
        const parsed = JSON.parse(stored);
        if (Array.isArray(parsed)) {
          return parsed;
        }
      }
    } catch (error) {
      logger.warn('Failed to load from localStorage:', error);
    }
    return null;
  }, [storageKey]);

  // localStorage에 필터 설정 저장
  const saveToStorage = useCallback(
    (filters: string[]) => {
      try {
        localStorage.setItem(storageKey, JSON.stringify(filters));
      } catch (error) {
        logger.warn('Failed to save to localStorage:', error);
      }
    },
    [storageKey]
  );

  // 초기 필터 결정 (우선순위: localStorage > externalVisibleFilters > defaultFilters)
  const getInitialFilters = useCallback((): string[] => {
    // 1. localStorage에서 불러오기
    const stored = getStoredFilters();
    if (stored) return stored;

    // 2. 외부에서 전달된 값
    if (externalVisibleFilters && externalVisibleFilters.length > 0) {
      return externalVisibleFilters;
    }

    // 3. 기본 필터 설정
    return defaultFilters;
  }, [getStoredFilters, externalVisibleFilters, defaultFilters]);

  // 초기 로드 시 localStorage 값으로 부모 상태 동기화
  useEffect(() => {
    if (!isInitialized) {
      const initialFilters = getInitialFilters();
      if (onFilterVisibilityChange && initialFilters.length > 0) {
        onFilterVisibilityChange(initialFilters);
      }
      setIsInitialized(true);
    }
  }, [isInitialized, onFilterVisibilityChange, getInitialFilters]);

  // 외부 visibleFilters가 변경되면 localStorage에 저장
  useEffect(() => {
    if (isInitialized && externalVisibleFilters && externalVisibleFilters.length > 0) {
      // 초기화 직후 첫 번째 실행은 건너뜀 (onFilterVisibilityChange 결과가 반영되기 전)
      if (skipNextSaveRef.current) {
        skipNextSaveRef.current = false;
        return;
      }
      saveToStorage(externalVisibleFilters);
    }
  }, [isInitialized, externalVisibleFilters, saveToStorage]);

  // UI를 렌더링하지 않음 (상태 관리 역할만 수행)
  return null;
};

export default FilterVisibilitySelector;
