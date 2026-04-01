/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * SlotContext.tsx
 *
 * 동적 슬롯 시스템을 위한 Context
 *
 * 슬롯 시스템은 컴포넌트를 원래 위치에서 다른 위치(SlotContainer)로
 * 동적으로 이동시킬 수 있는 기능을 제공합니다.
 *
 * 주요 기능:
 * - 컴포넌트를 슬롯에 등록/해제
 * - 슬롯별 컴포넌트 수집 및 정렬
 * - 슬롯 변경 시 구독자 알림
 * - 레이아웃 언마운트 시 자동 클리어
 *
 * @module SlotContext
 * @since engine-v1.10.0
 */

import React, { createContext, useContext, useCallback, useRef, useMemo, useLayoutEffect } from 'react';
import type { ComponentDefinition } from './DynamicRenderer';
import type { FormContextValue } from './FormContext';
import { createLogger } from '../utils/Logger';

const logger = createLogger('SlotContext');

/**
 * 슬롯에 등록되는 컴포넌트 정보
 */
export interface SlotRegistration {
  /** 컴포넌트 정의 (slot 속성이 제거된 상태) */
  componentDef: ComponentDefinition;

  /** 컴포넌트의 데이터 컨텍스트 (API 데이터, route params 등) */
  dataContext: Record<string, any>;

  /** 슬롯 내 정렬 순서 (낮을수록 먼저 렌더링) */
  order: number;

  /**
   * 부모 Form 컨텍스트
   *
   * 슬롯 이동 후에도 폼 바인딩이 유지되도록 함
   */
  parentFormContext: FormContextValue | null;

  /**
   * 부모 컴포넌트 컨텍스트 getter
   *
   * Stale Closure 문제를 방지하기 위해 getter 함수로 전달
   * 호출 시점의 최신 state/setState를 반환
   */
  getParentComponentContext: () => {
    state: Record<string, any>;
    setState: (updates: Record<string, any>) => void;
  };

  /** 번역 컨텍스트 */
  translationContext: any;

  /** 컴포넌트 등록 시점의 고유 키 (중복 등록 방지용) */
  registrationKey: string;
}

/**
 * 슬롯 컨텍스트 값 인터페이스
 */
export interface SlotContextValue {
  /**
   * 컴포넌트를 슬롯에 등록
   *
   * @param slotId 슬롯 ID (예: "basic_filters", "detail_filters")
   * @param componentId 컴포넌트 ID (고유 식별자)
   * @param registration 등록 정보
   */
  registerToSlot: (slotId: string, componentId: string, registration: SlotRegistration) => void;

  /**
   * 컴포넌트를 슬롯에서 해제
   *
   * @param slotId 슬롯 ID
   * @param componentId 컴포넌트 ID
   */
  unregisterFromSlot: (slotId: string, componentId: string) => void;

  /**
   * 슬롯에 등록된 컴포넌트 목록 조회
   *
   * @param slotId 슬롯 ID
   * @returns 등록된 컴포넌트 목록 (order 기준 정렬)
   */
  getSlotComponents: (slotId: string) => SlotRegistration[];

  /**
   * 슬롯 변경 구독
   *
   * @param slotId 슬롯 ID
   * @param callback 변경 시 호출될 콜백
   * @returns 구독 해제 함수
   */
  subscribeToSlot: (slotId: string, callback: () => void) => () => void;

  /**
   * 모든 슬롯 클리어 (레이아웃 언마운트 시 사용)
   */
  clearAllSlots: () => void;

  /**
   * 슬롯 시스템 활성화 여부
   *
   * SlotProvider 내부에서만 true
   */
  isEnabled: boolean;
}

/**
 * 기본 슬롯 컨텍스트 값 (비활성화 상태)
 */
const defaultSlotContextValue: SlotContextValue = {
  registerToSlot: () => {
    logger.warn('SlotContext not available: registerToSlot called outside SlotProvider');
  },
  unregisterFromSlot: () => {
    logger.warn('SlotContext not available: unregisterFromSlot called outside SlotProvider');
  },
  getSlotComponents: () => [],
  subscribeToSlot: () => () => {},
  clearAllSlots: () => {},
  isEnabled: false,
};

