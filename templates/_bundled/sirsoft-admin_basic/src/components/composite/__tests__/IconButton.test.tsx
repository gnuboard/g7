import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { IconButton } from '../IconButton';
import { IconName } from '../../basic/IconTypes';

describe('IconButton', () => {
  it('컴포넌트가 렌더링됨', () => {
    render(<IconButton iconName={IconName.Plus} />);

    // 버튼이 렌더링되는지 확인
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('라벨이 있을 때 라벨이 표시됨', () => {
    render(<IconButton iconName={IconName.Plus} label="추가" />);

    expect(screen.getByText('추가')).toBeInTheDocument();
  });

  it('onClick 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();

    render(<IconButton iconName={IconName.Plus} onClick={onClick} />);

    const button = screen.getByRole('button');
    await user.click(button);

    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('disabled일 때 클릭되지 않음', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();

    render(<IconButton iconName={IconName.Plus} onClick={onClick} disabled={true} />);

    const button = screen.getByRole('button');
    expect(button).toBeDisabled();

    await user.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('size="sm" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} size="sm" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('p-1.5');
  });

  it('size="md" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} size="md" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('p-2');
  });

  it('size="lg" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} size="lg" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('p-2.5');
  });

  it('variant="primary" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} variant="primary" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('bg-blue-600');
  });

  it('variant="secondary" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} variant="secondary" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('bg-gray-100');
  });

  it('variant="danger" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} variant="danger" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('bg-red-600');
  });

  it('variant="ghost" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} variant="ghost" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('bg-transparent');
  });

  it('라벨이 있을 때 크기가 조정됨', () => {
    render(<IconButton iconName={IconName.Plus} label="추가" size="md" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('px-4', 'py-2');
  });

  it('className prop이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} className="custom-button" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('custom-button');
  });

  it('style prop이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} style={{ marginTop: '20px' }} />);

    const button = screen.getByRole('button');
    expect(button).toHaveStyle({ marginTop: '20px' });
  });
});
