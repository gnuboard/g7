/**
 * useEditorHistory.ts — 실행 취소 / 다시 실행 이력 스택
 *
 * Phase 1 범위: 이력 스택 인터페이스 + push/undo/redo/canUndo/canRedo 골격만.
 * 실제 layoutDocument 변경 이력 연동은 첫 편집이 생기는 Phase 3 에서 적용.
 *
 * 8.3.5 — 이력은 **세션 한정** (편집기를 나가면 초기화).
 * `template_layout_versions` 의 버전 히스토리(저장 시점 스냅샷) 와는 별개 —
 * 전자는 in-session 편집 되돌리기, 후자는 저장된 버전 복원.
 *
 * 키보드 단축키 (Ctrl+Z / Ctrl+Shift+Z) 연결은 Phase 3 에서 추가.
 *
 * @since engine-v1.50.0
 */

import { useCallback, useRef, useState } from 'react';
import {
  trackEditorHistory,
  type EditorHistoryActionKind,
} from '../devtools/editorTrackers';

/**
 * 이력 항목 — 한 번의 `PATCH_LAYOUT` 결과 스냅샷을 보관할 때 사용.
 *
 * 5종 액션 분류 라벨이 추가됨 (insert/remove/move/property_change/inline_text_edit).
 * 트래커는 액션 종류만 적재하고 스냅샷 본체는 적재하지 않는다 (메타 누수 회귀 가드).
 */
export interface HistoryEntry<T = unknown> {
  /** 이력 항목 식별자 (디버깅용 라벨) */
  label?: string;
  /** 액션 종류 (5종 — devtools editor-history 트래커에 적재) */
  actionKind?: EditorHistoryActionKind;
  /** 스냅샷 페이로드 (Phase 3 에서 LayoutJson) */
  snapshot: T;
}

export interface UseEditorHistoryReturn<T = unknown> {
  /** 이력에 새 스냅샷 push (현재 위치 이후 redo 스택을 잘라낸다) */
  push: (entry: HistoryEntry<T>) => void;
  /** 실행 취소 — 직전 스냅샷 반환, 없으면 null */
  undo: () => HistoryEntry<T> | null;
  /** 다시 실행 — 다음 스냅샷 반환, 없으면 null */
  redo: () => HistoryEntry<T> | null;
  /** 이력 비우기 */
  clear: () => void;
  /** 실행 취소 가용 */
  canUndo: boolean;
  /** 다시 실행 가용 */
  canRedo: boolean;
}

/**
 * 세션 한정 이력 스택 hook.
 *
 * 단방향 스택 + cursor 모델 — `cursor` 가 현재 위치, undo 는 cursor 감소,
 * redo 는 cursor 증가. 새 push 는 cursor 이후 항목을 잘라내고 추가.
 *
 * @param limit 최대 스택 크기 (기본 50)
 */
export function useEditorHistory<T = unknown>(limit: number = 50): UseEditorHistoryReturn<T> {
  const stackRef = useRef<HistoryEntry<T>[]>([]);
  const cursorRef = useRef<number>(-1);
  const [, forceRender] = useState(0);

  const recompute = (): void => {
    forceRender((n) => (n + 1) % 1_000_000);
  };

  const emitTracker = (
    op: 'push' | 'undo' | 'redo' | 'clear',
    entry?: HistoryEntry<T>
  ): void => {
    const stackSize = stackRef.current.length;
    const cursor = cursorRef.current;
    trackEditorHistory({
      op,
      actionKind: entry?.actionKind,
      label: entry?.label,
      stackSize,
      cursor,
      canUndo: cursor > 0,
      canRedo: cursor >= 0 && cursor < stackSize - 1,
      timestamp: Date.now(),
    });
  };

  const push = useCallback(
    (entry: HistoryEntry<T>): void => {
      const stack = stackRef.current;
      // cursor 이후 redo 후보 제거
      const truncated = stack.slice(0, cursorRef.current + 1);
      truncated.push(entry);
      // limit 초과 시 가장 오래된 항목 drop (cursor 도 -1 보정)
      while (truncated.length > limit) {
        truncated.shift();
      }
      stackRef.current = truncated;
      cursorRef.current = truncated.length - 1;
      recompute();
      emitTracker('push', entry);
    },
    [limit]
  );

  const undo = useCallback((): HistoryEntry<T> | null => {
    if (cursorRef.current <= 0) {
      return null;
    }
    cursorRef.current -= 1;
    recompute();
    const result = stackRef.current[cursorRef.current] ?? null;
    emitTracker('undo', result ?? undefined);
    return result;
  }, []);

  const redo = useCallback((): HistoryEntry<T> | null => {
    const next = cursorRef.current + 1;
    if (next >= stackRef.current.length) {
      return null;
    }
    cursorRef.current = next;
    recompute();
    const result = stackRef.current[next] ?? null;
    emitTracker('redo', result ?? undefined);
    return result;
  }, []);

  const clear = useCallback((): void => {
    stackRef.current = [];
    cursorRef.current = -1;
    recompute();
    emitTracker('clear');
  }, []);

  return {
    push,
    undo,
    redo,
    clear,
    canUndo: cursorRef.current > 0,
    canRedo: cursorRef.current >= 0 && cursorRef.current < stackRef.current.length - 1,
  };
}
