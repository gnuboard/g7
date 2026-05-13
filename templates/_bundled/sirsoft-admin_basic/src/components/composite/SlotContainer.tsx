/**
 * SlotContainer.tsx
 *
 * 동적 슬롯에 등록된 컴포넌트들을 렌더링하는 컨테이너 컴포넌트
 *
 * SlotContainer는 SlotContext에서 지정된 슬롯 ID에 등록된 모든 컴포넌트를 수집하고,
 * slotOrder 기준으로 정렬하여 렌더링합니다.
 *
 * 주요 기능:
 * - 슬롯에 등록된 컴포넌트 수집 및 렌더링
 * - slotOrder 기준 정렬
 * - FormContext 및 ComponentContext 전달
 * - 빈 슬롯 처리 (emptyContent)
 *
 * @module SlotContainer
 * @since v1.10.0
 *
 * @example
 * ```json
 * {
 *   "type": "composite",
 *   "name": "SlotContainer",
 *   "props": {
 *     "slotId": "basic_filters",
 *     "className": "flex flex-col gap-2"
 *   }
 * }
 * ```
 */

import React, { useEffect, useState, useCallback } from 'react';
import { Div } from '../basic/Div';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:SlotContainer')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:SlotContainer]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:SlotContainer]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:SlotContainer]', ...args),
};

/**
 * SlotContainer Props
 */
export interface SlotContainerProps {
  /**
   * 슬롯 ID
   *
   * 이 슬롯에 등록된 컴포넌트들을 렌더링합니다.
   */
  slotId: string;

  /**
   * 컨테이너 CSS 클래스
   */
  className?: string;

  /**
   * 빈 슬롯일 때 표시할 내용
   *
   * React 엘리먼트 또는 문자열을 전달할 수 있습니다.
   */
  emptyContent?: React.ReactNode;

  /**
   * 인라인 스타일
   */
  style?: React.CSSProperties;

  /**
   * 컴포넌트 ID (DOM id)
   */
  id?: string;

  /**
   * 자식 컴포넌트 (children을 통한 fallback 또는 slot 컴포넌트가 아닌 정적 컨텐츠)
   */
  children?: React.ReactNode;
}

/**
 * SlotRegistration 타입 (SlotContext에서 정의된 것과 호환)
 */
interface SlotRegistration {
  componentDef: any;
  dataContext: Record<string, any>;
  order: number;
  parentFormContext: any;
  getParentComponentContext: () => { state: Record<string, any>; setState: (updates: any) => void };
  translationContext: any;
  registrationKey: string;
}

/**
 * SlotContext 값 타입
 */
interface SlotContextValue {
  getSlotComponents: (slotId: string) => SlotRegistration[];
  subscribeToSlot: (slotId: string, callback: () => void) => () => void;
  isEnabled: boolean;
}

/**
 * SlotContainer 컴포넌트
 *
 * SlotContext에서 지정된 슬롯 ID에 등록된 컴포넌트들을 수집하여 렌더링합니다.
 * 컴포넌트는 slotOrder 기준으로 정렬됩니다.
 *
 * 이 컴포넌트는 SlotContext를 사용하므로 SlotProvider 내부에서만 동작합니다.
 * SlotProvider는 DynamicRenderer의 루트에서 자동으로 래핑됩니다.
 *
 * @param props SlotContainerProps
 */
export const SlotContainer: React.FC<SlotContainerProps> = ({
  slotId,
  className = '',
  emptyContent,
  style,
  id,
  children,
}) => {
  const [slotComponents, setSlotComponents] = useState<SlotRegistration[]>([]);
  const [, forceUpdate] = useState({});

  // SlotContext 가져오기 (window.__slotContextValue 또는 G7Core.getSlotContext() 사용)
  const getSlotContext = useCallback((): SlotContextValue | null => {
    // SlotProvider에서 직접 설정한 전역 값 우선 확인
    const directSlotContext = (window as any).__slotContextValue;
    if (directSlotContext) {
      return directSlotContext;
    }

    // 폴백: G7Core.getSlotContext()를 통해 SlotContext 접근
    const g7Core = (window as any).G7Core;
    if (!g7Core) return null;

    const slotContext = g7Core.getSlotContext?.();
    return slotContext || null;
  }, []);

  // 슬롯 컴포넌트 업데이트 함수
  const updateSlotComponents = useCallback(() => {
    const slotContext = getSlotContext();
    if (slotContext && slotContext.isEnabled) {
      setSlotComponents(slotContext.getSlotComponents(slotId));
    }
  }, [slotId, getSlotContext]);

  // 슬롯 구독 및 업데이트
  useEffect(() => {
    const slotContext = getSlotContext();

    if (!slotContext || !slotContext.isEnabled) {
      return;
    }

    // 초기 컴포넌트 로드
    updateSlotComponents();

    // 슬롯 변경 구독
    const unsubscribe = slotContext.subscribeToSlot(slotId, () => {
      updateSlotComponents();
      forceUpdate({});
    });

    return unsubscribe;
  }, [slotId, getSlotContext, updateSlotComponents]);

  // G7Core에서 DynamicRenderer 가져오기
  const getDynamicRenderer = useCallback(() => {
    const g7Core = (window as any).G7Core;
    if (!g7Core) return null;

    return g7Core.getDynamicRenderer?.();
  }, []);

  // 빈 슬롯 처리
  if (slotComponents.length === 0) {
    if (emptyContent) {
      return (
        <Div className={className} style={style} id={id}>
          {emptyContent}
        </Div>
      );
    }

    // children이 있으면 fallback으로 사용
    if (children) {
      return (
        <Div className={className} style={style} id={id}>
          {children}
        </Div>
      );
    }

    // 빈 컨테이너 (내용 없음)
    return null;
  }

  const DynamicRenderer = getDynamicRenderer();
  const g7Core = (window as any).G7Core;

  if (!DynamicRenderer || !g7Core) {
    logger.error('G7Core or DynamicRenderer not available');
    return null;
  }

  return (
    <Div className={className} style={style} id={id}>
      {slotComponents.map((registration) => {
        const {
          componentDef,
          dataContext,
          parentFormContext,
          getParentComponentContext,
          translationContext,
        } = registration;

        return (
          <DynamicRenderer
            key={componentDef.id || `slot-${slotId}-${registration.order}`}
            componentDef={componentDef}
            dataContext={dataContext}
            translationContext={translationContext}
            registry={g7Core.getComponentRegistry()}
            bindingEngine={g7Core.getDataBindingEngine()}
            translationEngine={g7Core.getTranslationEngine()}
            actionDispatcher={g7Core.getActionDispatcher()}
            parentComponentContext={getParentComponentContext?.()}
            parentFormContextProp={parentFormContext}
            isRootRenderer={false}
          />
        );
      })}
      {/* 정적 children도 함께 렌더링 (있는 경우) */}
      {children}
    </Div>
  );
};

export default SlotContainer;
