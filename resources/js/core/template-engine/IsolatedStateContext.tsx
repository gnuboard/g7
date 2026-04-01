/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * IsolatedStateContext.tsx
 *
 * 격리된 상태 관리를 위한 Context
 *
 * isolatedState 속성을 가진 컴포넌트는 독립적인 상태 스코프를 가지며,
 * 상태 변경 시 해당 스코프 내의 컴포넌트만 리렌더링됩니다.
 *
 * 주요 기능:
 * - 독립적인 useState를 통한 격리된 상태 관리
 * - _isolated.* 경로로 상태 접근
 * - Stale Closure 방지를 위한 useRef 패턴
 * - DevTools 연동 (G7Core._isolatedStates)
 *
 * @module IsolatedStateContext
 * @since engine-v1.12.0
 */

import React, { createContext, useContext, useState, useCallback, useRef, useMemo, useEffect } from 'react';
import { createLogger } from '../utils/Logger';

const logger = createLogger('IsolatedStateContext');

/**
 * 격리된 상태 컨텍스트 값 인터페이스
 */
export interface IsolatedStateContextValue {
  /** 현재 격리된 상태 */
  state: Record<string, any>;

  /**
   * 특정 경로의 상태를 설정
   *
   * @param path 상태 경로 (dot notation, 예: "user.name")
   * @param value 설정할 값
   */
  setState: (path: string, value: any) => void;

  /**
   * 특정 경로의 상태를 조회
   *
   * @param path 상태 경로 (dot notation, 빈 문자열이면 전체 상태 반환)
   * @returns 조회된 값 또는 undefined
   */
  getState: (path: string) => any;

  /**
   * 상태를 병합 모드에 따라 업데이트
   *
   * @param updates 병합할 상태 객체
   * @param mergeMode 병합 모드 ('replace' | 'shallow' | 'deep', 기본값: 'deep')
   */
  mergeState: (updates: Record<string, any>, mergeMode?: 'replace' | 'shallow' | 'deep') => void;

  /**
   * 최신 상태를 참조하는 ref (Stale Closure 방지용)
   */
  stateRef: React.MutableRefObject<Record<string, any>>;

  /**
   * 스코프 ID (DevTools 식별용)
   */
  scopeId: string;
}

/**
 * 기본 컨텍스트 값 (컨텍스트 외부에서 사용 시)
 */
const defaultContextValue: IsolatedStateContextValue = {
  state: {},
  setState: () => {
    logger.warn('IsolatedStateContext not available: setState called outside IsolatedStateProvider');
  },
  getState: () => undefined,
  mergeState: () => {
    logger.warn('IsolatedStateContext not available: mergeState called outside IsolatedStateProvider');
  },
  stateRef: { current: {} },
  scopeId: '',
};

/**
 * 격리된 상태 컨텍스트
 */
export const IsolatedStateContext = createContext<IsolatedStateContextValue>(defaultContextValue);

/**
 * IsolatedStateProvider Props
 */
export interface IsolatedStateProviderProps {
  /** 초기 상태 */
  initialState?: Record<string, any>;

  /**
   * 스코프 ID (DevTools에서 식별용)
   *
   * 지정하지 않으면 자동 생성됩니다.
   */
  scopeId?: string;

  /** 자식 컴포넌트 */
  children: React.ReactNode;
}

/**
 * 고유한 스코프 ID를 생성합니다.
 */
let scopeIdCounter = 0;
function generateScopeId(): string {
  return `isolated-${++scopeIdCounter}-${Date.now().toString(36)}`;
}

/**
 * 깊은 병합을 수행하는 유틸리티 함수
 *
 * - 객체는 재귀적으로 병합
 * - 배열은 교체 (병합하지 않음)
 * - null/undefined는 덮어씀
 *
 * @param target 기존 상태 객체
 * @param source 병합할 객체
 * @returns 깊은 병합된 새 객체
 */
