import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Card } from '../Card';

describe('Card', () => {
  it('컴포넌트가 렌더링됨', () => {
    render(<Card />);

    // 카드 컨테이너가 렌더링되는지 확인
    const { container } = render(<Card />);
    expect(container.firstChild).toBeInTheDocument();
  });

  it('title이 표시됨', () => {
    render(<Card title="테스트 제목" />);

    expect(screen.getByText('테스트 제목')).toBeInTheDocument();
  });

  it('content가 표시됨', () => {
    render(<Card content="테스트 내용" />);

    expect(screen.getByText('테스트 내용')).toBeInTheDocument();
  });

  it('이미지가 표시됨', () => {
    render(<Card imageUrl="/test.png" imageAlt="테스트 이미지" />);

    const image = screen.getByAltText('테스트 이미지');
    expect(image).toBeInTheDocument();
    expect(image).toHaveAttribute('src', '/test.png');
  });

  it('title과 content가 함께 표시됨', () => {
    render(
      <Card
        title="제목"
        content="내용"
      />
    );

    expect(screen.getByText('제목')).toBeInTheDocument();
    expect(screen.getByText('내용')).toBeInTheDocument();
  });

  it('onClick 핸들러가 있을 때 카드 클릭 시 호출됨', async () => {
    const user = userEvent.setup();
    const onClickMock = vi.fn();

    const { container } = render(<Card title="클릭 가능한 카드" onClick={onClickMock} />);

    const card = container.firstChild as HTMLElement;
    await user.click(card);

    expect(onClickMock).toHaveBeenCalledTimes(1);
  });

  it('모든 props가 함께 표시됨', () => {
    render(
      <Card
        title="완전한 카드"
        content="설명 텍스트"
        imageUrl="/card-image.png"
        imageAlt="카드 이미지"
      />
    );

    expect(screen.getByText('완전한 카드')).toBeInTheDocument();
    expect(screen.getByText('설명 텍스트')).toBeInTheDocument();
    expect(screen.getByAltText('카드 이미지')).toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(<Card className="custom-card" />);
    expect(container.firstChild).toHaveClass('custom-card');
  });

  it('style prop이 적용됨', () => {
    const { container } = render(<Card style={{ marginTop: '20px' }} />);
    const card = container.querySelector('.bg-white');
    expect(card).toHaveStyle({ marginTop: '20px' });
  });
});
