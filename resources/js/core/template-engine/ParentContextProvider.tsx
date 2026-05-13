/**
 * ParentContextProvider.tsx
 *
 * 모달의 부모 컨텍스트 변경 시 선택적 리렌더링을 위한 Context Provider
 *
 * 문제:
 * - $parent._local 상태가 변경되면 모달이 최신 값을 반영해야 함
 * - 기존 방식: _global 변경 → 전체 앱 리렌더링 (성능 문제)
 *
 * 해결:
 * - ParentContextProvider로 모달만 감싸기
 * - 부모 컨텍스트 변경 시 이 Provider의 상태만 업데이트
 * - 모달만 리렌더링, 다른 컴포넌트는 영향 없음
 *
 * @package G7
 * @subpackage Template Engine
 * @since 1.17.0
 */

import React, { createContext, useContext, useState, useCallback, useRef, useEffect } from 'react';
import { createLogger } from '../utils/Logger';

const logger = createLogger('ParentContextProvider');

/**
 * 부모 컨텍스트 상태 인터페이스
 */
interface ParentContextState {
  /** 부모 컨텍스트 버전 (변경 감지용) */
  version: number;
  /** 부모 데이터 컨텍스트 (최신 값) */
  getParentDataContext: () => Record<string, any> | undefined;
}

/**
 * Context 기본값
 */
const ParentContextContext = createContext<ParentContextState | null>(null);

/**
 * 부모 컨텍스트 변경 트리거 함수
 * 전역에서 호출 가능하도록 export
 */
let triggerParentContextUpdate: (() => void) | null = null;

/**
 * 부모 컨텍스트 업데이트 트리거
 *
 * $parent._local이 변경되었을 때 호출하면
 * ParentContextProvider를 구독하는 모달만 리렌더링됨
 *
 * @example
 * // ActionDispatcher에서 $parent._local 업데이트 후
 * import { triggerModalParentUpdate } from './ParentContextProvider';
 * triggerModalParentUpdate();
 */
export function triggerModalParentUpdate(): void {
  if (triggerParentContextUpdate) {
    logger.log('[triggerModalParentUpdate] 모달 부모 컨텍스트 업데이트 트리거');
    triggerParentContextUpdate();
  } else {
    logger.warn('[triggerModalParentUpdate] Provider가 아직 마운트되지 않음');
  }
}

/**
 * ParentContextProvider Props
 */
interface ParentContextProviderProps {
  /** 자식 요소 (React.createElement에서 전달될 수 있음) */
  children?: React.ReactNode;
}

/**
 * 부모 컨텍스트 Provider
 *
 * 모달 섹션을 감싸서 부모 컨텍스트 변경 시
 * 모달만 선택적으로 리렌더링합니다.
 *
 * @example
 * <ParentContextProvider>
 *   {modals.map(modalDef => <DynamicRenderer ... />)}
 * </ParentContextProvider>
 */
export const ParentContextProvider: React.FC<ParentContextProviderProps> = ({ children }) => {
  const [version, setVersion] = useState(0);
  const mountedRef = useRef(true);

  // 부모 데이터 컨텍스트를 가져오는 함수 (항상 최신 값 반환)
  const getParentDataContext = useCallback(() => {
    const layoutContextStack: Array<{
      state: Record<string, any>;
      setState: (updates: any) => void;
      dataContext?: Record<string, any>;
    }> = (window as any).__g7LayoutContextStack || [];
    const parentContextEntry = layoutContextStack[layoutContextStack.length - 1];
    return parentContextEntry?.dataContext;
  }, []);

  // 전역 트리거 함수 등록
  useEffect(() => {
    mountedRef.current = true;
    triggerParentContextUpdate = () => {
      if (mountedRef.current) {
        setVersion(v => v + 1);
        logger.log('[ParentContextProvider] 버전 업데이트됨');
      }
    };

    return () => {
      mountedRef.current = false;
      triggerParentContextUpdate = null;
    };
  }, []);

  const contextValue: ParentContextState = {
    version,
    getParentDataContext,
  };

  return (
    <ParentContextContext.Provider value={contextValue}>
      {children}
    </ParentContextContext.Provider>
  );
};

/**
 * 부모 컨텍스트 훅
 *
 * 모달 내부에서 사용하여 부모 컨텍스트 변경을 구독합니다.
 * version이 변경되면 컴포넌트가 리렌더링됩니다.
 *
 * @returns 부모 컨텍스트 상태 또는 null (Provider 외부에서 사용 시)
 *
 * @example
 * const parentContext = useParentContext();
 * const parentDataContext = parentContext?.getParentDataContext();
 */
export function useParentContext(): ParentContextState | null {
  return useContext(ParentContextContext);
}

/**
 * 부모 데이터 컨텍스트 훅 (편의 함수)
 *
 * version 변경 시 자동으로 최신 부모 데이터 컨텍스트를 반환합니다.
 *
 * @returns 부모 데이터 컨텍스트 또는 undefined
 */
export function useParentDataContext(): Record<string, any> | undefined {
  const context = useParentContext();
  // version을 읽어서 변경 시 리렌더링되도록 함
  const _version = context?.version;
  return context?.getParentDataContext();
}

export default ParentContextProvider;