function deepMerge(target: any, source: any): any {
  if (source === null || source === undefined) return target;
  if (typeof source !== 'object' || Array.isArray(source)) return source;

  const result = { ...target };
  for (const key of Object.keys(source)) {
    const sourceValue = source[key];
    const targetValue = target?.[key];

    if (
      sourceValue !== null &&
      typeof sourceValue === 'object' &&
      !Array.isArray(sourceValue) &&
      targetValue !== null &&
      typeof targetValue === 'object' &&
      !Array.isArray(targetValue)
    ) {
      result[key] = deepMerge(targetValue, sourceValue);
    } else {
      result[key] = sourceValue;
    }
  }
  return result;
}

/**
 * dot notation 경로로 중첩된 값을 가져옵니다.
 *
 * @param obj 대상 객체
 * @param path dot notation 경로 (예: "user.profile.name")
 * @returns 해당 경로의 값 또는 undefined
 */
function getValueByPath(obj: any, path: string): any {
  if (!path) return obj;
  return path.split('.').reduce((current, key) => current?.[key], obj);
}

/**
 * dot notation 경로에 값을 설정한 새 객체를 반환합니다.
 *
 * @param obj 대상 객체
 * @param path dot notation 경로
 * @param value 설정할 값
 * @returns 새로운 객체 (원본 불변)
 */
function setValueByPath(obj: Record<string, any>, path: string, value: any): Record<string, any> {
  const keys = path.split('.');
  const result = { ...obj };

  let current: any = result;
  for (let i = 0; i < keys.length - 1; i++) {
    const key = keys[i];
    current[key] = { ...current[key] };
    current = current[key];
  }

  current[keys[keys.length - 1]] = value;
  return result;
}

/**
 * 격리된 상태 Provider
 *
 * isolatedState 속성을 가진 컴포넌트를 래핑하여 독립적인 상태 스코프를 제공합니다.
 *
 * @example
 * ```tsx
 * <IsolatedStateProvider
 *   initialState={{ selectedCategories: [null, null, null, null] }}
 *   scopeId="category-selector"
 * >
 *   <DynamicRenderer componentDef={...} />
 * </IsolatedStateProvider>
 * ```
 */
