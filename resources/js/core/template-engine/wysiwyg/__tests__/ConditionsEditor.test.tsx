/**
 * ConditionsEditor.test.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 조건/반복 에디터 테스트
 *
 * 테스트 항목:
 * 1. 기본 렌더링
 * 2. if 조건 편집
 * 3. iteration 편집
 * 4. 조건 템플릿 사용
 * 5. 사용 가능한 소스 표시
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ConditionsEditor } from '../components/PropertyPanel/ConditionsEditor';
import { useEditorState } from '../hooks/useEditorState';
import type { ComponentDefinition, LayoutData, HistoryEntry } from '../types/editor';

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

// 테스트용 컴포넌트 생성
const createTestComponent = (overrides: Partial<ComponentDefinition> = {}): ComponentDefinition => ({
  id: 'test-component',
  type: 'basic',
  name: 'Div',
  props: {},
  ...overrides,
});

// 테스트용 레이아웃 데이터 생성
const createTestLayoutData = (): LayoutData => ({
  version: '1.0.0',
  layout_name: 'test-layout',
  data_sources: [
    {
      id: 'users',
      type: 'api',
      endpoint: '/api/users',
    },
    {
      id: 'products',
      type: 'api',
      endpoint: '/api/products',
    },
  ],
  components: [],
});

describe('ConditionsEditor', () => {
  beforeEach(() => {
    resetStore();
  });

  describe('기본 렌더링', () => {
    it('조건부 렌더링 섹션이 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('조건부 렌더링 (if)')).toBeInTheDocument();
    });

    it('반복 렌더링 섹션이 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('반복 렌더링 (iteration)')).toBeInTheDocument();
    });

    it('if 조건이 없을 때 placeholder가 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByPlaceholderText('{{_local.showComponent}}')).toBeInTheDocument();
    });

    it('iteration이 없을 때 안내 메시지가 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('반복 설정이 없습니다')).toBeInTheDocument();
    });
  });

  describe('if 조건 편집', () => {
    it('기존 if 조건이 표시되어야 함', () => {
      const component = createTestComponent({
        if: '{{_local.isVisible}}',
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByDisplayValue('{{_local.isVisible}}')).toBeInTheDocument();
    });

    it('if 조건 입력 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      const input = screen.getByPlaceholderText('{{_local.showComponent}}');
      fireEvent.change(input, { target: { value: '{{_local.show}}' } });

      expect(onChange).toHaveBeenCalledWith({ if: '{{_local.show}}' });
    });

    it('if 조건 삭제 시 undefined로 설정되어야 함', () => {
      const component = createTestComponent({
        if: '{{_local.isVisible}}',
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      // 삭제 버튼 클릭
      const deleteButton = screen.getByTitle('조건 삭제');
      fireEvent.click(deleteButton);

      expect(onChange).toHaveBeenCalledWith({ if: undefined });
    });

    it('if 조건 활성화 배지가 표시되어야 함', () => {
      const component = createTestComponent({
        if: '{{_local.isVisible}}',
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText(/조건부 렌더링 활성화/)).toBeInTheDocument();
    });
  });

  describe('조건 템플릿', () => {
    it('빠른 입력 버튼이 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('빠른 입력:')).toBeInTheDocument();
      expect(screen.getByText('상태 확인')).toBeInTheDocument();
      expect(screen.getByText('값 존재 확인')).toBeInTheDocument();
      expect(screen.getByText('권한 확인')).toBeInTheDocument();
    });

    it('템플릿 버튼 클릭 시 조건이 설정되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      const stateButton = screen.getByText('상태 확인');
      fireEvent.click(stateButton);

      expect(onChange).toHaveBeenCalledWith({ if: '{{_local.isVisible}}' });
    });

    it('더보기 클릭 시 확장 템플릿이 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      const moreButton = screen.getByText('더보기...');
      fireEvent.click(moreButton);

      expect(screen.getByText('조건 템플릿 선택:')).toBeInTheDocument();
      expect(screen.getByText('로딩 완료')).toBeInTheDocument();
      expect(screen.getByText('에러 없음')).toBeInTheDocument();
    });

    it('확장 템플릿에서 선택 시 조건이 설정되고 닫혀야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      // 더보기 열기
      fireEvent.click(screen.getByText('더보기...'));

      // 로딩 완료 선택
      const loadingCompleteOption = screen.getByText('로딩 완료').closest('button');
      fireEvent.click(loadingCompleteOption!);

      expect(onChange).toHaveBeenCalledWith({ if: '{{!_local.isLoading}}' });
    });
  });

  describe('iteration 편집', () => {
    it('기존 iteration 설정이 표시되어야 함', () => {
      const component = createTestComponent({
        iteration: {
          source: '{{users.data}}',
          item_var: 'user',
          index_var: 'idx',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByDisplayValue('{{users.data}}')).toBeInTheDocument();
      expect(screen.getByDisplayValue('user')).toBeInTheDocument();
      expect(screen.getByDisplayValue('idx')).toBeInTheDocument();
    });

    it('반복 렌더링 추가 버튼이 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('+ 반복 렌더링 추가')).toBeInTheDocument();
    });

    it('반복 렌더링 추가 클릭 시 기본 iteration이 생성되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      const addButton = screen.getByText('+ 반복 렌더링 추가');
      fireEvent.click(addButton);

      expect(onChange).toHaveBeenCalledWith({
        iteration: {
          source: '',
          item_var: 'item',
          index_var: 'index',
        },
      });
    });

    it('iteration source 변경 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent({
        iteration: {
          source: '{{users.data}}',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      const sourceInput = screen.getByDisplayValue('{{users.data}}');
      fireEvent.change(sourceInput, { target: { value: '{{products.data}}' } });

      expect(onChange).toHaveBeenCalledWith({
        iteration: {
          source: '{{products.data}}',
          item_var: 'item',
          index_var: 'index',
        },
      });
    });

    it('item_var 변경 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent({
        iteration: {
          source: '{{users.data}}',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      const itemVarInput = screen.getByDisplayValue('item');
      fireEvent.change(itemVarInput, { target: { value: 'user' } });

      expect(onChange).toHaveBeenCalledWith({
        iteration: {
          source: '{{users.data}}',
          item_var: 'user',
          index_var: 'index',
        },
      });
    });

    it('index_var 변경 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent({
        iteration: {
          source: '{{users.data}}',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      const indexVarInput = screen.getByDisplayValue('index');
      fireEvent.change(indexVarInput, { target: { value: 'idx' } });

      expect(onChange).toHaveBeenCalledWith({
        iteration: {
          source: '{{users.data}}',
          item_var: 'item',
          index_var: 'idx',
        },
      });
    });

    it('iteration 삭제 시 undefined로 설정되어야 함', () => {
      const component = createTestComponent({
        iteration: {
          source: '{{users.data}}',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      // 삭제 버튼 클릭
      const deleteButton = screen.getByTitle('반복 삭제');
      fireEvent.click(deleteButton);

      expect(onChange).toHaveBeenCalledWith({ iteration: undefined });
    });

    it('반복 렌더링 활성화 배지가 표시되어야 함', () => {
      const component = createTestComponent({
        iteration: {
          source: '{{users.data}}',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText(/반복 렌더링 활성화/)).toBeInTheDocument();
    });

    it('사용법 힌트가 표시되어야 함', () => {
      const component = createTestComponent({
        iteration: {
          source: '{{users.data}}',
          item_var: 'user',
          index_var: 'idx',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('사용법:')).toBeInTheDocument();
      expect(screen.getByText(/{{user.속성}}/)).toBeInTheDocument();
      expect(screen.getByText(/{{idx}}/)).toBeInTheDocument();
    });
  });

  describe('사용 가능한 소스', () => {
    it('데이터소스가 있을 때 사용 가능한 소스 버튼이 표시되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const component = createTestComponent({
        iteration: {
          source: '',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('사용 가능:')).toBeInTheDocument();
      expect(screen.getByText('users')).toBeInTheDocument();
      expect(screen.getByText('products')).toBeInTheDocument();
    });

    it('사용 가능한 소스 버튼 클릭 시 source가 설정되어야 함', () => {
      const layoutData = createTestLayoutData();
      useEditorState.setState({ layoutData });

      const component = createTestComponent({
        iteration: {
          source: '',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      const usersButton = screen.getByText('users');
      fireEvent.click(usersButton);

      expect(onChange).toHaveBeenCalledWith({
        iteration: {
          source: '{{users.data}}',
          item_var: 'item',
          index_var: 'index',
        },
      });
    });

    it('mockDataContext의 배열이 사용 가능한 소스로 표시되어야 함', () => {
      useEditorState.setState({
        mockDataContext: {
          _global: {
            items: [1, 2, 3],
          },
          _local: {
            options: ['a', 'b', 'c'],
          },
        },
      });

      const component = createTestComponent({
        iteration: {
          source: '',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('_global.items')).toBeInTheDocument();
      expect(screen.getByText('_local.options')).toBeInTheDocument();
    });
  });

  describe('if + iteration 조합', () => {
    it('if와 iteration이 모두 설정되면 경고가 표시되어야 함', () => {
      const component = createTestComponent({
        if: '{{_local.showList}}',
        iteration: {
          source: '{{users.data}}',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      // 이모지가 포함된 텍스트이므로 정규식으로 찾음
      expect(screen.getByText(/조건.*반복 조합/)).toBeInTheDocument();
      expect(
        screen.getByText(/조건이 먼저 평가되고, 참일 때만 반복이 실행됩니다/)
      ).toBeInTheDocument();
    });

    it('if만 설정되면 조합 경고가 표시되지 않아야 함', () => {
      const component = createTestComponent({
        if: '{{_local.showList}}',
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.queryByText(/조건.*반복 조합/)).not.toBeInTheDocument();
    });

    it('iteration만 설정되면 조합 경고가 표시되지 않아야 함', () => {
      const component = createTestComponent({
        iteration: {
          source: '{{users.data}}',
          item_var: 'item',
          index_var: 'index',
        },
      });
      const onChange = vi.fn();

      render(<ConditionsEditor component={component} onChange={onChange} />);

      expect(screen.queryByText(/조건.*반복 조합/)).not.toBeInTheDocument();
    });
  });
});
