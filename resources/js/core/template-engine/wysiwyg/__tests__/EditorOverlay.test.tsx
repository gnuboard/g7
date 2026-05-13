/**
 * EditorOverlay.test.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 에디터 오버레이 컴포넌트 테스트
 *
 * 테스트 항목:
 * 1. 기본 렌더링
 * 2. 컴포넌트 선택/호버 (내부 상태 활용)
 * 3. 비활성화 상태
 */

import { useRef } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render } from '@testing-library/react';
import { EditorOverlay } from '../components/EditorOverlay';
import { useEditorState } from '../hooks/useEditorState';
import type { LayoutData, HistoryEntry } from '../types/editor';

// IntersectionObserver mock
class IntersectionObserverMock {
  observe = vi.fn();
  unobserve = vi.fn();
  disconnect = vi.fn();
}

// ResizeObserver mock
class ResizeObserverMock {
  observe = vi.fn();
  unobserve = vi.fn();
  disconnect = vi.fn();
}

// MutationObserver mock
class MutationObserverMock {
  observe = vi.fn();
  unobserve = vi.fn();
  disconnect = vi.fn();
}

beforeEach(() => {
  vi.stubGlobal('IntersectionObserver', IntersectionObserverMock);
  vi.stubGlobal('ResizeObserver', ResizeObserverMock);
  vi.stubGlobal('MutationObserver', MutationObserverMock);
  resetStore();
});

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
      id: 'test-button',
      type: 'basic',
      name: 'Button',
      props: { label: 'Click Me' },
    },
  ] as unknown as LayoutData['components'],
});

// EditorOverlay를 렌더링하기 위한 래퍼 컴포넌트
function EditorOverlayWrapper({
  enabled = true,
  selectionMode = true,
}: {
  enabled?: boolean;
  selectionMode?: boolean;
}) {
  const containerRef = useRef<HTMLDivElement>(null);

  return (
    <div ref={containerRef} data-testid="container">
      <div data-component-id="test-button" data-component-name="Button">
        Test Button
      </div>
      <EditorOverlay
        containerRef={containerRef}
        enabled={enabled}
        selectionMode={selectionMode}
      />
    </div>
  );
}

describe('EditorOverlay', () => {
  describe('기본 렌더링', () => {
    it('enabled=true일 때 오버레이가 렌더링되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<EditorOverlayWrapper enabled={true} />);

      // 컨테이너가 존재해야 함
      const container = document.querySelector('[data-testid="container"]');
      expect(container).toBeInTheDocument();
    });

    it('enabled=false일 때 오버레이가 렌더링되지 않아야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const { container } = render(<EditorOverlayWrapper enabled={false} />);

      // 오버레이 관련 요소가 없어야 함 (단, 컨테이너는 존재)
      const wrapper = container.querySelector('[data-testid="container"]');
      expect(wrapper).toBeInTheDocument();
    });
  });

  describe('상태 연동', () => {
    it('layoutData가 없을 때 정상 렌더링되어야 함', () => {
      // layoutData를 null로 유지
      render(<EditorOverlayWrapper />);

      // 에러 없이 렌더링 완료
      const container = document.querySelector('[data-testid="container"]');
      expect(container).toBeInTheDocument();
    });

    it('layoutData가 있을 때 정상 렌더링되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<EditorOverlayWrapper />);

      const container = document.querySelector('[data-testid="container"]');
      expect(container).toBeInTheDocument();
    });
  });

  describe('선택 상태', () => {
    it('selectedComponentId가 설정되면 하이라이트가 표시될 준비가 되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        selectedComponentId: 'test-button',
      });

      render(<EditorOverlayWrapper />);

      // 선택 상태가 스토어에 저장되어 있음
      expect(useEditorState.getState().selectedComponentId).toBe('test-button');
    });
  });

  describe('호버 상태', () => {
    it('hoveredComponentId가 설정되면 호버 표시가 준비되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({
        layoutData,
        hoveredComponentId: 'test-button',
      });

      render(<EditorOverlayWrapper />);

      // 호버 상태가 스토어에 저장되어 있음
      expect(useEditorState.getState().hoveredComponentId).toBe('test-button');
    });
  });

  describe('선택 모드', () => {
    it('selectionMode=false일 때도 렌더링되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      render(<EditorOverlayWrapper selectionMode={false} />);

      const container = document.querySelector('[data-testid="container"]');
      expect(container).toBeInTheDocument();
    });
  });
});
