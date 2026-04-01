/**
 * PropertyPanel.test.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 속성 패널 컴포넌트 테스트
 *
 * 테스트 항목:
 * 1. 기본 렌더링
 * 2. 컴포넌트 미선택 상태
 * 3. 탭 전환
 * 4. Props 편집 (PropsEditor)
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { PropertyPanel } from '../components/PropertyPanel';
import { useEditorState } from '../hooks/useEditorState';
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
// 주의: LayoutData.components는 LayoutComponent[]로 타입되어 있지만,
// 실제 런타임에서는 ComponentDefinition[] 형태로 사용됨
const createTestLayoutData = (): LayoutData => ({
  version: '1.0.0',
  layout_name: 'test-layout',
  data_sources: [],
  components: [
    {
      id: 'test-button',
      type: 'basic',
      name: 'Button',
      props: {
        label: 'Click Me',
        variant: 'primary',
        disabled: false,
        className: 'px-4 py-2',
      },
      actions: [
        {
          on: 'click',
          handler: 'navigate',
          target: '/home',
        },
      ],
    },
    {
      id: 'test-input',
      type: 'basic',
      name: 'Input',
      props: {
        placeholder: 'Enter text',
        type: 'text',
      },
      if: '{{_local.showInput}}',
    },
  ] as unknown as LayoutData['components'],
});

describe('PropertyPanel', () => {
  beforeEach(() => {
    resetStore();
  });

  describe('기본 렌더링', () => {
    it('컴포넌트가 선택되지 않으면 안내 메시지가 표시되어야 함', () => {
      render(<PropertyPanel />);

      expect(screen.getByText('컴포넌트를 선택하세요')).toBeInTheDocument();
    });

    it('레이아웃 데이터가 없으면 안내 메시지가 표시되어야 함', () => {
      useEditorState.setState({ selectedComponentId: 'some-id' });

      render(<PropertyPanel />);

      expect(screen.getByText('컴포넌트를 선택하세요')).toBeInTheDocument();
    });

    it('선택된 컴포넌트가 없으면 안내 메시지가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData, selectedComponentId: 'non-existent' });

      render(<PropertyPanel />);

      expect(screen.getByText('컴포넌트를 선택하세요')).toBeInTheDocument();
    });
  });

  describe('컴포넌트 선택 시', () => {
    it('선택된 컴포넌트 정보가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      // 컴포넌트 이름이 표시되어야 함 - 여러 곳에서 'Button' 텍스트가 나올 수 있음
      const buttonTexts = screen.getAllByText('Button');
      expect(buttonTexts.length).toBeGreaterThan(0);
      // ID가 표시되어야 함
      expect(screen.getByText(/test-button/)).toBeInTheDocument();
    });

    it('컴포넌트 타입 배지가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      expect(screen.getByText('basic')).toBeInTheDocument();
    });
  });

  describe('탭 네비게이션', () => {
    it('모든 탭이 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      // 탭들이 표시되어야 함 (아이콘과 함께)
      expect(screen.getByTitle('기본 속성 편집')).toBeInTheDocument();
      expect(screen.getByTitle('데이터 바인딩 설정')).toBeInTheDocument();
      expect(screen.getByTitle('이벤트 핸들러 설정')).toBeInTheDocument();
      expect(screen.getByTitle('조건부/반복 렌더링')).toBeInTheDocument();
      expect(screen.getByTitle('반응형 오버라이드')).toBeInTheDocument();
      expect(screen.getByTitle('고급 설정')).toBeInTheDocument();
    });

    it('Props 탭이 기본으로 선택되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      // Props 탭이 활성화되어 있어야 함
      const propsTab = screen.getByTitle('기본 속성 편집');
      expect(propsTab).toHaveClass('border-blue-500');
    });

    it('다른 탭으로 전환할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      // Actions 탭 클릭
      const actionsTab = screen.getByTitle('이벤트 핸들러 설정');
      fireEvent.click(actionsTab);

      // 액션 탭 내용이 표시되어야 함 (새 ActionsEditor는 "등록된 액션 (N)" 형식)
      expect(screen.getByText(/등록된 액션/)).toBeInTheDocument();
    });
  });

  describe('Actions 탭', () => {
    it('등록된 액션이 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      // Actions 탭으로 전환
      const actionsTab = screen.getByTitle('이벤트 핸들러 설정');
      fireEvent.click(actionsTab);

      // 액션 정보가 표시되어야 함
      expect(screen.getByText('click')).toBeInTheDocument();
      expect(screen.getByText('navigate')).toBeInTheDocument();
    });
  });

  describe('Conditions 탭', () => {
    it('조건부 렌더링 설정이 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-input', // if 속성이 있는 컴포넌트
      });

      render(<PropertyPanel />);

      // Conditions 탭으로 전환
      const conditionsTab = screen.getByTitle('조건부/반복 렌더링');
      fireEvent.click(conditionsTab);

      // 조건부 렌더링 섹션이 표시되어야 함
      expect(screen.getByText('조건부 렌더링 (if)')).toBeInTheDocument();
    });

    it('if 값을 편집할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-input',
      });

      render(<PropertyPanel />);

      // Conditions 탭으로 전환
      const conditionsTab = screen.getByTitle('조건부/반복 렌더링');
      fireEvent.click(conditionsTab);

      // if 입력 필드가 현재 값을 표시해야 함
      const ifInput = screen.getByDisplayValue('{{_local.showInput}}');
      expect(ifInput).toBeInTheDocument();
    });
  });

  describe('Advanced 탭', () => {
    it('컴포넌트 ID가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      // Advanced 탭으로 전환
      const advancedTab = screen.getByTitle('고급 설정');
      fireEvent.click(advancedTab);

      // 컴포넌트 ID가 표시되어야 함
      expect(screen.getByDisplayValue('test-button')).toBeInTheDocument();
    });

    it('Raw JSON이 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      // Advanced 탭으로 전환
      const advancedTab = screen.getByTitle('고급 설정');
      fireEvent.click(advancedTab);

      // Raw JSON 섹션이 표시되어야 함
      expect(screen.getByText('Raw JSON')).toBeInTheDocument();
    });
  });

  describe('Bindings 탭', () => {
    it('바인딩된 props가 표시되어야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'bound-component',
            type: 'basic',
            name: 'Div',
            props: {
              className: '{{_local.dynamicClass}}',
            },
            text: '{{user.name}}',
          },
        ] as unknown as LayoutData['components'],
      };
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'bound-component',
      });

      render(<PropertyPanel />);

      // Bindings 탭으로 전환
      const bindingsTab = screen.getByTitle('데이터 바인딩 설정');
      fireEvent.click(bindingsTab);

      // 바인딩 정보가 표시되어야 함 (새 BindingsEditor는 "현재 바인딩 (N)" 형식)
      expect(screen.getByText(/현재 바인딩/)).toBeInTheDocument();
      expect(screen.getByText('className')).toBeInTheDocument();
      // 'text'는 바인딩 키와 타입 배지 두 곳에서 표시되므로 getAllByText 사용
      expect(screen.getAllByText('text').length).toBeGreaterThanOrEqual(1);
    });

    it('바인딩이 없으면 안내 메시지가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      // Bindings 탭으로 전환
      const bindingsTab = screen.getByTitle('데이터 바인딩 설정');
      fireEvent.click(bindingsTab);

      // 빈 상태 메시지가 표시되어야 함
      expect(screen.getByText('설정된 바인딩이 없습니다')).toBeInTheDocument();
    });
  });

  describe('Responsive 탭', () => {
    it('반응형 설정이 없으면 프리셋 브레이크포인트만 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<PropertyPanel />);

      // Responsive 탭으로 전환
      const responsiveTab = screen.getByTitle('반응형 오버라이드');
      fireEvent.click(responsiveTab);

      // 프리셋 브레이크포인트가 표시되어야 함
      expect(screen.getByText('프리셋 브레이크포인트')).toBeInTheDocument();
      expect(screen.getByText('모바일')).toBeInTheDocument();
      expect(screen.getByText('태블릿')).toBeInTheDocument();
      expect(screen.getByText('데스크톱')).toBeInTheDocument();
      // 활성화된 브레이크포인트가 없으면 활성화 배지가 없어야 함
      expect(screen.queryByText(/브레이크포인트 활성화/)).not.toBeInTheDocument();
    });

    it('반응형 설정이 있으면 활성화 배지가 표시되어야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'responsive-component',
            type: 'layout',
            name: 'Grid',
            props: { cols: 3 },
            responsive: {
              portable: { props: { cols: 1 } },
              desktop: { props: { cols: 4 } },
            },
          },
        ] as unknown as LayoutData['components'],
      };
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'responsive-component',
      });

      render(<PropertyPanel />);

      // Responsive 탭으로 전환
      const responsiveTab = screen.getByTitle('반응형 오버라이드');
      fireEvent.click(responsiveTab);

      // 활성화된 브레이크포인트 배지가 표시되어야 함
      expect(screen.getByText(/2개 브레이크포인트 활성화/)).toBeInTheDocument();
      // 포터블 브레이크포인트가 표시되어야 함
      expect(screen.getByText('포터블')).toBeInTheDocument();
    });
  });

  describe('className', () => {
    it('className prop이 적용되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      const { container } = render(<PropertyPanel className="custom-panel" />);

      expect(container.firstChild).toHaveClass('custom-panel');
    });
  });
});
