import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Modal } from '../Modal';

describe('Modal', () => {
  const mockProps = {
    isOpen: true,
    onClose: vi.fn(),
    title: '테스트 모달',
    children: <div>모달 내용</div>,
  };

  it('컴포넌트가 렌더링됨', () => {
    render(<Modal {...mockProps} />);

    expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    expect(screen.getByText('모달 내용')).toBeInTheDocument();
  });

  it('isOpen이 false일 때 렌더링되지 않음', () => {
    render(<Modal {...mockProps} isOpen={false} />);

    expect(screen.queryByText('테스트 모달')).not.toBeInTheDocument();
  });

  it('닫기 버튼 클릭 시 onClose가 호출됨', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    const closeButton = screen.getByLabelText('모달 닫기');
    await user.click(closeButton);

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('오버레이 클릭 시 onClose가 호출됨', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    // 오버레이를 찾아서 클릭
    const overlay = screen.getByRole('dialog').parentElement;
    if (overlay) {
      await user.click(overlay);
    }

    expect(onClose).toHaveBeenCalled();
  });

  it('모달 내용 클릭 시 onClose가 호출되지 않음', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    const modalContent = screen.getByRole('dialog');
    await user.click(modalContent);

    // 모달 내용 클릭 시 닫히지 않아야 함
    expect(onClose).not.toHaveBeenCalled();
  });

  it('ESC 키를 누르면 onClose가 호출됨', () => {
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('width prop이 적용됨', () => {
    render(<Modal {...mockProps} width="800px" />);

    const modal = screen.getByRole('dialog');
    expect(modal).toHaveStyle({ width: '800px' });
  });

  it('className prop이 적용됨', () => {
    render(<Modal {...mockProps} className="custom-modal" />);

    const modal = screen.getByRole('dialog');
    expect(modal).toHaveClass('custom-modal');
  });

  it('title이 없어도 렌더링됨', () => {
    const { title, ...propsWithoutTitle } = mockProps;

    render(<Modal {...propsWithoutTitle} />);

    expect(screen.getByText('모달 내용')).toBeInTheDocument();
  });

  it('모달이 열릴 때 body 스크롤이 방지됨', () => {
    render(<Modal {...mockProps} />);

    expect(document.body.style.overflow).toBe('hidden');
  });

  it('모달이 닫힐 때 body 스크롤이 복원됨', () => {
    const { rerender } = render(<Modal {...mockProps} />);

    expect(document.body.style.overflow).toBe('hidden');

    rerender(<Modal {...mockProps} isOpen={false} />);

    expect(document.body.style.overflow).toBe('');
  });

  describe('titlePrefix/titleSuffix', () => {
    it('titlePrefix 없이도 기존 동작이 유지됨', () => {
      render(<Modal {...mockProps} />);

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    });

    it('titleSuffix 없이도 기존 동작이 유지됨', () => {
      render(<Modal {...mockProps} />);

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    });

    it('titlePrefix와 titleSuffix가 빈 배열일 때 기존 동작이 유지됨', () => {
      render(<Modal {...mockProps} titlePrefix={[]} titleSuffix={[]} />);

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    });

    it('G7Core가 없을 때 titlePrefix/titleSuffix가 무시됨 (에러 없이 렌더링)', () => {
      // G7Core가 window에 없는 상태에서 테스트
      const originalG7Core = (window as any).G7Core;
      delete (window as any).G7Core;

      const mockTitlePrefix = [{ type: 'basic', name: 'Div', props: {} }];

      // 에러 없이 렌더링되어야 함
      expect(() => {
        render(<Modal {...mockProps} titlePrefix={mockTitlePrefix} />);
      }).not.toThrow();

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();

      // 원복
      (window as any).G7Core = originalG7Core;
    });

    it('title 없이 titlePrefix만 있어도 렌더링됨', () => {
      const { title, ...propsWithoutTitle } = mockProps;

      render(<Modal {...propsWithoutTitle} titlePrefix={[]} />);

      expect(screen.getByText('모달 내용')).toBeInTheDocument();
    });
  });

  /**
   * footer — S16 F5 회귀 게이트.
   *
   * 이 prop 이 없던 동안 12개 모달의 취소/확인 버튼이 통째로 렌더되지 않았다. 레이아웃은
   * 버튼을 노드 레벨 `footer` 에 적어 두었는데 `Modal` 이 그것을 읽지 않아, 사용자에게는
   * 헤더의 X 만 남고 삭제·상태변경 확인이 불가능했다.
   */
  describe('footer', () => {
    /** DynamicRenderer 를 흉내내는 최소 G7Core — 넘어온 componentDef 의 text 를 그대로 그린다. */
    const installFakeG7Core = () => {
      const original = (window as any).G7Core;

      (window as any).G7Core = {
        getDynamicRenderer: () => ({ componentDef }: any) => <button>{componentDef.text}</button>,
        getComponentRegistry: () => ({}),
        getDataBindingEngine: () => ({}),
        getTranslationEngine: () => ({}),
        getActionDispatcher: () => ({}),
      };

      return () => {
        (window as any).G7Core = original;
      };
    };

    it('footer 컴포넌트가 렌더링됨', () => {
      const restore = installFakeG7Core();

      render(
        <Modal
          {...mockProps}
          footer={[
            { type: 'basic', name: 'Button', text: '취소' },
            { type: 'basic', name: 'Button', text: '삭제' },
          ]}
        />
      );

      expect(screen.getByText('취소')).toBeInTheDocument();
      expect(screen.getByText('삭제')).toBeInTheDocument();

      restore();
    });

    it('footer 는 본문 스크롤 영역 밖에 렌더링됨', () => {
      const restore = installFakeG7Core();

      render(<Modal {...mockProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }]} />);

      // 본문(.modal-body-content) 안에 있으면 내용이 길 때 버튼이 함께 스크롤되어 밀린다.
      const body = document.querySelector('.modal-body-content');
      expect(body).not.toBeNull();
      expect(body!.textContent).not.toContain('확인');

      const footerRow = document.querySelector('.modal-footer-row');
      expect(footerRow).not.toBeNull();
      expect(footerRow!.textContent).toContain('확인');

      restore();
    });

    it('footer 가 없거나 빈 배열이면 하단 영역 자체가 렌더링되지 않음', () => {
      const { rerender } = render(<Modal {...mockProps} />);
      expect(document.querySelector('.modal-footer-row')).toBeNull();

      rerender(<Modal {...mockProps} footer={[]} />);
      expect(document.querySelector('.modal-footer-row')).toBeNull();
    });

    it('G7Core 가 없어도 footer 때문에 렌더링이 깨지지 않음', () => {
      const original = (window as any).G7Core;
      delete (window as any).G7Core;

      expect(() => {
        render(<Modal {...mockProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }]} />);
      }).not.toThrow();

      expect(screen.getByText('모달 내용')).toBeInTheDocument();

      (window as any).G7Core = original;
    });
  });
});
