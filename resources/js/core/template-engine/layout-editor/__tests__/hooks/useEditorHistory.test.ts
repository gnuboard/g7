/**
 * useEditorHistory 단위 테스트
 *
 * Phase 1 골격 — push / undo / redo / clear / canUndo / canRedo 동작 검증.
 * 실제 layoutDocument 연동은 Phase 3 에서 추가.
 */

import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useEditorHistory } from '../../hooks/useEditorHistory';

describe('useEditorHistory — 초기 상태', () => {
  it('빈 스택 — canUndo / canRedo 모두 false', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    expect(result.current.canUndo).toBe(false);
    expect(result.current.canRedo).toBe(false);
  });
});

describe('useEditorHistory — push / undo / redo', () => {
  it('push 1건 — canUndo false (단일 항목이라 되돌릴 곳 없음), canRedo false', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => result.current.push({ snapshot: 'A' }));
    expect(result.current.canUndo).toBe(false);
    expect(result.current.canRedo).toBe(false);
  });

  it('push 2건 — canUndo true, canRedo false', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => result.current.push({ snapshot: 'A' }));
    act(() => result.current.push({ snapshot: 'B' }));
    expect(result.current.canUndo).toBe(true);
    expect(result.current.canRedo).toBe(false);
  });

  it('undo 후 redo — 양방향 가능', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => result.current.push({ snapshot: 'A' }));
    act(() => result.current.push({ snapshot: 'B' }));

    let entry: any;
    act(() => {
      entry = result.current.undo();
    });
    expect(entry?.snapshot).toBe('A');
    expect(result.current.canUndo).toBe(false);
    expect(result.current.canRedo).toBe(true);

    act(() => {
      entry = result.current.redo();
    });
    expect(entry?.snapshot).toBe('B');
    expect(result.current.canUndo).toBe(true);
    expect(result.current.canRedo).toBe(false);
  });

  it('undo 가능 한계 초과 시 null', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => result.current.push({ snapshot: 'A' }));
    let entry: any;
    act(() => {
      entry = result.current.undo();
    });
    expect(entry).toBeNull();
  });

  it('redo 가능 한계 초과 시 null', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => result.current.push({ snapshot: 'A' }));
    act(() => result.current.push({ snapshot: 'B' }));
    let entry: any;
    act(() => {
      entry = result.current.redo();
    });
    expect(entry).toBeNull();
  });

  it('undo 후 새 push — redo 스택 잘림', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => result.current.push({ snapshot: 'A' }));
    act(() => result.current.push({ snapshot: 'B' }));
    act(() => {
      result.current.undo();
    });
    expect(result.current.canRedo).toBe(true);

    act(() => result.current.push({ snapshot: 'C' }));
    expect(result.current.canRedo).toBe(false);
  });
});

describe('useEditorHistory — clear', () => {
  it('clear 후 빈 스택으로 회귀', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => result.current.push({ snapshot: 'A' }));
    act(() => result.current.push({ snapshot: 'B' }));
    act(() => result.current.clear());
    expect(result.current.canUndo).toBe(false);
    expect(result.current.canRedo).toBe(false);
  });
});

describe('useEditorHistory — limit', () => {
  it('limit 초과 시 가장 오래된 항목 drop', () => {
    const { result } = renderHook(() => useEditorHistory<string>(3));
    act(() => result.current.push({ snapshot: 'A' }));
    act(() => result.current.push({ snapshot: 'B' }));
    act(() => result.current.push({ snapshot: 'C' }));
    act(() => result.current.push({ snapshot: 'D' })); // A 가 drop

    // 3번 undo 가능 → D → C → B
    let entry: any;
    act(() => { entry = result.current.undo(); });
    expect(entry?.snapshot).toBe('C');
    act(() => { entry = result.current.undo(); });
    expect(entry?.snapshot).toBe('B');
    // 더 이상 undo 불가 — A 는 drop 됨
    expect(result.current.canUndo).toBe(false);
  });
});
