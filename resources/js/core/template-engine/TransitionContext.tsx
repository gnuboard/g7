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
  /**
   * 값을 그대로 둔 채 다시 받는 중인 데이터 소스 id (같은 화면 쿼리 갱신 등) — 없으면 빈 집합
   * @since engine-v1.82.0
   */
  refreshingSources?: ReadonlySet<string>;
}

const EMPTY_SOURCES: ReadonlySet<string> = new Set();

/**
 * Transition Context
 */
const TransitionContext = createContext<TransitionContextValue>({
  isTransitioning: false,
  refreshingSources: EMPTY_SOURCES,
});

/**
 * 공급하지 않는 전환 문맥 — 늘 「전환 아님·다시 받는 중 없음」. 흐림 판정이 없는 렌더러가 이것을 읽어, 전환 상태가 바뀔 때
 * `TransitionContext` 를 읽는 모든 렌더러가 다시 그려지던 비용을 없앤다 (@since engine-v1.82.0 — 이동 시작부터 전환 상태를
 * 켜면서 떠나는 화면 전체가 한 번 더 그려졌다)
 */
const IdleTransitionContext = createContext<TransitionContextValue>({
  isTransitioning: false,
  refreshingSources: EMPTY_SOURCES,
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

  // 대역(시험 mock·옛 확장 사본)에 새 메서드가 없어도 동작하게 선택 호출한다 (engine-v1.82.0)
  const [refreshingSources, setRefreshingSources] = useState<ReadonlySet<string>>(() => transitionManager.getRefreshingSources?.() ?? EMPTY_SOURCES);

  useEffect(() => {
    // 구독 전에 바뀐 값을 놓치지 않게 한 번 맞춘 뒤 구독한다 (engine-v1.82.0)
    setRefreshingSources(transitionManager.getRefreshingSources?.() ?? EMPTY_SOURCES);

    return transitionManager.subscribeRefreshing?.((sourceIds) => {
      setRefreshingSources(sourceIds);
    }) ?? (() => undefined);
  }, []);

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
    refreshingSources,
  }), [isTransitioning, refreshingSources]);

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

/**
 * 전환 상태를 구독하지 않는 Hook — 공급하지 않는 문맥을 읽어 늘 「전환 아님·다시 받는 중 없음」 이고, 전환 상태가 바뀌어도
 * 이 컴포넌트를 다시 그리지 않는다. `useTransitionState` 와 같이 문맥 읽기 하나뿐이라(훅 칸을 차지하지 않음) 렌더마다
 * 둘 중 하나를 골라 불러도 훅 순서가 같다.
 *
 * @returns 늘 전환 아님
 * @since engine-v1.82.0
 */
export const useIdleTransitionState = (): TransitionContextValue => {
  return useContext(IdleTransitionContext);
};

export { TransitionContext };
