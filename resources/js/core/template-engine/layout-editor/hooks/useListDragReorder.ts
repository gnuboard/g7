/**
 * useListDragReorder.ts — 카드 리스트 드래그 재배치 + 드롭 위치 표시 공용 훅
 *
 *
 * 세로 카드 리스트(세그먼트 조각·화면 동작·컴포넌트 동작)에서 `⠿` 핸들 HTML5 drag 로 항목을
 * 임의 위치로 옮기되, 캔버스 DnD 와 동일한 **드롭 예정 삽입선**을 보여 주는 상태/핸들러를 제공한다.
 * 종전 세 편집기가 각자 `dragIndex`/`onDrop` 을 인라인 구현했는데(SegmentedValueEditor 만 삽입선
 * 표시, 나머지는 카드 위 단순 onDrop + ▲▼ 버튼), 본 훅으로 일원화한다("표현식 편집기처럼
 * 드래그 시 드롭 위치 표시 + 순서 버튼 제거").
 *
 * 모델: `dropIndex` 는 **삽입 지점**(0..length) — 항목 index 앞에 떨어지면 index, 뒤면 index+1.
 * 드롭 확정 시 splice 보정(`dropIndex > dragIndex ? dropIndex - 1 : dropIndex`)으로 끌던 항목을
 * 그 지점으로 옮긴다. 잠금 항목(상속 base 등)은 `canDropAt` 으로 거른다 — 드래그 불가/경계
 * 불가침 정책은 호출자가 콜백으로 주입(컴포넌트마다 다름).
 *
 * 편집기 코어 — DOM/스타일 비포함(상태 + 핸들러만). 삽입선 렌더는 호출자가 `DropLine` 으로 그린다.
 *
 * @since engine-v1.50.0
 */

import { useCallback, useState } from 'react';

export interface ListDragReorder {
  /** 드래그 중인 항목 인덱스(없으면 null) */
  dragIndex: number | null;
  /** 드롭 예정 삽입 지점(0..length, 없으면 null) — 이 값과 같은 위치의 DropLine 을 활성화 */
  dropIndex: number | null;
  /** 드래그 진행 중 여부(삽입선/딤 활성 판정용) */
  dragging: boolean;
  /** 핸들 onDragStart — 항목 index 에서 드래그 시작 */
  onDragStart: (index: number) => void;
  /** 핸들/카드 onDragEnd — 드래그 취소(드롭 없이 종료) */
  onDragEnd: () => void;
  /**
   * 카드 onDragOver — 포인터 Y 가 카드 위/아래 절반 중 어디인지(half)로 삽입 지점 결정.
   * 'before' → index, 'after' → index + 1. 잠금 위치면 가장 가까운 허용 지점으로 보정한다.
   */
  onDragOverItem: (index: number, half: 'before' | 'after') => void;
  /** 카드/삽입선 onDrop — 드롭 확정(끌던 항목을 dropIndex 로 이동, splice 보정 후 onMove 호출) */
  onDrop: () => void;
  /** 특정 삽입 지점 DropLine 이 활성인지(dragging && dropIndex === at) */
  isDropTarget: (at: number) => boolean;
}

export interface UseListDragReorderOptions {
  /** 리스트 길이(삽입 지점 상한) */
  length: number;
  /** 끌던 항목(from)을 삽입 지점(to: 0..length)으로 옮긴다 — 호출자가 실제 배열 splice 수행 */
  onMove: (from: number, to: number) => void;
  /**
   * 항목 index 가 드래그 가능한지(기본: 전부 가능). 상속 base 잠금 항목 등을 거른다 —
   * false 면 onDragStart 가 무시된다.
   */
  canDrag?: (index: number) => boolean;
  /**
   * 끌던 항목(from)을 삽입 지점(to)으로 떨어뜨릴 수 있는지(기본: 가능). 잠금 구간 경계
   * 불가침(자식 구간 밖 진입 차단) 등을 거른다 — false 면 그 지점의 DropLine 이 비활성.
   */
  canDropAt?: (from: number, to: number) => boolean;
}

/**
 * 카드 리스트 드래그 재배치 + 드롭 위치 표시 상태/핸들러.
 *
 * @param opts UseListDragReorderOptions
 * @returns ListDragReorder 상태/핸들러
 */
export function useListDragReorder(opts: UseListDragReorderOptions): ListDragReorder {
  const { length, onMove, canDrag, canDropAt } = opts;
  const [dragIndex, setDragIndex] = useState<number | null>(null);
  const [dropIndex, setDropIndex] = useState<number | null>(null);

  const onDragStart = useCallback(
    (index: number): void => {
      if (canDrag && !canDrag(index)) return;
      setDragIndex(index);
      setDropIndex(index);
    },
    [canDrag],
  );

  const onDragEnd = useCallback((): void => {
    setDragIndex(null);
    setDropIndex(null);
  }, []);

  const onDragOverItem = useCallback(
    (index: number, half: 'before' | 'after'): void => {
      if (dragIndex === null) return;
      const at = half === 'before' ? index : index + 1;
      // 잠금 정책 — 떨어뜨릴 수 없는 지점이면 삽입선 숨김(dropIndex=null).
      if (canDropAt && !canDropAt(dragIndex, at)) {
        setDropIndex(null);
        return;
      }
      setDropIndex(at);
    },
    [dragIndex, canDropAt],
  );

  const onDrop = useCallback((): void => {
    if (dragIndex !== null && dropIndex !== null) {
      // splice 보정 — 앞에서 빼면 뒤 인덱스가 1 당겨진다.
      const target = dropIndex > dragIndex ? dropIndex - 1 : dropIndex;
      if (target !== dragIndex && (!canDropAt || canDropAt(dragIndex, dropIndex))) {
        onMove(dragIndex, target);
      }
    }
    setDragIndex(null);
    setDropIndex(null);
  }, [dragIndex, dropIndex, onMove, canDropAt]);

  const isDropTarget = useCallback(
    (at: number): boolean => dragIndex !== null && dropIndex === at && at <= length,
    [dragIndex, dropIndex, length],
  );

  return {
    dragIndex,
    dropIndex,
    dragging: dragIndex !== null,
    onDragStart,
    onDragEnd,
    onDragOverItem,
    onDrop,
    isDropTarget,
  };
}
