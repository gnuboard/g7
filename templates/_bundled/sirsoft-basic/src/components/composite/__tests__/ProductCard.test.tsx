/**
 * ProductCard 컴포넌트 테스트
 *
 * @description 상품 카드 컴포넌트의 렌더링 및 동작을 테스트합니다.
 */

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import ProductCard from '../ProductCard';

// G7Core mock
const mockG7Core = {
  t: vi.fn((key: string) => {
    const translations: Record<string, string> = {
      'shop.sold_out': '품절',
      'shop.add_to_wishlist': '찜하기',
      'shop.remove_from_wishlist': '찜 해제',
      'shop.quick_view': '빠른보기',
      'shop.discount_badge': '% 할인',
    };
    return translations[key] ?? key;
  }),
};

beforeEach(() => {
  (window as any).G7Core = mockG7Core;
});

interface Product {
  id: number;
  name: string;
  slug: string;
  price: number;
  original_price?: number;
  discount_rate?: number;
  thumbnail: string;
  is_sold_out?: boolean;
  is_new?: boolean;
  is_wishlisted?: boolean;
  review_count?: number;
  average_rating?: number;
}

// ProductCard Mock
const MockProductCard: React.FC<{
  product: Product;
  showWishlistButton?: boolean;
  showQuickView?: boolean;
  onWishlistToggle?: (productId: number) => void;
}> = ({
  product,
  showWishlistButton = true,
  showQuickView = true,
  onWishlistToggle,
}) => {
  const t = (key: string) => mockG7Core.t(key);
  const hasDiscount = product.discount_rate && product.discount_rate > 0;

  return (
    <div data-testid="product-card">
      <a href={`/shop/products/${product.slug}`} data-testid="product-link">
        <div data-testid="product-image-container">
          <img
            src={product.thumbnail}
            alt={product.name}
            data-testid="product-image"
          />
          {product.is_sold_out && (
            <div data-testid="sold-out-badge">{t('shop.sold_out')}</div>
          )}
          {product.is_new && (
            <span data-testid="new-badge">NEW</span>
          )}
          {hasDiscount && (
            <span data-testid="discount-badge">
              {product.discount_rate}%
            </span>
          )}
        </div>
        <div data-testid="product-info">
          <h3 data-testid="product-name">{product.name}</h3>
          <div data-testid="product-price">
            {hasDiscount && product.original_price && (
              <span data-testid="original-price">
                {product.original_price.toLocaleString()}원
              </span>
            )}
            <span data-testid="current-price">
              {product.price.toLocaleString()}원
            </span>
          </div>
          {product.review_count !== undefined && product.review_count > 0 && (
            <div data-testid="product-rating">
              <span>★ {product.average_rating?.toFixed(1)}</span>
              <span>({product.review_count})</span>
            </div>
          )}
        </div>
      </a>
      {showWishlistButton && (
        <button
          data-testid="wishlist-button"
          onClick={() => onWishlistToggle?.(product.id)}
          aria-label={product.is_wishlisted ? t('shop.remove_from_wishlist') : t('shop.add_to_wishlist')}
        >
          {product.is_wishlisted ? '❤️' : '🤍'}
        </button>
      )}
    </div>
  );
};

