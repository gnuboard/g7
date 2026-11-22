import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { IconButton } from '../IconButton';
import { IconName } from '../../basic/IconTypes';

describe('IconButton', () => {
  /**
   * @effects icon_button_renders_button
   */
  it('컴포넌트가 렌더링됨', () => {
    render(<IconButton iconName={IconName.Plus} />);

    // 버튼이 렌더링되는지 확인
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  /**
   * @effects icon_button_shows_label_when_given
   */
  it('라벨이 있을 때 라벨이 표시됨', () => {
    render(<IconButton iconName={IconName.Plus} label="추가" />);

    expect(screen.getByText('추가')).toBeInTheDocument();
  });

  /**
   * @effects button_click_calls_on_click
   */
  it('onClick 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();

    render(<IconButton iconName={IconName.Plus} onClick={onClick} />);

    const button = screen.getByRole('button');
    await user.click(button);

    expect(onClick).toHaveBeenCalledTimes(1);
  });

  /**
   * @effects disabled_button_ignores_click
   */
  it('disabled일 때 클릭되지 않음', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();

    render(<IconButton iconName={IconName.Plus} onClick={onClick} disabled={true} />);

    const button = screen.getByRole('button');
    expect(button).toBeDisabled();

    await user.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });

  // label 미지정 시 정사각 고정 크기 (w-N h-N) 가 적용된다. label 지정 시에는 padding 기반.
  /**
   * @effects icon_button_without_label_uses_square_size_classes
   */
  it('size="sm" (label 없음) 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} size="sm" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('w-8');
    expect(button).toHaveClass('h-8');
  });

  /**
   * @effects icon_button_without_label_uses_square_size_classes
   */
  it('size="md" (label 없음) 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} size="md" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('w-10');
    expect(button).toHaveClass('h-10');
  });

  /**
   * @effects icon_button_without_label_uses_square_size_classes
   */
  it('size="lg" (label 없음) 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} size="lg" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('w-12');
    expect(button).toHaveClass('h-12');
  });

  /**
   * @effects icon_button_variant_uses_semantic_btn_classes
   */
  it('variant="primary" 는 관리자 주 버튼 의미 클래스(btn-primary, 검정)를 쓴다 (#399 F14)', () => {
    render(<IconButton iconName={IconName.Plus} variant="primary" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('btn-primary', 'border-transparent');
    expect(button).not.toHaveClass('bg-blue-600');
  });

  /**
   * @effects icon_button_variant_uses_semantic_btn_classes
   */
  it('variant="secondary" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} variant="secondary" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('btn-secondary');
  });

  /**
   * @effects icon_button_variant_uses_semantic_btn_classes
   */
  it('variant="danger" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} variant="danger" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('btn-danger', 'border-transparent');
  });

  /**
   * @effects icon_button_variant_uses_semantic_btn_classes
   */
  it('variant="ghost" 스타일이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} variant="ghost" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('btn-ghost', 'border-transparent');
  });

  /**
   * @effects icon_button_outline_variant_keeps_legacy_tokens
   */
  it('variant="outline" 은 종전 토큰 그대로다(번들 사용 6곳 모양 불변)', () => {
    render(<IconButton iconName={IconName.Plus} variant="outline" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('bg-transparent', 'border-gray-300', 'text-gray-700');
    // 정사각 크기와 포커스 링은 유지한다
    expect(button).toHaveClass('w-10', 'h-10', 'focus:ring-blue-500');
  });

  /**
   * @effects icon_button_with_label_uses_padding_size
   */
  it('라벨이 있을 때 크기가 조정됨', () => {
    render(<IconButton iconName={IconName.Plus} label="추가" size="md" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('px-4', 'py-2');
  });

  /**
   * @effects button_keeps_caller_class_name
   */
  it('className prop이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} className="custom-button" />);

    const button = screen.getByRole('button');
    expect(button).toHaveClass('custom-button');
  });

  /**
   * @effects button_forwards_native_html_attributes
   */
  it('style prop이 적용됨', () => {
    render(<IconButton iconName={IconName.Plus} style={{ marginTop: '20px' }} />);

    const button = screen.getByRole('button');
    expect(button).toHaveStyle({ marginTop: '20px' });
  });
});
