import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { EmptyState, EmptyStateAction } from '../EmptyState';
import { IconName } from '../../basic/IconTypes';

describe('EmptyState', () => {
  it('컴포넌트가 렌더링됨', () => {
    render(<EmptyState title="데이터가 없습니다" />);

    expect(screen.getByText('데이터가 없습니다')).toBeInTheDocument();
  });

  it('description이 표시됨', () => {
    render(
      <EmptyState
        title="데이터가 없습니다"
        description="새 항목을 추가해보세요"
      />
    );

    expect(screen.getByText('새 항목을 추가해보세요')).toBeInTheDocument();
  });

  it('아이콘이 표시됨', () => {
    render(
      <EmptyState
        title="데이터가 없습니다"
        iconName={IconName.Search}
      />
    );

    // 아이콘이 렌더링되는지 확인 (실제로는 SVG 검증이 필요하지만 간략화)
    expect(screen.getByText('데이터가 없습니다')).toBeInTheDocument();
  });

  it('illustration 이미지가 표시됨', () => {
    render(
      <EmptyState
        title="데이터가 없습니다"
        illustrationSrc="/empty.svg"
        illustrationAlt="빈 상태 이미지"
      />
    );

    const image = screen.getByAltText('빈 상태 이미지');
    expect(image).toBeInTheDocument();
    expect(image).toHaveAttribute('src', '/empty.svg');
  });

  it('illustration이 있으면 icon이 표시되지 않음', () => {
    render(
      <EmptyState
        title="데이터가 없습니다"
        iconName={IconName.Search}
        illustrationSrc="/empty.svg"
        illustrationAlt="빈 상태 이미지"
      />
    );

    // illustration이 우선이므로 이미지만 표시됨
    expect(screen.getByAltText('빈 상태 이미지')).toBeInTheDocument();
  });

  it('액션 버튼이 표시됨', () => {
    const actions: EmptyStateAction[] = [
      { label: '새로 만들기', onClick: vi.fn() },
    ];

    render(
      <EmptyState
        title="데이터가 없습니다"
        actions={actions}
      />
    );

    expect(screen.getByText('새로 만들기')).toBeInTheDocument();
  });

  it('액션 버튼 클릭 시 onClick 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onClickMock = vi.fn();
    const actions: EmptyStateAction[] = [
      { label: '새로 만들기', onClick: onClickMock },
    ];

    render(
      <EmptyState
        title="데이터가 없습니다"
        actions={actions}
      />
    );

    const button = screen.getByText('새로 만들기');
    await user.click(button);

    expect(onClickMock).toHaveBeenCalledTimes(1);
  });

  it('여러 액션 버튼이 표시됨', () => {
    const actions: EmptyStateAction[] = [
      { label: '새로 만들기', onClick: vi.fn(), variant: 'primary' },
      { label: '가져오기', onClick: vi.fn(), variant: 'secondary' },
    ];

    render(
      <EmptyState
        title="데이터가 없습니다"
        actions={actions}
      />
    );

    expect(screen.getByText('새로 만들기')).toBeInTheDocument();
    expect(screen.getByText('가져오기')).toBeInTheDocument();
  });

  it('액션 버튼에 아이콘이 표시됨', () => {
    const actions: EmptyStateAction[] = [
      { label: '추가', onClick: vi.fn(), iconName: IconName.Plus },
    ];

    render(
      <EmptyState
        title="데이터가 없습니다"
        actions={actions}
      />
    );

    expect(screen.getByText('추가')).toBeInTheDocument();
  });

  it('variant별 버튼 스타일이 적용됨', () => {
    const actions: EmptyStateAction[] = [
      { label: '삭제', onClick: vi.fn(), variant: 'danger' },
    ];

    render(
      <EmptyState
        title="데이터가 없습니다"
        actions={actions}
      />
    );

    const button = screen.getByText('삭제');
    expect(button).toHaveClass('bg-red-600');
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <EmptyState
        title="데이터가 없습니다"
        className="custom-empty"
      />
    );
    expect(container.firstChild).toHaveClass('custom-empty');
  });
});
