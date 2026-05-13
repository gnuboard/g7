/**
 * useDragDrop.test.ts
 *
 * G7 위지윅 레이아웃 편집기 - 드래그앤드롭 훅 테스트
 *
 * 테스트 항목:
 * 1. 초기 상태
 * 2. 드래그 시작/종료
 * 3. 드롭 위치 계산
 * 4. 컴포넌트 생성
 * 5. 드롭 유효성 검사
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useDragDrop } from '../hooks/useDragDrop';
import { useEditorState } from '../hooks/useEditorState';
import type { LayoutData, HistoryEntry, ComponentDefinition } from '../types/editor';
import type { ComponentMetadata } from '../../ComponentRegistry';

// ============================================================================
// 테스트 데이터
// ============================================================================

const createTestLayoutData = (): LayoutData => ({
  version: '1.0.0',
  layout_name: 'test-layout',
  meta: { title: 'Test Layout' },
  data_sources: [],
  components: [
    {
      id: 'root',
      type: 'layout',
      name: 'Container',
      props: {},
      children: [
        {
          id: 'header',
          type: 'composite',
          name: 'PageHeader',
          props: {},
        },
        {
          id: 'content',
          type: 'layout',
          name: 'Div',
          props: {},
          children: [
            {
              id: 'button-1',
              type: 'basic',
              name: 'Button',
              props: { label: 'Click Me' },
            },
          ],
        },
      ],
    },
  ] as unknown as LayoutData['components'],
});

const createMockComponentMetadata = (): ComponentMetadata => ({
  name: 'Button',
  type: 'basic',
  description: 'Button 컴포넌트',
  props: {
    label: { type: 'string', description: '버튼 텍스트' },
    variant: { type: 'string', description: '버튼 스타일', defaultValue: 'primary' },
  },
});

// 스토어 초기화 헬퍼
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
    history: [] as HistoryEntry[],
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

// ============================================================================
// 테스트
// ============================================================================

describe('useDragDrop', () => {
  beforeEach(() => {
    resetStore();
  });

  describe('초기 상태', () => {
    it('드래그 중이 아닌 상태로 시작해야 함', () => {
      const { result } = renderHook(() => useDragDrop());

      expect(result.current.isDragging).toBe(false);
      expect(result.current.dragItem).toBeNull();
      expect(result.current.dropTarget).toBeNull();
    });
  });

  describe('드래그 시작', () => {
    it('팔레트에서 드래그 시작 시 상태가 업데이트되어야 함', () => {
      const { result } = renderHook(() => useDragDrop());
      const metadata = createMockComponentMetadata();

      act(() => {
        result.current.handleDragStart('palette', metadata);
      });

      expect(result.current.isDragging).toBe(true);
      expect(result.current.dragItem).not.toBeNull();
      expect(result.current.dragItem?.source).toBe('palette');
      expect(result.current.dragItem?.componentMetadata).toBe(metadata);
    });

    it('트리에서 드래그 시작 시 컴포넌트 ID가 저장되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useDragDrop());

      act(() => {
        result.current.handleDragStart('tree', 'button-1');
      });

      expect(result.current.isDragging).toBe(true);
      expect(result.current.dragItem?.source).toBe('tree');
      expect(result.current.dragItem?.componentId).toBe('button-1');
    });
  });

  describe('드래그 종료', () => {
    it('드래그 종료 시 상태가 초기화되어야 함', () => {
      const { result } = renderHook(() => useDragDrop());
      const metadata = createMockComponentMetadata();

      // 드래그 시작
      act(() => {
        result.current.handleDragStart('palette', metadata);
      });

      expect(result.current.isDragging).toBe(true);

      // 드래그 종료
      act(() => {
        result.current.handleDragEnd();
      });

      expect(result.current.isDragging).toBe(false);
      expect(result.current.dragItem).toBeNull();
    });
  });

  describe('드롭 위치 계산', () => {
    it('상단 영역에서는 "before" 위치를 반환해야 함', () => {
      const { result } = renderHook(() => useDragDrop());

      const mockElement = {
        getBoundingClientRect: () => ({
          top: 0,
          bottom: 100,
          left: 0,
          right: 200,
          height: 100,
          width: 200,
        }),
      } as HTMLElement;

      const mockEvent = {
        clientY: 10, // 상단 10% 위치
      } as React.DragEvent;

      const position = result.current.calculateDropPosition(mockEvent, mockElement);
      expect(position).toBe('before');
    });

    it('하단 영역에서는 "after" 위치를 반환해야 함', () => {
      const { result } = renderHook(() => useDragDrop());

      const mockElement = {
        getBoundingClientRect: () => ({
          top: 0,
          bottom: 100,
          left: 0,
          right: 200,
          height: 100,
          width: 200,
        }),
      } as HTMLElement;

      const mockEvent = {
        clientY: 90, // 하단 10% 위치
      } as React.DragEvent;

      const position = result.current.calculateDropPosition(mockEvent, mockElement);
      expect(position).toBe('after');
    });

    it('중앙 영역에서는 "inside" 위치를 반환해야 함', () => {
      const { result } = renderHook(() => useDragDrop());

      const mockElement = {
        getBoundingClientRect: () => ({
          top: 0,
          bottom: 100,
          left: 0,
          right: 200,
          height: 100,
          width: 200,
        }),
      } as HTMLElement;

      const mockEvent = {
        clientY: 50, // 중앙 50% 위치
      } as React.DragEvent;

      const position = result.current.calculateDropPosition(mockEvent, mockElement);
      expect(position).toBe('inside');
    });
  });

  describe('컴포넌트 생성', () => {
    it('팔레트 아이템에서 컴포넌트 정의를 생성해야 함', () => {
      const { result } = renderHook(() => useDragDrop());
      const metadata = createMockComponentMetadata();

      const component = result.current.createComponentFromPalette(metadata, false);

      expect(component).toHaveProperty('id');
      expect(component.name).toBe('Button');
      expect(component.type).toBe('basic');
      expect(component.props).toHaveProperty('variant', 'primary');
    });

    it('생성된 컴포넌트는 고유한 ID를 가져야 함', () => {
      const { result } = renderHook(() => useDragDrop());
      const metadata = createMockComponentMetadata();

      const component1 = result.current.createComponentFromPalette(metadata, false);
      const component2 = result.current.createComponentFromPalette(metadata, false);

      expect(component1.id).not.toBe(component2.id);
    });

    it('기본값이 있는 props가 포함되어야 함', () => {
      const { result } = renderHook(() => useDragDrop());
      const metadata: ComponentMetadata = {
        name: 'Input',
        type: 'basic',
        description: 'Input 컴포넌트',
        props: {
          placeholder: { type: 'string', defaultValue: '입력하세요...' },
          disabled: { type: 'boolean', defaultValue: false },
        },
      };

      const component = result.current.createComponentFromPalette(metadata, false);

      expect(component.props).toEqual({
        placeholder: '입력하세요...',
        disabled: false,
      });
    });
  });

  describe('드롭 유효성 검사', () => {
    it('자기 자신에게는 드롭할 수 없어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useDragDrop());

      // button-1을 드래그 시작
      act(() => {
        result.current.handleDragStart('tree', 'button-1');
      });

      // button-1에 드롭 시도
      const canDrop = result.current.canDropAt('button-1', 'inside');
      expect(canDrop).toBe(false);
    });

    it('자식 컴포넌트에 부모를 드롭할 수 없어야 함 (순환 참조 방지)', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useDragDrop());

      // content(부모)를 드래그 시작
      act(() => {
        result.current.handleDragStart('tree', 'content');
      });

      // button-1(자식)에 드롭 시도
      const canDrop = result.current.canDropAt('button-1', 'inside');
      expect(canDrop).toBe(false);
    });

    it('형제 컴포넌트에는 드롭할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useDragDrop());

      // header를 드래그 시작
      act(() => {
        result.current.handleDragStart('tree', 'header');
      });

      // content(형제)에 드롭 시도
      const canDrop = result.current.canDropAt('content', 'before');
      expect(canDrop).toBe(true);
    });
  });

  describe('드래그 오버', () => {
    it('드래그 오버 시 드롭 대상이 업데이트되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { result } = renderHook(() => useDragDrop());
      const metadata = createMockComponentMetadata();

      // 드래그 시작
      act(() => {
        result.current.handleDragStart('palette', metadata);
      });

      // 드래그 오버
      const mockElement = {
        getBoundingClientRect: () => ({
          top: 0,
          bottom: 100,
          left: 0,
          right: 200,
          height: 100,
          width: 200,
        }),
      } as HTMLElement;

      const mockEvent = {
        clientY: 50,
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
      } as unknown as React.DragEvent;

      act(() => {
        result.current.handleDragOver(mockEvent, 'content', mockElement);
      });

      expect(result.current.dropTarget).not.toBeNull();
      expect(result.current.dropTarget?.targetId).toBe('content');
      expect(result.current.dropTarget?.position).toBe('inside');
    });
  });

  describe('드롭 처리', () => {
    it('팔레트에서 드롭 시 새 컴포넌트가 추가되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        dropPosition: 'inside',
        dropTargetId: 'content',
      });

      const { result } = renderHook(() => useDragDrop());
      const metadata = createMockComponentMetadata();

      // 드래그 시작
      act(() => {
        result.current.handleDragStart('palette', metadata);
      });

      // 드롭
      const mockEvent = {
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
      } as unknown as React.DragEvent;

      act(() => {
        result.current.handleDrop(mockEvent, 'content');
      });

      // 드래그 상태가 초기화되어야 함
      expect(result.current.isDragging).toBe(false);
    });
  });

  describe('이벤트 핸들러', () => {
    it('handleDragEnter가 기본 동작을 방지해야 함', () => {
      const { result } = renderHook(() => useDragDrop());

      const mockEvent = {
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
      } as unknown as React.DragEvent;

      act(() => {
        result.current.handleDragEnter(mockEvent, 'target-id');
      });

      expect(mockEvent.preventDefault).toHaveBeenCalled();
      expect(mockEvent.stopPropagation).toHaveBeenCalled();
    });

    it('handleDragLeave가 기본 동작을 방지해야 함', () => {
      const { result } = renderHook(() => useDragDrop());

      const mockEvent = {
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
      } as unknown as React.DragEvent;

      act(() => {
        result.current.handleDragLeave(mockEvent);
      });

      expect(mockEvent.preventDefault).toHaveBeenCalled();
      expect(mockEvent.stopPropagation).toHaveBeenCalled();
    });
  });
});
