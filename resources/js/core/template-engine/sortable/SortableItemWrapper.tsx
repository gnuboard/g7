/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
import React from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { SortableProvider } from './SortableContext';

/**
 * SortableItemWrapper Props
 */
interface SortableItemWrapperProps {
  /** 아이템 고유 ID */
  id: string | number;
  /** 드래그 핸들 CSS 선택자 (예: "[data-drag-handle]") */
  handle?: string;
  /** 자식 요소 */
  children: React.ReactNode;
}

/**
 * 드래그 가능한 아이템을 감싸는 래퍼 컴포넌트
 *
 * @dnd-kit의 useSortable 훅을 사용하여 드래그앤드롭 기능을 제공합니다.
 * SortableProvider를 통해 listeners를 하위 컴포넌트에 전달합니다.
 * DynamicRenderer가 data-drag-handle 속성을 감지하여 자동으로 listeners를 적용합니다.
 *
 * @since engine-v1.14.0
 */
export const SortableItemWrapper: React.FC<SortableItemWrapperProps> = ({
  id,
  handle,
  children,
}) => {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id });

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
    position: 'relative',
  };


  return (
    <div
      ref={setNodeRef}
      style={style}
      {...attributes}
      {...(handle ? {} : listeners)}
      data-sortable-item
      data-sortable-id={String(id)}
      data-dragging={isDragging}
    >
      <SortableProvider value={{ listeners, handle, isDragging }}>
        {children}
      </SortableProvider>
    </div>
  );
};

export default SortableItemWrapper;
