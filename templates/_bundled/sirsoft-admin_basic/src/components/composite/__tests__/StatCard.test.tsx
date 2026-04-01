import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { StatCard } from '../StatCard';
import { IconName } from '../../basic/IconTypes';

describe('StatCard', () => {
  it('컴포넌트가 렌더링됨', () => {
    render(<StatCard value={12345} label="총 사용자" />);

    expect(screen.getByText('12,345')).toBeInTheDocument();
    expect(screen.getByText('총 사용자')).toBeInTheDocument();
  });

  it('문자열 value가 표시됨', () => {
    render(<StatCard value="99.9%" label="가동률" />);

    expect(screen.getByText('99.9%')).toBeInTheDocument();
  });

  it('숫자 value가 천 단위 구분자와 함께 표시됨', () => {
    render(<StatCard value={1234567} label="총 매출" />);

    expect(screen.getByText('1,234,567')).toBeInTheDocument();
  });

  it('아이콘이 표시됨', () => {
    render(<StatCard value={100} label="사용자" iconName={IconName.User} />);

    expect(screen.getByText('사용자')).toBeInTheDocument();
  });

  it('증가 추세가 표시됨', () => {
    render(<StatCard value={100} label="사용자" change={15} trend="up" />);

    expect(screen.getByText('15%')).toBeInTheDocument();
  });

  it('감소 추세가 표시됨', () => {
    render(<StatCard value={100} label="사용자" change={10} trend="down" />);

    expect(screen.getByText('10%')).toBeInTheDocument();
  });

  it('neutral 추세가 표시됨', () => {
    render(<StatCard value={100} label="사용자" change={0} trend="neutral" />);

    expect(screen.getByText('0%')).toBeInTheDocument();
  });

  it('changeLabel이 표시됨', () => {
    render(
      <StatCard
        value={100}
        label="사용자"
        change={15}
        changeLabel="지난 주 대비"
        trend="up"
      />
    );

    expect(screen.getByText('지난 주 대비')).toBeInTheDocument();
  });

  it('증가 추세에 초록색 스타일이 적용됨', () => {
    render(<StatCard value={100} label="사용자" change={15} trend="up" />);

    const changeElement = screen.getByText('15%').parentElement;
    expect(changeElement).toHaveClass('text-green-600');
  });

  it('감소 추세에 빨간색 스타일이 적용됨', () => {
    render(<StatCard value={100} label="사용자" change={10} trend="down" />);

    const changeElement = screen.getByText('10%').parentElement;
    expect(changeElement).toHaveClass('text-red-600');
  });

  it('neutral 추세에 회색 스타일이 적용됨', () => {
    render(<StatCard value={100} label="사용자" change={0} trend="neutral" />);

    const changeElement = screen.getByText('0%').parentElement;
    expect(changeElement).toHaveClass('text-gray-600');
  });

  it('모든 props가 함께 표시됨', () => {
    render(
      <StatCard
        value={12345}
        label="총 사용자"
        change={12.5}
        changeLabel="전월 대비"
        iconName={IconName.User}
        trend="up"
      />
    );

    expect(screen.getByText('12,345')).toBeInTheDocument();
    expect(screen.getByText('총 사용자')).toBeInTheDocument();
    expect(screen.getByText('12.5%')).toBeInTheDocument();
    expect(screen.getByText('전월 대비')).toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <StatCard value={100} label="사용자" className="custom-stat" />
    );
    expect(container.firstChild).toHaveClass('custom-stat');
  });

  it('style prop이 적용됨', () => {
    const { container } = render(
      <StatCard value={100} label="사용자" style={{ marginTop: '20px' }} />
    );
    const card = container.querySelector('.bg-white');
    expect(card).toHaveStyle({ marginTop: '20px' });
  });
});
