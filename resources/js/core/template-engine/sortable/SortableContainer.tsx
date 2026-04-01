/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
import React, { useCallback, useId, useRef } from 'react';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  DragEndEvent,
  DragStartEvent,
  UniqueIdentifier,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
  horizontalListSortingStrategy,
  rectSortingStrategy,
  arrayMove,
} from '@dnd-kit/sortable';

/**
 * 정렬 전략 타입
 */
export type SortableStrategy = 'verticalList' | 'horizontalList' | 'rectSorting';

/**
 * SortableContainer Props
 */
interface SortableContainerProps {
  /** 정렬할 아이템 배열 */
  items: any[];
  /** 아이템 고유 키 필드명 */
  itemKey: string;
  /** 정렬 전략 */
  strategy: SortableStrategy;
  /** 자식 요소 (SortableItemWrapper로 감싼 아이템들) */
  children: React.ReactNode;
  /** 정렬 완료 시 콜백 */
  onSortEnd?: (sortedItems: any[], event: { oldIndex: number; newIndex: number }) => void;
  /** 드래그 시작 시 콜백 */
  onSortStart?: (event: { activeId: UniqueIdentifier }) => void;
  /** 정렬 버전 (변경 시 DndContext 리마운트하여 내부 상태 초기화) */
  sortVersion?: number;
}

/**
 * 전략별 정렬 알고리즘 매핑
 */
const strategyMap = {
  verticalList: verticalListSortingStrategy,
  horizontalList: horizontalListSortingStrategy,
  rectSorting: rectSortingStrategy,
};

/**
 * 드래그앤드롭 정렬 컨테이너
 *
 * @dnd-kit의 DndContext와 SortableContext를 사용하여
 * 자식 아이템들의 드래그앤드롭 정렬 기능을 제공합니다.
 *
 * @since engine-v1.14.0
 */
export const SortableContainer: React.FC<SortableContainerProps> = ({
  items,
  itemKey,
  strategy,
  children,
  onSortEnd,
  onSortStart,
  sortVersion,
}) => {
  // 고유 ID 생성 (다중 sortable 컨텍스트 충돌 방지)
  const contextId = useId();

  // 중복 정렬 방지를 위한 ref
  // 상태 업데이트 후 재렌더링 시 @dnd-kit이 다시 이벤트를 발생시킬 수 있음
  const sortingRef = useRef<{
    inProgress: boolean;
    lastSortTime: number;
    lastActiveId: UniqueIdentifier | null;
  }>({
    inProgress: false,
    lastSortTime: 0,
    lastActiveId: null,
  });

  // 센서 설정
  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 5, // 5px 이동 후 드래그 시작 (클릭과 구분)
      },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  /**
   * 드래그 시작 핸들러
   */
  const handleDragStart = useCallback((event: DragStartEvent) => {
    // 드래그 시작 시 상태 초기화
    sortingRef.current = {
      inProgress: true,
      lastSortTime: Date.now(),
      lastActiveId: event.active.id,
    };
    onSortStart?.({ activeId: event.active.id });
  }, [onSortStart]);

  /**
   * 드래그 종료 핸들러
   *
   * 중복 정렬 방지 로직:
   * 상태 업데이트 후 재렌더링 시 @dnd-kit이 다시 onDragEnd를 발생시킬 수 있음.
   * 동일한 activeId에 대해 짧은 시간 내 중복 호출을 무시합니다.
   */
  const handleDragEnd = useCallback((event: DragEndEvent) => {
    const { active, over } = event;
    const now = Date.now();


    // 유효하지 않은 드롭 또는 같은 위치
    if (!over || active.id === over.id) {
      sortingRef.current.inProgress = false;
      return;
    }

    // 중복 정렬 방지: 동일 아이템에 대해 500ms 내 재호출 무시
    const { lastSortTime, lastActiveId, inProgress } = sortingRef.current;
    if (
      lastActiveId === active.id &&
      now - lastSortTime < 500 &&
      !inProgress // inProgress가 false면 이미 한 번 처리된 것
    ) {
      return;
    }

    // 처리 완료 표시 (다음 정렬 전까지 중복 무시)
    sortingRef.current = {
      inProgress: false,
      lastSortTime: now,
      lastActiveId: active.id,
    };

    // 인덱스 찾기
    const oldIndex = items.findIndex((item) => String(item[itemKey]) === String(active.id));
    const newIndex = items.findIndex((item) => String(item[itemKey]) === String(over.id));

    if (oldIndex !== -1 && newIndex !== -1 && oldIndex !== newIndex) {
      // arrayMove로 새 배열 생성
      const sortedItems = arrayMove(items, oldIndex, newIndex);

      onSortEnd?.(sortedItems, { oldIndex, newIndex });
    }
  }, [items, itemKey, onSortEnd]);

  // 아이템 ID 배열 생성 (SortableContext용)
  const itemIds = items.map((item) => String(item[itemKey]));


  return (
    <DndContext
      key={sortVersion !== undefined ? `${contextId}-v${sortVersion}` : contextId}
      id={contextId}
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragStart={handleDragStart}
      onDragEnd={handleDragEnd}
    >
      <SortableContext items={itemIds} strategy={strategyMap[strategy]}>
        {children}
      </SortableContext>
    </DndContext>
  );
};

export default SortableContainer;
