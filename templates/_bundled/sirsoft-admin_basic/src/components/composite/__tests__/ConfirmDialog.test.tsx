import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConfirmDialog } from '../ConfirmDialog';

describe('ConfirmDialog', () => {
  const mockProps = {
    isOpen: true,
    onClose: vi.fn(),
    title: '삭제 확인',
    message: '정말 이 항목을 삭제하시겠습니까?',
    onConfirm: vi.fn(),
  };

  it('컴포넌트가 렌더링됨', () => {
    render(<ConfirmDialog {...mockProps} />);

    expect(screen.getByText('삭제 확인')).toBeInTheDocument();
    expect(screen.getByText('정말 이 항목을 삭제하시겠습니까?')).toBeInTheDocument();
  });

  it('isOpen이 false일 때 렌더링되지 않음', () => {
    render(<ConfirmDialog {...mockProps} isOpen={false} />);

    expect(screen.queryByText('삭제 확인')).not.toBeInTheDocument();
  });

  it('확인과 취소 버튼이 표시됨', () => {
    render(<ConfirmDialog {...mockProps} />);

    expect(screen.getByText('확인')).toBeInTheDocument();
    expect(screen.getByText('취소')).toBeInTheDocument();
  });

  it('커스텀 버튼 텍스트가 표시됨', () => {
    render(
      <ConfirmDialog
        {...mockProps}
        confirmText="삭제"
        cancelText="아니오"
      />
    );

    expect(screen.getByText('삭제')).toBeInTheDocument();
    expect(screen.getByText('아니오')).toBeInTheDocument();
  });

  it('확인 버튼 클릭 시 onConfirm이 호출됨', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    const onClose = vi.fn();

    render(
      <ConfirmDialog
        {...mockProps}
        onConfirm={onConfirm}
        onClose={onClose}
      />
    );

    const confirmButton = screen.getByText('확인');
    await user.click(confirmButton);

    // 확인 버튼은 onConfirm만 호출 (onClose는 부모가 처리)
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('취소 버튼 클릭 시 onCancel과 onClose가 호출됨', async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();
    const onClose = vi.fn();

    render(
      <ConfirmDialog
        {...mockProps}
        onCancel={onCancel}
        onClose={onClose}
      />
    );

    const cancelButton = screen.getByText('취소');
    await user.click(cancelButton);

    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('React 노드를 message로 사용할 수 있음', () => {
    render(
      <ConfirmDialog
        {...mockProps}
        message={<div>커스텀 <strong>경고</strong></div>}
      />
    );

    expect(screen.getByText('커스텀')).toBeInTheDocument();
    expect(screen.getByText('경고')).toBeInTheDocument();
  });

  it('confirmButtonVariant prop이 적용됨', () => {
    const { container } = render(
      <ConfirmDialog
        {...mockProps}
        confirmButtonVariant="danger"
      />
    );

    // confirmButtonVariant="danger"일 때 bg-red-600 클래스가 적용됨
    const confirmButton = container.querySelector('button.bg-red-600');
    expect(confirmButton).toBeInTheDocument();
  });

  it('cancelButtonVariant prop은 현재 고정 스타일 사용', () => {
    const { container } = render(
      <ConfirmDialog
        {...mockProps}
        cancelButtonVariant="ghost"
      />
    );

    // 취소 버튼은 현재 고정 스타일 사용 (bg-white)
    const cancelButton = screen.getByText('취소').closest('button');
    expect(cancelButton).toHaveClass('bg-white');
  });
});
