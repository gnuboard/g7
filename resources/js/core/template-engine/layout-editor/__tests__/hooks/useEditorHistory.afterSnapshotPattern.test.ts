/**
 * useEditorHistory — "after 스냅샷 + baseline" 사용 패턴 회귀
 *
 * EditorCanvasOverlay 가 채택한 호출 패턴:
 *  - 문서 로드 직후 1회: baseline(현재 components) push  → cursor=0
 *  - 변경 후: 변경 결과(nextComponents) push              → cursor=1, canUndo=true
 *  - undo: cursor=0 의 baseline 스냅샷 반환 (= 원복)
 *
 * 결함 회고: 기존 EditorCanvasOverlay 는 baseline 없이 "변경 전 스냅샷(beforeSnap)"
 * 만 push 했고, useEditorHistory 의 canUndo 가 `cursor > 0` 조건이라 첫 push 후에도
 * cursor=0 → canUndo 영구 false. 결과적으로 Toolbar 실행취소/다시실행 버튼이 영원히
 * 비활성, Ctrl+Z 도 history.undo() 가 null 반환으로 미동작.
 *
 * 본 테스트는 hook 자체 동작은 그대로 두고 (단위 시그니처 호환), 사용 패턴이
 * "baseline + 변경 후 푸시" 시 canUndo true + undo 시 baseline 반환을 보장한다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useEditorHistory } from '../../hooks/useEditorHistory';

interface Snap {
  components: Array<{ name: string }>;
}

describe('useEditorHistory — "after 스냅샷 + baseline" 사용 패턴', () => {
  it('baseline 1회 + insert 1회 → canUndo true (직전 결함 회귀)', () => {
    const { result } = renderHook(() => useEditorHistory<Snap>());

    // 문서 로드 직후 baseline push (EditorCanvasOverlay 가 useEffect 로 수행)
    act(() =>
      result.current.push({
        actionKind: 'inline_text_edit',
        label: 'baseline',
        snapshot: { components: [{ name: 'Header' }] },
      })
    );

    // 첫 요소 추가 — 변경 *후* 스냅샷 push (EditorCanvasOverlay.handleInsert)
    act(() =>
      result.current.push({
        actionKind: 'insert',
        label: 'insert Div',
        snapshot: { components: [{ name: 'Header' }, { name: 'Div' }] },
      })
    );

    // 회귀 핵심: 첫 요소 추가 후 즉시 canUndo true 여야 한다
    // (이전 결함: beforeSnap 1회만 push → cursor=0 → canUndo false 였음)
    expect(result.current.canUndo).toBe(true);
    expect(result.current.canRedo).toBe(false);
  });

  it('undo → baseline 스냅샷 반환 (원복 동작 검증)', () => {
    const { result } = renderHook(() => useEditorHistory<Snap>());

    const baseline: Snap = { components: [{ name: 'Header' }] };
    const afterInsert: Snap = { components: [{ name: 'Header' }, { name: 'Div' }] };

    act(() => result.current.push({ snapshot: baseline, label: 'baseline' }));
    act(() => result.current.push({ snapshot: afterInsert, label: 'insert Div' }));

    let entry: any;
    act(() => {
      entry = result.current.undo();
    });

    expect(entry).not.toBeNull();
    // 회귀 핵심: undo 시 baseline 으로 원복 (EditorCanvasOverlay 가 setLayoutComponents
    // 에 이 스냅샷을 적용하면 추가된 Div 가 사라지고 Header 만 남는다)
    expect(entry?.snapshot).toEqual(baseline);
    expect(result.current.canUndo).toBe(false); // baseline 까지 왔으니 더 undo 불가
    expect(result.current.canRedo).toBe(true);
  });

  it('undo 후 redo → 변경 후 스냅샷 복원', () => {
    const { result } = renderHook(() => useEditorHistory<Snap>());

    const baseline: Snap = { components: [{ name: 'Header' }] };
    const afterInsert: Snap = { components: [{ name: 'Header' }, { name: 'Div' }] };

    act(() => result.current.push({ snapshot: baseline }));
    act(() => result.current.push({ snapshot: afterInsert }));

    act(() => {
      result.current.undo();
    });

    let entry: any;
    act(() => {
      entry = result.current.redo();
    });

    expect(entry?.snapshot).toEqual(afterInsert);
    expect(result.current.canUndo).toBe(true);
    expect(result.current.canRedo).toBe(false);
  });

  it('baseline + insert ×3 → 모두 undo 가능 + 마지막은 baseline 반환', () => {
    const { result } = renderHook(() => useEditorHistory<Snap>());

    const snapshots: Snap[] = [
      { components: [{ name: 'Header' }] },
      { components: [{ name: 'Header' }, { name: 'Div' }] },
      { components: [{ name: 'Header' }, { name: 'Div' }, { name: 'Span' }] },
      { components: [{ name: 'Header' }, { name: 'Div' }, { name: 'Span' }, { name: 'P' }] },
    ];

    for (const snap of snapshots) {
      act(() => result.current.push({ snapshot: snap }));
    }

    // 마지막 push 후 cursor=3 → canUndo true
    expect(result.current.canUndo).toBe(true);

    // 3번 undo → 마지막은 baseline
    let entry: any;
    for (let i = snapshots.length - 2; i >= 0; i--) {
      act(() => {
        entry = result.current.undo();
      });
      expect(entry?.snapshot).toEqual(snapshots[i]);
    }

    expect(result.current.canUndo).toBe(false);
    expect(result.current.canRedo).toBe(true);
  });
});
