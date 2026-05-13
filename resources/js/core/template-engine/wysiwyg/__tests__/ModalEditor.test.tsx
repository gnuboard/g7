/**
 * ModalEditor.test.tsx
 *
 * ModalEditor 컴포넌트 테스트
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ModalEditor, ModalDefinition } from '../components/PropertyPanel/ModalEditor';

// ============================================================================
// 테스트 헬퍼
// ============================================================================

function createTestModals(): ModalDefinition[] {
  return [
    {
      id: 'confirm_modal',
      type: 'composite',
      name: 'Modal',
      props: {
        title: '확인',
        size: 'medium',
        closable: true,
      },
      children: [],
    },
    {
      id: 'delete_modal',
      type: 'composite',
      name: 'Modal',
      props: {
        title: '삭제 확인',
        size: 'small',
      },
      children: [
        {
          id: 'delete_message',
          type: 'basic',
          name: 'Div',
          text: '정말 삭제하시겠습니까?',
        },
      ],
    },
  ];
}

// ============================================================================
// 테스트
// ============================================================================

describe('ModalEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('기본 렌더링', () => {
    it('헤더와 개수가 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      expect(screen.getByText('모달')).toBeInTheDocument();
      expect(screen.getByText('2개')).toBeInTheDocument();
    });

    it('모달 목록이 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      expect(screen.getByText('confirm_modal')).toBeInTheDocument();
      expect(screen.getByText('delete_modal')).toBeInTheDocument();
    });

    it('빈 상태일 때 안내 메시지가 표시되어야 함', () => {
      const onChange = vi.fn();

      render(<ModalEditor modals={[]} onChange={onChange} />);

      expect(screen.getByText(/모달이 없습니다/)).toBeInTheDocument();
    });

    it('추가 버튼이 표시되어야 함', () => {
      const onChange = vi.fn();

      render(<ModalEditor modals={[]} onChange={onChange} />);

      expect(screen.getByText('+ 새 모달 추가')).toBeInTheDocument();
    });

    it('도움말이 표시되어야 함', () => {
      const onChange = vi.fn();

      render(<ModalEditor modals={[]} onChange={onChange} />);

      expect(screen.getByText('모달 시스템 규칙:')).toBeInTheDocument();
      expect(screen.getByText(/isOpen, onClose/)).toBeInTheDocument();
    });
  });

  describe('모달 추가', () => {
    it('새 모달 추가 시 기본값이 설정되어야 함', () => {
      const onChange = vi.fn();

      render(<ModalEditor modals={[]} onChange={onChange} />);

      fireEvent.click(screen.getByText('+ 새 모달 추가'));

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          id: 'modal1',
          type: 'composite',
          name: 'Modal',
          props: {
            title: '새 모달',
            size: 'medium',
          },
          children: [],
        }),
      ]);
    });

    it('기존 모달이 있을 때 새 모달 추가 시 번호가 증가해야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      fireEvent.click(screen.getByText('+ 새 모달 추가'));

      expect(onChange).toHaveBeenCalledWith([
        ...modals,
        expect.objectContaining({
          id: 'modal3',
        }),
      ]);
    });
  });

  describe('모달 삭제', () => {
    it('삭제 버튼 클릭 시 해당 모달이 제거되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 첫 번째 삭제 버튼 클릭
      const deleteButtons = screen.getAllByText('삭제');
      fireEvent.click(deleteButtons[0]);

      expect(onChange).toHaveBeenCalledWith([modals[1]]);
    });
  });

  describe('모달 편집', () => {
    it('모달 클릭 시 편집 폼이 펼쳐져야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // confirm_modal 클릭
      fireEvent.click(screen.getByText('confirm_modal'));

      // 편집 필드들이 표시되어야 함
      expect(screen.getByDisplayValue('confirm_modal')).toBeInTheDocument();
      expect(screen.getByDisplayValue('확인')).toBeInTheDocument();
    });

    it('ID 변경 시 onChange가 호출되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 첫 번째 모달 펼치기
      fireEvent.click(screen.getByText('confirm_modal'));

      // ID 변경
      const idInput = screen.getByDisplayValue('confirm_modal');
      fireEvent.change(idInput, { target: { value: 'new_modal_id' } });

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({ id: 'new_modal_id' }),
        modals[1],
      ]);
    });

    it('제목 변경 시 onChange가 호출되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 첫 번째 모달 펼치기
      fireEvent.click(screen.getByText('confirm_modal'));

      // 제목 변경
      const titleInput = screen.getByDisplayValue('확인');
      fireEvent.change(titleInput, { target: { value: '새로운 제목' } });

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          props: expect.objectContaining({ title: '새로운 제목' }),
        }),
        modals[1],
      ]);
    });

    it('크기 변경이 가능해야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 첫 번째 모달 펼치기
      fireEvent.click(screen.getByText('confirm_modal'));

      // 크기 변경
      const sizeSelect = screen.getByDisplayValue(/중간/);
      fireEvent.change(sizeSelect, { target: { value: 'large' } });

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          props: expect.objectContaining({ size: 'large' }),
        }),
        modals[1],
      ]);
    });

    it('커스텀 너비 입력이 가능해야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 첫 번째 모달 펼치기
      fireEvent.click(screen.getByText('confirm_modal'));

      // 커스텀 너비 입력
      const widthInput = screen.getByPlaceholderText('600px');
      fireEvent.change(widthInput, { target: { value: '800px' } });

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          props: expect.objectContaining({ width: '800px' }),
        }),
        modals[1],
      ]);
    });
  });

  describe('ID 유효성 검사', () => {
    it('중복 ID 입력 시 에러가 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 첫 번째 모달 펼치기
      fireEvent.click(screen.getByText('confirm_modal'));

      // ID를 기존 ID와 동일하게 변경
      const idInput = screen.getByDisplayValue('confirm_modal');
      fireEvent.change(idInput, { target: { value: 'delete_modal' } });

      expect(screen.getByText('이미 사용 중인 ID입니다')).toBeInTheDocument();
    });

    it('빈 ID 입력 시 에러가 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 첫 번째 모달 펼치기
      fireEvent.click(screen.getByText('confirm_modal'));

      // ID 비우기
      const idInput = screen.getByDisplayValue('confirm_modal');
      fireEvent.change(idInput, { target: { value: '' } });

      expect(screen.getByText('ID는 필수입니다')).toBeInTheDocument();
    });

    it('잘못된 형식의 ID 입력 시 에러가 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 첫 번째 모달 펼치기
      fireEvent.click(screen.getByText('confirm_modal'));

      // 숫자로 시작하는 ID 입력
      const idInput = screen.getByDisplayValue('confirm_modal');
      fireEvent.change(idInput, { target: { value: '123modal' } });

      expect(screen.getByText(/ID는 영문자로 시작/)).toBeInTheDocument();
    });
  });

  describe('JSON 미리보기', () => {
    it('모달이 있으면 JSON 미리보기가 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      expect(screen.getByText('JSON 미리보기')).toBeInTheDocument();
      expect(screen.getByText(/\"id\": \"confirm_modal\"/)).toBeInTheDocument();
    });

    it('모달이 없으면 JSON 미리보기가 표시되지 않아야 함', () => {
      const onChange = vi.fn();

      render(<ModalEditor modals={[]} onChange={onChange} />);

      expect(screen.queryByText('JSON 미리보기')).not.toBeInTheDocument();
    });
  });

  describe('닫기 버튼 옵션', () => {
    it('closable 체크박스가 동작해야 함', () => {
      const modals: ModalDefinition[] = [
        {
          id: 'test_modal',
          type: 'composite',
          name: 'Modal',
          props: {
            title: '테스트',
            closable: true,
          },
        },
      ];
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 모달 펼치기
      fireEvent.click(screen.getByText('test_modal'));

      // 닫기 버튼 체크 해제
      const checkbox = screen.getByLabelText('닫기 버튼 표시');
      fireEvent.click(checkbox);

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          props: expect.objectContaining({ closable: false }),
        }),
      ]);
    });
  });

  describe('자식 컴포넌트 표시', () => {
    it('자식 컴포넌트가 있으면 개수가 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // delete_modal 펼치기 (자식 컴포넌트 있음)
      fireEvent.click(screen.getByText('delete_modal'));

      expect(screen.getByText('1개의 자식 컴포넌트')).toBeInTheDocument();
    });

    it('자식 컴포넌트가 없으면 안내 메시지가 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // confirm_modal 펼치기 (자식 컴포넌트 없음)
      fireEvent.click(screen.getByText('confirm_modal'));

      expect(screen.getByText(/본문 컴포넌트 없음/)).toBeInTheDocument();
    });
  });

  describe('사용 방법 안내', () => {
    it('모달 사용 방법 안내가 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 모달 펼치기
      fireEvent.click(screen.getByText('confirm_modal'));

      expect(screen.getByText('모달 사용 방법:')).toBeInTheDocument();
      // openModal과 closeModal 핸들러 사용 예시 code 요소 확인
      const codeElements = screen.getAllByText(/handler.*openModal|handler.*closeModal/);
      expect(codeElements.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('모달 제목 미리보기', () => {
    it('모달 헤더에 제목이 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 모달 헤더에 제목이 표시됨
      expect(screen.getByText('확인')).toBeInTheDocument();
      expect(screen.getByText('삭제 확인')).toBeInTheDocument();
    });
  });

  describe('크기 배지', () => {
    it('모달 헤더에 크기 배지가 표시되어야 함', () => {
      const modals = createTestModals();
      const onChange = vi.fn();

      render(<ModalEditor modals={modals} onChange={onChange} />);

      // 크기 배지 표시
      expect(screen.getByText('중간')).toBeInTheDocument();
      expect(screen.getByText('작음')).toBeInTheDocument();
    });
  });
});