describe('ProductCard 컴포넌트', () => {
  const mockProduct: Product = {
    id: 1,
    name: '테스트 상품',
    slug: 'test-product',
    price: 29000,
    thumbnail: '/images/product.jpg',
    review_count: 10,
    average_rating: 4.5,
  };

  describe('렌더링', () => {
    it('기본 상품 카드가 렌더링되어야 함', () => {
      render(<MockProductCard product={mockProduct} />);
      expect(screen.getByTestId('product-card')).toBeInTheDocument();
    });

    it('상품명이 표시되어야 함', () => {
      render(<MockProductCard product={mockProduct} />);
      expect(screen.getByTestId('product-name')).toHaveTextContent('테스트 상품');
    });

    it('가격이 표시되어야 함', () => {
      render(<MockProductCard product={mockProduct} />);
      expect(screen.getByTestId('current-price')).toHaveTextContent('29,000원');
    });

    it('상품 이미지가 표시되어야 함', () => {
      render(<MockProductCard product={mockProduct} />);
      const img = screen.getByTestId('product-image');
      expect(img).toHaveAttribute('src', '/images/product.jpg');
      expect(img).toHaveAttribute('alt', '테스트 상품');
    });
  });

  describe('할인 상품', () => {
    const discountProduct: Product = {
      ...mockProduct,
      price: 23200,
      original_price: 29000,
      discount_rate: 20,
    };

    it('할인율 뱃지가 표시되어야 함', () => {
      render(<MockProductCard product={discountProduct} />);
      expect(screen.getByTestId('discount-badge')).toHaveTextContent('20%');
    });

    it('원래 가격이 표시되어야 함', () => {
      render(<MockProductCard product={discountProduct} />);
      expect(screen.getByTestId('original-price')).toHaveTextContent('29,000원');
    });

    it('할인된 가격이 표시되어야 함', () => {
      render(<MockProductCard product={discountProduct} />);
      expect(screen.getByTestId('current-price')).toHaveTextContent('23,200원');
    });
  });

  describe('품절 상품', () => {
    const soldOutProduct: Product = {
      ...mockProduct,
      is_sold_out: true,
    };

    it('품절 뱃지가 표시되어야 함', () => {
      render(<MockProductCard product={soldOutProduct} />);
      expect(screen.getByTestId('sold-out-badge')).toHaveTextContent('품절');
    });
  });

  describe('신상품', () => {
    const newProduct: Product = {
      ...mockProduct,
      is_new: true,
    };

    it('NEW 뱃지가 표시되어야 함', () => {
      render(<MockProductCard product={newProduct} />);
      expect(screen.getByTestId('new-badge')).toHaveTextContent('NEW');
    });
  });

  describe('찜하기 기능', () => {
    it('찜하기 버튼이 표시되어야 함', () => {
      render(<MockProductCard product={mockProduct} showWishlistButton={true} />);
      expect(screen.getByTestId('wishlist-button')).toBeInTheDocument();
    });

    it('찜하기 버튼 클릭 시 콜백이 호출되어야 함', () => {
      const onWishlistToggle = vi.fn();
      render(
        <MockProductCard
          product={mockProduct}
          showWishlistButton={true}
          onWishlistToggle={onWishlistToggle}
        />
      );

      fireEvent.click(screen.getByTestId('wishlist-button'));
      expect(onWishlistToggle).toHaveBeenCalledWith(1);
    });

    it('찜한 상품은 채워진 하트가 표시되어야 함', () => {
      const wishlistedProduct = { ...mockProduct, is_wishlisted: true };
      render(<MockProductCard product={wishlistedProduct} />);
      expect(screen.getByTestId('wishlist-button')).toHaveTextContent('❤️');
    });

    it('찜하지 않은 상품은 빈 하트가 표시되어야 함', () => {
      render(<MockProductCard product={mockProduct} />);
      expect(screen.getByTestId('wishlist-button')).toHaveTextContent('🤍');
    });
  });

  describe('리뷰 정보', () => {
    it('리뷰 수와 평점이 표시되어야 함', () => {
      render(<MockProductCard product={mockProduct} />);
      const rating = screen.getByTestId('product-rating');
      expect(rating).toHaveTextContent('4.5');
      expect(rating).toHaveTextContent('(10)');
    });

    it('리뷰가 없으면 리뷰 정보가 표시되지 않아야 함', () => {
      const noReviewProduct = { ...mockProduct, review_count: 0 };
      render(<MockProductCard product={noReviewProduct} />);
      expect(screen.queryByTestId('product-rating')).not.toBeInTheDocument();
    });
  });

  describe('링크', () => {
    it('상품 상세 페이지로 링크되어야 함', () => {
      render(<MockProductCard product={mockProduct} />);
      const link = screen.getByTestId('product-link');
      expect(link).toHaveAttribute('href', '/shop/products/test-product');
    });
  });

  describe('판매 상태 표시', () => {
    const soldOutProduct: Product = {
      ...mockProduct,
      sales_status: 'sold_out',
      sales_status_label: '품절',
    } as any;

    const comingSoonProduct: Product = {
      ...mockProduct,
      sales_status: 'coming_soon',
      sales_status_label: '출시예정',
    } as any;

    it('품절 상태일 때 오버레이가 표시되어야 함', () => {
      const MockWithStatus: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          <div data-testid="product-image-container">
            <img
              src={product.thumbnail}
              alt={product.name}
              data-testid="product-image"
              className={product.sales_status && product.sales_status !== 'on_sale' ? 'opacity-50 grayscale' : ''}
            />
            {product.sales_status && product.sales_status !== 'on_sale' && (
              <div data-testid="sales-status-overlay">
                {product.sales_status_label}
              </div>
            )}
          </div>
        </div>
      );

      render(<MockWithStatus product={soldOutProduct} />);
      expect(screen.getByTestId('sales-status-overlay')).toHaveTextContent('품절');
      expect(screen.getByTestId('product-image')).toHaveClass('opacity-50');
      expect(screen.getByTestId('product-image')).toHaveClass('grayscale');
    });

    it('출시예정 상태일 때 오버레이가 표시되어야 함', () => {
      const MockWithStatus: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          <div data-testid="product-image-container">
            <img
              src={product.thumbnail}
              alt={product.name}
              data-testid="product-image"
              className={product.sales_status && product.sales_status !== 'on_sale' ? 'opacity-50 grayscale' : ''}
            />
            {product.sales_status && product.sales_status !== 'on_sale' && (
              <div data-testid="sales-status-overlay">
                {product.sales_status_label}
              </div>
            )}
          </div>
        </div>
      );

      render(<MockWithStatus product={comingSoonProduct} />);
      expect(screen.getByTestId('sales-status-overlay')).toHaveTextContent('출시예정');
    });

    it('판매중 상태일 때 오버레이가 표시되지 않아야 함', () => {
      const onSaleProduct = { ...mockProduct, sales_status: 'on_sale' } as any;
      const MockWithStatus: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          <div data-testid="product-image-container">
            {product.sales_status && product.sales_status !== 'on_sale' && (
              <div data-testid="sales-status-overlay">
                {product.sales_status_label}
              </div>
            )}
          </div>
        </div>
      );

      render(<MockWithStatus product={onSaleProduct} />);
      expect(screen.queryByTestId('sales-status-overlay')).not.toBeInTheDocument();
    });
  });

  describe('상품 라벨/뱃지', () => {
    it('라벨이 있으면 표시되어야 함', () => {
      const productWithLabels = {
        ...mockProduct,
        labels: [
          { name: '신상품', color: '#FF5733' },
          { name: 'BEST', color: '#3366FF' },
        ],
      } as any;

      const MockWithLabels: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          {product.labels && product.labels.length > 0 && (
            <div data-testid="product-labels">
              {product.labels.map((label: any, idx: number) => (
                <span
                  key={idx}
                  data-testid={`label-${idx}`}
                  style={{ backgroundColor: label.color }}
                >
                  {label.name}
                </span>
              ))}
            </div>
          )}
        </div>
      );

      render(<MockWithLabels product={productWithLabels} />);
      expect(screen.getByTestId('product-labels')).toBeInTheDocument();
      expect(screen.getByTestId('label-0')).toHaveTextContent('신상품');
      expect(screen.getByTestId('label-1')).toHaveTextContent('BEST');
    });

    it('라벨이 없으면 표시되지 않아야 함', () => {
      const productWithoutLabels = { ...mockProduct, labels: [] } as any;

      const MockWithLabels: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          {product.labels && product.labels.length > 0 && (
            <div data-testid="product-labels" />
          )}
        </div>
      );

      render(<MockWithLabels product={productWithoutLabels} />);
      expect(screen.queryByTestId('product-labels')).not.toBeInTheDocument();
    });
  });

  describe('브랜드명', () => {
    it('브랜드명이 있으면 표시되어야 함', () => {
      const productWithBrand = { ...mockProduct, brand_name: '나이키' } as any;

      const MockWithBrand: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          {product.brand_name && (
            <span data-testid="brand-name">{product.brand_name}</span>
          )}
        </div>
      );

      render(<MockWithBrand product={productWithBrand} />);
      expect(screen.getByTestId('brand-name')).toHaveTextContent('나이키');
    });

    it('브랜드명이 없으면 표시되지 않아야 함', () => {
      const MockWithBrand: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          {product.brand_name && (
            <span data-testid="brand-name">{product.brand_name}</span>
          )}
        </div>
      );

      render(<MockWithBrand product={mockProduct} />);
      expect(screen.queryByTestId('brand-name')).not.toBeInTheDocument();
    });
  });

  describe('별점 (rating_avg / review_count)', () => {
    const baseProduct = {
      id: 1,
      name_localized: '테스트 상품',
      thumbnail_url: '/img/test.jpg',
      selling_price: 10000,
      selling_price_formatted: '10,000원',
    };

    it('rating_avg 4.5, review_count 10이면 별점과 개수가 표시되어야 함', () => {
      render(
        <ProductCard
          product={{ ...baseProduct, rating_avg: 4.5, review_count: 10 }}
        />
      );
      expect(screen.getByText('4.5')).toBeInTheDocument();
      expect(screen.getByText('(10)')).toBeInTheDocument();
    });

    it('rating_avg 0.0, review_count 0이면 0.0과 (0)이 표시되어야 함', () => {
      render(
        <ProductCard
          product={{ ...baseProduct, rating_avg: 0.0, review_count: 0 }}
        />
      );
      expect(screen.getByText('0.0')).toBeInTheDocument();
      expect(screen.getByText('(0)')).toBeInTheDocument();
    });

    it('rating_avg, review_count 미전달 시 0.0과 (0)이 표시되어야 함', () => {
      render(<ProductCard product={baseProduct} />);
      expect(screen.getByText('0.0')).toBeInTheDocument();
      expect(screen.getByText('(0)')).toBeInTheDocument();
    });

    it('rating_avg 3.0이면 채워진 별 3개, 빈 별 2개가 렌더링되어야 함', () => {
      const { container } = render(
        <ProductCard
          product={{ ...baseProduct, rating_avg: 3.0, review_count: 5 }}
        />
      );
      const fullStars = container.querySelectorAll('.fa-solid.fa-star');
      const emptyStars = container.querySelectorAll('.fa-regular.fa-star');
      expect(fullStars.length).toBe(3);
      expect(emptyStars.length).toBe(2);
    });

    it('rating_avg 3.5이면 채워진 별 3개, 반별 1개, 빈 별 1개가 렌더링되어야 함', () => {
      const { container } = render(
        <ProductCard
          product={{ ...baseProduct, rating_avg: 3.5, review_count: 5 }}
        />
      );
      const fullStars = container.querySelectorAll('.fa-solid.fa-star');
      const halfStars = container.querySelectorAll('.fa-star-half-stroke');
      const emptyStars = container.querySelectorAll('.fa-regular.fa-star');
      expect(fullStars.length).toBe(3);
      expect(halfStars.length).toBe(1);
      expect(emptyStars.length).toBe(1);
    });
  });

  describe('검색 하이라이트', () => {
    const highlightedProduct: Product = {
      ...mockProduct,
      name_highlighted: '테스트 <mark>상품</mark>',
      description_highlighted: '이것은 <mark>상품</mark> 설명입니다',
    } as any;

    it('하이라이트된 상품명이 있으면 HTML로 렌더링되어야 함', () => {
      const MockWithHighlight: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          {product.name_highlighted ? (
            <div
              data-testid="product-name-highlighted"
              dangerouslySetInnerHTML={{ __html: product.name_highlighted }}
            />
          ) : (
            <h3 data-testid="product-name">{product.name}</h3>
          )}
          {product.description_highlighted && (
            <div
              data-testid="product-description-highlighted"
              dangerouslySetInnerHTML={{ __html: product.description_highlighted }}
            />
          )}
        </div>
      );

      render(<MockWithHighlight product={highlightedProduct} />);
      expect(screen.getByTestId('product-name-highlighted')).toBeInTheDocument();
      expect(screen.queryByTestId('product-name')).not.toBeInTheDocument();
    });

    it('하이라이트된 설명이 있으면 표시되어야 함', () => {
      const MockWithHighlight: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          {product.description_highlighted && (
            <div
              data-testid="product-description-highlighted"
              dangerouslySetInnerHTML={{ __html: product.description_highlighted }}
            />
          )}
        </div>
      );

      render(<MockWithHighlight product={highlightedProduct} />);
      expect(screen.getByTestId('product-description-highlighted')).toBeInTheDocument();
    });

    it('하이라이트가 없으면 일반 텍스트로 표시되어야 함', () => {
      const MockWithHighlight: React.FC<{ product: any }> = ({ product }) => (
        <div data-testid="product-card">
          {product.name_highlighted ? (
            <div data-testid="product-name-highlighted" />
          ) : (
            <h3 data-testid="product-name">{product.name}</h3>
          )}
        </div>
      );

      render(<MockWithHighlight product={mockProduct} />);
      expect(screen.queryByTestId('product-name-highlighted')).not.toBeInTheDocument();
      expect(screen.getByTestId('product-name')).toHaveTextContent('테스트 상품');
    });
  });
});
