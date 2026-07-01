/**
 * useUndoRedoShortcuts.ts — Ctrl+Z / Ctrl+Shift+Z 단축키 hook
 *
 * 편집기 마운트 동안 window keydown 이벤트를 듣다가, **편집기 캔버스에 포커스가
 * 있거나 INPUT/TEXTAREA/CONTENTEDITABLE 에 포커스가 없을 때만** undo/redo 호출.
 *
 * 모달이 열려 있거나 입력 컨트롤이 활성일 때는 단축키를 가로채지 않는다 —
 * 사용자가 텍스트 입력 중 Ctrl+Z 를 누르면 그 입력의 undo 동작이 우선.
 *
 * @since engine-v1.50.0
 */

import { useEffect } from 'react';

export interface UseUndoRedoShortcutsParams {
  /** Ctrl+Z 또는 ⌘+Z 가 눌렸을 때 호출 */
  onUndo: () => void;
  /** Ctrl+Shift+Z / Ctrl+Y / ⌘+Shift+Z 가 눌렸을 때 호출 */
  onRedo: () => void;
  /** false 면 단축키 비활성 (모달 열림 등에서 사용) */
  enabled?: boolean;
}

function isEditableTarget(target: EventTarget | null): boolean {
  if (!target || !(target instanceof HTMLElement)) return false;
  const tag = target.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (target.isContentEditable) return true;
  return false;
}

export function useUndoRedoShortcuts(params: UseUndoRedoShortcutsParams): void {
  const { onUndo, onRedo, enabled = true } = params;

  useEffect(() => {
    if (!enabled) return;
    if (typeof window === 'undefined') return;

    const handler = (e: KeyboardEvent): void => {
      // input/textarea 에 포커스가 있는 동안에는 가로채지 않음
      if (isEditableTarget(e.target)) return;

      const isModifier = e.ctrlKey || e.metaKey;
      if (!isModifier) return;

      const key = e.key.toLowerCase();
      // Ctrl+Z = undo / Ctrl+Shift+Z 또는 Ctrl+Y = redo (Mac 도 동일 키맵 + meta)
      if (key === 'z' && !e.shiftKey) {
        e.preventDefault();
        onUndo();
        return;
      }
      if ((key === 'z' && e.shiftKey) || key === 'y') {
        e.preventDefault();
        onRedo();
        return;
      }
    };

    window.addEventListener('keydown', handler);
    return () => {
      window.removeEventListener('keydown', handler);
    };
  }, [onUndo, onRedo, enabled]);
}
