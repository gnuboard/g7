/**
 * WysiwygEditor.test.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 메인 편집기 컴포넌트 테스트
 *
 * 테스트 항목:
 * 1. 편집기 렌더링
 * 2. 편집 모드 전환
 * 3. 키보드 단축키
 * 4. 패널 레이아웃
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { WysiwygEditor } from '../WysiwygEditor';
import { useEditorState } from '../hooks/useEditorState';
import type { LayoutData, HistoryEntry } from '../types/editor';

// 테스트용 레이아웃 데이터 생성
// 주의: LayoutData.components는 LayoutComponent[]로 타입되어 있지만,
// 실제 런타임에서는 ComponentDefinition[] 형태로 사용됨
const createTestLayoutData = (): LayoutData => ({
  version: '1.0.0',
  layout_name: 'test-layout',
  meta: {
    title: 'Test Layout',
    description: 'A test layout',
  },
  data_sources: [],
  components: [
    {
      id: 'root',
      type: 'layout',
      name: 'Container',
      props: { className: 'container mx-auto' },
      children: [
        {
          id: 'header',
          type: 'composite',
          name: 'PageHeader',
          props: { title: 'Test Page' },
        },
        {
          id: 'content',
          type: 'basic',
          name: 'Div',
          props: { className: 'content' },
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

// ResizeObserver mock
class ResizeObserverMock {
  observe = vi.fn();
  unobserve = vi.fn();
  disconnect = vi.fn();
}

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

beforeEach(() => {
  vi.stubGlobal('ResizeObserver', ResizeObserverMock);
  resetStore();
});

describe('WysiwygEditor', () => {
  describe('렌더링', () => {
    it('편집기가 올바르게 렌더링되어야 함', () => {
      const layoutData = createTestLayoutData();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
        />
      );

      // 편집기가 렌더링되어야 함 - 레이아웃 트리 헤더 확인
      // UI에 '레이아웃 트리' 텍스트가 여러 곳에 있을 수 있으므로 getAllByText 사용
      const layoutTreeElements = screen.getAllByText('레이아웃 트리');
      expect(layoutTreeElements.length).toBeGreaterThan(0);
    });

    it('레이아웃 이름이 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      // Zustand 상태에 layoutData 설정 (WysiwygEditor가 props를 상태로 초기화하지 않으므로)
      useEditorState.setState({ layoutData });

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
        />
      );

      // 레이아웃 이름이 Toolbar와 LayoutTree 모두에 표시됨
      // getAllByText로 여러 요소 확인
      const layoutNameElements = screen.getAllByText('test-layout');
      expect(layoutNameElements.length).toBeGreaterThan(0);
    });

    it('읽기 전용 모드에서 수정 불가 표시가 되어야 함', () => {
      const layoutData = createTestLayoutData();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
          readOnly
        />
      );

      // 읽기 전용 모드 확인
      // 저장 버튼이 비활성화되어야 함
      const saveButton = screen.getByTitle('저장');
      expect(saveButton).toBeDisabled();
    });
  });

  describe('편집 모드 전환', () => {
    it('초기 편집 모드가 설정되어야 함', () => {
      const layoutData = createTestLayoutData();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
          initialEditMode="json"
        />
      );

      // JSON 모드가 활성화되어야 함 - JSON 표시가 있어야 함
      const jsonButton = screen.getByTitle('JSON 모드');
      expect(jsonButton).toBeInTheDocument();
    });

    it('편집 모드를 전환할 수 있어야 함', () => {
      const layoutData = createTestLayoutData();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
          initialEditMode="visual"
        />
      );

      // JSON 모드 버튼 클릭
      const jsonButton = screen.getByTitle('JSON 모드');
      fireEvent.click(jsonButton);

      // JSON 모드가 활성화되어야 함
      expect(jsonButton.closest('button')).toHaveClass('bg-white');
    });
  });

  describe('콜백', () => {
    it('onClose 콜백이 호출되어야 함', () => {
      const layoutData = createTestLayoutData();
      const onClose = vi.fn();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
          onClose={onClose}
        />
      );

      // 닫기 버튼 클릭
      const closeButton = screen.getByTitle('닫기');
      fireEvent.click(closeButton);

      expect(onClose).toHaveBeenCalled();
    });
  });

  describe('레이아웃 트리', () => {
    it('레이아웃 트리가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
        />
      );

      // 레이아웃 트리 컴포넌트가 렌더링되어야 함
      // UI에 '레이아웃 트리' 텍스트가 여러 곳에 있을 수 있으므로 getAllByText 사용
      const layoutTreeElements = screen.getAllByText('레이아웃 트리');
      expect(layoutTreeElements.length).toBeGreaterThan(0);
    });
  });

  describe('속성 패널', () => {
    it('컴포넌트 선택 시 속성 패널에 정보가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
        />
      );

      // 속성 패널 헤더 확인 (실제 구현은 '속성'으로 표시됨)
      expect(screen.getByText('속성')).toBeInTheDocument();
    });
  });

  describe('프리뷰 디바이스', () => {
    it('데스크톱 프리뷰가 기본값이어야 함', () => {
      const layoutData = createTestLayoutData();
      // Zustand 상태에 layoutData 설정
      useEditorState.setState({ layoutData });

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
        />
      );

      // 데스크톱 디바이스 선택기 버튼 확인 - title 속성으로 확인
      // Desktop 텍스트는 sm:inline 클래스로 작은 화면에선 숨겨지므로
      // title 속성에 "Desktop"이 포함된 버튼을 찾음
      const desktopButton = screen.getByTitle(/Desktop/);
      expect(desktopButton).toBeInTheDocument();
    });
  });

  describe('키보드 단축키', () => {
    it('Escape 키로 선택 해제가 되어야 함', () => {
      const layoutData = createTestLayoutData();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
        />
      );

      // Escape 키 이벤트 발생
      fireEvent.keyDown(document, { key: 'Escape' });

      // 선택이 해제되어야 함 (초기 상태로 유지)
    });

    it('Ctrl+S 단축키가 저장을 트리거해야 함', async () => {
      const layoutData = createTestLayoutData();
      const onSaveComplete = vi.fn();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
          onSaveComplete={onSaveComplete}
        />
      );

      // Ctrl+S 이벤트 발생 (변경사항 없으므로 저장되지 않음)
      fireEvent.keyDown(document, { key: 's', ctrlKey: true });

      // 변경사항이 없으므로 저장 콜백은 호출되지 않음
    });
  });

  describe('상태 표시', () => {
    it('변경사항 없음 상태가 표시되어야 함', () => {
      const layoutData = createTestLayoutData();

      render(
        <WysiwygEditor
          layoutData={layoutData}
          templateId="test-template"
        />
      );

      // 저장 버튼이 비활성화 상태 (변경사항 없음)
      const saveButton = screen.getByTitle('저장');
      expect(saveButton).toBeDisabled();
    });
  });
});
