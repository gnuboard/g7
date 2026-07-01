/**
 * useListDragReorder.test.ts — 카드 리스트 드래그 재배치 + 드롭 위치 표시 공용 훅
 *
 *
 * 검증:
 *  - 드래그 시작/종료 상태(dragIndex/dropIndex/dragging)
 *  - onDragOverItem 절반 판정(before=index / after=index+1)
 *  - onDrop splice 보정(dropIndex > dragIndex ? -1) → onMove(from, target)
 *  - 같은 위치 드롭 = no-op(onMove 미호출)
 *  - canDrag 잠금 — 드래그 시작 거부
 *  - canDropAt 잠금 — 삽입 지점 거부 시 dropIndex=null + onMove 미호출
 *  - isDropTarget(at) — dragging && dropIndex === at
 */

import { describe, it, expect, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useListDragReorder } from '../../hooks/useListDragReorder';

describe('useListDragReorder', () => {
  it('드래그 시작/종료 상태를 추적한다', () => {
    const onMove = vi.fn();
    const { result } = renderHook(() => useListDragReorder({ length: 3, onMove }));

    expect(result.current.dragging).toBe(false);
    expect(result.current.dragIndex).toBeNull();

    act(() => result.current.onDragStart(1));
    expect(result.current.dragging).toBe(true);
    expect(result.current.dragIndex).toBe(1);
    // 시작 시 dropIndex 는 자기 위치(이동 없음 기준점).
    expect(result.current.dropIndex).toBe(1);

    act(() => result.current.onDragEnd());
    expect(result.current.dragging).toBe(false);
    expect(result.current.dragIndex).toBeNull();
    expect(result.current.dropIndex).toBeNull();
  });

  it('onDragOverItem 절반 판정 — before=index, after=index+1', () => {
    const onMove = vi.fn();
    const { result } = renderHook(() => useListDragReorder({ length: 3, onMove }));
    act(() => result.current.onDragStart(0));

    act(() => result.current.onDragOverItem(2, 'before'));
    expect(result.current.dropIndex).toBe(2);

    act(() => result.current.onDragOverItem(2, 'after'));
    expect(result.current.dropIndex).toBe(3);
  });

  it('onDrop — 끌던 항목을 삽입 지점으로 이동(splice 보정: dropIndex>dragIndex → -1)', () => {
    const onMove = vi.fn();
    const { result } = renderHook(() => useListDragReorder({ length: 3, onMove }));
    // 0번을 2번 뒤(삽입 지점 3)로 → target = 3-1 = 2.
    act(() => result.current.onDragStart(0));
    act(() => result.current.onDragOverItem(2, 'after'));
    act(() => result.current.onDrop());
    expect(onMove).toHaveBeenCalledWith(0, 2);
    // 종료 후 상태 초기화.
    expect(result.current.dragging).toBe(false);
  });

  it('onDrop — 뒤 항목을 앞으로 이동(dropIndex<dragIndex → 보정 없음)', () => {
    const onMove = vi.fn();
    const { result } = renderHook(() => useListDragReorder({ length: 3, onMove }));
    // 2번을 0번 앞(삽입 지점 0)으로 → target = 0.
    act(() => result.current.onDragStart(2));
    act(() => result.current.onDragOverItem(0, 'before'));
    act(() => result.current.onDrop());
    expect(onMove).toHaveBeenCalledWith(2, 0);
  });

  it('같은 위치 드롭은 no-op(onMove 미호출)', () => {
    const onMove = vi.fn();
    const { result } = renderHook(() => useListDragReorder({ length: 3, onMove }));
    // 1번을 자기 앞(삽입 지점 1)으로 → target = 1 = dragIndex → 무동작.
    act(() => result.current.onDragStart(1));
    act(() => result.current.onDragOverItem(1, 'before'));
    act(() => result.current.onDrop());
    expect(onMove).not.toHaveBeenCalled();
  });

  it('canDrag 잠금 — 거부된 인덱스는 드래그 시작 안 됨', () => {
    const onMove = vi.fn();
    const { result } = renderHook(() =>
      useListDragReorder({ length: 3, onMove, canDrag: (i) => i !== 0 }),
    );
    act(() => result.current.onDragStart(0));
    expect(result.current.dragging).toBe(false);
    expect(result.current.dragIndex).toBeNull();
    // 허용 인덱스는 정상.
    act(() => result.current.onDragStart(1));
    expect(result.current.dragIndex).toBe(1);
  });

  it('canDropAt 잠금 — 거부 지점 오버 시 삽입선 숨김 + onMove 미호출', () => {
    const onMove = vi.fn();
    // 삽입 지점 0(부모 구간)으로의 드롭 금지(to >= 1 만 허용).
    const { result } = renderHook(() =>
      useListDragReorder({ length: 3, onMove, canDropAt: (_from, to) => to >= 1 }),
    );
    act(() => result.current.onDragStart(2));
    // 0번 앞(삽입 0)은 거부 → dropIndex null.
    act(() => result.current.onDragOverItem(0, 'before'));
    expect(result.current.dropIndex).toBeNull();
    act(() => result.current.onDrop());
    expect(onMove).not.toHaveBeenCalled();
  });

  it('isDropTarget(at) — dragging && dropIndex === at 일 때만 true', () => {
    const onMove = vi.fn();
    const { result } = renderHook(() => useListDragReorder({ length: 3, onMove }));
    expect(result.current.isDropTarget(0)).toBe(false); // 비드래그
    act(() => result.current.onDragStart(0));
    act(() => result.current.onDragOverItem(1, 'after')); // 삽입 지점 2
    expect(result.current.isDropTarget(2)).toBe(true);
    expect(result.current.isDropTarget(1)).toBe(false);
    // 리스트 끝(length)도 유효 삽입 지점.
    act(() => result.current.onDragOverItem(2, 'after')); // 삽입 지점 3 = length
    expect(result.current.isDropTarget(3)).toBe(true);
  });
});
