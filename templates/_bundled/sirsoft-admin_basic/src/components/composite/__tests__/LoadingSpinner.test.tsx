import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { LoadingSpinner } from '../LoadingSpinner';

describe('LoadingSpinner', () => {
  // @scenario mode=inline
  // @effects loading_spinner_renders_status
  it('컴포넌트가 렌더링됨', () => {
    render(<LoadingSpinner />);

    const spinner = screen.getByRole('status');
    expect(spinner).toBeInTheDocument();
  });

  // @scenario mode=inline
  // @effects loading_spinner_shows_text
  it('text가 표시됨', () => {
    render(<LoadingSpinner text="로딩 중..." />);

    expect(screen.getByText('로딩 중...')).toBeInTheDocument();
  });

  // @scenario mode=inline
  // @effects loading_spinner_size_classes
  it('size="sm" 스타일이 적용됨', () => {
    render(<LoadingSpinner size="sm" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-4', 'h-4');
  });

  // @scenario mode=inline
  // @effects loading_spinner_size_classes
  it('size="md" 스타일이 적용됨', () => {
    render(<LoadingSpinner size="md" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-8', 'h-8');
  });

  // @scenario mode=inline
  // @effects loading_spinner_size_classes
  it('size="lg" 스타일이 적용됨', () => {
    render(<LoadingSpinner size="lg" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-12', 'h-12');
  });

  // @scenario mode=inline
  // @effects loading_spinner_size_classes
  it('size="xl" 스타일이 적용됨', () => {
    render(<LoadingSpinner size="xl" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-16', 'h-16');
  });

  // @scenario mode=fullscreen
  // @effects loading_spinner_fullscreen_overlay
  it('fullscreen 모드가 적용됨', () => {
    const { container } = render(<LoadingSpinner fullscreen={true} />);

    const fullscreenDiv = container.querySelector('.fixed.inset-0');
    expect(fullscreenDiv).toBeInTheDocument();
    expect(fullscreenDiv).toHaveClass('z-50');
  });

  // Tailwind v4 에는 v3 의 `bg-opacity-*` 가 없어 규칙이 생기지 않는다 — 덮개가 불투명한 흰/검정 바탕이 됐다.
  // 투명도는 색 뒤 `/90` 로 준다.
  // @scenario mode=fullscreen
  // @effects loading_spinner_fullscreen_overlay_translucent
  it('fullscreen 덮개는 v4 투명도 표기(/90)를 쓰고 v3 bg-opacity 유틸을 쓰지 않는다', () => {
    const { container } = render(<LoadingSpinner fullscreen={true} />);

    const fullscreenDiv = container.querySelector('.fixed.inset-0') as HTMLElement;
    expect(fullscreenDiv.className).not.toMatch(/(^|\s)(dark:)?bg-opacity-\d+/);
    expect(fullscreenDiv).toHaveClass('bg-white/90', 'dark:bg-gray-900/90');
  });

  // @scenario mode=inline
  // @effects loading_spinner_fullscreen_overlay
  it('fullscreen이 false일 때 일반 모드로 표시됨', () => {
    render(<LoadingSpinner fullscreen={false} />);

    const container = screen.getByRole('status').parentElement;
    expect(container).not.toHaveClass('fixed');
  });

  // @scenario mode=inline
  // @effects loading_spinner_color_and_class_props
  it('color prop이 적용됨', () => {
    render(<LoadingSpinner color="text-red-600" />);

    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('text-red-600');
  });

  // @scenario mode=inline
  // @effects loading_spinner_size_classes
  it('text와 size가 함께 적용됨', () => {
    render(<LoadingSpinner size="lg" text="데이터 로딩 중..." />);

    expect(screen.getByText('데이터 로딩 중...')).toBeInTheDocument();
    const spinner = screen.getByRole('status');
    expect(spinner).toHaveClass('w-12', 'h-12');
  });

  // @scenario mode=inline
  // @effects loading_spinner_color_and_class_props
  it('className prop이 적용됨', () => {
    const { container } = render(<LoadingSpinner className="custom-spinner" />);

    // 스피너 컨테이너에 className이 적용됨
    expect(container.querySelector('.custom-spinner')).toBeInTheDocument();
  });

  // @scenario mode=inline
  // @effects loading_spinner_renders_status
  it('aria-label이 적용됨', () => {
    render(<LoadingSpinner />);

    const spinner = screen.getByRole('status');
    // common.loading 번역 값이 적용됨
    expect(spinner).toHaveAttribute('aria-label', '로딩 중...');
  });

  // @scenario mode=fullscreen
  // @effects loading_spinner_fullscreen_overlay
  it('aria-busy가 fullscreen 모드에서 적용됨', () => {
    const { container } = render(<LoadingSpinner fullscreen={true} />);

    const fullscreenDiv = container.querySelector('.fixed.inset-0');
    expect(fullscreenDiv).toHaveAttribute('aria-busy', 'true');
  });
});
