import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { LoadingSpinner } from '../LoadingSpinner';

describe('LoadingSpinner', () => {
  it('컴포넌트가 렌더링됨', () => {
    render(<LoadingSpinner />);

    const spinner = screen.getByRole('status');
    expect(spinner).toBeInTheDocument();
  });

  it('text가 표시됨', () => {
    render(<LoadingSpinner text="로딩 중..." />);

    expect(screen.getByText('로딩 중...')).toBeInTheDocument();
  });

  it('size="sm" 스타일이 적용됨', () => {
    render(<LoadingSpinner size="sm" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-4', 'h-4');
  });

  it('size="md" 스타일이 적용됨', () => {
    render(<LoadingSpinner size="md" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-8', 'h-8');
  });

  it('size="lg" 스타일이 적용됨', () => {
    render(<LoadingSpinner size="lg" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-12', 'h-12');
  });

  it('size="xl" 스타일이 적용됨', () => {
    render(<LoadingSpinner size="xl" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-16', 'h-16');
  });

  it('fullscreen 모드가 적용됨', () => {
    const { container } = render(<LoadingSpinner fullscreen={true} />);

    const fullscreenDiv = container.querySelector('.fixed.inset-0');
    expect(fullscreenDiv).toBeInTheDocument();
    expect(fullscreenDiv).toHaveClass('z-50');
  });

  it('fullscreen이 false일 때 일반 모드로 표시됨', () => {
    render(<LoadingSpinner fullscreen={false} />);

    const container = screen.getByRole('status').parentElement;
    expect(container).not.toHaveClass('fixed');
  });

  it('color prop이 적용됨', () => {
    render(<LoadingSpinner color="text-red-600" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('text-red-600');
  });

  it('text와 size가 함께 적용됨', () => {
    render(<LoadingSpinner size="lg" text="데이터 로딩 중..." />);

    expect(screen.getByText('데이터 로딩 중...')).toBeInTheDocument();
    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-12', 'h-12');
  });

  it('className prop이 적용됨', () => {
    const { container } = render(<LoadingSpinner className="custom-spinner" />);

    // 스피너 컨테이너에 className이 적용됨
    expect(container.querySelector('.custom-spinner')).toBeInTheDocument();
  });

  it('aria-label이 적용됨', () => {
    render(<LoadingSpinner />);

    const spinner = screen.getByRole('status');
    // common.loading 번역 값이 적용됨
    expect(spinner).toHaveAttribute('aria-label', '로딩 중...');
  });

  it('aria-busy가 fullscreen 모드에서 적용됨', () => {
    const { container } = render(<LoadingSpinner fullscreen={true} />);

    const fullscreenDiv = container.querySelector('.fixed.inset-0');
    expect(fullscreenDiv).toHaveAttribute('aria-busy', 'true');
  });
});
