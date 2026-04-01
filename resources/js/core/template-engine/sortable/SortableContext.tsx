import React, { createContext, useContext } from 'react';
import type { SyntheticListenerMap } from '@dnd-kit/core/dist/hooks/utilities';

/**
 * Sortable 컨텍스트 값
 */
interface SortableContextValue {
  /** 드래그 리스너 (useSortable에서 제공) */
  listeners?: SyntheticListenerMap;
  /** 드래그 핸들 CSS 선택자 */
  handle?: string;
  /** 현재 드래그 중인지 여부 */
  isDragging?: boolean;
}

/**
 * Sortable 컨텍스트
 *
 * SortableItemWrapper에서 제공하고, DynamicRenderer에서 소비합니다.
 * 이를 통해 개별 컴포넌트 수정 없이 엔진 레벨에서 drag handle을 지원합니다.
 */
const SortableReactContext = createContext<SortableContextValue | null>(null);

/**
 * Sortable 컨텍스트 Provider
 */
export const SortableProvider: React.FC<{
  value: SortableContextValue;
  children: React.ReactNode;
}> = ({ value, children }) => {
  return (
    <SortableReactContext.Provider value={value}>
      {children}
    </SortableReactContext.Provider>
  );
};

/**
 * Sortable 컨텍스트 사용 훅
 *
 * @returns Sortable 컨텍스트 값 또는 null (sortable 외부에서 사용 시)
 */
export const useSortableContext = (): SortableContextValue | null => {
  return useContext(SortableReactContext);
};

export default SortableReactContext;