export const IsolatedStateProvider: React.FC<IsolatedStateProviderProps> = ({
  initialState = {},
  scopeId: providedScopeId,
  children,
}) => {
  // 스코프 ID 결정 (제공되지 않으면 자동 생성)
  const scopeIdRef = useRef<string>(providedScopeId || generateScopeId());
  const scopeId = scopeIdRef.current;

  // 격리된 상태
  const [state, setStateInternal] = useState<Record<string, any>>(initialState);

  // Stale Closure 방지를 위한 ref (SlotContext 패턴 참조)
  const stateRef = useRef<Record<string, any>>(state);
  stateRef.current = state;

  /**
   * 특정 경로의 상태를 조회
   */
  const getState = useCallback((path: string): any => {
    return getValueByPath(stateRef.current, path);
  }, []);

  /**
   * 특정 경로에 값을 설정
   */
  const setState = useCallback((path: string, value: any): void => {
    setStateInternal(prev => {
      const newState = setValueByPath(prev, path, value);
      logger.log(`[${scopeId}] setState: ${path} =`, value);
      return newState;
    });
  }, [scopeId]);

  /**
   * 상태를 병합 모드에 따라 업데이트
   * @param updates 업데이트할 상태
   * @param mergeMode 병합 모드 ('replace' | 'shallow' | 'deep', 기본값: 'deep')
   */
  const mergeState = useCallback((updates: Record<string, any>, mergeMode: 'replace' | 'shallow' | 'deep' = 'deep'): void => {
    setStateInternal(prev => {
      let newState: Record<string, any>;
      if (mergeMode === 'replace') {
        newState = updates;
      } else if (mergeMode === 'shallow') {
        newState = { ...prev, ...updates };
      } else {
        newState = deepMerge(prev, updates);
      }
      logger.log(`[${scopeId}] mergeState (${mergeMode}):`, updates);
      return newState;
    });
  }, [scopeId]);

  /**
   * 컨텍스트 값 메모이제이션
   */
  const contextValue = useMemo<IsolatedStateContextValue>(() => ({
    state,
    setState,
    getState,
    mergeState,
    stateRef,
    scopeId,
  }), [state, setState, getState, mergeState, scopeId]);

  /**
   * 레지스트리 등록용 컨텍스트 값 (stateRef와 함수들 포함)
   *
   * G7Core.state.getIsolated/setIsolated API에서 사용됨
   */
  const registryValue = useMemo(() => ({
    state: stateRef.current,  // stateRef로 최신 상태 참조
    setState,
    getState,
    mergeState,
  }), [setState, getState, mergeState]);

  /**
   * 레지스트리 등록 및 DevTools 이벤트 발생
   *
   * __g7IsolatedStates 레지스트리:
   * - G7Core.state.getIsolated(scopeId) / setIsolated(scopeId, updates)에서 사용
   * - 전체 컨텍스트 값을 저장하여 mergeState 등 API 접근 가능
   */
  useEffect(() => {
    const G7Core = (window as any).G7Core;
    const registry = (window as any).__g7IsolatedStates || {};

    // __g7IsolatedStates 레지스트리 초기화
    if (!(window as any).__g7IsolatedStates) {
      (window as any).__g7IsolatedStates = registry;
    }

    // 현재 스코프 등록 (전체 컨텍스트 값)
    registry[scopeId] = registryValue;

    // G7Core._isolatedStates에도 등록 (DevTools 하위 호환성)
    if (G7Core) {
      if (!G7Core._isolatedStates) {
        G7Core._isolatedStates = {};
      }
      G7Core._isolatedStates[scopeId] = state;
    }

    // DevTools 이벤트 발생 (생성)
    if (G7Core?.devTools?.isEnabled?.()) {
      G7Core.devTools.emit?.('isolated:created', { id: scopeId, state });
    }

    logger.log(`[${scopeId}] IsolatedStateProvider mounted with initial state:`, state);

    // cleanup: 레지스트리에서 제거
    return () => {
      delete registry[scopeId];

      if (G7Core?._isolatedStates) {
        delete G7Core._isolatedStates[scopeId];
      }

      // DevTools 이벤트 발생 (삭제)
      if (G7Core?.devTools?.isEnabled?.()) {
        G7Core.devTools.emit?.('isolated:destroyed', { id: scopeId });
      }

      logger.log(`[${scopeId}] IsolatedStateProvider unmounted`);
    };
  }, [scopeId, registryValue]); // registryValue 변경 시에도 레지스트리 업데이트

  /**
   * 상태 변경 시 레지스트리 및 DevTools 업데이트
   */
  useEffect(() => {
    const G7Core = (window as any).G7Core;
    const registry = (window as any).__g7IsolatedStates;

    // 레지스트리의 state 참조 업데이트
    if (registry?.[scopeId]) {
      registry[scopeId].state = state;
    }

    // G7Core._isolatedStates 업데이트 (DevTools 하위 호환성)
    if (G7Core?._isolatedStates) {
      G7Core._isolatedStates[scopeId] = state;
    }

    // DevTools 이벤트 발생 (업데이트)
    if (G7Core?.devTools?.isEnabled?.()) {
      G7Core.devTools.emit?.('isolated:updated', { id: scopeId, state });
    }
  }, [state, scopeId]);

  return (
    <IsolatedStateContext.Provider value={contextValue}>
      {children}
    </IsolatedStateContext.Provider>
  );
};

/**
 * 격리된 상태 컨텍스트 Hook
 *
 * IsolatedStateProvider 내부에서만 유효한 값을 반환합니다.
 * 외부에서 호출 시 null을 반환합니다.
 *
 * @returns 격리된 상태 컨텍스트 값 또는 null
 */
export const useIsolatedState = (): IsolatedStateContextValue | null => {
  const context = useContext(IsolatedStateContext);
  // 기본값(scopeId가 빈 문자열)인 경우 null 반환
  return context.scopeId ? context : null;
};

/**
 * 격리된 상태가 활성화되어 있는지 확인하는 Hook
 *
 * @returns IsolatedStateProvider 내부인지 여부
 */
export const useIsInIsolatedScope = (): boolean => {
  const context = useContext(IsolatedStateContext);
  return !!context.scopeId;
};

export default IsolatedStateContext;
