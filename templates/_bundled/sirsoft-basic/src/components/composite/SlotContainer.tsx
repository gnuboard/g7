/**
 * SlotContainer.tsx
 *
 * 동적 슬롯에 등록된 컴포넌트들을 렌더링하는 컨테이너 컴포넌트
 *
 * SlotContainer는 SlotContext에서 지정된 슬롯 ID에 등록된 모든 컴포넌트를 수집하고,
 * slotOrder 기준으로 정렬하여 렌더링합니다.
 *
 * 통짜 TSX 컴포넌트(예: Header)가 레이아웃 JSON 노드(`"slot": "..."`)로 주입된 UI를
 * 떙겨 쓰는 코어 슬롯 시스템의 소비 측 컴포넌트입니다. 빈 슬롯이면 null 을 렌더하므로
 * "모듈 활성 시에만 노출"이 확장 주입 인프라로 자동 충족됩니다(헤더는 주입 모듈을 모름).
 *
 * @module SlotContainer
 *
 * @example
 * ```json
 * {
 *   "type": "composite",
 *   "name": "SlotContainer",
 *   "props": {
 *     "slotId": "header_currency",
 *     "className": "flex items-center"
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
 * 슬롯에 주입된 컴포넌트의 root id 를 컨테이너 id 로 스코프해 고유화한다.
 *
 * 같은 슬롯 컴포넌트가 여러 SlotContainer(예: 헤더 데스크톱/모바일)에서 렌더되면 주입
 * 컴포넌트의 정적 root id 가 컨테이너마다 같은 값으로 중복 출력되어 HTML id 유일성을
 * 위반한다. 컨테이너 고유 id 가 있고 컴포넌트가 root id 를 가지면 `{id}__{containerId}`
 * 로 스코프한다. 둘 중 하나라도 없으면 원본 그대로(무영향).
 *
 * @param componentDef 주입 컴포넌트 정의
 * @param containerId 이 SlotContainer 의 DOM id (없으면 스코프 안 함)
 * @returns 스코프된(또는 원본) 컴포넌트 정의
 */
export function scopeSlotChildDef<T extends { id?: unknown }>(
  componentDef: T,
  containerId: string | undefined,
): T {
  if (
    containerId &&
    componentDef &&
    typeof componentDef.id === 'string' &&
    componentDef.id.length > 0
  ) {
    return { ...componentDef, id: `${componentDef.id}__${containerId}` };
  }
  return componentDef;
}

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
    const directSlotContext = (window as any).__slotContextValue;
    if (directSlotContext) {
      return directSlotContext;
    }

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

    updateSlotComponents();

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

    if (children) {
      return (
        <Div className={className} style={style} id={id}>
          {children}
        </Div>
      );
    }

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

        // 같은 슬롯 컴포넌트가 여러 SlotContainer(헤더 데스크톱/모바일)에서 렌더될 때
        // 주입 컴포넌트 root id 를 컨테이너 id 로 스코프해 HTML id 중복을 막는다.
        const scopedDef = scopeSlotChildDef(componentDef, id);

        return (
          <DynamicRenderer
            key={scopedDef.id || `slot-${slotId}-${registration.order}`}
            componentDef={scopedDef}
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
      {children}
    </Div>
  );
};

export default SlotContainer;
