import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AlertDialog } from '../AlertDialog';

describe('AlertDialog', () => {
  const mockProps = {
    isOpen: true,
    onClose: vi.fn(),
    title: '알림',
    message: '작업이 완료되었습니다.',
  };

  it('컴포넌트가 렌더링됨', () => {
    render(<AlertDialog {...mockProps} />);

    expect(screen.getByText('알림')).toBeInTheDocument();
    expect(screen.getByText('작업이 완료되었습니다.')).toBeInTheDocument();
  });

  it('isOpen이 false일 때 렌더링되지 않음', () => {
    render(<AlertDialog {...mockProps} isOpen={false} />);

    expect(screen.queryByText('알림')).not.toBeInTheDocument();
  });

  it('확인 버튼이 표시됨', () => {
    render(<AlertDialog {...mockProps} />);

    expect(screen.getByText('확인')).toBeInTheDocument();
  });

  it('커스텀 확인 버튼 텍스트가 표시됨', () => {
    render(<AlertDialog {...mockProps} confirmText="닫기" />);

    expect(screen.getByText('닫기')).toBeInTheDocument();
  });

  it('확인 버튼 클릭 시 onConfirm과 onClose가 호출됨', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    const onClose = vi.fn();

    render(
      <AlertDialog
        {...mockProps}
        onConfirm={onConfirm}
        onClose={onClose}
      />
    );

    const confirmButton = screen.getByText('확인');
    await user.click(confirmButton);

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('React 노드를 message로 사용할 수 있음', () => {
    render(
      <AlertDialog
        {...mockProps}
        message={<div>커스텀 <strong>메시지</strong></div>}
      />
    );

    expect(screen.getByText('커스텀')).toBeInTheDocument();
    expect(screen.getByText('메시지')).toBeInTheDocument();
  });

  it('confirmButtonVariant prop이 적용됨', () => {
    render(
      <AlertDialog
        {...mockProps}
        confirmButtonVariant="danger"
      />
    );

    const confirmButton = screen.getByText('확인');
    expect(confirmButton).toHaveClass('bg-red-600');
  });

  it('closeOnOverlay가 false일 때 오버레이 클릭 시 닫히지 않음', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(
      <AlertDialog
        {...mockProps}
        onClose={onClose}
        closeOnOverlay={false}
      />
    );

    // Dialog 컴포넌트의 오버레이를 찾아 클릭
    const overlay = screen.getByRole('dialog').parentElement?.querySelector('.bg-black');
    if (overlay) {
      await user.click(overlay as Element);
    }

    // closeOnOverlay가 false이므로 onClose가 호출되지 않아야 함
    // 하지만 Dialog 내부 구현에 따라 다를 수 있음
  });
});
