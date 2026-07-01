/**
 * useEditorHistory — devtools 트래커 호출 회귀
 *
 * push/undo/redo/clear 시 window.__g7Devtools.trackEditorHistoryEntry 가
 * actionKind/op/stackSize/cursor/canUndo/canRedo 를 포함해 호출되는지 검증.
 * devtools 비활성 환경에서는 no-op (예외 미발생).
 *
 * @since engine-v1.50.0
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useEditorHistory } from '../../hooks/useEditorHistory';

interface FakeDevtools {
  trackEditorHistoryEntry: ReturnType<typeof vi.fn>;
}

declare global {
  // eslint-disable-next-line no-var
  var __g7Devtools: FakeDevtools | undefined;
}

describe('useEditorHistory — devtools 트래커 호출', () => {
  let original: FakeDevtools | undefined;

  beforeEach(() => {
    original = (window as any).__g7Devtools;
    (window as any).__g7Devtools = {
      trackEditorHistoryEntry: vi.fn(),
    };
  });

  afterEach(() => {
    (window as any).__g7Devtools = original;
  });

  it('push 시 op=push + actionKind 가 트래커로 전달', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => {
      result.current.push({ snapshot: 'A', actionKind: 'insert', label: 'add Div' });
    });

    const calls = (window as any).__g7Devtools.trackEditorHistoryEntry.mock.calls;
    expect(calls.length).toBe(1);
    const entry = calls[0][0];
    expect(entry.op).toBe('push');
    expect(entry.actionKind).toBe('insert');
    expect(entry.label).toBe('add Div');
    expect(entry.stackSize).toBe(1);
    expect(typeof entry.timestamp).toBe('number');
  });

  it('undo 시 op=undo + canUndo/canRedo 갱신 반영', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => result.current.push({ snapshot: 'A', actionKind: 'insert' }));
    act(() => result.current.push({ snapshot: 'B', actionKind: 'remove' }));
    (window as any).__g7Devtools.trackEditorHistoryEntry.mockClear();

    act(() => {
      result.current.undo();
    });

    const calls = (window as any).__g7Devtools.trackEditorHistoryEntry.mock.calls;
    expect(calls.length).toBe(1);
    const entry = calls[0][0];
    expect(entry.op).toBe('undo');
    expect(entry.canUndo).toBe(false);
    expect(entry.canRedo).toBe(true);
  });

  it('redo / clear 도 op 분류 적재', () => {
    const { result } = renderHook(() => useEditorHistory<string>());
    act(() => result.current.push({ snapshot: 'A' }));
    act(() => result.current.push({ snapshot: 'B' }));
    act(() => {
      result.current.undo();
    });
    (window as any).__g7Devtools.trackEditorHistoryEntry.mockClear();

    act(() => {
      result.current.redo();
    });
    act(() => {
      result.current.clear();
    });

    const calls = (window as any).__g7Devtools.trackEditorHistoryEntry.mock.calls;
    expect(calls.map((c: any[]) => c[0].op)).toEqual(['redo', 'clear']);
  });

  it('devtools 가 없으면 no-op (예외 미발생)', () => {
    (window as any).__g7Devtools = undefined;
    const { result } = renderHook(() => useEditorHistory<string>());
    expect(() => act(() => result.current.push({ snapshot: 'A' }))).not.toThrow();
  });
});
