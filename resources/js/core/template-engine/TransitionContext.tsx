/**
 * TransitionContext.tsx
 *
 * 페이지 전환 상태를 React Context로 제공하는 모듈
 * DynamicRenderer에서 사용하여 모든 자식 컴포넌트에 전환 상태를 전달합니다.
 */

import React, { createContext, useContext, useState, useEffect, useMemo } from 'react';
import { transitionManager } from './TransitionManager';
import { createLogger } from '../utils/Logger';

const logger = createLogger('TransitionContext');

/**
 * Transition Context 값 인터페이스
 */
export interface TransitionContextValue {
  /** 페이지 전환 중 여부 (데이터 로딩 중) */
  isTransitioning: boolean;
}

/**
 * Transition Context
 */
const TransitionContext = createContext<TransitionContextValue>({
  isTransitioning: false,
});

/**
 * Transition Context Provider Props
 */
interface TransitionProviderProps {
  children: React.ReactNode;
}

/**
 * Transition Provider 컴포넌트
 *
 * TransitionManager를 구독하여 페이지 전환 상태를 Context로 제공합니다.
 * template-engine.ts의 renderTemplate에서 최상위에 래핑됩니다.
 */
export const TransitionProvider: React.FC<TransitionProviderProps> = ({ children }) => {
  const [isTransitioning, setIsTransitioning] = useState(() => {
    const initial = transitionManager.getIsPending();
    logger.log('Initial state:', initial);
    return initial;
  });

  useEffect(() => {
    logger.log('Subscribing to TransitionManager');
    // TransitionManager 구독
    const unsubscribe = transitionManager.subscribe((isPending) => {
      logger.log('Received isPending update:', isPending);
      setIsTransitioning(isPending);
    });

    return () => {
      logger.log('Unsubscribing from TransitionManager');
      unsubscribe();
    };
  }, []);

  const value = useMemo(() => ({
    isTransitioning,
  }), [isTransitioning]);

  return (
    <TransitionContext.Provider value={value}>
      {children}
    </TransitionContext.Provider>
  );
};

/**
 * Transition 상태를 사용하는 Hook
 *
 * @returns TransitionContextValue
 *
 * @example
 * // 컴포넌트에서 사용
 * const { isTransitioning } = useTransition();
 *
 * return (
 *   <div className={isTransitioning ? 'opacity-70' : ''}>
 *     {content}
 *   </div>
 * );
 */
export const useTransitionState = (): TransitionContextValue => {
  return useContext(TransitionContext);
};

export { TransitionContext };
