import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ProductCard } from '../ProductCard';

describe('ProductCard', () => {
  const mockProps = {
    title: '프리미엄 제품',
    price: 45000,
  };

  it('컴포넌트가 렌더링됨', () => {
    render(<ProductCard {...mockProps} />);

    expect(screen.getByText('프리미엄 제품')).toBeInTheDocument();
    expect(screen.getByText('₩45,000')).toBeInTheDocument();
  });

  it('이미지가 표시됨', () => {
    render(<ProductCard {...mockProps} imageUrl="/product.png" imageAlt="제품 이미지" />);

    const image = screen.getByAltText('제품 이미지');
    expect(image).toBeInTheDocument();
    expect(image).toHaveAttribute('src', '/product.png');
  });

  it('imageAlt가 없으면 title을 alt로 사용함', () => {
    render(<ProductCard {...mockProps} imageUrl="/product.png" />);

    const image = screen.getByAltText('프리미엄 제품');
    expect(image).toBeInTheDocument();
  });

  it('subtitle이 표시됨', () => {
    render(<ProductCard {...mockProps} subtitle="신제품" />);

    expect(screen.getByText('신제품')).toBeInTheDocument();
  });

  it('description이 표시됨', () => {
    render(<ProductCard {...mockProps} description="최고급 소재로 제작되었습니다" />);

    expect(screen.getByText('최고급 소재로 제작되었습니다')).toBeInTheDocument();
  });

  it('할인가격이 표시됨', () => {
    render(
      <ProductCard
        {...mockProps}
        imageUrl="/product.png"
        originalPrice={50000}
        discountRate={10}
      />
    );

    expect(screen.getByText('₩45,000')).toBeInTheDocument();
    expect(screen.getByText('₩50,000')).toBeInTheDocument();
    expect(screen.getByText('-10%')).toBeInTheDocument();
  });

  it('할인 뱃지가 표시됨', () => {
    render(
      <ProductCard
        {...mockProps}
        imageUrl="/product.png"
        discountRate={15}
      />
    );

    expect(screen.getByText('-15%')).toBeInTheDocument();
  });

  it('커스텀 currency가 표시됨', () => {
    render(<ProductCard {...mockProps} currency="$" price={100} />);

    expect(screen.getByText('$100')).toBeInTheDocument();
  });

  it('버튼 텍스트가 표시됨', () => {
    render(<ProductCard {...mockProps} buttonText="구매하기" />);

    expect(screen.getByText('구매하기')).toBeInTheDocument();
  });

  it('버튼 클릭 시 onButtonClick이 호출됨', async () => {
    const user = userEvent.setup();
    const onButtonClick = vi.fn();

    render(
      <ProductCard
        {...mockProps}
        buttonText="구매하기"
        onButtonClick={onButtonClick}
      />
    );

    const button = screen.getByText('구매하기');
    await user.click(button);

    expect(onButtonClick).toHaveBeenCalledTimes(1);
  });

  it('모든 props가 함께 표시됨', () => {
    render(
      <ProductCard
        imageUrl="/product.png"
        imageAlt="제품 이미지"
        title="완전한 제품"
        subtitle="신상품"
        description="상세 설명"
        price={45000}
        originalPrice={50000}
        discountRate={10}
        buttonText="장바구니에 추가"
      />
    );

    expect(screen.getByText('완전한 제품')).toBeInTheDocument();
    expect(screen.getByText('신상품')).toBeInTheDocument();
    expect(screen.getByText('상세 설명')).toBeInTheDocument();
    expect(screen.getByText('₩45,000')).toBeInTheDocument();
    expect(screen.getByText('₩50,000')).toBeInTheDocument();
    expect(screen.getByText('-10%')).toBeInTheDocument();
    expect(screen.getByText('장바구니에 추가')).toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <ProductCard {...mockProps} className="custom-card" />
    );
    expect(container.firstChild).toHaveClass('custom-card');
  });

  it('style prop이 적용됨', () => {
    const { container } = render(
      <ProductCard {...mockProps} style={{ marginTop: '20px' }} />
    );
    const card = container.querySelector('.bg-white');
    expect(card).toHaveStyle({ marginTop: '20px' });
  });
});
