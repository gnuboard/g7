/**
 * ActionsEditor.test.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 액션 에디터 컴포넌트 테스트
 *
 * 테스트 항목:
 * 1. 기본 렌더링
 * 2. 액션 추가
 * 3. 액션 삭제
 * 4. 액션 편집
 * 5. 액션 순서 이동
 * 6. 액션 복제
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ActionsEditor } from '../components/PropertyPanel/ActionsEditor';
import type { ComponentDefinition } from '../types/editor';

// 테스트용 컴포넌트 생성
const createTestComponent = (actions?: any[]): ComponentDefinition =>
  ({
    id: 'test-component',
    type: 'basic',
    name: 'Button',
    props: { label: 'Test Button' },
    actions,
  } as ComponentDefinition);

describe('ActionsEditor', () => {
  describe('기본 렌더링', () => {
    it('액션이 없으면 빈 상태 메시지가 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('등록된 액션이 없습니다')).toBeInTheDocument();
    });

    it('액션이 있으면 액션 목록이 표시되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText(/등록된 액션/)).toBeInTheDocument();
      expect(screen.getByText('click')).toBeInTheDocument();
      expect(screen.getByText('navigate')).toBeInTheDocument();
    });

    it('여러 액션이 표시되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
        { type: 'submit', handler: 'apiCall', target: '/api/submit' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 텍스트가 분리되어 있으므로 정규식 사용
      expect(screen.getByText(/등록된 액션/)).toBeInTheDocument();
      expect(screen.getByText('2')).toBeInTheDocument();
      expect(screen.getByText('click')).toBeInTheDocument();
      expect(screen.getByText('submit')).toBeInTheDocument();
    });

    it('커스텀 이벤트가 표시되어야 함', () => {
      const component = createTestComponent([
        { event: 'onChange', handler: 'setState', params: { target: 'local' } },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('onChange')).toBeInTheDocument();
    });

    it('후속 액션 체인 표시자가 표시되어야 함', () => {
      const component = createTestComponent([
        {
          type: 'click',
          handler: 'apiCall',
          target: '/api/delete',
          onSuccess: { handler: 'toast', params: { type: 'success' } },
        },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('+chain')).toBeInTheDocument();
    });

    it('확인 대화상자 표시자가 표시되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'apiCall', confirm: '정말 삭제하시겠습니까?' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('⚠️')).toBeInTheDocument();
    });
  });

  describe('액션 추가', () => {
    it('액션 추가 버튼이 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      expect(screen.getByText('+ 액션 추가')).toBeInTheDocument();
    });

    it('액션 추가 버튼 클릭 시 옵션이 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      const addButton = screen.getByText('+ 액션 추가');
      fireEvent.click(addButton);

      expect(screen.getByText('기본 액션 추가')).toBeInTheDocument();
      expect(screen.getByText('취소')).toBeInTheDocument();
    });

    it('기본 액션 추가 클릭 시 새 액션이 생성되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 액션 추가 버튼 클릭
      fireEvent.click(screen.getByText('+ 액션 추가'));
      // 기본 액션 추가 클릭
      fireEvent.click(screen.getByText('기본 액션 추가'));

      expect(onChange).toHaveBeenCalledWith({
        actions: [
          {
            type: 'click',
            handler: 'navigate',
            params: {},
          },
        ],
      });
    });

    it('취소 버튼 클릭 시 추가 모드가 닫혀야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 액션 추가 버튼 클릭
      fireEvent.click(screen.getByText('+ 액션 추가'));
      expect(screen.getByText('취소')).toBeInTheDocument();

      // 취소 클릭
      fireEvent.click(screen.getByText('취소'));
      expect(screen.queryByText('취소')).not.toBeInTheDocument();
    });
  });

  describe('액션 편집', () => {
    it('액션 클릭 시 편집 패널이 열려야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 액션 아이템 클릭
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 편집 패널이 열려야 함
      expect(screen.getByText('이벤트 타입')).toBeInTheDocument();
      expect(screen.getByText('핸들러')).toBeInTheDocument();
      expect(screen.getByText('Target (선택)')).toBeInTheDocument();
    });

    it('핸들러 변경 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 액션 아이템 클릭하여 편집 패널 열기
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 핸들러 선택 변경
      const handlerSelect = screen.getByDisplayValue(/navigate/);
      fireEvent.change(handlerSelect, { target: { value: 'apiCall' } });

      expect(onChange).toHaveBeenCalledWith({
        actions: [{ type: 'click', handler: 'apiCall', target: '/home' }],
      });
    });

    it('target 변경 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // target 변경
      const targetInput = screen.getByPlaceholderText('URL, 모달 ID, API 엔드포인트 등');
      fireEvent.change(targetInput, { target: { value: '/users' } });

      expect(onChange).toHaveBeenCalledWith({
        actions: [{ type: 'click', handler: 'navigate', target: '/users' }],
      });
    });

    it('confirm 메시지 변경 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'apiCall', target: '/api/delete' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // confirm 메시지 입력
      const confirmInput = screen.getByPlaceholderText('실행 전 확인 대화상자 메시지');
      fireEvent.change(confirmInput, { target: { value: '삭제하시겠습니까?' } });

      expect(onChange).toHaveBeenCalledWith({
        actions: [
          { type: 'click', handler: 'apiCall', target: '/api/delete', confirm: '삭제하시겠습니까?' },
        ],
      });
    });
  });

  describe('액션 삭제', () => {
    it('삭제 버튼 클릭 시 액션이 삭제되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 삭제 버튼 클릭
      const deleteButton = screen.getByTitle('삭제');
      fireEvent.click(deleteButton);

      expect(onChange).toHaveBeenCalledWith({ actions: undefined });
    });

    it('여러 액션 중 하나 삭제 시 나머지가 유지되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
        { type: 'submit', handler: 'apiCall', target: '/api/submit' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 첫 번째 액션 편집 패널 열기
      const firstAction = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(firstAction!);

      // 삭제 버튼 클릭
      const deleteButton = screen.getByTitle('삭제');
      fireEvent.click(deleteButton);

      expect(onChange).toHaveBeenCalledWith({
        actions: [{ type: 'submit', handler: 'apiCall', target: '/api/submit' }],
      });
    });
  });

  describe('액션 복제', () => {
    it('복제 버튼 클릭 시 액션이 복제되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 복제 버튼 클릭
      const duplicateButton = screen.getByTitle('복제');
      fireEvent.click(duplicateButton);

      expect(onChange).toHaveBeenCalledWith({
        actions: [
          { type: 'click', handler: 'navigate', target: '/home' },
          { type: 'click', handler: 'navigate', target: '/home' },
        ],
      });
    });
  });

  describe('액션 순서 이동', () => {
    it('위로 이동 버튼 클릭 시 순서가 변경되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
        { type: 'submit', handler: 'apiCall', target: '/api/submit' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 두 번째 액션 편집 패널 열기
      const secondAction = screen.getByText('submit').closest('div[class*="cursor-pointer"]');
      fireEvent.click(secondAction!);

      // 위로 이동 버튼 클릭
      const upButton = screen.getByTitle('위로 이동');
      fireEvent.click(upButton);

      expect(onChange).toHaveBeenCalledWith({
        actions: [
          { type: 'submit', handler: 'apiCall', target: '/api/submit' },
          { type: 'click', handler: 'navigate', target: '/home' },
        ],
      });
    });

    it('첫 번째 액션의 위로 이동 버튼은 비활성화되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
        { type: 'submit', handler: 'apiCall', target: '/api/submit' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 첫 번째 액션 편집 패널 열기
      const firstAction = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(firstAction!);

      // 위로 이동 버튼이 비활성화되어 있어야 함
      const upButton = screen.getByTitle('위로 이동');
      expect(upButton).toBeDisabled();
    });

    it('마지막 액션의 아래로 이동 버튼은 비활성화되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'navigate', target: '/home' },
        { type: 'submit', handler: 'apiCall', target: '/api/submit' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 두 번째 (마지막) 액션 편집 패널 열기
      const secondAction = screen.getByText('submit').closest('div[class*="cursor-pointer"]');
      fireEvent.click(secondAction!);

      // 아래로 이동 버튼이 비활성화되어 있어야 함
      const downButton = screen.getByTitle('아래로 이동');
      expect(downButton).toBeDisabled();
    });
  });

  describe('파라미터 편집', () => {
    it('파라미터 섹션이 표시되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'setState', params: { target: 'local', value: 'test' } },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 파라미터 섹션이 표시되어야 함
      expect(screen.getByText('파라미터')).toBeInTheDocument();
    });

    it('기존 파라미터가 표시되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'setState', params: { target: 'local' } },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 파라미터 값이 표시되어야 함
      expect(screen.getByText('target:')).toBeInTheDocument();
      expect(screen.getByDisplayValue('local')).toBeInTheDocument();
    });

    it('핸들러별 권장 파라미터가 표시되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'toast', params: {} },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 권장 파라미터 힌트가 표시되어야 함
      expect(screen.getByText(/권장: type, message/)).toBeInTheDocument();
    });
  });

  describe('커스텀 이벤트', () => {
    it('커스텀 이벤트가 있는 액션에서 이벤트명 입력 필드가 표시되어야 함', () => {
      // 이미 커스텀 이벤트가 설정된 액션
      const component = createTestComponent([
        { event: 'onChange', handler: 'setState', params: { target: 'local' } },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('onChange').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 커스텀 이벤트 입력 필드가 표시되어야 함
      expect(screen.getByDisplayValue('onChange')).toBeInTheDocument();
    });

    it('커스텀 이벤트 선택 시 onChange가 올바르게 호출되어야 함', () => {
      const component = createTestComponent([
        { type: 'click', handler: 'setState' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('click').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 이벤트 타입을 커스텀으로 변경
      const eventSelect = screen.getAllByRole('combobox')[0];
      fireEvent.change(eventSelect, { target: { value: 'custom' } });

      // onChange가 event 필드와 함께 호출되어야 함
      expect(onChange).toHaveBeenCalledWith({
        actions: [{ type: 'click', handler: 'setState', event: 'onChange' }],
      });
    });
  });

  describe('Debounce 설정', () => {
    it('debounce가 설정된 액션은 시간 표시가 나타나야 함', () => {
      const component = createTestComponent([
        { type: 'change', handler: 'setState', debounce: 300 },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // debounce 시간 표시 확인
      expect(screen.getByText(/300ms/)).toBeInTheDocument();
    });

    it('debounce 토글 활성화 시 기본값 300ms가 설정되어야 함', () => {
      const component = createTestComponent([
        { type: 'change', handler: 'setState' },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('change').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // Debounce 섹션 확인
      expect(screen.getByText('Debounce (연속 호출 제어)')).toBeInTheDocument();

      // 토글 버튼 클릭
      const toggleButton = screen.getByRole('button', { name: '' });
      // 토글 버튼을 찾기 위해 부모 요소에서 찾기
      const debounceSection = screen.getByText('Debounce (연속 호출 제어)').closest('div');
      const toggle = debounceSection?.querySelector('button[type="button"]');
      if (toggle) {
        fireEvent.click(toggle);
        expect(onChange).toHaveBeenCalledWith({
          actions: [{ type: 'change', handler: 'setState', debounce: 300 }],
        });
      }
    });

    it('debounce 객체 형식이 있는 액션도 시간이 표시되어야 함', () => {
      const component = createTestComponent([
        {
          type: 'change',
          handler: 'setState',
          debounce: { delay: 500, leading: true, trailing: false },
        },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // debounce 시간 표시 확인
      expect(screen.getByText(/500ms/)).toBeInTheDocument();
    });

    it('debounce 토글 비활성화 시 debounce가 제거되어야 함', () => {
      const component = createTestComponent([
        { type: 'change', handler: 'setState', debounce: 300 },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('change').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 토글 버튼 클릭하여 비활성화
      const debounceSection = screen.getByText('Debounce (연속 호출 제어)').closest('div');
      const toggle = debounceSection?.querySelector('button[type="button"]');
      if (toggle) {
        fireEvent.click(toggle);
        expect(onChange).toHaveBeenCalledWith({
          actions: [{ type: 'change', handler: 'setState', debounce: undefined }],
        });
      }
    });

    it('debounce 지연 시간 변경 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent([
        { type: 'change', handler: 'setState', debounce: 300 },
      ]);
      const onChange = vi.fn();

      render(<ActionsEditor component={component} onChange={onChange} />);

      // 편집 패널 열기
      const actionItem = screen.getByText('change').closest('div[class*="cursor-pointer"]');
      fireEvent.click(actionItem!);

      // 지연 시간 입력 필드 찾기 및 변경
      const delayInput = screen.getByDisplayValue('300');
      fireEvent.change(delayInput, { target: { value: '500' } });

      expect(onChange).toHaveBeenCalledWith({
        actions: [{ type: 'change', handler: 'setState', debounce: 500 }],
      });
    });
  });
});
