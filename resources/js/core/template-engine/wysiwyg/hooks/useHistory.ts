/**
 * useHistory.ts
 *
 * 그누보드7 위지윅 레이아웃 편집기의 Undo/Redo 훅
 *
 * 역할:
 * - 히스토리 상태 관리
 * - Undo/Redo 동작 제공
 * - 디바운스 히스토리 저장
 * - 배치 처리 지원
 * - 히스토리 관련 유틸리티
 */

import { useCallback, useMemo, useRef } from 'react';
import { useEditorState, selectCanUndo, selectCanRedo } from './useEditorState';
import type { HistoryEntry } from '../types/editor';

// ============================================================================
// 훅 타입 정의
// ============================================================================

export interface UseHistoryOptions {
  /** 디바운스 시간 (ms) - 같은 설명의 연속 변경을 병합 */
  debounceMs?: number;
  /** 최대 히스토리 크기 */
  maxSize?: number;
}

export interface UseHistoryReturn {
  /** 히스토리 스택 */
  history: HistoryEntry[];

  /** 현재 히스토리 인덱스 */
  historyIndex: number;

  /** Undo 가능 여부 */
  canUndo: boolean;

  /** Redo 가능 여부 */
  canRedo: boolean;

  /** Undo 동작 */
  undo: () => void;

  /** Redo 동작 */
  redo: () => void;

  /** 히스토리에 스냅샷 추가 */
  pushHistory: (description: string) => void;

  /** 디바운스된 히스토리 저장 (연속 변경 병합) */
  pushHistoryDebounced: (description: string) => void;

  /** 배치 시작 (여러 변경을 하나의 히스토리로) */
  startBatch: (description: string) => void;

  /** 배치 종료 */
  endBatch: () => void;

  /** 배치 진행 중 여부 */
  isBatching: boolean;

  /** 히스토리 초기화 */
  clearHistory: () => void;

  /** 특정 히스토리 항목으로 이동 */
  goToHistory: (index: number) => void;

  /** 현재 히스토리 항목 */
  currentEntry: HistoryEntry | null;

  /** 히스토리 크기 */
  historySize: number;

  /** Undo 스택 크기 */
  undoStackSize: number;

  /** Redo 스택 크기 */
  redoStackSize: number;
}

// ============================================================================
// 훅 구현
// ============================================================================

/** 기본 디바운스 시간 */
const DEFAULT_DEBOUNCE_MS = 500;

/**
 * Undo/Redo 관리 훅
 *
 * 레이아웃 편집의 히스토리를 관리합니다.
 * 디바운스 및 배치 처리를 지원합니다.
 */