/**
 * 슬롯 컨텍스트
 */
export const SlotContext = createContext<SlotContextValue>(defaultSlotContextValue);

/**
 * SlotProvider Props
 */
export interface SlotProviderProps {
  children: React.ReactNode;
}

/**
 * 슬롯 Provider
 *
 * 레이아웃 최상위에서 한 번만 래핑하여 슬롯 시스템을 활성화합니다.
 * DynamicRenderer의 최상위 렌더러에서 자동으로 래핑됩니다.
 *
 * @example
 * ```tsx
 * <SlotProvider>
 *   <DynamicRenderer componentDef={...} />
 * </SlotProvider>
 * ```
 */
export const SlotProvider: React.FC<SlotProviderProps> = ({ children }) => {
  /**
   * 슬롯별 컴포넌트 저장소
   *
   * Map<slotId, Map<componentId, SlotRegistration>>
   */
  const slotsRef = useRef<Map<string, Map<string, SlotRegistration>>>(new Map());

  /**
   * 슬롯별 구독자 저장소
   *
   * Map<slotId, Set<callback>>
   */
  const subscribersRef = useRef<Map<string, Set<() => void>>>(new Map());

  /**
   * 슬롯 변경 알림
   */
  const notifySlotSubscribers = useCallback((slotId: string) => {
    const subscribers = subscribersRef.current.get(slotId);
    if (subscribers) {
      subscribers.forEach(callback => {
        try {
          callback();
        } catch (error) {
          logger.error(`Slot subscriber error (slotId: ${slotId}):`, error);
        }
      });
    }
  }, []);

  /**
   * 컴포넌트를 슬롯에 등록
   */
  const registerToSlot = useCallback((
    slotId: string,
    componentId: string,
    registration: SlotRegistration
  ) => {
    if (!slotId || !componentId) {
      logger.warn('registerToSlot: slotId and componentId are required');
      return;
    }

    // 슬롯 맵 생성 (없으면)
    if (!slotsRef.current.has(slotId)) {
      slotsRef.current.set(slotId, new Map());
    }

    const slotMap = slotsRef.current.get(slotId)!;

    // 기존 등록이 있고 같은 registrationKey면 스킵 (중복 등록 방지)
    const existing = slotMap.get(componentId);
    if (existing && existing.registrationKey === registration.registrationKey) {
      return;
    }

    // 등록
    slotMap.set(componentId, registration);
    logger.log(`Component registered to slot: ${componentId} -> ${slotId} (order: ${registration.order})`);

    // 구독자 알림
    notifySlotSubscribers(slotId);
  }, [notifySlotSubscribers]);

  /**
   * 컴포넌트를 슬롯에서 해제
   */
  const unregisterFromSlot = useCallback((slotId: string, componentId: string) => {
    const slotMap = slotsRef.current.get(slotId);
    if (!slotMap) return;

    if (slotMap.has(componentId)) {
      slotMap.delete(componentId);
      logger.log(`Component unregistered from slot: ${componentId} <- ${slotId}`);

      // 슬롯이 비었으면 맵 제거
      if (slotMap.size === 0) {
        slotsRef.current.delete(slotId);
      }

      // 구독자 알림
      notifySlotSubscribers(slotId);
    }
  }, [notifySlotSubscribers]);

  /**
   * 슬롯에 등록된 컴포넌트 목록 조회 (order 기준 정렬)
   */
  const getSlotComponents = useCallback((slotId: string): SlotRegistration[] => {
    const slotMap = slotsRef.current.get(slotId);
    if (!slotMap) return [];

    // Map을 배열로 변환 후 order 기준 정렬
    return Array.from(slotMap.values()).sort((a, b) => a.order - b.order);
  }, []);

  /**
   * 슬롯 변경 구독
   */
  const subscribeToSlot = useCallback((slotId: string, callback: () => void): (() => void) => {
    // 구독자 Set 생성 (없으면)
    if (!subscribersRef.current.has(slotId)) {
      subscribersRef.current.set(slotId, new Set());
    }

    const subscribers = subscribersRef.current.get(slotId)!;
    subscribers.add(callback);

    // 구독 해제 함수 반환
    return () => {
      subscribers.delete(callback);
      if (subscribers.size === 0) {
        subscribersRef.current.delete(slotId);
      }
    };
  }, []);

  /**
   * 모든 슬롯 클리어
   */
  const clearAllSlots = useCallback(() => {
    // 모든 슬롯의 구독자에게 알림
    slotsRef.current.forEach((_, slotId) => {
      notifySlotSubscribers(slotId);
    });

    // 슬롯 및 구독자 클리어
    slotsRef.current.clear();
    subscribersRef.current.clear();
    logger.log('All slots cleared');
  }, [notifySlotSubscribers]);

  /**
   * 컨텍스트 값 메모이제이션
   */
  const contextValue = useMemo<SlotContextValue>(() => ({
    registerToSlot,
    unregisterFromSlot,
    getSlotComponents,
    subscribeToSlot,
    clearAllSlots,
    isEnabled: true,
  }), [registerToSlot, unregisterFromSlot, getSlotComponents, subscribeToSlot, clearAllSlots]);

  /**
   * SlotContext를 전역에 노출
   *
   * SlotContainer 등 외부 컴포넌트에서 G7Core.getSlotContext()로 접근할 수 있도록 합니다.
   * G7Core 존재 여부와 관계없이 __slotContextValue를 설정합니다.
   *
   * 중요: useLayoutEffect 사용 (useEffect가 아님)
   * - 자식 컴포넌트의 useEffect(슬롯 등록)보다 먼저 실행되어야 함
   * - React effect 실행 순서: useLayoutEffect(자식→부모) → paint → useEffect(자식→부모)
   * - useLayoutEffect는 모든 useEffect보다 먼저 실행되므로, 부모의 useLayoutEffect →
   *   자식의 useEffect 순서가 보장됨
   *
   * 중요: cleanup에서 null로 설정하지 않음
   * - React의 리렌더링/Strict Mode에서 cleanup → new effect 사이에 다른 컴포넌트가
   *   isEnabled를 확인하면 false가 되는 타이밍 이슈 방지
   * - 새 SlotProvider가 마운트되면 새 contextValue로 덮어씀
   * - 진짜 언마운트 시에만 페이지 이동 등으로 자연스럽게 정리됨
   */
  useLayoutEffect(() => {
    if (typeof window !== 'undefined') {
      // 전역에 현재 SlotContext 값에 접근하는 getter 저장
      // G7Core 존재 여부와 관계없이 설정 (SlotContainer에서 직접 접근 가능)
      (window as any).__slotContextValue = contextValue;
      logger.log('SlotContext exposed to window.__slotContextValue');
    }

    // cleanup에서 null로 설정하지 않음 - 타이밍 이슈 방지
    // 새 SlotProvider가 마운트되면 자동으로 새 값으로 덮어씀
  }, [contextValue]);

  return (
    <SlotContext.Provider value={contextValue}>
      {children}
    </SlotContext.Provider>
  );
};

/**
 * 슬롯 컨텍스트 Hook
 *
 * @returns 슬롯 컨텍스트 값
 */
export const useSlotContext = (): SlotContextValue => {
  return useContext(SlotContext);
};

/**
 * 슬롯에 등록된 컴포넌트 목록을 구독하는 Hook
 *
 * 슬롯 내용이 변경되면 컴포넌트를 리렌더링합니다.
 *
 * @param slotId 슬롯 ID
 * @returns 등록된 컴포넌트 목록
 */
export const useSlotComponents = (slotId: string): SlotRegistration[] => {
  const { getSlotComponents, subscribeToSlot, isEnabled } = useSlotContext();
  const [, forceUpdate] = React.useState({});

  // 슬롯 변경 구독
  React.useEffect(() => {
    if (!isEnabled) return;

    const unsubscribe = subscribeToSlot(slotId, () => {
      forceUpdate({});
    });

    return unsubscribe;
  }, [slotId, subscribeToSlot, isEnabled]);

  return getSlotComponents(slotId);
};

export default SlotContext;
