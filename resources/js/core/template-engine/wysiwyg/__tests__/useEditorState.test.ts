/**
 * useEditorState.test.ts
 *
 * G7 위지윅 레이아웃 편집기 - Zustand 상태 관리 훅 테스트
 *
 * 테스트 항목:
 * 1. 초기 상태 설정
 * 2. 컴포넌트 선택/호버
 * 3. 편집 모드 변경
 * 4. 프리뷰 디바이스
 * 5. 히스토리 (Undo/Redo)
 * 6. 셀렉터 함수
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useEditorState, selectCanUndo, selectCanRedo } from '../hooks/useEditorState';
import type { LayoutData, ComponentDefinition } from '../types/editor';

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
          type: 'basic',
          name: 'Div',
          props: {},
        },
      ],
    },
  ],
});

// 스토어 상태 초기화 헬퍼
const resetStore = () => {
  const state = useEditorState.getState();
  // 초기 상태로 직접 설정
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

describe('useEditorState', () => {
  beforeEach(() => {
    resetStore();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('초기 상태', () => {
    it('초기 상태가 올바르게 설정되어야 함', () => {
      const state = useEditorState.getState();

      expect(state.layoutData).toBeNull();
      expect(state.selectedComponentId).toBeNull();
      expect(state.hoveredComponentId).toBeNull();
      expect(state.editMode).toBe('visual');
      expect(state.hasChanges).toBe(false);
      expect(state.loadingState).toBe('idle');
    });

    it('히스토리가 비어있어야 함', () => {
      const state = useEditorState.getState();

      expect(state.history).toEqual([]);
      expect(state.historyIndex).toBe(-1);
    });
  });

  describe('컴포넌트 선택/호버', () => {
    it('컴포넌트를 선택할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();

      // 레이아웃 데이터 설정
      useEditorState.setState({ layoutData });

      // 컴포넌트 선택
      act(() => {
        useEditorState.getState().selectComponent('header');
      });

      expect(useEditorState.getState().selectedComponentId).toBe('header');
    });

    it('컴포넌트 호버 상태를 설정할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().hoverComponent('content');
      });

      expect(useEditorState.getState().hoveredComponentId).toBe('content');
    });

    it('선택 해제할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().selectComponent('header');
        useEditorState.getState().selectComponent(null);
      });

      expect(useEditorState.getState().selectedComponentId).toBeNull();
    });

    it('호버 해제할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().hoverComponent('content');
        useEditorState.getState().hoverComponent(null);
      });

      expect(useEditorState.getState().hoveredComponentId).toBeNull();
    });
  });

  describe('auto_path: ID 정규화', () => {
    // ID가 없는 컴포넌트가 포함된 테스트 레이아웃
    const createLayoutWithNoIdComponent = (): LayoutData => ({
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
              // ID 없음 - auto_path: 로 선택될 컴포넌트
              type: 'basic',
              name: 'Button',
              props: { label: 'Click' },
            },
            {
              id: 'footer',
              type: 'basic',
              name: 'Div',
              props: {},
            },
          ],
        },
      ] as any,
    });

    it('auto_path: ID로 선택 시 영구 ID가 자동 부여되어야 함', () => {
      const layoutData = createLayoutWithNoIdComponent();
      useEditorState.setState({ layoutData });

      // auto_path:0.children.0 형식으로 선택 (첫 번째 자식)
      act(() => {
        useEditorState.getState().selectComponent('auto_path:0.children.0');
      });

      const state = useEditorState.getState();

      // selectedComponentId가 auto_path: 형식이 아닌 영구 ID여야 함
      expect(state.selectedComponentId).not.toBeNull();
      expect(state.selectedComponentId).not.toContain('auto_path:');
      expect(state.selectedComponentId).toContain('button_'); // Button 컴포넌트이므로 button_ 접두사 (소문자)
    });

    it('auto_path: ID 정규화 시 hasChanges가 변경되지 않아야 함', () => {
      const layoutData = createLayoutWithNoIdComponent();
      useEditorState.setState({ layoutData, hasChanges: false });

      act(() => {
        useEditorState.getState().selectComponent('auto_path:0.children.0');
      });

      const state = useEditorState.getState();

      // hasChanges는 false로 유지되어야 함 (정책: ID 자동 부여를 변경으로 취급하지 않음)
      expect(state.hasChanges).toBe(false);
    });

    it('auto_path: ID 정규화 시 히스토리에 추가되지 않아야 함', () => {
      const layoutData = createLayoutWithNoIdComponent();
      useEditorState.setState({ layoutData, history: [], historyIndex: -1 });

      act(() => {
        useEditorState.getState().selectComponent('auto_path:0.children.0');
      });

      const state = useEditorState.getState();

      // 히스토리는 비어있어야 함 (정책: Undo 대상에서 제외)
      expect(state.history.length).toBe(0);
      expect(state.historyIndex).toBe(-1);
    });

    it('이미 ID가 있는 컴포넌트에 auto_path: 사용 시 기존 ID 유지', () => {
      const layoutData = createLayoutWithNoIdComponent();
      useEditorState.setState({ layoutData });

      // auto_path:0.children.1은 footer (이미 ID가 있음)
      act(() => {
        useEditorState.getState().selectComponent('auto_path:0.children.1');
      });

      const state = useEditorState.getState();

      // 기존 ID가 그대로 사용되어야 함
      expect(state.selectedComponentId).toBe('footer');
    });

    it('잘못된 auto_path: 경로 사용 시 원래 ID 그대로 유지', () => {
      const layoutData = createLayoutWithNoIdComponent();
      useEditorState.setState({ layoutData });

      // 존재하지 않는 경로
      act(() => {
        useEditorState.getState().selectComponent('auto_path:99.children.99');
      });

      const state = useEditorState.getState();

      // 경로가 잘못되면 원래 ID를 그대로 유지 (정규화 실패)
      // 이렇게 하면 UI에서 선택 상태가 깨지지 않고, 오류 추적이 용이함
      expect(state.selectedComponentId).toBe('auto_path:99.children.99');
    });

    it('일반 ID 선택은 정상 동작해야 함', () => {
      const layoutData = createLayoutWithNoIdComponent();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().selectComponent('root');
      });

      expect(useEditorState.getState().selectedComponentId).toBe('root');
    });
  });

  describe('편집 모드 변경', () => {
    it('편집 모드를 visual에서 json으로 변경할 수 있어야 함', () => {
      act(() => {
        useEditorState.getState().setEditMode('json');
      });

      expect(useEditorState.getState().editMode).toBe('json');
    });

    it('편집 모드를 split으로 변경할 수 있어야 함', () => {
      act(() => {
        useEditorState.getState().setEditMode('split');
      });

      expect(useEditorState.getState().editMode).toBe('split');
    });

    it('편집 모드를 다시 visual로 변경할 수 있어야 함', () => {
      act(() => {
        useEditorState.getState().setEditMode('json');
        useEditorState.getState().setEditMode('visual');
      });

      expect(useEditorState.getState().editMode).toBe('visual');
    });
  });

  describe('프리뷰 디바이스', () => {
    it('프리뷰 디바이스를 mobile로 변경할 수 있어야 함', () => {
      act(() => {
        useEditorState.getState().setPreviewDevice('mobile');
      });

      expect(useEditorState.getState().previewDevice).toBe('mobile');
    });

    it('프리뷰 디바이스를 tablet으로 변경할 수 있어야 함', () => {
      act(() => {
        useEditorState.getState().setPreviewDevice('tablet');
      });

      expect(useEditorState.getState().previewDevice).toBe('tablet');
    });

    it('커스텀 너비를 설정할 수 있어야 함', () => {
      act(() => {
        useEditorState.getState().setPreviewDevice('custom', 800);
      });

      expect(useEditorState.getState().previewDevice).toBe('custom');
      expect(useEditorState.getState().customWidth).toBe(800);
    });

    it('다크 모드를 토글할 수 있어야 함', () => {
      expect(useEditorState.getState().previewDarkMode).toBe(false);

      act(() => {
        useEditorState.getState().togglePreviewDarkMode();
      });

      expect(useEditorState.getState().previewDarkMode).toBe(true);

      act(() => {
        useEditorState.getState().togglePreviewDarkMode();
      });

      expect(useEditorState.getState().previewDarkMode).toBe(false);
    });
  });

  describe('컴포넌트 업데이트', () => {
    it('컴포넌트를 업데이트할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().updateComponent('header', {
          props: { title: 'Updated Title' },
        });
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');
      const header = root?.children?.find((c: any) => c.id === 'header');

      expect(header?.props?.title).toBe('Updated Title');
      expect(state.hasChanges).toBe(true);
    });

    it('컴포넌트 props를 업데이트할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().updateComponentProps('content', {
          className: 'new-class',
        });
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');
      const content = root?.children?.find((c: any) => c.id === 'content');

      expect(content?.props?.className).toBe('new-class');
    });
  });

  describe('컴포넌트 추가', () => {
    it('컴포넌트를 추가할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const newComponent: ComponentDefinition = {
        id: 'new-button',
        type: 'basic',
        name: 'Button',
        props: { label: 'Click me' },
      };

      act(() => {
        useEditorState.getState().addComponent(newComponent, 'root', 'inside');
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');

      // 새 컴포넌트는 고유 ID가 생성됨
      expect(root?.children?.some((c: any) => c.name === 'Button')).toBe(true);
      expect(state.hasChanges).toBe(true);
    });
  });

  describe('컴포넌트 삭제', () => {
    it('컴포넌트를 삭제할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().deleteComponent('content');
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');

      expect(root?.children?.some((c: any) => c.id === 'content')).toBe(false);
      expect(state.hasChanges).toBe(true);
    });

    it('선택된 컴포넌트 삭제 시 선택이 해제되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData, selectedComponentId: 'content' });

      act(() => {
        useEditorState.getState().deleteComponent('content');
      });

      expect(useEditorState.getState().selectedComponentId).toBeNull();
    });
  });

  describe('컴포넌트 복제', () => {
    it('컴포넌트를 복제할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().duplicateComponent('header');
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');

      // PageHeader 컴포넌트가 2개가 되어야 함 (원본 + 복제본)
      const pageHeaders = root?.children?.filter((c: any) => c.name === 'PageHeader');
      expect(pageHeaders?.length).toBe(2);
      expect(state.hasChanges).toBe(true);
    });

    it('복제된 컴포넌트가 선택되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().duplicateComponent('header');
      });

      const state = useEditorState.getState();
      // 선택된 ID는 원본 'header'가 아닌 새로 생성된 ID
      expect(state.selectedComponentId).not.toBe('header');
      expect(state.selectedComponentId).toBeTruthy();
    });
  });

  describe('컴포넌트 이동', () => {
    it('컴포넌트를 다른 위치로 이동할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      // content를 header 앞으로 이동
      act(() => {
        useEditorState.getState().moveComponent('content', 'header', 'before');
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');

      // content가 header보다 먼저 와야 함
      const contentIndex = root?.children?.findIndex((c: any) => c.id === 'content');
      const headerIndex = root?.children?.findIndex((c: any) => c.id === 'header');

      expect(contentIndex).toBeLessThan(headerIndex!);
      expect(state.hasChanges).toBe(true);
    });

    it('컴포넌트를 다른 컴포넌트 뒤로 이동할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      // header를 content 뒤로 이동
      act(() => {
        useEditorState.getState().moveComponent('header', 'content', 'after');
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');

      // content가 header보다 먼저 와야 함
      const contentIndex = root?.children?.findIndex((c: any) => c.id === 'content');
      const headerIndex = root?.children?.findIndex((c: any) => c.id === 'header');

      expect(contentIndex).toBeLessThan(headerIndex!);
    });
  });

  describe('히스토리 (Undo/Redo)', () => {
    it('히스토리에 항목을 추가할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      act(() => {
        useEditorState.getState().pushHistory('Test action');
      });

      const state = useEditorState.getState();
      expect(state.history.length).toBe(1);
      expect(state.history[0].description).toBe('Test action');
      expect(state.historyIndex).toBe(0);
    });

    it('Undo가 작동해야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      // 변경 전 히스토리 저장
      act(() => {
        useEditorState.getState().pushHistory('Before change');
      });

      // 변경 수행
      act(() => {
        useEditorState.getState().updateComponent('header', {
          props: { title: 'Changed' },
        });
      });

      // Undo 실행
      act(() => {
        useEditorState.getState().undo();
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');
      const header = root?.children?.find((c: any) => c.id === 'header');

      expect(header?.props?.title).toBe('Test');
    });

    it('Redo가 작동해야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      // 변경 수행 (이는 자동으로 히스토리 추가)
      act(() => {
        useEditorState.getState().updateComponent('header', {
          props: { title: 'Changed' },
        });
      });

      // Undo
      act(() => {
        useEditorState.getState().undo();
      });

      // Redo
      act(() => {
        useEditorState.getState().redo();
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');
      const header = root?.children?.find((c: any) => c.id === 'header');

      expect(header?.props?.title).toBe('Changed');
    });
  });

  describe('셀렉터', () => {
    it('selectCanUndo가 히스토리 있을 때 true를 반환해야 함', () => {
      const stateWithHistory = {
        historyIndex: 0,
        history: [{ layoutData: {}, description: 'test', timestamp: Date.now() }],
      };

      expect(selectCanUndo(stateWithHistory as any)).toBe(true);
    });

    it('selectCanUndo가 히스토리 없을 때 false를 반환해야 함', () => {
      const stateWithoutHistory = {
        historyIndex: -1,
        history: [],
      };

      expect(selectCanUndo(stateWithoutHistory as any)).toBe(false);
    });

    it('selectCanRedo가 redo 가능할 때 true를 반환해야 함', () => {
      const stateCanRedo = {
        historyIndex: 0,
        history: [
          { layoutData: {}, description: 'test1', timestamp: Date.now() },
          { layoutData: {}, description: 'test2', timestamp: Date.now() },
        ],
      };

      expect(selectCanRedo(stateCanRedo as any)).toBe(true);
    });

    it('selectCanRedo가 마지막 히스토리일 때 false를 반환해야 함', () => {
      const stateCannotRedo = {
        historyIndex: 1,
        history: [
          { layoutData: {}, description: 'test1', timestamp: Date.now() },
          { layoutData: {}, description: 'test2', timestamp: Date.now() },
        ],
      };

      expect(selectCanRedo(stateCannotRedo as any)).toBe(false);
    });
  });

  describe('UI 상태', () => {
    it('속성 패널 탭을 변경할 수 있어야 함', () => {
      act(() => {
        useEditorState.getState().setActivePropertyTab('bindings');
      });

      expect(useEditorState.getState().activePropertyTab).toBe('bindings');
    });

    it('트리 노드를 토글할 수 있어야 함', () => {
      act(() => {
        useEditorState.getState().toggleTreeNode('node-1');
      });

      expect(useEditorState.getState().expandedTreeNodes).toContain('node-1');

      act(() => {
        useEditorState.getState().toggleTreeNode('node-1');
      });

      expect(useEditorState.getState().expandedTreeNodes).not.toContain('node-1');
    });
  });

  describe('클립보드', () => {
    it('컴포넌트를 클립보드에 복사할 수 있어야 함', () => {
      const component: ComponentDefinition = {
        id: 'test',
        type: 'basic',
        name: 'Button',
        props: { label: 'Test' },
      };

      act(() => {
        useEditorState.getState().copyToClipboard(component);
      });

      expect(useEditorState.getState().clipboard).toEqual(component);
    });

    it('클립보드에서 붙여넣기할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      const component: ComponentDefinition = {
        id: 'original',
        type: 'basic',
        name: 'Button',
        props: { label: 'Test' },
      };

      useEditorState.setState({ layoutData, clipboard: component });

      act(() => {
        useEditorState.getState().pasteFromClipboard('root', 'inside');
      });

      const state = useEditorState.getState();
      const root = state.layoutData?.components.find((c: any) => c.id === 'root');

      // 붙여넣기된 컴포넌트가 있어야 함 (새 ID로)
      expect(root?.children?.some((c: any) => c.name === 'Button')).toBe(true);
    });
  });

  describe('드래그앤드롭', () => {
    it('드래그 시작을 설정할 수 있어야 함', () => {
      const component: ComponentDefinition = {
        id: 'test',
        type: 'basic',
        name: 'Button',
        props: {},
      };

      act(() => {
        useEditorState.getState().startDrag(component);
      });

      expect(useEditorState.getState().draggingComponent).toEqual(component);
    });

    it('드래그 오버 상태를 설정할 수 있어야 함', () => {
      act(() => {
        useEditorState.getState().dragOver('target-id', 'after');
      });

      expect(useEditorState.getState().dropTargetId).toBe('target-id');
      expect(useEditorState.getState().dropPosition).toBe('after');
    });

    it('드래그 종료 시 상태가 초기화되어야 함', () => {
      const component: ComponentDefinition = {
        id: 'test',
        type: 'basic',
        name: 'Button',
        props: {},
      };

      act(() => {
        useEditorState.getState().startDrag(component);
        useEditorState.getState().dragOver('target-id', 'after');
        useEditorState.getState().endDrag();
      });

      const state = useEditorState.getState();
      expect(state.draggingComponent).toBeNull();
      expect(state.dropTargetId).toBeNull();
      expect(state.dropPosition).toBeNull();
    });
  });
});
