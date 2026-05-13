/**
 * useHistory.test.ts
 *
 * G7 위지윅 레이아웃 편집기 - Undo/Redo 훅 테스트
 *
 * 테스트 항목:
 * 1. Undo 기능
 * 2. Redo 기능
 * 3. 히스토리 크기 관리
 * 4. 히스토리 초기화
 * 5. 유틸리티 함수
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useHistory, compareHistoryEntries, summarizeHistory, mergeConsecutiveHistory } from '../hooks/useHistory';
import { useEditorState } from '../hooks/useEditorState';
import type { LayoutData, HistoryEntry } from '../types/editor';

// 테스트용 레이아웃 데이터
const createTestLayoutData = (): LayoutData => ({
  version: '1.0.0',
  layout_name: 'test-layout',
  data_sources: [],
  components: [
    {
      id: 'root',
      type: 'layout',
      name: 'Container',
      children: [
        {
          id: 'button-1',
          type: 'basic',
          name: 'Button',
          props: { label: 'Original' },
        },
      ],
    },
  ],
});

// 스토어 상태 초기화 헬퍼
const resetStore = () => {
  useEditorState.setState({
    layoutData: null,
    originalLayoutData: null,
    templateId: null,
    templateType: null,
    layoutName: null,
    selectedComponentId: null,
    hoveredComponentId: null,
    multiSelectedIds: [],
    editMode: 'visual',
    previewDevice: 'desktop',
    customWidth: 1024,
    previewDarkMode: false,
    loadingState: 'idle',
    error: null,
    history: [],
    historyIndex: -1,
    maxHistorySize: 50,
    componentCategories: null,
    handlers: [],
    mockDataContext: {},
    versions: [],
    currentVersionId: null,
    inheritanceInfo: null,
    overlays: [],
    extensionPoints: [],
    activePropertyTab: 'props',
    expandedTreeNodes: [],
    clipboard: null,
    draggingComponent: null,
    dropTargetId: null,
    dropPosition: null,
    hasChanges: false,
    lastSavedAt: null,
  });
};

describe('useHistory', () => {
  beforeEach(() => {
    resetStore();
  });

  describe('기본 기능', () => {
    it('초기 상태에서 undo/redo가 불가능해야 함', () => {
      const { result } = renderHook(() => useHistory());

      expect(result.current.canUndo).toBe(false);
      expect(result.current.canRedo).toBe(false);
    });

    it('히스토리 크기 정보를 제공해야 함', () => {
      const { result } = renderHook(() => useHistory());

      expect(result.current.historySize).toBe(0);
      expect(result.current.undoStackSize).toBe(0);
      expect(result.current.redoStackSize).toBe(0);
    });

    it('현재 히스토리 항목이 없어야 함', () => {
      const { result } = renderHook(() => useHistory());

      expect(result.current.currentEntry).toBeNull();
    });
  });

  describe('히스토리 추가', () => {
    it('히스토리에 항목을 추가할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useHistory());

      act(() => {
        result.current.pushHistory('First change');
      });

      expect(result.current.historySize).toBe(1);
      expect(result.current.history[0].description).toBe('First change');
    });

    it('여러 항목을 히스토리에 추가할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useHistory());

      act(() => {
        result.current.pushHistory('First change');
        result.current.pushHistory('Second change');
        result.current.pushHistory('Third change');
      });

      expect(result.current.historySize).toBe(3);
      expect(result.current.undoStackSize).toBe(3);
    });
  });

  describe('Undo 기능', () => {
    it('히스토리 추가 후 undo가 가능해야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useHistory());

      act(() => {
        result.current.pushHistory('Change 1');
      });

      expect(result.current.canUndo).toBe(true);
    });

    it('undo 함수가 제공되어야 함', () => {
      const { result } = renderHook(() => useHistory());

      expect(typeof result.current.undo).toBe('function');
    });

    it('undo 실행 후 히스토리 인덱스가 감소해야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useHistory());

      act(() => {
        result.current.pushHistory('Change 1');
        result.current.pushHistory('Change 2');
      });

      const indexBefore = result.current.historyIndex;

      act(() => {
        result.current.undo();
      });

      expect(result.current.historyIndex).toBe(indexBefore - 1);
    });
  });

  describe('Redo 기능', () => {
    it('undo 후 redo가 가능해야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useHistory());

      act(() => {
        result.current.pushHistory('Change 1');
        result.current.pushHistory('Change 2');
        result.current.undo();
      });

      expect(result.current.canRedo).toBe(true);
    });

    it('redo 함수가 제공되어야 함', () => {
      const { result } = renderHook(() => useHistory());

      expect(typeof result.current.redo).toBe('function');
    });

    it('초기 상태에서 redo가 불가능해야 함', () => {
      const { result } = renderHook(() => useHistory());

      expect(result.current.canRedo).toBe(false);
      expect(result.current.redoStackSize).toBe(0);
    });
  });

  describe('히스토리 관리', () => {
    it('clearHistory가 히스토리를 초기화해야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useHistory());

      act(() => {
        result.current.pushHistory('Change 1');
        result.current.pushHistory('Change 2');
      });

      expect(result.current.historySize).toBe(2);

      act(() => {
        result.current.clearHistory();
      });

      expect(result.current.historySize).toBe(0);
      expect(result.current.canUndo).toBe(false);
      expect(result.current.canRedo).toBe(false);
    });

    it('goToHistory로 특정 히스토리 항목으로 이동할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useHistory());

      act(() => {
        result.current.pushHistory('Change 1');
        result.current.pushHistory('Change 2');
        result.current.pushHistory('Change 3');
      });

      act(() => {
        result.current.goToHistory(0);
      });

      expect(result.current.historyIndex).toBe(0);
    });

    it('유효하지 않은 인덱스로 goToHistory 호출 시 무시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useHistory());

      act(() => {
        result.current.pushHistory('Change 1');
      });

      const indexBefore = result.current.historyIndex;

      act(() => {
        result.current.goToHistory(-5);
        result.current.goToHistory(100);
      });

      // 인덱스가 변경되지 않아야 함
      expect(result.current.historyIndex).toBe(indexBefore);
    });
  });
});

describe('useHistory 유틸리티 함수', () => {
  describe('compareHistoryEntries', () => {
    it('동일한 항목을 비교하면 true를 반환해야 함', () => {
      const entry1: HistoryEntry = {
        layoutData: { version: '1.0.0', layout_name: 'test', data_sources: [], components: [] },
        description: 'test',
        timestamp: 1000,
      };
      const entry2: HistoryEntry = {
        layoutData: { version: '1.0.0', layout_name: 'test', data_sources: [], components: [] },
        description: 'test',
        timestamp: 2000,
      };

      expect(compareHistoryEntries(entry1, entry2)).toBe(true);
    });

    it('다른 항목을 비교하면 false를 반환해야 함', () => {
      const entry1: HistoryEntry = {
        layoutData: { version: '1.0.0', layout_name: 'test1', data_sources: [], components: [] },
        description: 'test',
        timestamp: 1000,
      };
      const entry2: HistoryEntry = {
        layoutData: { version: '1.0.0', layout_name: 'test2', data_sources: [], components: [] },
        description: 'test',
        timestamp: 1000,
      };

      expect(compareHistoryEntries(entry1, entry2)).toBe(false);
    });

    it('null 값 비교가 올바르게 동작해야 함', () => {
      const entry: HistoryEntry = {
        layoutData: { version: '1.0.0', layout_name: 'test', data_sources: [], components: [] },
        description: 'test',
        timestamp: 1000,
      };

      expect(compareHistoryEntries(null, null)).toBe(true);
      expect(compareHistoryEntries(entry, null)).toBe(false);
      expect(compareHistoryEntries(null, entry)).toBe(false);
    });
  });

  describe('summarizeHistory', () => {
    it('히스토리 요약을 생성해야 함', () => {
      const history: HistoryEntry[] = [
        {
          layoutData: { version: '1.0.0', layout_name: 'test', data_sources: [], components: [] },
          description: 'First change',
          timestamp: Date.now(),
        },
        {
          layoutData: { version: '1.0.0', layout_name: 'test', data_sources: [], components: [] },
          description: 'Second change',
          timestamp: Date.now(),
        },
      ];

      const summary = summarizeHistory(history);

      expect(summary).toHaveLength(2);
      expect(summary[0]).toContain('First change');
      expect(summary[1]).toContain('Second change');
    });

    it('maxItems 파라미터가 적용되어야 함', () => {
      const history: HistoryEntry[] = Array.from({ length: 20 }, (_, i) => ({
        layoutData: { version: '1.0.0', layout_name: 'test', data_sources: [], components: [] },
        description: `Change ${i + 1}`,
        timestamp: Date.now() + i,
      }));

      const summary = summarizeHistory(history, 5);

      expect(summary).toHaveLength(5);
    });

    it('빈 히스토리에서 빈 배열을 반환해야 함', () => {
      const summary = summarizeHistory([]);

      expect(summary).toEqual([]);
    });
  });

  describe('mergeConsecutiveHistory', () => {
    it('연속된 동일 설명의 항목을 병합해야 함', () => {
      const now = Date.now();
      const history: HistoryEntry[] = [
        {
          layoutData: { version: '1.0.0', layout_name: 'test', data_sources: [], components: [] },
          description: 'Typing',
          timestamp: now,
        },
        {
          layoutData: { version: '1.0.0', layout_name: 'test2', data_sources: [], components: [] },
          description: 'Typing',
          timestamp: now + 500, // 병합 윈도우 내
        },
        {
          layoutData: { version: '1.0.0', layout_name: 'test3', data_sources: [], components: [] },
          description: 'Different action',
          timestamp: now + 2000,
        },
      ];

      const merged = mergeConsecutiveHistory(history, 1000);

      expect(merged).toHaveLength(2);
      expect(merged[0].description).toBe('Typing');
      expect(merged[1].description).toBe('Different action');
    });

    it('병합 윈도우 외의 항목은 병합하지 않아야 함', () => {
      const now = Date.now();
      const history: HistoryEntry[] = [
        {
          layoutData: { version: '1.0.0', layout_name: 'test', data_sources: [], components: [] },
          description: 'Typing',
          timestamp: now,
        },
        {
          layoutData: { version: '1.0.0', layout_name: 'test2', data_sources: [], components: [] },
          description: 'Typing',
          timestamp: now + 2000, // 병합 윈도우 외
        },
      ];

      const merged = mergeConsecutiveHistory(history, 1000);

      expect(merged).toHaveLength(2);
    });

    it('빈 히스토리는 그대로 반환해야 함', () => {
      const merged = mergeConsecutiveHistory([]);

      expect(merged).toEqual([]);
    });

    it('단일 항목 히스토리는 그대로 반환해야 함', () => {
      const history: HistoryEntry[] = [
        {
          layoutData: { version: '1.0.0', layout_name: 'test', data_sources: [], components: [] },
          description: 'Single',
          timestamp: Date.now(),
        },
      ];

      const merged = mergeConsecutiveHistory(history);

      expect(merged).toEqual(history);
    });
  });
});
