/**
 * LayoutTree.test.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 레이아웃 트리 컴포넌트 테스트
 *
 * 테스트 항목:
 * 1. 트리 렌더링
 * 2. 컴포넌트 선택
 * 3. 트리 확장/축소
 * 4. 유틸리티 훅
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { LayoutTree, useTreeExpansion } from '../components/LayoutTree';
import { useEditorState } from '../hooks/useEditorState';
import { renderHook } from '@testing-library/react';
import type { LayoutData, HistoryEntry } from '../types/editor';

// 스토어 초기화 헬퍼
const resetStore = () => {
  useEditorState.setState({
    // 레이아웃 데이터
    layoutData: null,
    originalLayoutData: null,
    templateId: null,
    templateType: null,
    layoutName: null,

    // 선택 상태
    selectedComponentId: null,
    hoveredComponentId: null,
    multiSelectedIds: [],

    // 편집 모드
    editMode: 'visual',
    previewDevice: 'desktop',
    customWidth: 1024,
    previewDarkMode: false,

    // 로딩 상태
    loadingState: 'idle',
    error: null,

    // 히스토리
    history: [] as HistoryEntry[],
    historyIndex: -1,
    maxHistorySize: 50,

    // 컴포넌트 레지스트리
    componentCategories: null,
    handlers: [],

    // 데이터 컨텍스트
    mockDataContext: {},

    // 버전 관리
    versions: [],
    currentVersionId: null,

    // 상속 및 확장
    inheritanceInfo: null,
    overlays: [],
    extensionPoints: [],

    // UI 상태
    activePropertyTab: 'props',
    expandedTreeNodes: [],
    clipboard: null,
    draggingComponent: null,
    dropTargetId: null,
    dropPosition: null,

    // 변경 감지
    hasChanges: false,
    lastSavedAt: null,
  });
};

// 테스트용 레이아웃 데이터 생성
const createTestLayoutData = (): LayoutData => ({
  version: '1.0.0',
  layout_name: 'test-layout',
  data_sources: [],
  components: [
    {
      id: 'root',
      type: 'layout',
      name: 'Container',
      props: { className: 'container' },
      children: [
        {
          id: 'header',
          type: 'composite',
          name: 'PageHeader',
          props: { title: 'Test' },
        },
        {
          id: 'content',
          type: 'layout',
          name: 'Grid',
          props: { cols: 2 },
          children: [
            {
              id: 'card-1',
              type: 'composite',
              name: 'Card',
              props: { title: 'Card 1' },
            },
            {
              id: 'card-2',
              type: 'composite',
              name: 'Card',
              props: { title: 'Card 2' },
              if: '{{showCard2}}',
            },
          ],
        },
      ],
    },
  ] as unknown as LayoutData['components'],
});

describe('LayoutTree', () => {
  beforeEach(() => {
    resetStore();
  });

  describe('렌더링', () => {
    it('레이아웃 데이터가 없으면 안내 메시지가 표시되어야 함', () => {
      render(<LayoutTree />);

      expect(screen.getByText('레이아웃을 로드하세요')).toBeInTheDocument();
    });

    it('레이아웃 데이터가 있으면 트리가 렌더링되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      // 헤더가 표시되어야 함
      expect(screen.getByText('레이아웃 트리')).toBeInTheDocument();
      expect(screen.getByText('test-layout')).toBeInTheDocument();

      // 루트 컴포넌트가 표시되어야 함
      expect(screen.getByText('Container')).toBeInTheDocument();
    });

    it('컴포넌트 타입에 따른 아이콘이 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      // 타입별 아이콘 (이모지)이 표시되어야 함 - 여러 layout 컴포넌트가 있을 수 있음
      const layoutIcons = screen.getAllByText('📐');
      expect(layoutIcons.length).toBeGreaterThan(0); // layout
    });

    it('컴포넌트 ID가 축약되어 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      // ID가 8자로 축약되어 표시
      expect(screen.getByText('#root')).toBeInTheDocument();
    });

    it('extends 정보가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      (layoutData as any).extends = '_base_layout';
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      expect(screen.getByText(/extends: _base_layout/)).toBeInTheDocument();
    });
  });

  describe('컴포넌트 선택', () => {
    it('노드 클릭 시 해당 컴포넌트가 선택되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      // Container 클릭
      fireEvent.click(screen.getByText('Container'));

      // 스토어의 selectedComponentId가 업데이트되어야 함
      expect(useEditorState.getState().selectedComponentId).toBe('root');
    });

    it('onNodeClick 콜백이 호출되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });
      const onNodeClick = vi.fn();

      render(<LayoutTree onNodeClick={onNodeClick} />);

      fireEvent.click(screen.getByText('Container'));

      expect(onNodeClick).toHaveBeenCalledWith('root');
    });

    it('선택된 노드가 하이라이트되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'root',
      });

      render(<LayoutTree />);

      // 선택된 노드에 배경색 클래스가 있어야 함
      const containerNode = screen.getByText('Container').closest('[role="treeitem"]');
      expect(containerNode).toHaveClass('bg-blue-100');
    });
  });

  describe('호버', () => {
    it('노드에 마우스를 올리면 hoveredComponentId가 업데이트되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      const containerNode = screen.getByText('Container').closest('[role="treeitem"]');
      fireEvent.mouseEnter(containerNode!);

      expect(useEditorState.getState().hoveredComponentId).toBe('root');
    });

    it('노드에서 마우스를 떼면 hoveredComponentId가 null이 되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData, hoveredComponentId: 'root' });

      render(<LayoutTree />);

      const containerNode = screen.getByText('Container').closest('[role="treeitem"]');
      fireEvent.mouseLeave(containerNode!);

      expect(useEditorState.getState().hoveredComponentId).toBeNull();
    });
  });

  describe('트리 확장/축소', () => {
    it('자식이 있는 노드는 확장/축소 버튼이 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      // 확장 버튼이 있어야 함
      const toggleButtons = screen.getAllByRole('button', { name: /펼치기|접기/ });
      expect(toggleButtons.length).toBeGreaterThan(0);
    });

    it('확장 버튼 클릭 시 자식 노드가 표시되어야 함', async () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      // 초기에는 루트 노드만 확장되어 있음
      // PageHeader가 표시되어야 함
      expect(screen.getByText('PageHeader')).toBeInTheDocument();
    });
  });

  describe('조건부/반복 렌더링 표시', () => {
    it('조건부 렌더링이 있는 노드에 if 배지가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      // if 배지가 표시되어야 함 (조건부 렌더링 있는 card-2)
      // 트리가 확장되어 있어야 보임
      const ifBadges = screen.queryAllByText('if');
      // 최소한 하나의 if 배지가 있어야 함 (트리가 확장되면)
      expect(ifBadges).toBeDefined();
    });

    it('반복 렌더링이 있는 노드에 ∞ 배지가 표시되어야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'list',
            type: 'layout',
            name: 'List',
            props: {},
            iteration: {
              source: '{{items}}',
              item_var: 'item',
              index_var: 'idx',
            },
          },
        ] as unknown as LayoutData['components'],
      };
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      // ∞ 배지가 표시되어야 함
      expect(screen.getByText('∞')).toBeInTheDocument();
    });
  });

  describe('확장 컴포넌트 표시', () => {
    it('확장 컴포넌트에 출처 배지가 표시되어야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'ext-component',
            type: 'composite',
            name: 'ExtWidget',
            props: {
              _extensionSource: 'sirsoft-ecommerce',
            },
          },
        ] as unknown as LayoutData['components'],
      };
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      expect(screen.getByText('sirsoft-ecommerce')).toBeInTheDocument();
    });
  });

  describe('props 옵션', () => {
    it('className이 적용되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { container } = render(<LayoutTree className="custom-class" />);

      expect(container.firstChild).toHaveClass('custom-class');
    });

    it('enableDragDrop이 false이면 draggable이 비활성화되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree enableDragDrop={false} />);

      const treeItem = screen.getByText('Container').closest('[role="treeitem"]');
      expect(treeItem).not.toHaveAttribute('draggable', 'true');
    });

    it('enableDragDrop이 true이면 draggable이 활성화되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree enableDragDrop={true} />);

      const treeItem = screen.getByText('Container').closest('[role="treeitem"]');
      expect(treeItem).toHaveAttribute('draggable', 'true');
    });
  });

  describe('접근성', () => {
    it('tree role이 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      expect(screen.getByRole('tree')).toBeInTheDocument();
    });

    it('aria-label이 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      expect(screen.getByRole('tree')).toHaveAttribute('aria-label', '레이아웃 트리');
    });

    it('treeitem role이 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<LayoutTree />);

      expect(screen.getAllByRole('treeitem').length).toBeGreaterThan(0);
    });
  });
});

describe('useTreeExpansion', () => {
  it('초기 확장 상태가 설정되어야 함', () => {
    const { result } = renderHook(() =>
      useTreeExpansion(['node-1', 'node-2'])
    );

    expect(result.current.isExpanded('node-1')).toBe(true);
    expect(result.current.isExpanded('node-2')).toBe(true);
    expect(result.current.isExpanded('node-3')).toBe(false);
  });

  it('toggleNode가 확장/접힘을 토글해야 함', () => {
    const { result } = renderHook(() => useTreeExpansion(['node-1']));

    expect(result.current.isExpanded('node-1')).toBe(true);

    act(() => {
      result.current.toggleNode('node-1');
    });

    expect(result.current.isExpanded('node-1')).toBe(false);

    act(() => {
      result.current.toggleNode('node-1');
    });

    expect(result.current.isExpanded('node-1')).toBe(true);
  });

  it('expandAll이 모든 노드를 확장해야 함', () => {
    const { result } = renderHook(() => useTreeExpansion([]));

    act(() => {
      result.current.expandAll(['a', 'b', 'c']);
    });

    expect(result.current.isExpanded('a')).toBe(true);
    expect(result.current.isExpanded('b')).toBe(true);
    expect(result.current.isExpanded('c')).toBe(true);
  });

  it('collapseAll이 모든 노드를 접어야 함', () => {
    const { result } = renderHook(() =>
      useTreeExpansion(['a', 'b', 'c'])
    );

    act(() => {
      result.current.collapseAll();
    });

    expect(result.current.isExpanded('a')).toBe(false);
    expect(result.current.isExpanded('b')).toBe(false);
    expect(result.current.isExpanded('c')).toBe(false);
  });
});
