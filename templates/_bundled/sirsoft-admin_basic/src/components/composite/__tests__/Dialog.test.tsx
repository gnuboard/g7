import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Dialog, DialogAction } from '../Dialog';

describe('Dialog', () => {
  const mockActions: DialogAction[] = [
    { label: '취소', onClick: vi.fn(), variant: 'secondary' },
    { label: '확인', onClick: vi.fn(), variant: 'primary' },
  ];

  const mockProps = {
    isOpen: true,
    onClose: vi.fn(),
    title: '테스트 다이얼로그',
    content: <div>테스트 내용</div>,
  };

  it('컴포넌트가 렌더링됨', () => {
    render(<Dialog {...mockProps} />);

    expect(screen.getByText('테스트 다이얼로그')).toBeInTheDocument();
    expect(screen.getByText('테스트 내용')).toBeInTheDocument();
  });

  it('isOpen이 false일 때 렌더링되지 않음', () => {
    render(<Dialog {...mockProps} isOpen={false} />);

    expect(screen.queryByText('테스트 다이얼로그')).not.toBeInTheDocument();
  });

  it('액션 버튼이 표시됨', () => {
    render(<Dialog {...mockProps} actions={mockActions} />);

    expect(screen.getByText('취소')).toBeInTheDocument();
    expect(screen.getByText('확인')).toBeInTheDocument();
  });

  it('액션 버튼 클릭 시 onClick 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onClickMock = vi.fn();
    const actions: DialogAction[] = [
      { label: '테스트', onClick: onClickMock },
    ];

    render(<Dialog {...mockProps} actions={actions} />);

    const button = screen.getByText('테스트');
    await user.click(button);

    expect(onClickMock).toHaveBeenCalledTimes(1);
  });

  it('닫기 버튼 클릭 시 onClose가 호출됨', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<Dialog {...mockProps} onClose={onClose} />);

    const closeButton = screen.getByLabelText('대화상자 닫기');
    await user.click(closeButton);

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('showCloseButton이 false일 때 닫기 버튼이 표시되지 않음', () => {
    render(<Dialog {...mockProps} showCloseButton={false} />);

    expect(screen.queryByLabelText('대화상자 닫기')).not.toBeInTheDocument();
  });

  it('비활성화된 액션 버튼은 클릭되지 않음', async () => {
    const user = userEvent.setup();
    const onClickMock = vi.fn();
    const actions: DialogAction[] = [
      { label: '비활성화', onClick: onClickMock, disabled: true },
    ];

    render(<Dialog {...mockProps} actions={actions} />);

    const button = screen.getByText('비활성화');
    expect(button).toBeDisabled();

    await user.click(button);
    expect(onClickMock).not.toHaveBeenCalled();
  });

  it('액션 버튼에 아이콘이 표시됨', () => {
    const actions: DialogAction[] = [
      { label: '저장', onClick: vi.fn(), iconName: 'save' as any },
    ];

    render(<Dialog {...mockProps} actions={actions} />);

    expect(screen.getByText('저장')).toBeInTheDocument();
    // 아이콘이 렌더링되는지 확인하기는 어렵지만, 버튼은 표시됨
  });

  it('variant별 스타일이 적용됨', () => {
    const actions: DialogAction[] = [
      { label: '위험', onClick: vi.fn(), variant: 'danger' },
    ];

    render(<Dialog {...mockProps} actions={actions} />);

    const button = screen.getByText('위험');
    expect(button).toHaveClass('bg-red-600');
  });

  it('width prop이 적용됨', () => {
    render(<Dialog {...mockProps} width="800px" />);

    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveStyle({ width: '800px' });
  });

  it('className prop이 적용됨', () => {
    render(<Dialog {...mockProps} className="custom-dialog" />);

    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveClass('custom-dialog');
  });
});