export function useHistory(options: UseHistoryOptions = {}): UseHistoryReturn {
  const { debounceMs = DEFAULT_DEBOUNCE_MS } = options;

  // Zustand 상태
  const history = useEditorState((state) => state.history);
  const historyIndex = useEditorState((state) => state.historyIndex);
  const undoAction = useEditorState((state) => state.undo);
  const redoAction = useEditorState((state) => state.redo);
  const pushHistoryAction = useEditorState((state) => state.pushHistory);

  // Selector 기반
  const canUndo = useEditorState(selectCanUndo);
  const canRedo = useEditorState(selectCanRedo);

  // 디바운스/배치 관련 ref
  const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const lastDescriptionRef = useRef<string | null>(null);
  const batchDescriptionRef = useRef<string | null>(null);
  const isBatchingRef = useRef(false);

  // 히스토리 초기화
  const clearHistory = useCallback(() => {
    // 진행 중인 디바운스 취소
    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current);
      debounceTimerRef.current = null;
    }

    useEditorState.setState({
      history: [],
      historyIndex: -1,
    });
  }, []);

  // 특정 히스토리로 이동
  const goToHistory = useCallback((index: number) => {
    if (index < 0 || index >= history.length) return;

    const entry = history[index];
    if (!entry) return;

    useEditorState.setState({
      layoutData: JSON.parse(JSON.stringify(entry.layoutData)),
      historyIndex: index,
      hasChanges: true,
    });
  }, [history]);

  // 디바운스된 히스토리 저장
  const pushHistoryDebounced = useCallback(
    (description: string) => {
      // 배치 중이면 무시 (배치 종료 시 저장됨)
      if (isBatchingRef.current) return;

      // 이전 타이머 취소
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current);
      }

      // 같은 설명이면 디바운스 적용
      if (lastDescriptionRef.current === description) {
        debounceTimerRef.current = setTimeout(() => {
          pushHistoryAction(description);
          debounceTimerRef.current = null;
        }, debounceMs);
      } else {
        // 다른 설명이면 즉시 저장
        pushHistoryAction(description);
        lastDescriptionRef.current = description;
      }
    },
    [pushHistoryAction, debounceMs]
  );

  // 배치 시작
  const startBatch = useCallback((description: string) => {
    if (isBatchingRef.current) return; // 이미 배치 중

    isBatchingRef.current = true;
    batchDescriptionRef.current = description;

    // 진행 중인 디바운스 취소
    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current);
      debounceTimerRef.current = null;
    }
  }, []);

  // 배치 종료
  const endBatch = useCallback(() => {
    if (!isBatchingRef.current) return;

    const description = batchDescriptionRef.current || 'Batch update';
    pushHistoryAction(description);

    isBatchingRef.current = false;
    batchDescriptionRef.current = null;
  }, [pushHistoryAction]);

  // 현재 히스토리 항목
  const currentEntry = useMemo(() => {
    if (historyIndex < 0 || historyIndex >= history.length) return null;
    return history[historyIndex];
  }, [history, historyIndex]);

  // 계산된 값
  const historySize = history.length;
  const undoStackSize = historyIndex + 1;
  const redoStackSize = history.length - historyIndex - 1;

  return {
    history,
    historyIndex,
    canUndo,
    canRedo,
    undo: undoAction,
    redo: redoAction,
    pushHistory: pushHistoryAction,
    pushHistoryDebounced,
    startBatch,
    endBatch,
    isBatching: isBatchingRef.current,
    clearHistory,
    goToHistory,
    currentEntry,
    historySize,
    undoStackSize,
    redoStackSize,
  };
}

// ============================================================================
// 유틸리티 함수
// ============================================================================

/**
 * 히스토리 항목 비교
 *
 * @param entry1 첫 번째 항목
 * @param entry2 두 번째 항목
 * @returns 동일 여부
 */
export function compareHistoryEntries(
  entry1: HistoryEntry | null,
  entry2: HistoryEntry | null
): boolean {
  if (!entry1 || !entry2) return entry1 === entry2;
  return JSON.stringify(entry1.layoutData) === JSON.stringify(entry2.layoutData);
}

/**
 * 히스토리 요약 생성
 *
 * @param history 히스토리 배열
 * @param maxItems 최대 표시 항목 수
 * @returns 요약 문자열 배열
 */
export function summarizeHistory(
  history: HistoryEntry[],
  maxItems: number = 10
): string[] {
  return history
    .slice(-maxItems)
    .map((entry, index) => {
      const time = new Date(entry.timestamp).toLocaleTimeString();
      return `[${index + 1}] ${entry.description} (${time})`;
    });
}

/**
 * 히스토리 병합 (연속된 동일 타입의 변경을 하나로)
 *
 * @param history 원본 히스토리
 * @param mergeWindow 병합 시간 윈도우 (ms)
 * @returns 병합된 히스토리
 */
export function mergeConsecutiveHistory(
  history: HistoryEntry[],
  mergeWindow: number = 1000
): HistoryEntry[] {
  if (history.length <= 1) return history;

  const merged: HistoryEntry[] = [history[0]];

  for (let i = 1; i < history.length; i++) {
    const current = history[i];
    const previous = merged[merged.length - 1];

    // 동일한 설명이고 시간 간격이 윈도우 내인 경우 병합
    if (
      current.description === previous.description &&
      current.timestamp - previous.timestamp < mergeWindow
    ) {
      // 이전 항목의 데이터를 현재로 업데이트
      merged[merged.length - 1] = {
        ...current,
        timestamp: previous.timestamp, // 첫 번째 타임스탬프 유지
      };
    } else {
      merged.push(current);
    }
  }

  return merged;
}

// ============================================================================
// Export
// ============================================================================

export default useHistory;
